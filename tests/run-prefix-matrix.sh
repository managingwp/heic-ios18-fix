#!/usr/bin/env bash
#
# Run the disposable container matrix for the shared prefix, one case at a time.
#
# Every case is bounded: two CPUs, six GB of memory and no swap, so a build
# spike dies inside the container instead of on the host. Containers are named
# for this project, labelled with the project path, and recorded in
# .opencode/docker-instances.log so they can always be traced back here.
#
# Usage:
#   tests/run-prefix-matrix.sh              # all three cases, sequentially
#   CPU_LIMIT=2 MEM_LIMIT=6g JOBS=2 tests/run-prefix-matrix.sh
#   MATRIX="22.04 8.3" tests/run-prefix-matrix.sh
#
# Launching this script builds container images and runs compiles, so it needs
# explicit approval first (see AGENTS.md).
#
# Copyright (c) 2026 Managing WP
# SPDX-License-Identifier: MIT

set -Eeuo pipefail

REPO=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
CPU_LIMIT=${CPU_LIMIT:-2}
MEM_LIMIT=${MEM_LIMIT:-6g}
JOBS=${JOBS:-2}
MATRIX=${MATRIX:-"22.04 8.3
22.04 8.5
24.04 8.3"}
LOG_DIR="$REPO/.opencode"
LOG_FILE="$LOG_DIR/docker-instances.log"

fail() {
    printf '\nFAIL: %s\n' "$*" >&2
    exit 1
}

started_names=()

cleanup() {
    local name
    for name in "${started_names[@]}"; do
        docker stop "$name" >/dev/null 2>&1 || true
        printf '%s %s stopped %s (interrupted)\n' \
            "$(date -Iseconds)" "$name" "$(date -Iseconds)" >>"$LOG_FILE" || true
    done
}

trap cleanup EXIT

command -v docker >/dev/null || fail "docker is not installed"
docker info >/dev/null 2>&1 || fail "the docker daemon is not reachable"

mkdir -p "$LOG_DIR"

printf 'ceiling: %s cpus, %s memory (no swap), %s jobs\n' \
    "$CPU_LIMIT" "$MEM_LIMIT" "$JOBS" >&2

case_count=0
while read -r ubuntu php; do
    [[ -z ${ubuntu:-} ]] && continue
    case_count=$((case_count + 1))

    name="oc-heic-ios18-fix-prefix-u${ubuntu//./}-p${php//./}"
    printf '\n=== Ubuntu %s / PHP %s (%s) ===\n' "$ubuntu" "$php" "$name" >&2

    started_names+=("$name")
    printf '%s %s shared-prefix-e2e\n' "$(date -Iseconds)" "$name" >>"$LOG_FILE"

    docker run --rm --name "$name" \
        --cpus="$CPU_LIMIT" --memory="$MEM_LIMIT" --memory-swap="$MEM_LIMIT" \
        --label "opencode.project=$REPO" \
        --label "opencode.started=$(date -Iseconds)" \
        -v "$REPO:/repo:ro" \
        -e PHP_VER="$php" -e JOBS="$JOBS" \
        "ubuntu:$ubuntu" bash /repo/tests/run-container-test.sh

    printf '%s %s stopped %s\n' \
        "$(date -Iseconds)" "$name" "$(date -Iseconds)" >>"$LOG_FILE"
done <<<"$MATRIX"

(( case_count > 0 )) || fail "the matrix is empty"

if docker ps --filter "label=opencode.project=$REPO" --format '{{.Names}}' | grep -q .; then
    docker ps --filter "label=opencode.project=$REPO" >&2
    fail "project containers are still running"
fi

printf '\nAll %d prefix matrix cases passed; no project containers remain.\n' "$case_count"
