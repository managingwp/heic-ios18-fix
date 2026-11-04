#!/usr/bin/env bash
#
# Fast, non-building checks for the default install prefix.
#
# Proves the four behaviours the shared prefix depends on, without compiling
# anything and without touching a real installation:
#
#   1. --help advertises the readable default.
#   2. An explicit --prefix still wins.
#   3. A legacy-only /opt installation produces exactly one migration hint and
#      nothing is written to either prefix.
#   4. The hint stays silent when the caller chose a prefix, or when the default
#      prefix already holds an installation.
#
# It also accepts --audit, which fails when a source file mentions the legacy
# prefix without being on the allowlist below, so a half-finished migration
# cannot pass silently. Run --audit once the docs and plugin defaults are
# migrated; the fixture checks above never need a build.
#
# Usage: tests/check-default-prefix.sh [--audit]
#
# Copyright (c) 2026 Managing WP
# SPDX-License-Identifier: MIT

set -Eeuo pipefail

AUDIT=0
while (( $# )); do
    case $1 in
        --audit) AUDIT=1; shift ;;
        *) printf 'usage: %s [--audit]\n' "$(basename -- "$0")" >&2; exit 2 ;;
    esac
done

REPO=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
SCRIPT="$REPO/heic-fix.sh"
NEW_DEFAULT="/usr/local/lib/heic-ios18-fix"

# Files allowed to mention /opt/heic-ios18-fix: the legacy constant and its
# comment, the migration runbook, the monitor's backward-compatibility notes,
# historical changelog/plan evidence, and this test.
ALLOWED_FILES="
heic-fix.sh
docs/commands.md
docs/how-it-works.md
docs/troubleshooting.md
docs/platforms/gridpane.md
wordpress-plugin/heic-fix-monitor/docs/configuration.md
wordpress-plugin/heic-fix-monitor/docs/architecture.md
wordpress-plugin/heic-fix-monitor/includes/probes/class-heic-fix-monitor-check-probe.php
CHANGELOG.md
plans/20261028-wordpress-heic-monitor.md
tests/check-default-prefix.sh
"

pass() {
    printf 'ok: %s\n' "$*"
}

fail() {
    printf '\nFAIL: %s\n' "$*" >&2
    exit 1
}

TMP=$(mktemp -d "${TMPDIR:-/tmp}/heic-prefix-test.XXXXXX")
trap 'rm -rf -- "$TMP"' EXIT

[[ -x $SCRIPT ]] || fail "missing $SCRIPT"

# 1. The help text is the documented flag source of truth.
out=$("$SCRIPT" --help)
grep -qF "default: $NEW_DEFAULT" <<<"$out" \
    || fail "--help does not advertise $NEW_DEFAULT"
grep -qF '/opt/heic-ios18-fix' <<<"$out" \
    && fail "--help still presents /opt/heic-ios18-fix as the default"
pass "--help advertises $NEW_DEFAULT"

# 2. An explicit prefix is honoured and is not overridden by the default.
explicit="$TMP/explicit"
out=$("$SCRIPT" status --prefix "$explicit" 2>&1)
grep -qF "prefix        : $explicit" <<<"$out" \
    || fail "status --prefix did not use $explicit"
grep -q 'found an installation in' <<<"$out" \
    && fail "the legacy hint fired for an explicit prefix"
pass "--prefix overrides the default"

# 3. A legacy-only installation is reported once, and nothing is mutated.
new="$TMP/new-default"
old="$TMP/legacy"
mkdir -p "$old/php/8.2"
printf 'LIBHEIF_VERSION=v1.23.7\n' >"$old/installed-versions.txt"
: >"$old/php/8.2/imagick.so"
legacy_before=$(cd "$old" && find . -type f -exec sha256sum {} + | sort)

set +e
out=$(HEIC_FIX_DEFAULT_PREFIX="$new" HEIC_FIX_LEGACY_PREFIX="$old" \
    "$SCRIPT" status 2>&1)
rc=$?
set -e
(( rc == 0 )) || fail "status exited $rc with a legacy installation present"

hints=$(grep -c "found an installation in $old" <<<"$out" || true)
(( hints == 1 )) || fail "expected exactly 1 legacy hint, got $hints"

grep -qF -- "$SCRIPT  status" <<<"$out" \
    || grep -qF -- "status --prefix $old" <<<"$out" \
    || fail "the hint does not show how to inspect the old prefix"
grep -qF "Nothing in $old was changed" <<<"$out" \
    || fail "the hint does not state that the old prefix is untouched"
pass "legacy-only install produces one non-destructive hint"

[[ ! -e $new ]] || fail "the hint created $new"
legacy_after=$(cd "$old" && find . -type f -exec sha256sum {} + | sort)
[[ $legacy_before == "$legacy_after" ]] || fail "the hint modified $old"
pass "neither prefix was modified"

# 4a. An explicit --prefix suppresses the hint.
out=$(HEIC_FIX_DEFAULT_PREFIX="$new" HEIC_FIX_LEGACY_PREFIX="$old" \
    "$SCRIPT" status --prefix "$old" 2>&1)
grep -q 'found an installation in' <<<"$out" \
    && fail "the hint fired with an explicit --prefix"
pass "explicit --prefix suppresses the hint"

# 4b. A default prefix that already holds an install suppresses the hint.
mkdir -p "$new/php/8.2"
: >"$new/php/8.2/imagick.so"
out=$(HEIC_FIX_DEFAULT_PREFIX="$new" HEIC_FIX_LEGACY_PREFIX="$old" \
    "$SCRIPT" status 2>&1)
grep -q 'found an installation in' <<<"$out" \
    && fail "the hint fired even though the default prefix is installed"
pass "an installed default prefix suppresses the hint"

# 5. No stale default survives outside the allowlist.
if (( AUDIT )); then
    unexpected=""
    while IFS= read -r rel; do
        [[ -z $rel ]] && continue
        if ! grep -qxF -- "$rel" <<<"$ALLOWED_FILES"; then
            unexpected+="  $rel"$'\n'
        fi
    done < <(cd "$REPO" && grep -RFIl --exclude-dir=.git --exclude-dir=node_modules \
        --exclude-dir=build --exclude-dir=.docusaurus --exclude-dir=plans \
        '/opt/heic-ios18-fix' . 2>/dev/null | sed 's|^\./||' | sort)

    if [[ -n $unexpected ]]; then
        printf '%s' "$unexpected" >&2
        fail "the files above still reference /opt/heic-ios18-fix; migrate them or add them to ALLOWED_FILES"
    fi
    pass "no unclassified /opt/heic-ios18-fix references"
else
    printf 'skip: stale-default audit (pass --audit)\n'
fi

printf '\ndefault prefix checks passed\n'
