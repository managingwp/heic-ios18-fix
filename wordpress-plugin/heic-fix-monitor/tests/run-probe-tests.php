<?php
/**
 * Probe contract tests, runnable without WordPress.
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
function hfm_assert( $condition, $label ) {
	global $failures;

	if ( $condition ) {
		echo 'PASS: ' . $label . PHP_EOL;
		return;
	}

	++$failures;
	echo 'FAIL: ' . $label . PHP_EOL;
}

$line = 'php8.5 status=ok imagick=3.8.1 imagemagick=7 heic=yes libheif=1.23.7 linkage=static-hidden decode=ok';
$parsed = Heic_Fix_Monitor_Check_Probe::parse_output( $line, array( '8.5' ) );
hfm_assert( true === $parsed['ok'], 'parses the stable check line' );
hfm_assert( 'static-hidden' === $parsed['versions']['8.5']['linkage'], 'captures linkage' );
hfm_assert( '1.23.7' === $parsed['versions']['8.5']['libheif'], 'captures libheif version' );

$parsed = Heic_Fix_Monitor_Check_Probe::parse_output( 'php8.5 status=ok imagick=3.8.1 imagemagick=7 heic=yes libheif=1.23.7 decode=ok', array( '8.5' ) );
hfm_assert( false === $parsed['ok'] && in_array( 'linkage', $parsed['missing'], true ), 'rejects a missing field' );

$parsed = Heic_Fix_Monitor_Check_Probe::parse_output( $line . ' extra=1', array( '8.5' ) );
hfm_assert( false === $parsed['ok'], 'rejects an unknown field' );

$parsed = Heic_Fix_Monitor_Check_Probe::parse_output( $line . "\n" . $line, array( '8.5' ) );
hfm_assert( false === $parsed['ok'], 'rejects a duplicate version line' );

$parsed = Heic_Fix_Monitor_Check_Probe::parse_output( $line, array( '8.3' ) );
hfm_assert( false === $parsed['ok'], 'rejects a missing requested version' );

hfm_assert( 'ok' === Heic_Fix_Monitor_Check_Probe::map_exit( 0 ), 'maps exit 0 to ok' );
hfm_assert( 'affected' === Heic_Fix_Monitor_Check_Probe::map_exit( 1 ), 'maps exit 1 to affected' );
hfm_assert( 'error' === Heic_Fix_Monitor_Check_Probe::map_exit( 2 ), 'maps exit 2 to error' );
hfm_assert( 'affected' === Heic_Fix_Monitor_Check_Probe::map_exit( 3 ), 'maps exit 3 to affected' );
hfm_assert( 'error' === Heic_Fix_Monitor_Check_Probe::map_exit( 42 ), 'maps unknown exits to error' );

hfm_assert( false === Heic_Fix_Monitor_Check_Probe::is_allowed_binary( '/tmp/evil/heic-fix.sh' ), 'rejects an unallowlisted binary' );
hfm_assert( false === Heic_Fix_Monitor_Check_Probe::is_allowed_binary( '/opt/heic-ios18-fix/other.sh' ), 'rejects a different script name' );

$tmp = sys_get_temp_dir() . '/hfm-probe-' . getmypid();
mkdir( $tmp, 0700, true );
$allowed = $tmp . '/heic-fix.sh';
file_put_contents( $allowed, "#!/bin/sh\nexit 0\n" );
chmod( $allowed, 0700 );
define( 'HEIC_FIX_MONITOR_ALLOWED_CHECK_PATHS', array( $allowed ) );
hfm_assert( true === Heic_Fix_Monitor_Check_Probe::is_allowed_binary( $allowed ), 'accepts an allowlisted executable' );

hfm_assert( "'/bin/echo' 'hello world'" === Heic_Fix_Monitor_Command_Runner::format_command( '/bin/echo', array( 'hello world' ) ), 'escapes every argument for audit output' );

$runner = new Heic_Fix_Monitor_Command_Runner();
$result = $runner->run( '/bin/echo', array( 'hello world' ), 5 );
hfm_assert( 0 === $result['exit_code'] && "hello world\n" === $result['stdout'], 'runs a fixed binary without a shell' );

$result = $runner->run( '/bin/sleep', array( '2' ), 1 );
hfm_assert( true === $result['timed_out'], 'enforces the timeout' );

$result = $runner->run( '/nonexistent/binary', array(), 2 );
hfm_assert( 'binary_invalid' === $result['error'], 'rejects a missing binary before execution' );

$settings = array(
	'decode_enabled'      => 1,
	'prefix'              => $tmp . '/missing-prefix',
	'php_versions'        => array( '8.5' ),
	'memory_floor_mb'     => 256,
	'decode_min_interval' => 300,
	'min_jpeg_bytes'      => 1024,
	'min_width'           => 100,
	'min_height'          => 100,
	'check_enabled'       => 1,
	'check_binary'        => $allowed,
	'filesystem_enabled'  => 1,
	'timeout'             => 5,
);

$probe = new Heic_Fix_Monitor_Filesystem_Probe();
$signal = $probe->run( array( 'settings' => $settings, 'php_versions' => array( '8.5' ), 'runner' => $runner ) );
hfm_assert( 'filesystem_elf' === $signal['signal'] && 'missing' === $signal['status'], 'reports a missing prefix as missing' );

$probe  = new Heic_Fix_Monitor_Imagick_Probe();
$signal = $probe->run( array( 'settings' => $settings, 'source' => 'manual' ) );
if ( extension_loaded( 'imagick' ) ) {
	hfm_assert( in_array( $signal['status'], array( 'ok', 'affected' ), true ), 'runs the Imagick probe without an uncaught exception' );
	hfm_assert( isset( $signal['evidence']['decode'] ) || isset( $signal['evidence']['heic_formats'] ), 'returns bounded decode evidence' );
} else {
	hfm_assert( 'affected' === $signal['status'] && 'imagick_missing' === $signal['code'], 'reports missing Imagick as affected' );
}

$engine = new Heic_Fix_Monitor_Probe_Engine();
$result = $engine->run( $settings, 'cli' );
hfm_assert( isset( $result['signals']['imagick_decode'], $result['signals']['check_command'], $result['signals']['filesystem_elf'] ), 'engine returns all three signals' );
hfm_assert( 1 === $result['schema_version'] && is_array( $result['php_versions'] ), 'engine returns a versioned result document' );

$result = $runner->run( $allowed, array( 'check', '-q', '--php', '8.5' ), 5 );
hfm_assert( 0 === $result['exit_code'], 'runs the allowlisted check binary with fixed arguments' );

unlink( $allowed );
rmdir( $tmp );

exit( $failures > 0 ? 1 : 0 );
