# 20261028-docs-local-search

**Version:** 5
**Status:** Not Started
**Type:** feature

## Plan Contract

- **Mission:** Give the heic-ios18-fix Docusaurus site a working offline search index that returns correct `/heic-ios18-fix/...` URLs, plus one command that fails when that wiring drifts.
- **Execution Size:** small — three implementation phases and a final verification phase, each with a blocking validation and a pushed commit.
- **Scope:**
  - In scope: `@easyops-cn/docusaurus-search-local` pinned at 0.55.3; a top-level `themes` block with `docsRouteBasePath: '/'`; a committed lockfile entry; a repo-owned check script; one step in the existing `docs` CI job; user-facing test instructions.
  - Out of scope: Algolia DocSearch, custom search UI or theming, i18n or multi-locale indexes, versioned docs, page restructuring, `trailingSlash` changes, and any edit to the site's page content.
- **Success Metrics:**
  - `npm ci && npm run build` exits 0 and emits `build/search-index.json`.
  - The index contains `/heic-ios18-fix/getting-started` and no `/heic-ios18-fix/docs/` URL.
  - `bash scripts/check-docs-search.sh` exits 0; it exits non-zero when `docsRouteBasePath` is reverted to `'docs'`.
  - The `docs` CI job runs that script, and the deployed site serves the index.
- **Touched paths:** `docusaurus.config.js`, `package.json`, `package-lock.json`, `scripts/check-docs-search.sh`, `.github/workflows/ci.yml`, `plans/20261028-docs-local-search.md`
- **Version Target:** none
- **Version note:** documentation-site plumbing only; no version field is read or written, and installer/plugin versions are owned by their own release plans.
- **Approach / Architectural Fit:** Add the search plugin as a Docusaurus theme beside the existing `classic` preset. The site serves docs at route root (`docs.routeBasePath: '/'`), so `docsRouteBasePath` must be `'/'`; the plugin default `'docs'` indexes URLs that 404 on this project site — that is the regression the plan guards. Settings mirror the docs skill: `hashed: true`, `indexBlog: false`, `language: ['en']`, and a plain module string because the config uses ESM `import`. The guard stays small and repo-owned instead of adopting the full docs-site checker: it asserts the built artifact, not the rendered screen.
- **References:** `docusaurus-docs-migration` (Search section and Phase 7 assertions); `plan-generation-skill` (v5 contract); `lmt-skills-development-workflow` (commit and push per phase); `docusaurus.config.js`; `.github/workflows/ci.yml`; `.github/workflows/deploy-pages.yml`; `docs/index.md`.

## Skills Used

- **Creation:** `plan-generation-skill` (v5 format, blocking validations, final testing phase), `docusaurus-docs-migration` (search wiring, `docsRouteBasePath` pitfall, artifact assertions).
- **Implementation:** none yet.

## Phases

### Phase 1: Pin the plugin and wire the theme

**Goal:** Add the local search dependency and a `themes` block so a build produces an index.

#### Tasks
- [ ] Install pinned: `npm install --save-exact @easyops-cn/docusaurus-search-local@0.55.3`.
- [ ] Add a top-level `themes` key to `docusaurus.config.js` between the `presets` block (ends line 50) and `themeConfig` (line 52):
      `[['@easyops-cn/docusaurus-search-local', { hashed: true, indexBlog: false, language: ['en'], docsRouteBasePath: '/' }]]`
- [ ] Keep the plain module string, not `require.resolve` — the config is written with ESM `import`.
- [ ] Add no `algolia` key: local search and DocSearch are alternatives, and configuring both is ambiguous.
- [ ] Commit `package.json`, `package-lock.json`, and `docusaurus.config.js` together so `npm ci` resolves the same tree.
- [ ] **Validation:** all four commands exit 0.

```bash
node -p "require('./package-lock.json').packages['node_modules/@easyops-cn/docusaurus-search-local'].version" | grep -qx 0.55.3
grep -q "@easyops-cn/docusaurus-search-local" docusaurus.config.js
grep -q "docsRouteBasePath: '/'" docusaurus.config.js
test "$(grep -c 'algolia:' docusaurus.config.js)" -eq 0
```

**Commit:** pending

### Phase 2: Prove the build and add the guard script

**Goal:** Build from a cleared cache, confirm the index carries base-URL-correct entries, and capture the check as a repeatable script.

#### Tasks
- [ ] `npm run clear && npm run build`; record build time from a clean cache and confirm no HTML file exceeds 5 MB.
- [ ] Create `scripts/check-docs-search.sh` with exactly this content, then `chmod +x` it.
- [ ] Confirm the negative case: pointing the script at a config where `docsRouteBasePath` is `'docs'` fails.
- [ ] **Validation:** the four commands below succeed and print `OK`.

```bash
#!/usr/bin/env bash
# Fail when the local search wiring or its built index drifts.
set -Eeuo pipefail

SITE_DIR=${SITE_DIR:-$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)}
CONFIG=${CONFIG:-$SITE_DIR/docusaurus.config.js}
BUILD=${BUILD:-$SITE_DIR/build}
BASE_URL=${BASE_URL:-/heic-ios18-fix/}

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

grep -q "@easyops-cn/docusaurus-search-local" "$CONFIG" || fail "search theme is not configured"
grep -q "docsRouteBasePath: '/'" "$CONFIG" || fail "docsRouteBasePath must mirror docs.routeBasePath ('/')"
[ -f "$BUILD/search-index.json" ] || fail "build/search-index.json is missing; run npm run build"
grep -q "${BASE_URL}getting-started" "$BUILD/search-index.json" || fail "index has no baseUrl-prefixed URLs"
if grep -q "${BASE_URL}docs/" "$BUILD/search-index.json"; then
  fail "index contains ${BASE_URL}docs/ URLs; docsRouteBasePath is wrong"
fi
[ -d "$BUILD/search" ] || fail "the search route was not built"
find "$BUILD/search" -name index.html | grep -q . || fail "the search route has no index.html"

printf 'OK: local search index looks correct\n'
```

```bash
bash -n scripts/check-docs-search.sh
bash scripts/check-docs-search.sh
tmp="$(mktemp)"
sed "s|docsRouteBasePath: '/'|docsRouteBasePath: 'docs'|" docusaurus.config.js > "$tmp"
if CONFIG="$tmp" bash scripts/check-docs-search.sh; then echo "FAIL: guard accepted the wrong route base"; rm -f "$tmp"; exit 1; fi
rm -f "$tmp"
echo "OK"
```

**Commit:** pending

### Phase 3: Run the guard in CI

**Goal:** Make the `docs` job fail on search drift instead of shipping a broken index.

#### Tasks
- [ ] In `.github/workflows/ci.yml`, under the `docs` job after `Build Docusaurus site`, add a step named `Check local search wiring` that runs `bash scripts/check-docs-search.sh`.
- [ ] Do not touch `.github/workflows/deploy-pages.yml`: it already builds and deploys on every push to `main`, so the index reaches production without a workflow change.
- [ ] **Validation:** both commands exit 0 and the script appears exactly once in the workflow.

```bash
grep -q 'name: Check local search wiring' .github/workflows/ci.yml
grep -q 'run: bash scripts/check-docs-search.sh' .github/workflows/ci.yml
test "$(grep -c 'check-docs-search.sh' .github/workflows/ci.yml)" -eq 1
node -p "require('js-yaml').load(require('fs').readFileSync('.github/workflows/ci.yml','utf8')).jobs.docs.steps.some(s=>String(s.run||'').includes('check-docs-search.sh'))" | grep -qx true
```

**Commit:** pending

### Phase 4: Testing & Verification

**Goal:** Prove the published behaviour end to end and give the user one command to re-run it.

#### Tasks
- [ ] Re-run the full gate from a clean dependency tree.
- [ ] Deploy to Pages and confirm the live index is served.
- [ ] **Validation:** every command in `How to Test` produces the expected output.

#### How to Test

Prerequisites: Node 20+, network access for `npm ci`, and a push to `main` that triggers the Pages deploy.

```bash
npm ci
npm run build
bash scripts/check-docs-search.sh
node -p "require('./package-lock.json').packages['node_modules/@easyops-cn/docusaurus-search-local'].version"
```

Expected: the build exits 0; the script prints `OK: local search index looks correct`; the last command prints `0.55.3`.

#### Manual / UI Checks

- [ ] After deploying, `curl -sS -o /dev/null -w '%{http_code}\n' https://managingwp.github.io/heic-ios18-fix/search-index.json` prints `200`.
- [ ] `npm run serve`, then the navbar shows a search box on `/heic-ios18-fix/`.
- [ ] Typing `libheif` lists Getting started and How it works; opening a result keeps `/heic-ios18-fix/` in the URL (no `/docs/`, no 404).

**Commit:** pending
