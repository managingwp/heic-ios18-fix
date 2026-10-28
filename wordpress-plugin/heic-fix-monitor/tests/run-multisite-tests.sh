#!/usr/bin/env sh
#
# Multisite provisioning and isolation tests. Runs inside the CLI container.
#
set -eu

PLUGIN=heic-fix-monitor
BASE=http://localhost:18081
FAILURES=0

pass() {
	printf 'PASS: %s\n' "$1"
}

fail() {
	FAILURES=$((FAILURES + 1))
	printf 'FAIL: %s\n' "$1" >&2
}

wp_cli() {
	wp --allow-root "$@"
}

if ! wp_cli core is-installed 2>/dev/null; then
	wp_cli core install \
		--url="$BASE" \
		--title="HEIC Monitor Multisite" \
		--admin_user=admin \
		--admin_password=admin-password \
		--admin_email=admin@example.test \
		--skip-email
fi

if [ "$(wp_cli eval 'echo is_multisite() ? "yes" : "no";')" != "yes" ]; then
	wp_cli core multisite-convert --title="HEIC Monitor Multisite"
fi

wp_cli plugin deactivate "$PLUGIN" --network >/dev/null 2>&1 || true
wp_cli plugin activate "$PLUGIN" --network

if wp_cli option get hfm_settings --url="$BASE/" >/dev/null 2>&1; then
	pass "network activation provisions the main site"
else
	fail "network activation provisions the main site"
fi

second_id=$(wp_cli site list --fields=blog_id,slug --format=csv | awk -F, '$2 == "second" { print $1 }')
if [ -z "$second_id" ]; then
	second_id=$(wp_cli site create --slug=second --email=second@example.test --porcelain)
	wp_cli plugin deactivate "$PLUGIN" --network >/dev/null 2>&1 || true
	wp_cli plugin activate "$PLUGIN" --network
fi
if [ -n "$second_id" ]; then
	pass "creates a second site"
else
	fail "creates a second site"
fi

if wp_cli option get hfm_settings --url="$BASE/second/" >/dev/null 2>&1; then
	pass "wp_initialize_site provisions a new site"
else
	fail "wp_initialize_site provisions a new site"
fi

for url in "$BASE/" "$BASE/second/"; do
	hooks=$(wp_cli cron event list --url="$url" --fields=hook --format=csv || true)
	if printf '%s' "$hooks" | grep -q 'hfm_run_check'; then
		pass "check is scheduled for $url"
	else
		fail "check is scheduled for $url"
	fi
done

wp_cli option update hfm_state '{"site":"main"}' --format=json --url="$BASE/" >/dev/null
second_state=$(wp_cli option get hfm_state --format=json --url="$BASE/second/")
if [ "$second_state" = "[]" ]; then
	pass "site state is isolated"
else
	fail "site state is isolated (second site: $second_state)"
fi

wp_cli plugin deactivate "$PLUGIN" --network
wp_cli plugin uninstall "$PLUGIN" --skip-delete

for url in "$BASE/" "$BASE/second/"; do
	if wp_cli option get hfm_settings --url="$url" >/dev/null 2>&1; then
		fail "network uninstall removes options for $url"
	else
		pass "network uninstall removes options for $url"
	fi
done

if [ "$FAILURES" -gt 0 ]; then
	printf '\n%d failure(s)\n' "$FAILURES" >&2
	exit 1
fi

printf '\nAll multisite tests passed\n'
