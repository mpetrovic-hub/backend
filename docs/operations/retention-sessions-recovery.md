# Bounded landing-session retention recovery

This external deployment procedure reconciles stale CTA metrics while preserving archived handoffs, then starts and resumes the ordinary `landing_page_sessions` retention worker. It implements the acute repair in [Issue #135](https://github.com/mpetrovic-hub/backend/issues/135). Root-cause and prevention work remains separate.

## Before production use

Deploy the reviewed recovery artifact together with its read-context changes. The external runner is `tools/database/kiwi-retention-sessions-recovery.php`; it is never loaded by normal WordPress requests. It runs at `plugins_loaded` and halts before `init`, following the existing external database-deployment boundary. WP-CLI, the active Kiwi plugin, PDO SQLite, child-process APIs for the supervised health check, and transactional InnoDB tables are required. CLI opcode caching must be disabled.

Compare the deployed runtime hashes with the reviewed artifact. Each preview reports the actual loaded runtime files, settings, database identity hash, raw/source fingerprints and cutoff. The independent sandbox report is `tests/results/retention-sessions-recovery-validation.json`. A repository commit alone does not prove that production has deployed those files.

Preserve a verified database backup and the involved archive generations. Coordinate a maintenance window for the apply operation and completion checks: other retention invocations and summary refreshes must not compete with recovery, and old raw evidence must remain stable until the normal run is frozen. Do not change retention settings, bypass a corruption gate, delete an archive, or rewrite audit rows to make a preview pass. Archive-health scheduling can defer while recovery owns the ordinary generation locks.

`preview` reads persistent MySQL data in a consistent read-only transaction and reads SQLite through the existing supervised integrity runner and read-only connections. It creates, fills and drops connection-local temporary MySQL tables, and uses generation lock files and transient health-process files. It writes no persistent business tables, options, incidents or cleanup audits. Normal WordPress bootstrap and unrelated plugin/MU-plugin effects are outside the command's SQL guarantee; use the same reviewed early-bootstrap environment as the existing external database runner. A database account with `SELECT` and `CREATE TEMPORARY TABLES` is sufficient for the recovery service's preview and is covered by integration testing.

The preview may scan the full remaining retention scope and all discovered archive generations. Runtime, memory use and lock impact on the actual production dataset have not been measured. Apply uses serializable reads, which can block concurrent writes beyond the eight summary dates while it validates the full retention scope. Use the maintenance window; SQL lock waits are bounded to five seconds and their prior connection settings are restored.

## Preview

Prepare a private directory outside the web root, mode `0700`. Every command requires a **new**, absolute report filename within such a directory; the runner creates a `0600` JSONL journal and refuses overwrites. Reports contain metrics and evidence hashes, not raw Handoff/session payloads or credentials.

Example for **2026-10-01 with a verified 14-day sessions policy**; replace deployment paths and confirm the current normal cutoff. A later date or a different policy requires its actual cutoff. The runner rejects a caller-supplied cutoff that differs from the normal scheduler's cutoff.

```bash
wp --path=/path/to/wordpress \
  --require=/path/to/kiwi-backend/tools/database/kiwi-retention-sessions-recovery.php \
  kiwi retention-sessions-recovery preview \
  --from=2026-08-30 --to=2026-09-06 \
  --cutoff='2026-09-17 00:00:00' \
  --report=/private/recovery/preview-2026-10-01.jsonl
```

Inspect `ready_to_apply`, `blockers`, `changes`, `daily_values`, `gate`, and `input_evidence`. The range is explicit, inclusive, at most 31 days, and entirely before the cutoff. Each requested date must have eligible raw scope and receive a deep comparison. The gate also checks all remaining candidate dates through the current normal cutoff, including dates outside the requested repair window. A partial gate never authorizes this recovery.

The archive reader verifies every Handoff batch against independent completed MySQL cleanup audits: source, archive generation, cutoff, row counts, frozen primary-key boundary and archive/delete cursors. It verifies Source-ID receipts and matching archive rows. Missing audits/files/rows, unfinished Handoff runs, orphan batches, corruption state, or incompatible evidence block recovery. Identical rows across live/archive or overlapping generations count once; conflicting IDs or logical event identities block. The Handoff window includes the following day required by summary attribution.

The two existing aggregators compute candidates in temporary tables. The preview permits only changes to the six CTA metrics. Summary dimensions, Handoff counts/hidden times, session counts, sales and every other stored value must agree. Unexpected non-CTA changes are reported as blockers and require investigation rather than an automatic overwrite. IDs and creation timestamps are preserved during application; only changed CTA values and their `updated_at` are written.

Save the exact `preview_sha256` after reviewing the concrete report. It binds the input data, archive/audit evidence, runtime code, settings, cutoff and candidate differences. The apply command rebuilds the preview under serializable locks and requires the same fingerprint. Changed evidence requires another preview and review.

## Apply and start retention

Use the reviewed preview hash and a fresh report filename:

```bash
wp --path=/path/to/wordpress \
  --require=/path/to/kiwi-backend/tools/database/kiwi-retention-sessions-recovery.php \
  kiwi retention-sessions-recovery apply \
  --from=2026-08-30 --to=2026-09-06 \
  --cutoff='2026-09-17 00:00:00' \
  --expected-preview=REVIEWED_PREVIEW_SHA256 \
  --max-workers=1 \
  --report=/private/recovery/apply-2026-10-01.jsonl
```

The existing sessions settings must already be enabled and non-dry-run. Another open sessions run blocks a new repair. The command does not change policy, settings, source schema, gate rules or click tolerances.

Before writing, the private journal durably records the prepared preview. Both summaries' changed CTA values and the ordinary manual scheduler's audit/start are committed in one MySQL transaction. The scheduler uses the same verified Handoff snapshot as candidate calculation and gate validation. A failed summary write, gate or scheduler start rolls back the transaction. The temporary read context is not installed globally; ordinary cron continues to use its existing live reads.

The scheduler freezes its normal cutoff and maximum Source-ID; the regular worker subsequently performs its existing receipt-backed archive/delete phases. The tool runs bounded worker invocations without manufacturing `completed` or increasing worker limits. `--max-workers` permits 1–20 calls, defaults to 1, and respects the normal reschedule delay by returning when another call is due. A partial result retains its `run_id` and requests resumption.

Recovery metadata, including the preview hash, code hash, repair dates and independent eligible-ID fingerprint, is persisted in the normal run's gate evidence. The worker additionally accepts an expected Run-ID and rejects a different open run before archive/delete. No separate persistent recovery tables are created.

## Resume the same run

```bash
wp --path=/path/to/wordpress \
  --require=/path/to/kiwi-backend/tools/database/kiwi-retention-sessions-recovery.php \
  kiwi retention-sessions-recovery resume \
  --run-id=RECOVERY_RUN_ID \
  --expected-preview=REVIEWED_PREVIEW_SHA256 \
  --max-workers=1 \
  --report=/private/recovery/resume-01.jsonl
```

Use a fresh journal on every call and wait the normal worker reschedule delay when applicable. Resume does not reapply summaries or start a new scheduler run. It validates the persisted recovery identity, passed gate, frozen cutoff and runtime code before calling the ordinary worker for that exact Run-ID. It can continue across a date rollover because the existing run retains its cutoff. A runtime code change requires review and deliberately blocks this resume path.

Exit codes: `0` for a usable preview or verified completed run; `2` for a blocked preview or successful partial run needing resumption; `1` for a failed operation. Inspect the JSON as well as the exit code. A write failure after a confirmed commit can leave summaries published and the native run pending/partial; use its Run-ID to resume.

If the connection fails while acknowledging `COMMIT`, the tool reports `commit_outcome=unknown` and `summary_published=null`. The journal records `commit_requested` with the Run-ID before sending the commit. Inspect that exact audit run and current summaries; if the recovery run persisted, resume it. If it did not persist, establish rollback through a fresh read-only preview before deciding on another apply. Never blindly repeat apply after an unknown commit outcome. Automatic wpdb reconnection/retry is disabled during the transaction so a failed connection cannot silently execute a summary write outside it.

Completion requires the ordinary run to say `completed` and independent re-verification of the exact initial Source-ID fingerprint, matching archive/delete/eligible counts and cursors, batch identity and no remaining rows in the frozen scope. A `completed` audit without matching receipt evidence produces a failure. Newer rows or later inserted IDs outside the frozen scope are outside that run. A repeated resume of a verified completed run performs verification without starting another run.

## Validation and current rollout status

The implementation was tested with synthetic data in private MariaDB/SQLite, including supervised real health child processes, multiple generations, archive/live conflicts, a database account lacking persistent write permission, atomic rollback, stale-preview rejection, concurrent-write locking, normal multi-chunk worker resumption, lost commit acknowledgement, exact completion re-verification and early WP-CLI lifecycle/report guards. Existing repository regressions cover unchanged default runtime behavior. Test details and hashes are in the validation artifact.

The cloud environment has no configured production credentials or production access. No production preview, repair, deployment or 72,440-row production run has been executed. Full WordPress/WP-CLI deployment integration and production performance remain to be verified on the target installation. The CLI lifecycle test uses a WP-CLI harness with the real recovery service.
