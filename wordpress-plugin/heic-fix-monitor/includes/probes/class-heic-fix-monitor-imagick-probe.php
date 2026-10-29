<?php
/**
 * Primary in-FPM Imagick decode probe.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decodes the verified iOS 18 sample through Imagick in the current SAPI.
 */
final class Heic_Fix_Monitor_Imagick_Probe implements Heic_Fix_Monitor_Probe_Interface {

	/**
	 * Run the probe.
	 *
	 * @param array<string,mixed> $context Probe context.
	 * @return array<string,mixed>
	 */
	public function run( array $context ) {
		$settings = isset( $context['settings'] ) && is_array( $context['settings'] ) ? $context['settings'] : array();
		$source   = isset( $context['source'] ) ? (string) $context['source'] : 'cron';
		$force    = ! empty( $context['force_decode'] );

		if ( empty( $settings['decode_enabled'] ) ) {
			return Heic_Fix_Monitor_Probe_Result::make(
				'imagick_decode',
				'unavailable',
				'decode_disabled',
				__( 'The primary decode probe is disabled in settings.', 'heic-fix-monitor' )
			);
		}

		if ( ! extension_loaded( 'imagick' ) ) {
			return Heic_Fix_Monitor_Probe_Result::make(
				'imagick_decode',
				'affected',
				'imagick_missing',
				__( 'Imagick is not loaded in the PHP SAPI running this check.', 'heic-fix-monitor' ),
				array(
					'sapi'    => PHP_SAPI,
					'imagick' => false,
				)
			);
		}

		try {
			$formats = Imagick::queryFormats( 'HEI*' );
		} catch ( Throwable $exception ) {
			$formats = array();
		}

		if ( empty( $formats ) ) {
			return Heic_Fix_Monitor_Probe_Result::make(
				'imagick_decode',
				'affected',
				'heic_coder_missing',
				__( 'This ImageMagick build has no HEIC coder.', 'heic-fix-monitor' ),
				array(
					'sapi'         => PHP_SAPI,
					'imagick'      => true,
					'heic_formats' => false,
				)
			);
		}

		$sample = $this->resolve_sample( $settings );
		if ( is_wp_error( $sample ) ) {
			return Heic_Fix_Monitor_Probe_Result::make(
				'imagick_decode',
				'missing',
				$sample->get_error_code(),
				$sample->get_error_message(),
				array( 'sample' => 'unusable' )
			);
		}

		$memory = $this->memory_bytes();
		$floor  = max( 64, (int) $settings['memory_floor_mb'] ) * 1024 * 1024;
		if ( PHP_INT_MAX !== $memory && $memory < $floor ) {
			return Heic_Fix_Monitor_Probe_Result::make(
				'imagick_decode',
				'skipped',
				'memory_guard',
				__( 'Decode skipped: the memory limit is below the configured guard.', 'heic-fix-monitor' ),
				array(
					'memory_limit'    => (string) ini_get( 'memory_limit' ),
					'required_memory' => (int) $settings['memory_floor_mb'] . 'M',
				)
			);
		}

		$interval = max( 60, (int) $settings['decode_min_interval'] );
		$last     = (int) get_transient( 'hfm_last_decode' );
		if ( ! $force && 'cron' === $source && $last > 0 && ( time() - $last ) < $interval ) {
			return Heic_Fix_Monitor_Probe_Result::make(
				'imagick_decode',
				'skipped',
				'decode_rate_limited',
				__( 'Decode skipped: the previous decode ran inside the minimum interval.', 'heic-fix-monitor' ),
				array(
					'source'       => $source,
					'min_interval' => $interval,
				)
			);
		}

		set_transient( 'hfm_last_decode', time(), HOUR_IN_SECONDS );

		$started = microtime( true );
		$image   = null;

		try {
			$image = new Imagick( $sample['path'] );
			$image->setImageFormat( 'jpeg' );
			$blob   = $image->getImageBlob();
			$bytes  = strlen( $blob );
			$width  = (int) $image->getImageWidth();
			$height = (int) $image->getImageHeight();
			$image->clear();
			$image->destroy();

			$min_bytes  = max( 128, (int) $settings['min_jpeg_bytes'] );
			$min_width  = max( 1, (int) $settings['min_width'] );
			$min_height = max( 1, (int) $settings['min_height'] );

			if ( $bytes < $min_bytes || $width < $min_width || $height < $min_height ) {
				return Heic_Fix_Monitor_Probe_Result::make(
					'imagick_decode',
					'affected',
					'decode_output_small',
					__( 'The sample decoded, but the JPEG output is below the configured thresholds.', 'heic-fix-monitor' ),
					array(
						'sapi'       => PHP_SAPI,
						'decode'     => 'weak',
						'jpeg_bytes' => $bytes,
						'width'      => $width,
						'height'     => $height,
					)
				);
			}

			return Heic_Fix_Monitor_Probe_Result::make(
				'imagick_decode',
				'ok',
				'decode_ok',
				__( 'The verified sample decoded through Imagick in this SAPI.', 'heic-fix-monitor' ),
				array(
					'sapi'          => PHP_SAPI,
					'imagick'       => true,
					'heic_formats'  => true,
					'decode'        => 'ok',
					'jpeg_bytes'    => $bytes,
					'width'         => $width,
					'height'        => $height,
					'sample_source' => $sample['source'],
					'duration_ms'   => (int) round( ( microtime( true ) - $started ) * 1000 ),
				)
			);
		} catch ( Throwable $exception ) {
			if ( $image instanceof Imagick ) {
				$image->clear();
				$image->destroy();
			}

			$message = $exception->getMessage();

			return Heic_Fix_Monitor_Probe_Result::make(
				'imagick_decode',
				'affected',
				$this->classify_exception( $message ),
				__( 'The sample could not be decoded through Imagick in this SAPI.', 'heic-fix-monitor' ),
				array(
					'sapi'         => PHP_SAPI,
					'imagick'      => true,
					'heic_formats' => true,
					'decode'       => 'fail',
					'error_class'  => $this->classify_exception( $message ),
					'duration_ms'  => (int) round( ( microtime( true ) - $started ) * 1000 ),
				)
			);
		}
	}

	/**
	 * Resolve and validate the sample file.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array{path:string,source:string}|WP_Error
	 */
	private function resolve_sample( array $settings ) {
		$configured = isset( $settings['sample_path'] ) ? trim( (string) $settings['sample_path'] ) : '';

		if ( '' !== $configured ) {
			$path   = $configured;
			$source = 'configured';
			if ( '/' !== substr( $path, 0, 1 ) || false !== strpos( $path, '..' ) ) {
				return new WP_Error( 'sample_invalid', __( 'The configured sample path is not an absolute safe path.', 'heic-fix-monitor' ) );
			}
		} else {
			$path   = HFM_PLUGIN_DIR . 'assets/failure_magick.HEIC';
			$source = 'bundled';
		}

		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'sample_missing', __( 'The HEIC sample is missing or unreadable.', 'heic-fix-monitor' ) );
		}

		$size = filesize( $path );
		if ( false === $size || $size < 1024 || $size > 52428800 ) {
			return new WP_Error( 'sample_size_invalid', __( 'The HEIC sample has an unexpected size.', 'heic-fix-monitor' ) );
		}

		if ( ! preg_match( '/\.(heic|heif)$/i', $path ) ) {
			return new WP_Error( 'sample_type_invalid', __( 'The sample must be a .heic or .heif file.', 'heic-fix-monitor' ) );
		}

		if ( 'bundled' === $source ) {
			$expected = $this->expected_checksum();
			$actual   = hash_file( 'sha256', $path );
			if ( '' === $expected || ! is_string( $actual ) || ! hash_equals( $expected, $actual ) ) {
				return new WP_Error( 'sample_checksum_failed', __( 'The bundled sample failed its checksum.', 'heic-fix-monitor' ) );
			}
		}

		return array(
			'path'   => $path,
			'source' => $source,
		);
	}

	/**
	 * Read the bundled sample checksum.
	 *
	 * @return string
	 */
	private function expected_checksum() {
		$file = HFM_PLUGIN_DIR . 'assets/SHA256SUMS';
		if ( ! is_readable( $file ) ) {
			return '';
		}

		$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		foreach ( (array) $lines as $line ) {
			if ( preg_match( '/^([a-f0-9]{64})\s+\*?(\S+)$/i', trim( $line ), $matches ) && 'failure_magick.HEIC' === basename( $matches[2] ) ) {
				return strtolower( $matches[1] );
			}
		}

		return '';
	}

	/**
	 * Convert memory_limit to bytes.
	 *
	 * @return int
	 */
	private function memory_bytes() {
		$value = trim( (string) ini_get( 'memory_limit' ) );
		if ( '' === $value || '-1' === $value ) {
			return PHP_INT_MAX;
		}

		$unit  = strtolower( substr( $value, -1 ) );
		$bytes = (int) $value;

		switch ( $unit ) {
			case 'g':
				$bytes *= 1024;
				// Fall through.
			case 'm':
				$bytes *= 1024;
				// Fall through.
			case 'k':
				$bytes *= 1024;
				break;
		}

		return $bytes;
	}

	/**
	 * Classify a decode exception without storing the raw message.
	 *
	 * @param string $message Exception message.
	 * @return string
	 */
	private function classify_exception( $message ) {
		$message = (string) $message;

		if ( false !== stripos( $message, 'Too many auxiliary image references' ) ) {
			return 'auxiliary_image_references';
		}
		if ( false !== stripos( $message, 'No decoding plugin installed' ) || false !== stripos( $message, 'Unsupported codec' ) ) {
			return 'hevc_plugin_missing';
		}
		if ( false !== stripos( $message, 'Failed to read the file' ) ) {
			return 'read_failed';
		}
		if ( false !== stripos( $message, 'undefined symbol' ) ) {
			return 'symbol_error';
		}

		return 'decode_error';
	}
}
