#!/bin/sh
set -eu
if [ "$#" -ne 1 ]; then
    echo 'Usage: /bin/sh build-compat.sh /ABSOLUTE/PRIVATE/DEBIAN11_SYSROOT' >&2
    exit 2
fi
module_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
sdk_dir=$(CDPATH= cd -- "$1" && pwd)
test "$(uname -m)" = x86_64
test "$(gcc -dumpfullversion)" = 11.4.0
test -r "$sdk_dir/usr/include/sqlite3.h"
test -r "$module_dir/fixtures/kiwi_retention_archive_2000.sqlite"
source_hash=$(sha256sum "$module_dir/limit-archive-reads.c" | cut -d ' ' -f 1)
gcc --sysroot="$sdk_dir" -B"$sdk_dir/usr/lib/x86_64-linux-gnu/" \
    -std=gnu11 -O2 -Wall -Wextra -Werror -shared -fPIC \
    "-DKIWI_BRAKE_BUILD_ID=\"$source_hash\"" \
    -o "$module_dir/limit-archive-reads.so" "$module_dir/limit-archive-reads.c" \
    -Wl,--no-as-needed -Wl,-z,defs \
    -L"$sdk_dir/lib/x86_64-linux-gnu" -L"$sdk_dir/usr/lib/x86_64-linux-gnu" \
    -l:libsqlite3.so.0 -ldl -pthread
python3 - "$module_dir" <<'PY'
import hashlib, json, re, shutil, subprocess, sys
from pathlib import Path
root = Path(sys.argv[1])
sha = lambda name: hashlib.sha256((root / name).read_bytes()).hexdigest()
elf = subprocess.check_output(["readelf", "-V", str(root / "limit-archive-reads.so")], text=True)
versions = sorted(set(re.findall(r"GLIBC_([0-9.]+)", elf)), key=lambda s: tuple(map(int, s.split("."))))
assert versions and all(tuple(map(int, v.split("."))) <= (2, 17) for v in versions), versions
dynamic = subprocess.check_output(["readelf", "-d", str(root / "limit-archive-reads.so")], text=True)
assert "RPATH" not in dynamic and "RUNPATH" not in dynamic
fixture = "fixtures/kiwi_retention_archive_2000.sqlite"
manifest = {
    "schema_version": 1, "contract_version": 1, "architecture": "x86_64-linux",
    "build_id": sha("limit-archive-reads.c"), "source_sha256": sha("limit-archive-reads.c"),
    "library_sha256": sha("limit-archive-reads.so"), "required_glibc_versions": versions,
    "required_libraries": re.findall(r"Shared library: \[([^]]+)\]", dynamic),
    "compiler": subprocess.check_output(["gcc", "--version"], text=True).splitlines()[0],
    "compiler_executable_sha256": hashlib.sha256(Path(subprocess.check_output(["which", "gcc"], text=True).strip()).resolve().read_bytes()).hexdigest(),
    "compiler_programs": {
        name: {"sha256": hashlib.sha256(Path(path).resolve().read_bytes()).hexdigest()}
        for name, path in {
            "cc1": subprocess.check_output(["gcc", "-print-prog-name=cc1"], text=True).strip(),
            "assembler": shutil.which("as"), "linker": shutil.which("ld"),
        }.items()
    },
    "binutils_version": subprocess.check_output(["ld", "--version"], text=True).splitlines()[0],
    "build_script_sha256": sha("build-compat.sh"),
    "sdk_preparation_sha256": sha("prepare-sdk.py"),
    "fixture": {"name": fixture, "bytes": (root / fixture).stat().st_size, "sha256": sha(fixture)},
    "fixture_generator_sha256": sha("build-fixture.py"),
    "sdk": json.loads((root / "sdk-inputs.json").read_text()),
}
(root / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
print(json.dumps({"library_sha256":manifest["library_sha256"], "glibc":versions, "fixture_sha256":manifest["fixture"]["sha256"]}))
PY
