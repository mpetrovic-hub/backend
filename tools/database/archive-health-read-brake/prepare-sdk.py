#!/usr/bin/env python3
"""External build preparation only. Never invoked by PHP or the cron."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import urllib.request

if len(sys.argv) != 2:
    raise SystemExit("Usage: python3 prepare-sdk.py /ABSOLUTE/PRIVATE/SYSROOT")
root = Path(sys.argv[1])
if not root.is_absolute():
    raise SystemExit("The private sysroot must be absolute")
root.mkdir(parents=True, exist_ok=True)
inputs = json.loads(Path(__file__).with_name("sdk-inputs.json").read_text())
cache = root.parent / (root.name + "-packages")
cache.mkdir(exist_ok=True)
for package in inputs["packages"]:
    archive = cache / package["url"].rsplit("/", 1)[1]
    if not archive.exists():
        with urllib.request.urlopen(package["url"], timeout=60) as response:
            archive.write_bytes(response.read())
    if hashlib.sha256(archive.read_bytes()).hexdigest() != package["sha256"]:
        raise SystemExit("SDK checksum mismatch: " + package["name"])
    version = subprocess.check_output(["dpkg-deb", "-f", str(archive), "Version"], text=True).strip()
    if version != package["version"]:
        raise SystemExit("SDK version mismatch: " + package["name"])
    subprocess.run(["dpkg-deb", "-x", str(archive), str(root)], check=True)
for path in root.rglob("*"):
    if path.is_symlink():
        target = os.readlink(path)
        if target.startswith("/"):
            relative = os.path.relpath(root / target.lstrip("/"), path.parent)
            path.unlink()
            path.symlink_to(relative)
print("Verified and extracted all five pinned Debian SDK packages")
