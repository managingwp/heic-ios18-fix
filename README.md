# heic-ios18-fix

Restore **iOS 18+ iPhone HEIC uploads** in **PHP Imagick** on Debian and Ubuntu
servers — without replacing system libraries, without upgrading the distro, and
without changing PHP versions.

The script builds a private libde265, libheif and ImageMagick 7 into
`/opt/heic-ios18-fix`, then builds a matching Imagick extension for each PHP
version you choose. Your system ImageMagick, libheif, PHP packages and
`/usr/lib` stay exactly as the distro shipped them.

* License: MIT
* Tested in containers: Ubuntu 22.04/24.04 (PHP 8.3, workaround) and
  Ubuntu 26.04 (PHP 8.5, native plugin path), with `php-gd` loaded where
  applicable
* Verified decoder: libheif **v1.23.7** (iPhone 15 Pro Max / iOS 18.0 sample)
* Minimum accepted libheif: **1.18.2**

---

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
| Ubuntu 26.04 | 1.21.2 | iOS 18 bug fixed; install the HEVC decoder plugin |
| Debian 12 | 1.15.1 | fails |
| Any build with libheif ≥ 1.18.2 | ≥ 1.18.2 | works |

### Ubuntu 26.04 and newer

Ubuntu 26.04 ships **libheif 1.21.2** and **ImageMagick 7.1.2.18**, so the iOS 18
`tmap` bug is fixed natively. **Do not build this prefix on 26.04.**

There is one separate catch: libheif 1.21 moves HEVC decoding into a plugin, and
`libheif1` only *Suggests* it. A stock `imagemagick`/`php-imagick` install can
therefore still fail decode with:

```text
ImagickException: Error while loading plugin:
No decoding plugin installed for this compression format
```

That is not the iOS 18 metadata failure. Fix it with one package:

```bash
sudo apt install libheif-plugin-libde265
sudo systemctl reload php8.5-fpm   # use the PHP version your sites run
```

Install `libheif-plugin-x265` only if you also need to **encode** HEIC.

`check` recognises this pattern when libheif is already ≥ 1.18.2 and prints the
plugin hint instead of recommending a rebuild. Full details:
[docs/platforms/ubuntu-26.04.md](docs/platforms/ubuntu-26.04.md).

### Symptoms

A failed upload commonly produces one of these:

* PHP Imagick: `ImagickException: Failed to read the file`
* `heif-convert` 1.12: `Invalid input: Unspecified: Metadata not correctly assigned to image`
* libheif 1.17.x: `Too many auxiliary image references`
* ImageMagick 7: `Metadata not correctly assigned to image (2.0) … error/heic.c/IsHEIFSuccess/…`
* After a partial fix, with PHP GD installed:
  `undefined symbol: heif_has_compatible_filetype` (or another
  `undefined symbol: heif_...`)
* Or the old library is silently used and decoding still fails.

The `undefined symbol` error is the GD trap described below.

---

## Requirements

* Debian or Ubuntu with PHP under `/etc/php/X.Y` (ondrej or distro packages).
* Root for `build`, `php`, `enable` and `rollback`.
* Network access to GitHub, apt repositories and (for the sample tests) the
  libheif example file.
* About 1–2 GB free under the prefix, plus build time. A clean build takes
  roughly 5–15 minutes depending on CPU.
* `phpX.Y-dev` for every PHP version you want to build; the script offers to
  install it.

`check`, `status`, `deps` and `verify` are read-only and can run as a normal
user.

---

## Quick start

On a test server, as root:

```bash
git clone https://github.com/managingwp/heic-ios18-fix.git
cd heic-ios18-fix

# 1. See what is wrong. Read-only, safe on production.
./heic-fix.sh check

# 2. Build the private libraries. This installs build packages and compiles.
sudo ./heic-fix.sh build

# 3. Build Imagick for one PHP version, run the pre-flight, enable it.
sudo ./heic-fix.sh php --php 8.3

# 4. Prove CLI and FPM are using ImageMagick 7.
sudo ./heic-fix.sh verify --php 8.3
```

Add `-y` to skip prompts (for automation), and `--sample /path/to/photo.HEIC`
to test with a real failing photo.

To undo:

```bash
sudo ./heic-fix.sh rollback --php 8.3
```

Rollback re-enables the stock `imagick` module and reloads FPM. The private
build stays in `/opt/heic-ios18-fix`, so `enable` can restore it without
rebuilding.

To remove the fix completely after rolling back every version:

```bash
sudo rm -rf /opt/heic-ios18-fix
sudo rm -f /etc/php/8.3/mods-available/imagick-heic.ini
```

`rollback` already removes the enabled symlinks; deleting the `mods-available`
file only removes the leftover definition.

---

## Commands

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

Options:

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

`check` exit codes:

| Code | Meaning |
| --- | --- |
| 0 | OK — HEIC support is present and no known problem was found. |
| 1 | AFFECTED — at least one PHP version is on an old libheif or fails to decode. |
| 2 | ERROR — a probe failed (missing binary, broken extension, …). |
| 3 | NO IMAGICK — no checked PHP version has Imagick. |

`check -q` line format, suitable for monitoring:

```text
php8.3 status=affected imagick=3.8.1 imagemagick=6 heic=yes libheif=1.17.6 decode=fail
php8.3 status=ok imagick=3.8.1 imagemagick=7 heic=yes libheif=1.23.7 decode=ok
```

---

## How it works

### Prefix layout

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

Nothing here shadows or replaces `/usr/lib`. The system ImageMagick 6 and
libheif remain available to other software.

### Per-PHP-version extension

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

### The GD / SONAME trap (why a normal prefix build breaks)

PHP's GD extension (`gd.so`) is linked against the **system** `libheif.so.1`.
On Debian/Ubuntu, `gd.so` is loaded before `imagick.so`, so the dynamic linker
already has `libheif.so.1` mapped. When the new Imagick or ImageMagick asks for
`libheif.so.1`, the linker reuses the system library — regardless of `rpath` or
`LD_LIBRARY_PATH`. Depending on versions you get:

* `undefined symbol: heif_has_compatible_filetype`, or
* silent use of the old libheif, so iOS 18 files still fail.

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
dependency graph wins over the already-loaded system library. `verify` proves
it: the test suite loads `gd.so` (which links the system libheif) and also runs
a second verification with the system `libheif.so.1` in `LD_PRELOAD`.

```text
libheif-heicfix.so.1     => /opt/heic-ios18-fix/heif/lib/libheif-heicfix.so.1
libde265-heicfix.so.0    => /opt/heic-ios18-fix/heif/lib/libde265-heicfix.so.0
```

### Realistic pre-flight

Before enabling anything, `php` builds a temporary `PHP_INI_SCAN_DIR` from the
site's real FPM `conf.d` (or CLI `conf.d` if FPM is absent), removes stock
imagick entries, adds the new extension, and runs PHP with that scan directory.
GD is therefore loaded exactly as it is in production.

`php -n` is deliberately **not** used: it skips GD and hides the SONAME bug.

The pre-flight must report:

* `gd=yes` (or a warning if GD is genuinely not installed),
* Imagick loaded,
* `ImageMagick 7`,
* HEIC listed by `Imagick::queryFormats("HEI*")`,
* a successful sample decode when a sample is present.

If any check fails, the extension is not enabled.

### needrestart

A plain `apt install` once triggered needrestart on a production GridPane
server; it restarted nginx, MariaDB and every php-fpm pool. Every apt call in
this script runs with:

```bash
NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1 DEBIAN_FRONTEND=noninteractive apt-get ...
```

### Versions

`versions.conf` pins libheif, because that is the component verified against
the failing iOS 18 sample. The other components default to `latest` and are
resolved from stable git tags with `git ls-remote`:

| Component | Default | Resolution |
| --- | --- | --- |
| libheif | `v1.23.7` | pinned, verified |
| libde265 | `latest` | newest `vX.Y[.Z]` tag |
| ImageMagick | `latest` | newest `7.x.y-z` tag |
| Imagick | `latest` | newest `X.Y.Z` tag |
| Minimum libheif | `1.18.2` | build refuses anything older |

At the time of the 1.0.0 test these resolved to libde265 **v1.1.3**,
ImageMagick **7.1.2-34** and Imagick **3.8.1**.

Override with environment variables:

```bash
sudo LIBHEIF_VERSION=v1.23.7 IMAGEMAGICK_VERSION=7.1.2-34 ./heic-fix.sh build -y
```

The minimum is enforced even when you override, because libheif below 1.18.2
does not implement the iOS 18 `tmap` handling.

---

## Platforms

| Platform | Detection | Build | PHP/FPM wiring |
| --- | --- | --- | --- |
| Generic Debian/Ubuntu | default | yes | `/etc/php/X.Y`, `phpdismod`/`phpenmod`, `phpX.Y-fpm` |
| GridPane | `/usr/local/bin/gp` or `/opt/gridpane` | yes | same as generic, with update warnings |
| RunCloud | `/RunCloud` | yes | **not implemented** — `TODO(runcloud)` |

### Ubuntu 26.04 and newer

No build is needed. libheif 1.21.2 already contains the iOS 18 fix; install
`libheif-plugin-libde265` for the HEVC decoder. See
[docs/platforms/ubuntu-26.04.md](docs/platforms/ubuntu-26.04.md).

### GridPane

GridPane is detected automatically. The code path is the same as generic
Debian/Ubuntu because GridPane uses the standard `/etc/php/X.Y` layout.

GridPane stack and security updates may reinstall packages or re-enable the
stock imagick module. They do not delete `/opt/heic-ios18-fix`, so after an
update:

```bash
sudo ./heic-fix.sh check
sudo ./heic-fix.sh verify --php 8.3
```

If the stock module came back, `enable --php X.Y` restores the private build.
See [docs/platforms/gridpane.md](docs/platforms/gridpane.md).

### RunCloud

RunCloud uses non-standard PHP paths (`/RunCloud/Packages/phpXXrc/bin/php`,
`/etc/phpXXrc/conf.d`, `phpXXrc-fpm`). Building the libraries works, but the
PHP extension wiring refuses to guess and explains what is missing. Every path
that needs verification is marked `TODO(runcloud)` in the script. See
[docs/platforms/runcloud.md](docs/platforms/runcloud.md).

---

## After updates and monitoring

Re-run `check` (or `verify`) after:

* platform stack updates (GridPane; RunCloud PHP wiring is still
  `TODO(runcloud)`),
* security updates that touch ImageMagick, libheif, PHP or GD,
* PHP upgrades (a new PHP version needs its own Imagick build),
* restoring a server snapshot.

Cron, monit and alerting examples are in
[monitoring/README.md](monitoring/README.md). The short version:

```bash
/opt/heic-ios18-fix/heic-fix.sh check -q --php 8.3 || echo "HEIC check failed"
```

`check -q` prints one line per PHP version and uses the exit code for health;
the cron wrapper in the monitoring file is silent on success.

---

## Test sample

The repository is designed to carry one real failing photo:

```text
samples/failure_magick.HEIC
  iPhone 15 Pro Max, iOS 18.0
  1,132,750 bytes
  Brands: mif1, MiHB, MiHE, MiPr, miaf, heic, tmap
```

Rules:

1. Move it as **raw bytes**. Photos, Messages and email re-encode HEIC and
   destroy the evidence.
2. The file contains GPS EXIF. Before committing, strip location metadata:

   ```bash
   exiftool -gps:all= -overwrite_original samples/failure_magick.HEIC
   ```

3. Update `samples/SHA256SUMS` after stripping.
4. The script compares the sample against `samples/SHA256SUMS` and reports:

   * `verified` — checksum matches; decode results are trusted.
   * `unverified` — no entry (for example a freshly generated test file).
   * `altered` — checksum differs; decode results are **ignored**.

Until the real photo is added, `samples/SHA256SUMS` contains a placeholder line.
`samples/README.md` explains how to generate a synthetic HEIC for tests without
the real sample.

---

## Alternatives

If this build is not a good fit:

1. **Convert at upload.** Run the isolated `heif-dec` from
   `/opt/heic-ios18-fix/heif/bin/heif-dec` and hand the resulting JPEG/PNG to
   ImageMagick. This still needs this build (or another libheif ≥ 1.18.2), but
   avoids rebuilding Imagick.
2. **Decode in the browser** with a client-side WASM HEIC decoder before upload.
   No server changes, but every client pays the download and CPU cost.
3. **Rely on iOS auto-conversion.** iOS can convert HEIC to JPEG when exporting
   through some paths, but the Files app and many upload pickers keep the
   original HEIC. It is not reliable enough to depend on.
4. **Upgrade the distro** to one that ships libheif ≥ 1.18.2. That is the clean
   long-term fix, but it is usually a much larger change than this script.

---

## Testing

The repository includes a container test that runs the whole flow on a clean
Ubuntu image:

```bash
docker run --rm -v "$PWD:/repo:ro" -e PHP_VER=8.3 ubuntu:24.04 \
    bash /repo/tests/run-container-test.sh
```

It installs PHP (ondrej PPA, with a distro fallback), `php-imagick` and
`php-gd`, generates a HEIC with the system `heif-enc`, then runs:

```text
check (AFFECTED) → build → build again (idempotent) → php → verify
→ rollback (AFFECTED) → enable → check/verify (OK)
```

It asserts that `ldd` resolves both private SONAMEs inside the prefix and that
GD is loaded. GitHub Actions runs shellcheck plus the same test on Ubuntu 22.04
and 24.04.

Ubuntu 26.04 takes the native path instead; it is documented in
[docs/platforms/ubuntu-26.04.md](docs/platforms/ubuntu-26.04.md) and was
validated there without building anything.

`shellcheck -x heic-fix.sh tests/*.sh` must be clean.

---

## Safety notes

* `build`, `php`, `enable` and `rollback` must run as root.
* Every system-changing step prompts unless `-y` is given.
* Nothing under `/usr` is replaced.
* Rollback is per PHP version and immediate: stock imagick is re-enabled and
  FPM is reloaded.
* Keep a snapshot on production before the first run.
* The script has been validated in containers; run it on a test server before
  the first client server.

---

## References

* libheif #1190 — iOS 18 on iPhone 15 Pro: <https://github.com/strukturag/libheif/issues/1190>
  (fixed in v1.18.2, commit `58ba30d`; `4d76bae` suggested as a 1.17.x cherry-pick)
* libheif #1213 — More iOS 18 format trouble: <https://github.com/strukturag/libheif/issues/1213>
* libheif PR #1503 — Add support for handling `tmap` items: <https://github.com/strukturag/libheif/pull/1503>
* libheif releases: <https://github.com/strukturag/libheif/releases>
* ImageMagick discussion #7426: <https://github.com/ImageMagick/ImageMagick/discussions/7426>
* libvips #4169: <https://github.com/libvips/libvips/issues/4169>
* imagor #527: <https://github.com/cshum/imagor/issues/527>
* pillow_heif #249: <https://github.com/bigcat88/pillow_heif/issues/249>
* immich #10464: <https://github.com/immich-app/immich/issues/10464>
* FileConverter #493: <https://github.com/Tichau/FileConverter/issues/493>
* GridPane community: <https://community.gridpane.com/t/heic-in-imagemagick/5037>

---

## License

MIT © Managing WP. See [LICENSE](LICENSE).
