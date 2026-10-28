---
title: Samples
description: The real iPhone HEIC sample, checksums and synthetic test files.
layout: default
---

[← Docs home](index.md) · [Testing](testing.md)

# Samples

The repository carries one real failing photo at:

```text
samples/failure_magick.HEIC
```

## Current sample

| Property | Value |
| --- | --- |
| Device | iPhone 15 Pro |
| iOS | 18.0 |
| Size | 3,151,842 bytes |
| Dimensions | 4032x3024 |
| Brands | `tmap`, `heic`, `mif1` |
| SHA256 | `4b887e6bc345191cbb9836602373b3ec454558ccc62d3518041e085117238fe0` |
| Source | public attachment in [libheif #1190](https://github.com/strukturag/libheif/issues/1190) (`IMG_1159.zip`) |

This is the public iPhone 15 Pro / iOS 18.0 sample from the libheif issue, used
as a **substitute** for the original BTN sample. It reproduces the failure on
Ubuntu 22.04 with libheif 1.12.0:

```text
Could not read HEIF/AVIF file:
Invalid input: Unspecified: Too many auxiliary image references
```

Ubuntu 24.04's patched libheif 1.17.6 decodes this particular file, so the
production proof is the Ubuntu 22.04 container test. If the original BTN photo
becomes available, replace this file and update the metadata and checksum. Do
not re-encode it.

The raw bytes matter. Photos, Messages, email and upload services re-encode
HEIC and remove the `tmap` item and auxiliary images that trigger the bug.

## Checksum states

`heic-fix.sh` compares the sample with `samples/SHA256SUMS`:

| State | Meaning | Decode results |
| --- | --- | --- |
| `verified` | checksum matches | used |
| `unverified` | no entry, or the placeholder | used |
| `altered` | checksum differs | **ignored** |
| `missing` | file not present | decode skipped |

The file and checksums live in the repository:

- `samples/failure_magick.HEIC`
- `samples/SHA256SUMS`
- [SHA256SUMS on GitHub](https://github.com/managingwp/heic-ios18-fix/blob/main/samples/SHA256SUMS)

## GPS and metadata

This public sample has no GPS tags. If you replace it with a photo that does,
strip location metadata and update the checksum:

```bash
exiftool -gps:all= -overwrite_original samples/failure_magick.HEIC
sha256sum samples/failure_magick.HEIC > samples/SHA256SUMS
```

Check the result before committing:

```bash
exiftool -gps:all samples/failure_magick.HEIC
```

## Generate a synthetic test HEIC

CI and local tests generate a normal HEIC with the system encoder:

```bash
# Ubuntu 22.04/24.04
sudo apt-get install -y imagemagick libheif-examples libheif-plugin-x265

convert -size 640x480 gradient:'#2050a0-#f0c040' /tmp/input.png
heif-enc -b 8 -q 80 -o /tmp/test.heic /tmp/input.png
heif-info /tmp/test.heic
```

On Ubuntu 26.04, omit `-b 8` for an 8-bit input; the newer encoder rejects it.

A generated file does **not** reproduce the iOS 18 `tmap` bug, but it proves the
new libheif and ImageMagick can decode HEIC end to end, including with GD
loaded.

## Why the real sample matters

The synthetic file exercises the normal HEVC path. The real iOS 18 photo
exercises the `tmap` and auxiliary-image handling added in libheif 1.18.2. Keep
the real sample in the repository so future changes are checked against the
actual failure, not just a happy-path HEIC.
