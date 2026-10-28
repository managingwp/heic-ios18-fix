<?php
/**
 * Minimal WordPress stubs for CLI probe tests.
 *
 * @package Heic_Fix_Monitor
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HFM_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

if ( ! function_exists( 'mb_substr' ) ) {
	/**
	 * Fallback for environments without mbstring.
	 *
	 * @param string   $string String.
	 * @param int      $start  Start.
	 * @param int|null $length Length.
	 * @return string
	 */
	function mb_substr( $string, $start, $length = null ) {
		return null === $length ? substr( $string, $start ) : substr( $string, $start, $length );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Error container.
	 */
	class WP_Error {

		/**
		 * Code.
		 *
		 * @var string
		 */
		private $code;

		/**
		 * Message.
		 *
		 * @var string
		 */
		private $message;

		/**
		 * Constructor.
		 *
		 * @param string $code    Code.
		 * @param string $message Message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * Code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Error check.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Key sanitizer.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Strip tags.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function wp_strip_all_tags( $value ) {
		return strip_tags( (string) $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stubs only.
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON encode.
	 *
	 * @param mixed $value Value.
	 * @return string|false
	 */
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

$GLOBALS['hfm_test_transients'] = array();

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Transient getter.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	function get_transient( $key ) {
		return $GLOBALS['hfm_test_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Transient setter.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   TTL.
	 * @return bool
	 */
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['hfm_test_transients'][ $key ] = $value;
		return true;
	}
}

$GLOBALS['hfm_test_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Filter registration.
	 *
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function add_filter( $hook, $callback ) {
		$GLOBALS['hfm_test_filters'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Filter dispatcher.
	 *
	 * @param string $hook  Hook.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		$args = array_slice( func_get_args(), 1 );
		foreach ( $GLOBALS['hfm_test_filters'][ $hook ] ?? array() as $callback ) {
			$args[0] = call_user_func_array( $callback, $args );
		}
		return $args[0];
	}
}

require_once HFM_PLUGIN_DIR . 'includes/probes/interface-heic-fix-monitor-probe.php';
require_once HFM_PLUGIN_DIR . 'includes/probes/class-heic-fix-monitor-probe-result.php';
require_once HFM_PLUGIN_DIR . 'includes/probes/class-heic-fix-monitor-command-runner.php';
require_once HFM_PLUGIN_DIR . 'includes/probes/class-heic-fix-monitor-check-probe.php';
require_once HFM_PLUGIN_DIR . 'includes/probes/class-heic-fix-monitor-imagick-probe.php';
require_once HFM_PLUGIN_DIR . 'includes/probes/class-heic-fix-monitor-filesystem-probe.php';
require_once HFM_PLUGIN_DIR . 'includes/probes/class-heic-fix-monitor-probe-engine.php';
