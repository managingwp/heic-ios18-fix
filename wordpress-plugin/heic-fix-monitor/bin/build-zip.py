#!/usr/bin/env python3
"""Build the standalone plugin zip without external zip tooling."""

import fnmatch
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DISTIGNORE = ROOT / ".distignore"
OUTPUT = ROOT / "artifacts" / "heic-fix-monitor.zip"
PREFIX = "heic-fix-monitor"

REQUIRED = [
    "heic-fix-monitor.php",
    "readme.txt",
    "LICENSE",
    "uninstall.php",
    "assets/failure_magick.HEIC",
    "assets/SHA256SUMS",
    "docs/configuration.md",
]


def patterns():
    entries = []
    if DISTIGNORE.exists():
        for line in DISTIGNORE.read_text().splitlines():
            line = line.strip()
            if line and not line.startswith("#"):
                entries.append(line)
    return entries


def ignored(relative: str, patterns_list) -> bool:
    if relative == ".git" or relative.startswith(".git/"):
        return True
    if relative.endswith(".DS_Store"):
        return True
    for pattern in patterns_list:
        if pattern.endswith("/"):
            if relative == pattern[:-1] or relative.startswith(pattern):
                return True
        elif fnmatch.fnmatch(relative, pattern) or relative == pattern:
            return True
    return False


def main() -> int:
    patterns_list = patterns()
    missing = [path for path in REQUIRED if not (ROOT / path).is_file()]
    if missing:
        print(f"missing required distribution files: {missing}", file=sys.stderr)
        return 1

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    if OUTPUT.exists():
        OUTPUT.unlink()

    count = 0
    with zipfile.ZipFile(OUTPUT, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for path in sorted(ROOT.rglob("*")):
            if path.is_symlink():
                print(f"refusing symlink in distribution: {path}", file=sys.stderr)
                return 1
            if not path.is_file():
                continue
            relative = path.relative_to(ROOT).as_posix()
            if ignored(relative, patterns_list):
                continue
            archive.write(path, f"{PREFIX}/{relative}")
            count += 1

    print(f"built {OUTPUT} ({count} files)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
