#!/usr/bin/env python3
"""Tests for the independent plugin release helper."""

from __future__ import annotations

import importlib.util
import subprocess
import sys
import tempfile
from contextlib import contextmanager
from pathlib import Path
from typing import Iterator

RELEASE_PY = Path(__file__).resolve().parent.parent / "bin" / "release.py"
SPEC = importlib.util.spec_from_file_location("hfm_release", RELEASE_PY)
assert SPEC and SPEC.loader
release = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(release)

FAILURES = 0


def check(condition: bool, label: str) -> None:
    """Record one assertion."""
    global FAILURES
    if condition:
        print(f"PASS: {label}")
        return
    FAILURES += 1
    print(f"FAIL: {label}")


def git(repo: Path, *args: str) -> subprocess.CompletedProcess[str]:
    """Run git in a test repository."""
    return subprocess.run(
        ["git", "-C", str(repo), *args],
        check=True,
        capture_output=True,
        text=True,
    )


def cli(*args: str) -> subprocess.CompletedProcess[str]:
    """Run the release CLI."""
    return subprocess.run(
        [sys.executable, str(RELEASE_PY), *args],
        check=False,
        capture_output=True,
        text=True,
    )


def write_plugin(plugin: Path, version: str = "0.1.0") -> None:
    """Write the minimum metadata files."""
    plugin.mkdir(parents=True, exist_ok=True)
    (plugin / "heic-fix-monitor.php").write_text(
        "<?php\n"
        "/**\n"
        " * Plugin Name: HEIC Fix Monitor\n"
        f" * Version:           {version}\n"
        " * Requires PHP:      8.0\n"
        " */\n"
        "define( 'HFM_VERSION', '" + version + "' );\n"
        "define( 'HFM_PLUGIN_DIR', __DIR__ );\n",
        encoding="utf-8",
    )
    (plugin / "readme.txt").write_text(
        "=== HEIC Fix Monitor ===\n"
        "Requires PHP: 8.0\n"
        f"Stable tag: {version}\n"
        "\n"
        "Read-only monitoring.\n",
        encoding="utf-8",
    )


@contextmanager
def repository(version: str = "0.1.0") -> Iterator[tuple[Path, Path]]:
    """Create a disposable git repository with a plugin tree."""
    with tempfile.TemporaryDirectory(prefix="hfm-release-") as temporary:
        repo = Path(temporary)
        plugin = repo / "wordpress-plugin" / "heic-fix-monitor"
        write_plugin(plugin, version)
        (repo / "heic-fix.sh").write_text("#!/usr/bin/env bash\necho installer\n", encoding="utf-8")
        (repo / "VERSION").write_text("1.0.2\n", encoding="utf-8")

        git(repo, "init", "-q")
        git(repo, "config", "user.email", "tests@example.test")
        git(repo, "config", "user.name", "Tests")
        git(repo, "config", "commit.gpgsign", "false")
        git(repo, "add", ".")
        git(repo, "commit", "-q", "-m", "fixture")

        yield repo, plugin


def main() -> int:
    """Run the tests."""
    check(release.parse_tag("heic-monitor-v1.2.3") == (1, 2, 3), "parses a valid tag")
    check(release.parse_tag("heic-monitor-v1.2") is None, "rejects a two-part tag")
    check(release.parse_tag("heic-monitor-v01.2.3") is None, "rejects a leading zero")
    check(release.parse_tag("v1.2.3") is None, "rejects an installer-style tag")
    check(release.parse_version("0.1.10") == (0, 1, 10), "parses a three-part version")
    check(release.parse_version("0.1.1-rc1") is None, "rejects a prerelease version")

    check(
        release.compare_versions((0, 1, 10), (0, 1, 9)) == 1,
        "orders 0.1.10 above 0.1.9 numerically",
    )
    check(
        release.compare_versions((1, 0, 0), (1, 0, 0)) == 0,
        "compares equal versions",
    )

    with repository() as (repo, plugin):
        versions = release.read_versions(plugin)
        check(
            versions == {"header": (0, 1, 0), "constant": (0, 1, 0), "stable": (0, 1, 0)},
            "reads all three matching metadata fields",
        )

        result = cli("check", "--tag", "heic-monitor-v0.1.0", "--root", str(plugin))
        check(result.returncode == 0, "check accepts a matching tag")
        check("ok:" in result.stdout, "check prints a success line")

        result = cli("check", "--tag", "heic-monitor-v0.1.1", "--root", str(plugin))
        check(result.returncode == 1, "check rejects a mismatched tag")
        check("expects 0.1.1" in result.stderr, "mismatch names the expected version")

        before_header = (plugin / "heic-fix-monitor.php").read_text(encoding="utf-8")
        before_readme = (plugin / "readme.txt").read_text(encoding="utf-8")

        result = cli("prepare", "0.1.1", "--dry-run", "--root", str(plugin))
        check(result.returncode == 0, "dry-run exits 0")
        check("would update" in result.stdout, "dry-run prints planned changes")
        check(
            (plugin / "heic-fix-monitor.php").read_text(encoding="utf-8") == before_header
            and (plugin / "readme.txt").read_text(encoding="utf-8") == before_readme,
            "dry-run leaves metadata byte-identical",
        )

        installer_before = (repo / "heic-fix.sh").read_bytes()
        version_before = (repo / "VERSION").read_bytes()

        result = cli("prepare", "0.1.1", "--root", str(plugin))
        check(result.returncode == 0, "prepare exits 0 on a clean repository")
        check(
            release.read_versions(plugin)
            == {"header": (0, 1, 1), "constant": (0, 1, 1), "stable": (0, 1, 1)},
            "prepare synchronizes all three metadata fields",
        )
        check(
            "define( 'HFM_PLUGIN_DIR', __DIR__ );"
            in (plugin / "heic-fix-monitor.php").read_text(encoding="utf-8"),
            "prepare preserves surrounding plugin code",
        )
        check((repo / "heic-fix.sh").read_bytes() == installer_before, "prepare leaves the installer untouched")
        check((repo / "VERSION").read_bytes() == version_before, "prepare leaves VERSION untouched")
        check(
            "tag heic-monitor-v0.1.1" in result.stdout,
            "prepare prints the tag command",
        )

    with repository() as (repo, plugin):
        (plugin / "readme.txt").write_text("dirty\n", encoding="utf-8")
        result = cli("prepare", "0.1.1", "--root", str(plugin))
        check(result.returncode == 1, "prepare refuses a dirty working tree")
        check("not clean" in result.stderr, "dirty refusal explains the condition")

    with repository() as (repo, plugin):
        git(repo, "tag", "heic-monitor-v0.1.1")
        result = cli("prepare", "0.1.1", "--root", str(plugin))
        check(result.returncode == 1, "prepare refuses an existing plugin tag")
        check("already exists" in result.stderr, "existing-tag refusal is explicit")

    with repository() as (repo, plugin):
        git(repo, "tag", "heic-monitor-v0.2.0")
        result = cli("prepare", "0.1.9", "--root", str(plugin))
        check(result.returncode == 1, "prepare refuses a lower version")
        check("not greater" in result.stderr, "lower-version refusal is explicit")

    with repository() as (repo, plugin):
        git(repo, "tag", "heic-monitor-vbanana")
        result = cli("prepare", "0.1.1", "--root", str(plugin))
        check(result.returncode == 1, "prepare refuses a malformed plugin tag")
        check("malformed plugin tag" in result.stderr, "malformed-tag refusal is explicit")

    with repository() as (repo, plugin):
        git(repo, "tag", "heic-monitor-v0.1.9")
        git(repo, "tag", "heic-monitor-v0.1.10")
        check(release.plugin_tags(repo) == [(0, 1, 9), (0, 1, 10)], "collects plugin tags only")
        result = cli("prepare", "0.1.11", "--root", str(plugin))
        check(result.returncode == 0, "prepare accepts a version above 0.1.10")

    if FAILURES:
        print(f"\n{FAILURES} failure(s)", file=sys.stderr)
        return 1

    print("\nAll release helper tests passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
