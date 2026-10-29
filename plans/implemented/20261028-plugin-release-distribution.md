# 20261028-plugin-release-distribution

**Version:** 5
**Status:** ✅ Implemented (2026-10-29)
**Type:** feature

## Plan Contract

- **Mission:** Publish independently versioned, WordPress-installable HEIC Fix Monitor ZIPs as GitHub Release assets, with a public download page that always selects the newest stable plugin release and never confuses plugin releases with installer releases.
- **Execution Size:** large — execute all phases in order in one continuous request, with a blocking validation and pushed commit after each phase; stop before the public first-release gate.
- **Scope:**
  - In scope: plugin-scoped semantic version tooling; `heic-monitor-vX.Y.Z` tags; a tag-triggered release workflow that tests, packages, checksums, and publishes the existing WordPress ZIP; release notes with install and verification steps; a Docusaurus download page that resolves the newest stable plugin release; documentation links; workflow and release-selection tests.
  - Out of scope: changing the installer or repository release version; using `git-squash-release` for the plugin; automatic WordPress dashboard updates or a self-hosted update server; publishing to WordPress.org; committing generated ZIPs; using the repository-wide `releases/latest` URL; retagging or force-pushing a published plugin version; publishing before the existing staging gate passes.
- **Success Metrics:**
  - `heic-monitor-vX.Y.Z` is the only release trigger; installer tags and drafts/prereleases are ignored.
  - The workflow fails unless the plugin header, `HFM_VERSION`, and readme `Stable tag` agree with the tag and every plugin test passes.
  - A published release exposes `heic-fix-monitor.zip` and `heic-fix-monitor.zip.sha256` at a stable, version-specific public URL.
  - The ZIP still has exactly one top-level `heic-fix-monitor/` directory and installs through Plugins → Add New → Upload Plugin.
  - The download page selects the highest stable plugin version numerically, not the newest repository release, and shows a working fallback when the GitHub API is unavailable.
  - Neither the installer version files nor the repository release tag are modified by plugin releases.
- **Touched paths:** `wordpress-plugin/heic-fix-monitor/**`, `.github/workflows/**`, `src/**`, `tests/plugin-releases.test.mjs`, `README.md`, `docs/**`, `package.json`, `plans/**`
- **Version Target:** none
- **Version ownership note:** The plugin has independent release ownership through its own helper and tag namespace; the repository's `git-squash-release` tooling and installer version remain untouched.
- **Approach / Architectural Fit:** Keep source and packaging in `wordpress-plugin/heic-fix-monitor/` and reuse `bin/build-zip.py`. Add one Python version helper that owns the three plugin metadata fields and validates tags. A tag-triggered GitHub Actions workflow runs the existing plugin suite, builds and checksums the ZIP, then publishes immutable release assets with the built-in `gh` CLI. The Docusaurus site gains one client-side page that queries only `heic-monitor-v*` releases and selects the highest semantic version.
- **References:** `plans/20261028-wordpress-heic-monitor.md`; `wordpress-plugin/heic-fix-monitor/bin/build-zip.py`; `wordpress-plugin/heic-fix-monitor/tests/assert-zip-contents.py`; `wordpress-plugin/heic-fix-monitor/tests/ci-plugin.sh`; `.github/workflows/ci.yml`; `.github/workflows/deploy-pages.yml`; `docusaurus.config.js`; `README.md`; `docs/monitoring.md`; `wordpress-plugin/heic-fix-monitor/docs/configuration.md`; `plan-generation-skill`; `lmt-skills-development-workflow`; `dependency-supply-chain-security`.

### Decisions to confirm

Defaults are executable; change only if a different audience or release policy is required.

- **Independent tag format:** `heic-monitor-vX.Y.Z`, with a strict three-part numeric version and no prerelease suffix in this scope.
- **Release gate:** pushing an annotated tag is the approval. The workflow publishes automatically; it does not create a draft that requires a second approval.
- **Asset names:** `heic-fix-monitor.zip` and `heic-fix-monitor.zip.sha256`. The tag identifies the version; filenames stay constant so documentation does not churn.
- **Public discovery:** a `/plugin-download/` page selects the newest stable plugin release. Version-specific links remain usable; there is no repository-wide “latest” plugin URL.
- **First published version:** `0.1.1`. The `heic-monitor-v0.1.0` tag was created before the release workflow could pass; it has no release object and was superseded rather than moved. `0.1.1` contains the WPCS and release-pipeline repairs.
- **Checksum scope:** SHA-256 over the ZIP asset only, written with a basename-only path.
- **Release notes:** a workflow-generated template describing install, compatibility, checksum verification, and the read-only nature of the plugin; no auto-generated commit list that mixes installer history.

## Skills Used

- **Creation:** `plan-generation-skill` (v5 contract, phases, validations, and test ledger); `lmt-skills-development-workflow` (sync, per-phase commits, push, inclusive terminology, and version ownership); `dependency-supply-chain-security` (no generated artifacts in Git, minimal ZIP, built-in `gh` CLI over third-party release actions).
- **Implementation:** none yet.

## Phases

### Phase 1: Fix plugin tooling and add version ownership

**Goal:** Make the plugin's development tooling valid and give the plugin one authoritative, independently scoped semantic version command.

#### Tasks

- [x] Rewrite `wordpress-plugin/heic-fix-monitor/phpcs.xml.dist` as standalone XML by removing the leading PHP open tag and docblock; keep the WordPress ruleset, exclusions, and PHP 8.0+ test version.
- [x] Add `wordpress-plugin/heic-fix-monitor/bin/release.py` with `check` and `prepare` subcommands. `check --tag heic-monitor-vX.Y.Z` must parse the plugin header, `HFM_VERSION`, and readme `Stable tag`, require all three to match the tag, and reject malformed tags.
- [x] Implement `prepare X.Y.Z [--dry-run]` to synchronize those three plugin metadata fields through targeted replacements, require a clean working tree, refuse an existing `heic-monitor-vX.Y.Z` tag, and refuse a version that is not greater than the highest existing plugin tag. The command must never commit, tag, push, or publish.
- [x] Compare only `heic-monitor-v*` tags with semantic-version ordering; ignore `jekyll-docs-backup`, installer tags, and any non-plugin tag.
- [x] Add `wordpress-plugin/heic-fix-monitor/tests/test-release.py` covering matching and mismatched metadata, malformed and duplicate tags, version ordering such as `0.1.10 > 0.1.9`, dirty-tree refusal, dry-run leaving files byte-identical, and installer files remaining untouched.
- [x] Add `wordpress-plugin/heic-fix-monitor/docs/releasing.md` with the contributor sequence: confirm staging gate, run `prepare`, update the readme changelog, commit, run checks, create and push the annotated tag; add `docs/releasing.md` to `.distignore` and to the ZIP assertion's forbidden list.
- [x] **Validation:** `python3 wordpress-plugin/heic-fix-monitor/tests/test-release.py` exits 0; `python3 -c 'import xml.etree.ElementTree as E; E.parse("wordpress-plugin/heic-fix-monitor/phpcs.xml.dist")'` exits 0; `python3 wordpress-plugin/heic-fix-monitor/bin/release.py check --tag heic-monitor-v0.1.1` exits 0; `prepare 0.1.1 --dry-run` leaves `git diff --exit-code` clean.

**Commit:** `c7c7400` — `feat: add plugin-scoped release versioning`



### Phase 2: Publish plugin releases from tags

**Goal:** Test, package, checksum, and publish one immutable GitHub Release when a plugin tag is pushed, without touching installer releases.

#### Tasks

- [x] Add `.github/workflows/release-plugin.yml` triggered only by pushed `heic-monitor-v*` tags, with `contents: write` restricted to the publish job, a tag-scoped concurrency group, and a checkout of the exact tagged commit.
- [x] In the build job, install PHP 8.2 with sodium/zip and WPCS, run `python3 bin/release.py check --tag "${GITHUB_REF_NAME}"`, execute `tests/ci-plugin.sh`, build the ZIP, and write `heic-fix-monitor.zip.sha256` with a basename-only path.
- [x] Upload the ZIP and checksum as build artifacts; the publish job downloads them and creates the release with `gh release create`, `--latest=false`, a generated `--notes-file`, and both assets. Never use `--clobber`, force-push, or retag a published version.
- [x] Make publication rerunnable: if the release exists as a draft, upload assets to that draft and publish it; if the release is already published, fail with a clear message instead of replacing assets.
- [x] Generate release notes from the tag and plugin metadata: version, WordPress/PHP minimums, install steps, SHA-256 verification command, read-only behavior summary, and a link to the configuration and troubleshooting pages.
- [x] Add `wordpress-plugin/heic-fix-monitor/tests/test-release-workflow.py` using the Python standard library to assert the tag filter, exact-tag checkout, version check, test invocation, checksum basename, `--latest=false`, both asset names, and absence of `--clobber`.
- [x] **Validation:** `python3 wordpress-plugin/heic-fix-monitor/tests/test-release-workflow.py` exits 0; `docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:latest .github/workflows/release-plugin.yml` exits 0; `python3 wordpress-plugin/heic-fix-monitor/bin/release.py check --tag heic-monitor-v0.1.1` still exits 0.

**Commit:** `11c9ae5` — `ci: publish plugin releases from tags`

### Phase 3: Add the public plugin download page

**Goal:** Give visitors one stable page that finds the newest stable plugin release and links to its ZIP and checksum.

#### Tasks

- [x] Add `src/lib/pluginReleases.mjs` with strict tag parsing, semantic-version comparison, draft/prerelease filtering, required-asset filtering, paginated GitHub release collection, and a `selectLatestPluginRelease()` function.
- [x] Add `src/pages/plugin-download.jsx` using the Docusaurus `Layout`; show loading, no-release, and API-error states with a direct GitHub Releases fallback link. Never present an unverified release as installable.
- [x] Render the selected version, publication date, ZIP link, checksum link, and release-notes link. Include the install steps and the warning that this is not an automatic WordPress update.
- [x] Add `tests/plugin-releases.test.mjs` using `node:test` for pagination, installer-only releases, missing assets, drafts, prereleases, malformed tags, numeric ordering including `0.1.10 > 0.1.9`, and API failure.
- [x] Add a `test:plugin-releases` script to `package.json`; do not change the package version.
- [x] Link `/plugin-download/` from the navbar or footer, `README.md`, `docs/monitoring.md`, and the plugin configuration page using the published Pages URL.
- [x] **Validation:** `node --test tests/plugin-releases.test.mjs` exits 0; `npm run build` exits 0; `docker run --rm -v "$PWD:/input" lycheeverse/lychee:latest --offline --no-progress /input/README.md /input/docs` reports zero errors; the built page contains the `/plugin-download/` route.

**Commit:** `fabf67d` — `docs: add public plugin download page`

### Phase 4: Testing & Verification

**Goal:** Prove the version helper, workflow definition, download selector, WordPress package, and documentation work together before the first public tag.

#### Tasks

- [x] Run the release-helper tests, workflow assertions, download-selector tests, plugin CI, ZIP assertion, sample checksum, and Docusaurus build.
- [x] Confirm a dry-run prepare changes no files and that the installer version sources are untouched.
- [x] Confirm the workflow cannot be triggered by installer tags, drafts, or prereleases and that it never targets the repository-wide latest release.
- [x] **USER-ONLY / PUBLICATION — EXECUTED FOR THE REPAIR:** Publish the first installable plugin release. The failed `heic-monitor-v0.1.0` tag was left in place without a release; `heic-monitor-v0.1.1` was tagged from the repaired commit and published by the release workflow on 2026-10-29.
- [x] **Outstanding user gate:** Verified `0.1.1` publicly: anonymous download and SHA-256 check passed, the ZIP installed and uninstalled in a clean WordPress, and the live download selector resolves `heic-monitor-v0.1.1`.
- [x] **Validation:** every runnable command below exits 0; the dry run leaves the tree unchanged; no installer or repository version field changes; the publication gate remains unchecked until the user supplies evidence.


**Automated verification (2026-10-28):** all 15 `How to Test` commands pass: release
helper tests, XML parse, tagged-metadata check, clean-clone dry run, unchanged
metadata, workflow assertions, Actionlint, nine release-selector tests,
Docusaurus build, offline link audit, full plugin CI (including disposable
single-site and multisite WordPress, ZIP install, and static scan), ZIP
assertion, sample checksum, and whitespace check. The first published release
is `heic-monitor-v0.1.1`; the earlier `0.1.0` tag has no release object. The
public asset download, checksum, clean-site install, and live selector were
verified after publication.

#### How to Test

Prerequisites: Bash 4+, PHP 8.0+ with sodium and zip, Python 3, Node.js 20+, Docker with Compose, and network access for the container pulls. No tag is created and no release is published by these commands.

```bash
python3 wordpress-plugin/heic-fix-monitor/tests/test-release.py
python3 -c 'import xml.etree.ElementTree as E; E.parse("wordpress-plugin/heic-fix-monitor/phpcs.xml.dist")'
python3 wordpress-plugin/heic-fix-monitor/bin/release.py check --tag heic-monitor-v0.1.1
tmp=$(mktemp -d); git clone -q . "$tmp/repo"; python3 wordpress-plugin/heic-fix-monitor/bin/release.py prepare 0.1.2 --dry-run --root "$tmp/repo/wordpress-plugin/heic-fix-monitor"; rc=$?; rm -rf "$tmp"; exit $rc
git diff --exit-code -- wordpress-plugin/heic-fix-monitor/heic-fix-monitor.php wordpress-plugin/heic-fix-monitor/readme.txt
python3 wordpress-plugin/heic-fix-monitor/tests/test-release-workflow.py
docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:latest .github/workflows/release-plugin.yml
node --test tests/plugin-releases.test.mjs
npm run build
docker run --rm -v "$PWD:/input" lycheeverse/lychee:latest --offline --no-progress /input/README.md /input/docs
bash wordpress-plugin/heic-fix-monitor/tests/ci-plugin.sh
bash wordpress-plugin/heic-fix-monitor/bin/build-zip.sh
python3 wordpress-plugin/heic-fix-monitor/tests/assert-zip-contents.py wordpress-plugin/heic-fix-monitor/artifacts/heic-fix-monitor.zip
sha256sum -c samples/SHA256SUMS
git diff --check
```

Expected: the helper tests and workflow assertions exit 0; the dry run changes nothing; the plugin suite, ZIP assertion, and sample checksum pass; the Docusaurus build and offline link audit pass; Actionlint reports no workflow errors; the ZIP still contains one top-level `heic-fix-monitor/` directory. The public tag, release, anonymous download, and clean-site install remain user-only.

#### Manual / UI Checks

1. After publishing the tag, open the release page while signed out and confirm both assets are present and downloadable.
2. Open `/plugin-download/` while signed out and confirm it selects `0.1.1`, not an installer release, and both links download the expected files.
3. Install the downloaded ZIP through Plugins → Add New → Upload Plugin in a clean WordPress and confirm activation, settings, and `wp heic-monitor check --format=json` succeed.
4. Verify `sha256sum -c heic-fix-monitor.zip.sha256` beside the downloaded ZIP exits 0.

**Commit:** `close-out commit` — `test: verify plugin release distribution`
