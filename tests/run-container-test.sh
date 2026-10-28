#!/usr/bin/env bash
#
# End-to-end test for heic-fix.sh inside a disposable Debian/Ubuntu container.
#
# Usage (inside an ubuntu:22.04 or ubuntu:24.04 container):
#   PHP_VER=8.3 /repo/tests/run-container-test.sh
#
# The script installs ondrej PHP (falling back to the distro version if the PPA
# is unavailable), php-imagick and php-gd, generates a HEIC with the system
# heif-enc, then exercises check -> build -> php -> verify -> rollback -> enable.
#
# Two samples are mandatory:
#   - SAMPLE (generated): proves the general HEIC pipeline still works.
#   - samples/failure_magick.HEIC: the iOS 18 photo whose auxiliary images older
#     libheif builds reject. A generated HEIC decodes on those builds, so it
#     cannot prove this bug is fixed.
#
# Decode coverage runs three ways against the checked-in sample: the private
# ImageMagick CLI, Imagick alone, and GD loaded before Imagick. GD-first is the
# production order and the case where a system libheif can capture ImageMagick's
# heif_* calls.
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
COLLISION_SAMPLE=${COLLISION_SAMPLE:-$REPO/samples/failure_magick.HEIC}

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
    assert_contains "$out" "libMagickCore-7" "ldd MagickCore"
    if [[ $out == *"not found"* ]]; then
        printf '%s\n' "$out" >&2
        fail "imagick.so has unresolved libraries"
    fi
    if grep -Eq 'libheif[^ ]*\.so' <<<"$out"; then
        printf '%s\n' "$out" >&2
        fail "imagick.so still resolves a shared libheif"
    fi
}

php_extension_dir() {
    php"$PHP_VER" -r 'echo ini_get("extension_dir");'
}

gd_extension_path() {
    local dir
    dir=$(php_extension_dir)
    [[ -f "$dir/gd.so" ]] || return 1
    printf '%s\n' "$dir/gd.so"
}

# Decode with an explicit extension load order. "-n" keeps the host ini out of
# the comparison, so each mode loads exactly the extensions it names.
run_php_decode() {
    local sample=$1 mode=$2
    local imagick_so="$PREFIX/php/$PHP_VER/imagick.so"
    local gd_so
    [[ -f $imagick_so ]] || fail "missing $imagick_so"
    gd_so=$(gd_extension_path) || fail "gd.so not found for PHP $PHP_VER"

    local args=(-n -d display_errors=1)
    case $mode in
        imagick-only)
            args+=(-d "extension=$imagick_so")
            ;;
        gd-first)
            args+=(-d "extension=$gd_so" -d "extension=$imagick_so")
            ;;
        *)
            fail "unknown decode mode: $mode"
            ;;
    esac

    HEIC_PROBE_SAMPLE="$sample" php"$PHP_VER" "${args[@]}" -r '
        if (!extension_loaded("imagick")) { fwrite(STDERR, "imagick not loaded\n"); exit(1); }
        printf("gd=%s ", extension_loaded("gd") ? "yes" : "no");
        $im = new Imagick(getenv("HEIC_PROBE_SAMPLE"));
        $im->setImageFormat("jpeg");
        $blob = $im->getImageBlob();
        if (strlen($blob) < 100) { fwrite(STDERR, "decode produced no data\n"); exit(1); }
        printf("decode=ok %dx%d %d bytes\n", $im->getImageWidth(), $im->getImageHeight(), strlen($blob));
    '
}

assert_decode_with_gd() {
    local sample=${1:-$SAMPLE}
    [[ -f $sample ]] || fail "decode sample missing: $sample"
    printf 'GD-first decode (%s): %s\n' "$sample" "$(gd_extension_path 2>/dev/null || printf 'gd.so missing')" >&2
    run_php_decode "$sample" gd-first || fail "GD + Imagick decode failed: $sample"
}

# The exact production regression: the private CLI, Imagick alone and GD-first
# must all decode the checked-in iOS 18 sample. GD-first is where a system
# libheif captures ImageMagick's heif_* calls on affected PHP builds.
assert_decode_modes() {
    local sample=$COLLISION_SAMPLE
    local magick="$PREFIX/imagemagick/bin/magick"

    [[ -f $sample ]] || fail "collision sample missing: $sample"
    log "Collision sample: $sample"
    ( cd "$REPO" && sha256sum -c samples/SHA256SUMS ) >/dev/null \
        || fail "collision sample failed samples/SHA256SUMS"

    [[ -x $magick ]] || fail "private ImageMagick not built at $magick"
    "$magick" "${sample}[0]" null: || fail "private ImageMagick could not decode $sample"

    run_php_decode "$sample" imagick-only || fail "Imagick-only decode failed: $sample"
    run_php_decode "$sample" gd-first \
        || fail "GD-first decode failed: $sample (a system libheif captured ImageMagick's heif_* calls)"

    printf 'All decode modes succeeded for %s\n' "$sample" >&2
}

magickcore_real_path() {
    local lib
    for lib in "$PREFIX"/imagemagick/lib/libMagickCore-7*.so*; do
        [[ -f $lib && ! -L $lib ]] || continue
        printf '%s\n' "$lib"
        return 0
    done
    return 1
}

magickcore_needed() {
    readelf -d "$1" 2>/dev/null | sed -n 's/.*Shared library: \[\(.*\)\]/\1/p'
}

magickcore_defines_symbol() {
    readelf --wide --syms "$1" 2>/dev/null | awk -v s="$2" '
        $8 == s && $7 != "UND" { found = 1 }
        END { exit !found }
    '
}

magickcore_exported_heif() {
    readelf --wide --dyn-syms "$1" 2>/dev/null | awk '
        $4 == "FUNC" && ($5 == "GLOBAL" || $5 == "WEAK") \
            && $6 == "DEFAULT" && $7 != "UND" && $8 ~ /^heif_/ { print $8 }
    '
}

# Proves the private libheif is linked into MagickCore with local binding
# instead of being a shared dependency the dynamic linker can satisfy from the
# system copy GD already loaded.
assert_hidden_heif_binding() {
    local core mode needed leaked log gd_so sample sym
    core=$(magickcore_real_path) || fail "libMagickCore-7 not found under $PREFIX/imagemagick/lib"
    mode=$(awk -F= '$1 == "LIBDE265_MODE" { print $2; exit }' \
        "$PREFIX/installed-versions.txt" 2>/dev/null || true)

    needed=$(magickcore_needed "$core")
    printf 'MagickCore: %s\n' "$core" >&2
    if grep -Eq '^libheif' <<<"$needed"; then
        fail "libMagickCore still links a shared libheif: $(grep -E '^libheif' <<<"$needed" | paste -sd, -)"
    fi
    if [[ $mode != "system" ]] && grep -Eq '^libde265' <<<"$needed"; then
        fail "libMagickCore still links a shared libde265: $(grep -E '^libde265' <<<"$needed" | paste -sd, -)"
    fi

    for sym in heif_context_alloc heif_context_read_from_file heif_context_free; do
        magickcore_defines_symbol "$core" "$sym" \
            || fail "libMagickCore does not embed $sym; the private decoder is not statically linked"
    done

    leaked=$(magickcore_exported_heif "$core")
    [[ -z $leaked ]] \
        || fail "libMagickCore still exports heif_* symbols: $(paste -sd, - <<<"$leaked")"

    # Runtime proof: with GD loaded first, no MagickCore heif_* call may bind to
    # a shared libheif, and GD must keep binding to the system copy it ships with.
    sample=$COLLISION_SAMPLE
    [[ -f $sample ]] || fail "collision sample missing: $sample"
    gd_so=$(gd_extension_path) || fail "gd.so not found for PHP $PHP_VER"
    log=$(mktemp)
    LD_DEBUG=bindings HEIC_PROBE_SAMPLE="$sample" php"$PHP_VER" -n \
        -d "extension=$gd_so" -d "extension=$PREFIX/php/$PHP_VER/imagick.so" \
        -r '
            try {
                $im = new Imagick(getenv("HEIC_PROBE_SAMPLE"));
                $im->setImageFormat("jpeg");
                $im->getImageBlob();
            } catch (Throwable $e) {
                fwrite(STDERR, "probe decode failed: " . $e->getMessage() . "\n");
            }
        ' >/dev/null 2>"$log" || true

    leaked=$(grep -E 'binding file .*libMagickCore[^ ]* \[0\] to .*libheif[^ ]* \[0\]' "$log" | head -n 5 || true)

    if ldd "$gd_so" 2>/dev/null | grep -q 'libheif'; then
        if ! grep -qE 'binding file .*libgd\.so[^ ]* \[0\] to .*libheif[^ ]* \[0\]' "$log"; then
            rm -f "$log"
            fail "GD did not bind its own heif_* calls to the system libheif"
        fi
    fi
    rm -f "$log"

    [[ -z $leaked ]] \
        || fail "libMagickCore heif_* calls bound to a system libheif: $(head -n 1 <<<"$leaked")"

    printf 'Static-hidden libheif: no MagickCore-to-system heif_* binding with GD loaded first\n' >&2
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
    local php_rc=0
    "$REPO/heic-fix.sh" php --php "$PHP_VER" -y --sample "$SAMPLE" || php_rc=$?

    # The extension exists even when the pre-flight refuses to enable it, so the
    # collision probe always runs and reports the exact defect before any
    # enablement failure is reported.
    log "collision sample: CLI, Imagick-only, GD-first"
    assert_decode_modes
    assert_hidden_heif_binding
    (( php_rc == 0 )) || fail "heic-fix.sh php failed (exit $php_rc)"

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
    assert_decode_modes
    assert_hidden_heif_binding

    log "status"
    "$REPO/heic-fix.sh" status --php "$PHP_VER"

    printf '\n%sAll container tests passed (PHP %s)%s\n' "$(printf '\033[32m')" "$PHP_VER" "$(printf '\033[0m')"
}

main "$@"
