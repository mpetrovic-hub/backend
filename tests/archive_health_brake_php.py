"""Resolve the interpreter before applying the archive child's preload."""
import os
from pathlib import Path
import shutil
import subprocess


def resolve_php_binary():
    launcher = shutil.which("php")
    if not launcher:
        raise RuntimeError("PHP is required for the archive-health checks")
    process = subprocess.run(
        [launcher, "-r", "echo PHP_BINARY;"],
        capture_output=True, text=True, check=False, timeout=15,
    )
    binary = Path(process.stdout.strip())
    if process.returncode != 0 or not binary.is_absolute() or not binary.is_file() or not os.access(binary, os.X_OK):
        raise RuntimeError("PHP did not report an executable absolute PHP_BINARY")
    return str(binary.resolve(strict=True))
