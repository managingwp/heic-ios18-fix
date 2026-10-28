<?php
/**
 * Plugin orchestrator.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin features to WordPress.
 */
final class Heic_Fix_Monitor {

	/**
	 * Singleton instance.
	 *
	 * @var Heic_Fix_Monitor|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Heic_Fix_Monitor
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function run() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
		add_action( 'wp_initialize_site', array( 'Heic_Fix_Monitor_Activator', 'provision_new_site' ), 20, 1 );

		Heic_Fix_Monitor_Cron::instance()->init();
		Heic_Fix_Monitor_Settings::instance()->init();
		Heic_Fix_Monitor_Notification_Manager::init();

		if ( is_admin() ) {
			Heic_Fix_Monitor_Admin::instance()->init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Heic_Fix_Monitor_CLI_Command::register();
		}
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'heic-fix-monitor', false, dirname( HFM_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Suggest privacy policy text.
	 *
	 * @return void
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__(
			'HEIC Fix Monitor checks this site\'s own server for HEIC/Imagick regressions. It collects no visitor data and does not store IP addresses. It stores normalized server evidence, check timestamps, configured administrator email addresses, and the destination hosts administrators explicitly configure for Slack or JSON webhook alerts. All of this data is removed when the plugin is uninstalled.',
			'heic-fix-monitor'
		) . '</p>';

		wp_add_privacy_policy_content( 'heic-fix-monitor', $content );
	}
}
