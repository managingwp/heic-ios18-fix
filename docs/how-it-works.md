---
title: How it works
description: Why older libheif fails, and how the private ImageMagick and static symbol isolation work.
---

[← Docs home](index.md) · [Getting started](getting-started.md) · [Commands](commands.md)

# How it works

## The problem

Photos taken on iOS 18+ iPhones carry HEIF metadata that older libheif releases
cannot parse: a `tmap` tone-map/gain-map item and additional auxiliary images.
Both libheif 1.12.0 (Ubuntu 22.04) and 1.17.6 (Ubuntu 24.04) fail before the
image is decoded. Upgrading PHP does not help, because every PHP version on the
server shares the same system ImageMagick and libheif.

| Distribution | libheif | iOS 18 HEIC |
| --- | --- | --- |
| Ubuntu 22.04 | 1.12.0 | fails |
| Ubuntu 24.04 | 1.17.6 | fails |
| Ubuntu 26.04 | 1.21.2 | fixed natively; install the HEVC decoder plugin |
| Debian 12 | 1.15.1 | fails |
| Any build with libheif ≥ 1.18.2 | ≥ 1.18.2 | works, with an HEVC decoder available |

Ubuntu 26.04 does not need this build. Install `libheif-plugin-libde265` for
HEVC decoding; see [Ubuntu 26.04](platforms/ubuntu-26-04.md).

## Symptoms

A failed upload commonly produces one of these:

- PHP Imagick: `ImagickException: Failed to read the file`
- `heif-convert` 1.12: `Invalid input: Unspecified: Metadata not correctly assigned to image`
- libheif 1.17.x: `Too many auxiliary image references`
- ImageMagick 7: `Metadata not correctly assigned to image (2.0) … error/heic.c/IsHEIFSuccess/…`
- After a partial fix, with PHP GD installed:
  `undefined symbol: heif_has_compatible_filetype` (or another
  `undefined symbol: heif_...`)
- Or the old library is silently used and decoding still fails.

The `undefined symbol` error is the GD trap described below.

## Prefix layout

```text
/opt/heic-ios18-fix/
├── heif/                        libde265 + libheif, private SONAMEs
│   ├── bin/                     heif-dec, heif-info, heif-enc, ...
│   └── lib/
│       ├── libde265-heicfix.so.0
│       ├── libheif-heicfix.so.1
│       └── pkgconfig/
├── heif-static/                 PIC archives linked into MagickCore
│   └── lib/
│       ├── libde265.a
│       ├── libheif.a
│       └── pkgconfig/
├── imagemagick/                 ImageMagick 7.1.x, Q16-HDRI
│   ├── bin/magick
│   └── lib/libMagickCore-7.Q16HDRI.so
├── php/
│   └── 8.3/imagick.so           per PHP version, linked to the prefix
├── src/                         downloaded sources and build trees
├── logs/                        one log per build step
├── tmp/
└── installed-versions.txt
```

`heif/` is the shared build used by the diagnostic tools. `heif-static/` holds
the archives that are linked into ImageMagick, so the two roles stay separate.
Nothing here shadows or replaces `/usr/lib`. The system ImageMagick and libheif
remain available to other software.

## Per-PHP-version extension

Imagick is a thin PHP extension over ImageMagick's MagickWand C API, so it must
be compiled for each PHP ABI. The script builds the same Imagick tag once per
requested PHP version and records the result:

```text
/etc/php/8.3/mods-available/imagick-heic.ini
    ; priority=20
    extension=/opt/heic-ios18-fix/php/8.3/imagick.so
```

The stock `imagick.ini` is disabled with `phpdismod -v 8.3 imagick`, and
`phpenmod -v 8.3 imagick-heic` creates the CLI/FPM symlinks. FPM is reloaded
only after confirmation.

## The GD symbol-capture trap

PHP's GD extension (`gd.so`) is linked against the **system** `libheif.so.1`.
On Debian/Ubuntu, `gd.so` is loaded before `imagick.so`, so the dynamic linker
already has `libheif.so.1` mapped and has exported its `heif_*` functions
globally.

Private SONAMEs alone do not fix that on every PHP build. A SONAME stops the
linker from reusing the system *object*; it does not rename the exported
*functions*. On Ubuntu 22.04 with PHP 8.5, a prefix that used only private
SONAMEs loaded both libheifs and produced this binding trace:

```text
libMagickCore-7.Q16HDRI.so.10 → /lib/x86_64-linux-gnu/libheif.so.1  heif_context_alloc
libMagickCore-7.Q16HDRI.so.10 → /opt/heic-ios18-fix/heif/lib/libheif-heicfix.so.1  heif_context_get_security_limits
libMagickCore-7.Q16HDRI.so.10 → /lib/x86_64-linux-gnu/libheif.so.1  heif_context_read_from_file
libMagickCore-7.Q16HDRI.so.10 → /lib/x86_64-linux-gnu/libheif.so.1  heif_context_free
```

The private ImageMagick decoded the sample, and Imagick decoded it when loaded
alone. With GD loaded first, libheif 1.12.0 parsed the file and failed with
`Too many auxiliary image references`. One function handed a context allocated
by the other library version, which is unsafe whether or not it happens to
decode.

`RTLD_DEEPBIND` is not a guarantee here: the trace above comes from a real PHP
8.5 process that uses it.

### How the build isolates the symbols

`build` compiles a second, position-independent **static** copy of libde265 and
libheif into `heif-static/`, then links those archives into
`libMagickCore-7` with:

```text
-Wl,--exclude-libs,ALL
-Wl,-Bsymbolic-functions
```

The `heif_*` and `de265_*` definitions become local to `libMagickCore`, so every
ImageMagick call binds to the private code regardless of what GD loaded. GD
keeps its own system `libheif.so.1`; the two implementations coexist in one PHP
process.

The resulting library has no shared libheif dependency at all:

```text
$ readelf -d /opt/heic-ios18-fix/imagemagick/lib/libMagickCore-7.Q16HDRI.so.10 | grep -i heif
$ ldd /opt/heic-ios18-fix/php/8.3/imagick.so | grep -i heif
```

Both commands print nothing. `check`, `verify` and the pre-flight report
`libheif linkage=static-hidden` only when that layout is proven.

The shared `heif/` build keeps its private SONAMEs so `heif-dec`, `heif-info`
and the diagnostic paths keep working next to the system libraries.

## Realistic pre-flight

Before enabling anything, `php` builds a temporary `PHP_INI_SCAN_DIR` from the
site's real FPM `conf.d` (or CLI `conf.d` if FPM is absent), removes stock
imagick entries, adds the new extension, and runs PHP with that scan directory.
GD is therefore loaded exactly as it is in production.

`php -n` is deliberately **not** used: it skips GD and hides the symbol-capture
bug.

The pre-flight must report:

- `gd=yes` (or a warning if GD is genuinely not installed),
- Imagick loaded,
- `ImageMagick 7`,
- HEIC listed by `Imagick::queryFormats("HEI*")`,
- `libheif link : static-hidden`,
- a successful sample decode when a sample is present.

If any check fails, the extension is not enabled.

## needrestart

A plain `apt install` once triggered needrestart on a production GridPane
server; it restarted nginx, MariaDB and every php-fpm pool. Every apt call in
the script runs with:

```bash
NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1 DEBIAN_FRONTEND=noninteractive apt-get ...
```

## Why not just upgrade PHP?

Every PHP version on the server uses the same system ImageMagick and libheif.
Changing PHP does not change the HEIC decoder. The long-term fix is a
distribution with libheif ≥ 1.18.2 (Ubuntu 26.04) or a private build like this
one.

See [Commands](commands.md) for version overrides and [GridPane](platforms/gridpane.md)
for platform-specific notes.
