<?php
/**
 * Plugin Name:       HEIC Fix Monitor
 * Plugin URI:        https://managingwp.github.io/heic-ios18-fix/monitoring/
 * Description:       Read-only monitoring for the iOS 18 HEIC/Imagick repair, with transition-based email, Slack and JSON webhook alerts.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Managing WP
 * Author URI:        https://managingwp.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       heic-fix-monitor
 * Domain Path:       /languages
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HFM_VERSION', '0.1.0' );
define( 'HFM_PLUGIN_FILE', __FILE__ );
define( 'HFM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HFM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HFM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load the plugin classes.
 *
 * @return void
 */
function hfm_load_classes() {
	$files = array(
		'includes/class-heic-fix-monitor-secret-store.php',
		'includes/probes/interface-heic-fix-monitor-probe.php',
		'includes/probes/class-heic-fix-monitor-probe-result.php',
		'includes/probes/class-heic-fix-monitor-command-runner.php',
		'includes/probes/class-heic-fix-monitor-imagick-probe.php',
		'includes/probes/class-heic-fix-monitor-check-probe.php',
		'includes/probes/class-heic-fix-monitor-filesystem-probe.php',
		'includes/probes/class-heic-fix-monitor-probe-engine.php',
		'includes/class-heic-fix-monitor-normalizer.php',
		'includes/class-heic-fix-monitor-state-evaluator.php',
		'includes/class-heic-fix-monitor-state-store.php',
		'includes/notifications/interface-heic-fix-monitor-notifier.php',
		'includes/notifications/class-heic-fix-monitor-message-builder.php',
		'includes/notifications/class-heic-fix-monitor-url-validator.php',
		'includes/notifications/class-heic-fix-monitor-email-notifier.php',
		'includes/notifications/class-heic-fix-monitor-slack-notifier.php',
		'includes/notifications/class-heic-fix-monitor-webhook-notifier.php',
		'includes/notifications/class-heic-fix-monitor-notification-manager.php',
		'includes/class-heic-fix-monitor-activator.php',
		'includes/class-heic-fix-monitor-deactivator.php',
		'admin/class-heic-fix-monitor-settings.php',
		'admin/class-heic-fix-monitor-admin.php',
		'includes/class-heic-fix-monitor.php',
	);

	foreach ( $files as $file ) {
		require_once HFM_PLUGIN_DIR . $file;
	}
}

hfm_load_classes();

register_activation_hook( __FILE__, array( 'Heic_Fix_Monitor_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Heic_Fix_Monitor_Deactivator', 'deactivate' ) );

/**
 * Begin plugin execution.
 *
 * @return void
 */
function hfm_run() {
	Heic_Fix_Monitor::instance()->run();
}

hfm_run();
