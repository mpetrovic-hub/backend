#!/usr/bin/env python3
"""Run the real child suite through a PHP version-manager-style launcher."""
import json
import os
from pathlib import Path
import shlex
import shutil
import subprocess
import tempfile

repo = Path(__file__).resolve().parent.parent
launcher = shutil.which("php")
assert os.name == "posix" and launcher, "Linux and PHP are required"
actual = Path(subprocess.check_output([launcher, "-r", "echo PHP_BINARY;"], text=True).strip())
assert actual.is_absolute() and actual.is_file()

with tempfile.TemporaryDirectory(prefix="kiwi-brake-php-shim-") as directory:
    root = Path(directory)
    shim = root / "php"
    trace = root / "launcher.trace"
    # A real version manager invokes helper programs before exec'ing PHP.
    # Those helpers do not carry the health-child marker, so preloading the
    # launcher rather than PHP must be rejected by the existing native guard.
    shim.write_text(
        "#!/bin/sh\n"
        "printf '%s\\n' 'resolution' >> \"$KIWI_PHP_SHIM_TRACE\"\n"
        "real_dir=$(/usr/bin/dirname " + shlex.quote(str(actual)) + ") || exit \"$?\"\n"
        "exec \"$real_dir\"/" + shlex.quote(actual.name) + " \"$@\"\n"
    )
    shim.chmod(0o755)
    environment = os.environ.copy()
    environment["PATH"] = str(root) + os.pathsep + environment.get("PATH", "")
    environment["KIWI_PHP_SHIM_TRACE"] = str(trace)
    process = subprocess.run(
        [shutil.which("python3"), str(repo / "tests/archive-health-read-brake-integration.py")],
        env=environment, capture_output=True, text=True, timeout=240,
    )
    assert process.returncode == 0, (process.returncode, process.stdout[-1200:], process.stderr[-1800:])
    summary = json.loads(process.stdout.splitlines()[-1])
    assert summary["status"] == "PASS" and summary["checks"] >= 30
    assert trace.read_text().splitlines() == ["resolution"], "Only unpreloaded interpreter resolution may invoke the shim"
    print("PASS real PHP resolved once before child-only preload; all " + str(summary["checks"]) + " native/PDO cases passed through a version-manager-style launcher")
