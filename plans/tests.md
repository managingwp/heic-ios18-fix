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
