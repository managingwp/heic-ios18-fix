---
title: Troubleshooting
description: PCRE2 package conflicts and common verification failures.
---

[← Docs home](index.md) · [Getting started](getting-started.md) · [Commands](commands.md)

# Troubleshooting

## `phpX.Y-dev` fails on Sury/GridPane: libpcre2-dev 10.39 vs 10.42

### Symptom

```text
The following packages have unmet dependencies:
 libpcre2-dev : Depends: libpcre2-8-0 (= 10.39-3ubuntu0.1)
                but 10.42-3+ubuntu22.04.1+deb.sury.org+1 is to be installed
E: Unable to correct problems, you have held broken packages.
```

This blocks `php8.3-dev` and therefore the Imagick build. The libraries from
`build` are fine; only the PHP development package is missing.

### Cause

Sury packages PHP 8.3/8.4/8.5 against **PCRE2 10.42**. The server has the Sury
`libpcre2-8-0` 10.42 runtime installed, but Ubuntu 22.04's `libpcre2-dev` is
built for **10.39** and pins the exact runtime version. Installing the Ubuntu
dev package would downgrade the runtime that PHP expects.

The current `packages.sury.org/php` mirror on the server does not carry
`libpcre2-dev`, but Ondrej's Launchpad PPA does, at the matching 10.42 version.

### Fix: install the matching Sury PCRE2 development set

Do **not** downgrade `libpcre2-8-0` to 10.39 and do not use
`dpkg --force-depends` on `phpX.Y-dev`.

```bash
mkdir -p /tmp/pcre2-sury
cd /tmp/pcre2-sury

base='https://ppa.launchpadcontent.net/ondrej/php/ubuntu/pool/main/p/pcre2'
ver='10.42-3+ubuntu22.04.1+deb.sury.org+1'

for p in libpcre2-dev libpcre2-8-0 libpcre2-16-0 libpcre2-32-0 libpcre2-posix3; do
    curl -fLO "$base/${p}_${ver}_amd64.deb"
done

dpkg -i libpcre2-dev_${ver}_amd64.deb \
        libpcre2-8-0_${ver}_amd64.deb \
        libpcre2-16-0_${ver}_amd64.deb \
        libpcre2-32-0_${ver}_amd64.deb \
        libpcre2-posix3_${ver}_amd64.deb
```

If `dpkg` reports a dependency problem, run:

```bash
apt-get install -f
```

Confirm the set is consistent:

```bash
dpkg-query -W -f='${Package} ${Version}\n' \
    libpcre2-dev libpcre2-8-0 libpcre2-16-0 libpcre2-32-0 libpcre2-posix3
```

All five should report `10.42-3+ubuntu22.04.1+deb.sury.org+1`.

### Then install the PHP development package

```bash
apt-get install -y php8.3-dev
phpize8.3 --version
```

After that, rerun only the PHP step — **no need to rebuild the libraries**:

```bash
cd ~/heic-ios18-fix
./heic-fix.sh php --php 8.3
./heic-fix.sh php --php 8.1 --php 8.2   # other versions used by sites
```

The same PCRE2 fix covers every PHP version; it is a one-time system change.

### Why not downgrade PCRE2?

PHP 8.3/8.4/8.5 from Sury are built for PCRE2 10.42. Downgrading to Ubuntu's
10.39 can produce PHP startup warnings such as:

```text
PHP Warning: PHP Startup: ^(text/|application/xhtml\+xml) (offset=0):
unrecognised compile-time option bit(s)
```

### Package checksums

From the Ondrej PPA `Packages` index (SHA256):

```text
4ead2ea114ee540883b93848dd2dda08992e3b820f3eb0d7604b47430911e51d  libpcre2-dev
5e51ced86ae7bd77cc6df2311d60b9d091f28564c0d89da988794b0c82119d3a  libpcre2-8-0
396787ac8f837a192d40be64c969183d8192fe77368cb08ec6426217ef7d7241  libpcre2-16-0
3eda8ac2aa3763c8cec1a042664da6682f3047dc888c02647c8e93b755575d71  libpcre2-32-0
4bb8ed549cc46d433e19cd8d445fe294177ec3b1785c2acfb7f498d61ec09b7c  libpcre2-posix3
```

Verify with:

```bash
sha256sum /tmp/pcre2-sury/*.deb
```

## `verify` fails with stock ImageMagick 6

Run the PHP step first:

```bash
./heic-fix.sh php --php 8.3
```

`build` only compiles libde265, libheif and ImageMagick 7. It does not create
`/opt/heic-ios18-fix/php/8.3/imagick.so`; `php` does that and enables it.

## Decoding fails only when GD is loaded

### Symptom

The private tools decode the photo, but PHP fails once `gd.so` is loaded:

```text
ImagickException: Invalid input: Unspecified:
Too many auxiliary image references (2.0)
  @ error/heic.c/IsHEIFSuccess/207
```

`php -n -d extension=imagick.so` decodes; `php -n -d extension=gd.so -d
extension=imagick.so` fails. `check` reports `linkage=shared` or `Linkage:
mixed` for the prefix extension.

### Cause

The prefix was built with private SONAMEs only. GD loads the system
`libheif.so.1` and exports its `heif_*` functions globally; a SONAME isolates
the loaded object but not the function names, so ImageMagick's calls bind to
the system libheif. Known to happen on Ubuntu 22.04 with PHP 8.5.

### Confirm it

```bash
cd ~/heic-ios18-fix

# 1. Private CLI — proves the build itself is fine
/opt/heic-ios18-fix/imagemagick/bin/magick samples/failure_magick.HEIC[0] null:

# 2. Imagick alone and GD first — the split identifies symbol capture
for mode in imagick-only gd-first; do
    printf '\n=== %s ===\n' "$mode"
    args=(-n)
    [[ $mode == gd-first ]] && args+=(-d extension=gd.so)
    php8.5 "${args[@]}" -d extension=/opt/heic-ios18-fix/php/8.5/imagick.so \
        -r '$im = new Imagick($argv[1]); $im->setImageFormat("jpeg");
            printf("bytes=%d\n", strlen($im->getImageBlob()));' \
        samples/failure_magick.HEIC
done

# 3. Show which library each call binds to
LD_DEBUG=bindings php8.5 -n -d extension=gd.so \
    -d extension=/opt/heic-ios18-fix/php/8.5/imagick.so \
    -r '$im = new Imagick($argv[1]); $im->setImageFormat("jpeg"); $im->getImageBlob();' \
    samples/failure_magick.HEIC 2>&1 |
    grep -E 'binding file .*libMagickCore.* to .*libheif'
```

Any `libMagickCore → /lib/.../libheif.so.1` line confirms the problem.

### Fix

Rebuild the prefix; this is a link-layout change, not a library upgrade:

```bash
cd ~/heic-ios18-fix
sudo ./heic-fix.sh build
sudo ./heic-fix.sh php --php 8.5
sudo ./heic-fix.sh verify --php 8.5
```

`build` links the private static archives into `libMagickCore` with hidden
symbols. `check`, `verify` and the pre-flight now require
`libheif link : static-hidden` and refuse to enable anything else.

### Do not

- disable `gd.so` or change the global extension order — other code depends on
  GD, and the order is not yours to guarantee;
- add the prefix to `LD_PRELOAD` or `/etc/ld.so.conf.d` in production — it
  changes every process on the server;
- run `enable` after a failed pre-flight. The refusal is the safety gate.

## WordPress monitor states

The [HEIC Fix Monitor plugin](https://github.com/managingwp/heic-ios18-fix/tree/main/wordpress-plugin/heic-fix-monitor)
reports one aggregate state per check:

| State | Meaning |
| --- | --- |
| `ok` | FPM Imagick decoded the verified sample and `check`/ELF facts agree. |
| `degraded` | The primary decode is healthy, but a corroborating check was unavailable, skipped or errored. |
| `affected` | Decode failed, Imagick/HEIC is missing, `check` reports affected, or linkage is not `static-hidden`. |
| `missing` | The prefix, archives, sample, extension or ini is absent. |
| `error` | The primary probe could not run or no trustworthy evidence was collected. |

The status page names the exact reason code. A `degraded` result usually means
`proc_open` is disabled, `check` timed out, or the decode was skipped by the
memory guard; it does not mean the repair was silently changed.
