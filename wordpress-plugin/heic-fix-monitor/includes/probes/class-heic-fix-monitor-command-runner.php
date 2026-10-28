<?php
/**
 * Bounded process runner.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs fixed binaries without a shell, with a hard timeout and capped output.
 */
final class Heic_Fix_Monitor_Command_Runner {

	/**
	 * Output cap per stream.
	 *
	 * @var int
	 */
	const MAX_BYTES = 65536;

	/**
	 * Run a command.
	 *
	 * @param string       $binary  Absolute binary path.
	 * @param array<mixed> $args    Arguments.
	 * @param int          $timeout Timeout in seconds.
	 * @return array<string,mixed>
	 */
	public function run( $binary, array $args, $timeout = 30 ) {
		$result = array(
			'ok'          => false,
			'error'       => null,
			'exit_code'   => null,
			'timed_out'   => false,
			'stdout'      => '',
			'stderr'      => '',
			'duration_ms' => 0,
		);

		if ( ! function_exists( 'proc_open' ) || $this->is_disabled( 'proc_open' ) ) {
			$result['error'] = 'exec_disabled';
			return $result;
		}

		$binary = is_string( $binary ) ? $binary : '';
		if ( '' === $binary || ! is_file( $binary ) || ! is_executable( $binary ) || '/' !== substr( $binary, 0, 1 ) ) {
			$result['error'] = 'binary_invalid';
			return $result;
		}

		$command = array_merge( array( $binary ), array_map( 'strval', $args ) );
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$env = array(
			'PATH'     => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
			'LANG'     => 'C',
			'LC_ALL'   => 'C',
			'HOME'     => '/nonexistent',
		);

		$started = microtime( true );
		$process = @proc_open( $command, $descriptors, $pipes, '/', $env, array( 'bypass_shell' => true ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A disabled or missing binary is an expected probe outcome.

		if ( ! is_resource( $process ) ) {
			$result['error'] = 'proc_open_failed';
			return $result;
		}

		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$deadline = $started + max( 1, (int) $timeout );
		$stdout   = '';
		$stderr   = '';

		while ( true ) {
			$read = array( $pipes[1], $pipes[2] );
			$write = null;
			$except = null;
			$remaining = $deadline - microtime( true );

			if ( $remaining <= 0 ) {
				$result['timed_out'] = true;
				proc_terminate( $process, 9 );
				break;
			}

			$seconds = (int) floor( min( 1.0, $remaining ) );
			$micro   = (int) ( ( min( 1.0, $remaining ) - $seconds ) * 1000000 );
			$ready   = @stream_select( $read, $write, $except, $seconds, $micro ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Interrupted select is handled by the loop.

			foreach ( array( 1 => 'stdout', 2 => 'stderr' ) as $index => $target ) {
				while ( isset( $pipes[ $index ] ) && is_resource( $pipes[ $index ] ) ) {
					$chunk = fread( $pipes[ $index ], 8192 );
					if ( false === $chunk || '' === $chunk ) {
						break;
					}
					if ( strlen( $$target ) < self::MAX_BYTES ) {
						$$target .= substr( $chunk, 0, self::MAX_BYTES - strlen( $$target ) );
					}
				}
			}

			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				$result['exit_code'] = (int) $status['exitcode'];
				break;
			}

			if ( false === $ready ) {
				usleep( 20000 );
			}
		}

		foreach ( array( $pipes[1], $pipes[2] ) as $pipe ) {
			if ( is_resource( $pipe ) ) {
				fclose( $pipe );
			}
		}

		$closed = proc_close( $process );
		if ( null === $result['exit_code'] && ! $result['timed_out'] ) {
			$result['exit_code'] = (int) $closed;
		}

		$result['stdout']      = $stdout;
		$result['stderr']      = $stderr;
		$result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		$result['ok']          = ! $result['timed_out'] && 0 === (int) $result['exit_code'];

		return $result;
	}

	/**
	 * Build an audit string with escaped arguments.
	 *
	 * @param string       $binary Binary.
	 * @param array<mixed> $args   Arguments.
	 * @return string
	 */
	public static function format_command( $binary, array $args ) {
		$parts = array( escapeshellarg( (string) $binary ) );
		foreach ( $args as $arg ) {
			$parts[] = escapeshellarg( (string) $arg );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Whether a function is disabled.
	 *
	 * @param string $function Function name.
	 * @return bool
	 */
	public function is_disabled( $function ) {
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return in_array( $function, $disabled, true );
	}

	/**
	 * Whether process execution is usable at all.
	 *
	 * @return bool
	 */
	public function available() {
		return function_exists( 'proc_open' ) && ! $this->is_disabled( 'proc_open' );
	}
}
