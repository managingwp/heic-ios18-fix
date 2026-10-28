<?php
/**
 * Notifier interface.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A notification channel.
 */
interface Heic_Fix_Monitor_Notifier_Interface { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedInterface -- Documented contract.

	/**
	 * Send one event.
	 *
	 * @param array<string,mixed> $event    Event.
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>
	 */
	public function send( array $event, array $settings );
}
