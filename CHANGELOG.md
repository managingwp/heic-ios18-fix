# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.0.0]: https://github.com/managingwp/heic-ios18-fix/releases/tag/v1.0.0
