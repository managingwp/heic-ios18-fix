<?php
/**
 * heic-fix.sh check probe.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses the stable quiet output contract of heic-fix.sh check.
 */
final class Heic_Fix_Monitor_Check_Probe implements Heic_Fix_Monitor_Probe_Interface {

	/**
	 * Required output fields.
	 *
	 * @var array<int,string>
	 */
	const FIELDS = array( 'status', 'imagick', 'imagemagick', 'heic', 'libheif', 'linkage', 'decode' );

	/**
	 * Allowed per-version status values.
	 *
	 * @var array<int,string>
	 */
	const STATUS_VALUES = array( 'ok', 'affected', 'no-imagick' );

	/**
	 * Run the probe.
	 *
	 * @param array<string,mixed> $context Probe context.
	 * @return array<string,mixed>
	 */
	public function run( array $context ) {
		$settings  = isset( $context['settings'] ) && is_array( $context['settings'] ) ? $context['settings'] : array();
		$runner    = isset( $context['runner'] ) ? $context['runner'] : new Heic_Fix_Monitor_Command_Runner();
		$versions  = isset( $context['php_versions'] ) && is_array( $context['php_versions'] ) ? $context['php_versions'] : array();
		$timeout   = isset( $settings['timeout'] ) ? (int) $settings['timeout'] : 30;

		if ( empty( $settings['check_enabled'] ) ) {
			return Heic_Fix_Monitor_Probe_Result::make(
				'check_command',
				'unavailable',
				'check_disabled',
				__( 'The check probe is disabled in settings.', 'heic-fix-monitor' )
			);
		}

		if ( ! $runner->available() ) {
			return Heic_Fix_Monitor_Probe_Result::make(
				'check_command',
				'unavailable',
				'exec_disabled',
				__( 'Process execution is disabled, so heic-fix.sh check cannot run.', 'heic-fix-monitor' ),
				array(
					'proc_open' => false,
					'exec'      => false,
				)
			);
		}

		$binary = isset( $settings['check_binary'] ) ? (string) $settings['check_binary'] : '';
		if ( ! self::is_allowed_binary( $binary ) ) {
			return Heic_Fix_Monitor_Probe_Result::make(
				'check_command',
				'unavailable',
				'check_path_not_allowed',
				__( 'The configured check binary is not an allowlisted heic-fix.sh.', 'heic-fix-monitor' )
			);
		}

		$evidence = array();
		$affected = false;
		$failed   = false;

		foreach ( $versions as $version ) {
			$version = (string) $version;
			if ( ! preg_match( '/^[0-9]+\.[0-9]+$/', $version ) ) {
				return Heic_Fix_Monitor_Probe_Result::make(
					'check_command',
					'error',
					'php_version_invalid',
					__( 'A configured PHP version is not in X.Y form.', 'heic-fix-monitor' )
				);
			}

			$result = $runner->run( $binary, array( 'check', '-q', '--php', $version ), $timeout );

			if ( function_exists( 'apply_filters' ) ) {
				$result = apply_filters( 'hfm_command_result', $result, $binary, array( 'check', '-q', '--php', $version ) );
				$result = is_array( $result ) ? $result : array( 'error' => 'filter_invalid' );
			}

			if ( ! empty( $result['timed_out'] ) ) {
				return Heic_Fix_Monitor_Probe_Result::make(
					'check_command',
					'error',
					'check_timeout',
					__( 'heic-fix.sh check did not finish within the timeout.', 'heic-fix-monitor' ),
					array(
						'timeout_seconds' => $timeout,
						'terminated'      => true,
						'exit_code'       => null,
					)
				);
			}

			if ( isset( $result['error'] ) && null !== $result['error'] ) {
				return Heic_Fix_Monitor_Probe_Result::make(
					'check_command',
					'unavailable',
					(string) $result['error'],
					__( 'heic-fix.sh check could not be executed.', 'heic-fix-monitor' ),
					array( 'error' => (string) $result['error'] )
				);
			}

			$exit = isset( $result['exit_code'] ) ? (int) $result['exit_code'] : -1;

			if ( 2 === $exit ) {
				$failed = true;
				$evidence[ 'php' . $version ] = array(
					'exit_code' => 2,
					'status'    => 'error',
				);
				continue;
			}

			$parsed = self::parse_output( (string) $result['stdout'], array( $version ) );
			if ( ! $parsed['ok'] ) {
				return Heic_Fix_Monitor_Probe_Result::make(
					'check_command',
					'error',
					'check_malformed',
					__( 'heic-fix.sh check output did not match the stable field contract.', 'heic-fix-monitor' ),
					array(
						'missing_fields' => implode( ',', $parsed['missing'] ),
						'exit_code'      => $exit,
					)
				);
			}

			$fields = $parsed['versions'][ $version ];
			$evidence[ 'php' . $version ] = array(
				'status'      => $fields['status'],
				'imagick'     => $fields['imagick'],
				'imagemagick' => $fields['imagemagick'],
				'heic'        => $fields['heic'],
				'libheif'     => $fields['libheif'],
				'linkage'     => $fields['linkage'],
				'decode'      => $fields['decode'],
				'exit_code'   => $exit,
			);

			if ( 'affected' === $fields['status'] || 'no-imagick' === $fields['status'] || 1 === $exit || 3 === $exit ) {
				$affected = true;
			}

			if ( 'static-hidden' !== $fields['linkage'] || 'yes' !== $fields['heic'] || 'ok' !== $fields['decode'] ) {
				$affected = true;
			}
		}

		if ( $affected ) {
			$code = 'check_affected';
			foreach ( $evidence as $entry ) {
				if ( isset( $entry['linkage'] ) && 'static-hidden' !== $entry['linkage'] ) {
					$code = 'linkage_' . preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $entry['linkage'] ) );
					break;
				}
				if ( isset( $entry['status'] ) && 'no-imagick' === $entry['status'] ) {
					$code = 'no_imagick';
					break;
				}
				if ( isset( $entry['decode'] ) && 'ok' !== $entry['decode'] ) {
					$code = 'decode_failed';
					break;
				}
			}

			return Heic_Fix_Monitor_Probe_Result::make(
				'check_command',
				'affected',
				$code,
				__( 'heic-fix.sh check reported an affected PHP version.', 'heic-fix-monitor' ),
				$evidence
			);
		}

		if ( $failed ) {
			return Heic_Fix_Monitor_Probe_Result::make(
				'check_command',
				'error',
				'check_error',
				__( 'heic-fix.sh check could not complete its own probe.', 'heic-fix-monitor' ),
				$evidence
			);
		}

		return Heic_Fix_Monitor_Probe_Result::make(
			'check_command',
			'ok',
			'check_ok',
			__( 'heic-fix.sh check reported ok for every target.', 'heic-fix-monitor' ),
			$evidence
		);
	}

	/**
	 * Parse quiet output.
	 *
	 * @param string            $stdout   Captured stdout.
	 * @param array<int,string> $versions Expected versions.
	 * @return array<string,mixed>
	 */
	public static function parse_output( $stdout, array $versions ) {
		$parsed  = array();
		$missing = array();
		$lines   = preg_split( '/\r\n|\r|\n/', (string) $stdout );

		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 !== strpos( $line, 'php' ) ) {
				continue;
			}

			if ( ! preg_match( '/^php([0-9]+\.[0-9]+)\s+(.+)$/', $line, $matches ) ) {
				return array(
					'ok'       => false,
					'error'    => 'line_invalid',
					'missing'  => array( 'line_format' ),
					'versions' => array(),
				);
			}

			$version = $matches[1];
			if ( isset( $parsed[ $version ] ) ) {
				return array(
					'ok'       => false,
					'error'    => 'duplicate_version',
					'missing'  => array( 'duplicate:' . $version ),
					'versions' => array(),
				);
			}

			$fields = array();
			$missing = array();
			foreach ( preg_split( '/\s+/', trim( $matches[2] ) ) as $pair ) {
				if ( ! preg_match( '/^([a-z_]+)=(\S+)$/', $pair, $field ) ) {
					return array(
						'ok'       => false,
						'error'    => 'field_invalid',
						'missing'  => array( 'field_format' ),
						'versions' => array(),
					);
				}
				if ( isset( $fields[ $field[1] ] ) ) {
					return array(
						'ok'       => false,
						'error'    => 'field_duplicate',
						'missing'  => array( 'duplicate:' . $field[1] ),
						'versions' => array(),
					);
				}
				$fields[ $field[1] ] = $field[2];
			}

			foreach ( self::FIELDS as $required ) {
				if ( ! isset( $fields[ $required ] ) ) {
					$missing[] = $required;
				}
			}

			foreach ( array_keys( $fields ) as $key ) {
				if ( ! in_array( $key, self::FIELDS, true ) ) {
					return array(
						'ok'       => false,
						'error'    => 'unknown_field',
						'missing'  => array( 'unknown:' . $key ),
						'versions' => array(),
					);
				}
			}

			if ( ! empty( $missing ) ) {
				return array(
					'ok'       => false,
					'error'    => 'field_missing',
					'missing'  => $missing,
					'versions' => array(),
				);
			}

			if ( ! in_array( $fields['status'], self::STATUS_VALUES, true ) ) {
				return array(
					'ok'       => false,
					'error'    => 'status_invalid',
					'missing'  => array( 'status_value' ),
					'versions' => array(),
				);
			}

			$parsed[ $version ] = $fields;
		}

		foreach ( $versions as $version ) {
			if ( ! isset( $parsed[ (string) $version ] ) ) {
				$missing[] = (string) $version;
			}
		}

		return array(
			'ok'       => empty( $missing ) && ! empty( $parsed ),
			'error'    => empty( $missing ) ? null : 'version_missing',
			'missing'  => $missing,
			'versions' => $parsed,
		);
	}

	/**
	 * Map an exit code to a state.
	 *
	 * @param int $exit Exit code.
	 * @return string
	 */
	public static function map_exit( $exit ) {
		$map = array(
			0 => 'ok',
			1 => 'affected',
			2 => 'error',
			3 => 'affected',
		);

		return isset( $map[ (int) $exit ] ) ? $map[ (int) $exit ] : 'error';
	}

	/**
	 * Whether the path is an allowlisted heic-fix.sh.
	 *
	 * @param string $path Configured path.
	 * @return bool
	 */
	public static function is_allowed_binary( $path ) {
		$path = (string) $path;
		if ( '' === $path || '/' !== substr( $path, 0, 1 ) || 'heic-fix.sh' !== basename( $path ) ) {
			return false;
		}

		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) || ! is_executable( $real ) ) {
			return false;
		}

		$allowlist = array( '/opt/heic-ios18-fix/heic-fix.sh' );
		if ( defined( 'HEIC_FIX_MONITOR_ALLOWED_CHECK_PATHS' ) ) {
			$constant = constant( 'HEIC_FIX_MONITOR_ALLOWED_CHECK_PATHS' );
			if ( is_array( $constant ) ) {
				$allowlist = array_merge( $allowlist, $constant );
			}
		}

		foreach ( $allowlist as $allowed ) {
			$allowed_real = realpath( (string) $allowed );
			if ( false !== $allowed_real && $allowed_real === $real ) {
				return true;
			}
		}

		return false;
	}
}
