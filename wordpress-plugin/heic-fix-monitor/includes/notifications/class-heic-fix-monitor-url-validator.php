<?php
/**
 * Outbound URL validation.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blocks unsafe webhook destinations before an HTTP request.
 */
final class Heic_Fix_Monitor_Url_Validator {

	/**
	 * Validate a Slack webhook URL.
	 *
	 * @param string $url URL.
	 * @return true|WP_Error
	 */
	public static function validate_slack( $url ) {
		$result = self::validate_base( $url );
		if ( true !== $result ) {
			return $result;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( 'hooks.slack.com' !== $host ) {
			return new WP_Error( 'hfm_host_not_allowed', __( 'The Slack webhook host is not allowed.', 'heic-fix-monitor' ) );
		}

		return self::validate_resolved_ips( $host );
	}

	/**
	 * Validate a generic webhook URL.
	 *
	 * @param string            $url           URL.
	 * @param array<int,string> $allowed_hosts Allowed hosts.
	 * @return true|WP_Error
	 */
	public static function validate_generic( $url, array $allowed_hosts ) {
		$result = self::validate_base( $url );
		if ( true !== $result ) {
			return $result;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$list = array();
		foreach ( $allowed_hosts as $allowed ) {
			$allowed = strtolower( trim( (string) $allowed ) );
			if ( '' !== $allowed ) {
				$list[] = $allowed;
			}
		}

		if ( defined( 'HEIC_FIX_MONITOR_ALLOWED_WEBHOOK_HOSTS' ) ) {
			$constant = constant( 'HEIC_FIX_MONITOR_ALLOWED_WEBHOOK_HOSTS' );
			if ( is_array( $constant ) ) {
				foreach ( $constant as $allowed ) {
					$allowed = strtolower( trim( (string) $allowed ) );
					if ( '' !== $allowed ) {
						$list[] = $allowed;
					}
				}
			}
		}

		if ( empty( $list ) || ! in_array( $host, array_unique( $list ), true ) ) {
			return new WP_Error( 'hfm_host_not_allowed', __( 'The webhook host is not in HEIC_FIX_MONITOR_ALLOWED_WEBHOOK_HOSTS.', 'heic-fix-monitor' ) );
		}

		return self::validate_resolved_ips( $host );
	}

	/**
	 * Validate a URL again before every request.
	 *
	 * @param string $url URL.
	 * @return true|WP_Error
	 */
	public static function validate_base( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url || strlen( $url ) > 2048 ) {
			return new WP_Error( 'hfm_url_invalid', __( 'The webhook URL is empty or too long.', 'heic-fix-monitor' ) );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'hfm_url_invalid', __( 'The webhook URL is malformed.', 'heic-fix-monitor' ) );
		}

		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return new WP_Error( 'hfm_url_insecure', __( 'Only HTTPS webhooks are allowed.', 'heic-fix-monitor' ) );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'hfm_url_credentials', __( 'Webhook URLs must not embed credentials.', 'heic-fix-monitor' ) );
		}

		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return new WP_Error( 'hfm_url_port', __( 'Only the default HTTPS port is allowed.', 'heic-fix-monitor' ) );
		}

		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( '' === $host || 'localhost' === $host || '.local' === substr( $host, -6 ) ) {
			return new WP_Error( 'hfm_host_not_allowed', __( 'Local webhook hosts are not allowed.', 'heic-fix-monitor' ) );
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) && ! self::is_public_ip( $host ) ) {
			return new WP_Error( 'hfm_host_not_allowed', __( 'Private and reserved webhook addresses are not allowed.', 'heic-fix-monitor' ) );
		}

		if ( function_exists( 'wp_http_validate_url' ) && false === wp_http_validate_url( $url ) ) {
			return new WP_Error( 'hfm_url_unsafe', __( 'WordPress rejected the webhook URL as unsafe.', 'heic-fix-monitor' ) );
		}

		return true;
	}

	/**
	 * Resolve a host immediately before the request and require public addresses.
	 *
	 * @param string $host Hostname.
	 * @return true|WP_Error
	 */
	public static function validate_resolved_ips( $host ) {
		if ( ! function_exists( 'dns_get_record' ) && ! function_exists( 'gethostbynamel' ) ) {
			return new WP_Error( 'hfm_dns_unavailable', __( 'DNS resolution is unavailable.', 'heic-fix-monitor' ) );
		}

		$ips = array();
		if ( function_exists( 'gethostbynamel' ) ) {
			$ips = gethostbynamel( $host );
			$ips = is_array( $ips ) ? $ips : array();
		}

		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failure is handled below.
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}

		$ips = array_values( array_unique( $ips ) );
		if ( empty( $ips ) ) {
			return new WP_Error( 'hfm_dns_failed', __( 'The webhook host did not resolve.', 'heic-fix-monitor' ) );
		}

		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return new WP_Error( 'hfm_host_not_allowed', __( 'The webhook host resolves to a private or reserved address.', 'heic-fix-monitor' ) );
			}
		}

		return true;
	}

	/**
	 * Whether an IP is public.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_public_ip( $ip ) {
		$ip = (string) $ip;

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = ip2long( $ip );
			if ( false === $long ) {
				return false;
			}

			$blocked = array(
				array( '0.0.0.0', '0.255.255.255' ),
				array( '10.0.0.0', '10.255.255.255' ),
				array( '100.64.0.0', '100.127.255.255' ),
				array( '127.0.0.0', '127.255.255.255' ),
				array( '169.254.0.0', '169.254.255.255' ),
				array( '172.16.0.0', '172.31.255.255' ),
				array( '192.0.0.0', '192.0.0.255' ),
				array( '192.168.0.0', '192.168.255.255' ),
				array( '198.18.0.0', '198.19.255.255' ),
				array( '224.0.0.0', '239.255.255.255' ),
				array( '240.0.0.0', '255.255.255.255' ),
			);

			foreach ( $blocked as $range ) {
				if ( $long >= ip2long( $range[0] ) && $long <= ip2long( $range[1] ) ) {
					return false;
				}
			}

			return true;
		}

		$normalized = strtolower( $ip );
		if ( '::1' === $normalized || '::' === $normalized ) {
			return false;
		}

		foreach ( array( 'fc', 'fd', 'fe8', 'fe9', 'fea', 'feb' ) as $prefix ) {
			if ( 0 === strpos( $normalized, $prefix ) ) {
				return false;
			}
		}

		return true;
	}
}
