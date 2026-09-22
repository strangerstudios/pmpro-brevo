<?php
/**
 * Plugin Name: Paid Memberships Pro - Brevo Add On
 * Plugin URI: https://www.paidmembershipspro.com/add-ons/brevo-integration/
 * Description: Connect Paid Memberships Pro to Brevo to add members as contacts and manage lists automatically.
 * Version: 1.0
 * Author: Paid Memberships Pro
 * Author URI: https://www.paidmembershipspro.com
 * Text Domain: pmpro-brevo
 * Domain Path: /languages
 * License: GPL-3.0+
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Requires PHP: 7.4
 * Requires at least: 5.6
 * Tested up to: 6.9
*/

defined( 'ABSPATH' ) || exit;

define( 'PMPROBREVO_VERSION', '1.0' );
define( 'PMPROBREVO_DIR', plugin_dir_path( __FILE__ ) );
define( 'PMPROBREVO_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load plugin files after all plugins have loaded.
 *
 * @since TBD
 */
function pmprobrevo_load_plugin() {
	if ( ! defined( 'PMPRO_VERSION' ) ) {
		return;
	}

	require_once PMPROBREVO_DIR . 'classes/class-pmpro-brevo-api.php';
	require_once PMPROBREVO_DIR . 'includes/functions.php';
	require_once PMPROBREVO_DIR . 'includes/admin.php';
}
add_action( 'plugins_loaded', 'pmprobrevo_load_plugin' );

/**
 * Show a notice after the plugin is activated.
 *
 * @since TBD
 */
function pmprobrevo_activation() {
	set_transient( 'pmprobrevo-admin-notice', true, 5 );
}
register_activation_hook( __FILE__, 'pmprobrevo_activation' );

/**
 * Admin notice on activation.
 *
 * @since TBD
 */
function pmprobrevo_admin_notice() {
	if ( get_transient( 'pmprobrevo-admin-notice' ) ) {
		?>
		<div class="updated notice is-dismissible">
			<p>
				<?php
				esc_html_e( 'Thank you for activating the Brevo Add On.', 'pmpro-brevo' );
				echo ' <a href="' . esc_url( admin_url( 'admin.php?page=pmpro-brevo' ) ) . '">';
				esc_html_e( 'Click here to configure settings.', 'pmpro-brevo' );
				echo '</a>';
				?>
			</p>
		</div>
		<?php
		delete_transient( 'pmprobrevo-admin-notice' );
	}
}
add_action( 'admin_notices', 'pmprobrevo_admin_notice' );

/**
 * Add a Settings link to the plugin action links.
 *
 * @since TBD
 *
 * @param array $links Array of links.
 * @return array
 */
function pmprobrevo_plugin_action_links( $links ) {
	if ( current_user_can( 'manage_options' ) ) {
		$new_links = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=pmpro-brevo' ) ) . '">' . esc_html__( 'Settings', 'pmpro-brevo' ) . '</a>',
		);
		$links = array_merge( $new_links, $links );
	}
	return $links;
}
add_filter( 'plugin_action_links_' . PMPROBREVO_BASENAME, 'pmprobrevo_plugin_action_links' );

/**
 * Add Docs and Support links to the plugin row meta.
 *
 * @since TBD
 *
 * @param array  $links Array of links.
 * @param string $file  Plugin basename.
 * @return array
 */
function pmprobrevo_plugin_row_meta( $links, $file ) {
	if ( strpos( $file, 'pmpro-brevo.php' ) !== false ) {
		$new_links = array(
			'<a href="https://www.paidmembershipspro.com/add-ons/brevo-integration/" title="' . esc_attr__( 'View Documentation', 'pmpro-brevo' ) . '">' . esc_html__( 'Docs', 'pmpro-brevo' ) . '</a>',
			'<a href="https://www.paidmembershipspro.com/support/" title="' . esc_attr__( 'Visit Customer Support Forum', 'pmpro-brevo' ) . '">' . esc_html__( 'Support', 'pmpro-brevo' ) . '</a>',
		);
		$links = array_merge( $links, $new_links );
	}
	return $links;
}
add_filter( 'plugin_row_meta', 'pmprobrevo_plugin_row_meta', 10, 2 );

/**
 * Show an admin notice if PMPro is not active.
 *
 * @since TBD
 */
function pmprobrevo_admin_notice_no_pmpro() {
	if ( defined( 'PMPRO_VERSION' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'Paid Memberships Pro - Brevo Add On requires Paid Memberships Pro to be installed and active.', 'pmpro-brevo' ); ?></p>
	</div>
	<?php
}
add_action( 'admin_notices', 'pmprobrevo_admin_notice_no_pmpro' );
