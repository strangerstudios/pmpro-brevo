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
 * @return string The log file path, or an empty string if no path is available.
 */
function pmprobrevo_get_log_file_path() {
	// pmpro_get_restricted_file_path() was added in PMPro 3.5.
	$log_file_path = function_exists( 'pmpro_get_restricted_file_path' ) ? pmpro_get_restricted_file_path( 'logs', 'pmpro-brevo.log' ) : '';

	return apply_filters( 'pmprobrevo_log_file_path', $log_file_path );
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

	$logfile = pmprobrevo_get_log_file_path();
	if ( empty( $logfile ) ) {
		return;
	}

	$logstr    = "Logged On: " . date_i18n( "m/d/Y H:i:s" ) . "\n" . $message . "\n-------------\n";
	$loghandle = fopen( $logfile, "a+" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( $loghandle ) {
		fwrite( $loghandle, $logstr ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $loghandle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

// ------------------------------------------------------------------
// Sync: Enqueue and Retry
// ------------------------------------------------------------------

/**
 * Check whether background processing is available and enabled.
 *
 * @since TBD
 *
 * @return bool
 */
function pmprobrevo_use_async() {
	$options      = get_option( 'pmprobrevo_options', array() );
	$enable_async = isset( $options['enable_async'] ) ? $options['enable_async'] : 'yes';

	return 'no' !== $enable_async && class_exists( 'PMPro_Action_Scheduler' );
}

/**
 * Enqueue a sync for a user, either via Action Scheduler or immediately.
 *
 * @since TBD
 *
 * @param int  $user_id      WordPress user ID.
 * @param bool $update_lists Whether to sync list memberships.
 */
function pmprobrevo_enqueue_sync_for_user( $user_id, $update_lists = true ) {
	$args = array(
		'user_id'      => $user_id,
		'update_lists' => $update_lists,
	);

	if ( ! pmprobrevo_use_async() ) {
		$result = pmprobrevo_sync_contact_for_user( $user_id, $update_lists );
		pmprobrevo_handle_task_result( $result, 'pmprobrevo_sync_contact_for_user', $args, 0, false );
		return;
	}

	PMPro_Action_Scheduler::instance()->maybe_add_task( 'pmprobrevo_sync_contact_for_user', $args, 'pmprobrevo_sync_tasks' );
}

/**
 * Run a queued user sync from Action Scheduler.
 *
 * @since TBD
 *
 * @param int  $user_id      WordPress user ID.
 * @param bool $update_lists Whether to sync list memberships.
 * @param int  $attempt      Number of earlier attempts for this sync.
 * @throws Exception If the sync failed and will not be retried, so Action Scheduler marks the task as failed.
 */
function pmprobrevo_run_sync_task( $user_id, $update_lists = true, $attempt = 0 ) {
	$result = pmprobrevo_sync_contact_for_user( $user_id, $update_lists );
	$args   = array(
		'user_id'      => $user_id,
		'update_lists' => $update_lists,
	);
	pmprobrevo_handle_task_result( $result, 'pmprobrevo_sync_contact_for_user', $args, (int) $attempt, true );
}
add_action( 'pmprobrevo_sync_contact_for_user', 'pmprobrevo_run_sync_task', 10, 3 );

/**
 * Retry a failed sync task when the error is temporary.
 *
 * Retries use exponential backoff and respect Brevo's rate limit reset time.
 * When the task is out of retries, a task running in Action Scheduler throws so it is recorded as failed.
 *
 * @since TBD
 *
 * @param true|WP_Error $result                 Result of the task.
 * @param string        $hook                   Action Scheduler hook for the task.
 * @param array         $args                   Task arguments, without the attempt count.
 * @param int           $attempt                Number of earlier attempts for this task.
 * @param bool          $in_action_scheduler    Whether the task is running inside Action Scheduler.
 * @throws Exception If the task failed for good and is running inside Action Scheduler.
 */
function pmprobrevo_handle_task_result( $result, $hook, $args, $attempt, $in_action_scheduler ) {
	if ( ! is_wp_error( $result ) ) {
		return;
	}

	/**
	 * Filter the maximum number of retries for a failed Brevo sync.
	 *
	 * @since TBD
	 *
	 * @param int $max_retries Maximum number of retries. Default 5.
	 */
	$max_retries = (int) apply_filters( 'pmprobrevo_max_sync_retries', 5 );

	if ( PMPro_Brevo_API::is_retryable_error( $result ) && $attempt < $max_retries && class_exists( 'PMPro_Action_Scheduler' ) ) {
		$error_data  = $result->get_error_data();
		$retry_after = ( is_array( $error_data ) && ! empty( $error_data['retry_after'] ) ) ? (int) $error_data['retry_after'] : 0;
		$delay       = max( MINUTE_IN_SECONDS * pow( 2, $attempt ), $retry_after + 5 );
		$delay       = min( $delay, HOUR_IN_SECONDS );

		$args['attempt'] = $attempt + 1;
		PMPro_Action_Scheduler::instance()->maybe_add_task( $hook, $args, 'pmprobrevo_sync_tasks', time() + $delay );

		pmprobrevo_debug_log( "Task {$hook} failed with a temporary error: " . $result->get_error_message() . ". Retry {$args['attempt']} of {$max_retries} scheduled in {$delay} seconds. Args: " . wp_json_encode( $args ) );
		return;
	}

	$message = "Task {$hook} failed and will not be retried: " . $result->get_error_message() . ". Args: " . wp_json_encode( $args );
	pmprobrevo_debug_log( $message );

	if ( $in_action_scheduler ) {
		throw new Exception( esc_html( $message ) );
	}
}

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
 * @return true|WP_Error True on success or when there is nothing to do, WP_Error if a Brevo request failed.
 */
function pmprobrevo_sync_contact_for_user( $user_id, $update_lists = true ) {
	$api = PMPro_Brevo_API::get_instance();
	if ( ! $api->is_connected() ) {
		return true;
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return true;
	}

	$options = get_option( 'pmprobrevo_options', array() );

	// Get the user's current membership levels.
	$levels    = pmpro_getMembershipLevelsForUser( $user_id );
	$level_ids = wp_list_pluck( $levels, 'id' );

	// Build log message as we go.
	$log  = "Updating contact for user ID {$user_id} (email: {$user->user_email}). ";
	$log .= "Current level IDs: " . ( ! empty( $level_ids ) ? implode( ', ', $level_ids ) : 'none' ) . ". ";

	// If the user has no membership levels and has never been synced, bail.
	$contact_id = (int) get_user_meta( $user_id, 'pmprobrevo_contact_id', true );
	$synced     = ! empty( $contact_id ) || ! empty( get_user_meta( $user_id, 'pmprobrevo_synced', true ) );
	if ( empty( $level_ids ) && ! $synced ) {
		$log .= "User has no membership levels and has never been synced. No action taken. ";
		pmprobrevo_debug_log( $log );
		return true;
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
	if ( $update_lists && ! empty( $subscribe_lists ) ) {
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
		$lists_to_remove = array_values( array_diff( pmprobrevo_get_controlled_list_ids(), $subscribe_lists ) );
		$log .= "Lists to remove: " . ( ! empty( $lists_to_remove ) ? implode( ', ', $lists_to_remove ) : 'none' ) . ". ";
	} elseif ( 'no' === $unsubscribe ) {
		$log .= "List removal is disabled. ";
	}

	// ------------------------------------------------------------------
	// Update by stored contact ID when we have one.
	// Look the contact up first so a deleted contact (404) falls back to the
	// email upsert and so we know its current email and blacklist status.
	// ------------------------------------------------------------------
	$email_blacklisted = null;
	if ( ! empty( $contact_id ) ) {
		$existing = $api->get_contact_by_id( $contact_id );
		if ( is_wp_error( $existing ) ) {
			if ( 404 === PMPro_Brevo_API::get_error_status( $existing ) ) {
				// Contact was deleted in Brevo. Fall back to the email upsert below.
				$log .= "Contact ID {$contact_id} no longer exists in Brevo. Falling back to upsert by email. ";
				delete_user_meta( $user_id, 'pmprobrevo_contact_id' );
				$contact_id = 0;
			} else {
				$log .= "Could not retrieve contact ID {$contact_id}: " . $existing->get_error_message() . ". ";
				pmprobrevo_debug_log( $log );
				return $existing;
			}
		}
	}

	if ( ! empty( $contact_id ) ) {
		$existing_email    = ! empty( $existing['email'] ) ? $existing['email'] : '';
		$email_blacklisted = ! empty( $existing['emailBlacklisted'] );
		$email_changed     = ( '' !== $existing_email && strcasecmp( $existing_email, $contact_data['email'] ) !== 0 );

		$update_data = $contact_data;
		unset( $update_data['email'] );
		if ( $email_changed ) {
			// Brevo removes the email blacklist when a blacklisted contact's email is changed.
			if ( $email_blacklisted && 'active' !== $status_mode ) {
				$log .= "WARNING: Contact {$contact_id} is blacklisted for email in Brevo and the member's email changed from {$existing_email} to {$contact_data['email']}. Not updating the email address because that would resubscribe them. Update it manually in Brevo if the member has consented. ";
			} else {
				$update_data['attributes']['EMAIL'] = $contact_data['email'];
			}
		}
		if ( ! empty( $lists_to_remove ) ) {
			$update_data['unlinkListIds'] = $lists_to_remove;
		}

		$result = $api->update_contact( $contact_id, $update_data );

		// The new email may already belong to another Brevo contact. Sync everything else and keep the old email.
		if ( isset( $update_data['attributes']['EMAIL'] ) && pmprobrevo_is_identifier_conflict( $result ) ) {
			$log .= "WARNING: Could not change contact {$contact_id}'s email to {$contact_data['email']} because Brevo reported a conflict (" . $result->get_error_message() . "). Another Brevo contact probably uses that email. Syncing without the email change. Merge the contacts manually in Brevo if needed. ";
			unset( $update_data['attributes']['EMAIL'] );
			$result = $api->update_contact( $contact_id, $update_data );
		}

		if ( is_wp_error( $result ) ) {
			$log .= "Error updating contact ID {$contact_id}: " . $result->get_error_message() . ". ";
			pmprobrevo_debug_log( $log );
			return $result;
		}

		$log .= "Updated contact ID {$contact_id} by ID. ";
		if ( ! empty( $lists_to_remove ) ) {
			$log .= "Removed list IDs: " . implode( ', ', $lists_to_remove ) . ". ";
		}
	} elseif ( empty( $level_ids ) ) {
		// ------------------------------------------------------------------
		// A former member whose contact ID we don't have.
		// Remove their lists by email, but don't create a contact for them.
		// ------------------------------------------------------------------
		if ( ! empty( $lists_to_remove ) ) {
			$result = $api->update_contact_by_email( $contact_data['email'], array( 'unlinkListIds' => $lists_to_remove ) );
			if ( is_wp_error( $result ) ) {
				if ( 404 === PMPro_Brevo_API::get_error_status( $result ) ) {
					$log .= "No Brevo contact found for {$contact_data['email']}. Nothing to remove. ";
					delete_user_meta( $user_id, 'pmprobrevo_synced' );
					pmprobrevo_debug_log( $log );
					return true;
				}
				$log .= "Error removing lists by email: " . $result->get_error_message() . ". ";
				pmprobrevo_debug_log( $log );
				return $result;
			}
			$log .= "Removed list IDs by email: " . implode( ', ', $lists_to_remove ) . ". ";
		} else {
			$log .= "No contact ID stored and no lists to remove. ";
		}
	} else {
		// ------------------------------------------------------------------
		// Otherwise upsert by email.
		// POST /contacts with updateEnabled is non-destructive — listIds here are ADDED, not replaced.
		// ------------------------------------------------------------------
		$result = $api->upsert_contact( $contact_data );

		if ( is_wp_error( $result ) ) {
			$log .= "Error upserting contact: " . $result->get_error_message() . ". ";
			pmprobrevo_debug_log( $log );
			return $result;
		}

		update_user_meta( $user_id, 'pmprobrevo_synced', 1 );

		if ( ! empty( $result['id'] ) ) {
			// 201: a brand-new contact, so it isn't on any lists we need to remove.
			$contact_id = (int) $result['id'];
			update_user_meta( $user_id, 'pmprobrevo_contact_id', $contact_id );
			$log .= "Created contact ID {$contact_id}. ";
		} else {
			// 204: updated an existing contact, which returns no body, so look the contact up.
			$contact = $api->get_contact( $contact_data['email'] );
			if ( is_wp_error( $contact ) ) {
				$log .= "Updated existing contact but could not retrieve it: " . $contact->get_error_message() . ". ";
			} else {
				$contact_id        = ! empty( $contact['id'] ) ? (int) $contact['id'] : 0;
				$email_blacklisted = ! empty( $contact['emailBlacklisted'] );
				if ( $contact_id ) {
					update_user_meta( $user_id, 'pmprobrevo_contact_id', $contact_id );
				}
				$log .= "Updated contact ID {$contact_id} (emailBlacklisted: " . ( $email_blacklisted ? 'true' : 'false' ) . "). ";
			}

			if ( ! empty( $lists_to_remove ) ) {
				$unlink_data = array( 'unlinkListIds' => $lists_to_remove );
				$result      = $contact_id ? $api->update_contact( $contact_id, $unlink_data ) : $api->update_contact_by_email( $contact_data['email'], $unlink_data );
				if ( is_wp_error( $result ) ) {
					$log .= "Error removing list IDs " . implode( ', ', $lists_to_remove ) . ": " . $result->get_error_message() . ". ";
					pmprobrevo_debug_log( $log );
					return $result;
				}
				$log .= "Removed list IDs: " . implode( ', ', $lists_to_remove ) . ". ";
			}
		}
	}

	// Log a warning for contacts that have unsubscribed from email.
	if ( true === $email_blacklisted ) {
		$log .= "WARNING: Contact {$contact_id} is blacklisted for email in Brevo (unsubscribed). They are in their lists but will not receive campaigns unless the Contact Status setting is set to Always set to Active. ";
	}

	pmprobrevo_debug_log( $log );
	return true;
}

/**
 * Remove a deleted user's contact from all PMPro-controlled lists.
 *
 * Runs from a task that carries the contact ID and email, because the WordPress user no longer exists.
 *
 * @since TBD
 *
 * @param int    $contact_id Brevo contact ID, or 0 if unknown.
 * @param string $email      Contact email address.
 * @return true|WP_Error True on success or when there is nothing to do, WP_Error if the Brevo request failed.
 */
function pmprobrevo_remove_contact_from_controlled_lists( $contact_id, $email ) {
	$api = PMPro_Brevo_API::get_instance();
	if ( ! $api->is_connected() ) {
		return true;
	}

	$lists_to_remove = pmprobrevo_get_controlled_list_ids();
	if ( empty( $lists_to_remove ) ) {
		return true;
	}

	$unlink_data = array( 'unlinkListIds' => $lists_to_remove );
	$result      = ! empty( $contact_id ) ? $api->update_contact( $contact_id, $unlink_data ) : $api->update_contact_by_email( $email, $unlink_data );

	$log = "Removing deleted user's contact (ID: " . ( $contact_id ? $contact_id : 'unknown' ) . ", email: {$email}) from list IDs " . implode( ', ', $lists_to_remove ) . ". ";
	if ( is_wp_error( $result ) ) {
		if ( 404 === PMPro_Brevo_API::get_error_status( $result ) ) {
			pmprobrevo_debug_log( $log . "Contact not found in Brevo. Nothing to remove." );
			return true;
		}
		pmprobrevo_debug_log( $log . "Error: " . $result->get_error_message() );
		return $result;
	}

	pmprobrevo_debug_log( $log . "Done." );
	return true;
}

/**
 * Run a queued deleted-user removal from Action Scheduler.
 *
 * @since TBD
 *
 * @param int    $contact_id Brevo contact ID, or 0 if unknown.
 * @param string $email      Contact email address.
 * @param int    $attempt    Number of earlier attempts for this task.
 * @throws Exception If the removal failed and will not be retried, so Action Scheduler marks the task as failed.
 */
function pmprobrevo_run_remove_deleted_contact_task( $contact_id, $email, $attempt = 0 ) {
	$result = pmprobrevo_remove_contact_from_controlled_lists( (int) $contact_id, $email );
	$args   = array(
		'contact_id' => (int) $contact_id,
		'email'      => $email,
	);
	pmprobrevo_handle_task_result( $result, 'pmprobrevo_remove_deleted_contact', $args, (int) $attempt, true );
}
add_action( 'pmprobrevo_remove_deleted_contact', 'pmprobrevo_run_remove_deleted_contact_task', 10, 3 );

/**
 * Check whether a Brevo error is an identifier conflict, such as an email that belongs to another contact.
 *
 * Brevo documents a 4xx response for identifier conflicts but not a specific error code,
 * so this treats any 4xx other than auth, not found and rate limit errors as a possible conflict.
 *
 * @since TBD
 *
 * @param array|WP_Error $result Result of an update request.
 * @return bool
 */
function pmprobrevo_is_identifier_conflict( $result ) {
	if ( ! is_wp_error( $result ) ) {
		return false;
	}

	$status = PMPro_Brevo_API::get_error_status( $result );
	return $status >= 400 && $status < 500 && ! in_array( $status, array( 401, 403, 404, 408, 425, 429 ), true );
}

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------

/**
 * Get all list IDs that PMPro controls.
 *
 * Includes the lists assigned to any membership level, plus lists that were
 * unassigned from a level since members may still be on them.
 *
 * @since TBD
 *
 * @return array
 */
function pmprobrevo_get_controlled_list_ids() {
	$options = get_option( 'pmprobrevo_options', array() );

	// Use the pre-computed list saved on settings save when available.
	if ( ! empty( $options['level_lists_all'] ) ) {
		$all_lists = $options['level_lists_all'];
	} else {
		// Fallback: compute from per-level keys.
		$all_lists = array();
		$levels    = pmpro_getAllLevels( true, true );
		foreach ( $levels as $level ) {
			$key = 'level_lists_' . $level->id;
			if ( ! empty( $options[ $key ] ) ) {
				$all_lists = array_merge( $all_lists, $options[ $key ] );
			}
		}
	}

	if ( ! empty( $options['retired_lists'] ) ) {
		$all_lists = array_merge( $all_lists, $options['retired_lists'] );
	}

	$controlled_list_ids = array_values( array_unique( array_map( 'intval', array_filter( $all_lists ) ) ) );

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
	return array_values( array_map( 'intval', (array) apply_filters( 'pmprobrevo_controlled_list_ids', $controlled_list_ids ) ) );
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

/**
 * When a WordPress user is deleted, remove their contact from PMPro-controlled lists.
 *
 * Runs while the user's meta is still readable. The removal task carries the
 * contact ID and email because the user will be gone when it runs.
 *
 * @since TBD
 *
 * @param int $user_id WordPress user ID.
 */
function pmprobrevo_remove_lists_on_user_delete( $user_id ) {
	$api = PMPro_Brevo_API::get_instance();
	if ( ! $api->is_connected() ) {
		return;
	}

	$options     = get_option( 'pmprobrevo_options', array() );
	$unsubscribe = isset( $options['unsubscribe'] ) ? $options['unsubscribe'] : 'yes';
	if ( 'yes' !== $unsubscribe ) {
		return;
	}

	$user       = get_userdata( $user_id );
	$contact_id = (int) get_user_meta( $user_id, 'pmprobrevo_contact_id', true );
	$synced     = ! empty( $contact_id ) || ! empty( get_user_meta( $user_id, 'pmprobrevo_synced', true ) );
	if ( ! $user || ! $synced ) {
		return;
	}

	$args = array(
		'contact_id' => $contact_id,
		'email'      => $user->user_email,
	);

	if ( ! pmprobrevo_use_async() ) {
		$result = pmprobrevo_remove_contact_from_controlled_lists( $contact_id, $user->user_email );
		pmprobrevo_handle_task_result( $result, 'pmprobrevo_remove_deleted_contact', $args, 0, false );
		return;
	}

	PMPro_Action_Scheduler::instance()->maybe_add_task( 'pmprobrevo_remove_deleted_contact', $args, 'pmprobrevo_sync_tasks' );
}
add_action( 'delete_user', 'pmprobrevo_remove_lists_on_user_delete' );
add_action( 'wpmu_delete_user', 'pmprobrevo_remove_lists_on_user_delete' );
