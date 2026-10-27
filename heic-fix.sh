#!/usr/bin/env bash
#
# heic-fix.sh - restore iOS 18+ HEIC support in PHP Imagick on Debian/Ubuntu.
#
# Builds libde265, libheif and ImageMagick 7 into an isolated prefix
# (/opt/heic-ios18-fix by default) and builds a matching Imagick extension for
# one or more PHP versions. System libraries are never replaced.
#
# The new libraries use private SONAMEs (libheif-heicfix.so.1,
# libde265-heicfix.so.0) so the dynamic linker cannot satisfy them from the
# system libheif that PHP's GD extension loads first.
#
# Copyright (c) 2026 Managing WP
# SPDX-License-Identifier: MIT
#
# shellcheck disable=SC2016 # PHP snippets are intentionally single-quoted.
# shellcheck disable=SC2030,SC2031 # build env is scoped to an intentional subshell.

set -Eeuo pipefail
umask 022

SCRIPT_NAME=$(basename -- "${BASH_SOURCE[0]}")
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SCRIPT_VERSION="1.0.0"

PREFIX="/opt/heic-ios18-fix"
SAMPLE=""
ASSUME_YES=0
QUIET=0
SYSTEM_LIBDE265=0
JOBS=""
PHP_TARGETS=()
COMMAND=""

PLATFORM="generic"

LIBDE265_VERSION="latest"
LIBHEIF_VERSION="v1.23.7"
IMAGEMAGICK_VERSION="latest"
IMAGICK_VERSION="latest"
MIN_LIBHEIF="1.18.2"

# Bump when the Imagick source build recipe changes, so existing builds refresh.
IMAGICK_BUILD_REV="1"

RESOLVED_LIBDE265=""
RESOLVED_LIBHEIF=""
RESOLVED_IMAGEMAGICK=""
RESOLVED_IMAGICK=""

HEIF=""
IMAGEMAGICK=""
SRC=""
LOGS=""
PHPROOT=""
VERSIONS_FILE=""
TMPDIR_PREFIX=""

PROBE_OUT=""
PROBE_ERR_FILE=""
CHECK_RESULT="error"

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------

if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
    C_RESET=$'\033[0m'
    C_BOLD=$'\033[1m'
    C_RED=$'\033[31m'
    C_GREEN=$'\033[32m'
    C_YELLOW=$'\033[33m'
    C_BLUE=$'\033[34m'
else
    C_RESET="" C_BOLD="" C_RED="" C_GREEN="" C_YELLOW="" C_BLUE=""
fi

log() {
    if (( QUIET )); then
        return 0
    fi
    printf '%s\n' "$*" >&2
}

log_step() {
    if (( QUIET )); then
        return 0
    fi
    printf '\n%s==> %s%s\n' "$C_BOLD$C_BLUE" "$*" "$C_RESET" >&2
}

warn() {
    printf '%sWARN:%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2
}

error() {
    printf '%sERROR:%s %s\n' "$C_RED" "$C_RESET" "$*" >&2
}

die() {
    error "$*"
    exit 2
}

ok() {
    printf '%sOK:%s %s\n' "$C_GREEN" "$C_RESET" "$*" >&2
}

usage() {
    cat <<'EOF'
heic-fix.sh - restore iOS 18+ HEIC decoding in PHP Imagick

USAGE
  heic-fix.sh <command> [options]

COMMANDS
  check                 Read-only health check for every PHP version.
  build                 Build libde265, libheif and ImageMagick 7 in the prefix.
  php                   Build the Imagick extension for PHP, then offer to enable it.
  enable                Enable the already-built Imagick for PHP and reload FPM.
  rollback              Disable our Imagick and restore the stock imagick module.
  verify                Verify CLI and FPM load ImageMagick 7 and decode a sample.
  status                Show what is installed and enabled.
  deps                  Show build dependency status.

OPTIONS
  --php X.Y             Target PHP version (repeatable). Default: all installed.
  --prefix DIR          Install prefix (default: /opt/heic-ios18-fix).
  --sample FILE         HEIC sample used to prove decoding works.
                        Default: <repo>/samples/failure_magick.HEIC.
  --system-libde265     Link libheif against the distro libde265 instead of
                        building libde265. Default: build a private copy.
  --jobs N, -j N        Parallel build jobs (default: nproc).
  -y, --yes             Do not prompt. Every action is still logged.
  -q, --quiet           Suppress progress output. check prints one line per
                        PHP version when quiet.
  -h, --help            Show this help.
      --version         Show the script version.

EXIT CODES (check)
  0  OK: HEIC decoding works (or is at least not known to be broken).
  1  AFFECTED: at least one PHP version cannot be relied on for iOS 18 HEIC.
  2  ERROR: the check itself failed (missing binary, broken extension, ...).
  3  NO IMAGICK: no checked PHP version has the Imagick extension.

VERSION OVERRIDES
  LIBDE265_VERSION, LIBHEIF_VERSION, IMAGEMAGICK_VERSION and IMAGICK_VERSION
  may be set in the environment. Use "latest" for the newest stable tag.
  versions.conf pins the defaults.
EOF
}

# ---------------------------------------------------------------------------
# Small utilities
# ---------------------------------------------------------------------------

have() {
    command -v "$1" >/dev/null 2>&1
}

version_ge() {
    # version_ge A B -> true when A >= B (sort -V semantics).
    local a=$1 b=$2
    [[ $(printf '%s\n%s\n' "$b" "$a" | sort -V | tail -n 1) == "$a" ]]
}

strip_v() {
    printf '%s' "${1#v}"
}

strip_deb_version() {
    # 1:1.17.6-1ubuntu4.2 -> 1.17.6
    local v=$1
    v=${v#*:}
    v=${v%%-*}
    printf '%s' "$v"
}

confirm() {
    local prompt=$1
    if (( ASSUME_YES )); then
        log "auto-confirm (-y): $prompt"
        return 0
    fi
    if [[ ! -t 0 ]]; then
        warn "no TTY and -y not given; refusing: $prompt"
        return 1
    fi
    local reply=""
    printf '%s [y/N] ' "$prompt" >&2
    read -r reply || return 1
    [[ $reply =~ ^[Yy]([Ee][Ss])?$ ]]
}

require_root() {
    if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
        die "this command must run as root (try: sudo $SCRIPT_NAME $COMMAND)"
    fi
}

require_command() {
    local c=$1
    have "$c" || die "required command not found: $c"
}

acquire_lock() {
    mkdir -p "$PREFIX"
    exec 9>"$PREFIX/.heic-fix.lock"
    if ! flock -n 9; then
        die "another heic-fix.sh run is using $PREFIX"
    fi
}

apt_run() {
    # needrestart once restarted nginx, MariaDB and every php-fpm pool during a
    # plain "apt install". Keep it from doing that again.
    NEEDRESTART_MODE=l \
    NEEDRESTART_SUSPEND=1 \
    DEBIAN_FRONTEND=noninteractive \
        apt-get -y \
            -o Dpkg::Options::=--force-confdef \
            -o Dpkg::Options::=--force-confold \
            "$@"
}

nice_run() {
    if have ionice; then
        nice -n 19 ionice -c 3 "$@"
    else
        nice -n 19 "$@"
    fi
}

run_logged() {
    local logfile=$1
    shift
    mkdir -p "$(dirname -- "$logfile")"
    {
        printf '\n== %s\n== %s\n' "$(date -Is 2>/dev/null || date)" "$*"
    } >>"$logfile"
    if (( QUIET )); then
        "$@" >>"$logfile" 2>&1
    else
        "$@" 2>&1 | tee -a "$logfile"
    fi
}

on_error() {
    local rc=$?
    local line=${1:-?}
    printf '%sERROR:%s command failed with status %s at %s line %s\n' \
        "$C_RED" "$C_RESET" "$rc" "$SCRIPT_NAME" "$line" >&2
    exit "$rc"
}
trap 'on_error $LINENO' ERR

# ---------------------------------------------------------------------------
# Platform hooks
# ---------------------------------------------------------------------------

detect_platform() {
    if [[ -d /RunCloud ]]; then
        PLATFORM="runcloud"
    elif [[ -x /usr/local/bin/gp || -d /opt/gridpane ]]; then
        PLATFORM="gridpane"
    else
        PLATFORM="generic"
    fi
}

# Print installed PHP X.Y versions, newest last.
php_versions() {
    case $PLATFORM in
        runcloud)
            # TODO(runcloud): RunCloud keeps PHP under /RunCloud/Packages/phpXXrc
            # with config in /etc/phpXXrc. Those paths are unverified, so only
            # report the standard layout if it happens to exist.
            if [[ -d /etc/php ]]; then
                php_versions_generic
            fi
            ;;
        *)
            php_versions_generic
            ;;
    esac
}

php_versions_generic() {
    local d name
    [[ -d /etc/php ]] || return 0
    for d in /etc/php/*; do
        [[ -d $d ]] || continue
        name=$(basename -- "$d")
        [[ $name =~ ^[0-9]+\.[0-9]+$ ]] || continue
        printf '%s\n' "$name"
    done | sort -V
}

# php_bin X.Y -> path to the CLI binary.
php_bin() {
    local ver=$1
    case $PLATFORM in
        runcloud)
            # TODO(runcloud): expected at /RunCloud/Packages/phpXXrc/bin/php.
            not_implemented_runcloud "php_bin"
            return 1
            ;;
        *)
            if have "php$ver"; then
                command -v "php$ver"
            elif [[ -x "/usr/bin/php$ver" ]]; then
                printf '/usr/bin/php%s\n' "$ver"
            else
                return 1
            fi
            ;;
    esac
}

php_config_bin() {
    local ver=$1
    if have "php-config$ver"; then
        command -v "php-config$ver"
    elif [[ -x "/usr/bin/php-config$ver" ]]; then
        printf '/usr/bin/php-config%s\n' "$ver"
    else
        return 1
    fi
}

phpize_bin() {
    local ver=$1
    if have "phpize$ver"; then
        command -v "phpize$ver"
    elif [[ -x "/usr/bin/phpize$ver" ]]; then
        printf '/usr/bin/phpize%s\n' "$ver"
    else
        return 1
    fi
}

fpm_bin() {
    local ver=$1
    case $PLATFORM in
        runcloud)
            # TODO(runcloud): FPM binary is expected under /RunCloud/Packages/phpXXrc.
            not_implemented_runcloud "fpm_bin"
            return 1
            ;;
    esac
    if have "php-fpm$ver"; then
        command -v "php-fpm$ver"
    elif [[ -x "/usr/sbin/php-fpm$ver" ]]; then
        printf '/usr/sbin/php-fpm%s\n' "$ver"
    else
        return 1
    fi
}

not_implemented_runcloud() {
    error "RunCloud PHP integration is not implemented yet ($1)."
    error "TODO(runcloud): verify PHP binary, conf.d and FPM unit names for this RunCloud image."
    error "The build commands work on RunCloud; only PHP wiring is unavailable."
}

enable_ext() {
    local ver=$1
    case $PLATFORM in
        runcloud)
            # TODO(runcloud): /etc/phpXXrc/conf.d and phpenmod equivalents.
            not_implemented_runcloud "enable_ext"
            return 1
            ;;
    esac

    local so="$PHPROOT/$ver/imagick.so"
    local mods="/etc/php/$ver/mods-available"
    local ini="$mods/imagick-heic.ini"
    [[ -f $so ]] || die "missing $so (run: $SCRIPT_NAME php --php $ver)"
    [[ -d $mods ]] || die "missing $mods; is php$ver installed?"

    printf '; iOS 18 HEIC fix: ImageMagick 7 + libheif-heicfix\n; priority=20\nextension=%s\n' "$so" >"$ini"
    chmod 0644 "$ini"

    if [[ -e "$mods/imagick.ini" ]]; then
        log "disabling stock imagick for PHP $ver"
        phpdismod -v "$ver" imagick >/dev/null 2>&1 || warn "phpdismod -v $ver imagick failed"
    fi
    log "enabling imagick-heic for PHP $ver"
    phpenmod -v "$ver" imagick-heic || die "phpenmod -v $ver imagick-heic failed"
}

disable_ext() {
    local ver=$1
    case $PLATFORM in
        runcloud)
            # TODO(runcloud): /etc/phpXXrc/conf.d and phpdismod equivalents.
            not_implemented_runcloud "disable_ext"
            return 1
            ;;
    esac

    local mods="/etc/php/$ver/mods-available"
    phpdismod -v "$ver" imagick-heic >/dev/null 2>&1 || true
    if [[ -e "$mods/imagick.ini" ]]; then
        phpenmod -v "$ver" imagick || warn "could not re-enable stock imagick for PHP $ver"
    else
        warn "stock imagick.ini is not present for PHP $ver; no stock module to restore"
    fi
}

reload_fpm() {
    local ver=$1
    local svc="php$ver-fpm"

    case $PLATFORM in
        runcloud)
            # TODO(runcloud): FPM unit is expected to be phpXXrc-fpm.
            not_implemented_runcloud "reload_fpm"
            return 1
            ;;
    esac

    if [[ -d /run/systemd/system ]] && systemctl list-unit-files "$svc.service" >/dev/null 2>&1; then
        if systemctl is-active --quiet "$svc"; then
            log "reloading $svc"
            systemctl reload "$svc" || warn "systemctl reload $svc failed"
        else
            warn "$svc is not running; config is in place and will load when it starts"
        fi
        return 0
    fi

    if [[ -x "/etc/init.d/$svc" ]]; then
        if "/etc/init.d/$svc" status >/dev/null 2>&1; then
            log "reloading $svc (sysvinit)"
            "/etc/init.d/$svc" reload || warn "could not reload $svc"
        else
            warn "$svc is not running; reload it after starting"
        fi
        return 0
    fi

    warn "could not find a running $svc service; reload FPM yourself"
    return 0
}

site_map() {
    local conf ver pool listen
    local found=0
    [[ -d /etc/php ]] || return 0
    for conf in /etc/php/*/fpm/pool.d/*.conf; do
        [[ -f $conf ]] || continue
        ver=$(basename -- "$(dirname -- "$(dirname -- "$(dirname -- "$conf")")")")
        pool=$(basename -- "$conf" .conf)
        listen=$(awk -F= '
            /^[[:space:]]*\[/ { next }
            /^[[:space:]]*listen[[:space:]]*=/ {
                sub(/^[[:space:]]*listen[[:space:]]*=[[:space:]]*/, "");
                print; exit
            }' "$conf" 2>/dev/null || true)
        printf '%s\t%s\t%s\n' "$pool" "$ver" "${listen:-?}"
        found=1
    done
    (( found )) || printf '%s\t%s\t%s\n' "(none found)" "-" "-"
}

platform_notes() {
    case $PLATFORM in
        gridpane)
            warn "GridPane detected. GridPane stack/security updates may reinstall packages or re-enable stock imagick."
            warn "Re-run 'check' and 'verify' after every GridPane update."
            ;;
        runcloud)
            warn "RunCloud detected. Building works, but PHP/FPM wiring is TODO(runcloud) and not implemented."
            ;;
    esac
}

# ---------------------------------------------------------------------------
# Versions and paths
# ---------------------------------------------------------------------------

load_versions() {
    local env_libde265="" env_libheif="" env_imagemagick="" env_imagick="" env_min_libheif=""
    env_libde265=$(printenv LIBDE265_VERSION || true)
    env_libheif=$(printenv LIBHEIF_VERSION || true)
    env_imagemagick=$(printenv IMAGEMAGICK_VERSION || true)
    env_imagick=$(printenv IMAGICK_VERSION || true)
    env_min_libheif=$(printenv MIN_LIBHEIF || true)

    if [[ -f "$SCRIPT_DIR/versions.conf" ]]; then
        # shellcheck source=versions.conf disable=SC1091
        source "$SCRIPT_DIR/versions.conf"
    fi

    [[ -n $env_libde265 ]] && LIBDE265_VERSION=$env_libde265
    [[ -n $env_libheif ]] && LIBHEIF_VERSION=$env_libheif
    [[ -n $env_imagemagick ]] && IMAGEMAGICK_VERSION=$env_imagemagick
    [[ -n $env_imagick ]] && IMAGICK_VERSION=$env_imagick
    [[ -n $env_min_libheif ]] && MIN_LIBHEIF=$env_min_libheif

    : "${LIBDE265_VERSION:=latest}"
    : "${LIBHEIF_VERSION:=latest}"
    : "${IMAGEMAGICK_VERSION:=latest}"
    : "${IMAGICK_VERSION:=latest}"
    : "${MIN_LIBHEIF:=1.18.2}"
}

init_paths() {
    HEIF="$PREFIX/heif"
    IMAGEMAGICK="$PREFIX/imagemagick"
    SRC="$PREFIX/src"
    LOGS="$PREFIX/logs"
    PHPROOT="$PREFIX/php"
    VERSIONS_FILE="$PREFIX/installed-versions.txt"
    TMPDIR_PREFIX="$PREFIX/tmp"
}

get_recorded() {
    local key=$1
    [[ -f $VERSIONS_FILE ]] || return 0
    awk -F= -v k="$key" '$1 == k { print $2; exit }' "$VERSIONS_FILE"
}

record_version() {
    local key=$1 value=$2
    mkdir -p "$PREFIX"
    touch "$VERSIONS_FILE"
    local tmp="$VERSIONS_FILE.tmp.$$"
    awk -F= -v k="$key" '$1 != k' "$VERSIONS_FILE" >"$tmp" || true
    printf '%s=%s\n' "$key" "$value" >>"$tmp"
    sort -o "$tmp" "$tmp"
    mv "$tmp" "$VERSIONS_FILE"
    chmod 0644 "$VERSIONS_FILE"
}

resolve_latest() {
    local component=$1 configured=$2 repo=$3 regex=$4
    if [[ $configured != "latest" ]]; then
        printf '%s\n' "$configured"
        return 0
    fi

    local tags tag best=""
    if ! have git; then
        die "git is required to resolve the latest $component version; run '$SCRIPT_NAME deps' or pin it"
    fi
    if ! tags=$(GIT_TERMINAL_PROMPT=0 timeout 60 git ls-remote --tags --refs "$repo" 2>/dev/null); then
        die "could not query $repo for the latest tag; pin $component in versions.conf or the environment"
    fi
    while IFS= read -r tag; do
        tag=${tag##*refs/tags/}
        [[ $tag =~ $regex ]] || continue
        if [[ -z $best ]] || [[ $(printf '%s\n%s\n' "$best" "$tag" | sort -V | tail -n 1) == "$tag" ]]; then
            best=$tag
        fi
    done <<<"$tags"
    [[ -n $best ]] || die "no stable $component tag matched $regex at $repo"
    printf '%s\n' "$best"
}

resolve_imagick_version() {
    RESOLVED_IMAGICK=$(resolve_latest imagick "$IMAGICK_VERSION" \
        "https://github.com/Imagick/imagick.git" \
        '^[0-9]+\.[0-9]+\.[0-9]+$')
}

resolve_versions() {
    if (( SYSTEM_LIBDE265 )); then
        RESOLVED_LIBDE265="system"
    else
        RESOLVED_LIBDE265=$(resolve_latest libde265 "$LIBDE265_VERSION" \
            "https://github.com/strukturag/libde265.git" \
            '^v1\.[0-9]+(\.[0-9]+)?$')
    fi
    RESOLVED_LIBHEIF=$(resolve_latest libheif "$LIBHEIF_VERSION" \
        "https://github.com/strukturag/libheif.git" \
        '^v1\.[0-9]+\.[0-9]+$')
    RESOLVED_IMAGEMAGICK=$(resolve_latest ImageMagick "$IMAGEMAGICK_VERSION" \
        "https://github.com/ImageMagick/ImageMagick.git" \
        '^7\.[0-9]+\.[0-9]+-[0-9]+$')
    RESOLVED_IMAGICK=$(resolve_latest imagick "$IMAGICK_VERSION" \
        "https://github.com/Imagick/imagick.git" \
        '^[0-9]+\.[0-9]+\.[0-9]+$')

    # Accept "1.23.7" as well as "v1.23.7".
    [[ $RESOLVED_LIBHEIF == v* ]] || RESOLVED_LIBHEIF="v$RESOLVED_LIBHEIF"

    # The SONAME isolation below is written for the v1 ABI and ImageMagick 7.
    if [[ ! $RESOLVED_LIBHEIF =~ ^v1\. ]]; then
        die "libheif $RESOLVED_LIBHEIF is not a v1 release; this script only isolates the v1 SONAME"
    fi
    if (( ! SYSTEM_LIBDE265 )); then
        [[ $RESOLVED_LIBDE265 == v* ]] || RESOLVED_LIBDE265="v$RESOLVED_LIBDE265"
        if [[ ! $RESOLVED_LIBDE265 =~ ^v1\. ]]; then
            die "libde265 $RESOLVED_LIBDE265 is not a v1 release; this script only isolates the v1 SONAME"
        fi
    fi

    if ! version_ge "$(strip_v "$RESOLVED_LIBHEIF")" "$MIN_LIBHEIF"; then
        die "libheif $(strip_v "$RESOLVED_LIBHEIF") is below MIN_LIBHEIF=$MIN_LIBHEIF; iOS 18 files need >= $MIN_LIBHEIF"
    fi
}

requested_targets() {
    if (( ${#PHP_TARGETS[@]} )); then
        printf '%s\n' "${PHP_TARGETS[@]}"
    else
        php_versions
    fi
}

validate_php_targets() {
    local v
    for v in "${PHP_TARGETS[@]}"; do
        if [[ ! $v =~ ^[0-9]+\.[0-9]+$ ]]; then
            die "invalid --php version '$v' (expected X.Y)"
        fi
    done
}

# ---------------------------------------------------------------------------
# Sample handling
# ---------------------------------------------------------------------------

default_sample() {
    printf '%s/samples/failure_magick.HEIC\n' "$SCRIPT_DIR"
}

sample_state() {
    local sample=$1
    if [[ ! -f $sample ]]; then
        printf 'missing\n'
        return 0
    fi

    local sums="$SCRIPT_DIR/samples/SHA256SUMS"
    if [[ ! -f $sums ]]; then
        printf 'unverified\n'
        return 0
    fi

    local base expected actual
    base=$(basename -- "$sample")
    expected=$(awk -v b="$base" '
        /^[[:space:]]*#/ { next }
        NF >= 2 {
            f = $2
            sub(/^\*/, "", f)
            sub(/^.*\//, "", f)
            if (f == b) { print $1; exit }
        }' "$sums")

    if [[ -z $expected || $expected == "PLACEHOLDER" || $expected == "-" ]]; then
        printf 'unverified\n'
        return 0
    fi

    actual=$(sha256sum "$sample" | awk '{print $1}')
    if [[ $actual == "$expected" ]]; then
        printf 'verified\n'
    else
        printf 'altered\n'
    fi
}

sample_for_decode() {
    local sample=$1 state=$2
    [[ -f $sample ]] || return 1
    [[ $state == "altered" ]] && return 1
    return 0
}

# ---------------------------------------------------------------------------
# PHP probing
# ---------------------------------------------------------------------------

# Runs a PHP probe. Sets PROBE_OUT, PROBE_ERR_FILE and PROBE_RC.
probe_php() {
    local php=$1 sample=${2:-}
    PROBE_ERR_FILE=$(mktemp)
    if PROBE_OUT=$(HEIC_PROBE_SAMPLE="$sample" "$php" -d display_errors=1 -r '
$kv = function (string $k, string $v): void {
    echo $k, "=", str_replace(["\r", "\n"], " ", $v), "\n";
};
$ext_file = function (string $name): string {
    $maps = @file_get_contents("/proc/self/maps");
    if ($maps !== false) {
        foreach (explode("\n", $maps) as $line) {
            if (strpos($line, "/" . $name . ".so") === false) {
                continue;
            }
            $parts = preg_split("/\s+/", trim($line));
            $path = end($parts);
            if (is_string($path) && is_file($path)) {
                return $path;
            }
        }
    }
    $fallback = ini_get("extension_dir") . "/" . $name . ".so";
    return is_file($fallback) ? $fallback : "";
};
$kv("php_version", PHP_VERSION);
$kv("gd", extension_loaded("gd") ? "yes" : "no");
$kv("imagick", extension_loaded("imagick") ? "yes" : "no");
if (extension_loaded("imagick")) {
    $kv("ext_file", $ext_file("imagick"));
    $kv("imagick_version", (string)phpversion("imagick"));
    $v = Imagick::getVersion();
    $kv("imagemagick", (string)($v["versionString"] ?? ""));
    $formats = Imagick::queryFormats("HEI*");
    $kv("heic_formats", $formats ? implode(",", $formats) : "-");
    $s = getenv("HEIC_PROBE_SAMPLE");
    if ($s !== false && $s !== "" && is_file($s)) {
        try {
            $img = new Imagick($s);
            $img->setImageFormat("jpeg");
            $blob = $img->getImageBlob();
            $kv("decode", "ok");
            $kv("dimensions", $img->getImageWidth() . "x" . $img->getImageHeight());
            $kv("jpeg_bytes", (string)strlen($blob));
        } catch (Throwable $e) {
            $kv("decode", "fail");
            $kv("decode_error", $e->getMessage());
        }
    }
}
' 2>"$PROBE_ERR_FILE"); then
        PROBE_RC=0
    else
        PROBE_RC=$?
    fi
}

probe_value() {
    local key=$1
    awk -F= -v k="$key" '
        $1 == k { sub(/^[^=]*=/, ""); print; exit }
    ' <<<"$PROBE_OUT"
}

cleanup_probe() {
    [[ -n ${PROBE_ERR_FILE:-} && -f ${PROBE_ERR_FILE:-} ]] && rm -f "$PROBE_ERR_FILE"
    PROBE_ERR_FILE=""
}

imagemagick_major() {
    local v=$1
    sed -n 's/^ImageMagick \([0-9][0-9]*\).*/\1/p' <<<"$v" | head -n 1
}

libheif_path_for_ext() {
    local ext=$1 im_major=$2
    local lib
    lib=$(ldd "$ext" 2>/dev/null | awk '/libheif/ && $3 != "not" { print $3; exit }')
    if [[ -n $lib ]]; then
        printf '%s\n' "$lib"
        return 0
    fi

    if [[ $im_major == "6" ]]; then
        local mods="" core="" coder=""
        if have convert; then
            mods=$(convert -list configure 2>/dev/null | awk '$1 == "MODULES_PATH" { print $2; exit }')
        fi
        if [[ -z $mods ]]; then
            core=$(ldd "$ext" 2>/dev/null | awk '/libMagickCore/ { print $3; exit }')
            if [[ -n $core ]]; then
                core=$(readlink -f "$core" 2>/dev/null || printf '%s' "$core")
                mods=$(dirname -- "$core")
            fi
        fi
        if [[ -n $mods ]]; then
            # Debian/Ubuntu: <libdir>/ImageMagick-6*/modules-Q16/coders/heic.so
            coder=$(find "$mods" -maxdepth 5 -type f -path '*/coders/heic.so' 2>/dev/null | head -n 1)
            if [[ -z $coder && -f "$mods/coders/heic.so" ]]; then
                coder="$mods/coders/heic.so"
            fi
            if [[ -n $coder ]]; then
                lib=$(ldd "$coder" 2>/dev/null | awk '/libheif/ && $3 != "not" { print $3; exit }')
                if [[ -n $lib ]]; then
                    printf '%s\n' "$lib"
                    return 0
                fi
            fi
        fi
    fi
    return 1
}

libheif_version_for_path() {
    local lib=$1
    [[ -n $lib ]] || return 1
    local real
    real=$(readlink -f "$lib" 2>/dev/null || printf '%s' "$lib")

    if [[ $real == "$PREFIX"/* ]]; then
        local recorded
        recorded=$(get_recorded "LIBHEIF_VERSION")
        [[ -n $recorded ]] || return 1
        printf '%s\n' "${recorded#v}"
        return 0
    fi

    local pkg=""
    if have dpkg-query; then
        pkg=$(dpkg-query -S "$real" 2>/dev/null | head -n 1 | cut -d: -f1 || true)
        if [[ -n $pkg ]]; then
            local debver
            debver=$(dpkg-query -W -f='${Version}' "$pkg" 2>/dev/null || true)
            if [[ -n $debver ]]; then
                strip_deb_version "$debver"
                return 0
            fi
        fi
    fi

    if have heif-info; then
        local hv
        hv=$(heif-info --version 2>/dev/null | sed -n 's/.*libheif version: *\([0-9][0-9.]*\).*/\1/p' | head -n 1)
        [[ -n $hv ]] && printf '%s\n' "$hv" && return 0
    fi
    return 1
}

# ---------------------------------------------------------------------------
# check
# ---------------------------------------------------------------------------

check_php_version() {
    local ver=$1 sample=$2 sample_state=$3
    local php
    if ! php=$(php_bin "$ver"); then
        printf 'php%s: no PHP CLI binary found\n' "$ver" >&2
        CHECK_RESULT="error"
        return 2
    fi

    probe_php "$php" ""
    local probe_rc=$PROBE_RC probe_err ext imagick_ver imver im_major formats
    probe_err=$(cat "$PROBE_ERR_FILE" 2>/dev/null || true)
    cleanup_probe

    if (( probe_rc != 0 )); then
        printf 'php%s: probe failed: %s\n' "$ver" "${probe_err:-exit $probe_rc}" >&2
        CHECK_RESULT="error"
        return 2
    fi

    if [[ $(probe_value imagick) != "yes" ]]; then
        if (( QUIET )); then
            printf 'php%s status=no-imagick imagick=none imagemagick=? heic=no libheif=unknown decode=skipped\n' "$ver"
        else
            printf '  %sPHP %s%s\n' "$C_BOLD" "$ver" "$C_RESET"
            printf '    imagick      : not installed\n'
        fi
        CHECK_RESULT="no-imagick"
        return 3
    fi

    ext=$(probe_value ext_file)
    imagick_ver=$(probe_value imagick_version)
    imver=$(probe_value imagemagick)
    im_major=$(imagemagick_major "$imver")
    formats=$(probe_value heic_formats)

    local libheif="" libheif_ver="unknown"
    if libheif=$(libheif_path_for_ext "$ext" "${im_major:-?}"); then
        if libheif_ver=$(libheif_version_for_path "$libheif"); then
            :
        else
            libheif_ver="unknown"
        fi
    fi

    local decode="skipped" decode_err="" dims=""
    if sample_for_decode "$sample" "$sample_state"; then
        probe_php "$php" "$sample"
        if (( PROBE_RC == 0 )); then
            decode=$(probe_value decode)
            [[ -z $decode ]] && decode="unknown"
            decode_err=$(probe_value decode_error)
            dims=$(probe_value dimensions)
        else
            decode="fail"
            decode_err="probe failed"
        fi
        cleanup_probe
    elif [[ $sample_state == "altered" ]]; then
        decode="altered"
    elif [[ $sample_state == "missing" ]]; then
        decode="missing"
    fi

    local status="ok" reasons=()
    if [[ -z $formats || $formats == "-" ]]; then
        status="affected"
        reasons+=("ImageMagick build has no HEIC coder")
    fi
    if [[ -n $libheif_ver && $libheif_ver != "unknown" ]] && ! version_ge "$libheif_ver" "$MIN_LIBHEIF"; then
        status="affected"
        reasons+=("libheif $libheif_ver < $MIN_LIBHEIF (iOS 18 tmap unsupported)")
    fi
    if [[ -z $libheif ]]; then
        # If we cannot identify libheif, only a verified real-sample decode is
        # strong enough evidence that it is new enough.
        if [[ $decode != "ok" || $sample_state != "verified" ]]; then
            status="affected"
            reasons+=("could not identify the libheif used by Imagick")
        fi
    fi
    if [[ $decode == "fail" ]]; then
        status="affected"
        reasons+=("sample decode failed")
    fi

    local heic_yes="no"
    [[ -n $formats && $formats != "-" ]] && heic_yes="yes"

    CHECK_RESULT=$status

    if (( QUIET )); then
        printf 'php%s status=%s imagick=%s imagemagick=%s heic=%s libheif=%s decode=%s\n' \
            "$ver" "$status" "${imagick_ver:-unknown}" "${im_major:-?}" "$heic_yes" "$libheif_ver" "$decode"
        return 0
    fi

    printf '  %sPHP %s%s (%s)\n' "$C_BOLD" "$ver" "$C_RESET" "$php"
    printf '    imagick      : %s (loaded %s)\n' "${imagick_ver:-unknown}" "${ext:-?}"
    printf '    ImageMagick  : %s\n' "${imver:-unknown}"
    printf '    HEIC formats : %s\n' "${formats:-none}"
    printf '    libheif      : %s' "${libheif:-not found}"
    if [[ -n $libheif ]]; then
        printf ' [%s]' "$libheif_ver"
    fi
    printf '\n'
    printf '    sample       : %s' "$sample_state"
    case $decode in
        ok) printf ' decode=ok (%s)' "$dims" ;;
        fail) printf ' decode=FAIL%s' "${decode_err:+: $decode_err}" ;;
        altered) printf ' decode ignored (checksum mismatch)' ;;
        missing) printf ' decode not run (sample missing)' ;;
        *) printf ' decode=%s' "$decode" ;;
    esac
    printf '\n'
    if [[ $status == "ok" ]]; then
        printf '    status       : %sOK%s\n' "$C_GREEN" "$C_RESET"
    else
        printf '    status       : %sAFFECTED%s' "$C_RED" "$C_RESET"
        if (( ${#reasons[@]} )); then
            printf ' - %s' "$(IFS='; '; printf '%s' "${reasons[*]}")"
        fi
        printf '\n'
    fi
    return 0
}

cmd_check() {
    if [[ $PLATFORM == "runcloud" ]]; then
        not_implemented_runcloud "check"
        return 2
    fi
    if (( ! QUIET )); then
        printf '%sheic-fix.sh check%s\n' "$C_BOLD" "$C_RESET"
        printf 'platform: %s\n' "$PLATFORM"
        printf 'prefix:   %s\n' "$PREFIX"
    fi

    local sample=$SAMPLE
    local state
    state=$(sample_state "$sample")
    if [[ $state == "altered" ]]; then
        warn "sample checksum does not match samples/SHA256SUMS; decode results are ignored"
    fi
    if (( ! QUIET )); then
        if [[ -f $sample ]]; then
            printf 'sample:   %s (%s)\n' "$sample" "$state"
        else
            printf 'sample:   %s (missing)\n' "$sample"
        fi
    fi

    local targets=()
    mapfile -t targets < <(requested_targets)
    if (( ${#targets[@]} == 0 )); then
        error "no PHP versions found under /etc/php"
        return 3
    fi

    local ver rc
    local any_affected=0 any_error=0 any_imagick=0
    for ver in "${targets[@]}"; do
        rc=0
        CHECK_RESULT="error"
        check_php_version "$ver" "$sample" "$state" || rc=$?
        case $CHECK_RESULT in
            ok|affected) any_imagick=1 ;;
            no-imagick) : ;;
            *) any_error=1 ;;
        esac
        case $CHECK_RESULT in
            affected) any_affected=1 ;;
            error) any_error=1 ;;
        esac
        if (( rc == 2 )); then
            any_error=1
        fi
    done

    if (( ! QUIET )) && [[ $PLATFORM != "runcloud" ]]; then
        printf '\n%sSite to PHP map (best effort)%s\n' "$C_BOLD" "$C_RESET"
        printf '  %-40s %-6s %s\n' "POOL / SITE" "PHP" "LISTEN"
        local pool pver listen
        while IFS=$'\t' read -r pool pver listen; do
            printf '  %-40s %-6s %s\n' "$pool" "$pver" "$listen"
        done < <(site_map)
        printf '\n'
    fi

    if (( any_error )); then
        (( QUIET )) || printf '%sResult: ERROR%s\n' "$C_RED" "$C_RESET"
        return 2
    fi
    if (( any_affected )); then
        (( QUIET )) || printf '%sResult: AFFECTED%s (run build, then: %s php --php X.Y)\n' "$C_RED" "$C_RESET" "$SCRIPT_NAME"
        return 1
    fi
    if (( ! any_imagick )); then
        (( QUIET )) || printf '%sResult: NO IMAGICK%s\n' "$C_YELLOW" "$C_RESET"
        return 3
    fi
    (( QUIET )) || printf '%sResult: OK%s\n' "$C_GREEN" "$C_RESET"
    return 0
}

# ---------------------------------------------------------------------------
# Dependencies
# ---------------------------------------------------------------------------

BUILD_PACKAGES=(
    build-essential cmake pkg-config git ca-certificates curl autoconf automake libtool
    libjpeg-dev libpng-dev libwebp-dev libtiff-dev liblcms2-dev libxml2-dev
    libfreetype6-dev libfontconfig1-dev zlib1g-dev libbz2-dev liblzma-dev
    libzstd-dev libaom-dev patchelf xz-utils
)

missing_packages() {
    local p
    for p in "${BUILD_PACKAGES[@]}"; do
        if ! pkg_installed "$p"; then
            printf '%s\n' "$p"
        fi
    done
}

# Debian/Ubuntu renamed some -dev packages over time. Treat the alternatives as
# equivalent so a satisfied dependency is not reinstalled on every run.
pkg_installed() {
    local p=$1 candidates=()
    case $p in
        libfreetype6-dev) candidates=(libfreetype6-dev libfreetype-dev) ;;
        libjpeg-dev) candidates=(libjpeg-dev libjpeg-turbo8-dev libjpeg62-turbo-dev) ;;
        libtiff-dev) candidates=(libtiff-dev libtiff5-dev) ;;
        *) candidates=("$p") ;;
    esac
    local c
    for c in "${candidates[@]}"; do
        if dpkg-query -W -f='${db:Status-Status}' "$c" 2>/dev/null | grep -qx 'installed'; then
            return 0
        fi
    done
    return 1
}

ensure_deps() {
    local missing=()
    mapfile -t missing < <(missing_packages)
    if (( ${#missing[@]} == 0 )); then
        log "build dependencies already installed"
        return 0
    fi

    warn "missing build packages: ${missing[*]}"
    if ! confirm "Install ${#missing[@]} build packages with apt-get?"; then
        die "build dependencies are required"
    fi
    require_command apt-get
    log "refreshing apt lists"
    apt_run update
    log "installing build dependencies"
    apt_run install "${missing[@]}"
}

cmd_deps() {
    local missing_any=0 p
    printf '%sBuild dependencies%s\n' "$C_BOLD" "$C_RESET"
    for p in "${BUILD_PACKAGES[@]}"; do
        if pkg_installed "$p"; then
            printf '  %-24s %sinstalled%s\n' "$p" "$C_GREEN" "$C_RESET"
        else
            printf '  %-24s %smissing%s\n' "$p" "$C_RED" "$C_RESET"
            missing_any=1
        fi
    done

    printf '\n%sTools%s\n' "$C_BOLD" "$C_RESET"
    local t
    for t in git cmake make gcc patchelf pkg-config phpize php-config; do
        if have "$t"; then
            printf '  %-24s %s%s%s\n' "$t" "$C_GREEN" "$(command -v "$t")" "$C_RESET"
        else
            printf '  %-24s %smissing%s\n' "$t" "$C_RED" "$C_RESET"
            [[ $t == phpize || $t == php-config ]] || missing_any=1
        fi
    done

    if (( missing_any )); then
        printf '\nInstall everything with:\n  apt-get install -y %s\n' "${BUILD_PACKAGES[*]}"
        return 1
    fi
    printf '\nAll build dependencies are installed.\n'
    return 0
}

# ---------------------------------------------------------------------------
# Build: libde265
# ---------------------------------------------------------------------------

download() {
    local url=$1 dest=$2
    if [[ -s $dest ]]; then
        return 0
    fi
    mkdir -p "$(dirname -- "$dest")"
    log "downloading $url"
    if ! curl -fL --retry 3 --connect-timeout 20 -o "$dest.part" "$url"; then
        rm -f "$dest.part"
        die "download failed: $url"
    fi
    mv "$dest.part" "$dest"
}

extract_source() {
    local archive=$1 expected_dir=$2
    if [[ -f "$expected_dir/.heic-fix-extracted" ]]; then
        return 0
    fi
    log "extracting $(basename -- "$archive")"
    rm -rf "$expected_dir"
    tar -xf "$archive" -C "$SRC"
    [[ -d $expected_dir ]] || die "expected source directory missing after extract: $expected_dir"
    touch "$expected_dir/.heic-fix-extracted"
}

build_libde265() {
    local ver=$1
    local plain
    plain=$(strip_v "$ver")

    if (( SYSTEM_LIBDE265 )); then
        log "using the system libde265 (--system-libde265)"
        if ! dpkg-query -W -f='${db:Status-Status}' libde265-dev 2>/dev/null | grep -qx installed; then
            if confirm "Install libde265-dev from apt?"; then
                apt_run install libde265-dev
            else
                die "libde265-dev is required with --system-libde265"
            fi
        fi
        # A previous run may have built a private copy. Remove it so libheif's
        # pkg-config lookup cannot silently pick the prefix copy again.
        if [[ -e "$HEIF/lib/libde265-heicfix.so.0" || -f "$HEIF/lib/pkgconfig/libde265.pc" ]]; then
            warn "removing the private libde265 from $HEIF to use the system copy"
            rm -f "$HEIF/lib/libde265-heicfix.so.0" "$HEIF/lib/libde265.so" \
                "$HEIF/lib/libde265.so.0" "$HEIF/lib"/libde265.so.* \
                "$HEIF/lib/pkgconfig/libde265.pc"
            rm -rf "$HEIF/include/libde265"
            rm -f "$HEIF/bin/dec265" "$HEIF/bin/enc265"
        fi
        record_version "LIBDE265_MODE" "system"
        record_version "LIBDE265_VERSION" "system"
        return 0
    fi

    if [[ $(get_recorded "LIBDE265_MODE") == "built" && $(get_recorded "LIBDE265_VERSION") == "$ver" ]] \
        && compgen -G "$HEIF/lib/libde265.so.*" >/dev/null; then
        log "libde265 $ver already built; skipping"
        ensure_libde265_soname
        return 0
    fi

    local archive="$SRC/libde265-$plain.tar.gz"
    local srcdir="$SRC/libde265-$plain"
    local builddir="$SRC/build-libde265-$plain"
    local logfile="$LOGS/libde265-$ver.log"

    download "https://github.com/strukturag/libde265/releases/download/$ver/libde265-$plain.tar.gz" "$archive"
    extract_source "$archive" "$srcdir"
    rm -rf "$builddir"

    log_step "Building libde265 $ver"
    mkdir -p "$builddir" "$HEIF"
    pushd "$builddir" >/dev/null
    run_logged "$logfile" nice_run cmake "$srcdir" \
        -DCMAKE_INSTALL_PREFIX="$HEIF" \
        -DCMAKE_INSTALL_LIBDIR=lib \
        -DCMAKE_BUILD_TYPE=Release \
        -DCMAKE_INSTALL_RPATH="$HEIF/lib" \
        -DCMAKE_SHARED_LINKER_FLAGS="-Wl,-Bsymbolic-functions" \
        -DBUILD_SHARED_LIBS=ON \
        -DENABLE_SDL=OFF \
        -DENABLE_ENCODER=OFF
    run_logged "$logfile" nice_run cmake --build . --parallel "$JOBS"
    run_logged "$logfile" nice_run cmake --install .
    popd >/dev/null

    ensure_libde265_soname
    local real
    real=$(libde265_real_file)
    local notfound
    notfound=$(ldd "$real" 2>/dev/null | awk '/not found/ { print }')
    [[ -z $notfound ]] || die "libde265 has unresolved dependencies: $notfound"

    record_version "LIBDE265_MODE" "built"
    record_version "LIBDE265_VERSION" "$ver"
    ok "libde265 $ver installed to $HEIF"
}

libde265_real_file() {
    local real
    real=$(find "$HEIF/lib" -maxdepth 1 -type f -name 'libde265.so.*' 2>/dev/null | sort -V | tail -n 1)
    [[ -n $real ]] || die "libde265 shared library not found under $HEIF/lib"
    printf '%s\n' "$real"
}

ensure_libde265_soname() {
    if (( SYSTEM_LIBDE265 )); then
        return 0
    fi
    local real base
    real=$(libde265_real_file)
    base=$(basename -- "$real")
    if [[ $(patchelf --print-soname "$real" 2>/dev/null || true) != "libde265-heicfix.so.0" ]]; then
        patchelf --set-soname libde265-heicfix.so.0 "$real"
    fi
    ln -sfn "$base" "$HEIF/lib/libde265-heicfix.so.0"
    # The plain SONAME must not be resolvable from our prefix. Anything built
    # against -lde265 records the private SONAME above.
    rm -f "$HEIF/lib/libde265.so.0"
    ln -sfn "$base" "$HEIF/lib/libde265.so"

    local f needed rpath
    for f in "$HEIF"/bin/*; do
        [[ -f $f && ! -L $f ]] || continue
        needed=$(patchelf --print-needed "$f" 2>/dev/null || true)
        if grep -qx 'libde265.so.0' <<<"$needed"; then
            patchelf --replace-needed libde265.so.0 libde265-heicfix.so.0 "$f"
        fi
        rpath=$(patchelf --print-rpath "$f" 2>/dev/null || true)
        if [[ $rpath != *"$HEIF/lib"* ]]; then
            patchelf --set-rpath "$HEIF/lib${rpath:+:$rpath}" "$f"
        fi
    done
}

# ---------------------------------------------------------------------------
# Build: libheif
# ---------------------------------------------------------------------------

libheif_real_file() {
    local real
    real=$(find "$HEIF/lib" -maxdepth 1 -type f -name 'libheif.so.*' 2>/dev/null | sort -V | tail -n 1)
    [[ -n $real ]] || die "libheif shared library not found under $HEIF/lib"
    printf '%s\n' "$real"
}

ensure_libheif_soname() {
    local real base needed
    real=$(libheif_real_file)
    base=$(basename -- "$real")

    needed=$(patchelf --print-needed "$real")
    if (( ! SYSTEM_LIBDE265 )) && grep -qx 'libde265.so.0' <<<"$needed"; then
        patchelf --replace-needed libde265.so.0 libde265-heicfix.so.0 "$real"
    fi
    if [[ $(patchelf --print-soname "$real" 2>/dev/null || true) != "libheif-heicfix.so.1" ]]; then
        patchelf --set-soname libheif-heicfix.so.1 "$real"
    fi
    ln -sfn "$base" "$HEIF/lib/libheif-heicfix.so.1"
    rm -f "$HEIF/lib/libheif.so.1"
    # pkg-config consumers link -lheif; the linker then reads the private SONAME.
    ln -sfn "$base" "$HEIF/lib/libheif.so"

    local rpath
    rpath=$(patchelf --print-rpath "$real" 2>/dev/null || true)
    if [[ $rpath != *"$HEIF/lib"* ]]; then
        patchelf --set-rpath "$HEIF/lib${rpath:+:$rpath}" "$real"
    fi

    local f f_needed rpath
    for f in "$HEIF"/bin/*; do
        [[ -f $f && ! -L $f ]] || continue
        f_needed=$(patchelf --print-needed "$f" 2>/dev/null || true)
        if grep -qx 'libheif.so.1' <<<"$f_needed"; then
            patchelf --replace-needed libheif.so.1 libheif-heicfix.so.1 "$f"
        fi
        if (( ! SYSTEM_LIBDE265 )) && grep -qx 'libde265.so.0' <<<"$f_needed"; then
            patchelf --replace-needed libde265.so.0 libde265-heicfix.so.0 "$f"
        fi
        rpath=$(patchelf --print-rpath "$f" 2>/dev/null || true)
        if [[ $rpath != *"$HEIF/lib"* ]]; then
            patchelf --set-rpath "$HEIF/lib${rpath:+:$rpath}" "$f"
        fi
    done
}

build_libheif() {
    local ver=$1 force=${2:-0}
    local plain
    plain=$(strip_v "$ver")

    local mode="built"
    (( SYSTEM_LIBDE265 )) && mode="system"
    local recorded_mode
    recorded_mode=$(get_recorded "LIBDE265_MODE")
    if [[ -n $recorded_mode && $recorded_mode != "$mode" ]]; then
        log "libde265 mode changed ($recorded_mode -> $mode); rebuilding libheif"
        force=1
    fi

    if (( ! force )) \
        && [[ $(get_recorded "LIBHEIF_VERSION") == "$ver" ]] \
        && compgen -G "$HEIF/lib/libheif.so.*" >/dev/null; then
        log "libheif $ver already built; skipping"
        ensure_libheif_soname
        return 0
    fi

    local archive="$SRC/libheif-$plain.tar.gz"
    local srcdir="$SRC/libheif-$plain"
    local builddir="$SRC/build-libheif-$plain"
    local logfile="$LOGS/libheif-$ver.log"

    download "https://github.com/strukturag/libheif/releases/download/$ver/libheif-$plain.tar.gz" "$archive"
    extract_source "$archive" "$srcdir"
    rm -rf "$builddir"

    log_step "Building libheif $ver"
    mkdir -p "$builddir" "$HEIF"
    pushd "$builddir" >/dev/null
    (
        export PKG_CONFIG_PATH="$HEIF/lib/pkgconfig${PKG_CONFIG_PATH:+:$PKG_CONFIG_PATH}"
        run_logged "$logfile" nice_run cmake "$srcdir" \
            -DCMAKE_INSTALL_PREFIX="$HEIF" \
            -DCMAKE_INSTALL_LIBDIR=lib \
            -DCMAKE_BUILD_TYPE=Release \
            -DCMAKE_INSTALL_RPATH="$HEIF/lib" \
            -DBUILD_SHARED_LIBS=ON \
            -DWITH_LIBDE265=ON \
            -DWITH_X265=OFF \
            -DWITH_AOM_DECODER=ON \
            -DWITH_AOM_ENCODER=ON \
            -DWITH_EXAMPLES=ON \
            -DWITH_EXAMPLE_HEIF_VIEW=OFF \
            -DWITH_EXAMPLE_HEIF_THUMB=OFF \
            -DWITH_GDK_PIXBUF=OFF \
            -DBUILD_TESTING=OFF \
            -DBUILD_DOCUMENTATION=OFF \
            -DENABLE_PLUGIN_LOADING=OFF
        run_logged "$logfile" nice_run cmake --build . --parallel "$JOBS"
        run_logged "$logfile" nice_run cmake --install .
    )
    popd >/dev/null

    ensure_libheif_soname

    local real notfound
    real=$(libheif_real_file)
    notfound=$(ldd "$real" 2>/dev/null | awk '/not found/ { print }')
    [[ -z $notfound ]] || die "libheif has unresolved dependencies: $notfound"

    if (( SYSTEM_LIBDE265 )); then
        patchelf --print-needed "$real" | grep -qx 'libde265.so.0' \
            || warn "libheif does not list libde265.so.0; is HEVC decoding enabled?"
    else
        patchelf --print-needed "$real" | grep -qx 'libde265-heicfix.so.0' \
            || die "libheif did not link against libde265-heicfix.so.0"
    fi

    record_version "LIBHEIF_VERSION" "$ver"
    ok "libheif $ver installed to $HEIF"
}

# ---------------------------------------------------------------------------
# Build: ImageMagick 7
# ---------------------------------------------------------------------------

build_imagemagick() {
    local ver=$1
    local logfile="$LOGS/imagemagick-$ver.log"

    if [[ $(get_recorded "IMAGEMAGICK_VERSION") == "$ver" ]] \
        && compgen -G "$IMAGEMAGICK/lib/libMagickCore-7*.so*" >/dev/null; then
        log "ImageMagick $ver already built; skipping"
        verify_imagemagick_linkage
        return 0
    fi

    local archive="$SRC/ImageMagick-$ver.tar.xz"
    local srcdir="$SRC/ImageMagick-$ver"
    download "https://github.com/ImageMagick/ImageMagick/releases/download/$ver/ImageMagick-$ver.tar.xz" "$archive"
    extract_source "$archive" "$srcdir"

    log_step "Building ImageMagick $ver"
    mkdir -p "$IMAGEMAGICK"
    pushd "$srcdir" >/dev/null
    if [[ ! -x ./configure ]]; then
        popd >/dev/null
        die "ImageMagick source has no configure script; cannot build"
    fi
    (
        export PKG_CONFIG_PATH="$HEIF/lib/pkgconfig${PKG_CONFIG_PATH:+:$PKG_CONFIG_PATH}"
        export CPPFLAGS="-I$HEIF/include"
        export LDFLAGS="-L$HEIF/lib -Wl,-rpath,$IMAGEMAGICK/lib -Wl,-rpath,$HEIF/lib"
        run_logged "$logfile" nice_run ./configure \
            --prefix="$IMAGEMAGICK" \
            --with-heic=yes \
            --without-modules \
            --disable-openmp \
            --disable-docs \
            --without-perl \
            --without-x \
            --without-magick-plus-plus \
            --with-quantum-depth=16 \
            --enable-hdri
        run_logged "$logfile" nice_run make -j "$JOBS"
        run_logged "$logfile" nice_run make install
    )
    popd >/dev/null

    verify_imagemagick_linkage
    record_version "IMAGEMAGICK_VERSION" "$ver"
    ok "ImageMagick $ver installed to $IMAGEMAGICK"
}

verify_imagemagick_linkage() {
    local magick="$IMAGEMAGICK/bin/magick"
    [[ -x $magick ]] || die "ImageMagick binary not found at $magick"

    local version
    version=$("$magick" -version 2>&1 || true)
    if [[ $version != *"ImageMagick 7"* ]]; then
        die "unexpected ImageMagick version: ${version%%$'\n'*}"
    fi

    local core="" lib
    for lib in "$IMAGEMAGICK"/lib/libMagickCore-7*.so*; do
        [[ -f $lib && ! -L $lib ]] || continue
        core=$lib
        break
    done
    [[ -n $core ]] || die "libMagickCore-7 not found under $IMAGEMAGICK/lib"

    local needed
    needed=$(ldd "$core" 2>/dev/null || true)
    if [[ $needed != *"libheif-heicfix.so.1"* ]]; then
        die "libMagickCore does not link libheif-heicfix.so.1; HEIC delegate is missing"
    fi
    if [[ $needed == *"not found"* ]]; then
        die "libMagickCore has unresolved dependencies: $(grep 'not found' <<<"$needed" | head -n 1)"
    fi

    local formats
    formats=$("$magick" -list format 2>/dev/null | awk '$1 ~ /^HEI[CF]\*?$/ { gsub(/\*$/, "", $1); print $1 }' | sort -u | paste -sd, - || true)
    [[ -n $formats ]] || die "ImageMagick does not list HEIC/HEIF support"
    log "ImageMagick HEIC formats: $formats"
}

imagemagick_decode_sample() {
    local sample=$1 state=$2
    sample_for_decode "$sample" "$state" || return 0
    local magick="$IMAGEMAGICK/bin/magick"
    local out="$TMPDIR_PREFIX/imagemagick-decoded.jpg"
    mkdir -p "$TMPDIR_PREFIX"
    rm -f "$out"
    if ! run_logged "$LOGS/imagemagick-sample.log" nice_run "$magick" "${sample}[0]" "$out"; then
        die "ImageMagick could not decode $sample (see $LOGS/imagemagick-sample.log)"
    fi
    [[ -s $out ]] || die "ImageMagick produced an empty decode output"
    local dims
    dims=$("$IMAGEMAGICK/bin/identify" -format '%wx%h' "$out" 2>/dev/null || true)
    ok "ImageMagick decoded sample (${dims:-unknown size})"
    rm -f "$out"
}

libheif_decode_sample() {
    local sample=$1 state=$2
    sample_for_decode "$sample" "$state" || return 0
    local dec="$HEIF/bin/heif-dec"
    [[ -x $dec ]] || { warn "heif-dec not built; skipping libheif sample decode"; return 0; }
    local outdir="$TMPDIR_PREFIX/heif-dec"
    local out="$outdir/decoded.png"
    mkdir -p "$outdir"
    rm -f "$outdir"/*.png "$outdir"/*.jpg
    # heif-dec writes "decoded.png" for a single image, or "decoded-1.png",
    # "decoded-2.png", ... when the file contains several images.
    if ! run_logged "$LOGS/heif-dec-sample.log" nice_run "$dec" --quiet "$sample" "$out"; then
        die "libheif could not decode $sample (see $LOGS/heif-dec-sample.log)"
    fi
    if ! compgen -G "$outdir/*.png" >/dev/null && ! compgen -G "$outdir/*.jpg" >/dev/null; then
        die "heif-dec produced no output for $sample"
    fi
    ok "libheif decoded sample"
    rm -rf "$outdir"
}

# ---------------------------------------------------------------------------
# Build: Imagick
# ---------------------------------------------------------------------------

imagick_so() {
    printf '%s/%s/imagick.so\n' "$PHPROOT" "$1"
}

ensure_php_dev() {
    local ver=$1
    if phpize_bin "$ver" >/dev/null && php_config_bin "$ver" >/dev/null; then
        return 0
    fi
    [[ $PLATFORM == "runcloud" ]] && { not_implemented_runcloud "ensure_php_dev"; return 1; }
    if ! confirm "Install php$ver-dev to build the Imagick extension?"; then
        die "php$ver-dev is required"
    fi
    apt_run install "php$ver-dev"
    phpize_bin "$ver" >/dev/null || die "phpize$ver still missing after installing php$ver-dev"
}

build_imagick() {
    local ver=$1
    local phpize phpconfig
    phpize=$(phpize_bin "$ver") || die "phpize$ver not found"
    phpconfig=$(php_config_bin "$ver") || die "php-config$ver not found"

    if [[ $(get_recorded "IMAGICK_VERSION_$ver") == "$RESOLVED_IMAGICK" ]] \
        && [[ $(get_recorded "IMAGICK_BUILD_REV_$ver") == "$IMAGICK_BUILD_REV" ]] \
        && [[ $(get_recorded "IMAGICK_IM_FOR_$ver") == "$(get_recorded "IMAGEMAGICK_VERSION")" ]] \
        && [[ -f $(imagick_so "$ver") ]]; then
        log "Imagick $RESOLVED_IMAGICK for PHP $ver already built; skipping"
        return 0
    fi

    local cached="$SRC/imagick-src-$RESOLVED_IMAGICK"
    local srcdir="$SRC/imagick-$RESOLVED_IMAGICK-php$ver"
    local logfile="$LOGS/imagick-$ver-$RESOLVED_IMAGICK.log"
    if [[ ! -d $cached ]]; then
        log "cloning Imagick $RESOLVED_IMAGICK"
        if ! git clone --depth 1 --branch "$RESOLVED_IMAGICK" \
            https://github.com/Imagick/imagick.git "$cached" >>"$logfile" 2>&1; then
            die "could not clone Imagick $RESOLVED_IMAGICK (see $logfile)"
        fi
    fi

    # Each PHP ABI needs a clean tree: phpize/configure and object files are
    # version-specific.
    rm -rf "$srcdir"
    cp -a "$cached" "$srcdir"
    # The git source deliberately leaves @PACKAGE_VERSION@ for PECL to replace.
    sed -i "s/@PACKAGE_VERSION@/$RESOLVED_IMAGICK/g" "$srcdir/php_imagick.h"

    log_step "Building Imagick $RESOLVED_IMAGICK for PHP $ver"
    pushd "$srcdir" >/dev/null
    run_logged "$logfile" nice_run "$phpize"
    (
        export PKG_CONFIG_PATH="$IMAGEMAGICK/lib/pkgconfig:$HEIF/lib/pkgconfig${PKG_CONFIG_PATH:+:$PKG_CONFIG_PATH}"
        export CPPFLAGS="-I$IMAGEMAGICK/include/ImageMagick-7"
        export LDFLAGS="-L$IMAGEMAGICK/lib -L$HEIF/lib -Wl,-rpath,$IMAGEMAGICK/lib -Wl,-rpath,$HEIF/lib"
        run_logged "$logfile" nice_run ./configure \
            --with-php-config="$phpconfig" \
            --with-imagick="$IMAGEMAGICK"
        run_logged "$logfile" nice_run make -j "$JOBS"
    )
    popd >/dev/null

    local built="$srcdir/modules/imagick.so"
    [[ -f $built ]] || die "Imagick build did not produce modules/imagick.so"
    mkdir -p "$PHPROOT/$ver"
    install -m 0755 "$built" "$(imagick_so "$ver")"

    verify_imagick_linkage "$ver"
    record_version "IMAGICK_VERSION_$ver" "$RESOLVED_IMAGICK"
    record_version "IMAGICK_BUILD_REV_$ver" "$IMAGICK_BUILD_REV"
    record_version "IMAGICK_IM_FOR_$ver" "$(get_recorded "IMAGEMAGICK_VERSION")"
    ok "Imagick $RESOLVED_IMAGICK for PHP $ver installed to $(imagick_so "$ver")"
}

verify_imagick_linkage() {
    local ver=$1 so
    so=$(imagick_so "$ver")
    [[ -f $so ]] || die "missing $so"

    local ldd_out
    ldd_out=$(ldd "$so" 2>/dev/null || true)
    if [[ $ldd_out == *"not found"* ]]; then
        die "imagick.so has unresolved dependencies: $(grep 'not found' <<<"$ldd_out" | head -n 1)"
    fi
    local mode
    mode=$(get_recorded "LIBDE265_MODE")
    if [[ $mode != "system" ]]; then
        grep -q 'libheif-heicfix\.so\.1 =>' <<<"$ldd_out" \
            || die "imagick.so does not resolve libheif-heicfix.so.1"
        grep -q 'libde265-heicfix\.so\.0 =>' <<<"$ldd_out" \
            || die "imagick.so does not resolve libde265-heicfix.so.0"
        grep -q "libheif-heicfix\.so\.1 => $PREFIX/" <<<"$ldd_out" \
            || die "libheif-heicfix.so.1 does not resolve inside $PREFIX"
        grep -q "libde265-heicfix\.so\.0 => $PREFIX/" <<<"$ldd_out" \
            || die "libde265-heicfix.so.0 does not resolve inside $PREFIX"
    fi
}

# A copy of the real scan directory (FPM when present) with our extension and
# with stock imagick removed. This keeps GD loaded: "php -n" would hide the
# SONAME collision that breaks production.
make_preflight_scan_dir() {
    local ver=$1 dest=$2
    local src=""
    if [[ -d "/etc/php/$ver/fpm/conf.d" ]]; then
        src="/etc/php/$ver/fpm/conf.d"
    elif [[ -d "/etc/php/$ver/cli/conf.d" ]]; then
        src="/etc/php/$ver/cli/conf.d"
    else
        die "no conf.d for PHP $ver under /etc/php/$ver"
    fi

    mkdir -p "$dest"
    local f
    for f in "$src"/*.ini; do
        [[ -f $f ]] || continue
        if grep -qi 'imagick' "$f" 2>/dev/null; then
            continue
        fi
        cp -L "$f" "$dest/"
    done
    printf 'extension=%s\n' "$(imagick_so "$ver")" >"$dest/20-imagick-heic.ini"
}

preflight_imagick() {
    local ver=$1 php sample=$2 sample_state=$3
    php=$(php_bin "$ver") || die "php$ver not found"
    [[ -f $(imagick_so "$ver") ]] || die "missing $(imagick_so "$ver")"

    local tmp
    mkdir -p "$TMPDIR_PREFIX"
    tmp=$(mktemp -d "$TMPDIR_PREFIX/preflight.XXXXXX")
    make_preflight_scan_dir "$ver" "$tmp/conf.d"

    local out="" rc=0
    if out=$(PHP_INI_SCAN_DIR="$tmp/conf.d" HEIC_PROBE_SAMPLE="$sample" "$php" -d display_errors=1 -r '
$kv = function (string $k, string $v): void {
    echo $k, "=", str_replace(["\r", "\n"], " ", $v), "\n";
};
$ext_file = function (string $name): string {
    $maps = @file_get_contents("/proc/self/maps");
    if ($maps !== false) {
        foreach (explode("\n", $maps) as $line) {
            if (strpos($line, "/" . $name . ".so") === false) {
                continue;
            }
            $parts = preg_split("/\s+/", trim($line));
            $path = end($parts);
            if (is_string($path) && is_file($path)) {
                return $path;
            }
        }
    }
    $fallback = ini_get("extension_dir") . "/" . $name . ".so";
    return is_file($fallback) ? $fallback : "";
};
$kv("gd", extension_loaded("gd") ? "yes" : "no");
$kv("imagick", extension_loaded("imagick") ? "yes" : "no");
if (extension_loaded("imagick")) {
    $kv("imagick_version", (string)phpversion("imagick"));
    $kv("ext_file", $ext_file("imagick"));
    $v = Imagick::getVersion();
    $kv("imagemagick", (string)($v["versionString"] ?? ""));
    $formats = Imagick::queryFormats("HEI*");
    $kv("heic_formats", $formats ? implode(",", $formats) : "-");
    $s = getenv("HEIC_PROBE_SAMPLE");
    if ($s !== false && $s !== "" && is_file($s)) {
        try {
            $img = new Imagick($s);
            $img->setImageFormat("jpeg");
            $blob = $img->getImageBlob();
            $kv("decode", "ok");
            $kv("dimensions", $img->getImageWidth() . "x" . $img->getImageHeight());
            $kv("jpeg_bytes", (string)strlen($blob));
        } catch (Throwable $e) {
            $kv("decode", "fail");
            $kv("decode_error", $e->getMessage());
        }
    }
}
' 2>&1); then
        rc=0
    else
        rc=$?
    fi

    local gd imagick_ver imver formats decode decode_err
    gd=$(awk -F= '$1=="gd"{print $2}' <<<"$out")
    imagick_ver=$(awk -F= '$1=="imagick_version"{print $2}' <<<"$out")
    imver=$(awk -F= '$1=="imagemagick"{sub(/^[^=]*=/,""); print}' <<<"$out")
    formats=$(awk -F= '$1=="heic_formats"{print $2}' <<<"$out")
    decode=$(awk -F= '$1=="decode"{print $2}' <<<"$out")
    decode_err=$(awk -F= '$1=="decode_error"{sub(/^[^=]*=/,""); print}' <<<"$out")

    local failed=0
    printf '%sPre-flight for PHP %s%s\n' "$C_BOLD" "$ver" "$C_RESET"
    printf '  scan dir     : %s (real conf.d, stock imagick removed)\n' "$tmp/conf.d"
    printf '  GD loaded    : %s\n' "${gd:-unknown}"
    printf '  imagick      : %s\n' "${imagick_ver:-NOT LOADED}"
    printf '  ImageMagick  : %s\n' "${imver:-unknown}"
    printf '  HEIC formats : %s\n' "${formats:-none}"
    case $decode in
        ok) printf '  sample       : decode=ok\n' ;;
        fail) printf '  sample       : decode=FAIL: %s\n' "${decode_err:-unknown}" ;;
        "") printf '  sample       : not run (%s)\n' "$sample_state" ;;
        *) printf '  sample       : %s\n' "$decode" ;;
    esac

    [[ $gd == "yes" ]] || { warn "GD is not loaded in pre-flight; the SONAME collision cannot be proven here"; }
    [[ $imagick_ver != "" ]] || failed=1
    [[ ${imver:-} == *"ImageMagick 7"* ]] || failed=1
    [[ -n $formats && $formats != "-" ]] || failed=1
    if [[ $decode == "fail" ]]; then
        failed=1
    fi

    if (( rc != 0 )); then
        printf '  probe exited with status %s\n' "$rc"
        failed=1
    fi

    rm -rf "$tmp"

    if (( failed )); then
        error "pre-flight failed for PHP $ver; not enabling"
        return 1
    fi
    ok "pre-flight passed for PHP $ver"
    return 0
}

# ---------------------------------------------------------------------------
# Commands: build
# ---------------------------------------------------------------------------

cmd_build() {
    require_root
    acquire_lock
    platform_notes
    ensure_deps
    resolve_versions

    printf '%sBuild plan%s\n' "$C_BOLD" "$C_RESET"
    printf '  platform      : %s\n' "$PLATFORM"
    printf '  prefix        : %s\n' "$PREFIX"
    if (( SYSTEM_LIBDE265 )); then
        printf '  libde265      : system (libde265-dev, not built)\n'
    else
        printf '  libde265      : %s\n' "$RESOLVED_LIBDE265"
    fi
    printf '  libheif       : %s (minimum %s)\n' "$RESOLVED_LIBHEIF" "$MIN_LIBHEIF"
    printf '  ImageMagick   : %s\n' "$RESOLVED_IMAGEMAGICK"
    printf '  make jobs     : %s\n' "$JOBS"

    if ! confirm "Build libde265, libheif and ImageMagick 7 into $PREFIX?"; then
        die "build cancelled"
    fi

    mkdir -p "$HEIF" "$SRC" "$LOGS" "$TMPDIR_PREFIX"

    local prev_mode prev_de265 prev_heif current_mode
    prev_mode=$(get_recorded "LIBDE265_MODE")
    prev_de265=$(get_recorded "LIBDE265_VERSION")
    prev_heif=$(get_recorded "LIBHEIF_VERSION")
    current_mode="built"
    (( SYSTEM_LIBDE265 )) && current_mode="system"

    local libheif_force=0
    [[ $prev_heif != "$RESOLVED_LIBHEIF" ]] && libheif_force=1
    [[ $prev_mode != "$current_mode" ]] && libheif_force=1
    if (( ! SYSTEM_LIBDE265 )) && [[ $prev_de265 != "$RESOLVED_LIBDE265" ]]; then
        libheif_force=1
    fi

    build_libde265 "$RESOLVED_LIBDE265"
    build_libheif "$RESOLVED_LIBHEIF" "$libheif_force"
    build_imagemagick "$RESOLVED_IMAGEMAGICK"

    local state
    state=$(sample_state "$SAMPLE")
    if [[ $state == "altered" ]]; then
        warn "sample checksum mismatch; not using it to prove decoding"
    fi
    libheif_decode_sample "$SAMPLE" "$state"
    imagemagick_decode_sample "$SAMPLE" "$state"

    printf '\n%sBuild complete%s\n' "$C_GREEN" "$C_RESET"
    printf '  libheif       : %s\n' "$(get_recorded LIBHEIF_VERSION)"
    printf '  ImageMagick   : %s\n' "$(get_recorded IMAGEMAGICK_VERSION)"
    printf '  logs          : %s\n' "$LOGS"
    printf '\nNext: %s php --php <X.Y>\n' "$SCRIPT_NAME"
}

# ---------------------------------------------------------------------------
# Commands: php
# ---------------------------------------------------------------------------

cmd_php() {
    if [[ $PLATFORM == "runcloud" ]]; then
        not_implemented_runcloud "php"
        return 2
    fi
    require_root
    acquire_lock
    platform_notes
    resolve_imagick_version

    local targets=()
    mapfile -t targets < <(requested_targets)
    if (( ${#targets[@]} == 0 )); then
        die "no PHP versions found; pass --php X.Y"
    fi

    local built_im
    built_im=$(get_recorded "IMAGEMAGICK_VERSION")
    if [[ -z $built_im ]] \
        || ! compgen -G "$IMAGEMAGICK/lib/libMagickCore-7*.so*" >/dev/null; then
        die "ImageMagick is not built in $PREFIX; run: $SCRIPT_NAME build"
    fi

    mkdir -p "$SRC" "$LOGS" "$PHPROOT" "$TMPDIR_PREFIX"

    local ver state
    state=$(sample_state "$SAMPLE")
    for ver in "${targets[@]}"; do
        log_step "PHP $ver"
        ensure_php_dev "$ver"
        build_imagick "$ver"

        if ! preflight_imagick "$ver" "$SAMPLE" "$state"; then
            die "refusing to enable the extension for PHP $ver"
        fi

        if confirm "Enable imagick-heic for PHP $ver and reload php$ver-fpm?"; then
            enable_ext "$ver"
            reload_fpm "$ver"
            ok "imagick-heic enabled for PHP $ver"
        else
            warn "not enabled for PHP $ver"
            printf '  To enable later: %s enable --php %s\n' "$SCRIPT_NAME" "$ver"
        fi
    done

    printf '\n%sDone%s\n' "$C_GREEN" "$C_RESET"
    printf 'Run: %s verify --sample %s\n' "$SCRIPT_NAME" "$SAMPLE"
}

# ---------------------------------------------------------------------------
# Commands: enable / rollback / verify
# ---------------------------------------------------------------------------

cmd_enable() {
    if [[ $PLATFORM == "runcloud" ]]; then
        not_implemented_runcloud "enable"
        return 2
    fi
    require_root
    acquire_lock
    platform_notes
    local targets=()
    mapfile -t targets < <(requested_targets)
    if (( ${#targets[@]} == 0 )); then
        die "no PHP versions found; pass --php X.Y"
    fi

    local ver
    for ver in "${targets[@]}"; do
        [[ -f $(imagick_so "$ver") ]] || die "missing $(imagick_so "$ver"); run: $SCRIPT_NAME php --php $ver"
        if confirm "Enable imagick-heic for PHP $ver and reload php$ver-fpm?"; then
            enable_ext "$ver"
            reload_fpm "$ver"
            ok "imagick-heic enabled for PHP $ver"
        fi
    done
}

cmd_rollback() {
    if [[ $PLATFORM == "runcloud" ]]; then
        not_implemented_runcloud "rollback"
        return 2
    fi
    require_root
    acquire_lock
    platform_notes
    local targets=()
    mapfile -t targets < <(requested_targets)
    if (( ${#targets[@]} == 0 )); then
        die "no PHP versions found; pass --php X.Y"
    fi

    local ver
    for ver in "${targets[@]}"; do
        if confirm "Disable imagick-heic for PHP $ver and restore stock imagick?"; then
            disable_ext "$ver"
            reload_fpm "$ver"
            ok "rolled back PHP $ver to stock imagick"
        fi
    done
    printf '\nRun: %s check   (expect AFFECTED again)\n' "$SCRIPT_NAME"
}

cmd_verify() {
    if [[ $PLATFORM == "runcloud" ]]; then
        not_implemented_runcloud "verify"
        return 2
    fi
    local targets=()
    mapfile -t targets < <(requested_targets)
    if (( ${#targets[@]} == 0 )); then
        die "no PHP versions found; pass --php X.Y"
    fi

    local state
    state=$(sample_state "$SAMPLE")
    [[ $state == "altered" ]] && warn "sample checksum mismatch; ignoring decode results"
    [[ $state == "unverified" ]] && warn "sample is not listed in samples/SHA256SUMS; treating decode as unverified"

    local ver failures=0
    for ver in "${targets[@]}"; do
        printf '\n%sPHP %s%s\n' "$C_BOLD" "$ver" "$C_RESET"
        verify_cli "$ver" "$state" || failures=$((failures + 1))
        verify_fpm "$ver" "$state" || failures=$((failures + 1))
        verify_library_isolation "$ver" || failures=$((failures + 1))
    done

    printf '\n'
    if (( failures )); then
        printf '%sVerify FAILED%s (%s issue(s))\n' "$C_RED" "$C_RESET" "$failures"
        return 1
    fi
    printf '%sVerify PASSED%s\n' "$C_GREEN" "$C_RESET"
    return 0
}

verify_cli() {
    local ver=$1 state=$2
    local php
    php=$(php_bin "$ver") || { error "php$ver not found"; return 1; }

    local sample=$SAMPLE
    sample_for_decode "$sample" "$state" || sample=""
    probe_php "$php" "$sample"
    local rc=$PROBE_RC err
    err=$(cat "$PROBE_ERR_FILE" 2>/dev/null || true)

    local imagick imver ext formats decode dims
    imagick=$(probe_value imagick)
    imver=$(probe_value imagemagick)
    ext=$(probe_value ext_file)
    formats=$(probe_value heic_formats)
    decode=$(probe_value decode)
    dims=$(probe_value dimensions)
    cleanup_probe

    local failed=0
    printf '  CLI           : %s\n' "$php"
    if (( rc != 0 )) || [[ $imagick != "yes" ]]; then
        printf '    imagick     : NOT LOADED\n'
        (( rc != 0 )) && printf '    stderr      : %s\n' "${err%%$'\n'*}"
        return 1
    fi
    printf '    imagick.so  : %s\n' "$ext"
    printf '    ImageMagick : %s\n' "$imver"
    printf '    HEIC        : %s\n' "${formats:-none}"
    [[ $imver == *"ImageMagick 7"* ]] || { printf '    %sFAIL%s: CLI is not using ImageMagick 7\n' "$C_RED" "$C_RESET"; failed=1; }
    [[ -n $formats && $formats != "-" ]] || { printf '    %sFAIL%s: HEIC coder missing\n' "$C_RED" "$C_RESET"; failed=1; }
    if [[ -n $sample ]]; then
        if [[ $decode == "ok" ]]; then
            printf '    sample      : decode=ok (%s)\n' "$dims"
        else
            printf '    sample      : %sFAIL%s decode=%s\n' "$C_RED" "$C_RESET" "${decode:-unknown}"
            failed=1
        fi
    else
        printf '    sample      : not used (%s)\n' "$state"
    fi
    if [[ $err == *"already loaded"* ]]; then
        printf '    %sWARN%s: duplicate imagick ini detected (already loaded)\n' "$C_YELLOW" "$C_RESET"
    fi
    if (( failed )); then
        return 1
    fi
    printf '    %sPASS%s\n' "$C_GREEN" "$C_RESET"
    return 0
}

verify_fpm() {
    local ver=$1 state=$2
    local fpm
    if ! fpm=$(fpm_bin "$ver"); then
        error "php-fpm$ver not found; cannot verify FPM"
        return 1
    fi

    local scan_dirs=""
    if [[ -d "/etc/php/$ver/fpm/conf.d" ]]; then
        scan_dirs="/etc/php/$ver/fpm/conf.d"
    fi

    local out="" rc=0
    if [[ -n $scan_dirs ]]; then
        if out=$(PHP_INI_SCAN_DIR="$scan_dirs" "$fpm" -i 2>&1); then
            rc=0
        else
            rc=$?
        fi
    else
        if out=$("$fpm" -i 2>&1); then
            rc=0
        else
            rc=$?
        fi
    fi

    local imver imagick_module
    imver=$(sed -n 's/^Imagick using ImageMagick library version => //p' <<<"$out" | head -n 1)
    if [[ -z $imver ]]; then
        imver=$(sed -n 's/^Imagick compiled with ImageMagick version => //p' <<<"$out" | head -n 1)
    fi
    imagick_module=$(sed -n 's/^imagick module => //p' <<<"$out" | head -n 1)

    local failed=0
    printf '  FPM (%s)\n' "$fpm"
    if (( rc != 0 )); then
        printf '    %sFAIL%s: php-fpm -i exited %s\n' "$C_RED" "$C_RESET" "$rc"
        printf '    %s\n' "${out%%$'\n'*}"
        return 1
    fi
    printf '    imagick     : %s\n' "${imagick_module:-not enabled}"
    printf '    ImageMagick : %s\n' "${imver:-unknown}"
    [[ $imagick_module == "enabled" ]] || { printf '    %sFAIL%s: imagick is not enabled in FPM\n' "$C_RED" "$C_RESET"; failed=1; }
    [[ $imver == *"ImageMagick 7"* ]] || { printf '    %sFAIL%s: FPM is not using ImageMagick 7\n' "$C_RED" "$C_RESET"; failed=1; }
    if [[ $out == *"already loaded"* ]]; then
        printf '    %sWARN%s: duplicate imagick ini detected in FPM (already loaded)\n' "$C_YELLOW" "$C_RESET"
        failed=1
    fi
    if (( failed )); then
        return 1
    fi
    printf '    %sPASS%s\n' "$C_GREEN" "$C_RESET"
    return 0
}

verify_library_isolation() {
    local ver=$1 so
    so=$(imagick_so "$ver")
    if [[ ! -f $so ]]; then
        printf '  Library isolation: %sFAIL%s %s missing\n' "$C_RED" "$C_RESET" "$so"
        return 1
    fi

    local ldd_out mode
    ldd_out=$(ldd "$so" 2>/dev/null || true)
    mode=$(get_recorded "LIBDE265_MODE")
    printf '  Library isolation\n'
    if [[ $mode == "system" ]]; then
        printf '    mode        : system libde265\n'
        if grep -q 'libheif-heicfix\.so\.1 =>' <<<"$ldd_out"; then
            printf '    %sPASS%s libheif-heicfix.so.1 resolves\n' "$C_GREEN" "$C_RESET"
            return 0
        fi
        printf '    %sFAIL%s libheif-heicfix.so.1 not resolved\n' "$C_RED" "$C_RESET"
        return 1
    fi

    local line failed=0
    for line in "libheif-heicfix.so.1" "libde265-heicfix.so.0"; do
        if grep -q "$line => $PREFIX/" <<<"$ldd_out"; then
            printf '    %-24s %sPASS%s\n' "$line" "$C_GREEN" "$C_RESET"
        else
            printf '    %-24s %sFAIL%s (not resolved inside %s)\n' "$line" "$C_RED" "$C_RESET" "$PREFIX"
            failed=1
        fi
    done
    return "$failed"
}

# ---------------------------------------------------------------------------
# Commands: status
# ---------------------------------------------------------------------------

cmd_status() {
    printf '%sheic-fix.sh status%s\n' "$C_BOLD" "$C_RESET"
    printf 'platform      : %s\n' "$PLATFORM"
    printf 'prefix        : %s\n' "$PREFIX"
    printf 'sample        : %s (%s)\n' "$SAMPLE" "$(sample_state "$SAMPLE")"
    if [[ -d $PREFIX ]]; then
        printf 'disk usage    : %s\n' "$(du -sh "$PREFIX" 2>/dev/null | awk '{print $1}')"
    else
        printf 'disk usage    : prefix does not exist\n'
    fi

    printf '\n%sInstalled versions%s\n' "$C_BOLD" "$C_RESET"
    if [[ -f $VERSIONS_FILE ]]; then
        sed 's/^/  /' "$VERSIONS_FILE"
    else
        printf '  (none)\n'
    fi

    printf '\n%sBuilt artifacts%s\n' "$C_BOLD" "$C_RESET"
    if [[ $(get_recorded "LIBDE265_MODE") == "system" ]]; then
        printf '  %-60s %s\n' "$HEIF/lib/libde265" "using the system libde265"
    elif [[ -e "$HEIF/lib/libde265-heicfix.so.0" ]]; then
        printf '  %-60s ok\n' "$HEIF/lib/libde265-heicfix.so.0"
    else
        printf '  %-60s missing\n' "$HEIF/lib/libde265-heicfix.so.0"
    fi
    if [[ -e "$HEIF/lib/libheif-heicfix.so.1" ]]; then
        printf '  %-60s ok\n' "$HEIF/lib/libheif-heicfix.so.1"
    else
        printf '  %-60s missing\n' "$HEIF/lib/libheif-heicfix.so.1"
    fi
    if compgen -G "$IMAGEMAGICK/lib/libMagickCore-7*.so*" >/dev/null; then
        printf '  %-60s ok\n' "$IMAGEMAGICK/lib/libMagickCore-7"
    else
        printf '  %-60s missing\n' "$IMAGEMAGICK/lib/libMagickCore-7"
    fi

    printf '\n%sPHP%s\n' "$C_BOLD" "$C_RESET"
    local ver targets=()
    mapfile -t targets < <(requested_targets)
    if (( ${#targets[@]} == 0 )); then
        printf '  (no PHP versions found)\n'
    fi
    for ver in "${targets[@]}"; do
        local so active="stock/none" php
        so=$(imagick_so "$ver")
        if [[ -f $so ]]; then
            active="built ($so)"
        fi
        printf '  PHP %-4s extension: %s\n' "$ver" "$active"
        if php=$(php_bin "$ver" 2>/dev/null); then
            probe_php "$php" ""
            local ext imver
            ext=$(probe_value ext_file)
            imver=$(probe_value imagemagick)
            cleanup_probe
            printf '           CLI loads : %s\n' "${ext:-none}"
            printf '           CLI IM    : %s\n' "${imver:-none}"
        else
            printf '           CLI       : not installed\n'
        fi
    done

    printf '\n%sSystem libheif%s\n' "$C_BOLD" "$C_RESET"
    if have dpkg-query; then
        local v
        v=$(dpkg-query -W -f='${Version}' libheif1 2>/dev/null || true)
        if [[ -n $v ]]; then
            printf '  libheif1 package: %s\n' "$v"
        else
            printf '  libheif1 package: not installed\n'
        fi
    else
        printf '  (dpkg-query not available)\n'
    fi
}

# ---------------------------------------------------------------------------
# Argument parsing and main
# ---------------------------------------------------------------------------

parse_args() {
    while (( $# )); do
        case $1 in
            check|build|php|enable|rollback|verify|status|deps)
                [[ -z $COMMAND ]] || die "only one command may be given"
                COMMAND=$1
                shift
                ;;
            --php)
                [[ $# -ge 2 ]] || die "--php requires X.Y"
                PHP_TARGETS+=("$2")
                shift 2
                ;;
            --php=*)
                PHP_TARGETS+=("${1#*=}")
                shift
                ;;
            --prefix)
                [[ $# -ge 2 ]] || die "--prefix requires a directory"
                PREFIX=$2
                shift 2
                ;;
            --prefix=*)
                PREFIX=${1#*=}
                shift
                ;;
            --sample)
                [[ $# -ge 2 ]] || die "--sample requires a file"
                SAMPLE=$2
                shift 2
                ;;
            --sample=*)
                SAMPLE=${1#*=}
                shift
                ;;
            --system-libde265)
                SYSTEM_LIBDE265=1
                shift
                ;;
            --jobs|-j)
                [[ $# -ge 2 ]] || die "--jobs requires a number"
                JOBS=$2
                shift 2
                ;;
            --jobs=*|-j=*)
                JOBS=${1#*=}
                shift
                ;;
            -y|--yes)
                ASSUME_YES=1
                shift
                ;;
            -q|--quiet)
                QUIET=1
                shift
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            --version)
                printf '%s %s\n' "$SCRIPT_NAME" "$SCRIPT_VERSION"
                exit 0
                ;;
            *)
                die "unknown argument: $1 (try --help)"
                ;;
        esac
    done

    [[ -n $COMMAND ]] || { usage >&2; exit 2; }
    [[ -n $SAMPLE ]] || SAMPLE=$(default_sample)
    [[ -n $JOBS ]] || JOBS=$(nproc 2>/dev/null || printf '1')
    if [[ ! $JOBS =~ ^[0-9]+$ ]] || (( JOBS < 1 )); then
        die "--jobs must be a positive integer"
    fi

    validate_php_targets
}

main() {
    parse_args "$@"
    detect_platform
    load_versions
    init_paths

    local rc=0
    case $COMMAND in
        check) cmd_check || rc=$? ;;
        build) cmd_build || rc=$? ;;
        php) cmd_php || rc=$? ;;
        enable) cmd_enable || rc=$? ;;
        rollback) cmd_rollback || rc=$? ;;
        verify) cmd_verify || rc=$? ;;
        status) cmd_status || rc=$? ;;
        deps) cmd_deps || rc=$? ;;
        *) die "unknown command: $COMMAND" ;;
    esac
    return "$rc"
}

main "$@" || exit $?
