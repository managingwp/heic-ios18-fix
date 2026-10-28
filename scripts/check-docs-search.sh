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
