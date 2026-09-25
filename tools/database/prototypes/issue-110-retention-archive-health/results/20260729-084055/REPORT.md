# Issue #110 prototype results

Local synthetic evidence only; these are not Hostinger timings.

## Environment

- Python: `3.14.6`
- SQLite: `3.50.4`
- MariaDB: `11.8.8-MariaDB`
- OS: `Windows-11-10.0.26200-SP0`
- Free disk before run: `624.21 GiB`

## Scenario verdicts

| Scenario | Passed |
|---|---:|
| JSON/exit contract | yes |
| OS lock | yes |
| Atomic campaign status | yes |
| Cron/incident lifecycle | yes |
| Quarantine/generation | yes |
| Year/campaign | yes |
| Crash recovery | yes |
| Receipt/delete pipeline | yes |

## SQLite check timings

| Archive | Actual size MiB | Check | Runs | Min s | Median s | Max s | P95 s |
|---|---:|---|---:|---:|---:|---:|---:|
| 50MiB | 50.10 | quick_check | 10 | 0.0299 | 0.0315 | 0.0348 | 0.0348 |
| 50MiB | 50.10 | integrity_check | 10 | 0.0325 | 0.0338 | 0.0356 | 0.0356 |
| 250MiB | 250.70 | quick_check | 10 | 0.1341 | 0.1428 | 0.1579 | 0.1579 |
| 250MiB | 250.70 | integrity_check | 10 | 0.1491 | 0.1529 | 0.1698 | 0.1698 |
| 1.3GiB | 1337.94 | quick_check | 3 | 0.7330 | 0.7441 | 0.7510 | n/a |
| 1.3GiB | 1337.94 | integrity_check | 3 | 0.7888 | 0.7967 | 0.8959 | n/a |

## Pipeline totals

| Rows | Runs | Min s | Median s | Max s | P95 s |
|---:|---:|---:|---:|---:|---:|
| 100 | 10 | 0.0723 | 0.1343 | 0.1684 | 0.1684 |
| 10000 | 10 | 0.4569 | 0.4862 | 0.5596 | 0.5596 |
| 50000 | 10 | 1.8173 | 1.8515 | 1.9378 | 1.9378 |

## Slowest individual phases

| Phase | Scenario | Seconds |
|---|---|---:|
| archive_generation | archive_1.3GiB | 5.1865 |
| entire_worker_invocation | pipeline_50000_iteration_1 | 1.9378 |
| entire_worker_invocation | pipeline_50000_iteration_5 | 1.9288 |
| entire_worker_invocation | pipeline_50000_iteration_7 | 1.9188 |
| entire_worker_invocation | pipeline_50000_iteration_2 | 1.8633 |
| entire_worker_invocation | pipeline_50000_iteration_4 | 1.8526 |
| entire_worker_invocation | pipeline_50000_iteration_8 | 1.8504 |
| entire_worker_invocation | pipeline_50000_iteration_3 | 1.8489 |
| entire_worker_invocation | pipeline_50000_iteration_6 | 1.8449 |
| entire_worker_invocation | pipeline_50000_iteration_9 | 1.8415 |
| entire_worker_invocation | pipeline_50000_iteration_10 | 1.8173 |
| archive_generation | archive_250MiB | 1.0913 |
| integrity_check | archive_1.3GiB_check_1 | 0.8959 |
| setup_seed_mariadb | pipeline_50000_iteration_7 | 0.8827 |
| setup_seed_mariadb | pipeline_50000_iteration_2 | 0.8703 |

## Confirmed boundaries

- No tested worker pipeline invoked a global SQLite health check before delete.
- All crash windows ended with one archive row, one receipt, one delete count per source ID.
- A busy file lock returned immediately; takeover succeeded after the holder was killed.
- Only a completed SQLite integrity result with non-`ok` rows qualified as corruption.
- A later `ok` did not automatically remove an existing quarantine marker.
- Campaign files were processed serially; the quarantined file was omitted.

## Limits

- Windows file-lock behavior still requires the planned Hostinger Linux preflight.
- OS cache was not flushed; first runs are labelled `first_run_uncontrolled_cache`, not cold.
- Local CPU, storage, and MariaDB timings must not be projected to Hostinger.
- The production PHP/WP-CLI bootstrap was not exercised by this throwaway Python harness.
