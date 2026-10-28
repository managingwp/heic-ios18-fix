#!/usr/bin/env sh
#
# Runs inside the WordPress CLI container.
# Assumes WordPress core is installed and the plugin is mounted.
#
set -eu

PLUGIN=heic-fix-monitor
FAILURES=0

pass() {
	printf 'PASS: %s\n' "$1"
}

fail() {
	FAILURES=$((FAILURES + 1))
	printf 'FAIL: %s\n' "$1" >&2
}

assert_eq() {
	if [ "$1" = "$2" ]; then
		pass "$3"
	else
		fail "$3 (expected '$2', got '$1')"
	fi
}

wp_cli() {
	wp --allow-root "$@"
}

if ! wp_cli core is-installed 2>/dev/null; then
	wp_cli core install \
		--url=http://localhost:18080 \
		--title="HEIC Monitor Test" \
		--admin_user=admin \
		--admin_password=admin-password \
		--admin_email=admin@example.test \
		--skip-email
fi

wp_cli plugin is-active "$PLUGIN" >/dev/null 2>&1 || wp_cli plugin activate "$PLUGIN"

sample_before=$(sha256sum /var/www/html/wp-content/plugins/$PLUGIN/assets/failure_magick.HEIC | awk '{print $1}')

settings=$(wp_cli option get hfm_settings --format=json)
assert_eq "$(printf '%s' "$settings" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo is_array($d) && isset($d["interval"]) && $d["interval"] === "hourly" ? "yes" : "no";')" "yes" "settings option has the typed default shape"

autoload=$(wp_cli option list --search='hfm_%' --fields=option_name,autoload --format=csv)
if printf '%s' "$autoload" | grep -v '^option_name' | grep -q 'on\|yes\|auto'; then
	fail "plugin options must not autoload"
else
	pass "plugin options do not autoload"
fi

sanitized=$(wp_cli eval '
$s = Heic_Fix_Monitor_Settings::instance();
$clean = $s->sanitize(
	array(
		"php_versions"   => "8.5, 8.5, bad, 8.3",
		"prefix"         => "/opt/heic-ios18-fix/../etc",
		"sample_path"    => "",
		"check_binary"   => "/opt/heic-ios18-fix/heic-fix.sh",
		"interval"       => "weekly",
		"timeout"        => "9999",
		"emails"         => "ops@example.test, not-an-email",
		"digest_time"    => "25:99",
		"renotify_hours" => "0",
	)
);
echo wp_json_encode(
	array(
		"versions" => $clean["php_versions"],
		"prefix"   => $clean["prefix"],
		"interval" => $clean["interval"],
		"timeout"  => $clean["timeout"],
		"emails"   => $clean["emails"],
		"digest"   => $clean["digest_time"],
		"renotify" => $clean["renotify_hours"],
	)
);
')
assert_eq "$(printf '%s' "$sanitized" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo implode(",", $d["versions"]);')" "8.3,8.5" "PHP versions are validated and deduplicated"
assert_eq "$(printf '%s' "$sanitized" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["prefix"];')" "/opt/heic-ios18-fix" "parent-directory paths fall back to the previous value"
assert_eq "$(printf '%s' "$sanitized" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["interval"];')" "hourly" "unknown intervals fall back"
assert_eq "$(printf '%s' "$sanitized" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["timeout"];')" "30" "out-of-range numbers fall back"
assert_eq "$(printf '%s' "$sanitized" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo implode(",", $d["emails"]);')" "ops@example.test" "invalid recipients are dropped"
assert_eq "$(printf '%s' "$sanitized" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["digest"];')" "08:00" "invalid digest times fall back"

secret_roundtrip=$(wp_cli eval '
if ( ! Heic_Fix_Monitor_Secret_Store::encryption_available() ) { echo "no-sodium"; return; }
$url = "https://hooks.slack.com/services/T000/B000/secret-canary";
$r = Heic_Fix_Monitor_Secret_Store::save( array( "slack_webhook_url" => $url ) );
$raw = get_option( "hfm_secrets" );
$plain = is_array( $raw ) && isset( $raw["slack_webhook_url"] ) ? $raw["slack_webhook_url"] : "";
echo is_wp_error( $r ) ? "save-error" : ( Heic_Fix_Monitor_Secret_Store::get( "slack_webhook_url" ) === $url ? "roundtrip" : "mismatch" );
echo "|", ( strpos( $plain, "secret-canary" ) === false ? "no-plaintext" : "plaintext" );
')
assert_eq "$secret_roundtrip" "roundtrip|no-plaintext" "secrets round-trip through encryption without plaintext storage"

notify_output=$(wp_cli eval-file /var/www/html/wp-content/plugins/$PLUGIN/tests/notification-tests.php 2>&1) || true
printf '%s\n' "$notify_output"
if printf '%s\n' "$notify_output" | grep -Eq '^FAIL:|Parse error'; then
	fail "notification tests"
fi

admin_output=$(wp_cli eval-file /var/www/html/wp-content/plugins/$PLUGIN/tests/admin-tests.php 2>&1) || true
printf '%s\n' "$admin_output"
if printf '%s\n' "$admin_output" | grep -Eq '^FAIL:|Parse error'; then
	fail "admin tests"
fi

cli_rc=0
cli_out=$(wp_cli heic-monitor check --format=json --force-decode 2>/dev/null) || cli_rc=$?
cli_state=$(printf '%s' "$cli_out" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo is_array($d) && isset($d["state"], $d["signals"]) ? $d["state"] : "invalid";')
if printf '%s' "$cli_out" | php -r '$d=json_decode(stream_get_contents(STDIN), true); exit(is_array($d) && isset($d["state"], $d["signals"], $d["event"]) ? 0 : 1);'; then
	pass "wp heic-monitor check emits bounded JSON"
else
	fail "wp heic-monitor check emits bounded JSON"
fi
case "$cli_state" in
	ok|degraded|affected|missing|error) pass "CLI reports a valid state ($cli_state)" ;;
	*) fail "CLI reports a valid state ($cli_state)" ;;
esac
case "$cli_rc" in
	0|1|2|3) pass "CLI exit code is in the documented set ($cli_rc)" ;;
	*) fail "CLI exit code is in the documented set ($cli_rc)" ;;
esac

if wp_cli heic-monitor check --format=xml >/dev/null 2>&1; then
	fail "CLI rejects an unknown format"
else
	pass "CLI rejects an unknown format"
fi
if wp_cli heic-monitor check --unknown-option >/dev/null 2>&1; then
	fail "CLI rejects unknown options"
else
	pass "CLI rejects unknown options"
fi
if wp_cli heic-monitor check extra-arg >/dev/null 2>&1; then
	fail "CLI rejects positional arguments"
else
	pass "CLI rejects positional arguments"
fi

wp_cli plugin deactivate "$PLUGIN"
cron_after=$(wp_cli cron event list --fields=hook --format=csv || true)
if printf '%s' "$cron_after" | grep -q 'hfm_run_check'; then
	fail "deactivation unschedules the check event"
else
	pass "deactivation unschedules the check event"
fi

wp_cli plugin activate "$PLUGIN"
assert_eq "$(wp_cli plugin is-active "$PLUGIN" && echo active)" "active" "reactivation leaves the plugin active"
cron_reactivated=$(wp_cli cron event list --fields=hook --format=csv || true)
if printf '%s' "$cron_reactivated" | grep -q 'hfm_run_check'; then
	pass "reactivation reschedules the check event"
else
	fail "reactivation reschedules the check event"
fi

wp_cli plugin deactivate "$PLUGIN"
wp_cli plugin uninstall "$PLUGIN" --skip-delete
if wp_cli option get hfm_settings >/dev/null 2>&1; then
	fail "uninstall removes plugin options"
else
	pass "uninstall removes plugin options"
fi
cron_uninstalled=$(wp_cli cron event list --fields=hook --format=csv || true)
if printf '%s' "$cron_uninstalled" | grep -q 'hfm_'; then
	fail "uninstall removes plugin scheduled events"
else
	pass "uninstall removes plugin scheduled events"
fi

sample_after=$(sha256sum /var/www/html/wp-content/plugins/$PLUGIN/assets/failure_magick.HEIC | awk '{print $1}')
if [ "$sample_before" = "$sample_after" ]; then
	pass "probes and lifecycle leave the bundled sample unchanged"
else
	fail "probes and lifecycle leave the bundled sample unchanged"
fi

if [ "$FAILURES" -gt 0 ]; then
	printf '\n%d failure(s)\n' "$FAILURES" >&2
	exit 1
fi

printf '\nAll WordPress tests passed\n'
