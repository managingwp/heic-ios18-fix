<?php
/**
 * State persistence and transition events.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores the current result and derives one event per run.
 */
final class Heic_Fix_Monitor_State_Store {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'hfm_state';

	/**
	 * Record a probe result.
	 *
	 * @param array<string,mixed> $result Probe result.
	 * @return array<string,mixed>
	 */
	public function record( array $result ) {
		$normalizer = new Heic_Fix_Monitor_Normalizer();
		$normalized = $normalizer->build( $result );

		$state       = (string) $normalized['document']['state'];
		$codes       = (array) $normalized['document']['codes'];
		$fingerprint = (string) $normalized['fingerprint'];
		$now         = $this->now();
		$settings    = class_exists( 'Heic_Fix_Monitor_Settings' ) ? Heic_Fix_Monitor_Settings::get() : get_option( 'hfm_settings', array() );
		$settings    = is_array( $settings ) ? $settings : array();
		$window      = max( 1, (int) ( $settings['renotify_hours'] ?? 24 ) ) * 3600;

		$raw      = get_option( self::OPTION, false );
		$has_raw  = false !== $raw;
		$stored   = $has_raw ? $raw : array();
		$previous = $this->valid_state( $stored );

		$previous_state       = $previous ? (string) $previous['state'] : null;
		$previous_fingerprint = $previous ? (string) $previous['fingerprint'] : '';
		$previous_notified    = $previous ? (int) $previous['last_notified'] : 0;
		$first_seen           = $previous ? (int) $previous['first_seen'] : $now;
		$last_changed         = $previous ? (int) $previous['last_changed'] : $now;
		$last_good            = $previous ? (int) $previous['last_good'] : 0;

		$state_changed = null === $previous_state || $previous_state !== $state;
		$changed       = $state_changed || $previous_fingerprint !== $fingerprint;

		if ( null === $previous_state ) {
			$type = 'ok' === $state ? 'baseline' : 'regression';
		} elseif ( 'ok' === $previous_state && 'ok' !== $state ) {
			$type = 'regression';
		} elseif ( 'ok' !== $previous_state && 'ok' === $state ) {
			$type = 'recovery';
		} elseif ( $previous_state === $state ) {
			if ( $previous_fingerprint === $fingerprint ) {
				$type = ( 'ok' !== $state && 0 !== $previous_notified && ( $now - $previous_notified ) >= $window )
					? 'reminder'
					: 'none';
			} else {
				$type = 'ok' === $state ? 'none' : 'change';
			}
		} else {
			$type = 'change';
		}

		if ( $state_changed ) {
			$first_seen = $now;
		}

		if ( $changed ) {
			$last_changed = $now;
		}

		if ( 'ok' === $state ) {
			$last_good = $now;
		}

		$notified = in_array( $type, array( 'regression', 'change', 'recovery', 'reminder' ), true );

		$stored_new = array(
			'schema_version' => 1,
			'state'          => $state,
			'codes'          => $codes,
			'fingerprint'    => $fingerprint,
			'document'       => $normalized['document'],
			'php_versions'   => isset( $result['php_versions'] ) && is_array( $result['php_versions'] ) ? array_values( array_map( 'strval', $result['php_versions'] ) ) : array(),
			'first_seen'     => $first_seen,
			'last_checked'   => $now,
			'last_changed'   => $last_changed,
			'last_good'      => $last_good,
			'last_notified'  => $notified ? $now : $previous_notified,
			'last_reminder'  => 'reminder' === $type ? $now : ( $previous ? (int) $previous['last_reminder'] : 0 ),
			'last_source'    => isset( $result['source'] ) ? Heic_Fix_Monitor_Probe_Result::bounded_key( (string) $result['source'] ) : 'cron',
		);

		update_option( self::OPTION, $stored_new, false );

		return array(
			'type'                 => $type,
			'is_notification'      => $notified,
			'state'                => $state,
			'previous_state'       => $previous_state,
			'codes'                => $codes,
			'fingerprint'          => $fingerprint,
			'previous_fingerprint' => $previous_fingerprint,
			'changed'              => $changed,
			'document'             => $normalized['document'],
			'checked_at'           => $now,
			'first_seen'           => $first_seen,
			'last_changed'         => $last_changed,
			'last_good'            => $last_good,
			'last_notified'        => $stored_new['last_notified'],
			'recovered_store'      => $has_raw && null === $previous,
		);
	}

	/**
	 * Read the stored state for display.
	 *
	 * @return array<string,mixed>
	 */
	public static function current() {
		$stored = get_option( self::OPTION, array() );
		$store  = new self();

		return $store->valid_state( $stored ) ? $stored : array();
	}

	/**
	 * Reset the stored state.
	 *
	 * @return void
	 */
	public static function reset() {
		delete_option( self::OPTION );
	}

	/**
	 * Current time, filterable for tests.
	 *
	 * @return int
	 */
	private function now() {
		$now = time();

		if ( function_exists( 'apply_filters' ) ) {
			$now = (int) apply_filters( 'hfm_now', $now );
		}

		return $now;
	}

	/**
	 * Validate a stored state document.
	 *
	 * @param mixed $stored Stored value.
	 * @return array<string,mixed>|null
	 */
	private function valid_state( $stored ) {
		if ( ! is_array( $stored ) || ! isset( $stored['schema_version'], $stored['state'], $stored['fingerprint'] ) ) {
			return null;
		}

		if ( 1 !== (int) $stored['schema_version'] || ! is_string( $stored['fingerprint'] ) || ! is_string( $stored['state'] ) ) {
			return null;
		}

		$defaults = array(
			'codes'         => array(),
			'document'      => array(),
			'php_versions'  => array(),
			'first_seen'    => 0,
			'last_checked'  => 0,
			'last_changed'  => 0,
			'last_good'     => 0,
			'last_notified' => 0,
			'last_reminder' => 0,
			'last_source'   => 'cron',
		);

		return array_merge( $defaults, $stored );
	}
}
