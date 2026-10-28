<?php
/**
 * Deactivation cleanup.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unschedules events and removes runtime locks without deleting history.
 */
final class Heic_Fix_Monitor_Deactivator {

	/**
	 * Deactivate the plugin for the current site.
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_transient( 'hfm_run_lock' );
	}
}
