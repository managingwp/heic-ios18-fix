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

## 20261028-wordpress-heic-monitor
- 2026-10-28T15:05:04Z | phase 9 | pass | 4f812f9 | `find wordpress-plugin/heic-fix-monitor -type f -name '*.php' -print0 \| xargs -0 -n1 php -l` | No syntax errors detected in wordpress-plugin/heic-fix-monitor/uninstall.php
- 2026-10-28T15:05:04Z | phase 9 | pass | 4f812f9 | `if command -v phpcs >/dev/null 2>&1 && phpcs -i 2>/dev/null \| grep -q WordPress; then phpcs --standard=wordpress-plugin/heic-fix-monitor/phpcs.xml.dist wordpress-plugin/heic-fix-monitor; fi`
- 2026-10-28T15:05:04Z | phase 9 | pass | 4f812f9 | `python3 -c 'import glob,json; [json.load(open(p)) for p in glob.glob("wordpress-plugin/heic-fix-monitor/tests/fixtures/*.json")]'`
- 2026-10-28T15:05:05Z | phase 9 | pass | 4f812f9 | `php -d memory_limit=512M wordpress-plugin/heic-fix-monitor/tests/run-probe-tests.php` | PASS: runs the allowlisted check binary with fixed arguments
- 2026-10-28T15:05:06Z | phase 9 | pass | 4f812f9 | `sh wordpress-plugin/heic-fix-monitor/tests/run-fixture-tests.sh` | PASS: a material evidence change alters the fingerprint
- 2026-10-28T15:05:06Z | phase 9 | pass | 4f812f9 | `bash wordpress-plugin/heic-fix-monitor/bin/static-scan.sh` | PASS: static safety scan (29 runtime files)
- 2026-10-28T15:05:28Z | phase 9 | pass | 4f812f9 | `docker compose -f wordpress-plugin/heic-fix-monitor/tests/docker-compose.yml up -d --wait db wordpress db-ms wordpress-ms` |  Container tests-wordpress-ms-1  Healthy
- 2026-10-28T15:05:43Z | phase 9 | pass | 4f812f9 | `docker compose -f wordpress-plugin/heic-fix-monitor/tests/docker-compose.yml run --rm --entrypoint sh cli /var/www/html/wp-content/plugins/heic-fix-monitor/tests/run-wp-tests.sh` | All WordPress tests passed
- 2026-10-28T15:06:59Z | phase 9 | pass | 4f812f9 | `docker compose -f wordpress-plugin/heic-fix-monitor/tests/docker-compose.yml run --rm --entrypoint sh cli-ms /var/www/html/wp-content/plugins/heic-fix-monitor/tests/run-multisite-tests.sh` | All multisite tests passed
- 2026-10-28T15:07:03Z | phase 9 | pass | 4f812f9 | `find wordpress-plugin/heic-fix-monitor -type f -name '*.php' -print0 \| xargs -0 -n1 php -l` | No syntax errors detected in wordpress-plugin/heic-fix-monitor/uninstall.php
- 2026-10-28T15:07:03Z | phase 9 | pass | 4f812f9 | `if command -v phpcs >/dev/null 2>&1 && phpcs -i 2>/dev/null \| grep -q WordPress; then phpcs --standard=wordpress-plugin/heic-fix-monitor/phpcs.xml.dist wordpress-plugin/heic-fix-monitor; fi`
- 2026-10-28T15:07:03Z | phase 9 | pass | 4f812f9 | `python3 -c 'import glob,json; [json.load(open(p)) for p in glob.glob("wordpress-plugin/heic-fix-monitor/tests/fixtures/*.json")]'`
- 2026-10-28T15:07:04Z | phase 9 | pass | 4f812f9 | `php -d memory_limit=512M wordpress-plugin/heic-fix-monitor/tests/run-probe-tests.php` | PASS: runs the allowlisted check binary with fixed arguments
- 2026-10-28T15:07:04Z | phase 9 | pass | 4f812f9 | `sh wordpress-plugin/heic-fix-monitor/tests/run-fixture-tests.sh` | PASS: a material evidence change alters the fingerprint
- 2026-10-28T15:07:04Z | phase 9 | pass | 4f812f9 | `bash wordpress-plugin/heic-fix-monitor/bin/static-scan.sh` | PASS: static safety scan (29 runtime files)
- 2026-10-28T15:07:22Z | phase 9 | pass | 4f812f9 | `docker compose -f wordpress-plugin/heic-fix-monitor/tests/docker-compose.yml up -d --wait db wordpress db-ms wordpress-ms` |  Container tests-wordpress-1  Healthy
- 2026-10-28T15:07:37Z | phase 9 | pass | 4f812f9 | `docker compose -f wordpress-plugin/heic-fix-monitor/tests/docker-compose.yml run --rm --entrypoint sh cli /var/www/html/wp-content/plugins/heic-fix-monitor/tests/run-wp-tests.sh` | All WordPress tests passed
- 2026-10-28T15:07:41Z | phase 9 | pass | 4f812f9 | `bash wordpress-plugin/heic-fix-monitor/bin/build-zip.sh` | built /home/openchamber/git/heic-ios18-fix/wordpress-plugin/heic-fix-monitor/artifacts/heic-fix-monitor.zip (36 files)
- 2026-10-28T15:07:41Z | phase 9 | pass | 4f812f9 | `python3 wordpress-plugin/heic-fix-monitor/tests/assert-zip-contents.py wordpress-plugin/heic-fix-monitor/artifacts/heic-fix-monitor.zip` | PASS: 36 entries, rooted, minimal
- 2026-10-28T15:08:04Z | phase 9 | pass | 4f812f9 | `docker compose -f wordpress-plugin/heic-fix-monitor/tests/docker-compose.yml up -d --wait db-zip wordpress-zip` |  Container tests-wordpress-zip-1  Healthy
- 2026-10-28T15:08:12Z | phase 9 | pass | 4f812f9 | `docker compose -f wordpress-plugin/heic-fix-monitor/tests/docker-compose.yml run --rm --entrypoint sh cli-zip /hfm-tests/run-zip-install-tests.sh` | All zip install tests passed
- 2026-10-28T15:08:12Z | phase 9 | pass | 4f812f9 | `sha256sum -c samples/SHA256SUMS` | samples/failure_magick.HEIC: OK
- 2026-10-28T15:08:13Z | phase 9 | pass | 4f812f9 | `git diff --check`
- 2026-10-28T15:08:17Z | phase 9 | pass | 4f812f9 | `npm run build` | [INFO] Use `npm run serve` command to test your build locally.
- 2026-10-28T15:08:20Z | phase 9 | pass | 4f812f9 | `docker compose -f wordpress-plugin/heic-fix-monitor/tests/docker-compose.yml down -v` |  Volume tests_wp_data  Removed

## 20261028-plugin-release-distribution
- 2026-10-28T18:51:45Z | phase 4 | pass | ee7724d | `python3 wordpress-plugin/heic-fix-monitor/tests/test-release.py` | All release helper tests passed
- 2026-10-28T18:51:45Z | phase 4 | pass | ee7724d | `python3 -c 'import xml.etree.ElementTree as E; E.parse("wordpress-plugin/heic-fix-monitor/phpcs.xml.dist")'`
- 2026-10-28T18:51:45Z | phase 4 | pass | ee7724d | `python3 wordpress-plugin/heic-fix-monitor/bin/release.py check --tag heic-monitor-v0.1.0` | ok: heic-monitor-v0.1.0 matches plugin header, HFM_VERSION and Stable tag
- 2026-10-28T18:51:45Z | phase 4 | fail | ee7724d | `python3 wordpress-plugin/heic-fix-monitor/bin/release.py prepare 0.1.1 --dry-run` | exit 1: error: working tree is not clean; commit or stash changes first
- 2026-10-28T18:51:45Z | phase 4 | pass | ee7724d | `git diff --exit-code -- wordpress-plugin/heic-fix-monitor/heic-fix-monitor.php wordpress-plugin/heic-fix-monitor/readme.txt`
- 2026-10-28T18:51:46Z | phase 4 | pass | ee7724d | `python3 wordpress-plugin/heic-fix-monitor/tests/test-release-workflow.py` | All release workflow assertions passed
- 2026-10-28T18:51:47Z | phase 4 | pass | ee7724d | `docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:latest .github/workflows/release-plugin.yml`
- 2026-10-28T18:51:47Z | phase 4 | pass | ee7724d | `node --test tests/plugin-releases.test.mjs` | # duration_ms 132.188737
- 2026-10-28T18:51:51Z | phase 4 | pass | ee7724d | `npm run build` | [INFO] Use `npm run serve` command to test your build locally.
- 2026-10-28T18:51:52Z | phase 4 | pass | ee7724d | `docker run --rm -v "$PWD:/input" lycheeverse/lychee:latest --offline --no-progress /input/README.md /input/docs` | 🔍 87 Total (in 8ms) 🔗 31 Unique ✅ 68 OK 🚫 0 Errors 👻 19 Excluded
- 2026-10-28T18:53:27Z | phase 4 | pass | ee7724d | `bash wordpress-plugin/heic-fix-monitor/tests/ci-plugin.sh` | PASS: plugin CI complete
- 2026-10-28T18:53:41Z | phase 4 | pass | 6c6f2c7 | `tmp=$(mktemp -d); git clone -q . "$tmp/repo"; python3 wordpress-plugin/heic-fix-monitor/bin/release.py prepare 0.1.1 --dry-run --root "$tmp/repo/wordpress-plugin/heic-fix-monitor"; rc=$?; rm -rf "$tmp"; exit $rc` | would create tag heic-monitor-v0.1.1 after commit
- 2026-10-28T18:53:41Z | phase 4 | pass | 6c6f2c7 | `bash wordpress-plugin/heic-fix-monitor/bin/build-zip.sh` | built /home/openchamber/git/heic-ios18-fix/wordpress-plugin/heic-fix-monitor/artifacts/heic-fix-monitor.zip (36 files)
- 2026-10-28T18:53:41Z | phase 4 | pass | 6c6f2c7 | `python3 wordpress-plugin/heic-fix-monitor/tests/assert-zip-contents.py wordpress-plugin/heic-fix-monitor/artifacts/heic-fix-monitor.zip` | PASS: 36 entries, rooted, minimal
- 2026-10-28T18:53:42Z | phase 4 | pass | 6c6f2c7 | `sha256sum -c samples/SHA256SUMS` | samples/failure_magick.HEIC: OK
- 2026-10-28T18:53:42Z | phase 4 | pass | 6c6f2c7 | `git diff --check`
- 2026-10-28T18:54:46Z | phase 4 | pass | dd7d15c | `python3 wordpress-plugin/heic-fix-monitor/tests/test-release-workflow.py` | All release workflow assertions passed
- 2026-10-28T18:54:47Z | phase 4 | pass | dd7d15c | `docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:latest .github/workflows/release-plugin.yml`

- 2026-10-29T08:32:47Z | phase 4 | pass | ff485c6 | `python3 wordpress-plugin/heic-fix-monitor/bin/release.py check --tag heic-monitor-v0.1.1` | ok: heic-monitor-v0.1.1 matches plugin header, HFM_VERSION and Stable tag
- 2026-10-29T08:32:47Z | phase 4 | pass | ff485c6 | `tmp=$(mktemp -d); git clone -q . "$tmp/repo"; python3 wordpress-plugin/heic-fix-monitor/bin/release.py prepare 0.1.2 --dry-run --root "$tmp/repo/wordpress-plugin/heic-fix-monitor"; rc=$?; rm -rf "$tmp"; exit $rc` | would create tag heic-monitor-v0.1.2 after commit
## 20261028-docs-local-search
- 2026-10-28T19:25:12Z | phase 4 | pass | 9b19b8b | `npm ci` | Run `npm audit` for details.
- 2026-10-28T19:25:22Z | phase 4 | pass | 9b19b8b | `npm run build` | [INFO] Use `npm run serve` command to test your build locally.
- 2026-10-28T19:25:22Z | phase 4 | pass | 9b19b8b | `bash scripts/check-docs-search.sh` | OK: local search index looks correct
- 2026-10-28T19:25:22Z | phase 4 | pass | 9b19b8b | `node -p "require('./package-lock.json').packages['node_modules/@easyops-cn/docusaurus-search-local'].version"` | 0.55.3
