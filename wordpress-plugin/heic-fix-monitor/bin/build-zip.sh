#!/usr/bin/env bash
#
# Build the standalone plugin zip.
#
set -Eeuo pipefail

ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
exec python3 "$ROOT/bin/build-zip.py"
