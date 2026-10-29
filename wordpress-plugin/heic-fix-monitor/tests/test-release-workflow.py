#!/usr/bin/env python3
"""Static assertions for the plugin release workflow."""

from __future__ import annotations

import re
import sys
from pathlib import Path

WORKFLOW = (
    Path(__file__).resolve().parents[3]
    / ".github"
    / "workflows"
    / "release-plugin.yml"
)
FAILURES = 0


def check(condition: bool, label: str) -> None:
    """Record one assertion."""
    global FAILURES
    if condition:
        print(f"PASS: {label}")
        return
    FAILURES += 1
    print(f"FAIL: {label}")


def main() -> int:
    """Inspect the workflow text."""
    text = WORKFLOW.read_text(encoding="utf-8")

    check(
        bool(re.search(r"tags:\s*\n\s*-\s*'heic-monitor-v\*'", text)),
        "workflow triggers only on plugin tags",
    )
    check("pull_request_target" not in text, "workflow does not use pull_request_target")
    check(
        "ref: ${{ inputs.tag || github.ref_name }}" in text,
        "checkout uses the exact tagged ref, including manual dispatch",
    )
    check("workflow_dispatch:" in text and "inputs.tag" in text, "manual dispatch accepts an existing tag")
    check("fetch-depth: 0" in text, "checkout fetches tags and history")
    check("contents: read" in text, "default token is read-only")
    check(text.count("contents: write") == 1, "only the publish job can write contents")
    check(
        "release.py check" in text and '--tag "$TAG"' in text,
        "workflow validates the plugin tag against metadata",
    )
    check(
        "allow-plugins.dealerdirect/phpcodesniffer-composer-installer true" in text,
        "WPCS Composer plugin is explicitly allowed",
    )
    check(
        "phpcs --standard=phpcs.xml.dist --runtime-set ignore_warnings_on_exit 1 ." in text,
        "release CI requires WPCS errors while allowing advisory warnings",
    )
    check("tests/ci-plugin.sh" in text, "workflow runs the plugin suite")
    check(
        "sha256sum heic-fix-monitor.zip > heic-fix-monitor.zip.sha256" in text,
        "checksum uses the asset basename only",
    )
    check("gh release create" in text, "workflow creates a GitHub Release")
    check("--verify-tag" in text, "release creation verifies the tag exists")
    check("--latest=false" in text, "plugin release is never repository latest")
    check("--clobber" not in text, "workflow never replaces published assets")
    check("isDraft" in text and "already published" in text, "published releases are immutable")
    check("GH_REPO: ${{ github.repository }}" in text, "publish job has explicit repository context")
    check(
        "release-assets/heic-fix-monitor.zip" in text
        and "release-assets/heic-fix-monitor.zip.sha256" in text,
        "both ZIP and checksum are attached",
    )
    check(
        text.count("actions/checkout@v4") == 1,
        "only the build job checks out source",
    )
    check("permissions:\n      contents: write" in text, "write permission is job-scoped")

    if FAILURES:
        print(f"\n{FAILURES} failure(s)", file=sys.stderr)
        return 1

    print("\nAll release workflow assertions passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
