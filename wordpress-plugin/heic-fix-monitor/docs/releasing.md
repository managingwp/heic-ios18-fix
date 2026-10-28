# Releasing the HEIC Fix Monitor plugin

The plugin has its **own** semantic version and release cadence. Plugin releases
never change the installer version, the repository release tag, or the root
`CHANGELOG.md` version sections.

## Prerequisites

- The staging/live-channel gate in the plugin implementation plan is complete.
- The plugin test suite passes locally.
- The worktree is clean and `main` is pushed.

## 1. Choose the version

Use semantic versioning:

| Change | Example |
| --- | --- |
| Compatible fix | `0.1.1` |
| New compatible feature | `0.2.0` |
| Incompatible change | `1.0.0` |

The first public release is `0.1.0`.

## 2. Synchronize plugin metadata

```bash
python3 wordpress-plugin/heic-fix-monitor/bin/release.py prepare 0.1.1 --dry-run
python3 wordpress-plugin/heic-fix-monitor/bin/release.py prepare 0.1.1
```

The helper updates exactly three fields:

- the plugin header in `heic-fix-monitor.php`
- `HFM_VERSION` in `heic-fix-monitor.php`
- `Stable tag` in `readme.txt`

It refuses a dirty tree, an existing plugin tag, a malformed plugin tag, and a
version that is not greater than the highest existing `heic-monitor-v*` tag.
It never commits, tags, pushes, or publishes.

## 3. Update the plugin changelog

Add a `= X.Y.Z =` section to the `== Changelog ==` block in
`wordpress-plugin/heic-fix-monitor/readme.txt`. Keep the root `CHANGELOG.md`
for repository-level changes.

## 4. Verify and commit

```bash
python3 wordpress-plugin/heic-fix-monitor/bin/release.py check --tag heic-monitor-v0.1.1
bash wordpress-plugin/heic-fix-monitor/tests/ci-plugin.sh
bash wordpress-plugin/heic-fix-monitor/bin/build-zip.sh
python3 wordpress-plugin/heic-fix-monitor/tests/assert-zip-contents.py \
  wordpress-plugin/heic-fix-monitor/artifacts/heic-fix-monitor.zip
```

Commit only the reviewed plugin files. Do not commit `artifacts/`.

## 5. Tag and publish

Pushing the annotated tag is the publication approval:

```bash
git tag -a heic-monitor-v0.1.1 -m "HEIC Fix Monitor 0.1.1"
git push origin heic-monitor-v0.1.1
```

`.github/workflows/release-plugin.yml` then:

1. validates the tag against all three metadata fields;
2. runs the plugin suite;
3. builds `heic-fix-monitor.zip` and `heic-fix-monitor.zip.sha256`;
4. publishes a GitHub Release with `--latest=false`;
5. exposes the assets at:

```text
https://github.com/managingwp/heic-ios18-fix/releases/download/heic-monitor-v0.1.1/heic-fix-monitor.zip
https://github.com/managingwp/heic-ios18-fix/releases/download/heic-monitor-v0.1.1/heic-fix-monitor.zip.sha256
```

The repository-wide “latest” release may belong to the installer. Always link
the plugin download page, not `releases/latest`.

## 6. Verify the published release

While signed out:

```bash
curl -fL -o /tmp/heic-fix-monitor.zip \
  https://github.com/managingwp/heic-ios18-fix/releases/download/heic-monitor-v0.1.1/heic-fix-monitor.zip
curl -fL -o /tmp/heic-fix-monitor.zip.sha256 \
  https://github.com/managingwp/heic-ios18-fix/releases/download/heic-monitor-v0.1.1/heic-fix-monitor.zip.sha256
(cd /tmp && sha256sum -c heic-fix-monitor.zip.sha256)
unzip -t /tmp/heic-fix-monitor.zip
```

Then install the ZIP in a clean WordPress and confirm activation, the settings
pages, and `wp heic-monitor check --format=json`.

## Failure and rollback policy

- A failed workflow leaves the tag and release for inspection. Fix forward with
  a new patch version; never move or force-push a published tag.
- Never replace the asset on a published release. `gh release upload --clobber`
  is forbidden.
- If the release page is wrong but the ZIP is correct, correct the release
  notes; do not retag.
- Deleting a published release or tag is a destructive, user-approved action.

## What not to do

- Do not use `git-squash-release` for the plugin.
- Do not change the installer version or `VERSION` for a plugin release.
- Do not commit generated ZIPs.
- Do not publish a plugin release before the staging gate passes.
- Do not use `https://github.com/managingwp/heic-ios18-fix/releases/latest/download/heic-fix-monitor.zip`.
