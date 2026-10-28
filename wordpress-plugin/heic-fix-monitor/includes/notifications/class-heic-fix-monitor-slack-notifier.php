<?php
/**
 * Slack notifier.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends one Slack incoming-webhook message.
 */
final class Heic_Fix_Monitor_Slack_Notifier implements Heic_Fix_Monitor_Notifier_Interface {

	/**
	 * Send one event.
	 *
	 * @param array<string,mixed> $event    Event.
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>
	 */
	public function send( array $event, array $settings ) {
		$result = array(
			'channel' => 'slack',
			'status'  => 'skipped',
			'code'    => '',
		);

		if ( empty( $settings['slack_enabled'] ) ) {
			$result['code'] = 'disabled';
			return $result;
		}

		$url = Heic_Fix_Monitor_Secret_Store::get( 'slack_webhook_url' );
		if ( '' === $url ) {
			$result['code'] = 'no_secret';
			return $result;
		}

		$valid = Heic_Fix_Monitor_Url_Validator::validate_slack( $url );
		if ( is_wp_error( $valid ) ) {
			$result['status'] = 'failed';
			$result['code']   = $valid->get_error_code();
			return $result;
		}

		$message = ( new Heic_Fix_Monitor_Message_Builder() )->build( $event, $settings );
		$body    = wp_json_encode( array( 'text' => $message['text'] ) );

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => max( 2, (int) ( $settings['webhook_timeout'] ?? 5 ) ),
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array( 'Content-Type' => 'application/json; charset=UTF-8' ),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['status'] = 'failed';
			$result['code']   = 'http_error';
			return $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$result['status'] = 'failed';
			$result['code']   = 'http_' . $code;
			return $result;
		}

		$result['status'] = 'sent';
		$result['code']   = 'sent';

		return $result;
	}
}
