<?php
/**
 * Fingerprint normalization.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces the stable fingerprint document.
 */
final class Heic_Fix_Monitor_Normalizer {

	/**
	 * Build the normalized document and fingerprint.
	 *
	 * @param array<string,mixed> $result Probe result.
	 * @return array{document:array<string,mixed>,fingerprint:string}
	 */
	public function build( array $result ) {
		$state   = isset( $result['state'] ) ? (string) $result['state'] : 'error';
		$codes   = isset( $result['codes'] ) && is_array( $result['codes'] ) ? array_map( 'strval', $result['codes'] ) : array();
		$signals = isset( $result['signals'] ) && is_array( $result['signals'] ) ? $result['signals'] : array();

		$codes = array_values( array_unique( $codes ) );
		sort( $codes, SORT_STRING );

		$normalized = array();
		foreach ( array( 'imagick_decode', 'check_command', 'filesystem_elf' ) as $name ) {
			if ( ! isset( $signals[ $name ] ) || ! is_array( $signals[ $name ] ) ) {
				continue;
			}

			$normalized[ $name ] = array(
				'status'   => isset( $signals[ $name ]['status'] ) ? (string) $signals[ $name ]['status'] : 'error',
				'code'     => isset( $signals[ $name ]['code'] ) ? (string) $signals[ $name ]['code'] : 'unknown',
				'evidence' => $this->normalize_value( $signals[ $name ]['evidence'] ?? array(), 0 ),
			);
		}
		ksort( $normalized, SORT_STRING );

		$document = array(
			'schema_version' => 1,
			'state'          => $state,
			'codes'          => $codes,
			'signals'        => $normalized,
		);

		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $document ) : json_encode( $document ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- CLI test fallback.

		return array(
			'document'    => $document,
			'fingerprint' => hash( 'sha256', (string) $json ),
		);
	}

	/**
	 * Normalize evidence keys, ordering and scalar types.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	private function normalize_value( $value, $depth ) {
		if ( $depth > 3 ) {
			return null;
		}

		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			$clean   = array();

			foreach ( $value as $key => $item ) {
				$clean_key           = $is_list ? (int) $key : Heic_Fix_Monitor_Probe_Result::bounded_key( (string) $key );
				$clean[ $clean_key ] = $this->normalize_value( $item, $depth + 1 );
			}

			if ( ! $is_list ) {
				ksort( $clean, SORT_STRING );
			}

			return $clean;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		return Heic_Fix_Monitor_Probe_Result::bounded_text( (string) $value, 120 );
	}
}
