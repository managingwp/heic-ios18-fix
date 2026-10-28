# Tests — heic-ios18-fix

Baseline: 2026-10-28

## 20261028-fix-gd-libheif-symbol-collision
- 2026-10-28T12:59:40Z | phase 4 | pass | 2afbcf1 | `shellcheck -x heic-fix.sh tests/*.sh` | shellcheck clean
- 2026-10-28T12:59:40Z | phase 4 | pass | 2afbcf1 | `git diff --check` | no whitespace errors
- 2026-10-28T12:59:40Z | phase 4 | pass | 2afbcf1 | `sha256sum -c samples/SHA256SUMS` | sample checksum OK
- 2026-10-28T12:59:40Z | phase 4 | pass | 2afbcf1 | `docker run --rm -v "$PWD:/repo:ro" -e PHP_VER=8.3 -e JOBS=4 ubuntu:22.04 bash /repo/tests/run-container-test.sh` | All container tests passed (PHP 8.3, 22.04)
- 2026-10-28T12:59:40Z | phase 4 | pass | 2afbcf1 | `docker run --rm -v "$PWD:/repo:ro" -e PHP_VER=8.5 -e JOBS=4 ubuntu:22.04 bash /repo/tests/run-container-test.sh` | All container tests passed (PHP 8.5, 22.04); GD-first decode ok
- 2026-10-28T12:59:40Z | phase 4 | pass | 2afbcf1 | `docker run --rm -v "$PWD:/repo:ro" -e PHP_VER=8.3 -e JOBS=4 ubuntu:24.04 bash /repo/tests/run-container-test.sh` | All container tests passed (PHP 8.3, 24.04)
- 2026-10-28T12:59:41Z | phase 4 | pass | 2afbcf1 | `npm ci` | npm ci clean
- 2026-10-28T12:59:41Z | phase 4 | pass | 2afbcf1 | `npm run build` | Docusaurus build succeeded
- 2026-10-28T12:59:41Z | phase 4 | pass | 2afbcf1 | `docker run --rm -v "$PWD:/input" lycheeverse/lychee:latest --offline --no-progress /input/README.md /input/docs` | 82 links, 0 errors
