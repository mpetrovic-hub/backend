#!/usr/bin/env bash
set -euo pipefail

# Starts a private database with TCP disabled; never uses an existing server.
parent=${1:-/tmp}
mkdir -p "$parent"
sandbox=$(mktemp -d "$parent/retention-sessions-XXXXXX")
script_dir=$(cd -- "$(dirname -- "$0")" && pwd)
php_bin=${PHP_BIN:-php}
mariadb_base=${MARIADB_BASE_DIR:-/usr}
export LD_LIBRARY_PATH="$mariadb_base/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
printf '%s\n' 'retention-sessions-recovery-tests-only' > "$sandbox/SANDBOX_ONLY"

"$mariadb_base/bin/mariadb-install-db" --no-defaults --basedir="$mariadb_base" \
    --datadir="$sandbox/database" --auth-root-authentication-method=normal --skip-test-db > "$sandbox/database-init.log" 2>&1
"$mariadb_base/sbin/mariadbd" --no-defaults --basedir="$mariadb_base" \
    --datadir="$sandbox/database" --socket="$sandbox/mariadb.sock" \
    --pid-file="$sandbox/mariadb.pid" --log-error="$sandbox/mariadb.log" \
    --skip-networking --innodb-use-native-aio=0 > "$sandbox/database-output.log" 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true' EXIT
for ((attempt=0; attempt<100; attempt++)); do
    if [[ -S "$sandbox/mariadb.sock" ]]; then break; fi
    if ! kill -0 "$server_pid" 2>/dev/null; then cat "$sandbox/mariadb.log"; exit 1; fi
    sleep 0.1
done
"$php_bin" "$script_dir/retention-sessions-recovery-integration.php" "$sandbox"
printf 'Sandbox report: %s/result.json\n' "$sandbox"
