---
title: Plugin releases
description: Download the HEIC Fix Monitor WordPress plugin and follow its independent versioning and release workflow.
---

[← Docs home](index.md) · [Monitoring](monitoring.md) · [Testing](testing.md)

# Plugin releases

The HEIC Fix Monitor is a standalone WordPress plugin distributed independently
from the `heic-fix.sh` installer. It monitors the iOS 18 HEIC/Imagick repair and
alerts on regression without changing the server.

## Download and install

Use the [plugin download page](https://managingwp.github.io/heic-ios18-fix/plugin-download/).
It resolves the newest stable `heic-monitor-v*` release and links both the ZIP
and its SHA-256 checksum.

1. Download `heic-fix-monitor.zip`.
2. In WordPress, open **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP, activate it, then open **Settings → HEIC Fix Monitor**.
4. Configure PHP versions, paths, recipients and channels. Prefer
   `wp-config.php` constants for webhook secrets.

The ZIP contains one top-level `heic-fix-monitor/` directory, so WordPress
installs it directly. It is not the repository source archive.

Verify the download before installing:

```bash
sha256sum -c heic-fix-monitor.zip.sha256
```

Expected: `heic-fix-monitor.zip: OK`

Updating with a newer ZIP replaces the plugin files and keeps settings and
monitor history. Uninstalling removes the plugin options, transients and
scheduled events; it never changes the private prefix, PHP configuration or the
sample.

## Independent versioning

The plugin has its own semantic version and release cadence:

| Item | Value |
| --- | --- |
| Tag format | `heic-monitor-vX.Y.Z` |
| Release asset | `heic-fix-monitor.zip` |
| Checksum asset | `heic-fix-monitor.zip.sha256` |
| Version fields | plugin header, `HFM_VERSION`, readme `Stable tag` |
| Trigger | pushing a plugin tag |

Only plugin changes bump the plugin version. Installer releases and the root
`CHANGELOG.md` are separate and are not changed by a plugin release.

Do not use the repository-wide `releases/latest` URL: the latest repository
release may belong to the installer. Always use the
[plugin download page](https://managingwp.github.io/heic-ios18-fix/plugin-download/)
or a version-specific URL:

```text
https://github.com/managingwp/heic-ios18-fix/releases/download/heic-monitor-vX.Y.Z/heic-fix-monitor.zip
```

## Release workflow

Maintainers release from the repository root after the staging/live-channel
gate passes.

### 1. Synchronize the plugin version

```bash
python3 wordpress-plugin/heic-fix-monitor/bin/release.py prepare X.Y.Z --dry-run
python3 wordpress-plugin/heic-fix-monitor/bin/release.py prepare X.Y.Z
```

The helper updates only the three plugin metadata fields. It refuses a dirty
tree, an existing plugin tag, a malformed plugin tag, and a version that is not
greater than the highest plugin tag. It never commits, tags or publishes.

Add the matching `= X.Y.Z =` section to the plugin `readme.txt` changelog.

### 2. Verify the package

```bash
python3 wordpress-plugin/heic-fix-monitor/bin/release.py check --tag heic-monitor-vX.Y.Z
bash wordpress-plugin/heic-fix-monitor/tests/ci-plugin.sh
bash wordpress-plugin/heic-fix-monitor/bin/build-zip.sh
python3 wordpress-plugin/heic-fix-monitor/tests/assert-zip-contents.py \
  wordpress-plugin/heic-fix-monitor/artifacts/heic-fix-monitor.zip
```

Commit the reviewed plugin files. Do not commit generated ZIPs.

### 3. Tag and publish

Pushing the annotated tag is the publication approval:

```bash
git tag -a heic-monitor-vX.Y.Z -m "HEIC Fix Monitor X.Y.Z"
git push origin heic-monitor-vX.Y.Z
```

The `Release plugin` workflow then:

1. checks out the tagged commit;
2. validates the tag against all three metadata fields;
3. runs WordPress Coding Standards and the full plugin suite;
4. builds `heic-fix-monitor.zip` and `heic-fix-monitor.zip.sha256`;
5. publishes a GitHub Release with `--latest=false` and both assets;
6. refuses to replace assets on a release that is already published.

The workflow is defined in
[`.github/workflows/release-plugin.yml`](https://github.com/managingwp/heic-ios18-fix/blob/main/.github/workflows/release-plugin.yml).

### 4. Verify the public release

While signed out, download the release asset and checksum, verify them, and
install the ZIP in a clean WordPress:

```bash
curl -fL -o /tmp/heic-fix-monitor.zip \
  https://github.com/managingwp/heic-ios18-fix/releases/download/heic-monitor-vX.Y.Z/heic-fix-monitor.zip
curl -fL -o /tmp/heic-fix-monitor.zip.sha256 \
  https://github.com/managingwp/heic-ios18-fix/releases/download/heic-monitor-vX.Y.Z/heic-fix-monitor.zip.sha256
(cd /tmp && sha256sum -c heic-fix-monitor.zip.sha256)
unzip -t /tmp/heic-fix-monitor.zip
```

Then confirm activation, the settings pages, and:

```bash
wp heic-monitor check --format=json
```

## Failure and rollback policy

- A failed workflow leaves the tag and draft for inspection. Fix forward with a
  new patch version.
- Never move or force-push a published tag.
- Never replace a published release asset. `gh release upload --clobber` is
  forbidden.
- Correct release notes without retagging.
- Deleting a published release or tag requires explicit user approval.

## Related

- [Plugin download page](https://managingwp.github.io/heic-ios18-fix/plugin-download/)
- [Plugin configuration](https://github.com/managingwp/heic-ios18-fix/blob/main/wordpress-plugin/heic-fix-monitor/docs/configuration.md)
- [Monitoring](monitoring.md)
- [Troubleshooting](troubleshooting.md)
- [Testing](testing.md)
