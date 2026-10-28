<?php
/**
 * Constant-first secret storage.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves secrets from wp-config constants or an encrypted option.
 */
final class Heic_Fix_Monitor_Secret_Store {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'hfm_secrets';

	/**
	 * Supported secret keys mapped to their constant.
	 *
	 * @var array<string,string>
	 */
	const CONSTANTS = array(
		'slack_webhook_url'     => 'HEIC_FIX_MONITOR_SLACK_WEBHOOK_URL',
		'generic_webhook_url'   => 'HEIC_FIX_MONITOR_GENERIC_WEBHOOK_URL',
		'generic_webhook_token' => 'HEIC_FIX_MONITOR_GENERIC_WEBHOOK_TOKEN',
	);

	/**
	 * Read a secret. Constants always win.
	 *
	 * @param string $key Secret key.
	 * @return string
	 */
	public static function get( $key ) {
		if ( ! isset( self::CONSTANTS[ $key ] ) ) {
			return '';
		}

		$constant = self::CONSTANTS[ $key ];
		if ( defined( $constant ) ) {
			$value = constant( $constant );
			return is_string( $value ) ? $value : '';
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || empty( $stored[ $key ] ) || ! is_string( $stored[ $key ] ) ) {
			return '';
		}

		$plain = self::decrypt( $stored[ $key ] );
		return is_string( $plain ) ? $plain : '';
	}

	/**
	 * Whether a secret is configured.
	 *
	 * @param string $key Secret key.
	 * @return bool
	 */
	public static function has( $key ) {
		$value = self::get( $key );
		return '' !== $value;
	}

	/**
	 * Whether a secret comes from a constant.
	 *
	 * @param string $key Secret key.
	 * @return bool
	 */
	public static function is_constant( $key ) {
		return isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] );
	}

	/**
	 * Whether encryption is usable.
	 *
	 * @return bool
	 */
	public static function encryption_available() {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' );
	}

	/**
	 * Persist provided secrets. Empty strings keep the stored value.
	 *
	 * @param array<string,string> $values  Secret values.
	 * @param bool                 $clear   Remove all stored secrets first.
	 * @return true|WP_Error
	 */
	public static function save( array $values, $clear = false ) {
		if ( ! self::encryption_available() ) {
			return new WP_Error( 'hfm_encryption_unavailable', __( 'Sodium is not available, so secrets cannot be stored safely. Define the wp-config constants instead.', 'heic-fix-monitor' ) );
		}

		$stored = $clear ? array() : get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		foreach ( self::CONSTANTS as $key => $unused ) {
			if ( ! isset( $values[ $key ] ) || ! is_string( $values[ $key ] ) || '' === $values[ $key ] ) {
				continue;
			}

			$encrypted = self::encrypt( $values[ $key ] );
			if ( is_wp_error( $encrypted ) ) {
				return $encrypted;
			}

			$stored[ $key ] = $encrypted;
		}

		update_option( self::OPTION, $stored, false );

		if ( function_exists( 'wp_set_option_autoload_values' ) ) {
			wp_set_option_autoload_values( array( self::OPTION => 'no' ) );
		}

		return true;
	}

	/**
	 * Describe a secret for the UI without revealing it.
	 *
	 * @param string $key Secret key.
	 * @return string
	 */
	public static function describe( $key ) {
		if ( self::is_constant( $key ) ) {
			return __( 'Set by wp-config.php (read-only)', 'heic-fix-monitor' );
		}

		return self::has( $key )
			? __( 'Stored encrypted', 'heic-fix-monitor' )
			: __( 'Not set', 'heic-fix-monitor' );
	}

	/**
	 * Derive the 32-byte encryption key.
	 *
	 * @return string
	 */
	private static function key() {
		if ( defined( 'HEIC_FIX_MONITOR_ENCRYPTION_KEY' ) ) {
			$decoded = base64_decode( (string) constant( 'HEIC_FIX_MONITOR_ENCRYPTION_KEY' ), true );
			if ( is_string( $decoded ) && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $decoded ) ) {
				return $decoded;
			}
		}

		return hash( 'sha256', wp_salt( 'auth' ) . '|heic-fix-monitor|v1', true );
	}

	/**
	 * Encrypt a value.
	 *
	 * @param string $plaintext Plain value.
	 * @return string|WP_Error
	 */
	private static function encrypt( $plaintext ) {
		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
			return base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext transport.
		} catch ( Exception $exception ) {
			return new WP_Error( 'hfm_encrypt_failed', __( 'The secret could not be encrypted.', 'heic-fix-monitor' ) );
		}
	}

	/**
	 * Decrypt a stored value.
	 *
	 * @param string $encoded Encoded ciphertext.
	 * @return string|null
	 */
	private static function decrypt( $encoded ) {
		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary ciphertext transport.
		if ( ! is_string( $raw ) || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );

		return is_string( $plain ) ? $plain : null;
	}
}
