#!/usr/bin/env bash
#
# End-to-end test for heic-fix.sh inside a disposable Debian/Ubuntu container.
#
# Usage (inside an ubuntu:22.04 or ubuntu:24.04 container):
#   PHP_VER=8.3 /repo/tests/run-container-test.sh
#
# The script installs ondrej PHP 8.3 (falling back to the distro version if the
# PPA is unavailable), php-imagick, php-gd, generates a HEIC with the system
# heif-enc, then exercises check -> build -> php -> verify -> rollback -> enable.
#
# Copyright (c) 2026 Managing WP
# SPDX-License-Identifier: MIT
#
# shellcheck disable=SC2016 # PHP snippets are intentionally single-quoted.

set -Eeuo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
REPO=${REPO:-$(dirname -- "$SCRIPT_DIR")}
PHP_VER=${PHP_VER:-8.3}
JOBS=${JOBS:-$(nproc 2>/dev/null || printf '2')}
PREFIX=${PREFIX:-/opt/heic-ios18-fix}
SAMPLE=${SAMPLE:-/tmp/heic-fix-test.heic}

export NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1 DEBIAN_FRONTEND=noninteractive

log() {
    printf '\n=== %s ===\n' "$*" >&2
}

fail() {
    printf '\nTEST FAILURE: %s\n' "$*" >&2
    exit 1
}

run_expect() {
    local expected=$1
    shift
    local rc=0
    "$@" || rc=$?
    if (( rc != expected )); then
        fail "expected exit $expected, got $rc: $*"
    fi
}

apt_install() {
    apt-get install -y --no-install-recommends "$@"
}

setup_php() {
    apt-get update -qq
    apt_install ca-certificates curl gnupg lsb-release software-properties-common

    if ! add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1; then
        printf 'WARN: ondrej PPA unavailable; using the distro PHP\n' >&2
    fi
    apt-get update -qq

    local candidate="php${PHP_VER}-cli"
    if ! apt-cache show "$candidate" >/dev/null 2>&1; then
        local fallback
        fallback=$(apt-cache search --names-only '^php[0-9]+\.[0-9]+-cli$' | awk '{print $1}' | sort -V | tail -n 1)
        [[ -n $fallback ]] || fail "no PHP CLI package available"
        PHP_VER=${fallback#php}
        PHP_VER=${PHP_VER%-cli}
        printf 'WARN: using distro PHP %s\n' "$PHP_VER" >&2
    fi

    apt_install "php${PHP_VER}-cli" "php${PHP_VER}-dev" "php${PHP_VER}-fpm" \
        "php${PHP_VER}-gd" "php${PHP_VER}-imagick" imagemagick libheif-examples

    # libheif >= 1.17 splits codecs into plugins; both names are best effort.
    apt_install libheif-plugin-x265 || printf 'WARN: libheif-plugin-x265 not installed\n' >&2
    apt_install libheif-plugin-libde265 >/dev/null 2>&1 || true
    apt_install libx265-199 >/dev/null 2>&1 || true

    command -v "php${PHP_VER}" >/dev/null || fail "php${PHP_VER} not installed"
    command -v "phpize${PHP_VER}" >/dev/null || fail "phpize${PHP_VER} not installed"
}

make_sample() {
    [[ -f $SAMPLE ]] && return 0
    local png=/tmp/heic-fix-input.png
    convert -size 640x480 -depth 8 gradient:'#2050a0-#f0c040' "$png" \
        || fail "could not create test PNG"
    if ! heif-enc -b 8 -q 80 -o "$SAMPLE" "$png" >/tmp/heif-enc.log 2>&1; then
        cat /tmp/heif-enc.log >&2 || true
        # ImageMagick 6 can sometimes encode HEIC when heif-enc cannot.
        if ! convert "$png" "$SAMPLE" >/tmp/convert-heic.log 2>&1; then
            cat /tmp/convert-heic.log >&2 || true
            # Last resort: a pinned sample shipped in the libheif repository.
            printf 'WARN: falling back to the libheif example HEIC\n' >&2
            curl -fL --retry 3 -o "$SAMPLE" \
                "https://raw.githubusercontent.com/strukturag/libheif/v1.17.6/examples/example.heic" \
                || fail "could not generate or download a HEIC test sample"
        fi
    fi
    [[ -s $SAMPLE ]] || fail "generated sample is empty"
    # Validate the container structure without requiring a decoder plugin.
    if ! head -c 12 "$SAMPLE" | grep -aq 'ftyp'; then
        fail "generated sample is not an ISO BMFF/HEIF file"
    fi
    heif-info "$SAMPLE" >/tmp/heif-info.log 2>&1 || true
    printf 'sample: %s (%s bytes)\n' "$SAMPLE" "$(stat -c %s "$SAMPLE")" >&2
}

assert_contains() {
    local haystack=$1 needle=$2 label=$3
    if [[ $haystack != *"$needle"* ]]; then
        printf '%s\n' "$haystack" >&2
        fail "$label: expected to find '$needle'"
    fi
}

assert_ldd_isolation() {
    local so="$PREFIX/php/$PHP_VER/imagick.so"
    [[ -f $so ]] || fail "missing $so"
    local out
    out=$(ldd "$so")
    assert_contains "$out" "libheif-heicfix.so.1 => $PREFIX/" "ldd libheif isolation"
    assert_contains "$out" "libde265-heicfix.so.0 => $PREFIX/" "ldd libde265 isolation"
    if [[ $out == *"not found"* ]]; then
        printf '%s\n' "$out" >&2
        fail "imagick.so has unresolved libraries"
    fi
}

assert_decode_with_gd() {
    local gd_so
    gd_so="$(php"$PHP_VER" -r 'echo ini_get("extension_dir");')/gd.so"
    if [[ -f $gd_so ]] && ldd "$gd_so" 2>/dev/null | grep -q 'libheif'; then
        printf 'GD links the system libheif (the SONAME trap is present)\n' >&2
    else
        printf 'WARN: GD does not link libheif here; forcing the collision with LD_PRELOAD\n' >&2
    fi

    HEIC_PROBE_SAMPLE="$SAMPLE" php"$PHP_VER" -r '
        if (!extension_loaded("gd")) { fwrite(STDERR, "gd not loaded\n"); exit(1); }
        if (!extension_loaded("imagick")) { fwrite(STDERR, "imagick not loaded\n"); exit(1); }
        $im = new Imagick(getenv("HEIC_PROBE_SAMPLE"));
        $im->setImageFormat("jpeg");
        $blob = $im->getImageBlob();
        if (strlen($blob) < 100) { fwrite(STDERR, "decode produced no data\n"); exit(1); }
        printf("GD + Imagick decoded %dx%d in one process (%d JPEG bytes)\n",
            $im->getImageWidth(), $im->getImageHeight(), strlen($blob));
    ' || fail "GD + Imagick decode failed in one process"
}

main() {
    [[ ${EUID:-$(id -u)} -eq 0 ]] || fail "run this test as root (inside a container)"

    log "Installing PHP $PHP_VER, php-imagick and php-gd"
    setup_php
    make_sample

    log "CLI sanity: GD and stock imagick must both be loaded"
    local modules
    modules=$(php"$PHP_VER" -m)
    assert_contains "$modules" "gd" "php-gd"
    assert_contains "$modules" "imagick" "php-imagick"

    log "check before the fix (expect AFFECTED)"
    local out rc
    rc=0
    out=$("$REPO/heic-fix.sh" check --php "$PHP_VER" --sample "$SAMPLE" 2>&1) || rc=$?
    printf '%s\n' "$out" >&2
    [[ $rc -eq 1 ]] || fail "check should report AFFECTED (exit 1), got $rc"
    assert_contains "$out" "AFFECTED" "check output"

    log "build"
    "$REPO/heic-fix.sh" build -y --jobs "$JOBS" --sample "$SAMPLE"

    log "build again (idempotency)"
    "$REPO/heic-fix.sh" build -y --jobs "$JOBS" --sample "$SAMPLE"

    log "php: build, pre-flight with GD loaded, enable"
    "$REPO/heic-fix.sh" php --php "$PHP_VER" -y --sample "$SAMPLE"

    log "verify (CLI + FPM, with GD loaded)"
    "$REPO/heic-fix.sh" verify --php "$PHP_VER" --sample "$SAMPLE"
    assert_ldd_isolation
    assert_decode_with_gd

    local system_libheif
    system_libheif=$(ldconfig -p | awk '/libheif\.so\.1 /{print $NF; exit}')
    if [[ -n $system_libheif ]]; then
        log "verify with the system libheif preloaded (stronger GD collision test)"
        LD_PRELOAD="$system_libheif" "$REPO/heic-fix.sh" verify --php "$PHP_VER" --sample "$SAMPLE"
    fi

    local cli_im fpm_im
    cli_im=$(php"$PHP_VER" -r 'echo Imagick::getVersion()["versionString"];')
    assert_contains "$cli_im" "ImageMagick 7" "CLI ImageMagick version"
    fpm_im=$(php-fpm"$PHP_VER" -i 2>&1 | sed -n 's/^Imagick using ImageMagick library version => //p' | head -n 1)
    assert_contains "$fpm_im" "ImageMagick 7" "FPM ImageMagick version"

    log "check after the fix (expect OK)"
    run_expect 0 "$REPO/heic-fix.sh" check -q --php "$PHP_VER" --sample "$SAMPLE"

    log "rollback (expect AFFECTED again)"
    run_expect 0 "$REPO/heic-fix.sh" rollback --php "$PHP_VER" -y
    run_expect 1 "$REPO/heic-fix.sh" check -q --php "$PHP_VER" --sample "$SAMPLE"

    log "re-enable (expect OK and verified decode)"
    run_expect 0 "$REPO/heic-fix.sh" enable --php "$PHP_VER" -y
    run_expect 0 "$REPO/heic-fix.sh" check -q --php "$PHP_VER" --sample "$SAMPLE"
    "$REPO/heic-fix.sh" verify --php "$PHP_VER" --sample "$SAMPLE"
    assert_ldd_isolation
    assert_decode_with_gd

    log "status"
    "$REPO/heic-fix.sh" status --php "$PHP_VER"

    printf '\n%sAll container tests passed (PHP %s)%s\n' "$(printf '\033[32m')" "$PHP_VER" "$(printf '\033[0m')"
}

main "$@"
