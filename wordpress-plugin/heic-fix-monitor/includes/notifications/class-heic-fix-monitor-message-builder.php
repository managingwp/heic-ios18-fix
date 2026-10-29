<?php
/**
 * Notification message construction.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds bounded, redacted plain-text, HTML and JSON messages.
 */
final class Heic_Fix_Monitor_Message_Builder {

	/**
	 * Troubleshooting URL.
	 *
	 * @var string
	 */
	const TROUBLESHOOTING_URL = 'https://managingwp.github.io/heic-ios18-fix/troubleshooting/';

	/**
	 * Human labels.
	 *
	 * @var array<string,string>
	 */
	const LABELS = array(
		'ok'         => 'OK',
		'degraded'   => 'Degraded',
		'affected'   => 'Affected',
		'missing'    => 'Missing',
		'error'      => 'Error',
		'baseline'   => 'Baseline established',
		'regression' => 'Regression detected',
		'change'     => 'State evidence changed',
		'reminder'   => 'Regression still present',
		'recovery'   => 'Recovered',
		'none'       => 'No change',
		'test'       => 'Test notification',
	);

	/**
	 * Build a message.
	 *
	 * @param array<string,mixed> $event    Event.
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>
	 */
	public function build( array $event, array $settings ) {
		$site        = $this->site_host();
		$state       = isset( $event['state'] ) ? (string) $event['state'] : 'error';
		$type        = isset( $event['type'] ) ? (string) $event['type'] : 'none';
		$label       = self::LABELS[ $state ] ?? ucfirst( $state );
		$event_label = self::LABELS[ $type ] ?? ucfirst( $type );
		$codes       = isset( $event['codes'] ) && is_array( $event['codes'] ) ? array_map( 'strval', $event['codes'] ) : array();
		$php         = defined( 'PHP_VERSION' ) ? PHP_VERSION : phpversion();

		$subject = sprintf( '[HEIC Fix Monitor] %s: %s on %s', $event_label, $label, $site );

		$lines   = array();
		$lines[] = sprintf( '%s on %s', $event_label, $site );
		$lines[] = sprintf( 'State: %s (previously %s)', $label, isset( $event['previous_state'] ) && $event['previous_state'] ? ( self::LABELS[ $event['previous_state'] ] ?? $event['previous_state'] ) : 'none' );
		if ( ! empty( $codes ) ) {
			$lines[] = 'Reason codes: ' . implode( ', ', $codes );
		}

		$signals = isset( $event['document']['signals'] ) && is_array( $event['document']['signals'] ) ? $event['document']['signals'] : array();
		foreach ( $signals as $name => $signal ) {
			if ( ! is_array( $signal ) ) {
				continue;
			}
			$lines[]  = sprintf(
				'- %s: %s (%s)',
				(string) $name,
				(string) ( $signal['status'] ?? 'unknown' ),
				(string) ( $signal['code'] ?? 'unknown' )
			);
			$evidence = $this->flatten( $signal['evidence'] ?? array(), (string) $name );
			if ( ! empty( $evidence ) ) {
				$lines[] = '  Evidence: ' . implode( '; ', $evidence );
			}
		}

		$last_good = isset( $event['last_good'] ) ? (int) $event['last_good'] : 0;
		$lines[]   = 'Last good: ' . ( $last_good > 0 ? gmdate( 'Y-m-d H:i:s', $last_good ) . ' UTC' : 'never recorded' );
		$lines[]   = 'Checked: ' . gmdate( 'Y-m-d H:i:s', isset( $event['checked_at'] ) ? (int) $event['checked_at'] : time() ) . ' UTC';
		$lines[]   = 'Host PHP: ' . $php;
		$lines[]   = 'Repair and rollback: ' . self::TROUBLESHOOTING_URL;
		$lines[]   = 'This monitor is read-only; it never changed the server.';

		$text = implode( "\n", $lines );
		$text = $this->redact( $text );

		$html  = '<p><strong>' . esc_html( $event_label ) . '</strong> — ' . esc_html( $site ) . '</p>';
		$html .= '<p>' . esc_html( sprintf( 'State: %s (previously %s)', $label, isset( $event['previous_state'] ) && $event['previous_state'] ? ( self::LABELS[ $event['previous_state'] ] ?? $event['previous_state'] ) : 'none' ) ) . '</p>';
		if ( ! empty( $codes ) ) {
			$html .= '<p>' . esc_html( 'Reason codes: ' . implode( ', ', $codes ) ) . '</p>';
		}
		$html .= '<ul>';
		foreach ( $signals as $name => $signal ) {
			$html .= '<li>' . esc_html( $name . ': ' . ( $signal['status'] ?? 'unknown' ) . ' (' . ( $signal['code'] ?? 'unknown' ) . ')' ) . '</li>';
		}
		$html .= '</ul>';
		$html .= '<p>' . esc_html( 'Last good: ' . ( $last_good > 0 ? gmdate( 'Y-m-d H:i:s', $last_good ) . ' UTC' : 'never recorded' ) ) . '</p>';
		$html .= '<p><a href="' . esc_url( self::TROUBLESHOOTING_URL ) . '">' . esc_html__( 'Repair and rollback runbook', 'heic-fix-monitor' ) . '</a></p>';
		$html  = $this->redact( $html );

		$payload = array(
			'schema_version'  => 1,
			'event'           => $type,
			'state'           => $state,
			'previous_state'  => $event['previous_state'] ?? null,
			'codes'           => $codes,
			'signals'         => $signals,
			'checked_at'      => gmdate( 'c', isset( $event['checked_at'] ) ? (int) $event['checked_at'] : time() ),
			'last_good'       => $last_good > 0 ? gmdate( 'c', $last_good ) : null,
			'host'            => $site,
			'php'             => $php,
			'troubleshooting' => self::TROUBLESHOOTING_URL,
		);

		return array(
			'subject' => $this->redact( $subject ),
			'text'    => $text,
			'html'    => $html,
			'payload' => $this->redact( $payload ),
		);
	}

	/**
	 * Flatten bounded evidence for text output.
	 *
	 * @param mixed  $value Evidence.
	 * @param string $prefix Prefix.
	 * @return array<int,string>
	 */
	private function flatten( $value, $prefix ) {
		$out = array();

		if ( ! is_array( $value ) ) {
			return $out;
		}

		foreach ( $value as $key => $item ) {
			if ( count( $out ) >= 12 ) {
				break;
			}

			$key = (string) $key;
			if ( preg_match( '/(secret|token|password|webhook_url|authorization)/i', $key ) ) {
				continue;
			}

			$label = $prefix . '.' . $key;
			if ( is_array( $item ) ) {
				$out = array_merge( $out, $this->flatten( $item, $label ) );
				continue;
			}

			if ( is_bool( $item ) ) {
				$item = $item ? 'true' : 'false';
			}

			$out[] = $label . '=' . Heic_Fix_Monitor_Probe_Result::bounded_text( (string) $item, 80 );
		}

		return $out;
	}

	/**
	 * Redact configured secrets from any string or array.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function redact( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->redact( $item );
			}
			return $value;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		foreach ( array( 'slack_webhook_url', 'generic_webhook_url', 'generic_webhook_token' ) as $key ) {
			$secret = Heic_Fix_Monitor_Secret_Store::get( $key );
			if ( is_string( $secret ) && strlen( $secret ) >= 8 ) {
				$value = str_replace( $secret, '[redacted]', $value );
			}
		}

		return $value;
	}

	/**
	 * Site host without a scheme.
	 *
	 * @return string
	 */
	private function site_host() {
		$host = function_exists( 'wp_parse_url' ) ? wp_parse_url( home_url(), PHP_URL_HOST ) : '';

		if ( is_string( $host ) && '' !== $host ) {
			return $host;
		}

		$name = function_exists( 'gethostname' ) ? gethostname() : '';

		return is_string( $name ) && '' !== $name ? $name : 'site';
	}
}
