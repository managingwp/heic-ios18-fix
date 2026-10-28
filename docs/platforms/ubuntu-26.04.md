# Ubuntu 26.04 and newer

Ubuntu 26.04 ships a libheif that already contains the iOS 18 fix, so this
repository's build is **not needed** there. The remaining requirement is a
HEVC decoder plugin.

## Versions verified

| Component | Ubuntu 26.04 |
| --- | --- |
| libheif | 1.21.2 |
| ImageMagick | 7.1.2.18 |
| php-imagick | 3.8.0 (PHP 8.5) |
| libde265 | provided by `libheif-plugin-libde265` |

libheif 1.18.2 is the first release that handles the iOS 18 `tmap` item and the
extra auxiliary images. 1.21.2 is well past that, so the original failure does
not reproduce.

## The plugin catch

libheif 1.21 splits codecs into plugins. `libheif1` *Suggests*
`libheif-plugin-libde265` but does not depend on or recommend it. A stock
`apt install imagemagick php-imagick` install therefore has HEIC listed but no
HEVC decoder:

```text
ImagickException: Error while loading plugin:
No decoding plugin installed for this compression format
…
@ error/heic.c/IsHEIFSuccess/202
```

This is a **different** failure from the iOS 18/Tmap errors in the main README.
The metadata is parsed correctly; no plugin can decode the HEVC payload yet.

Install the decoder:

```bash
sudo apt install libheif-plugin-libde265
sudo systemctl reload php8.5-fpm   # use the version your sites run
```

`libheif-plugin-x265` is only needed for **encoding** HEIC. It is not required
for uploads.

## Verify

```bash
# 1. Confirm the package versions
dpkg-query -W -f='libheif1=${Version}\n' libheif1
php8.5 -r 'echo phpversion("imagick"), " ", Imagick::getVersion()["versionString"], "\n";'

# 2. Decode a real HEIC in PHP
php8.5 -r '
  $im = new Imagick("/path/to/photo.heic");
  $im->setImageFormat("jpeg");
  printf("%dx%d, %d JPEG bytes\n", $im->getImageWidth(), $im->getImageHeight(), strlen($im->getImageBlob()));
'
```

Or use the repository checker:

```bash
./heic-fix.sh check --php 8.5 --sample /path/to/photo.heic
```

* `status=ok` — native support is working.
* `status=affected` with the plugin hint — install
  `libheif-plugin-libde265`; do not rebuild.

## In-place upgrades from 24.04

Check what is actually installed before concluding anything:

```bash
dpkg-query -W -f='${Package} ${Version}\n' libheif1 imagemagick php-imagick
```

On 26.04 the expected versions are libheif 1.21.x and ImageMagick 7.1.x. If
libheif is still 1.17.x, the upgrade did not complete (or a PPA/hold is pinning
it):

```bash
sudo apt update
sudo apt full-upgrade
sudo apt install libheif-plugin-libde265
```

Then reload the PHP-FPM service used by the sites.

## Why the GD workaround is not needed

On 22.04/24.04 the system has an old libheif, so this repository builds a
private new one with unique SONAMEs to avoid GD's old `libheif.so.1`.

On 26.04, GD and ImageMagick both use the same system libheif 1.21.2. There is
no old/new collision, no `libheif-heicfix.so.1`, and no reason to install the
prefix.

## If the workaround was already installed on 26.04

Roll it back per PHP version and remove the prefix:

```bash
sudo /opt/heic-ios18-fix/heic-fix.sh rollback --php 8.5
sudo rm -rf /opt/heic-ios18-fix
sudo rm -f /etc/php/8.5/mods-available/imagick-heic.ini
sudo apt install libheif-plugin-libde265
sudo systemctl reload php8.5-fpm
```

## Test evidence

Validated in a clean `ubuntu:26.04` container:

1. Installed `imagemagick`, `libheif-examples`, `libheif-plugin-x265`,
   `php8.5-cli` and `php8.5-imagick`.
2. Generated an 8-bit HEIC with `heif-enc`.
3. Without `libheif-plugin-libde265`:
   `No decoding plugin installed for this compression format`, and PHP Imagick
   threw the same error.
4. After `apt install libheif-plugin-libde265`, `heif-dec` and PHP Imagick
   decoded the file to JPEG (`320x240`).

No build, no SONAME patching, and no PHP extension recompilation was involved.
