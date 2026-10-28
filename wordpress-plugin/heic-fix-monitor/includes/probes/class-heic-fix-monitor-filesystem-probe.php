<?php
/**
 * Filesystem and ELF probe.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads prefix artifacts, ini state and ELF linkage without changing anything.
 */
final class Heic_Fix_Monitor_Filesystem_Probe implements Heic_Fix_Monitor_Probe_Interface {

	/**
	 * Recorded version keys the probe trusts.
	 *
	 * @var array<int,string>
	 */
	const VERSION_KEYS = array( 'IMAGEMAGICK_LINKAGE_REV', 'LIBHEIF_VERSION', 'LIBDE265_MODE' );

	/**
	 * Run the probe.
	 *
	 * @param array<string,mixed> $context Probe context.
	 * @return array<string,mixed>
	 */
	public function run( array $context ) {
		$settings = isset( $context['settings'] ) && is_array( $context['settings'] ) ? $context['settings'] : array();
		$versions = isset( $context['php_versions'] ) && is_array( $context['php_versions'] ) ? $context['php_versions'] : array();
		$runner   = isset( $context['runner'] ) ? $context['runner'] : new Heic_Fix_Monitor_Command_Runner();

		if ( empty( $settings['filesystem_enabled'] ) ) {
			return Heic_Fix_Monitor_Probe_Result::make(
				'filesystem_elf',
				'unavailable',
				'filesystem_disabled',
				__( 'The filesystem probe is disabled in settings.', 'heic-fix-monitor' )
			);
		}

		$prefix = isset( $settings['prefix'] ) ? rtrim( (string) $settings['prefix'], '/' ) : '/opt/heic-ios18-fix';
		if ( '' === $prefix || '/' !== substr( $prefix, 0, 1 ) ) {
			return Heic_Fix_Monitor_Probe_Result::make(
				'filesystem_elf',
				'error',
				'prefix_invalid',
				__( 'The configured prefix is not an absolute path.', 'heic-fix-monitor' )
			);
		}

		if ( ! is_dir( $prefix ) ) {
			return Heic_Fix_Monitor_Probe_Result::make(
				'filesystem_elf',
				'missing',
				'prefix_missing',
				__( 'The configured install prefix is absent.', 'heic-fix-monitor' ),
				array(
					'prefix'          => 'missing',
					'static_archives' => 'missing',
				)
			);
		}

		$evidence = array( 'prefix' => 'present' );
		$recorded = $this->read_versions( $prefix . '/installed-versions.txt' );
		$evidence = array_merge( $evidence, $recorded['evidence'] );

		$static = $prefix . '/heif-static/lib';
		$archives_ok = is_file( $static . '/libheif.a' );
		if ( 'system' !== ( $recorded['values']['LIBDE265_MODE'] ?? '' ) ) {
			$archives_ok = $archives_ok && is_file( $static . '/libde265.a' );
		}

		if ( ! $archives_ok ) {
			return Heic_Fix_Monitor_Probe_Result::make(
				'filesystem_elf',
				'missing',
				'static_archives_missing',
				__( 'The static libheif archives required by the fix are absent.', 'heic-fix-monitor' ),
				array_merge( $evidence, array( 'static_archives' => 'missing' ) )
			);
		}
		$evidence['static_archives'] = 'present';

		$affected   = false;
		$codes      = array();
		$extensions = array();

		foreach ( $versions as $version ) {
			$version = (string) $version;
			if ( ! preg_match( '/^[0-9]+\.[0-9]+$/', $version ) ) {
				return Heic_Fix_Monitor_Probe_Result::make(
					'filesystem_elf',
					'error',
					'php_version_invalid',
					__( 'A configured PHP version is not in X.Y form.', 'heic-fix-monitor' )
				);
			}

			$extension = $prefix . '/php/' . $version . '/imagick.so';
			$ini       = '/etc/php/' . $version . '/mods-available/imagick-heic.ini';
			$entry     = array(
				'extension' => is_file( $extension ) ? 'present' : 'missing',
				'ini'       => is_file( $ini ) ? 'present' : 'missing',
			);

			if ( 'missing' === $entry['extension'] ) {
				$affected = true;
				$codes[]  = 'extension_missing';
			}

			if ( 'missing' === $entry['ini'] ) {
				$affected = true;
				$codes[]  = 'ini_missing';
				$entry['ini_enabled'] = false;
			} else {
				$contents = $this->read_file( $ini );
				$enabled  = false !== strpos( $contents, 'extension=' . $extension )
					|| false !== strpos( $contents, 'extension = ' . $extension );
				$entry['ini_enabled'] = $enabled;
				if ( ! $enabled ) {
					$affected = true;
					$codes[]  = 'ini_mismatch';
				}
			}

			$extensions[ 'php' . $version ] = $entry;
		}
		$evidence['extensions'] = $extensions;

		if ( isset( $recorded['values']['IMAGEMAGICK_LINKAGE_REV'] ) ) {
			$evidence['linkage_rev'] = (string) $recorded['values']['IMAGEMAGICK_LINKAGE_REV'];
		}
		if ( isset( $recorded['values']['LIBHEIF_VERSION'] ) ) {
			$evidence['libheif_version'] = ltrim( (string) $recorded['values']['LIBHEIF_VERSION'], 'v' );
		}

		$core = $this->find_magickcore( $prefix . '/imagemagick/lib' );
		if ( '' === $core ) {
			return Heic_Fix_Monitor_Probe_Result::make(
				'filesystem_elf',
				'missing',
				'magickcore_missing',
				__( 'libMagickCore was not found in the prefix.', 'heic-fix-monitor' ),
				array_merge( $evidence, array( 'magickcore' => 'missing' ) )
			);
		}

		$elf      = $this->inspect_elf( $core, $runner );
		$evidence = array_merge( $evidence, $elf['evidence'] );

		if ( $elf['checked'] ) {
			if ( ! empty( $elf['shared_libheif'] ) ) {
				$affected = true;
				$codes[]  = 'linkage_shared';
			}
			if ( ! empty( $elf['exported_heif_symbols'] ) ) {
				$affected = true;
				$codes[]  = 'heif_symbols_exported';
			}
			if ( ! empty( $elf['unresolved'] ) ) {
				$affected = true;
				$codes[]  = 'unresolved_dependency';
			}
		}

		$linked = isset( $elf['needed'] ) ? implode( ',', array_intersect( array( 'libheif.so.1', 'libheif-heicfix.so.1' ), (array) $elf['needed'] ) ) : '';
		$evidence['linkage_static_hidden'] = $elf['checked']
			? ( empty( $elf['shared_libheif'] ) && empty( $elf['exported_heif_symbols'] ) )
			: null;
		$evidence['elf_checked'] = (bool) $elf['checked'];

		if ( $affected ) {
			$code = ! empty( $codes ) ? $codes[0] : 'filesystem_affected';
			if ( 'linkage_shared' !== $code && in_array( 'linkage_shared', $codes, true ) ) {
				$code = 'linkage_shared';
			}

			return Heic_Fix_Monitor_Probe_Result::make(
				'filesystem_elf',
				'affected',
				$code,
				__( 'Prefix files or ELF linkage do not match the static-hidden contract.', 'heic-fix-monitor' ),
				array_merge(
					$evidence,
					array(
						'codes'         => implode( ',', array_unique( $codes ) ),
						'linked_libheif' => $linked,
					)
				)
			);
		}

		return Heic_Fix_Monitor_Probe_Result::make(
			'filesystem_elf',
			'ok',
			'filesystem_ok',
			__( 'Prefix, extension, ini and ELF linkage agree with the repair contract.', 'heic-fix-monitor' ),
			$evidence
		);
	}

	/**
	 * Parse installed-versions.txt for allowlisted keys.
	 *
	 * @param string $file Path.
	 * @return array{values:array<string,string>,evidence:array<string,string>}
	 */
	private function read_versions( $file ) {
		$values   = array();
		$evidence = array( 'versions_file' => is_file( $file ) ? 'present' : 'missing' );

		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return array(
				'values'   => $values,
				'evidence' => $evidence,
			);
		}

		$contents = $this->read_file( $file );
		foreach ( preg_split( '/\r\n|\r|\n/', $contents ) as $line ) {
			if ( false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $key, $value ) = explode( '=', $line, 2 );
			$key = trim( $key );
			if ( in_array( $key, self::VERSION_KEYS, true ) ) {
				$values[ $key ] = trim( $value );
			}
		}

		return array(
			'values'   => $values,
			'evidence' => $evidence,
		);
	}

	/**
	 * Find the real libMagickCore file.
	 *
	 * @param string $dir Library directory.
	 * @return string
	 */
	private function find_magickcore( $dir ) {
		$matches = glob( $dir . '/libMagickCore-7*.so*' );
		if ( ! is_array( $matches ) ) {
			return '';
		}

		foreach ( $matches as $match ) {
			if ( is_file( $match ) && ! is_link( $match ) ) {
				return $match;
			}
		}

		return '';
	}

	/**
	 * Inspect ELF metadata with readelf.
	 *
	 * @param string                            $core   MagickCore path.
	 * @param Heic_Fix_Monitor_Command_Runner   $runner Runner.
	 * @return array<string,mixed>
	 */
	private function inspect_elf( $core, $runner ) {
		$result = array(
			'checked'               => false,
			'shared_libheif'        => false,
			'exported_heif_symbols' => 0,
			'unresolved'            => false,
			'needed'                => array(),
			'evidence'              => array(),
		);

		$readelf = '/usr/bin/readelf';
		if ( ! $runner->available() || ! is_executable( $readelf ) ) {
			$result['evidence']['elf'] = 'readelf_unavailable';
			return $result;
		}

		$dynamic = $runner->run( $readelf, array( '-d', $core ), 20 );
		if ( ! empty( $dynamic['error'] ) || ! empty( $dynamic['timed_out'] ) || 0 !== (int) $dynamic['exit_code'] ) {
			$result['evidence']['elf'] = 'readelf_failed';
			return $result;
		}

		$needed = array();
		if ( preg_match_all( '/Shared library: \[([^\]]+)\]/', (string) $dynamic['stdout'], $matches ) ) {
			$needed = array_values( array_unique( $matches[1] ) );
		}

		$symbols = $runner->run( $readelf, array( '--wide', '--dyn-syms', $core ), 20 );
		$exported = array();
		if ( empty( $symbols['error'] ) && empty( $symbols['timed_out'] ) ) {
			if ( preg_match_all( '/^\s*\d+:\s+[0-9a-fA-F]+\s+\d+\s+FUNC\s+(?:GLOBAL|WEAK)\s+DEFAULT\s+\d+\s+(heif_[A-Za-z0-9_]+)\s*$/m', (string) $symbols['stdout'], $matches ) ) {
				$exported = array_values( array_unique( $matches[1] ) );
			}
		}

		$ldd = '/usr/bin/ldd';
		$unresolved = false;
		if ( is_executable( $ldd ) ) {
			$extension = dirname( $core ) . '/../php';
			$ldd_result = $runner->run( $ldd, array( $core ), 20 );
			if ( empty( $ldd_result['error'] ) && false !== strpos( (string) $ldd_result['stdout'], 'not found' ) ) {
				$unresolved = true;
			}
			unset( $extension );
		}

		$shared = false;
		foreach ( $needed as $name ) {
			if ( 0 === strpos( $name, 'libheif' ) && '.so' === substr( $name, -3 ) ) {
				$shared = true;
			}
		}

		return array(
			'checked'               => true,
			'shared_libheif'        => $shared,
			'exported_heif_symbols' => count( $exported ),
			'unresolved'            => $unresolved,
			'needed'                => $needed,
			'evidence'              => array(
				'elf'                   => 'checked',
				'needed_libheif'        => $shared,
				'exported_heif_symbols' => count( $exported ),
			),
		);
	}

	/**
	 * Read a file without modifying it.
	 *
	 * @param string $file Path.
	 * @return string
	 */
	private function read_file( $file ) {
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return '';
		}

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local probe.

		return is_string( $contents ) ? $contents : '';
	}
}
