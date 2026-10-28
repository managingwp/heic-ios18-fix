#!/usr/bin/env bash
#
# Plugin CI: lint, tests, safety scan, disposable WordPress, zip assertion.
#
set -Eeuo pipefail

ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT"

find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
echo "PASS: PHP lint"

python3 -c 'import glob,json; [json.load(open(p)) for p in glob.glob("tests/fixtures/*.json")]'
echo "PASS: fixture JSON parses"

if command -v phpcs >/dev/null 2>&1 && phpcs -i 2>/dev/null | grep -q WordPress; then
	phpcs --standard=phpcs.xml.dist .
else
	echo "SKIP: PHPCS/WordPress standard is not installed"
fi

php -d memory_limit=512M tests/run-probe-tests.php
sh tests/run-fixture-tests.sh
bash bin/static-scan.sh

compose=(docker compose -f tests/docker-compose.yml)
"${compose[@]}" down -v >/dev/null 2>&1 || true
"${compose[@]}" up -d --wait db wordpress db-ms wordpress-ms
"${compose[@]}" run --rm --entrypoint sh cli /var/www/html/wp-content/plugins/heic-fix-monitor/tests/run-wp-tests.sh
"${compose[@]}" run --rm --entrypoint sh cli-ms /var/www/html/wp-content/plugins/heic-fix-monitor/tests/run-multisite-tests.sh
"${compose[@]}" down -v

bash bin/build-zip.sh
python3 tests/assert-zip-contents.py artifacts/heic-fix-monitor.zip

echo "PASS: plugin CI complete"
