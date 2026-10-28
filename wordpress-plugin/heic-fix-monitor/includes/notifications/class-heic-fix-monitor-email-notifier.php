<?php
/**
 * Email notifier.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends plain-text alerts with wp_mail().
 */
final class Heic_Fix_Monitor_Email_Notifier implements Heic_Fix_Monitor_Notifier_Interface {

	/**
	 * Send one event.
	 *
	 * @param array<string,mixed> $event    Event.
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>
	 */
	public function send( array $event, array $settings ) {
		$result = array(
			'channel' => 'email',
			'status'  => 'skipped',
			'code'    => '',
		);

		if ( empty( $settings['email_enabled'] ) ) {
			$result['code'] = 'disabled';
			return $result;
		}

		$recipients = array();
		foreach ( (array) ( $settings['emails'] ?? array() ) as $email ) {
			if ( is_string( $email ) && is_email( $email ) ) {
				$recipients[] = $email;
			}
		}

		if ( empty( $recipients ) ) {
			$result['code'] = 'no_recipients';
			return $result;
		}

		$message = ( new Heic_Fix_Monitor_Message_Builder() )->build( $event, $settings );
		$sent    = wp_mail(
			$recipients,
			$message['subject'],
			$message['text'],
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);

		$result['status'] = $sent ? 'sent' : 'failed';
		$result['code']   = $sent ? 'sent' : 'wp_mail_failed';

		return $result;
	}
}
