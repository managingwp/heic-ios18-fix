---
title: GridPane
description: Runbook for GridPane-managed servers.
---

[← Docs home](../index.md) · [Getting started](../getting-started.md) · [Troubleshooting](../troubleshooting.md)

# GridPane

GridPane uses the standard Debian/Ubuntu PHP layout:

```text
/etc/php/8.3/fpm/pool.d/<site>.conf
/etc/php/8.3/mods-available/
/etc/php/8.3/fpm/conf.d/
```

so `heic-fix.sh` takes the generic code path. It is detected when
`/usr/local/bin/gp` or `/opt/gridpane` exists, and prints extra update warnings.

## Before you start

1. Snapshot the server in your provider or GridPane backup system.
2. Run the read-only check:

   ```bash
   sudo ./heic-fix.sh check
   ```

   Expect exit code 1 (`AFFECTED`) on Ubuntu 22.04 and 24.04. The output shows
   the system libheif version and confirms HEIC is listed but not trustworthy.

3. Identify which PHP versions the affected sites use. `check` prints a
   best-effort pool → PHP map; GridPane pool files are usually named after the
   site domain.

## Build and enable

```bash
sudo ./heic-fix.sh build -y
sudo ./heic-fix.sh php --php 8.3
sudo ./heic-fix.sh verify --php 8.3
```

`build` installs the documented build packages with needrestart disabled and
compiles into `/usr/local/lib/heic-ios18-fix`. Distribution packages and
`/usr/lib` are untouched; only a new directory tree under `/usr/local/lib` is
created.

`php` will:

1. install `php8.3-dev` if needed,
2. build Imagick against the private ImageMagick 7,
3. copy a real FPM `conf.d` to a temporary scan directory, remove stock
   imagick, add the new extension, and run the pre-flight with GD loaded,
4. ask before writing `mods-available/imagick-heic.ini`, disabling stock
   imagick, and reloading `php8.3-fpm`.

## Why the prefix is not in `/opt`

GridPane protects `/opt` with an access control entry for its own groups:

```text
# file: /opt
group:GridPane-System-Users:---
```

Site users belong to `GridPane-System-Users`, and a named ACL entry overrides
`other::r-x`. An installation in `/opt/heic-ios18-fix` therefore looks perfectly
readable to root and to `namei -l` (which prints `0755`) but cannot be traversed
by the accounts PHP-FPM and WP-CLI run as. PHP fails at startup:

```text
PHP Warning:  PHP Startup: Unable to load dynamic library
'/opt/heic-ios18-fix/php/8.2/imagick.so' (... Permission denied)
```

That is why the default prefix is `/usr/local/lib/heic-ios18-fix`. Do not remove
the ACL or grant the whole `GridPane-System-Users` group access to `/opt`; move
the installation instead. If you already have a build in `/opt`, follow
[Migrate an existing installation](../troubleshooting.md#migrate-an-existing-optheic-ios18-fix-installation).

## After GridPane updates

GridPane stack and security updates can:

* reinstall `phpX.Y-imagick`,
* re-enable the stock `imagick` module,
* upgrade or downgrade system ImageMagick/libheif.

They do not remove `/usr/local/lib/heic-ios18-fix`. After every update run:

```bash
sudo ./heic-fix.sh check
sudo ./heic-fix.sh verify --php 8.3
```

If `check` reports AFFECTED again, run:

```bash
sudo ./heic-fix.sh enable --php 8.3
```

If `verify` shows the stock module and the private module both loaded
(`already loaded`), disable the stock module again with `enable` (which runs
`phpdismod`) or inspect:

```bash
ls -l /etc/php/8.3/fpm/conf.d/*imagick*
```

## Multiple PHP versions

Sites on different PHP versions need their own Imagick build:

```bash
sudo ./heic-fix.sh php --php 7.4
sudo ./heic-fix.sh php --php 8.1
sudo ./heic-fix.sh php --php 8.3
```

All of them link the same private libheif and ImageMagick build, so the
expensive libraries are compiled once.

## Rollback

```bash
sudo ./heic-fix.sh rollback --php 8.3
```

This re-enables stock imagick for that PHP version and reloads FPM. The private
build remains in the prefix for a later `enable`.

## Notes

* The script has been validated in Ubuntu containers, not on a live GridPane
  server. Verify the first production run with `check` and `verify`.
* GridPane's own `gp` commands are not used; the script relies on standard
  Debian packaging so it also works if GridPane changes its UI.
* Do not run `gp stack` or security updates while the build is running.
