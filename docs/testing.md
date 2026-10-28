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
- a docs build and link check for the GitHub Pages site.

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

## Docs build

From the repository root:

```bash
npm install
npm run build
npm run serve
```

The development server is at `http://localhost:3000/heic-ios18-fix/`. The
production output is `build/`; `build/index.html` must exist.
