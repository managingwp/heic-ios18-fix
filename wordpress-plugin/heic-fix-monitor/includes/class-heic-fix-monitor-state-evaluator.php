<?php
/**
 * Aggregate state evaluation.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies the documented precedence rules to probe signals.
 */
final class Heic_Fix_Monitor_State_Evaluator {

	/**
	 * Signals in reporting order.
	 *
	 * @var array<int,string>
	 */
	const SIGNALS = array( 'imagick_decode', 'check_command', 'filesystem_elf' );

	/**
	 * Evaluate signals.
	 *
	 * @param array<string,mixed> $signals Signals keyed by name.
	 * @return array{state:string,codes:array<int,string>}
	 */
	public function evaluate( array $signals ) {
		$statuses = array();
		$codes    = array();

		foreach ( self::SIGNALS as $name ) {
			if ( ! isset( $signals[ $name ] ) || ! is_array( $signals[ $name ] ) ) {
				continue;
			}

			$status            = isset( $signals[ $name ]['status'] ) ? (string) $signals[ $name ]['status'] : 'error';
			$statuses[ $name ] = $status;

			if ( 'ok' !== $status && isset( $signals[ $name ]['code'] ) ) {
				$codes[] = Heic_Fix_Monitor_Probe_Result::bounded_key( (string) $signals[ $name ]['code'] );
			}
		}

		$has = static function ( $status ) use ( $statuses ) {
			return in_array( $status, $statuses, true );
		};

		$primary = $statuses['imagick_decode'] ?? null;
		$healthy = 'ok' === $primary;
		if ( null === $primary ) {
			$healthy = $has( 'ok' );
		}

		$state = 'error';

		if ( $has( 'affected' ) ) {
			$state = 'affected';
		} elseif ( $has( 'missing' ) ) {
			$state = 'missing';
		} elseif ( 'error' === $primary ) {
			$state = 'error';
		} elseif ( $has( 'error' ) ) {
			$state = $healthy ? 'degraded' : 'error';
		} elseif ( $has( 'degraded' ) || $has( 'unavailable' ) || $has( 'skipped' ) ) {
			$state = 'degraded';
		} elseif ( $healthy ) {
			$state = 'ok';
		}

		if ( 'ok' === $state && empty( $codes ) ) {
			foreach ( self::SIGNALS as $name ) {
				if ( isset( $signals[ $name ]['code'] ) ) {
					$codes[] = Heic_Fix_Monitor_Probe_Result::bounded_key( (string) $signals[ $name ]['code'] );
				}
			}
		}

		$codes = array_values( array_unique( array_filter( $codes ) ) );
		sort( $codes, SORT_STRING );

		return array(
			'state' => $state,
			'codes' => array_slice( $codes, 0, 10 ),
		);
	}
}
