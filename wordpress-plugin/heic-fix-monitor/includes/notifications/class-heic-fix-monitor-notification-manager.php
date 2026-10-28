<?php
/**
 * Notification dispatch and bounded retries.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Selects channels, sends transitions, and schedules bounded retries.
 */
final class Heic_Fix_Monitor_Notification_Manager {

	/**
	 * Retry hook.
	 *
	 * @var string
	 */
	const RETRY_HOOK = 'hfm_retry_delivery';

	/**
	 * Register the retry callback.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::RETRY_HOOK, array( 'Heic_Fix_Monitor_Notification_Manager', 'retry' ), 10, 3 );
	}

	/**
	 * Dispatch one state event.
	 *
	 * @param array<string,mixed> $event    Event.
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>
	 */
	public function dispatch( array $event, array $settings ) {
		$results = array();

		if ( empty( $event['is_notification'] ) ) {
			return array(
				'event_id' => '',
				'results'  => $results,
			);
		}

		$event_id = $this->event_id();
		$channels = array(
			'email'   => ! empty( $settings['email_enabled'] ),
			'slack'   => ! empty( $settings['slack_enabled'] ),
			'webhook' => ! empty( $settings['webhook_enabled'] ),
		);

		foreach ( $channels as $channel => $enabled ) {
			if ( ! $enabled ) {
				continue;
			}

			$notifier = $this->notifier( $channel );
			if ( null === $notifier ) {
				continue;
			}

			$result = $notifier->send( $event, $settings );
			$results[] = $result;

			if ( 'failed' === ( $result['status'] ?? '' ) ) {
				$this->schedule_retry( $event_id, $event, $channel, 0, $settings );
			}
		}

		return array(
			'event_id' => $event_id,
			'results'  => $results,
		);
	}

	/**
	 * Send a synthetic test message to one channel.
	 *
	 * @param string              $channel  email|slack|webhook.
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>
	 */
	public function send_test( $channel, array $settings ) {
		$notifier = $this->notifier( $channel );
		if ( null === $notifier ) {
			return array(
				'channel' => (string) $channel,
				'status'  => 'failed',
				'code'    => 'unknown_channel',
			);
		}

		$current = Heic_Fix_Monitor_State_Store::current();
		$event   = array(
			'type'           => 'test',
			'is_notification' => true,
			'state'          => ! empty( $current['state'] ) ? (string) $current['state'] : 'ok',
			'previous_state' => null,
			'codes'          => array( 'manual_test' ),
			'document'       => array(
				'signals' => array(
					'imagick_decode' => array(
						'status'   => 'ok',
						'code'     => 'manual_test',
						'evidence' => array( 'note' => 'Synthetic message; no probe ran.' ),
					),
				),
			),
			'checked_at'     => time(),
			'last_good'      => ! empty( $current['last_good'] ) ? (int) $current['last_good'] : 0,
		);

		return $notifier->send( $event, $settings );
	}

	/**
	 * Retry one delivery.
	 *
	 * @param string $event_id Opaque event id.
	 * @param string $channel  Channel.
	 * @param int    $attempt  Attempt number.
	 * @return void
	 */
	public static function retry( $event_id, $channel, $attempt ) {
		$event_id = sanitize_key( (string) $event_id );
		$channel  = sanitize_key( (string) $channel );
		$attempt  = (int) $attempt;
		$stored   = get_transient( 'hfm_event_' . $event_id );

		if ( ! is_array( $stored ) || empty( $stored['event'] ) || ! is_array( $stored['event'] ) ) {
			return;
		}

		$settings = Heic_Fix_Monitor_Settings::get();
		$manager  = new self();
		$notifier = $manager->notifier( $channel );
		if ( null === $notifier ) {
			delete_transient( 'hfm_event_' . $event_id );
			return;
		}

		$result = $notifier->send( $stored['event'], $settings );

		if ( 'failed' === ( $result['status'] ?? '' ) && $attempt < (int) ( $settings['retry_max'] ?? 0 ) ) {
			$manager->schedule( $event_id, $stored['event'], $channel, $attempt + 1, (int) ( $settings['retry_max'] ?? 0 ) );
			return;
		}

		delete_transient( 'hfm_event_' . $event_id );
	}

	/**
	 * Schedule a retry without putting secrets in cron arguments.
	 *
	 * @param string              $event_id Opaque event id.
	 * @param array<string,mixed> $event    Event.
	 * @param string              $channel  Channel.
	 * @param int                 $attempt  Attempt.
	 * @param array<string,mixed> $settings Settings.
	 * @return void
	 */
	private function schedule_retry( $event_id, array $event, $channel, $attempt, array $settings ) {
		$max = (int) ( $settings['retry_max'] ?? 0 );
		if ( $max <= 0 || $attempt >= $max ) {
			return;
		}

		set_transient(
			'hfm_event_' . $event_id,
			array(
				'event'   => $event,
				'channel' => $channel,
			),
			DAY_IN_SECONDS
		);

		$this->schedule( $event_id, $event, $channel, $attempt, $max );
	}

	/**
	 * Schedule one retry event.
	 *
	 * @param string              $event_id Opaque event id.
	 * @param array<string,mixed> $event    Event.
	 * @param string              $channel  Channel.
	 * @param int                 $attempt  Attempt.
	 * @param int                 $max      Maximum attempts.
	 * @return void
	 */
	private function schedule( $event_id, array $event, $channel, $attempt, $max ) {
		if ( $attempt >= $max ) {
			return;
		}

		$delay = min( 3600, 60 * ( 2 ** max( 0, $attempt ) ) );
		wp_schedule_single_event(
			time() + $delay,
			self::RETRY_HOOK,
			array( $event_id, $channel, $attempt + 1 )
		);
	}

	/**
	 * Build a notifier.
	 *
	 * @param string $channel Channel.
	 * @return Heic_Fix_Monitor_Notifier_Interface|null
	 */
	private function notifier( $channel ) {
		switch ( $channel ) {
			case 'email':
				return new Heic_Fix_Monitor_Email_Notifier();
			case 'slack':
				return new Heic_Fix_Monitor_Slack_Notifier();
			case 'webhook':
				return new Heic_Fix_Monitor_Webhook_Notifier();
		}

		return null;
	}

	/**
	 * Generate an opaque event id.
	 *
	 * @return string
	 */
	private function event_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return sanitize_key( wp_generate_uuid4() );
		}

		return substr( hash( 'sha256', uniqid( '', true ) . random_int( 1, PHP_INT_MAX ) ), 0, 32 );
	}
}
