#!/usr/bin/env python3
"""Build the HumHub Marketplace package for metadata-cleaner.

Produces a ZIP with exactly one top-level folder named after the module ID,
an explicit root directory entry, and file entries only (no nested directory
entries), which is the layout the Marketplace validator expects.

Usage: build-marketplace-zip.py [--src DIR] [--out FILE] [--version X.Y.Z]
"""

import argparse
import json
import os
import sys
import zipfile

MODULE_ID = "metadata-cleaner"

# Paths (relative to the module root) that must never ship in the package.
EXCLUDED_DIRS = {
    ".git",
    ".github",
    "node_modules",
    "vendor",
    ".idea",
    ".vscode",
    "_output",
    "_generated",
    "__pycache__",
}
EXCLUDED_FILES = {".DS_Store", "Thumbs.db", "desktop.ini"}
EXCLUDED_PREFIXES = ("backup",)


def excluded(rel: str) -> bool:
    parts = rel.split("/")
    if any(part in EXCLUDED_DIRS for part in parts[:-1]):
        return True
    name = parts[-1]
    if name in EXCLUDED_FILES:
        return True
    if len(parts) == 1 and name.endswith(".zip"):
        return True
    if name.endswith((".log", ".bundle", ".phar")):
        return True
    return any(rel.startswith(prefix) or f"/{prefix}" in rel for prefix in EXCLUDED_PREFIXES)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--src", default=".", help="module source directory")
    parser.add_argument("--out", default=f"{MODULE_ID}.zip", help="output zip path")
    parser.add_argument("--version", help="override version (must match module.json)")
    args = parser.parse_args()

    src = os.path.abspath(args.src)
    with open(os.path.join(src, "module.json"), encoding="utf-8") as fh:
        meta = json.load(fh)
    version = args.version or meta.get("version")
    if not version:
        print("module.json has no version", file=sys.stderr)
        return 1
    if meta.get("id") != MODULE_ID:
        print(f"module.json id is {meta.get('id')!r}, expected {MODULE_ID!r}", file=sys.stderr)
        return 1

    out = os.path.abspath(args.out)
    if os.path.exists(out):
        os.remove(out)

    count = 0
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.writestr(f"{MODULE_ID}/", "")
        for root, dirs, files in os.walk(src):
            dirs.sort()
            for name in sorted(files):
                full = os.path.join(root, name)
                rel = os.path.relpath(full, src).replace(os.sep, "/")
                if excluded(rel):
                    continue
                zf.write(full, f"{MODULE_ID}/{rel}")
                count += 1

    print(f"{out}: {count} files + root entry, version {version}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
