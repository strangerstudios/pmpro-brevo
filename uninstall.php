<?php
/**
 * Remove plugin data when the plugin is deleted.
 *
 * Brevo contacts and lists are not changed.
 *
 * @since TBD
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Delete the log file, which contains member emails and names.
if ( function_exists( 'pmpro_get_restricted_file_path' ) ) {
	$pmprobrevo_log_file = pmpro_get_restricted_file_path( 'logs', 'pmpro-brevo.log' );
	if ( ! empty( $pmprobrevo_log_file ) && file_exists( $pmprobrevo_log_file ) ) {
		wp_delete_file( $pmprobrevo_log_file );
	}
}

// Cancel pending sync tasks.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'pmprobrevo_sync_contact_for_user' );
	as_unschedule_all_actions( 'pmprobrevo_remove_deleted_contact' );
}

delete_option( 'pmprobrevo_options' );
delete_transient( 'pmprobrevo_all_lists' );
delete_metadata( 'user', 0, 'pmprobrevo_contact_id', '', true );
delete_metadata( 'user', 0, 'pmprobrevo_synced', '', true );
