<?php
/**
 * Uninstall cleanup.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove plugin data from the current site.
 *
 * @return void
 */
function hfm_uninstall_site() {
	$options = array( 'hfm_settings', 'hfm_secrets', 'hfm_state' );
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	$hooks = array( 'hfm_run_check', 'hfm_daily_digest', 'hfm_retry_delivery' );
	foreach ( $hooks as $hook ) {
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( $hook );
		} else {
			wp_clear_scheduled_hook( $hook );
		}
	}

	delete_transient( 'hfm_run_lock' );
	delete_transient( 'hfm_last_decode' );
	delete_transient( 'hfm_test_result' );
}

if ( is_multisite() ) {
	$hfm_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $hfm_site_ids as $hfm_site_id ) {
		switch_to_blog( (int) $hfm_site_id );
		hfm_uninstall_site();
		restore_current_blog();
	}
} else {
	hfm_uninstall_site();
}
