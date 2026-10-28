---
title: How it works
description: Why older libheif fails, and how the private ImageMagick and SONAME isolation work.
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

## The GD / SONAME trap

PHP's GD extension (`gd.so`) is linked against the **system** `libheif.so.1`.
On Debian/Ubuntu, `gd.so` is loaded before `imagick.so`, so the dynamic linker
already has `libheif.so.1` mapped. When the new Imagick or ImageMagick asks for
`libheif.so.1`, the linker reuses the system library — regardless of `rpath` or
`LD_LIBRARY_PATH`. Depending on versions you get:

- `undefined symbol: heif_has_compatible_filetype`, or
- silent use of the old libheif, so iOS 18 files still fail.

This is reproducible on Ubuntu 24.04 with PHP 8.3 + `php8.3-gd`.

The script avoids the collision by giving its private libraries unique SONAMEs
**before** ImageMagick is built, so every consumer links to the private names
automatically:

```bash
patchelf --set-soname libde265-heicfix.so.0   .../libde265.so.0.2.3
patchelf --replace-needed libde265.so.0 \
         libde265-heicfix.so.0                .../libheif.so.1.23.7
patchelf --set-soname libheif-heicfix.so.1    .../libheif.so.1.23.7
patchelf --replace-needed libheif.so.1 \
         libheif-heicfix.so.1                 .../bin/heif-dec
```

The system `libheif.so.1` can then be loaded by GD at the same time as
`libheif-heicfix.so.1`; they are different libraries with different SONAMEs.
PHP loads extensions with `RTLD_GLOBAL | RTLD_DEEPBIND` on glibc, so the private
dependency graph wins over the already-loaded system library. The test suite
loads `gd.so` (which links the system libheif) and also runs a second
verification with the system `libheif.so.1` in `LD_PRELOAD`.

```text
libheif-heicfix.so.1     => /opt/heic-ios18-fix/heif/lib/libheif-heicfix.so.1
libde265-heicfix.so.0    => /opt/heic-ios18-fix/heif/lib/libde265-heicfix.so.0
```

## Realistic pre-flight

Before enabling anything, `php` builds a temporary `PHP_INI_SCAN_DIR` from the
site's real FPM `conf.d` (or CLI `conf.d` if FPM is absent), removes stock
imagick entries, adds the new extension, and runs PHP with that scan directory.
GD is therefore loaded exactly as it is in production.

`php -n` is deliberately **not** used: it skips GD and hides the SONAME bug.

The pre-flight must report:

- `gd=yes` (or a warning if GD is genuinely not installed),
- Imagick loaded,
- `ImageMagick 7`,
- HEIC listed by `Imagick::queryFormats("HEI*")`,
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
