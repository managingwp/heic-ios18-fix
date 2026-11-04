---
title: Getting started
description: Requirements, installation, enabling the extension and rollback.
---

[← Docs home](index.md) · [Commands](commands.md) · [How it works](how-it-works.md)

# Getting started

## Requirements

- Debian or Ubuntu with PHP under `/etc/php/X.Y` (ondrej/Sury or distribution
  packages).
- Root for `build`, `php`, `enable` and `rollback`.
- Network access to GitHub and apt repositories.
- About 1–2 GB free under the prefix. A clean build takes roughly 5–15 minutes
  depending on CPU.
- `phpX.Y-dev` for every PHP version you want to build; the script offers to
  install it.

`check`, `status`, `deps` and `verify` are read-only and can run as a normal
user.

## Quick start

On a test server:

```bash
git clone https://github.com/managingwp/heic-ios18-fix.git
cd heic-ios18-fix

# 1. See what is wrong. Read-only and safe on production.
./heic-fix.sh check

# 2. Build the private libraries. Installs build packages and compiles.
sudo ./heic-fix.sh build

# 3. Build Imagick for one PHP version, run the pre-flight, enable it.
sudo ./heic-fix.sh php --php 8.3

# 4. Prove CLI and FPM use ImageMagick 7.
sudo ./heic-fix.sh verify --php 8.3
```

Add `-y` to skip prompts. Add `--sample /path/to/photo.HEIC` to test a specific
photo instead of `samples/failure_magick.HEIC`.

## Where it installs

`build` compiles into `/usr/local/lib/heic-ios18-fix` by default. That path
matters: the extension is loaded by PHP-FPM and WP-CLI as the site's system
user, so every site user has to be able to read it. The default prefix is world
readable and root owned; nothing under it is writable by a site user.

`build`, `php` and `enable` check that before they report success and stop with
the exact directory, mode or ACL that blocks a site user. Use `--prefix DIR` if
you need a different location, and expect a warning when that location is not
readable by site users.

A prefix under `/opt` is a common trap: GridPane's `/opt` ACL denies its
site-user group even though the directory mode is `0755`. See
[Troubleshooting](troubleshooting.md#permission-denied-loading-imagickso).

## Which PHP versions?

`check` prints a best-effort site → PHP map from the FPM pool files. Build and
enable only the versions that sites actually use:

```bash
sudo ./heic-fix.sh php --php 8.1 --php 8.2 --php 8.3
sudo ./heic-fix.sh verify --php 8.1 --php 8.2 --php 8.3
```

All versions link the same private libheif and ImageMagick build, so the
expensive libraries are compiled once. Each PHP ABI gets its own Imagick
`.so`.

Scoping matters: `check` and `verify` without `--php` inspect **every**
installed version. A version that was never built will still report stock
ImageMagick 6. Use `--php` in monitoring and verification if only some versions
are in use.

## Ubuntu 26.04 and newer

Do not build the prefix on a stock Ubuntu 26.04. libheif 1.21.2 already contains
the iOS 18 fix. Install the separate HEVC decoder plugin instead:

```bash
sudo apt install libheif-plugin-libde265
sudo systemctl reload php8.5-fpm
```

See [Ubuntu 26.04](platforms/ubuntu-26-04.md) for details and verification.

## Rollback

```bash
sudo ./heic-fix.sh rollback --php 8.3
```

Rollback re-enables stock `imagick` for that PHP version and reloads FPM. The
private build stays in `/usr/local/lib/heic-ios18-fix`, so `enable --php 8.3` restores it
without rebuilding.

## Uninstall

After rolling back every version:

```bash
sudo rm -rf /usr/local/lib/heic-ios18-fix
sudo rm -f /etc/php/8.3/mods-available/imagick-heic.ini
```

`rollback` removes the enabled symlinks; deleting the `mods-available` file only
removes the leftover definition.

## Safety

- Snapshot the server before the first production run.
- Every system-changing step prompts unless `-y` is given.
- Nothing under `/usr` is replaced.
- Re-run `check` and `verify` after GridPane/security updates or PHP upgrades.
- Keep `samples/failure_magick.HEIC` checksum-verified so decode tests are
  trustworthy.
