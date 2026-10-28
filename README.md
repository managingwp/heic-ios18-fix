# heic-ios18-fix

Restore **iOS 18+ iPhone HEIC uploads** in **PHP Imagick** on Debian and Ubuntu
servers — without replacing system libraries, upgrading the distribution, or
changing PHP versions.

The script builds a private libde265, libheif and ImageMagick 7 into
`/opt/heic-ios18-fix`, then builds a matching Imagick extension for each PHP
version you choose. The system ImageMagick, libheif, PHP packages and
`/usr/lib` stay exactly as the distribution shipped them.

- License: MIT
- Tested in containers: Ubuntu 22.04/24.04 (workaround) and Ubuntu 26.04
  (native plugin path)
- Verified decoder: libheif **v1.23.7** (iPhone 15 Pro / iOS 18.0 sample)
- Minimum accepted libheif: **1.18.2**

## Quick start

```bash
git clone https://github.com/managingwp/heic-ios18-fix.git
cd heic-ios18-fix

./heic-fix.sh check                  # read-only
sudo ./heic-fix.sh build             # libde265 + libheif + ImageMagick 7
sudo ./heic-fix.sh php --php 8.3     # build and enable Imagick for one PHP
sudo ./heic-fix.sh verify --php 8.3  # CLI + FPM must report ImageMagick 7
```

Add `-y` to skip prompts. Add `--sample /path/to/photo.HEIC` to test a specific
photo. Roll back one PHP version with:

```bash
sudo ./heic-fix.sh rollback --php 8.3
```

## What it does

- Builds **libde265**, **libheif** and **ImageMagick 7** in an isolated prefix.
- Links the private libheif and libde265 **statically into MagickCore** with
  hidden symbols, so PHP GD's system libheif cannot capture ImageMagick's
  `heif_*` calls. Private SONAMEs (`libheif-heicfix.so.1`,
  `libde265-heicfix.so.0`) remain for the shared diagnostic tools.
- Builds one Imagick extension per PHP ABI and runs a pre-flight with GD loaded.
- Provides `check`, `build`, `php`, `enable`, `rollback`, `verify`, `status` and
  `deps`; detects GridPane and marks RunCloud PHP wiring `TODO(runcloud)`.

## Requirements

- Debian or Ubuntu with PHP under `/etc/php/X.Y`.
- Root for `build`, `php`, `enable` and `rollback`.
- Network access to GitHub and apt repositories.
- About 1–2 GB free under the prefix; a clean build takes roughly 5–15 minutes.
- `phpX.Y-dev` for each PHP version; the script offers to install it.

`check`, `status`, `deps` and `verify` are read-only and can run as a normal user.

## Commands

| Command | Root? | What it does |
| --- | --- | --- |
| `check` | no | Read-only health check per PHP version, plus a site → PHP map. Exit codes 0/1/2/3. |
| `build` | yes | Builds libde265 → libheif → ImageMagick 7 into the prefix. |
| `php --php X.Y` | yes | Builds Imagick, runs the GD-loaded pre-flight, enables it. |
| `enable --php X.Y` | yes | Enables an already-built extension and reloads FPM. |
| `rollback --php X.Y` | yes | Restores stock imagick and reloads FPM. |
| `verify --php X.Y` | no | Checks CLI and FPM, sample decode and static-hidden linkage. |
| `status` | no | Shows the prefix and what is enabled. |
| `deps` | no | Shows build package and tool status. |

Full options and exit codes: [Commands](docs/commands.md).

## Platforms

| Platform | Detection | Build | PHP/FPM wiring |
| --- | --- | --- | --- |
| Generic Debian/Ubuntu | default | yes | `/etc/php/X.Y`, `phpdismod`/`phpenmod` |
| GridPane | `/usr/local/bin/gp` or `/opt/gridpane` | yes | same as generic, with update warnings |
| RunCloud | `/RunCloud` | yes | **not implemented** — `TODO(runcloud)` |

On **Ubuntu 26.04** do not build the prefix: libheif 1.21.2 already contains the
fix. Install `libheif-plugin-libde265` for HEVC decoding. See
[docs/platforms/ubuntu-26-04.md](docs/platforms/ubuntu-26-04.md).

## Sample

The repository includes a public iPhone 15 Pro / iOS 18.0 HEIC at
`samples/failure_magick.HEIC`. It reproduces the failure on Ubuntu 22.04 and is
used by `check` and `verify`. Source, checksum and replacement rules:
[docs/samples.md](docs/samples.md).

## Documentation

Published site: <https://managingwp.github.io/heic-ios18-fix/>

| Page | Contents |
| --- | --- |
| [Getting started](docs/getting-started.md) | Requirements, install, rollback, uninstall |
| [Commands](docs/commands.md) | Commands, options, exit codes, quiet output |
| [How it works](docs/how-it-works.md) | Problem, symptoms, static symbol isolation, pre-flight, needrestart |
| [GridPane](docs/platforms/gridpane.md) | GridPane runbook and update notes |
| [RunCloud](docs/platforms/runcloud.md) | Build support and unimplemented wiring |
| [Ubuntu 26.04](docs/platforms/ubuntu-26-04.md) | Native fix and HEVC plugin |
| [Troubleshooting](docs/troubleshooting.md) | PCRE2 conflicts, stock imagick after verify |
| [Samples](docs/samples.md) | Sample rules and synthetic files |
| [Monitoring](docs/monitoring.md) | Cron, monit and alerts |
| [WordPress monitor](wordpress-plugin/heic-fix-monitor/docs/configuration.md) | Read-only plugin with transition alerts and WP-CLI |
| [Testing](docs/testing.md) | Container tests and CI |
| [Alternatives](docs/alternatives.md) | Upload conversion, browser WASM, OS upgrade |
| [References](docs/references.md) | Upstream issues and discussions |

## Safety

- Snapshot the server before the first production run.
- Every system-changing step prompts unless `-y` is given.
- Nothing under `/usr` is replaced.
- Re-run `check` and `verify` after platform updates or PHP upgrades.
- The script has been validated in containers; test on a server before the
  first client server.

## License

MIT © Managing WP. See [LICENSE](LICENSE).
