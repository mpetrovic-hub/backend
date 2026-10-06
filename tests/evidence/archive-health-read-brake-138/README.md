# Issue #138 local implementation evidence

These are complete read-only archive-copy runs from 2026-10-06. The archive itself and its subscriber data are excluded. CSV files contain only sample times and cumulative local kernel block-operation/read-byte/write-byte counters.

| Run | Full check duration | Certified conservative peak | Peak after minute 60 | Gate |
| --- | ---: | ---: | ---: | --- |
| [cold 700](cold-700-result.json), regular supervisor | 3,148.562 s (52 min 28.562 s) | 918 | scan ended before minute 60 | PASS |
| [cold 350](cold-350-result.json), private actual child | 6,130.450 s (102 min 10.450 s) | 703 | 362 | PASS |
| [first run](rejected-noise-result.json) | 3,138.647 s | 1,251 | scan ended before minute 60 | REJECTED |

All checks completed with `sqlite_check_ok` / exit 0, initially zero resident archive pages and identical before/after SHA-256 `24c57a3de0efe291769f87d03b325519e007038b50f988b09e425536fac30a3c` for the complete 4,335,632,384-byte copy. Both passing runs stayed inside 7,200 seconds; no child was terminated. The 350 check itself ran beyond 60 minutes; no waiting after an early result is counted as long-run evidence.

The counter scope is every device/process charged to the temporary Linux root cgroup, including measurement writes. This is not a Hostinger Account/LVE measurement. The first run exceeded the gate in a brief interval coinciding with an Ubuntu Pro WSL bridge restart. Its failed curve is retained. For the two sequential retries, optional local timers/services were runtime-masked solely in this task's private distro; [environment metadata](measurement-environment.json) records the change. No background operations were subtracted from any curve and the rates/gate stayed fixed.

Each result lists hashes of the original CSV and its deterministic gzip representation. Sample timestamps precede reading `io.stat`; the certified rolling-second bound retains one additional older sample whose read ended before the next timestamp. This bounds counter-read time and arbitrary second boundaries. The 700 run originally reported 904 using the earlier baseline; re-certifying its complete retained curve under the stricter method yields 918. The 350 run already used the stricter method. The rejected run rises from 1,237 to 1,251. Certification can only strengthen this gate.

The library is `c616f6f7a3200513d3d1a9766acd20898c3589e8572ea381c6fc4ff0d5a0a39f`; source/build ID is `62898a3431670236d2944b23f000f25343e8d2cd9bf534e065abf21d62142944`. Local PHP 8.1.2-1ubuntu2.26 / SQLite 3.37.2 / glibc 2.35 were used. The separate [actual glibc 2.17 result](glibc217.json) passed both fixture phases and paced `pread`/`pread64` using the same library and SQLite 3.34.1.

Recompute all curve hashes, window peaks and gates on Linux without an archive scan:

```sh
python3 tests/evidence/archive-health-read-brake-138/verify-curves.py
```

Build and reproduction commands are in the [bundle README](../../../tools/database/archive-health-read-brake/README.md). The implementation also passed 406 PHP tests, 30 targeted Native/PDO checks, isolated default/clamp/environment contracts, two identical native builds and touched-file syntax checks. Production compatibility, Account IOPS and rollout acceptance remain in the single #138 Planner Report after a verified merge and separate rollout authorization.