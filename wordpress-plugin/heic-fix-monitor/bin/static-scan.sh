#!/usr/bin/env bash
#
# Static safety scan for the HEIC Fix Monitor plugin.
#
set -Eeuo pipefail

ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT"

fail() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

mapfile -t runtime < <(find . -type f -name '*.php' ! -path './tests/*' ! -path './artifacts/*' | sort)

if (( ${#runtime[@]} == 0 )); then
	fail "no runtime PHP files found"
fi

for pattern in 'eval[[:space:]]*(' 'shell_exec' 'passthru[[:space:]]*(' 'system[[:space:]]*(' 'popen[[:space:]]*(' 'create_function'; do
	if grep -nE "$pattern" "${runtime[@]}" >/dev/null 2>&1; then
		fail "forbidden code construct: $pattern"
	fi
done

if grep -nE "(include|require)(_once)?[^;]*(https?://|wp_remote_)" "${runtime[@]}" >/dev/null 2>&1; then
	fail "remote include detected"
fi

if grep -nE "'(build|enable|rollback|sudo|apt|apt-get|systemctl|service)'" includes/ admin/ >/dev/null 2>&1; then
	fail "mutating heic-fix command or privileged tool referenced"
fi

if grep -nE "add_option\([^;]+,[^;]+,[^;]+,[[:space:]]*'yes'" "${runtime[@]}" >/dev/null 2>&1; then
	fail "plugin option is autoloaded"
fi

for file in "${runtime[@]}"; do
	case "$file" in
		./uninstall.php)
			grep -q "WP_UNINSTALL_PLUGIN" "$file" || fail "uninstall.php lacks the uninstall guard"
			;;
		*)
			grep -q "defined( 'ABSPATH' )" "$file" || fail "$file lacks the ABSPATH guard"
			;;
	esac
done

printf 'PASS: static safety scan (%d runtime files)\n' "${#runtime[@]}"
