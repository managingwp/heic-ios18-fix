<?php
/**
 * Admin page and action tests. Run with: wp eval-file tests/admin-tests.php
 *
 * @package Heic_Fix_Monitor
 */

$hfm_admin_failures = 0;

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $label     Label.
 * @return void
 */
function hfm_admin_assert( $condition, $label ) {
	global $hfm_admin_failures;

	if ( $condition ) {
		echo 'PASS: ' . $label . PHP_EOL;
		return;
	}

	++$hfm_admin_failures;
	echo 'FAIL: ' . $label . PHP_EOL;
}

/**
 * Expect a redirect and return its location.
 *
 * @param callable $callback Action.
 * @return string
 */
function hfm_expect_redirect( callable $callback ) {
	remove_all_filters( 'wp_redirect' );
	add_filter(
		'wp_redirect',
		static function ( $location ) {
			throw new RuntimeException( (string) $location );
		}
	);

	try {
		$callback();
	} catch ( RuntimeException $exception ) {
		remove_all_filters( 'wp_redirect' );
		return $exception->getMessage();
	}

	remove_all_filters( 'wp_redirect' );
	return '';
}

/**
 * Expect wp_die and return its message.
 *
 * @param callable $callback Action.
 * @return string
 */
function hfm_expect_die( callable $callback ) {
	add_filter(
		'wp_die_handler',
		static function () {
			return static function ( $message ) {
				throw new RuntimeException( is_wp_error( $message ) ? $message->get_error_message() : (string) $message );
			};
		}
	);

	try {
		$callback();
	} catch ( RuntimeException $exception ) {
		remove_all_filters( 'wp_die_handler' );
		return $exception->getMessage();
	}

	remove_all_filters( 'wp_die_handler' );
	return '';
}

/**
 * Set request globals the way admin-post.php does.
 *
 * @param array $data Request data.
 * @return void
 */
function hfm_post( array $data ) {
	$_POST    = $data;
	$_REQUEST = $data;
}

$admin_id = (int) get_user_by( 'login', 'admin' )->ID;
wp_set_current_user( $admin_id );

update_option( 'hfm_settings', Heic_Fix_Monitor_Settings::defaults(), false );
Heic_Fix_Monitor_Secret_Store::save( array( 'generic_webhook_token' => 'admin-canary-secret-987654' ) );

$fixture = json_decode( (string) file_get_contents( HFM_PLUGIN_DIR . 'tests/fixtures/known-good.json' ), true );
$result  = array(
	'schema_version' => 1,
	'state'          => 'ok',
	'codes'          => array( 'check_ok', 'decode_ok', 'filesystem_ok' ),
	'signals'        => $fixture['runs'][0],
	'php_versions'   => array( '8.5' ),
	'source'         => 'fixture',
);
( new Heic_Fix_Monitor_State_Store() )->record( $result );

$admin = new Heic_Fix_Monitor_Admin();

ob_start();
$admin->render_status_page();
$status_html = (string) ob_get_clean();

hfm_admin_assert( false !== strpos( $status_html, 'hfm-state--ok' ), 'status page renders the OK state class' );
hfm_admin_assert( false !== strpos( $status_html, 'Per-signal evidence' ), 'status page renders per-signal evidence' );
hfm_admin_assert( false === strpos( $status_html, 'admin-canary-secret-987654' ), 'status page never renders a stored secret' );
hfm_admin_assert( false !== strpos( $status_html, 'hfm_run_check_now_nonce' ), 'status page includes the run-check nonce' );

ob_start();
$admin->render_settings_page();
$settings_html = (string) ob_get_clean();

hfm_admin_assert( false !== strpos( $settings_html, 'hfm_settings_group' ), 'settings page posts to options.php with the registered group' );
hfm_admin_assert( false === strpos( $settings_html, 'admin-canary-secret-987654' ), 'settings page masks stored secrets' );
hfm_admin_assert( false !== strpos( $settings_html, 'Stored encrypted' ), 'settings page describes the encrypted secret state' );

$suffix        = strtolower( wp_generate_password( 8, false ) );
$subscriber_id = wp_insert_user(
	array(
		'user_login' => 'hfm-subscriber-' . $suffix,
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => 'hfm-subscriber-' . $suffix . '@example.test',
		'role'       => 'subscriber',
	)
);
hfm_admin_assert( ! is_wp_error( $subscriber_id ), 'creates a subscriber fixture' );

wp_set_current_user( (int) $subscriber_id );
hfm_post( array( 'hfm_run_check_now_nonce' => wp_create_nonce( 'hfm_run_check_now' ) ) );
$message = hfm_expect_die( array( $admin, 'handle_run_check_now' ) );
hfm_admin_assert( false !== stripos( $message, 'not allowed' ), 'a valid nonce without manage_options is denied' );

wp_set_current_user( $admin_id );
hfm_post( array( 'hfm_run_check_now_nonce' => 'invalid' ) );
$message = hfm_expect_die( array( $admin, 'handle_run_check_now' ) );
hfm_admin_assert( '' !== $message, 'an invalid nonce is rejected before any probe runs' );

hfm_post( array(
	'hfm_reset_baseline_nonce' => wp_create_nonce( 'hfm_reset_baseline' ),
	'hfm_confirm_reset'        => '0',
) );
$location = hfm_expect_redirect( array( $admin, 'handle_reset_baseline' ) );
hfm_admin_assert( false !== strpos( $location, 'reset_unconfirmed' ), 'reset requires an explicit confirmation' );
hfm_admin_assert( ! empty( Heic_Fix_Monitor_State_Store::current() ), 'an unconfirmed reset leaves the state intact' );

hfm_post( array(
	'hfm_reset_baseline_nonce' => wp_create_nonce( 'hfm_reset_baseline' ),
	'hfm_confirm_reset'        => '1',
) );
$location = hfm_expect_redirect( array( $admin, 'handle_reset_baseline' ) );
hfm_admin_assert( false !== strpos( $location, 'hfm_notice=reset' ), 'a confirmed reset redirects with a reset notice' );
hfm_admin_assert( empty( Heic_Fix_Monitor_State_Store::current() ), 'a confirmed reset removes only the stored state' );
hfm_admin_assert( false !== get_option( 'hfm_settings', false ), 'a reset leaves settings untouched' );

add_filter( 'pre_wp_mail', '__return_true' );
update_option( 'hfm_settings', array_merge( Heic_Fix_Monitor_Settings::defaults(), array( 'email_enabled' => 1, 'emails' => array( 'ops@example.test' ) ) ), false );
hfm_post( array(
	'hfm_send_test_nonce' => wp_create_nonce( 'hfm_send_test' ),
	'hfm_test_channel'    => 'email',
) );
$location = hfm_expect_redirect( array( $admin, 'handle_send_test' ) );
hfm_admin_assert( false !== strpos( $location, 'test_sent' ), 'test-send uses a channel allowlist and reports success' );

hfm_post( array(
	'hfm_send_test_nonce' => wp_create_nonce( 'hfm_send_test' ),
	'hfm_test_channel'    => 'sms',
) );
$location = hfm_expect_redirect( array( $admin, 'handle_send_test' ) );
hfm_admin_assert( false !== strpos( $location, 'test_invalid' ), 'test-send rejects an unknown channel' );

exit( $hfm_admin_failures > 0 ? 1 : 0 );
