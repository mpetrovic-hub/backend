# Issue #124: temporary archive health I/O diagnostic

## Purpose

Capture the PHP child process I/O counters immediately around the SQLite `PRAGMA quick_check` run. The counters describe process activity and do not directly report Hostinger's billed IOPS.

## Scope and storage

- Implementation is in `tools/database/kiwi-retention-archive-health.php`; retention service code under `includes/`, the shell wrapper, cron output, and cron schedule are unchanged.
- After measurement and lock release, the tool attempts a create-only write of one JSON line to `$HOME/codex-deploy/issue-124-archive-health-io.json`.
- The destination must exist, resolve below the canonical home directory, remain outside the detected public web root, and have no group/other permissions. The new file is set to mode `0600`.
- Missing/unsafe storage, an existing file, unavailable counters, or a write failure must not change the health result, reason code, output, or exit status.
- The record contains no archive path or request data. It records UTC start/end, duration, `/proc/self/io` read/write byte and write-syscall deltas, and `getrusage()` block deltas where available. The `syscr` counter is omitted because reading `/proc/self/io` increments that same counter.

## Deployment observation and rollback

1. Reconfirm the live source SHA-256 from Issue #124 before deploying this tool.
2. Deploy only the reviewed tool file. Do not edit `includes/`, the wrapper, or the cron entry.
3. Allow exactly one regular `quick` cron run; do not invoke a manual quick check first. Read and preserve the JSON record, then compare its timestamp with the hPanel cron window.
4. Remove the JSON record after the observation.
5. Restore the tool to commit `4e53e4e982b58b8a0db02d6fb56e8fabe9c9d0bd`; verify the restored live tool hash is `d7f6cbeed4979844c6afd316bb6e84e7ddd2cde0d2716b03b1dccb001fd239fb` and run one regular quick check.
6. The production deployment and cron observation require a separate approval; this code change does not perform them.
