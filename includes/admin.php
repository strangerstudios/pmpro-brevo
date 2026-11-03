<?php
/**
 * Admin settings page.
 *
 * @since TBD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add Brevo settings link to PMPro settings menu.
 *
 * @since TBD
 */
function pmprobrevo_admin_menu() {
	add_submenu_page(
		'pmpro-dashboard',
		__( 'Brevo', 'pmpro-brevo' ),
		__( 'Brevo', 'pmpro-brevo' ),
		'manage_options',
		'pmpro-brevo',
		'pmprobrevo_settings_page'
	);
}
add_action( 'admin_menu', 'pmprobrevo_admin_menu' );

/**
 * Render the Brevo settings page.
 *
 * @since TBD
 */
function pmprobrevo_settings_page() {
	// Get existing options.
	$options = get_option( 'pmprobrevo_options', array() );

	// Get all PMPro levels.
	$pmpro_levels = pmpro_getAllLevels( true );
	$pmpro_levels = pmpro_sort_levels_by_order( $pmpro_levels );

	// Handle form submission.
	$notices = array();
	if ( isset( $_POST['pmprobrevo_settings_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['pmprobrevo_settings_nonce'] ) ), 'pmprobrevo_save_settings' ) ) {
		// The saved key is rendered masked, so an unchanged mask means keep the saved key.
		$old_api_key     = isset( $options['api_key'] ) ? $options['api_key'] : '';
		$posted_api_key  = empty( $_POST['api_key'] ) ? '' : sanitize_text_field( wp_unslash( $_POST['api_key'] ) );
		$new_api_key     = ( '' !== $old_api_key && pmprobrevo_mask_api_key( $old_api_key ) === $posted_api_key ) ? $old_api_key : $posted_api_key;
		$api_key_changed = ( $old_api_key !== $new_api_key );

		// Sanitize and save all general settings.
		$options['api_key']                = $new_api_key;
		$options['update_on_profile_save'] = empty( $_POST['update_on_profile_save'] ) ? 'yes' : sanitize_text_field( wp_unslash( $_POST['update_on_profile_save'] ) );
		$options['unsubscribe']            = empty( $_POST['unsubscribe'] ) ? 'yes' : sanitize_text_field( wp_unslash( $_POST['unsubscribe'] ) );
		$options['contact_status_mode']    = empty( $_POST['contact_status_mode'] ) ? 'respect' : sanitize_text_field( wp_unslash( $_POST['contact_status_mode'] ) );
		$options['enable_async']           = empty( $_POST['enable_async'] ) ? 'yes' : sanitize_text_field( wp_unslash( $_POST['enable_async'] ) );
		$options['enable_debug_log']       = empty( $_POST['enable_debug_log'] ) ? 'no' : sanitize_text_field( wp_unslash( $_POST['enable_debug_log'] ) );

		$account_reset = false;
		if ( $api_key_changed ) {
			// Clear cached lists so they are re-fetched with the new key.
			delete_transient( 'pmprobrevo_all_lists' );

			if ( '' !== $new_api_key ) {
				$api = PMPro_Brevo_API::get_instance();
				$api->set_api_key( $new_api_key );
				$new_fingerprint    = PMPro_Brevo_API::get_account_fingerprint( $api->get_account() );
				$stored_fingerprint = isset( $options['account_fingerprint'] ) ? $options['account_fingerprint'] : '';
				$same_account       = '' !== $new_fingerprint && $new_fingerprint === $stored_fingerprint;
				$had_account        = '' !== $stored_fingerprint || '' !== $old_api_key;

				// List and contact IDs are per-account, so they can't be reused with a different (or unverifiable) account.
				if ( ! $same_account && $had_account ) {
					$options       = pmprobrevo_reset_account_data( $options );
					$account_reset = true;
					$notices[]     = __( 'The new API key is for a different Brevo account, or the account could not be verified. List assignments and stored Brevo contact IDs were reset. Assign lists again below.', 'pmpro-brevo' );
				}

				$options['account_fingerprint'] = $new_fingerprint;
			}
		}

		// Save per-level list assignments if the lists section was rendered for the same account.
		if ( ! empty( $_POST['level_lists_shown'] ) && ! $api_key_changed ) {
			$old_controlled_lists       = ! empty( $options['level_lists_all'] ) ? array_map( 'intval', (array) $options['level_lists_all'] ) : array();
			$options['level_lists_all'] = array();
			foreach ( $pmpro_levels as $level ) {
				$key             = 'level_lists_' . $level->id;
				$options[ $key ] = empty( $_POST[ $key ] ) ? array() : array_map( 'intval', (array) $_POST[ $key ] );
				$options['level_lists_all'] = array_merge( $options['level_lists_all'], $options[ $key ] );
			}
			$options['level_lists_all'] = array_values( array_unique( $options['level_lists_all'] ) );

			// Keep removing members from lists that were unassigned from every level, until the admin opts out.
			$retired_lists = ! empty( $options['retired_lists'] ) ? array_map( 'intval', (array) $options['retired_lists'] ) : array();
			$retired_lists = array_merge( $retired_lists, array_diff( $old_controlled_lists, $options['level_lists_all'] ) );
			if ( ! empty( $_POST['forget_retired_lists'] ) ) {
				$retired_lists = array();
			}
			$options['retired_lists'] = array_values( array_diff( array_unique( $retired_lists ), $options['level_lists_all'] ) );
		} elseif ( ! empty( $_POST['level_lists_shown'] ) && ! $account_reset ) {
			$notices[] = __( 'List assignments were not saved because the API key changed. Review the lists below and save again.', 'pmpro-brevo' );
		}

		update_option( 'pmprobrevo_options', $options );
		PMPro_Brevo_API::get_instance()->set_api_key( $options['api_key'] );

		// If debug logging was enabled, write a log entry to confirm it works.
		pmprobrevo_debug_log( 'PMPro Brevo settings updated.' );

		// Show a success message.
		echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'pmpro-brevo' ) . '</p></div>';
		foreach ( $notices as $notice ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $notice ) . '</p></div>';
		}
	}

	// Get the API instance. Called after save so it reads the fresh option.
	$api = PMPro_Brevo_API::get_instance();

	// Test the connection so a bad or revoked key isn't mistaken for an empty account.
	$account = $api->is_connected() ? $api->get_account() : null;
	if ( is_array( $account ) && empty( $options['account_fingerprint'] ) ) {
		$options['account_fingerprint'] = PMPro_Brevo_API::get_account_fingerprint( $account );
		update_option( 'pmprobrevo_options', $options );
	}
	?>
	<div class="wrap pmpro_admin">
		<h1><?php esc_html_e( 'Brevo Settings', 'pmpro-brevo' ); ?></h1>
		<p>
			<?php
			$docs_link = '<a title="' . esc_attr__( 'Paid Memberships Pro - Brevo Add On Documentation', 'pmpro-brevo' ) . '" target="_blank" rel="nofollow noopener" href="https://www.paidmembershipspro.com/add-ons/brevo-integration/?utm_source=plugin&utm_medium=pmpro-brevo&utm_campaign=add-ons&utm_content=pmpro-brevo-settings">' . esc_html__( 'Brevo Add On documentation', 'pmpro-brevo' ) . '</a>';
			// translators: %s: Link to Brevo Add On documentation.
			printf( esc_html__( 'Learn more about these settings in the %s.', 'pmpro-brevo' ), $docs_link ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</p>

		<form method="post" action="">
			<div class="pmpro_section" data-visibility="shown" data-activated="true">
				<div class="pmpro_section_toggle">
					<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
						<span class="dashicons dashicons-arrow-up-alt2"></span>
						<?php esc_html_e( 'General Settings', 'pmpro-brevo' ); ?>
					</button>
				</div>
				<div class="pmpro_section_inside">
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="api_key"><?php esc_html_e( 'API Key', 'pmpro-brevo' ); ?></label>
							</th>
							<td>
								<input type="password" name="api_key" id="api_key" value="<?php echo esc_attr( ! empty( $options['api_key'] ) ? pmprobrevo_mask_api_key( $options['api_key'] ) : '' ); ?>" class="regular-text" autocomplete="off">
								<p class="description">
									<?php esc_html_e( 'Your API key is used to connect your Brevo account to this membership site. The saved key is masked. Leave it unchanged to keep it, or clear the field to disconnect.', 'pmpro-brevo' ); ?>
									<a href="https://app.brevo.com/settings/keys/api" target="_blank" rel="noopener"><?php esc_html_e( 'Find your API key in Brevo under SMTP & API > API Keys.', 'pmpro-brevo' ); ?></a>
								</p>
							</td>
						</tr>
						<?php if ( $api->is_connected() ) { ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Connection Status', 'pmpro-brevo' ); ?></th>
								<td>
									<?php
									if ( is_wp_error( $account ) ) {
										?>
										<div class="pmpro_message pmpro_error">
											<p>
												<?php
												// translators: %s: Error message from Brevo.
												echo esc_html( sprintf( __( 'Could not connect to Brevo: %s', 'pmpro-brevo' ), $account->get_error_message() ) );
												?>
											</p>
										</div>
										<?php
									} else {
										$account_name = ! empty( $account['companyName'] ) ? $account['companyName'] : ( ! empty( $account['email'] ) ? $account['email'] : '' );
										?>
										<div class="pmpro_message pmpro_success">
											<p>
												<?php
												if ( '' !== $account_name ) {
													// translators: %s: Brevo account company name or email.
													echo esc_html( sprintf( __( 'Connected to the Brevo account %s.', 'pmpro-brevo' ), $account_name ) );
												} else {
													esc_html_e( 'Connected to Brevo.', 'pmpro-brevo' );
												}
												?>
											</p>
										</div>
										<?php
									}
									?>
								</td>
							</tr>
						<?php } ?>
						<tr>
							<th scope="row"><label for="update_on_profile_save"><?php esc_html_e( 'Sync on Profile Update', 'pmpro-brevo' ); ?></label></th>
							<td>
								<?php
								$update_on_profile_save = isset( $options['update_on_profile_save'] ) ? $options['update_on_profile_save'] : 'yes';
								?>
								<select name="update_on_profile_save" id="update_on_profile_save">
									<option value="yes" <?php selected( $update_on_profile_save, 'yes' ); ?>><?php esc_html_e( 'Yes, sync contact data and lists', 'pmpro-brevo' ); ?></option>
									<option value="contact_only" <?php selected( $update_on_profile_save, 'contact_only' ); ?>><?php esc_html_e( 'Yes, sync contact data only', 'pmpro-brevo' ); ?></option>
									<option value="no" <?php selected( $update_on_profile_save, 'no' ); ?>><?php esc_html_e( 'No, do not sync anything on profile update', 'pmpro-brevo' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Choose what to sync to Brevo when a user profile is updated in WordPress.', 'pmpro-brevo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="unsubscribe"><?php esc_html_e( 'Remove Lists When Membership Changes', 'pmpro-brevo' ); ?></label></th>
							<td>
								<?php
								$unsubscribe = isset( $options['unsubscribe'] ) ? $options['unsubscribe'] : 'yes';
								?>
								<select name="unsubscribe" id="unsubscribe">
									<option value="yes" <?php selected( $unsubscribe, 'yes' ); ?>><?php esc_html_e( 'Yes, remove from lists that no longer apply', 'pmpro-brevo' ); ?></option>
									<option value="no" <?php selected( $unsubscribe, 'no' ); ?>><?php esc_html_e( 'No, never remove from lists', 'pmpro-brevo' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'When enabled, the integration will remove the contact from Brevo lists when they no longer match the current membership. Only lists assigned to a membership level below are ever removed.', 'pmpro-brevo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="contact_status_mode"><?php esc_html_e( 'Contact Status', 'pmpro-brevo' ); ?></label></th>
							<td>
								<?php
								$contact_status_mode = isset( $options['contact_status_mode'] ) ? $options['contact_status_mode'] : 'respect';
								?>
								<select name="contact_status_mode" id="contact_status_mode">
									<option value="respect" <?php selected( $contact_status_mode, 'respect' ); ?>><?php esc_html_e( 'Respect existing status (leave unsubscribed contacts unsubscribed)', 'pmpro-brevo' ); ?></option>
									<option value="active" <?php selected( $contact_status_mode, 'active' ); ?>><?php esc_html_e( 'Always set to Active (re-enable email for unsubscribed contacts)', 'pmpro-brevo' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Controls what happens when a member has previously unsubscribed from your emails in Brevo. Respect existing status keeps them blacklisted for email, so they are added to lists but do not receive campaigns. Always set to Active removes the email blacklist each time the member is synced.', 'pmpro-brevo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="enable_async"><?php esc_html_e( 'Process Updates in the Background', 'pmpro-brevo' ); ?></label></th>
							<td>
								<?php
								$enable_async = isset( $options['enable_async'] ) ? $options['enable_async'] : 'yes';
								?>
								<select name="enable_async" id="enable_async">
									<option value="yes" <?php selected( $enable_async, 'yes' ); ?>><?php esc_html_e( 'Yes, run updates in the background', 'pmpro-brevo' ); ?></option>
									<option value="no" <?php selected( $enable_async, 'no' ); ?>><?php esc_html_e( 'No, run updates immediately', 'pmpro-brevo' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'When enabled, contact updates and list changes will run in the background using Action Scheduler. This can improve performance during checkout, profile updates, and membership changes.', 'pmpro-brevo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="enable_debug_log"><?php esc_html_e( 'Debug Logging', 'pmpro-brevo' ); ?></label></th>
							<td>
								<?php
								$enable_debug_log = isset( $options['enable_debug_log'] ) ? $options['enable_debug_log'] : 'no';
								?>
								<select name="enable_debug_log" id="enable_debug_log">
									<option value="yes" <?php selected( $enable_debug_log, 'yes' ); ?>><?php esc_html_e( 'Yes, enable debug logging', 'pmpro-brevo' ); ?></option>
									<option value="no" <?php selected( $enable_debug_log, 'no' ); ?>><?php esc_html_e( 'No, disable debug logging', 'pmpro-brevo' ); ?></option>
								</select>
								<p class="description">
									<?php
									esc_html_e( 'When enabled, the integration will write debug details to the log to help troubleshoot issues. The log includes member email addresses, names, and list assignments, so enable it only while troubleshooting.', 'pmpro-brevo' );
									if ( 'yes' === $enable_debug_log && function_exists( 'pmpro_get_restricted_file_path' ) ) {
										$log_file_link = add_query_arg(
											array(
												'pmpro_restricted_file_dir' => 'logs',
												'pmpro_restricted_file'     => 'pmpro-brevo.log',
											),
											home_url()
										);
										echo ' <a href="' . esc_url( $log_file_link ) . '" target="_blank">' . esc_html__( 'Download log.', 'pmpro-brevo' ) . '</a>';
									}
									?>
								</p>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<div class="pmpro_section" data-visibility="<?php echo empty( $options['api_key'] ) ? 'hidden' : 'shown'; ?>" data-activated="<?php echo empty( $options['api_key'] ) ? 'false' : 'true'; ?>">
				<div class="pmpro_section_toggle">
					<button class="pmpro_section-toggle-button" type="button" aria-expanded="<?php echo empty( $options['api_key'] ) ? 'false' : 'true'; ?>">
						<span class="dashicons <?php echo empty( $options['api_key'] ) ? 'dashicons-arrow-down-alt2' : 'dashicons-arrow-up-alt2'; ?>"></span>
						<?php esc_html_e( 'Assign Lists', 'pmpro-brevo' ); ?>
					</button>
				</div>
				<div class="pmpro_section_inside" style="<?php echo empty( $options['api_key'] ) ? 'display:none;' : ''; ?>">
					<?php
					$force_refresh = ! empty( $_GET['pmprobrevo_refresh_lists'] ) && ! empty( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'pmprobrevo_refresh_lists' );
					$lists         = ( $api->is_connected() && ! is_wp_error( $account ) ) ? $api->get_lists( $force_refresh ) : array();
					$refresh_url   = add_query_arg(
						array(
							'pmprobrevo_refresh_lists' => '1',
							'_wpnonce'                 => wp_create_nonce( 'pmprobrevo_refresh_lists' ),
						)
					);

					if ( ! $api->is_connected() ) {
						echo '<div class="pmpro_message pmpro_error"><p>' . esc_html__( 'Enter your API key above and save to connect to Brevo.', 'pmpro-brevo' ) . '</p></div>';
					} elseif ( is_wp_error( $account ) ) {
						echo '<div class="pmpro_message pmpro_error"><p>' . esc_html__( 'Lists will load once the connection to Brevo works. Check the Connection Status above.', 'pmpro-brevo' ) . '</p></div>';
					} elseif ( empty( $lists ) ) {
						?>
						<p>
							<?php esc_html_e( 'No lists found in your Brevo account.', 'pmpro-brevo' ); ?>
							<a href="<?php echo esc_url( $refresh_url ); ?>">
								<?php esc_html_e( 'Click here to refresh lists', 'pmpro-brevo' ); ?>
							</a>
						</p>
						<?php
					} else {
						?>
						<p>
							<?php echo esc_html__( 'Select the Brevo lists to add members to when they are added to each membership level.', 'pmpro-brevo' ) . ' '; ?>
							<a href="<?php echo esc_url( $refresh_url ); ?>">
								<?php esc_html_e( 'Click here to refresh lists', 'pmpro-brevo' ); ?>
							</a>
						</p>
						<input type="hidden" name="level_lists_shown" value="1">
						<table class="form-table">
							<?php
							foreach ( $pmpro_levels as $level ) {
								$key            = 'level_lists_' . $level->id;
								$selected_lists = isset( $options[ $key ] ) ? array_map( 'intval', (array) $options[ $key ] ) : array();
								?>
								<tr>
									<th scope="row"><?php echo esc_html( $level->name ); ?></th>
									<td>
										<?php
										$classes = array( 'pmpro_checkbox_box' );
										if ( count( $lists ) > 5 ) {
											$classes[] = 'pmpro_scrollable';
										}
										?>
										<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
											<?php
											foreach ( $lists as $list ) {
												$checked = in_array( (int) $list['id'], $selected_lists, true ) ? 'checked' : '';
												?>
												<div class="pmpro_clickable">
													<input type="checkbox" id="level_lists_<?php echo esc_attr( $level->id ); ?>_<?php echo esc_attr( $list['id'] ); ?>" name="level_lists_<?php echo esc_attr( $level->id ); ?>[]" value="<?php echo esc_attr( $list['id'] ); ?>" <?php echo esc_attr( $checked ); ?>>
													<label for="level_lists_<?php echo esc_attr( $level->id ); ?>_<?php echo esc_attr( $list['id'] ); ?>">
														<?php echo esc_html( $list['name'] ); ?>
													</label>
												</div>
												<?php
											}
											?>
										</div>
									</td>
								</tr>
								<?php
							}
							if ( ! empty( $options['retired_lists'] ) ) {
								$list_names = array();
								foreach ( $lists as $list ) {
									$list_names[ (int) $list['id'] ] = $list['name'];
								}
								$retired_names = array();
								foreach ( $options['retired_lists'] as $retired_list_id ) {
									$retired_names[] = isset( $list_names[ (int) $retired_list_id ] ) ? $list_names[ (int) $retired_list_id ] : '#' . (int) $retired_list_id;
								}
								?>
								<tr>
									<th scope="row"><?php esc_html_e( 'Previously Assigned Lists', 'pmpro-brevo' ); ?></th>
									<td>
										<p>
											<?php
											// translators: %s: Comma-separated list of Brevo list names.
											echo esc_html( sprintf( __( 'These lists are no longer assigned to a level: %s. Members are still removed from them when they next sync, so former assignments are cleaned up.', 'pmpro-brevo' ), implode( ', ', $retired_names ) ) );
											?>
										</p>
										<label>
											<input type="checkbox" name="forget_retired_lists" value="1">
											<?php esc_html_e( 'Stop removing members from these lists', 'pmpro-brevo' ); ?>
										</label>
									</td>
								</tr>
								<?php
							}
							?>
						</table>
						<?php
					}
					?>
				</div>
			</div>

			<?php wp_nonce_field( 'pmprobrevo_save_settings', 'pmprobrevo_settings_nonce' ); ?>
			<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Changes', 'pmpro-brevo' ); ?>">
		</form>
	</div>
	<?php
}

/**
 * Mask an API key for display, keeping only the last four characters.
 *
 * @since TBD
 *
 * @param string $api_key API key.
 * @return string Masked key.
 */
function pmprobrevo_mask_api_key( $api_key ) {
	return str_repeat( '*', 20 ) . substr( $api_key, -4 );
}

/**
 * Clear account-specific data after switching to a different Brevo account.
 *
 * Brevo list IDs and contact IDs are per-account, so mappings and stored IDs from the old account would point at unrelated lists and contacts.
 *
 * @since TBD
 *
 * @param array $options Plugin options.
 * @return array Options without list assignments.
 */
function pmprobrevo_reset_account_data( $options ) {
	foreach ( array_keys( $options ) as $key ) {
		if ( 0 === strpos( $key, 'level_lists_' ) ) {
			unset( $options[ $key ] );
		}
	}
	unset( $options['retired_lists'], $options['account_fingerprint'] );

	delete_metadata( 'user', 0, 'pmprobrevo_contact_id', '', true );
	delete_metadata( 'user', 0, 'pmprobrevo_synced', '', true );

	return $options;
}
