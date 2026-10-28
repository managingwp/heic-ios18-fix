<?php
/**
 * Signal factory and validation.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds bounded, schema-conforming signals.
 */
final class Heic_Fix_Monitor_Probe_Result {

	/**
	 * Allowed signal statuses.
	 *
	 * @var array<int,string>
	 */
	const STATUSES = array( 'ok', 'degraded', 'affected', 'missing', 'error', 'unavailable', 'skipped' );

	/**
	 * Build a signal.
	 *
	 * @param string              $signal   Signal name.
	 * @param string              $status   Status.
	 * @param string              $code     Machine code.
	 * @param string              $summary  Short summary.
	 * @param array<string,mixed> $evidence Bounded evidence.
	 * @return array<string,mixed>
	 */
	public static function make( $signal, $status, $code, $summary, array $evidence = array() ) {
		$signal = sanitize_key( (string) $signal );
		$status = in_array( $status, self::STATUSES, true ) ? $status : 'error';

		return array(
			'signal'   => $signal,
			'status'   => $status,
			'code'     => self::bounded_key( $code ),
			'summary'  => self::bounded_text( $summary, 200 ),
			'evidence' => self::bounded_evidence( $evidence ),
		);
	}

	/**
	 * Normalize any signal array, rejecting unknown shapes as an error.
	 *
	 * @param string $signal Signal name.
	 * @param mixed  $value  Candidate signal.
	 * @return array<string,mixed>
	 */
	public static function normalize( $signal, $value ) {
		if ( ! is_array( $value ) || ! isset( $value['status'], $value['code'] ) ) {
			return self::make( $signal, 'error', 'signal_invalid', __( 'The probe returned an invalid signal.', 'heic-fix-monitor' ) );
		}

		return self::make(
			$signal,
			(string) $value['status'],
			(string) $value['code'],
			isset( $value['summary'] ) && is_string( $value['summary'] ) ? $value['summary'] : '',
			isset( $value['evidence'] ) && is_array( $value['evidence'] ) ? $value['evidence'] : array()
		);
	}

	/**
	 * Bound a text value.
	 *
	 * @param string $value  Raw value.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	public static function bounded_text( $value, $length = 200 ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = preg_replace( '/[\r\n\t]+/', ' ', $value );

		return mb_substr( trim( (string) $value ), 0, $length );
	}

	/**
	 * Bound a machine key.
	 *
	 * @param string $value Raw key.
	 * @return string
	 */
	public static function bounded_key( $value ) {
		return substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $value ) ), 0, 64 );
	}

	/**
	 * Bound evidence recursively.
	 *
	 * @param mixed $value  Evidence value.
	 * @param int   $depth  Current depth.
	 * @param int   $budget Item budget.
	 * @return mixed
	 */
	public static function bounded_evidence( $value, $depth = 0, &$budget = null ) {
		if ( null === $budget ) {
			$budget = 40;
		}

		if ( $budget <= 0 || $depth > 3 ) {
			return null;
		}

		--$budget;

		if ( is_array( $value ) ) {
			$clean = array();
			$count = 0;
			foreach ( $value as $key => $item ) {
				if ( $count >= 20 || $budget <= 0 ) {
					break;
				}
				$key = is_int( $key ) ? (string) $key : self::bounded_key( (string) $key );
				$clean[ $key ] = self::bounded_evidence( $item, $depth + 1, $budget );
				++$count;
			}

			return $clean;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		return self::bounded_text( (string) $value, 120 );
	}
}
