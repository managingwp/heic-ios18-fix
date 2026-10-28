---
title: RunCloud
description: RunCloud build support and unimplemented PHP wiring.
layout: default
---

[← Docs home](../index.md) · [How it works](../how-it-works.md)

# RunCloud

RunCloud support is **partial**:

* `build` works. It compiles libde265, libheif and ImageMagick 7 into
  `/opt/heic-ios18-fix` using the normal Debian/Ubuntu toolchain.
* PHP and FPM wiring is **not implemented**. The script detects `/RunCloud`,
  refuses to guess at RunCloud's paths, and prints a `TODO(runcloud)` message.

## Unverified paths

The following paths are expected but have not been confirmed on a real RunCloud
server. They are marked `TODO(runcloud)` in `heic-fix.sh`:

| Purpose | Expected path |
| --- | --- |
| PHP binary | `/RunCloud/Packages/phpXXrc/bin/php` |
| CLI config | `/etc/phpXXrc/cli/conf.d` |
| FPM config | `/etc/phpXXrc/fpm/conf.d` |
| Module config | `/etc/phpXXrc/mods-available` |
| FPM binary | `/RunCloud/Packages/phpXXrc/sbin/php-fpm` or `phpXXrc-fpm` |
| FPM service | `phpXXrc-fpm` |
| Pool configs | `/etc/phpXXrc/fpm/pool.d` |

## What works today

```bash
sudo /opt/heic-ios18-fix/heic-fix.sh build -y
```

This gives you:

* `/opt/heic-ios18-fix/heif/bin/heif-dec` — the isolated decoder, usable from a
  WordPress upload hook,
* `/opt/heic-ios18-fix/imagemagick/bin/magick` — ImageMagick 7,
* private `libheif-heicfix.so.1` / `libde265-heicfix.so.0`.

You can convert an upload with `heif-dec` without touching PHP's Imagick:

```bash
/opt/heic-ios18-fix/heif/bin/heif-dec --quiet photo.heic photo.jpg
```

## Adding RunCloud PHP support

Implement these hooks in `heic-fix.sh` and remove the corresponding
`TODO(runcloud)` markers:

* `php_versions` — enumerate installed `phpXXrc` stacks.
* `php_bin` — return the RunCloud CLI binary.
* `phpize_bin` / `php_config_bin` — point at the matching build tools.
* `fpm_bin` — return the RunCloud FPM binary.
* `enable_ext` / `disable_ext` — write the equivalent of
  `mods-available/imagick-heic.ini` and toggle the stock module.
* `reload_fpm` — reload the RunCloud FPM unit.
* `site_map` — map RunCloud sites to PHP versions.

Send a pull request with the verified paths and a `verify` transcript.

## Notes

* The script detects RunCloud by the presence of `/RunCloud`.
* `build` still uses `apt-get` with needrestart disabled.
* Do not run the script's `check`, `php`, `enable`, `rollback` or `verify`
  commands on RunCloud until the hooks are implemented; they exit with an
  explicit not-implemented error instead of making a wrong change.
