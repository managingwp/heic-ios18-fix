---
title: Commands
description: All heic-fix.sh commands, options, exit codes and quiet output.
---

[← Docs home](index.md) · [Getting started](getting-started.md)

# Commands

| Command | Root? | What it does |
| --- | --- | --- |
| `check` | no | Read-only. Per PHP version: Imagick version, HEIC formats, loaded `imagick.so`, the libheif it really uses, and a sample decode. Prints a best-effort site → PHP map from FPM pools. Exit codes 0/1/2/3. |
| `build` | yes | Installs build dependencies, builds libde265 → libheif → ImageMagick 7 into the prefix. Records versions and verifies linkage and decoding. |
| `php --php X.Y` | yes | Installs `phpX.Y-dev`, builds Imagick from the pinned git tag, runs a realistic pre-flight (GD loaded), then offers to enable it. |
| `enable --php X.Y` | yes | Enables an already-built Imagick and reloads FPM. |
| `rollback --php X.Y` | yes | Disables `imagick-heic`, re-enables stock `imagick`, reloads FPM. |
| `verify --php X.Y` | no | Checks CLI and `php-fpmX.Y` both report ImageMagick 7, warns about duplicate imagick ini files, decodes the sample, and confirms the private SONAMEs resolve inside the prefix. |
| `status` | no | Shows the prefix, recorded versions, built artifacts, per-PHP extension state and the system libheif package. |
| `deps` | no | Lists build package and tool status. |

## Options

| Option | Meaning |
| --- | --- |
| `--php X.Y` | Target PHP version. Repeatable. Default: every version under `/etc/php`. |
| `--prefix DIR` | Install prefix. Default `/opt/heic-ios18-fix`. |
| `--sample FILE` | HEIC used to prove decoding. Default `samples/failure_magick.HEIC`. |
| `--system-libde265` | Link against the distro libde265 instead of building one. |
| `--jobs N`, `-j N` | Parallel build jobs. Default `nproc`. |
| `-y`, `--yes` | Do not prompt. |
| `-q`, `--quiet` | Suppress progress. `check -q` prints one line per PHP version. |
| `-h`, `--help` | Help. |
| `--version` | Script version. |

## Check exit codes

| Code | Meaning |
| --- | --- |
| 0 | OK — HEIC support is present and no known problem was found. |
| 1 | AFFECTED — at least one PHP version is on an old libheif or fails to decode. |
| 2 | ERROR — a probe failed (missing binary, broken extension, …). |
| 3 | NO IMAGICK — no checked PHP version has Imagick. |

## Quiet output

`check -q` prints one line per PHP version, suitable for monitoring:

```text
php8.3 status=affected imagick=3.8.1 imagemagick=6 heic=yes libheif=1.17.6 decode=fail
php8.3 status=ok imagick=3.8.1 imagemagick=7 heic=yes libheif=1.23.7 decode=ok
```

Fields are stable: `status`, `imagick`, `imagemagick` (major), `heic`, `libheif`,
`decode`.

## Version overrides

`versions.conf` pins libheif; the other components default to `latest` and are
resolved from stable git tags:

| Component | Default | Resolution |
| --- | --- | --- |
| libheif | `v1.23.7` | pinned, verified |
| libde265 | `latest` | newest `vX.Y[.Z]` tag |
| ImageMagick | `latest` | newest `7.x.y-z` tag |
| Imagick | `latest` | newest `X.Y.Z` tag |
| Minimum libheif | `1.18.2` | build refuses anything older |

Override with environment variables:

```bash
sudo LIBHEIF_VERSION=v1.23.7 IMAGEMAGICK_VERSION=7.1.2-34 ./heic-fix.sh build -y
```

The minimum is enforced even when you override, because libheif below 1.18.2
does not implement the iOS 18 `tmap` handling.

## Examples

```bash
# One PHP version
sudo ./heic-fix.sh php --php 8.3

# Several versions, no prompts
sudo ./heic-fix.sh php --php 8.1 --php 8.2 --php 8.3 -y

# Verify with a real photo
sudo ./heic-fix.sh verify --php 8.3 --sample /path/to/photo.HEIC

# Monitoring
/opt/heic-ios18-fix/heic-fix.sh check -q --php 8.3
```
