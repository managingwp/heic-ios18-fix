<?php
/**
 * Probe interface.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A read-only probe returns one signal.
 */
interface Heic_Fix_Monitor_Probe_Interface { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedInterface -- Documented contract.

	/**
	 * Run the probe.
	 *
	 * @param array<string,mixed> $context Probe context.
	 * @return array<string,mixed>
	 */
	public function run( array $context );
}
