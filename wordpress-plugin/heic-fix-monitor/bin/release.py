#!/usr/bin/env python3
"""Independent semantic versioning for the HEIC Fix Monitor plugin.

The plugin is released with its own ``heic-monitor-vX.Y.Z`` tags. This helper
only reads and synchronizes the three plugin metadata fields; it never commits,
tags, pushes, or publishes.
"""

from __future__ import annotations

import argparse
import re
import subprocess
import sys
from pathlib import Path
from typing import Iterable

TAG_PREFIX = "heic-monitor-v"
TAG_RE = re.compile(r"^heic-monitor-v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$")
VERSION_RE = re.compile(r"^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$")

PLUGIN_ROOT = Path(__file__).resolve().parent.parent
HEADER_FILE = "heic-fix-monitor.php"
README_FILE = "readme.txt"
HEADER_PATTERN = re.compile(r"^(\s*\*\s*Version:\s*)(\d+\.\d+\.\d+)(\s*)$", re.MULTILINE)
CONSTANT_PATTERN = re.compile(
    r"(define\(\s*'HFM_VERSION'\s*,\s*')(\d+\.\d+\.\d+)('\s*\)\s*;)", re.MULTILINE
)
STABLE_PATTERN = re.compile(r"^(Stable tag:\s*)(\d+\.\d+\.\d+)(\s*)$", re.MULTILINE)

Version = tuple[int, int, int]


class ReleaseError(Exception):
    """A versioning precondition failed."""


def parse_version(value: str) -> Version | None:
    """Parse a strict X.Y.Z version."""
    match = VERSION_RE.fullmatch(str(value).strip())
    if not match:
        return None
    return tuple(int(part) for part in match.groups())  # type: ignore[return-value]


def format_version(version: Version) -> str:
    """Render a version tuple."""
    return ".".join(str(part) for part in version)


def parse_tag(tag: str) -> Version | None:
    """Parse a plugin release tag."""
    match = TAG_RE.fullmatch(str(tag).strip())
    if not match:
        return None
    return tuple(int(part) for part in match.groups())  # type: ignore[return-value]


def compare_versions(left: Version, right: Version) -> int:
    """Return -1, 0 or 1 for semantic ordering."""
    return (left > right) - (left < right)


def read_text(path: Path) -> str:
    """Read a UTF-8 file."""
    try:
        return path.read_text(encoding="utf-8")
    except OSError as error:
        raise ReleaseError(f"cannot read {path}: {error}") from error


def read_versions(root: Path) -> dict[str, Version]:
    """Read the three authoritative plugin versions."""
    header = read_text(root / HEADER_FILE)
    readme = read_text(root / README_FILE)

    header_match = HEADER_PATTERN.search(header)
    constant_match = CONSTANT_PATTERN.search(header)
    stable_match = STABLE_PATTERN.search(readme)

    missing = []
    if not header_match:
        missing.append(f"{HEADER_FILE} plugin header")
    if not constant_match:
        missing.append(f"{HEADER_FILE} HFM_VERSION")
    if not stable_match:
        missing.append(f"{README_FILE} Stable tag")
    if missing:
        raise ReleaseError("missing or malformed version field: " + ", ".join(missing))

    versions = {
        "header": parse_version(header_match.group(2)),
        "constant": parse_version(constant_match.group(2)),
        "stable": parse_version(stable_match.group(2)),
    }
    for name, version in versions.items():
        if version is None:
            raise ReleaseError(f"invalid version in {name}")

    return versions  # type: ignore[return-value]


def render_updates(root: Path, version: Version) -> dict[Path, str]:
    """Return updated file contents without writing anything."""
    rendered = format_version(version)
    header_path = root / HEADER_FILE
    readme_path = root / README_FILE
    header = read_text(header_path)
    readme = read_text(readme_path)

    header_updated, header_count = HEADER_PATTERN.subn(
        lambda match: f"{match.group(1)}{rendered}{match.group(3)}", header
    )
    header_updated, constant_count = CONSTANT_PATTERN.subn(
        lambda match: f"{match.group(1)}{rendered}{match.group(3)}", header_updated
    )
    readme_updated, stable_count = STABLE_PATTERN.subn(
        lambda match: f"{match.group(1)}{rendered}{match.group(3)}", readme
    )

    if header_count != 1 or constant_count != 1 or stable_count != 1:
        raise ReleaseError("expected exactly one version field per metadata location")

    return {
        header_path: header_updated,
        readme_path: readme_updated,
    }


def git(root: Path, *args: str) -> str:
    """Run a read-only git command."""
    try:
        completed = subprocess.run(
            ["git", "-C", str(root), *args],
            check=False,
            capture_output=True,
            text=True,
        )
    except OSError as error:
        raise ReleaseError(f"cannot run git: {error}") from error

    if completed.returncode != 0:
        message = completed.stderr.strip() or completed.stdout.strip() or "git failed"
        raise ReleaseError(message)

    return completed.stdout


def git_root(root: Path) -> Path:
    """Resolve the repository root for a plugin path."""
    output = git(root, "rev-parse", "--show-toplevel").strip()
    if not output:
        raise ReleaseError("not inside a git repository")
    return Path(output)


def working_tree_clean(repo_root: Path) -> bool:
    """Whether tracked and untracked changes are absent."""
    return git(repo_root, "status", "--porcelain").strip() == ""


def plugin_tags(repo_root: Path) -> list[Version]:
    """Read valid plugin tags, rejecting malformed plugin-prefixed tags."""
    output = git(repo_root, "tag", "--list", f"{TAG_PREFIX}*")
    tags: list[Version] = []
    malformed: list[str] = []

    for line in output.splitlines():
        tag = line.strip()
        if not tag:
            continue
        version = parse_tag(tag)
        if version is None:
            malformed.append(tag)
            continue
        tags.append(version)

    if malformed:
        raise ReleaseError("malformed plugin tag(s): " + ", ".join(sorted(malformed)))

    return sorted(tags)


def check(root: Path, tag: str) -> int:
    """Validate that the tag matches all plugin metadata."""
    tag_version = parse_tag(tag)
    if tag_version is None:
        print(f"error: invalid plugin tag '{tag}' (expected {TAG_PREFIX}X.Y.Z)", file=sys.stderr)
        return 1

    try:
        versions = read_versions(root)
    except ReleaseError as error:
        print(f"error: {error}", file=sys.stderr)
        return 1

    expected = format_version(tag_version)
    mismatches = [
        f"{name}={format_version(version)}"
        for name, version in versions.items()
        if version != tag_version
    ]

    if mismatches:
        print(
            f"error: tag {tag} expects {expected}, but plugin metadata reports "
            + ", ".join(mismatches),
            file=sys.stderr,
        )
        return 1

    print(f"ok: {tag} matches plugin header, HFM_VERSION and Stable tag")
    return 0


def prepare(root: Path, version_text: str, dry_run: bool) -> int:
    """Synchronize plugin metadata to one version without releasing it."""
    version = parse_version(version_text)
    if version is None:
        print(f"error: invalid version '{version_text}' (expected X.Y.Z)", file=sys.stderr)
        return 1

    try:
        repo_root = git_root(root)
        if not working_tree_clean(repo_root):
            raise ReleaseError("working tree is not clean; commit or stash changes first")

        tags = plugin_tags(repo_root)
        if version in tags:
            raise ReleaseError(f"{TAG_PREFIX}{format_version(version)} already exists")

        if tags:
            highest = max(tags)
            if compare_versions(version, highest) <= 0:
                raise ReleaseError(
                    f"version {format_version(version)} is not greater than the highest "
                    f"plugin tag {TAG_PREFIX}{format_version(highest)}"
                )

        updates = render_updates(root, version)
    except ReleaseError as error:
        print(f"error: {error}", file=sys.stderr)
        return 1

    if dry_run:
        for path in updates:
            print(f"would update {path.relative_to(root)}")
        print(f"would create tag {TAG_PREFIX}{format_version(version)} after commit")
        return 0

    for path, contents in updates.items():
        path.write_text(contents, encoding="utf-8")
        print(f"updated {path.relative_to(root)}")

    print(f"next: commit the change, then tag {TAG_PREFIX}{format_version(version)}")
    return 0


def build_parser() -> argparse.ArgumentParser:
    """Build the CLI parser."""
    parser = argparse.ArgumentParser(
        prog="release.py",
        description="Independent HEIC Fix Monitor plugin versioning.",
    )
    subparsers = parser.add_subparsers(dest="command", required=True)

    check_parser = subparsers.add_parser("check", help="validate a plugin tag against metadata")
    check_parser.add_argument("--tag", required=True, help="heic-monitor-vX.Y.Z")
    check_parser.add_argument("--root", type=Path, default=PLUGIN_ROOT, help=argparse.SUPPRESS)

    prepare_parser = subparsers.add_parser("prepare", help="synchronize plugin metadata")
    prepare_parser.add_argument("version", help="X.Y.Z")
    prepare_parser.add_argument("--dry-run", action="store_true", help="print changes only")
    prepare_parser.add_argument("--root", type=Path, default=PLUGIN_ROOT, help=argparse.SUPPRESS)

    return parser


def main(argv: Iterable[str] | None = None) -> int:
    """Run the CLI."""
    parser = build_parser()
    args = parser.parse_args(list(argv) if argv is not None else None)

    if args.command == "check":
        return check(args.root.resolve(), args.tag)
    if args.command == "prepare":
        return prepare(args.root.resolve(), args.version, args.dry_run)

    parser.error("unknown command")
    return 2


if __name__ == "__main__":
    sys.exit(main())
