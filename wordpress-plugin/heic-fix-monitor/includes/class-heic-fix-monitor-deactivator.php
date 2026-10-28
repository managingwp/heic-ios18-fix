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
	 * Deactivate the plugin.
	 *
	 * @param bool $network_wide Whether the network is being deactivated.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::deactivate_site();
				restore_current_blog();
			}

			return;
		}

		self::deactivate_site();
	}

	/**
	 * Clean up the current site.
	 *
	 * @return void
	 */
	private static function deactivate_site() {
		Heic_Fix_Monitor_Cron::unschedule_all();
		delete_transient( 'hfm_run_lock' );
	}
}
