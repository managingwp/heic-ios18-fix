<?php
/**
 * Activation and per-site provisioning.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates options and schedules without running probes.
 */
final class Heic_Fix_Monitor_Activator {

	/**
	 * Activate the plugin.
	 *
	 * @param bool $network_wide Whether the plugin is network activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::provision_site();
				restore_current_blog();
			}

			return;
		}

		self::provision_site();
	}

	/**
	 * Provision options and schedules for the current site.
	 *
	 * @return void
	 */
	public static function provision_site() {
		$defaults = Heic_Fix_Monitor_Settings::defaults();

		if ( false === get_option( 'hfm_settings', false ) ) {
			add_option( 'hfm_settings', $defaults, '', false );
		}

		if ( false === get_option( 'hfm_state', false ) ) {
			add_option( 'hfm_state', array(), '', false );
		}

		if ( false === get_option( 'hfm_secrets', false ) ) {
			add_option( 'hfm_secrets', array(), '', false );
		}

		if ( function_exists( 'wp_set_option_autoload_values' ) ) {
			wp_set_option_autoload_values(
				array(
					'hfm_settings' => 'no',
					'hfm_state'    => 'no',
					'hfm_secrets'  => 'no',
				)
			);
		}

		Heic_Fix_Monitor_Cron::schedule_all( $defaults );
	}

	/**
	 * Provision a site created after network activation.
	 *
	 * @param WP_Site $new_site New site object.
	 * @return void
	 */
	public static function provision_new_site( $new_site ) {
		if ( ! is_multisite() || ! is_object( $new_site ) || empty( $new_site->blog_id ) ) {
			return;
		}

		switch_to_blog( (int) $new_site->blog_id );
		self::provision_site();
		restore_current_blog();
	}
}
