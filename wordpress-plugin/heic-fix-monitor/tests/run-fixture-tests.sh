#!/usr/bin/env sh
#
# Runs the fixture-driven state and transition tests without WordPress.
#
set -eu

cd "$(dirname "$0")/.."
php -d memory_limit=512M tests/run-fixture-tests.php
