#!/usr/bin/env python3
"""Assert the standalone zip is minimal, rooted and free of development files."""

import sys
import zipfile
from pathlib import Path

PREFIX = "heic-fix-monitor/"

REQUIRED = {
    "heic-fix-monitor/heic-fix-monitor.php",
    "heic-fix-monitor/readme.txt",
    "heic-fix-monitor/LICENSE",
    "heic-fix-monitor/uninstall.php",
    "heic-fix-monitor/assets/failure_magick.HEIC",
    "heic-fix-monitor/assets/SHA256SUMS",
    "heic-fix-monitor/docs/configuration.md",
}

FORBIDDEN = (
    "/tests/",
    "/bin/",
    "/artifacts/",
    "phpcs.xml.dist",
    "docs/architecture.md",
    ".distignore",
    "node_modules",
    ".git/",
    ".DS_Store",
)


def fail(message: str) -> int:
    print(f"FAIL: {message}", file=sys.stderr)
    return 1


def main() -> int:
    if len(sys.argv) > 1 and sys.argv[1] != "-":
        zip_path = Path(sys.argv[1])
        try:
            with zipfile.ZipFile(zip_path) as archive:
                bad = archive.testzip()
                if bad:
                    return fail(f"corrupt entry: {bad}")
                names = archive.namelist()
        except (OSError, zipfile.BadZipFile) as error:
            return fail(f"cannot read zip: {error}")
    else:
        names = [line.strip() for line in sys.stdin if line.strip()]

    if not names:
        return fail("zip is empty")

    for name in names:
        if not name.startswith(PREFIX):
            return fail(f"entry is not rooted under {PREFIX}: {name}")
        if ".." in name.split("/"):
            return fail(f"entry contains a parent segment: {name}")
        for marker in FORBIDDEN:
            if marker in name:
                return fail(f"development file in zip: {name}")

    missing = REQUIRED.difference(names)
    if missing:
        return fail(f"missing required entries: {sorted(missing)}")

    if not any(name.startswith(PREFIX + "includes/") for name in names):
        return fail("plugin classes are missing")

    print(f"PASS: {len(names)} entries, rooted, minimal")
    return 0


if __name__ == "__main__":
    sys.exit(main())
