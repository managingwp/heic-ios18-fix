# Samples

The repository expects one real failing photo at:

```text
samples/failure_magick.HEIC
```

## The real sample

| Property | Value |
| --- | --- |
| Device | iPhone 15 Pro Max |
| iOS | 18.0 |
| Size | 1,132,750 bytes |
| Brands | `mif1`, `MiHB`, `MiHE`, `MiPr`, `miaf`, `heic`, `tmap` |

It must be moved as **raw bytes**. Do not send it through Photos, Messages,
email or any service that re-encodes HEIC; that removes the `tmap` item and the
extra auxiliary images that trigger the bug.

### Strip GPS before committing

The photo carries GPS EXIF. Strip location metadata and update the checksum:

```bash
exiftool -gps:all= -overwrite_original samples/failure_magick.HEIC
sha256sum samples/failure_magick.HEIC > samples/SHA256SUMS
```

Inspect the result before committing:

```bash
exiftool samples/failure_magick.HEIC | grep -i -E 'gps|location'
```

`heif-info samples/failure_magick.HEIC` should still list the `tmap` item.

### Checksum states

`heic-fix.sh` compares the sample with `samples/SHA256SUMS`:

| State | Meaning | Decode results |
| --- | --- | --- |
| `verified` | checksum matches | used |
| `unverified` | no entry, or the placeholder | used |
| `altered` | checksum differs | **ignored** |
| `missing` | file not present | decode skipped |

Until the real sample is added, `samples/SHA256SUMS` contains a `PLACEHOLDER`
line.

## Generate a test HEIC without the real sample

CI and local tests generate a normal HEIC with the system encoder:

```bash
# Ubuntu 22.04/24.04
sudo apt-get install -y imagemagick libheif-examples libheif-plugin-x265

convert -size 640x480 gradient:'#2050a0-#f0c040' /tmp/input.png
heif-enc -q 80 -o /tmp/test.heic /tmp/input.png
heif-info /tmp/test.heic
```

A generated file does **not** reproduce the iOS 18 `tmap` bug, but it proves
that the new libheif and ImageMagick can decode HEIC end to end, including with
GD loaded.

## Why the real sample matters

The synthetic file exercises the normal HEVC path. The real iOS 18 photo
exercises the `tmap` and auxiliary-image handling added in libheif 1.18.2. Keep
the real sample in the repository so future changes can be checked against the
actual failure, not just a happy-path HEIC.
