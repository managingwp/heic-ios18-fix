---
title: Testing
description: Container tests, CI and the real iOS 18 sample proof.
---

[← Docs home](index.md) · [Samples](samples.md)

# Testing

## Container test

`tests/run-container-test.sh` exercises the whole flow on a clean Ubuntu image:

```bash
docker run --rm -v "$PWD:/repo:ro" -e PHP_VER=8.3 ubuntu:24.04 \
    bash /repo/tests/run-container-test.sh
```

It installs PHP (ondrej PPA, with a distribution fallback), `php-imagick` and
`php-gd`, generates a synthetic HEIC with the system `heif-enc`, then runs:

```text
check (AFFECTED) → build → build again (idempotent) → php → verify
→ verify with system libheif preloaded → rollback (AFFECTED)
→ enable → check/verify (OK)
```

## Site-user access tests

PHP-FPM and WP-CLI do not run as root, so the container test also proves the
runtime is usable by an ordinary account:

- every runtime path under the prefix is root owned, not group/other writable,
  and readable/traversable by a non-owner, including in-prefix `ldd`
  dependencies;
- two accounts with different primary groups decode
  `samples/failure_magick.HEIC` with Imagick alone and with `gd.so` loaded first;
- an unreadable default prefix is refused by `enable` instead of being enabled.

`tests/check-default-prefix.sh` covers the surrounding behaviour without a
build: the documented default, an explicit `--prefix`, exactly one legacy hint
for a `/opt` installation, silence when the caller chose a prefix, and that
neither fixture is modified. Its `--audit` mode fails when a file still
mentions the legacy prefix outside the documented migration and
backwards-compatibility allowlist.

```bash
bash tests/check-default-prefix.sh --audit
```

## Bounded matrix

`tests/run-prefix-matrix.sh` runs the three CI combinations one after another
with a CPU and memory ceiling, records each container in
`.opencode/docker-instances.log`, and fails if a project container is still
running:

```bash
CPU_LIMIT=2 MEM_LIMIT=4g JOBS=2 bash tests/run-prefix-matrix.sh
```

The default ceiling is 2 CPUs and 6 GB with matching `--memory-swap`, so a
build spike is killed inside the container rather than on the host.

Two samples are mandatory. `SAMPLE` is the generated fixture; the checked-in
`samples/failure_magick.HEIC` is always used for the collision check, because a
generated HEIC decodes on the old libheif and cannot prove the iOS 18 fix.

The collision check decodes the checked-in sample three ways — private
ImageMagick CLI, Imagick with `-n`, and `gd.so` loaded before `imagick.so` — and
then asserts the ELF and runtime binding contract:

- `libMagickCore-7` has no shared libheif or libde265 dependency;
- `heif_context_alloc`, `heif_context_read_from_file` and `heif_context_free`
  are embedded and not exported in `.dynsym`;
- with GD loaded first, no `libMagickCore → libheif` binding appears in
  `LD_DEBUG=bindings`, while GD still binds its own calls to the system copy.

## Real iPhone sample

The repository includes `samples/failure_magick.HEIC`. On Ubuntu 22.04, the
pre-fix system libheif fails on it, so the same test can use the real file:

```bash
docker run --rm -v "$PWD:/repo:ro" \
    -e PHP_VER=8.5 \
    -e SAMPLE=/repo/samples/failure_magick.HEIC \
    ubuntu:22.04 bash /repo/tests/run-container-test.sh
```

The collision check always uses this file, even when `SAMPLE` points at the
generated fixture.

Expected: `check` reports AFFECTED (system libheif 1.12.0), the private build
decodes the file, and `verify` passes.

Ubuntu 24.04's patched libheif 1.17.6 decodes this particular public sample, so
the "expect AFFECTED" assertion does not hold there. Use the synthetic test on
24.04, or replace the sample with the original BTN photo that reproduces on that
server. The collision check is still enforced: it exercises the library binding,
not the sample's age.

Run the test with another version or job count:

```bash
docker run --rm -v "$PWD:/repo:ro" \
    -e PHP_VER=8.2 -e JOBS=4 \
    ubuntu:24.04 bash /repo/tests/run-container-test.sh
```

## GitHub Actions

`.github/workflows/ci.yml` runs:

- shellcheck against `heic-fix.sh` and `tests/*.sh`;
- the container test on Ubuntu 22.04 with PHP 8.3 and 8.5, and on Ubuntu 24.04
  with PHP 8.3;
- a docs build and link check for the GitHub Pages site;
- a plugin job: release-helper and release-selector tests, WPCS, fixtures,
  disposable single-site and multisite WordPress, and a clean-site ZIP install.

The tag-triggered plugin release workflow is documented in
[Plugin releases](plugin-releases.md).

## Shellcheck

```bash
shellcheck -x heic-fix.sh tests/*.sh
```

The script must stay clean. The build steps are written for `bash`, not `sh`.

## What the test proves

- `check` detects the old system libheif and reports AFFECTED.
- The private build installs libheif 1.23.7 and ImageMagick 7.1.x.
- The pre-flight loads GD from the real FPM `conf.d` and still gets
  ImageMagick 7 HEIC support.
- `libMagickCore` carries no shared libheif dependency and exports no `heif_*`
  symbols, so GD cannot capture ImageMagick's calls.
- CLI and FPM both report ImageMagick 7.
- Rollback restores stock imagick; enable restores the private extension.
- A second `build` is idempotent.
- The installed runtime is readable by two unprivileged accounts, and an
  unreadable prefix is refused.

## Docs build

From the repository root:

```bash
npm install
npm run build
npm run serve
```

The development server is at `http://localhost:3000/heic-ios18-fix/`. The
production output is `build/`; `build/index.html` must exist.
