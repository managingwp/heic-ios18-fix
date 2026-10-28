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
		'includes/class-heic-fix-monitor-activator.php',
		'includes/class-heic-fix-monitor-deactivator.php',
		'admin/class-heic-fix-monitor-settings.php',
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
