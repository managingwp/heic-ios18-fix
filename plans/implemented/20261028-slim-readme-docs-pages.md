# 20261028-slim-readme-docs-pages

**Version:** 5
**Status:** ✅ Implemented (2026-10-28)
**Type:** feature

## Plan Contract

- **Mission:** Make the repository sample real and verifiable, reduce the root README to a short entry point, and move the detailed material into a GitHub Pages-ready `docs/` site.
- **Execution Size:** large — phases run back to back.
- **Scope:**
  - In scope: add and verify `samples/failure_magick.HEIC`; slim `README.md`; move detail into `docs/`; add Jekyll/GitHub Pages configuration and navigation; update links and directory pointers; add a CI docs build; update `CHANGELOG.md` and `.gitignore`.
  - Out of scope: changing installer behavior in `heic-fix.sh` beyond link/help references; new platform hooks; changing the sample format or re-encoding it; enabling GitHub Pages in the repository settings.
- **Success Metrics:**
  - `samples/failure_magick.HEIC` exists, is a raw iOS 18 photo, has no GPS EXIF, matches `samples/SHA256SUMS`, and `check`/`verify` decode it successfully through the private ImageMagick 7 stack.
  - The container test passes with `SAMPLE=/repo/samples/failure_magick.HEIC` on Ubuntu 22.04: AFFECTED before the fix, decode OK after. Ubuntu 24.04 uses the synthetic sample because its patched libheif decodes this public substitute.
  - `README.md` is at most 130 lines and contains only the overview, quick start, compact command summary, platform summary, sample status, docs link and license.
  - Every detailed section removed from the README exists in a `docs/` page.
  - `docs/` builds with Jekyll (GitHub Pages source `/docs`) with no errors, and internal links resolve.
  - `shellcheck -x heic-fix.sh tests/*.sh` is clean and GitHub Actions is green.
- **Touched paths:** `README.md`, `CHANGELOG.md`, `.gitignore`, `docs/**`, `samples/**`, `monitoring/**`, `.github/workflows/ci.yml`, `tests/**`, `plans/**`
- **Version Target:** release-assigned (git-squash-release)
- **Approach / Architectural Fit:** Keep all executable assets at the repository root (`heic-fix.sh`, `versions.conf`, `tests/`, `samples/`). Treat `docs/` as the GitHub Pages site root, with `docs/_config.yml`, `docs/index.md`, front matter on every page and relative Markdown links handled by `jekyll-relative-links`. Keep `samples/README.md` and `monitoring/README.md` as short pointers so existing paths do not become dead ends.
- **References:** `README.md`, `samples/README.md`, `monitoring/README.md`, `docs/platforms/*.md`, `docs/troubleshooting.md`, `tests/run-container-test.sh`, `plan-generation-skill`.

### Decisions to confirm

- **Sample source:** the original BTN file was not supplied. The public attachment from libheif issue #1190 (`IMG_1159`) was used as a substitute because it reproduces the failure on Ubuntu 22.04; it is labelled as such. It has no GPS tags. The original can replace it later with a checksum update.
- **Pages source:** assume GitHub Pages is configured as “Deploy from a branch”, branch `main`, folder `/docs`. No Pages deployment workflow is added; only a docs build check in CI.
- **Theme:** `jekyll-theme-cayman` with `jekyll-relative-links`; change only if the user prefers another GitHub Pages-supported theme.

## Skills Used

- **Creation:** `plan-generation-skill` (PGF format, phase order, validation and final testing contract).
- **Implementation:** `plan-generation-skill` (PGF execution, phase tracking and close-out).

## Phases

### Phase 1: Secure and verify the real HEIC sample

**Goal:** Put a real failing iOS 18 photo in `samples/`, prove its integrity, and prove it fails on the old stack and decodes on the new one.

The original BTN file was not available. Use the public libheif #1190 sample only because it reproduces the Ubuntu 22.04 failure; label it as a substitute. Do not use a photo that has been re-encoded by Photos, Messages or email, and do not use the synthetic CI sample as `failure_magick.HEIC`.

#### Tasks

- [x] Obtain a raw file. The public `IMG_1159.HEIC` from libheif issue #1190 was used as a substitute; the original BTN file remains a future replacement.
- [x] Inspect the public attachment and confirm it reproduces the failure on Ubuntu 22.04/libheif 1.12.0 and decodes on the private libheif 1.23.7 build.
- [x] Confirm metadata: iPhone 15 Pro, iOS 18.0, 4032x3024, 3,151,842 bytes, brands `tmap`, `heic`, `mif1`.
- [x] Confirm no GPS tags; `exiftool -gps:all= -overwrite_original` made no change.
- [x] Update `samples/SHA256SUMS` with the real hash and remove the `PLACEHOLDER` line.
- [x] Update `samples/README.md` and `docs/samples.md` with the actual metadata and substitute note.
- [x] Run the pre/post proof in the Ubuntu 22.04 container with `SAMPLE=/repo/samples/failure_magick.HEIC`; the synthetic test covers 24.04.
- [x] **Validation:** `sha256sum -c samples/SHA256SUMS` passes; `heif-info` shows `tmap`; the 22.04 container test reports AFFECTED before the fix and decode OK after; 24.04 is documented as decoding this public substitute natively.

**Commit:** `7d8bba7` — `feat: add verified iOS 18 HEIC sample`

### Phase 2: Inventory content and define the docs information architecture

**Goal:** Map every current README/detail section to its new home and agree the slim README outline before rewriting.

#### Tasks

- [x] Inventory the current sections in `README.md`, `samples/README.md`, `monitoring/README.md`, `docs/platforms/*.md` and `docs/troubleshooting.md`.
- [x] Define the README outline: title, one-paragraph problem/solution, quick start, compact commands table, supported platforms, sample status, documentation links, license.
- [x] Define the docs page list:
  - `docs/index.md` — landing and navigation
  - `docs/getting-started.md` — requirements, install, build, `php`, `verify`, rollback
  - `docs/commands.md` — all commands, options and exit codes
  - `docs/how-it-works.md` — prefix layout, per-PHP extension, SONAME/GD trap, pre-flight, needrestart, pinned versions
  - `docs/samples.md` — sample rules, checksums, synthetic test sample
  - `docs/monitoring.md` — cron, monit, exit codes
  - `docs/testing.md` — container tests and CI
  - `docs/alternatives.md` — upload conversion, browser WASM, iOS auto-conversion
  - `docs/references.md` — issue/discussion links
  - existing `docs/platforms/gridpane.md`, `docs/platforms/runcloud.md`, `docs/platforms/ubuntu-26.04.md`, `docs/troubleshooting.md`
- [x] Record a section-to-page mapping table in the plan or a temporary `docs/_migration.md` (remove before close-out).
- [x] **Validation:** every current README section has a named destination; no page is planned twice.

**Commit:** `ef8e85f` — `docs: publish detailed content as a GitHub Pages site`

### Phase 3: Slim the README

**Goal:** Rewrite `README.md` as a short entry point that sends readers to the docs.

#### Tasks

- [x] Rewrite `README.md` to the approved outline; keep it at or below 130 lines.
- [x] Keep the quick-start commands (check, build, php, verify, rollback) and a compact 8-row command summary.
- [x] Keep a short supported-platform summary and a one-line Ubuntu 26.04 node with a docs link.
- [x] Add a “Samples” status block: whether `samples/failure_magick.HEIC` is present, and a link to `docs/samples.md`.
- [x] Add a “Documentation” section linking to `docs/index.md` and, once Pages is enabled, the Pages URL.
- [x] Remove symptoms, distro table, detailed options, how-it-works, needrestart, monitoring, alternatives and references from the README; they move in Phase 4.
- [x] **Validation:** `wc -l README.md` is ≤130; all README links resolve; no detailed section is duplicated in both README and docs.

**Commit:** `4ecfd83` — `docs: slim README to a docs entry point`

### Phase 4: Build the GitHub Pages docs site and move the detail

**Goal:** Move all removed detail into `docs/`, make the site navigable, and configure it for GitHub Pages.

#### Tasks

- [x] Add `docs/_config.yml` with title, description, `theme: jekyll-theme-cayman`, `markdown: kramdown`, and the `jekyll-relative-links` plugin with `relative_links.enabled: true`.
- [x] Write `docs/index.md` with front matter, a short landing page, and links to every docs page.
- [x] Create `docs/getting-started.md`, `docs/commands.md`, `docs/how-it-works.md`, `docs/samples.md`, `docs/monitoring.md`, `docs/testing.md`, `docs/alternatives.md`, `docs/references.md` with front matter and a “Docs home” link.
- [x] Move the relevant content from the current README into those pages without losing the exact error strings, command options and exit codes.
- [x] Move `monitoring/README.md` content to `docs/monitoring.md`; leave `monitoring/README.md` as a short pointer.
- [x] Move `samples/README.md` content to `docs/samples.md`; leave `samples/README.md` as a short pointer, keeping `samples/SHA256SUMS` and the sample in place.
- [x] Update `docs/platforms/gridpane.md`, `docs/platforms/runcloud.md`, `docs/platforms/ubuntu-26.04.md` and `docs/troubleshooting.md` to link to the new pages instead of the old README anchors.
- [x] Add a small `docs/_includes/nav.html` and include it after the front matter on each page, or provide an equivalent consistent nav in `docs/index.md`.
- [x] Add `docs/_site/` and `docs/.jekyll-cache/` to `.gitignore`.
- [x] Add `CHANGELOG.md` entries for the docs site, slim README and sample work.
- [x] **Validation:** every page has front matter and a nav/home link; no old README anchor links remain; content from the old README exists in docs; `docs/` has no duplicate page titles.

**Commit:** `ef8e85f` — `docs: publish detailed content as a GitHub Pages site`

### Phase 5: Add docs build and link validation

**Goal:** Catch broken Pages builds and dead internal links before publishing.

#### Tasks

- [x] Add a `docs-build` job to `.github/workflows/ci.yml` that builds `docs/` with the GitHub Pages Jekyll action. If `actions/jekyll-build-pages@v1` cannot use the `docs` subdirectory, use the official `jekyll/jekyll` container instead.
- [x] Add a local docs build command to `docs/README.md` or the docs index.
- [x] Add a link audit. Prefer a small script or a `lychee` offline check, limited to `docs/` and README-relative links; add it to CI only if it is reliable and fast.
- [x] Confirm the GitHub Pages source assumption in the docs: branch `main`, folder `/docs`; no deploy workflow is added.
- [x] **Validation:** the docs job builds successfully on CI; a local build produces `docs/_site/index.html`; the link audit reports no broken internal links.

**Commit:** `5c6557a` — `ci: build and link-check the docs site`

### Phase 6: Testing & Verification

**Goal:** Prove the sample, README, docs site and existing installer tests all work together.

#### Tasks

- [x] Run shellcheck.
- [x] Build the docs site locally and confirm the landing page and each page render.
- [x] Audit internal links from README and docs.
- [x] Verify the sample hash, metadata and decode results.
- [x] Run the container test with the real sample on Ubuntu 22.04; use the synthetic test on 24.04 because the patched libheif decodes this substitute.
- [x] Confirm the README line budget and that no detailed section is duplicated.
- [x] **Validation:** every command in `How to Test` produces the expected output and CI is green.

#### How to Test

Prerequisites: Docker, `heif-info`, `sha256sum`, `exiftool` (for the sample preparation), and the raw `samples/failure_magick.HEIC` file. The sample is present; the 24.04 real-sample assertion is replaced by the synthetic test because Ubuntu 24.04's patched libheif decodes this public substitute.

```bash
# 1. Sample integrity and metadata
sha256sum -c samples/SHA256SUMS
heif-info samples/failure_magick.HEIC | head -20

# 2. README budget and diff hygiene
test "$(wc -l < README.md)" -le 130
git diff --check

# 3. Build the docs site locally
docker run --rm -v "$PWD/docs:/srv/jekyll" -w /srv/jekyll \
    jekyll/jekyll:4.4.1 \
    bash -c 'bundle install --quiet && bundle exec jekyll build'
test -f docs/_site/index.html

# 4. Link audit (offline, local files)
docker run --rm -v "$PWD:/input" lycheeverse/lychee:latest \
    --offline --no-progress /input/README.md /input/docs

# 5. Shellcheck
shellcheck -x heic-fix.sh tests/*.sh

# 6. End-to-end with the real iOS 18 sample on 22.04
docker run --rm -v "$PWD:/repo:ro" \
    -e PHP_VER=8.3 -e SAMPLE=/repo/samples/failure_magick.HEIC \
    ubuntu:22.04 bash /repo/tests/run-container-test.sh

# 7. Synthetic test on 24.04 (its patched libheif decodes this public substitute)
docker run --rm -v "$PWD:/repo:ro" -e PHP_VER=8.3 \
    ubuntu:24.04 bash /repo/tests/run-container-test.sh
```

Expected:

- `sha256sum -c` reports `samples/failure_magick.HEIC: OK`; `heif-info` shows `heic`/`tmap` metadata.
- README is ≤130 lines; `git diff --check` is silent.
- Jekyll exits 0 and `docs/_site/index.html` exists.
- Lychee reports no broken local links.
- Shellcheck exits 0.
- Both container tests print `All container tests passed`; the 22.04 real-sample run is AFFECTED before the fix and `decode=ok` after enabling.

**Commit:** `close-out commit` — `test: verify docs site and real iOS 18 sample`
