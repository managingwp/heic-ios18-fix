<?php
/**
 * Probe engine.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs enabled probes and normalizes their signals.
 */
final class Heic_Fix_Monitor_Probe_Engine {

	/**
	 * Run all enabled probes.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param string              $source   cron|cli|manual.
	 * @return array<string,mixed>
	 */
	public function run( array $settings, $source = 'cron' ) {
		$versions = isset( $settings['php_versions'] ) && is_array( $settings['php_versions'] ) ? $settings['php_versions'] : array();
		$versions = array_values( array_filter( array_map( 'strval', $versions ), static function ( $version ) {
			return (bool) preg_match( '/^[0-9]+\.[0-9]+$/', $version );
		} ) );

		if ( empty( $versions ) ) {
			$versions = array( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION );
		}

		sort( $versions, SORT_NATURAL );

		$runner  = new Heic_Fix_Monitor_Command_Runner();
		$context = array(
			'settings'     => $settings,
			'source'       => (string) $source,
			'php_versions' => $versions,
			'runner'       => $runner,
			'force_decode' => ! empty( $settings['force_decode'] ),
		);

		$signals = array();

		$probes = array(
			'filesystem_elf' => 'Heic_Fix_Monitor_Filesystem_Probe',
			'check_command'  => 'Heic_Fix_Monitor_Check_Probe',
			'imagick_decode' => 'Heic_Fix_Monitor_Imagick_Probe',
		);

		foreach ( $probes as $name => $class ) {
			if ( ! class_exists( $class ) ) {
				$signals[ $name ] = Heic_Fix_Monitor_Probe_Result::make(
					$name,
					'error',
					'probe_unavailable',
					__( 'The probe class is unavailable.', 'heic-fix-monitor' )
				);
				continue;
			}

			try {
				$probe = new $class();
				$signal = $probe->run( $context );
			} catch ( Throwable $exception ) {
				$signal = Heic_Fix_Monitor_Probe_Result::make(
					$name,
					'error',
					'probe_exception',
					__( 'The probe stopped unexpectedly.', 'heic-fix-monitor' )
				);
			}

			$signals[ $name ] = Heic_Fix_Monitor_Probe_Result::normalize( $name, $signal );
		}

		if ( function_exists( 'apply_filters' ) ) {
			$signals = apply_filters( 'hfm_probe_signals', $signals, $settings, $source );
			if ( ! is_array( $signals ) ) {
				$signals = array();
			}
		}

		$normalized = array();
		foreach ( array( 'imagick_decode', 'check_command', 'filesystem_elf' ) as $name ) {
			if ( isset( $signals[ $name ] ) ) {
				$normalized[ $name ] = Heic_Fix_Monitor_Probe_Result::normalize( $name, $signals[ $name ] );
			}
		}

		$result = array(
			'schema_version' => 1,
			'php_versions'   => $versions,
			'signals'        => $normalized,
			'checked_at'     => time(),
			'source'         => (string) $source,
			'state'          => 'error',
			'codes'          => array(),
		);

		if ( class_exists( 'Heic_Fix_Monitor_State_Evaluator' ) ) {
			$evaluation      = ( new Heic_Fix_Monitor_State_Evaluator() )->evaluate( $normalized );
			$result['state'] = $evaluation['state'];
			$result['codes'] = $evaluation['codes'];
		}

		return $result;
	}
}
