# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

* `check`, `verify` and the pre-flight now report `linkage=` and require
  `static-hidden`: the private libheif is linked into `libMagickCore` with
  hidden symbols. A prefix that still depends on a shared libheif is reported
  as AFFECTED and is not enabled.
* Container regression that decodes `samples/failure_magick.HEIC` through the
  private CLI, Imagick alone and GD-first, then asserts the ELF and runtime
  binding contract. CI adds Ubuntu 22.04 with PHP 8.5.
* GitHub Pages documentation site with a landing page, getting-started guide,
  command reference, platform pages and monitoring.
* Public iPhone 15 Pro / iOS 18.0 HEIC sample (libheif #1190) at
  `samples/failure_magick.HEIC` with a verified SHA256 checksum.
* Docs build and link check in CI.
* **HEIC Fix Monitor** WordPress plugin (`wordpress-plugin/heic-fix-monitor/`):
  read-only FPM decode, optional `check`/ELF probes, deterministic state
  fingerprints, transition and recovery alerts by email, Slack or JSON webhook,
  optional digest, `wp heic-monitor check`, multisite provisioning, and a
  standalone zip build. Secrets come from `wp-config.php` constants or an
  encrypted, non-autoloaded option.
* Plugin fixture, disposable WordPress single-site/multisite, admin action,
  mocked webhook, static safety and zip-content checks in CI.
* Plugin release documentation for independent `heic-monitor-vX.Y.Z` versioning,
  the public download page, and the tag-triggered GitHub Release workflow.

### Fixed

* Plugin download page now uses the Docusaurus docs layout, so the documentation
  sidebar is available.
* GD could capture ImageMagick's `heif_*` calls on Ubuntu 22.04 with PHP 8.5.
  Private SONAMEs isolated the loaded objects but not the exported function
  names, so `heif_context_alloc`, `heif_context_read_from_file` and
  `heif_context_free` bound to the system libheif 1.12.0 while another call
  bound to the private 1.23.7 library. `build` now compiles PIC static
  libde265/libheif archives and links them into MagickCore with
  `--exclude-libs,ALL` and `-Bsymbolic-functions`. Existing prefixes rebuild
  once through the new `IMAGEMAGICK_LINKAGE_REV` marker.

### Changed

* Documentation site migrated from Jekyll/Cayman to **Docusaurus 3.10** with a
  sidebar and automatic previous/next navigation.
* GitHub Pages now deploys from the `Deploy docs` GitHub Actions workflow.
* Root `README.md` reduced to a short entry point; detailed material now lives
  under `docs/`.
* `monitoring/README.md` and `samples/README.md` are pointers to their docs
  pages.

## [1.0.2] - 2026-10-28

### Added

* Troubleshooting guide for the Sury/Ubuntu `libpcre2-dev` 10.39/10.42 conflict
  that blocks `phpX.Y-dev` on GridPane servers.
* Clearer `php` failure when `phpX.Y-dev` cannot be installed.

## [1.0.1] - 2026-10-28

### Added

* Ubuntu 26.04 documentation: libheif 1.21.2 already contains the iOS 18 fix,
  but HEVC decoding needs `libheif-plugin-libde265`.
* `check` and `verify` now recognise the missing HEVC plugin on libheif
  ≥ 1.18.2 and print the apt package instead of implying a rebuild is needed.
* `docs/platforms/ubuntu-26-04.md` and an Ubuntu 26.04 row in the README
  distro table.

## [1.0.0] - 2026-10-27

### Added

* `heic-fix.sh` with `check`, `build`, `php`, `enable`, `rollback`, `verify`,
  `status` and `deps` commands.
* Isolated libde265 + libheif + ImageMagick 7 build in `/opt/heic-ios18-fix`.
* Private SONAMEs (`libde265-heicfix.so.0`, `libheif-heicfix.so.1`) applied
  with `patchelf` before ImageMagick is built, so PHP GD's system libheif
  cannot be reused by the private ImageMagick.
* Per-PHP-version Imagick builds with a realistic pre-flight that keeps GD
  loaded (no `php -n`).
* Per-version enable/rollback through `phpdismod`/`phpenmod` and FPM reloads.
* Read-only `check` with monitoring-friendly `-q` output and exit codes
  0/1/2/3.
* Best-effort site → PHP map from FPM pool configs.
* Debian/Ubuntu generic, GridPane detection, and RunCloud detection with
  explicit `TODO(runcloud)` hooks.
* Sample checksum verification (`verified`, `unverified`, `altered`) and
  sample decode checks.
* Documentation, platform notes, monitoring examples, container test and
  GitHub Actions workflow.

[1.0.2]: https://github.com/managingwp/heic-ios18-fix/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/managingwp/heic-ios18-fix/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/managingwp/heic-ios18-fix/releases/tag/v1.0.0
