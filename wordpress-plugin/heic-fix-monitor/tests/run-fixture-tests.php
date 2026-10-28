<?php
/**
 * Fixture-driven state and transition tests.
 *
 * @package Heic_Fix_Monitor
 */

require_once __DIR__ . '/bootstrap.php';

$failures = 0;

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $label     Label.
 * @return void
 */
function hfm_fixture_assert( $condition, $label ) {
	global $failures;

	if ( $condition ) {
		echo 'PASS: ' . $label . PHP_EOL;
		return;
	}

	++$failures;
	echo 'FAIL: ' . $label . PHP_EOL;
}

$fixtures = glob( __DIR__ . '/fixtures/*.json' );
sort( $fixtures );

foreach ( $fixtures as $file ) {
	$fixture = json_decode( (string) file_get_contents( $file ), true );
	$name    = $fixture['name'];

	$GLOBALS['hfm_test_options']   = array();
	$GLOBALS['hfm_test_transients'] = array();
	$GLOBALS['hfm_test_filters']   = array();
	update_option( 'hfm_settings', array( 'renotify_hours' => 1 ), false );

	$evaluator = new Heic_Fix_Monitor_State_Evaluator();
	$store     = new Heic_Fix_Monitor_State_Store();
	$events    = array();
	$states    = array();

	foreach ( $fixture['runs'] as $index => $signals ) {
		$evaluation = $evaluator->evaluate( $signals );
		$states[]   = $evaluation['state'];

		$result = array(
			'schema_version' => 1,
			'state'          => $evaluation['state'],
			'codes'          => $evaluation['codes'],
			'signals'        => $signals,
			'php_versions'   => array( '8.5' ),
			'checked_at'     => 1760000000 + ( $index * 60 ),
			'source'         => 'fixture',
		);

		$event    = $store->record( $result );
		$events[] = $event['type'];
	}

	$notifications = 0;
	foreach ( $events as $index => $type ) {
		if ( in_array( $type, array( 'regression', 'change', 'recovery', 'reminder' ), true ) ) {
			++$notifications;
		}
	}

	hfm_fixture_assert( $states === $fixture['expected_states'], $name . ': states ' . implode( ' -> ', $states ) );
	hfm_fixture_assert( $events === $fixture['expected_events'], $name . ': events ' . implode( ' -> ', $events ) );
	hfm_fixture_assert( $notifications === $fixture['expected_notifications'], $name . ': notification count ' . $notifications );
}

$GLOBALS['hfm_test_options']   = array();
$GLOBALS['hfm_test_transients'] = array();
$GLOBALS['hfm_test_filters']   = array();
update_option( 'hfm_settings', array( 'renotify_hours' => 1 ), false );

$now = 1760000000;
add_filter(
	'hfm_now',
	static function () use ( &$now ) {
		return $now;
	}
);

$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/linkage-shared.json' ), true );
$signals = $fixture['runs'][0];
$result  = array(
	'schema_version' => 1,
	'state'          => 'affected',
	'codes'          => array( 'linkage_shared' ),
	'signals'        => $signals,
	'php_versions'   => array( '8.5' ),
	'source'         => 'fixture',
);

$store = new Heic_Fix_Monitor_State_Store();
$first = $store->record( $result );
$now  += 60;
$second = $store->record( $result );
$now  += 3700;
$third = $store->record( $result );

hfm_fixture_assert( 'regression' === $first['type'], 'reminder: first failure alerts' );
hfm_fixture_assert( 'none' === $second['type'], 'reminder: identical failure inside the window is silent' );
hfm_fixture_assert( 'reminder' === $third['type'], 'reminder: identical failure after the window re-alerts once' );

$GLOBALS['hfm_test_options']['hfm_state'] = 'corrupted';
$recovered = $store->record( $result );
hfm_fixture_assert( true === $recovered['recovered_store'], 'a corrupted state option is replaced without unserialize' );

$normalizer = new Heic_Fix_Monitor_Normalizer();
$first_doc  = $normalizer->build( $result );

$reordered        = $result;
$reordered['signals'] = array();
foreach ( array_reverse( array_keys( $signals ) ) as $key ) {
	$reordered['signals'][ $key ] = $signals[ $key ];
}
$second_doc = $normalizer->build( $reordered );

hfm_fixture_assert( $first_doc['fingerprint'] === $second_doc['fingerprint'], 'fingerprints are stable across key order' );

$changed                     = $result;
$changed['signals']['check_command']['evidence']['php8.5']['libheif'] = '1.24.0';
$third_doc                   = $normalizer->build( $changed );
hfm_fixture_assert( $first_doc['fingerprint'] !== $third_doc['fingerprint'], 'a material evidence change alters the fingerprint' );

exit( $failures > 0 ? 1 : 0 );
