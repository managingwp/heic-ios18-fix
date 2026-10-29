<?php
/**
 * WP-Cron scheduling and execution.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules site-scoped checks and the optional digest.
 */
final class Heic_Fix_Monitor_Cron {

	/**
	 * Check hook.
	 *
	 * @var string
	 */
	const CHECK_HOOK = 'hfm_run_check';

	/**
	 * Digest hook.
	 *
	 * @var string
	 */
	const DIGEST_HOOK = 'hfm_daily_digest';

	/**
	 * Singleton.
	 *
	 * @var Heic_Fix_Monitor_Cron|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return Heic_Fix_Monitor_Cron
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
		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
		add_action( self::CHECK_HOOK, array( $this, 'run_check' ) );
		add_action( self::DIGEST_HOOK, array( $this, 'run_digest' ) );
		add_action( 'update_option_hfm_settings', array( $this, 'maybe_reschedule' ), 10, 2 );
	}

	/**
	 * Add custom intervals.
	 *
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public function add_schedules( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		$schedules['hfm_15min']  = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (HEIC Fix Monitor)', 'heic-fix-monitor' ),
		);
		$schedules['hfm_30min']  = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 30 minutes (HEIC Fix Monitor)', 'heic-fix-monitor' ),
		);
		$schedules['hfm_6hours'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours (HEIC Fix Monitor)', 'heic-fix-monitor' ),
		);

		return $schedules;
	}

	/**
	 * Schedule both events for the current site.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return void
	 */
	public static function schedule_all( array $settings ) {
		self::unschedule_all();

		$interval = isset( $settings['interval'] ) && isset( Heic_Fix_Monitor_Settings::INTERVALS[ $settings['interval'] ] )
			? (string) $settings['interval']
			: 'hourly';

		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, $interval, self::CHECK_HOOK );

		if ( ! empty( $settings['digest_enabled'] ) ) {
			$next = self::next_digest_timestamp( isset( $settings['digest_time'] ) ? (string) $settings['digest_time'] : '08:00' );
			wp_schedule_event( $next, 'daily', self::DIGEST_HOOK );
		}
	}

	/**
	 * Clear every plugin event.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		$hooks = array( self::CHECK_HOOK, self::DIGEST_HOOK, Heic_Fix_Monitor_Notification_Manager::RETRY_HOOK );

		foreach ( $hooks as $hook ) {
			if ( function_exists( 'wp_unschedule_hook' ) ) {
				wp_unschedule_hook( $hook );
			} else {
				wp_clear_scheduled_hook( $hook );
			}
		}
	}

	/**
	 * Reschedule after a settings change.
	 *
	 * @param mixed $old_value Old settings.
	 * @param mixed $new_value New settings.
	 * @return void
	 */
	public function maybe_reschedule( $old_value, $new_value ) {
		if ( ! is_array( $new_value ) ) {
			return;
		}

		$old_value = is_array( $old_value ) ? $old_value : array();
		$keys      = array( 'interval', 'digest_enabled', 'digest_time' );

		foreach ( $keys as $key ) {
			if ( ( $old_value[ $key ] ?? null ) !== ( $new_value[ $key ] ?? null ) ) {
				self::schedule_all( $new_value );
				return;
			}
		}
	}

	/**
	 * Run one scheduled check.
	 *
	 * @return void
	 */
	public function run_check() {
		if ( false !== get_transient( 'hfm_run_lock' ) ) {
			return;
		}

		set_transient( 'hfm_run_lock', time(), 10 * MINUTE_IN_SECONDS );

		try {
			$settings = Heic_Fix_Monitor_Settings::get();
			$result   = ( new Heic_Fix_Monitor_Probe_Engine() )->run( $settings, 'cron' );
			$event    = ( new Heic_Fix_Monitor_State_Store() )->record( $result );
			( new Heic_Fix_Monitor_Notification_Manager() )->dispatch( $event, $settings );
		} finally {
			delete_transient( 'hfm_run_lock' );
		}
	}

	/**
	 * Send the optional daily digest or heartbeat.
	 *
	 * @return void
	 */
	public function run_digest() {
		$settings = Heic_Fix_Monitor_Settings::get();
		if ( empty( $settings['digest_enabled'] ) || empty( $settings['email_enabled'] ) ) {
			return;
		}

		$state  = Heic_Fix_Monitor_State_Store::current();
		$status = isset( $state['state'] ) ? (string) $state['state'] : 'unknown';

		if ( 'ok' === $status && empty( $settings['heartbeat_enabled'] ) ) {
			return;
		}

		$recipients = array();
		foreach ( (array) ( $settings['emails'] ?? array() ) as $email ) {
			if ( is_string( $email ) && is_email( $email ) ) {
				$recipients[] = $email;
			}
		}

		if ( empty( $recipients ) ) {
			return;
		}

		$site  = wp_parse_url( home_url(), PHP_URL_HOST );
		$lines = array(
			sprintf( 'HEIC Fix Monitor digest for %s', is_string( $site ) && '' !== $site ? $site : 'site' ),
			'State: ' . Heic_Fix_Monitor_Admin::state_label( $status ) . ' (' . $status . ')',
			'Last checked: ' . Heic_Fix_Monitor_Admin::format_time( $state['last_checked'] ?? 0 ),
			'Last good: ' . Heic_Fix_Monitor_Admin::format_time( $state['last_good'] ?? 0 ),
		);

		if ( ! empty( $state['codes'] ) && is_array( $state['codes'] ) ) {
			$lines[] = 'Reason codes: ' . implode( ', ', array_map( 'strval', $state['codes'] ) );
		}

		if ( ! empty( $settings['heartbeat_enabled'] ) ) {
			$next    = wp_next_scheduled( self::CHECK_HOOK );
			$lines[] = 'Heartbeat: WP-Cron is scheduled; next check ' . ( $next ? Heic_Fix_Monitor_Admin::format_time( $next ) : 'not scheduled' );
		}

		$lines[] = 'Repair and rollback: https://managingwp.github.io/heic-ios18-fix/troubleshooting/';

		wp_mail(
			$recipients,
			sprintf( '[HEIC Fix Monitor] %s digest', 'ok' === $status ? 'Daily' : 'Attention' ),
			implode( "\n", $lines ),
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);
	}

	/**
	 * Next digest timestamp in the site timezone.
	 *
	 * @param string $time HH:MM.
	 * @return int
	 */
	private static function next_digest_timestamp( $time ) {
		$time = preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time ) ? $time : '08:00';
		$zone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );

		try {
			$next = new DateTimeImmutable( 'today ' . $time, $zone );
		} catch ( Exception $exception ) {
			$next = new DateTimeImmutable( 'today 08:00', $zone );
		}

		if ( $next->getTimestamp() <= time() ) {
			$next = $next->modify( '+1 day' );
		}

		return $next->getTimestamp();
	}
}
