<?php
/**
 * Core sync functions and PMPro integration hooks.
 *
 * Handles syncing membership level changes and profile updates
 * to Brevo lists.
 *
 * @since TBD
 */

defined( 'ABSPATH' ) || exit;

// ------------------------------------------------------------------
// Logging
// ------------------------------------------------------------------

/**
 * Get the location of the PMPro Brevo log file.
 *
 * @since TBD
 *
 * @return string The log file path.
 */
function pmprobrevo_get_log_file_path() {
	return apply_filters( 'pmprobrevo_log_file_path', pmpro_get_restricted_file_path( 'logs', 'pmpro-brevo.log' ) );
}

/**
 * Maybe add an entry to the debug log.
 *
 * @since TBD
 *
 * @param string $message The log message.
 */
function pmprobrevo_debug_log( $message ) {
	$options          = get_option( 'pmprobrevo_options', array() );
	$enable_debug_log = isset( $options['enable_debug_log'] ) ? $options['enable_debug_log'] : 'no';
	if ( 'yes' !== $enable_debug_log ) {
		return;
	}

	$logstr    = "Logged On: " . date_i18n( "m/d/Y H:i:s" ) . "\n" . $message . "\n-------------\n";
	$logfile   = pmprobrevo_get_log_file_path();
	$loghandle = fopen( $logfile, "a+" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( $loghandle ) {
		fwrite( $loghandle, $logstr ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $loghandle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

// ------------------------------------------------------------------
// Sync: Enqueue for User
// ------------------------------------------------------------------

/**
 * Enqueue a sync for a user, either via Action Scheduler or immediately.
 *
 * @since TBD
 *
 * @param int  $user_id      WordPress user ID.
 * @param bool $update_lists Whether to sync list memberships.
 */
function pmprobrevo_enqueue_sync_for_user( $user_id, $update_lists = true ) {
	$options      = get_option( 'pmprobrevo_options', array() );
	$enable_async = isset( $options['enable_async'] ) ? $options['enable_async'] : 'yes';

	if ( 'no' === $enable_async || ! class_exists( 'PMPro_Action_Scheduler' ) ) {
		pmprobrevo_sync_contact_for_user( $user_id, $update_lists );
		return;
	}

	PMPro_Action_Scheduler::instance()->maybe_add_task(
		'pmprobrevo_sync_contact_for_user',
		array(
			'user_id'      => $user_id,
			'update_lists' => $update_lists,
		),
		'pmprobrevo_sync_tasks'
	);
}
add_action( 'pmprobrevo_sync_contact_for_user', 'pmprobrevo_sync_contact_for_user', 10, 2 );

// ------------------------------------------------------------------
// Sync: Core Logic
// ------------------------------------------------------------------

/**
 * Sync a single user to Brevo.
 *
 * Creates or updates the contact and manages list memberships
 * based on their current membership levels.
 *
 * @since TBD
 *
 * @param int  $user_id      WordPress user ID.
 * @param bool $update_lists Whether to sync list memberships.
 */
function pmprobrevo_sync_contact_for_user( $user_id, $update_lists = true ) {
	$api = PMPro_Brevo_API::get_instance();
	if ( ! $api->is_connected() ) {
		return;
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}

	$options = get_option( 'pmprobrevo_options', array() );

	// Get the user's current membership levels.
	$levels    = pmpro_getMembershipLevelsForUser( $user_id );
	$level_ids = wp_list_pluck( $levels, 'id' );

	// Build log message as we go.
	$log  = "Updating contact for user ID {$user_id} (email: {$user->user_email}). ";
	$log .= "Current level IDs: " . ( ! empty( $level_ids ) ? implode( ', ', $level_ids ) : 'none' ) . ". ";

	// If the user has no membership levels and is not already a contact, bail.
	$contact_id = get_user_meta( $user_id, 'pmprobrevo_contact_id', true );
	if ( empty( $level_ids ) && empty( $contact_id ) ) {
		$log .= "User has no membership levels and is not a contact. No action taken. ";
		pmprobrevo_debug_log( $log );
		return;
	}

	// ------------------------------------------------------------------
	// Build the list of Brevo lists for this user based on current levels.
	// ------------------------------------------------------------------
	$subscribe_lists = array();
	foreach ( $level_ids as $lid ) {
		$level_lists     = ! empty( $options[ 'level_lists_' . $lid ] ) ? $options[ 'level_lists_' . $lid ] : array();
		$subscribe_lists = array_merge( $subscribe_lists, $level_lists );
	}
	$subscribe_lists = array_values( array_unique( array_map( 'intval', array_filter( $subscribe_lists ) ) ) );
	$log .= "Lists to assign: " . ( ! empty( $subscribe_lists ) ? implode( ', ', $subscribe_lists ) : 'none' ) . ". ";

	// ------------------------------------------------------------------
	// Build contact data.
	// Brevo attribute names must be uppercase. FIRSTNAME and LASTNAME are default attributes.
	// ------------------------------------------------------------------
	$contact_data = array(
		'email'      => $user->user_email,
		'attributes' => array(
			'FIRSTNAME' => empty( $user->first_name ) ? $user->user_login : $user->first_name,
			'LASTNAME'  => $user->last_name,
		),
	);
	if ( ! empty( $subscribe_lists ) ) {
		$contact_data['listIds'] = $subscribe_lists;
	}

	// Optionally re-enable email for contacts that are blacklisted (unsubscribed) in Brevo.
	$status_mode = isset( $options['contact_status_mode'] ) ? $options['contact_status_mode'] : 'respect';
	if ( 'active' === $status_mode ) {
		$contact_data['emailBlacklisted'] = false;
	}

	/**
	 * Filter contact data before sending to Brevo.
	 *
	 * @since TBD
	 *
	 * @param array   $contact_data Data for the upsert.
	 * @param WP_User $user         The WordPress user.
	 * @param array   $levels       The user's membership levels.
	 */
	$contact_data = apply_filters( 'pmprobrevo_contact_data', $contact_data, $user, $levels );
	$log .= "Contact data: " . print_r( $contact_data, true ) . ". "; // phpcs:ignore WordPress.PHP.DevelopmentFunctions

	// ------------------------------------------------------------------
	// Work out which controlled lists the user should be removed from.
	// The upsert/update only adds lists — it does not remove.
	// ------------------------------------------------------------------
	$lists_to_remove = array();
	$unsubscribe     = isset( $options['unsubscribe'] ) ? $options['unsubscribe'] : 'yes';
	if ( $update_lists && 'yes' === $unsubscribe ) {
		$controlled_list_ids = pmprobrevo_get_controlled_list_ids();

		/**
		 * Filter the list IDs that PMPro controls.
		 *
		 * Only PMPro-controlled lists are removed when a member loses a level.
		 * Lists not in this list are preserved even during level changes.
		 *
		 * @since TBD
		 *
		 * @param array $controlled_list_ids All configured list IDs.
		 */
		$controlled_list_ids = apply_filters( 'pmprobrevo_controlled_list_ids', $controlled_list_ids );

		$lists_to_remove = array_values( array_diff( $controlled_list_ids, $subscribe_lists ) );
		$log .= "Lists to remove: " . ( ! empty( $lists_to_remove ) ? implode( ', ', $lists_to_remove ) : 'none' ) . ". ";
	} elseif ( 'no' === $unsubscribe ) {
		$log .= "List removal is disabled. ";
	}

	// ------------------------------------------------------------------
	// Update by stored contact ID when we have one.
	// PUT /contacts/{id} handles an email address change on the existing contact
	// and removes lists via unlinkListIds in the same call.
	// ------------------------------------------------------------------
	$updated_by_id = false;
	if ( ! empty( $contact_id ) ) {
		$update_data = $contact_data;
		unset( $update_data['email'] );
		$update_data['attributes']['EMAIL'] = $contact_data['email'];
		if ( ! empty( $lists_to_remove ) ) {
			$update_data['unlinkListIds'] = $lists_to_remove;
		}

		$result = $api->update_contact( $contact_id, $update_data );
		if ( is_wp_error( $result ) ) {
			// Contact was deleted or merged in Brevo. Fall back to the email upsert below.
			$log .= "Could not update contact ID {$contact_id}: " . $result->get_error_message() . ". Falling back to upsert by email. ";
			delete_user_meta( $user_id, 'pmprobrevo_contact_id' );
			$contact_id = 0;
		} else {
			$updated_by_id = true;
			$log .= "Updated contact ID {$contact_id} by ID. ";
			if ( ! empty( $lists_to_remove ) ) {
				$log .= "Removed list IDs: " . implode( ', ', $lists_to_remove ) . ". ";
			}
		}
	}

	// ------------------------------------------------------------------
	// Otherwise upsert by email.
	// POST /contacts with updateEnabled is non-destructive — listIds here are ADDED, not replaced.
	// ------------------------------------------------------------------
	$email_blacklisted = null;
	if ( ! $updated_by_id ) {
		$result = $api->upsert_contact( $contact_data );

		if ( is_wp_error( $result ) ) {
			$log .= "Error upserting contact: " . $result->get_error_message() . ". ";
			pmprobrevo_debug_log( $log );
			return;
		}

		// 201 returns the new ID. 204 (updated existing contact) returns nothing, so look the contact up.
		if ( ! empty( $result['id'] ) ) {
			$contact_id = (int) $result['id'];
			$log .= "Created contact ID {$contact_id}. ";
		} else {
			$contact = $api->get_contact( $contact_data['email'] );
			if ( is_wp_error( $contact ) ) {
				$log .= "Updated existing contact but could not retrieve it: " . $contact->get_error_message() . ". ";
			} else {
				$contact_id        = ! empty( $contact['id'] ) ? (int) $contact['id'] : 0;
				$email_blacklisted = ! empty( $contact['emailBlacklisted'] );
				$log .= "Updated contact ID {$contact_id} (emailBlacklisted: " . ( $email_blacklisted ? 'true' : 'false' ) . "). ";
			}
		}

		// Remove lists one call at a time, by email.
		foreach ( $lists_to_remove as $list_id ) {
			$response = $api->remove_contact_from_list( $contact_data['email'], $list_id );
			if ( is_wp_error( $response ) ) {
				$log .= "Error removing list ID {$list_id}: " . $response->get_error_message() . ". ";
			} elseif ( ! empty( $response['contacts']['failure'] ) ) {
				// Brevo reports contacts that were not in the list as failures. Not an error for us.
				$log .= "Contact was not in list ID {$list_id}. ";
			} else {
				$log .= "Removed list ID {$list_id}. ";
			}
		}
	}

	if ( $contact_id ) {
		update_user_meta( $user_id, 'pmprobrevo_contact_id', $contact_id );
	}

	// Log a warning for contacts that have unsubscribed from email.
	if ( true === $email_blacklisted ) {
		$log .= "WARNING: Contact {$contact_id} is blacklisted for email in Brevo (unsubscribed). They are in their lists but will not receive campaigns unless the Contact Status setting is set to Always set to Active. ";
	}

	pmprobrevo_debug_log( $log );
}

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------

/**
 * Get all list IDs configured across all membership levels.
 *
 * @since TBD
 *
 * @return array
 */
function pmprobrevo_get_controlled_list_ids() {
	$options = get_option( 'pmprobrevo_options', array() );

	// Use the pre-computed list saved on settings save when available.
	if ( ! empty( $options['level_lists_all'] ) ) {
		return array_values( array_unique( array_map( 'intval', array_filter( $options['level_lists_all'] ) ) ) );
	}

	// Fallback: compute from per-level keys.
	$all_lists = array();
	$levels    = pmpro_getAllLevels( true, true );
	foreach ( $levels as $level ) {
		$key = 'level_lists_' . $level->id;
		if ( ! empty( $options[ $key ] ) ) {
			$all_lists = array_merge( $all_lists, $options[ $key ] );
		}
	}

	return array_values( array_unique( array_map( 'intval', array_filter( $all_lists ) ) ) );
}

// ------------------------------------------------------------------
// PMPro Hooks
// ------------------------------------------------------------------

/**
 * When a user's membership level changes, sync their data to Brevo.
 *
 * Fires after the membership level change is confirmed.
 *
 * @since TBD
 *
 * @param array $old_users_and_levels Array of user IDs and their old levels.
 */
function pmprobrevo_sync_users_after_all_membership_level_changes( $old_users_and_levels ) {
	if ( empty( $old_users_and_levels ) || ! is_array( $old_users_and_levels ) ) {
		return;
	}

	foreach ( array_keys( $old_users_and_levels ) as $user_id ) {
		pmprobrevo_enqueue_sync_for_user( intval( $user_id ), true );
	}
}
add_action( 'pmpro_after_all_membership_level_changes', 'pmprobrevo_sync_users_after_all_membership_level_changes', 10, 1 );

/**
 * When a user's profile is updated, sync their data to Brevo.
 *
 * @since TBD
 *
 * @param int $user_id WordPress user ID.
 */
function pmprobrevo_sync_user_on_profile_update( $user_id ) {
	$options                = get_option( 'pmprobrevo_options', array() );
	$update_on_profile_save = isset( $options['update_on_profile_save'] ) ? $options['update_on_profile_save'] : 'yes';
	if ( 'no' === $update_on_profile_save ) {
		return;
	}

	pmprobrevo_enqueue_sync_for_user( $user_id, 'contact_only' !== $update_on_profile_save );
}
add_action( 'profile_update', 'pmprobrevo_sync_user_on_profile_update', 10, 1 );

/**
 * When user fields are saved from the PMPro Edit Member screen, sync their data to Brevo.
 *
 * PMPro's user fields panel saves directly to user meta without firing profile_update,
 * so we detect when a user-fields panel was saved and trigger the sync.
 *
 * Runs at priority 20 to fire after PMPro's save at priority 10.
 *
 * @since TBD
 */
function pmprobrevo_sync_user_on_edit_member_user_fields_save() {
	if ( empty( $_REQUEST['page'] ) || 'pmpro-member' !== $_REQUEST['page'] ) {
		return;
	}

	if ( empty( $_POST ) ) {
		return;
	}

	$panel_slug = empty( $_REQUEST['pmpro_member_edit_panel'] ) ? '' : sanitize_text_field( wp_unslash( $_REQUEST['pmpro_member_edit_panel'] ) );
	if ( empty( $panel_slug ) || strpos( $panel_slug, 'user-fields-' ) !== 0 ) {
		return;
	}

	if ( empty( $_REQUEST['pmpro_member_edit_saved_panel_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST['pmpro_member_edit_saved_panel_nonce'] ) ), 'pmpro_member_edit_saved_panel_' . $panel_slug ) ) {
		return;
	}

	$user_id = empty( $_REQUEST['user_id'] ) ? 0 : intval( $_REQUEST['user_id'] );
	if ( empty( $user_id ) ) {
		return;
	}

	$options                = get_option( 'pmprobrevo_options', array() );
	$update_on_profile_save = isset( $options['update_on_profile_save'] ) ? $options['update_on_profile_save'] : 'yes';
	if ( 'no' === $update_on_profile_save ) {
		return;
	}

	pmprobrevo_enqueue_sync_for_user( $user_id, 'contact_only' !== $update_on_profile_save );
}
add_action( 'admin_init', 'pmprobrevo_sync_user_on_edit_member_user_fields_save', 20 );
