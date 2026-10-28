<?php
/**
 * WP-CLI command.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only `wp heic-monitor check`.
 */
final class Heic_Fix_Monitor_CLI_Command {

	/**
	 * Register the command.
	 *
	 * @return void
	 */
	public static function register() {
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'heic-monitor', new self() );
		}
	}

	/**
	 * Run one read-only check.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json. Default: table.
	 *
	 * [--notify]
	 * : Dispatch transition notifications for this run.
	 *
	 * [--force-decode]
	 * : Bypass the cron decode interval for this run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp heic-monitor check --format=json
	 *     wp heic-monitor check --notify
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function check( $args, $assoc_args ) {
		if ( ! empty( $args ) ) {
			WP_CLI::error( 'This command accepts no positional arguments.' );
		}

		$allowed = array( 'format', 'notify', 'force-decode' );
		foreach ( array_keys( (array) $assoc_args ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				WP_CLI::error( sprintf( 'Unknown option: %s', $key ) );
			}
		}

		$format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';
		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			WP_CLI::error( 'The format must be table or json.' );
		}

		if ( false !== get_transient( 'hfm_run_lock' ) ) {
			WP_CLI::error( 'Another HEIC Fix Monitor check is already running.' );
		}

		set_transient( 'hfm_run_lock', time(), 10 * MINUTE_IN_SECONDS );

		try {
			$settings                 = Heic_Fix_Monitor_Settings::get();
			$settings['force_decode'] = ! empty( $assoc_args['force-decode'] );

			$result = ( new Heic_Fix_Monitor_Probe_Engine() )->run( $settings, 'cli' );
			$event  = ( new Heic_Fix_Monitor_State_Store() )->record( $result );

			$delivery = array(
				'event_id' => '',
				'results'  => array(),
			);

			if ( ! empty( $assoc_args['notify'] ) ) {
				$delivery = ( new Heic_Fix_Monitor_Notification_Manager() )->dispatch( $event, $settings );
			}
		} finally {
			delete_transient( 'hfm_run_lock' );
		}

		$output = array(
			'schema_version' => 1,
			'state'          => $result['state'],
			'codes'          => $result['codes'],
			'php_versions'   => $result['php_versions'],
			'signals'        => $result['signals'],
			'checked_at'     => gmdate( 'c', $result['checked_at'] ),
			'event'          => $event['type'],
			'fingerprint'    => $event['fingerprint'],
			'delivery'       => array(
				'event_id' => $delivery['event_id'],
				'results'  => $delivery['results'],
			),
		);

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $output ) );
		} else {
			WP_CLI::line( 'state: ' . $output['state'] );
			WP_CLI::line( 'event: ' . $output['event'] );
			WP_CLI::line( 'codes: ' . implode( ', ', $output['codes'] ) );
			foreach ( $result['signals'] as $name => $signal ) {
				WP_CLI::line( sprintf( '%s: %s (%s)', $name, $signal['status'], $signal['code'] ) );
			}
			WP_CLI::line( 'checked: ' . $output['checked_at'] );
		}

		$exit = array(
			'ok'       => 0,
			'affected' => 1,
			'missing'  => 1,
			'error'    => 2,
			'degraded' => 3,
		);

		WP_CLI::halt( $exit[ $result['state'] ] ?? 2 );
	}
}
