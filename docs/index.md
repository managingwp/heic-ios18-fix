---
title: heic-ios18-fix
description: Restore iOS 18+ HEIC uploads in PHP Imagick on Debian and Ubuntu.
slug: /
---

# heic-ios18-fix

Restore **iOS 18+ iPhone HEIC uploads** in **PHP Imagick** on Debian and Ubuntu
servers without replacing system libraries, upgrading the distribution, or
changing PHP versions.

The script builds a private libde265, libheif and ImageMagick 7 into
`/opt/heic-ios18-fix`, then builds a matching Imagick extension for each PHP
version you choose. The system ImageMagick, libheif, PHP packages and
`/usr/lib` stay exactly as the distribution shipped them.

## Start here

- [Getting started](getting-started.md) — install, build and enable the fix.
- [Commands](commands.md) — every subcommand, option and exit code.
- [How it works](how-it-works.md) — the GD/SONAME trap and the private prefix.
- [Troubleshooting](troubleshooting.md) — common package and verification failures.
- [Samples](samples.md) — the real iPhone HEIC used to prove decoding.

## What it fixes

Photos from iOS 18+ iPhones carry HEIF metadata that older libheif releases
cannot parse: a `tmap` tone-map/gain-map item and additional auxiliary images.
libheif **1.18.2** is the first release with the fix, but Ubuntu 22.04 ships
1.12.0 and Ubuntu 24.04 ships 1.17.6.

| Distribution | libheif | iOS 18 HEIC |
| --- | --- | --- |
| Ubuntu 22.04 | 1.12.0 | fails — use this project |
| Ubuntu 24.04 | 1.17.6 | fails — use this project |
| Ubuntu 26.04 | 1.21.2 | fixed natively; install the HEVC decoder plugin |
| Debian 12 | 1.15.1 | fails — use this project |
| Any build with libheif ≥ 1.18.2 | ≥ 1.18.2 | works, with an HEVC decoder available |

On Ubuntu 26.04 and newer, do not build the prefix. The distribution already
contains the fix; install `libheif-plugin-libde265` for HEVC decoding. See
[Ubuntu 26.04](platforms/ubuntu-26-04.md).

## What the script does

- Builds **libde265**, **libheif** and **ImageMagick 7** in an isolated prefix.
- Gives the private libraries unique SONAMEs so PHP GD's system libheif cannot
  be reused by the new ImageMagick.
- Builds one Imagick extension per PHP ABI, runs a pre-flight with GD loaded,
  then enables it with `phpdismod`/`phpenmod`.
- Provides **check**, **build**, **php**, **enable**, **rollback**, **verify**,
  **status** and **deps**.
- Detects GridPane; RunCloud building is supported and PHP wiring is explicitly
  marked `TODO(runcloud)`.

## Sample status

The repository includes a public iPhone 15 Pro / iOS 18.0 HEIC sample at
`samples/failure_magick.HEIC`. It reproduces the failure on Ubuntu 22.04 and is
used by `check` and `verify`. See [Samples](samples.md) for its source, checksum
and how to replace it with the original BTN photo.

## Documentation

| Page | Contents |
| --- | --- |
| [Getting started](getting-started.md) | Requirements, quick start, rollback, uninstall |
| [Commands](commands.md) | Commands, options, exit codes, quiet output |
| [How it works](how-it-works.md) | Problem, symptoms, prefix layout, SONAME isolation, pre-flight, needrestart, versions |
| [GridPane](platforms/gridpane.md) | GridPane-specific runbook and update notes |
| [RunCloud](platforms/runcloud.md) | Supported build, unimplemented PHP wiring |
| [Ubuntu 26.04](platforms/ubuntu-26-04.md) | Native fix and HEVC plugin requirement |
| [Troubleshooting](troubleshooting.md) | PCRE2 conflicts, stock imagick after verify |
| [Samples](samples.md) | Sample rules, checksums, synthetic test file |
| [Monitoring](monitoring.md) | Cron, monit and alert examples |
| [Testing](testing.md) | Container tests, CI and real-sample proof |
| [Alternatives](alternatives.md) | Convert at upload, browser WASM, OS upgrade |
| [References](references.md) | Upstream issues and discussions |

## Publishing

This site is built with **Docusaurus** and deployed to GitHub Pages by the
`.github/workflows/deploy-pages.yml` workflow. The configuration assumes the
project site at `https://managingwp.github.io/heic-ios18-fix/`; change `url` and
`baseUrl` in `docusaurus.config.js` if you use a custom domain or rename the
repository.

## License

MIT © Managing WP. See [LICENSE](https://github.com/managingwp/heic-ios18-fix/blob/main/LICENSE).
