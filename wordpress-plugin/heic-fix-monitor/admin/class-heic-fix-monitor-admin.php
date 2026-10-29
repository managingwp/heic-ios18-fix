<?php
/**
 * Admin page and manual actions.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the status/settings pages and capability-gated actions.
 */
final class Heic_Fix_Monitor_Admin {

	/**
	 * Status page slug.
	 *
	 * @var string
	 */
	const STATUS_PAGE = 'hfm-monitor';

	/**
	 * Singleton instance.
	 *
	 * @var Heic_Fix_Monitor_Admin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return Heic_Fix_Monitor_Admin
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
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_hfm_run_check_now', array( $this, 'handle_run_check_now' ) );
		add_action( 'admin_post_hfm_send_test', array( $this, 'handle_send_test' ) );
		add_action( 'admin_post_hfm_reset_baseline', array( $this, 'handle_reset_baseline' ) );
	}

	/**
	 * Register Settings submenu pages.
	 *
	 * @return void
	 */
	public function add_pages() {
		add_options_page(
			__( 'HEIC Fix Monitor', 'heic-fix-monitor' ),
			__( 'HEIC Fix Monitor', 'heic-fix-monitor' ),
			'manage_options',
			self::STATUS_PAGE,
			array( $this, 'render_status_page' )
		);

		add_options_page(
			__( 'HEIC Fix Monitor Settings', 'heic-fix-monitor' ),
			__( 'HEIC Fix Monitor Settings', 'heic-fix-monitor' ),
			'manage_options',
			Heic_Fix_Monitor_Settings::PAGE,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue assets only on plugin pages.
	 *
	 * @param string $hook Current hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'settings_page_' . self::STATUS_PAGE, 'settings_page_' . Heic_Fix_Monitor_Settings::PAGE ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'heic-fix-monitor-admin',
			HFM_PLUGIN_URL . 'admin/css/heic-fix-monitor-admin.css',
			array(),
			HFM_VERSION
		);
	}

	/**
	 * Render the status page.
	 *
	 * @return void
	 */
	public function render_status_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		include HFM_PLUGIN_DIR . 'admin/partials/heic-fix-monitor-status.php';
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		include HFM_PLUGIN_DIR . 'admin/partials/heic-fix-monitor-settings.php';
	}

	/**
	 * Run a check now.
	 *
	 * @return void
	 */
	public function handle_run_check_now() {
		check_admin_referer( 'hfm_run_check_now', 'hfm_run_check_now_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run this check.', 'heic-fix-monitor' ), 403 );
		}

		if ( false !== get_transient( 'hfm_run_lock' ) ) {
			$this->redirect( self::STATUS_PAGE, 'locked' );
		}

		set_transient( 'hfm_run_lock', time(), 300 );

		$settings                 = Heic_Fix_Monitor_Settings::get();
		$settings['force_decode'] = true;

		$result = ( new Heic_Fix_Monitor_Probe_Engine() )->run( $settings, 'manual' );
		$event  = ( new Heic_Fix_Monitor_State_Store() )->record( $result );
		( new Heic_Fix_Monitor_Notification_Manager() )->dispatch( $event, $settings );

		delete_transient( 'hfm_run_lock' );

		$this->redirect( self::STATUS_PAGE, 'checked' );
	}

	/**
	 * Send one test notification.
	 *
	 * @return void
	 */
	public function handle_send_test() {
		check_admin_referer( 'hfm_send_test', 'hfm_send_test_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to send test notifications.', 'heic-fix-monitor' ), 403 );
		}

		$channel  = isset( $_POST['hfm_test_channel'] ) ? sanitize_key( wp_unslash( (string) $_POST['hfm_test_channel'] ) ) : '';
		$channels = array( 'email', 'slack', 'webhook' );

		if ( ! in_array( $channel, $channels, true ) ) {
			$this->redirect( self::STATUS_PAGE, 'test_invalid' );
		}

		$result = ( new Heic_Fix_Monitor_Notification_Manager() )->send_test( $channel, Heic_Fix_Monitor_Settings::get() );
		$code   = isset( $result['status'] ) && 'sent' === $result['status'] ? 'test_sent' : ( 'skipped' === ( $result['status'] ?? '' ) ? 'test_skipped' : 'test_failed' );

		$this->redirect( self::STATUS_PAGE, $code );
	}

	/**
	 * Reset plugin state and notification timestamps.
	 *
	 * @return void
	 */
	public function handle_reset_baseline() {
		check_admin_referer( 'hfm_reset_baseline', 'hfm_reset_baseline_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to reset the baseline.', 'heic-fix-monitor' ), 403 );
		}

		$confirmed = isset( $_POST['hfm_confirm_reset'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['hfm_confirm_reset'] ) );
		if ( ! $confirmed ) {
			$this->redirect( self::STATUS_PAGE, 'reset_unconfirmed' );
		}

		Heic_Fix_Monitor_State_Store::reset();
		delete_transient( 'hfm_last_decode' );

		$this->redirect( self::STATUS_PAGE, 'reset' );
	}

	/**
	 * Redirect back to a plugin page with a code-only notice.
	 *
	 * @param string $page Page slug.
	 * @param string $code Notice code.
	 * @return void
	 */
	private function redirect( $page, $code ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => $page,
					'hfm_notice' => sanitize_key( $code ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Human state label.
	 *
	 * @param string $state State.
	 * @return string
	 */
	public static function state_label( $state ) {
		$labels = array(
			'ok'       => __( 'OK', 'heic-fix-monitor' ),
			'degraded' => __( 'Degraded', 'heic-fix-monitor' ),
			'affected' => __( 'Affected', 'heic-fix-monitor' ),
			'missing'  => __( 'Missing', 'heic-fix-monitor' ),
			'error'    => __( 'Error', 'heic-fix-monitor' ),
		);

		return $labels[ $state ] ?? __( 'Unknown', 'heic-fix-monitor' );
	}

	/**
	 * Dashicon for a state, paired with the text label.
	 *
	 * @param string $state State.
	 * @return string
	 */
	public static function state_icon( $state ) {
		$icons = array(
			'ok'       => 'dashicons-yes-alt',
			'degraded' => 'dashicons-warning',
			'affected' => 'dashicons-dismiss',
			'missing'  => 'dashicons-marker',
			'error'    => 'dashicons-editor-help',
		);

		return $icons[ $state ] ?? 'dashicons-editor-help';
	}

	/**
	 * Flatten signal evidence for display.
	 *
	 * @param array<string,mixed> $signal Signal.
	 * @return array<int,string>
	 */
	public static function evidence_lines( array $signal ) {
		$out   = array();
		$value = $signal['evidence'] ?? array();

		if ( ! is_array( $value ) ) {
			return $out;
		}

		$walk = static function ( $data, $prefix ) use ( &$walk, &$out ) {
			foreach ( (array) $data as $key => $item ) {
				if ( count( $out ) >= 12 ) {
					return;
				}
				$key   = (string) $key;
				$label = '' === $prefix ? $key : $prefix . '.' . $key;
				if ( preg_match( '/(secret|token|password|authorization)/i', $label ) ) {
					continue;
				}
				if ( is_array( $item ) ) {
					$walk( $item, $label );
					continue;
				}
				$out[] = $label . '=' . ( is_bool( $item ) ? ( $item ? 'true' : 'false' ) : (string) $item );
			}
		};

		$walk( $value, '' );

		return $out;
	}

	/**
	 * Format a timestamp.
	 *
	 * @param int $timestamp Timestamp.
	 * @return string
	 */
	public static function format_time( $timestamp ) {
		$timestamp = (int) $timestamp;
		if ( $timestamp <= 0 ) {
			return __( 'Never', 'heic-fix-monitor' );
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp ) . ' UTC';
	}
}
