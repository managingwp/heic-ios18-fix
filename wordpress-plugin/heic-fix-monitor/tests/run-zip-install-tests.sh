#!/usr/bin/env sh
#
# Install the built zip into a clean WordPress, smoke it, and remove it.
#
set -eu

BASE=http://localhost:18082
PLUGIN=heic-fix-monitor
FAILURES=0

pass() { printf 'PASS: %s\n' "$1"; }
fail() { FAILURES=$((FAILURES + 1)); printf 'FAIL: %s\n' "$1" >&2; }

wp_cli() { wp --allow-root "$@"; }

if ! wp_cli core is-installed 2>/dev/null; then
	wp_cli core install \
		--url="$BASE" \
		--title="HEIC Monitor Zip Test" \
		--admin_user=admin \
		--admin_password=admin-password \
		--admin_email=admin@example.test \
		--skip-email
fi

if wp_cli plugin install /artifacts/heic-fix-monitor.zip --activate; then
	pass "installs and activates the standalone zip"
else
	fail "installs and activates the standalone zip"
fi

if [ ! -d /var/www/html/wp-content/plugins/$PLUGIN/assets ]; then
	fail "installed zip contains the bundled sample"
else
	pass "installed zip contains the bundled sample"
fi

if wp_cli option get hfm_settings >/dev/null 2>&1; then
	pass "installed plugin provisions options"
else
	fail "installed plugin provisions options"
fi

if wp_cli cron event list --fields=hook --format=csv | grep -q 'hfm_run_check'; then
	pass "installed plugin schedules the check"
else
	fail "installed plugin schedules the check"
fi

if wp_cli heic-monitor check --format=json 2>/dev/null | php -r '$d=json_decode(stream_get_contents(STDIN), true); exit(is_array($d) && isset($d["state"]) ? 0 : 1);'; then
	pass "installed plugin exposes the WP-CLI command"
else
	fail "installed plugin exposes the WP-CLI command"
fi

wp_cli plugin deactivate "$PLUGIN"
wp_cli plugin uninstall "$PLUGIN" --skip-delete

if wp_cli option get hfm_settings >/dev/null 2>&1; then
	fail "zip uninstall removes options"
else
	pass "zip uninstall removes options"
fi

if [ "$FAILURES" -gt 0 ]; then
	printf '\n%d failure(s)\n' "$FAILURES" >&2
	exit 1
fi

printf '\nAll zip install tests passed\n'
