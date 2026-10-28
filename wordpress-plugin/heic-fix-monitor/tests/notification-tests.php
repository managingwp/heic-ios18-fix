<?php
/**
 * Notification tests. Run with: wp eval-file tests/notification-tests.php
 *
 * @package Heic_Fix_Monitor
 */

$hfm_failures = 0;

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $label     Label.
 * @return void
 */
function hfm_notify_assert( $condition, $label ) {
	global $hfm_failures;

	if ( $condition ) {
		echo 'PASS: ' . $label . PHP_EOL;
		return;
	}

	++$hfm_failures;
	echo 'FAIL: ' . $label . PHP_EOL;
}

update_option( 'hfm_settings', array_merge( Heic_Fix_Monitor_Settings::defaults(), array( 'slack_enabled' => 1, 'webhook_enabled' => 1, 'email_enabled' => 1, 'emails' => array( 'ops@example.test' ) ) ), false );

define( 'HEIC_FIX_MONITOR_ALLOWED_WEBHOOK_HOSTS', array( 'example.com' ) );
$canary = 'canary-secret-value-123456';
Heic_Fix_Monitor_Secret_Store::save(
	array(
		'slack_webhook_url'     => 'https://hooks.slack.com/services/T000/B000/' . $canary,
		'generic_webhook_url'   => 'https://example.com/hooks/heic',
		'generic_webhook_token' => $canary,
	)
);

$GLOBALS['hfm_requests'] = array();
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		$GLOBALS['hfm_requests'][] = array(
			'url'     => $url,
			'headers' => $args['headers'] ?? array(),
			'body'    => $args['body'] ?? '',
		);
		return array(
			'headers'  => array(),
			'body'     => '{"ok":true}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	},
	10,
	3
);
add_filter( 'pre_wp_mail', '__return_true' );

$fixture = json_decode( (string) file_get_contents( HFM_PLUGIN_DIR . 'tests/fixtures/linkage-shared.json' ), true );
$signals = $fixture['runs'][0];
$event   = array(
	'type'            => 'regression',
	'is_notification' => true,
	'state'           => 'affected',
	'previous_state'  => 'ok',
	'codes'           => array( 'linkage_shared' ),
	'document'        => array( 'signals' => $signals ),
	'checked_at'      => time(),
	'last_good'       => time() - HOUR_IN_SECONDS,
	'fingerprint'     => 'test-fingerprint',
);

$manager = new Heic_Fix_Monitor_Notification_Manager();
$result  = $manager->dispatch( $event, Heic_Fix_Monitor_Settings::get() );

$statuses = array();
foreach ( $result['results'] as $entry ) {
	$statuses[ $entry['channel'] ] = $entry['status'];
}

hfm_notify_assert( 'sent' === ( $statuses['email'] ?? '' ), 'email channel is sent through wp_mail' );
hfm_notify_assert( 'sent' === ( $statuses['slack'] ?? '' ), 'Slack channel is sent through a mocked HTTP request' );
hfm_notify_assert( 'sent' === ( $statuses['webhook'] ?? '' ), 'generic webhook is sent through a mocked HTTP request' );
hfm_notify_assert( 2 === count( $GLOBALS['hfm_requests'] ), 'exactly two outbound HTTP requests are made' );

$leaked = false;
$token_in_header = false;
foreach ( $GLOBALS['hfm_requests'] as $request ) {
	if ( false !== strpos( (string) $request['body'], $canary ) ) {
		$leaked = true;
	}
	foreach ( (array) $request['headers'] as $value ) {
		if ( false !== strpos( (string) $value, $canary ) ) {
			$token_in_header = true;
		}
	}
}

hfm_notify_assert( false === $leaked, 'no secret appears in a webhook payload body' );
hfm_notify_assert( true === $token_in_header, 'the generic token appears only in the destination Authorization header' );

$builder = new Heic_Fix_Monitor_Message_Builder();
$with_canary             = $event;
$with_canary['document']['signals']['check_command']['evidence']['note'] = $canary;
$message = $builder->build( $with_canary, Heic_Fix_Monitor_Settings::get() );
hfm_notify_assert( false === strpos( $message['text'], $canary ), 'message text redacts a leaked secret' );
hfm_notify_assert( false === strpos( (string) wp_json_encode( $message['payload'] ), $canary ), 'webhook payload redacts a leaked secret' );
hfm_notify_assert( false !== strpos( $message['text'], 'troubleshooting' ), 'message links to the troubleshooting runbook' );

hfm_notify_assert( true === Heic_Fix_Monitor_Url_Validator::validate_base( 'https://example.com/hooks' ), 'accepts a normal public HTTPS URL' );
hfm_notify_assert( is_wp_error( Heic_Fix_Monitor_Url_Validator::validate_base( 'http://example.com/hooks' ) ), 'rejects plain HTTP' );
hfm_notify_assert( is_wp_error( Heic_Fix_Monitor_Url_Validator::validate_base( 'https://127.0.0.1/hooks' ) ), 'rejects loopback IPs' );
hfm_notify_assert( is_wp_error( Heic_Fix_Monitor_Url_Validator::validate_base( 'https://localhost/hooks' ) ), 'rejects localhost' );
hfm_notify_assert( is_wp_error( Heic_Fix_Monitor_Url_Validator::validate_base( 'https://user:pass@example.com/hooks' ) ), 'rejects embedded credentials' );
hfm_notify_assert( is_wp_error( Heic_Fix_Monitor_Url_Validator::validate_generic( 'https://not-allowlisted.example/hooks', array() ) ), 'rejects a host absent from the allowlist' );

hfm_notify_assert( true === Heic_Fix_Monitor_Url_Validator::validate_generic( 'https://example.com/hooks', array() ), 'accepts an allowlisted host' );

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( false !== strpos( $url, '/fail' ) ) {
			return new WP_Error( 'hfm_http_fail', 'failed' );
		}
		return $preempt;
	},
	20,
	3
);

update_option( 'hfm_settings', array_merge( Heic_Fix_Monitor_Settings::get(), array( 'retry_max' => 2 ) ), false );
Heic_Fix_Monitor_Secret_Store::save( array( 'generic_webhook_url' => 'https://example.com/fail' ) );
$failed = $manager->dispatch( $event, Heic_Fix_Monitor_Settings::get() );
$webhook_status = '';
foreach ( $failed['results'] as $entry ) {
	if ( 'webhook' === $entry['channel'] ) {
		$webhook_status = $entry['status'];
	}
}
hfm_notify_assert( 'failed' === $webhook_status, 'a failing webhook is reported as failed' );
hfm_notify_assert( false !== wp_next_scheduled( 'hfm_retry_delivery', array( $failed['event_id'], 'webhook', 1 ) ), 'a failed delivery schedules one bounded retry' );
wp_clear_scheduled_hook( 'hfm_retry_delivery' );

exit( $hfm_failures > 0 ? 1 : 0 );
