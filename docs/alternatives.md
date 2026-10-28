---
title: Alternatives
description: Other ways to handle iOS 18 HEIC uploads.
---

[← Docs home](index.md)

# Alternatives

If this build is not a good fit:

1. **Convert at upload.** Run the isolated `heif-dec` from
   `/opt/heic-ios18-fix/heif/bin/heif-dec` and hand the resulting JPEG/PNG to
   ImageMagick. This still needs this build (or another libheif ≥ 1.18.2), but
   avoids rebuilding Imagick.

   ```bash
   /opt/heic-ios18-fix/heif/bin/heif-dec --quiet photo.heic photo.jpg
   ```

2. **Decode in the browser** with a client-side WASM HEIC decoder before
   upload. No server changes, but every client pays the download and CPU cost.

3. **Rely on iOS auto-conversion.** iOS can convert HEIC to JPEG when exporting
   through some paths, but the Files app and many upload pickers keep the
   original HEIC. It is not reliable enough to depend on.

4. **Upgrade the distribution** to one that ships libheif ≥ 1.18.2. Ubuntu
   26.04 is the native option; install `libheif-plugin-libde265` for HEVC
   decoding. This is the clean long-term fix, but it is usually a much larger
   change than this script.
