<?php

/**
 * One-time, explicitly confirmed recovery for the interrupted 2026-07-24
 * landing-page-session retention run.
 *
 * Load only through WP-CLI's global --require option. This runner stops after
 * plugins_loaded and before init so it cannot serve website traffic or run the
 * regular WordPress cron lifecycle. It is intentionally separate from the
 * read-only preflight artifact.
 *
 * The runner is bounded to one immutable run, cutoff, archive batch and source
 * primary-key receipt. It first runs the live coverage gate, reconciles the
 * already committed SQLite receipt, deletes only receipt-backed source keys in
 * 500-key batches, then hands the remaining frozen source scope to the existing
 * retention worker under WP-CLI (where SQLite checks may take as long as they
 * need, outside the web/cron request).
 */

function kiwi_retention_recovery_apply_cli_has_required_api(string $class_name): bool
{
    if (!class_exists($class_name)) {
        return false;
    }

    foreach (['add_command', 'add_wp_hook', 'get_runner', 'error', 'halt', 'line'] as $method) {
        if (!method_exists($class_name, $method)) {
            return false;
        }
    }

    return true;
}

if (!defined('WP_CLI') || !WP_CLI || !kiwi_retention_recovery_apply_cli_has_required_api('WP_CLI')) {
    if (defined('STDERR')) {
        fwrite(
            STDERR,
            "This recovery apply runner requires WP-CLI 2.12 core APIs and must be loaded through --require.\n"
        );
    }

    exit(1);
}

final class Kiwi_Retention_Recovery_20260724_Apply_Namespace
{
}

final class Kiwi_Retention_Recovery_20260724_Apply_Command
{
    private const RUN_ID = 'retention_3c4c12cf1b9244868d509ecbc2ffc5e4';
    private const SOURCE_KEY = 'landing_page_sessions';
    private const EXPECTED_CUTOFF_VALUE = '2026-07-10 00:00:00';
    private const EXPECTED_ELIGIBLE_ROWS = 66418;
    private const EXPECTED_EVIDENCE_ROWS = 50000;
    private const EXPECTED_FIRST_PRIMARY_KEY = 808247;
    private const EXPECTED_LAST_PRIMARY_KEY = 858246;
    private const EXPECTED_TARGET_MAX_PRIMARY_KEY = 874664;
    private const ARCHIVE_BATCH_ID = 'landing_page_sessions_20260724155302_c4bd2b0e';
    private const DELETE_BATCH_SIZE = 500;
    private const CONFIRMATION = 'complete-retention-recovery-20260724';
    private const RECOVERY_LOCK_KEY = 'kiwi_retention_cleanup_lock_landing_page_sessions';
    private const RECOVERY_LOCK_VALUE = 'recovery_20260724_apply';
    private const RECOVERY_LOCK_TTL_SECONDS = 21600;

    public function apply(array $args, array $assoc_args): void
    {
        if (($assoc_args['confirm'] ?? '') !== self::CONFIRMATION) {
            $this->emit_and_halt([
                'success' => false,
                'mode' => 'apply',
                'changed' => false,
                'run_id' => self::RUN_ID,
                'error_code' => 'explicit_confirmation_required',
                'error_message' => 'No recovery write was attempted. Pass the exact --confirm value documented by this runner.',
            ], 1);
        }

        $runner = WP_CLI::get_runner();
        if (!is_object($runner) || !method_exists($runner, 'load_wordpress')) {
            $this->fail('WP-CLI cannot provide the required WordPress loader.');
        }

        $executed = false;
        $hook_added = WP_CLI::add_wp_hook(
            'plugins_loaded',
            function () use (&$executed): void {
                $executed = true;
                $this->execute_apply();
            }
        );

        if (!$hook_added) {
            $this->fail('WP-CLI could not register the recovery apply lifecycle hook.');
        }

        $runner->load_wordpress();

        if (!$executed) {
            $this->fail('WordPress did not reach plugins_loaded; no recovery apply was executed.');
        }

        $this->fail('The recovery apply returned without stopping before WordPress init.');
    }

    private function execute_apply(): void
    {
        if (!function_exists('did_action')
            || did_action('plugins_loaded') < 1
            || did_action('init') > 0
        ) {
            $this->fail('The recovery apply must execute after plugins_loaded and before WordPress init.');
        }

        foreach ([
            'Kiwi_Config',
            'Kiwi_Retention_Source_Registry',
            'Kiwi_Retention_Cleanup_Run_Repository',
            'Kiwi_Retention_Coverage_Gate',
            'Kiwi_Retention_Cleanup_Service',
        ] as $required_class) {
            if (!class_exists($required_class)) {
                $this->fail('Kiwi Backend must be active and fully loaded before the recovery apply runs.');
            }
        }

        $lock_acquired = false;

        try {
            $state = $this->inspect_recovery_state(false);
            if (empty($state['success'])) {
                $this->emit_and_halt($state, 1);
            }

            if (!$this->acquire_recovery_lock()) {
                $this->emit_and_halt($this->blocked(
                    'recovery_lock_not_acquired',
                    'The recovery scheduler lock could not be acquired without overlapping normal retention activity.'
                ), 1);
            }
            $lock_acquired = true;

            $live_gate = null;
            if (($state['recovery_state'] ?? '') === 'initial') {
                $live_gate = $this->run_live_coverage_gate((array) $state['source_definition']);
                if (!$this->live_coverage_gate_passed($live_gate)) {
                    $this->emit_and_halt([
                        'success' => false,
                        'mode' => 'apply',
                        'changed' => false,
                        'run_id' => self::RUN_ID,
                        'error_code' => 'live_coverage_gate_failed',
                        'error_message' => 'No recovery write was attempted because the current coverage gate did not pass for the frozen cutoff.',
                        'live_coverage_gate' => $this->compact_gate_result($live_gate),
                    ], 1);
                }

                $state = $this->inspect_recovery_state(true);
                if (empty($state['success']) || ($state['recovery_state'] ?? '') !== 'initial') {
                    $this->emit_and_halt($this->blocked(
                        'post_gate_preflight_failed',
                        'The recovery state changed or could not be revalidated after the live coverage gate.'
                    ), 1);
                }

                if (!$this->reconcile_committed_archive_receipt($state, $live_gate)) {
                    $this->emit_and_halt($this->blocked(
                        'recovery_reconciliation_failed',
                        'The committed SQLite archive receipt could not be reconciled into the recovery audit state. No source rows were deleted.'
                    ), 1);
                }

                $state = $this->inspect_recovery_state(true);
            }

            if (empty($state['success']) || ($state['recovery_state'] ?? '') !== 'deleting_evidence') {
                $this->emit_and_halt($this->blocked(
                    'recovery_delete_state_invalid',
                    'The recovery run is not in the exact state required to delete receipt-backed source keys.'
                ), 1);
            }

            $delete_result = $this->delete_committed_evidence_batches($state);
            if (empty($delete_result['success'])) {
                $this->emit_and_halt($delete_result, 1);
            }

            $post_delete = $this->inspect_recovery_state(true);
            if (empty($post_delete['success']) || ($post_delete['recovery_state'] ?? '') !== 'archive_remaining') {
                $this->emit_and_halt($this->blocked(
                    'post_delete_state_invalid',
                    'The recovery source-delete postcondition did not match the bounded archive-remaining state.'
                ), 1);
            }

            $worker_results = [];
            for ($attempt = 1; $attempt <= 4; $attempt++) {
                $worker_result = (new Kiwi_Retention_Cleanup_Service())->run_worker(self::SOURCE_KEY);
                $worker_results[] = $this->compact_worker_result($worker_result);

                if (empty($worker_result['success'])
                    || (string) ($worker_result['status'] ?? '') !== 'partial'
                    || (string) ($worker_result['worker_phase'] ?? '') !== 'archive_partial'
                ) {
                    break;
                }
            }
            $postflight = $this->inspect_completed_state();
            $success = !empty($worker_result['success']) && !empty($postflight['success']);

            $this->emit_and_halt([
                'success' => $success,
                'mode' => 'apply',
                'changed' => true,
                'run_id' => self::RUN_ID,
                'live_coverage_gate' => $live_gate === null ? null : $this->compact_gate_result($live_gate),
                'evidence_delete' => $delete_result,
                'existing_worker_results' => $worker_results,
                'postflight' => $postflight,
                'next_step' => $success
                    ? 'Recovery completed: all frozen source rows were receipt-backed, archived, and deleted under the existing worker contract.'
                    : 'The existing worker did not reach every completion postcondition. The returned audit state is the source of truth; do not rerun normal retention blindly.',
            ], $success ? 0 : 1);
        } catch (Throwable $error) {
            $this->emit_and_halt([
                'success' => false,
                'mode' => 'apply',
                'changed' => true,
                'run_id' => self::RUN_ID,
                'error_code' => 'recovery_apply_exception',
                'error_message' => 'Recovery stopped after a guarded exception. Inspect the audit row before any further write attempt.',
            ], 1);
        } finally {
            if ($lock_acquired && function_exists('delete_transient')) {
                delete_transient(self::RECOVERY_LOCK_KEY);
            }
        }
    }

    private function inspect_recovery_state(bool $allow_recovery_lock): array
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            return $this->blocked('wpdb_unavailable', 'WordPress database access is unavailable.');
        }

        $repository = new Kiwi_Retention_Cleanup_Run_Repository();
        $run_table = $repository->get_table_name();
        if (!$this->is_identifier($run_table)) {
            return $this->blocked('run_table_identifier_invalid', 'The retention-run table identifier is invalid.');
        }

        $run = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$run_table} WHERE run_id = %s LIMIT 1", self::RUN_ID),
            ARRAY_A
        );
        if (!is_array($run)) {
            return $this->blocked('run_not_found', 'The expected interrupted retention run was not found.');
        }

        $source = (new Kiwi_Retention_Source_Registry())->get(self::SOURCE_KEY);
        if (!is_array($source)) {
            return $this->blocked('retention_source_unavailable', 'The landing-page-session retention source is unavailable.');
        }

        $source_table = (string) ($source['source_table'] ?? '');
        $primary_key = (string) ($source['primary_key'] ?? '');
        $cutoff_column = (string) ($source['cutoff_column'] ?? '');
        if (!$this->is_identifier($source_table)
            || !$this->is_identifier($primary_key)
            || !$this->is_identifier($cutoff_column)
        ) {
            return $this->blocked('source_identifier_invalid', 'The retention source contains an invalid SQL identifier.');
        }

        $archive = $this->inspect_archive_receipt($run, $source);
        $source_counts = $this->inspect_source_counts($source, $run, $archive);
        $checks = [];
        $this->check($checks, 'fixed_run_identity',
            (string) ($run['run_id'] ?? '') === self::RUN_ID
            && (string) ($run['source_key'] ?? '') === self::SOURCE_KEY
            && (string) ($run['source_table'] ?? '') === $source_table
            && (string) ($run['cutoff_column'] ?? '') === $cutoff_column,
            'The fixed run identity and source definition match.'
        );
        $this->check($checks, 'frozen_scope',
            (string) ($run['cutoff_value'] ?? '') === self::EXPECTED_CUTOFF_VALUE
            && (int) ($run['eligible_rows'] ?? 0) === self::EXPECTED_ELIGIBLE_ROWS
            && (int) ($run['target_max_primary_key'] ?? 0) === self::EXPECTED_TARGET_MAX_PRIMARY_KEY,
            'The immutable cutoff, eligible-row count, and target primary key match.'
        );
        $this->check($checks, 'recorded_coverage_gate_passed', $this->recorded_gate_passed($run),
            'The interrupted run recorded a passed coverage gate for the frozen cutoff.'
        );
        $this->check($checks, 'no_competing_retention_work',
            $this->no_competing_retention_work($allow_recovery_lock),
            'No retention worker or unowned scheduler lock/single event is active.'
        );

        foreach ((array) ($archive['checks'] ?? []) as $check) {
            $checks[] = $check;
        }
        foreach ((array) ($source_counts['checks'] ?? []) as $check) {
            $checks[] = $check;
        }

        $recovery_state = $this->determine_recovery_state($run, $archive, $source_counts);
        $this->check($checks, 'recovery_state_recognized', $recovery_state !== '',
            'The run and archive receipt are in a recognized bounded recovery state.'
        );

        $failed_checks = array_values(array_filter($checks, static function (array $check): bool {
            return empty($check['passed']);
        }));

        return [
            'success' => empty($failed_checks),
            'mode' => 'apply_preflight',
            'changed' => false,
            'run_id' => self::RUN_ID,
            'recovery_state' => $recovery_state,
            'run' => $this->compact_run($run),
            'source_definition' => $source,
            'archive_receipt' => $archive,
            'source' => $source_counts,
            'checks' => $checks,
            'blocking_checks' => array_values(array_map(static function (array $check): string {
                return (string) ($check['name'] ?? 'unknown_check');
            }, $failed_checks)),
        ];
    }

    private function inspect_archive_receipt(array $run, array $source): array
    {
        $checks = [];
        $year = substr((string) ($run['started_at'] ?? ''), 0, 4);
        $archive_root = rtrim((string) (new Kiwi_Config())->get_retention_archive_root(), '/\\');
        $archive_db_path = $archive_root . DIRECTORY_SEPARATOR . 'sqlite'
            . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_' . $year . '.sqlite';
        $this->check($checks, 'archive_file_readable', preg_match('/^\\d{4}$/', $year) === 1
            && is_file($archive_db_path) && is_readable($archive_db_path),
            'The immutable annual SQLite archive file exists and is readable.'
        );
        if (empty($checks[0]['passed']) || !class_exists('PDO')) {
            $this->check($checks, 'sqlite_pdo_available', class_exists('PDO'), 'PDO SQLite is available for receipt inspection.');

            return ['archive_db_path' => $archive_db_path, 'checks' => $checks];
        }

        try {
            $pdo = new PDO('sqlite:' . $archive_db_path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA query_only = ON');
            $batch = $this->sqlite_row(
                $pdo,
                'SELECT archive_batch_id, status, archived_rows, archive_inserted_rows, archive_duplicate_rows, started_at, finished_at, archive_db_path, error_message
                 FROM archive_batches WHERE archive_batch_id = :archive_batch_id',
                [':archive_batch_id' => self::ARCHIVE_BATCH_ID]
            );
            $evidence = $this->sqlite_row(
                $pdo,
                'SELECT COUNT(*) AS evidence_rows, MIN(source_pk) AS first_primary_key, MAX(source_pk) AS last_primary_key
                 FROM archive_batch_rows WHERE archive_batch_id = :archive_batch_id',
                [':archive_batch_id' => self::ARCHIVE_BATCH_ID]
            );
            $archive_table = $this->quote_sqlite_identifier((string) ($source['source_table'] ?? ''));
            $archived_rows = $this->sqlite_row(
                $pdo,
                'SELECT COUNT(*) AS archived_rows,
                        SUM(CASE WHEN _archive_batch_id = :archive_batch_id THEN 1 ELSE 0 END) AS current_batch_rows
                 FROM ' . $archive_table
                 . ' WHERE _source_pk >= :first_primary_key AND _source_pk <= :last_primary_key',
                [
                    ':archive_batch_id' => self::ARCHIVE_BATCH_ID,
                    ':first_primary_key' => self::EXPECTED_FIRST_PRIMARY_KEY,
                    ':last_primary_key' => self::EXPECTED_LAST_PRIMARY_KEY,
                ]
            );
        } catch (Throwable $error) {
            $this->check($checks, 'sqlite_receipt_readable', false, 'The SQLite batch receipt and archived rows can be read.');

            return ['archive_db_path' => $archive_db_path, 'checks' => $checks];
        }

        $evidence_rows = (int) ($evidence['evidence_rows'] ?? 0);
        $first_primary_key = (int) ($evidence['first_primary_key'] ?? 0);
        $last_primary_key = (int) ($evidence['last_primary_key'] ?? 0);
        $raw_archive_rows = (int) ($archived_rows['archived_rows'] ?? 0);
        $current_batch_rows = (int) ($archived_rows['current_batch_rows'] ?? 0);
        $this->check($checks, 'sqlite_receipt_readable', true, 'The SQLite batch receipt and archived rows can be read.');
        $this->check($checks, 'archive_evidence_exact', $evidence_rows === self::EXPECTED_EVIDENCE_ROWS
            && $first_primary_key === self::EXPECTED_FIRST_PRIMARY_KEY
            && $last_primary_key === self::EXPECTED_LAST_PRIMARY_KEY
            && $evidence_rows === ($last_primary_key - $first_primary_key + 1),
            'The receipt contains exactly 50,000 gap-free source primary keys.'
        );
        $this->check($checks, 'archive_rows_current_batch_exact', $raw_archive_rows === self::EXPECTED_EVIDENCE_ROWS
            && $current_batch_rows === self::EXPECTED_EVIDENCE_ROWS,
            'All receipt keys are actual archive rows written by this interrupted batch, not duplicates.'
        );

        return [
            'archive_db_path' => $archive_db_path,
            'archive_batch' => is_array($batch) ? $batch : [],
            'evidence_rows' => $evidence_rows,
            'evidence_first_primary_key' => $first_primary_key,
            'evidence_last_primary_key' => $last_primary_key,
            'raw_archive_rows' => $raw_archive_rows,
            'current_batch_rows' => $current_batch_rows,
            'checks' => $checks,
        ];
    }

    private function inspect_source_counts(array $source, array $run, array $archive): array
    {
        global $wpdb;

        $checks = [];
        $source_table = (string) ($source['source_table'] ?? '');
        $primary_key = (string) ($source['primary_key'] ?? '');
        $cutoff_column = (string) ($source['cutoff_column'] ?? '');
        $scope_rows = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$source_table}
             WHERE {$cutoff_column} < %s AND {$primary_key} <= %d",
            self::EXPECTED_CUTOFF_VALUE,
            self::EXPECTED_TARGET_MAX_PRIMARY_KEY
        ));
        $evidence_rows = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS rows_in_source, MIN({$primary_key}) AS first_primary_key, MAX({$primary_key}) AS last_primary_key
             FROM {$source_table}
             WHERE {$cutoff_column} < %s AND {$primary_key} <= %d
               AND {$primary_key} >= %d AND {$primary_key} <= %d",
            self::EXPECTED_CUTOFF_VALUE,
            self::EXPECTED_TARGET_MAX_PRIMARY_KEY,
            self::EXPECTED_FIRST_PRIMARY_KEY,
            self::EXPECTED_LAST_PRIMARY_KEY
        ), ARRAY_A);
        $rows_in_evidence_range = (int) ($evidence_rows['rows_in_source'] ?? 0);
        $first_primary_key = (int) ($evidence_rows['first_primary_key'] ?? 0);
        $last_primary_key = (int) ($evidence_rows['last_primary_key'] ?? 0);
        $deleted_rows = (int) ($run['deleted_rows'] ?? 0);
        $expected_remaining_evidence_rows = self::EXPECTED_EVIDENCE_ROWS - $deleted_rows;
        $expected_first_remaining_primary_key = $expected_remaining_evidence_rows > 0
            ? self::EXPECTED_FIRST_PRIMARY_KEY + $deleted_rows
            : 0;
        $expected_last_remaining_primary_key = $expected_remaining_evidence_rows > 0
            ? self::EXPECTED_LAST_PRIMARY_KEY
            : 0;

        $this->check($checks, 'frozen_source_scope_count', $scope_rows >= 0,
            'The frozen source scope can be counted.'
        );
        $this->check($checks, 'source_evidence_range_consistent', $rows_in_evidence_range === $expected_remaining_evidence_rows
            && ($expected_remaining_evidence_rows === 0
                || ($first_primary_key === $expected_first_remaining_primary_key
                    && $last_primary_key === $expected_last_remaining_primary_key)),
            'The source rows remaining in the receipt range exactly match persisted delete progress.'
        );

        return [
            'frozen_scope_rows' => $scope_rows,
            'source_evidence_rows' => $rows_in_evidence_range,
            'source_evidence_first_primary_key' => $first_primary_key,
            'source_evidence_last_primary_key' => $last_primary_key,
            'checks' => $checks,
        ];
    }

    private function determine_recovery_state(array $run, array $archive, array $source): string
    {
        $batch = (array) ($archive['archive_batch'] ?? []);
        $batch_status = (string) ($batch['status'] ?? '');
        $batch_rows = (int) ($batch['archived_rows'] ?? -1);
        $batch_inserted = (int) ($batch['archive_inserted_rows'] ?? -1);
        $batch_duplicates = (int) ($batch['archive_duplicate_rows'] ?? -1);
        $batch_finished = (string) ($batch['finished_at'] ?? '');
        $archived_rows = (int) ($run['archived_rows'] ?? 0);
        $inserted_rows = (int) ($run['archive_inserted_rows'] ?? 0);
        $duplicate_rows = (int) ($run['archive_duplicate_rows'] ?? 0);
        $deleted_rows = (int) ($run['deleted_rows'] ?? 0);
        $delete_last_primary_key = (int) ($run['delete_last_primary_key'] ?? 0);
        $archive_last_primary_key = (int) ($run['archive_last_primary_key'] ?? 0);
        $archive_path = (string) ($archive['archive_db_path'] ?? '');

        $interrupted_run = (((string) ($run['status'] ?? '') === 'running'
                && empty($run['finished_at'])
                && (string) ($run['error_code'] ?? '') === '')
            || ((string) ($run['status'] ?? '') === 'failed'
                && (string) ($run['error_code'] ?? '') === 'cron_timeout_suspected'
                && (string) ($run['worker_phase'] ?? '') === 'archive_running'))
            && (string) ($run['worker_phase'] ?? '') === 'archive_running'
            && (int) ($run['worker_runs'] ?? 0) === 1;
        $interrupted_batch = $batch_status === 'running'
            && $batch_rows === 0 && $batch_inserted === 0 && $batch_duplicates === 0 && $batch_finished === '';
        $reconciled_batch = $batch_status === 'partial'
            && $batch_rows === self::EXPECTED_EVIDENCE_ROWS
            && $batch_inserted === self::EXPECTED_EVIDENCE_ROWS
            && $batch_duplicates === 0 && $batch_finished === '';
        $initial_audit = $archived_rows === 0 && $inserted_rows === 0 && $duplicate_rows === 0
            && $deleted_rows === 0 && $archive_last_primary_key === 0 && $delete_last_primary_key === 0
            && trim((string) ($run['archive_db_path'] ?? '')) === '';

        if ($interrupted_run && $initial_audit && ($interrupted_batch || $reconciled_batch)
            && (int) ($source['frozen_scope_rows'] ?? -1) === self::EXPECTED_ELIGIBLE_ROWS
            && (int) ($source['source_evidence_rows'] ?? -1) === self::EXPECTED_EVIDENCE_ROWS) {
            return 'initial';
        }

        $reconciled_audit = (string) ($run['status'] ?? '') === 'partial'
            && (string) ($run['worker_phase'] ?? '') === 'recovery_delete_evidence'
            && empty($run['finished_at'])
            && $archived_rows === self::EXPECTED_EVIDENCE_ROWS
            && $inserted_rows === self::EXPECTED_EVIDENCE_ROWS
            && $duplicate_rows === 0
            && $archive_last_primary_key === self::EXPECTED_LAST_PRIMARY_KEY
            && (string) ($run['archive_db_path'] ?? '') === $archive_path
            && $deleted_rows >= 0 && $deleted_rows < self::EXPECTED_EVIDENCE_ROWS
            && $delete_last_primary_key === ($deleted_rows === 0 ? 0 : self::EXPECTED_FIRST_PRIMARY_KEY + $deleted_rows - 1);
        if ($reconciled_audit && $reconciled_batch
            && (int) ($source['frozen_scope_rows'] ?? -1) === self::EXPECTED_ELIGIBLE_ROWS - $deleted_rows) {
            return 'deleting_evidence';
        }

        $archive_remaining = (string) ($run['status'] ?? '') === 'partial'
            && (string) ($run['worker_phase'] ?? '') === 'recovery_archive_remaining'
            && empty($run['finished_at'])
            && $archived_rows === self::EXPECTED_EVIDENCE_ROWS
            && $inserted_rows === self::EXPECTED_EVIDENCE_ROWS
            && $duplicate_rows === 0
            && $deleted_rows === self::EXPECTED_EVIDENCE_ROWS
            && $archive_last_primary_key === self::EXPECTED_LAST_PRIMARY_KEY
            && $delete_last_primary_key === self::EXPECTED_LAST_PRIMARY_KEY
            && (string) ($run['archive_db_path'] ?? '') === $archive_path;
        if ($archive_remaining && $reconciled_batch
            && (int) ($source['frozen_scope_rows'] ?? -1) === self::EXPECTED_ELIGIBLE_ROWS - self::EXPECTED_EVIDENCE_ROWS
            && (int) ($source['source_evidence_rows'] ?? -1) === 0) {
            return 'archive_remaining';
        }

        return '';
    }

    private function reconcile_committed_archive_receipt(array $state, array $live_gate): bool
    {
        $archive = (array) ($state['archive_receipt'] ?? []);
        $archive_db_path = (string) ($archive['archive_db_path'] ?? '');
        $batch = (array) ($archive['archive_batch'] ?? []);

        if ((string) ($batch['status'] ?? '') === 'running') {
            try {
                $pdo = new PDO('sqlite:' . $archive_db_path);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec('PRAGMA foreign_keys = ON');
                $statement = $pdo->prepare(
                    'UPDATE archive_batches
                     SET archived_rows = :archived_rows,
                         archive_inserted_rows = :archive_inserted_rows,
                         archive_duplicate_rows = :archive_duplicate_rows,
                         status = :status,
                         finished_at = NULL,
                         error_message = :error_message
                     WHERE archive_batch_id = :archive_batch_id
                       AND status = :expected_status
                       AND archived_rows = 0
                       AND archive_inserted_rows = 0
                       AND archive_duplicate_rows = 0
                       AND finished_at IS NULL'
                );
                $statement->execute([
                    ':archived_rows' => self::EXPECTED_EVIDENCE_ROWS,
                    ':archive_inserted_rows' => self::EXPECTED_EVIDENCE_ROWS,
                    ':archive_duplicate_rows' => 0,
                    ':status' => 'partial',
                    ':error_message' => 'Reconciled committed archive receipt before controlled source deletion.',
                    ':archive_batch_id' => self::ARCHIVE_BATCH_ID,
                    ':expected_status' => 'running',
                ]);
                if ($statement->rowCount() !== 1) {
                    return false;
                }
            } catch (Throwable $error) {
                return false;
            }
        }

        $run = (array) ($state['run'] ?? []);
        $repository = new Kiwi_Retention_Cleanup_Run_Repository();

        return $repository->update_run((int) ($run['id'] ?? 0), [
            'status' => 'partial',
            'finished_at' => null,
            'worker_phase' => 'recovery_delete_evidence',
            'gate_status' => 'passed',
            'gate_results_json' => $live_gate,
            'archive_db_path' => $archive_db_path,
            'archive_integrity_check' => 'evidence_verified',
            'archived_rows' => self::EXPECTED_EVIDENCE_ROWS,
            'archive_inserted_rows' => self::EXPECTED_EVIDENCE_ROWS,
            'archive_duplicate_rows' => 0,
            'deleted_rows' => 0,
            'delete_batches' => 0,
            'archive_last_primary_key' => self::EXPECTED_LAST_PRIMARY_KEY,
            'delete_last_primary_key' => 0,
            'error_code' => '',
            'error_message' => 'Committed SQLite archive evidence reconciled; controlled source deletion is in progress.',
        ]);
    }

    private function delete_committed_evidence_batches(array $state): array
    {
        global $wpdb;

        $run = (array) ($state['run'] ?? []);
        $source = (array) ($state['source_definition'] ?? []);
        $archive_db_path = (string) (($state['archive_receipt'] ?? [])['archive_db_path'] ?? '');
        $source_table = (string) ($source['source_table'] ?? '');
        $primary_key = (string) ($source['primary_key'] ?? '');
        $cutoff_column = (string) ($source['cutoff_column'] ?? '');
        $deleted_rows = (int) ($run['deleted_rows'] ?? 0);
        $delete_batches = (int) ($run['delete_batches'] ?? 0);
        $last_primary_key = (int) ($run['delete_last_primary_key'] ?? 0);
        $repository = new Kiwi_Retention_Cleanup_Run_Repository();

        try {
            $pdo = new PDO('sqlite:' . $archive_db_path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA query_only = ON');
            $statement = $pdo->prepare(
                'SELECT source_pk FROM archive_batch_rows
                 WHERE archive_batch_id = :archive_batch_id AND source_pk > :last_primary_key
                 ORDER BY source_pk ASC LIMIT :batch_limit'
            );

            while ($deleted_rows < self::EXPECTED_EVIDENCE_ROWS) {
                $expected_count = min(self::DELETE_BATCH_SIZE, self::EXPECTED_EVIDENCE_ROWS - $deleted_rows);
                $statement->bindValue(':archive_batch_id', self::ARCHIVE_BATCH_ID, PDO::PARAM_STR);
                $statement->bindValue(':last_primary_key', $last_primary_key, PDO::PARAM_INT);
                $statement->bindValue(':batch_limit', $expected_count, PDO::PARAM_INT);
                $statement->execute();
                $primary_keys = array_map(static function (array $row): int {
                    return (int) ($row['source_pk'] ?? 0);
                }, $statement->fetchAll(PDO::FETCH_ASSOC));
                $primary_keys = array_values(array_filter($primary_keys, static function (int $primary_key): bool {
                    return $primary_key > 0;
                }));

                if (count($primary_keys) !== $expected_count
                    || $primary_keys[0] !== self::EXPECTED_FIRST_PRIMARY_KEY + $deleted_rows
                    || $primary_keys[count($primary_keys) - 1] !== self::EXPECTED_FIRST_PRIMARY_KEY + $deleted_rows + $expected_count - 1
                ) {
                    return $this->delete_failure('evidence_batch_invalid', 'The next SQLite receipt batch was not the expected contiguous bounded key range.', $deleted_rows, $delete_batches, $last_primary_key);
                }

                $placeholders = implode(', ', array_fill(0, count($primary_keys), '%d'));
                $scope_count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$source_table}
                     WHERE {$cutoff_column} < %s AND {$primary_key} <= %d
                       AND {$primary_key} IN ({$placeholders})",
                    self::EXPECTED_CUTOFF_VALUE,
                    self::EXPECTED_TARGET_MAX_PRIMARY_KEY,
                    ...$primary_keys
                ));
                if ($scope_count !== $expected_count) {
                    return $this->delete_failure('source_receipt_precondition_failed', 'A receipt-backed source batch no longer matched the frozen source scope; no batch was deleted.', $deleted_rows, $delete_batches, $last_primary_key);
                }

                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$source_table}
                     WHERE {$cutoff_column} < %s AND {$primary_key} <= %d
                       AND {$primary_key} IN ({$placeholders})",
                    self::EXPECTED_CUTOFF_VALUE,
                    self::EXPECTED_TARGET_MAX_PRIMARY_KEY,
                    ...$primary_keys
                ));
                if ($deleted === false || (int) $deleted !== $expected_count) {
                    return $this->delete_failure('source_receipt_delete_count_mismatch', 'A guarded source delete did not affect exactly its receipt-backed key count.', $deleted_rows, $delete_batches, $last_primary_key);
                }

                $deleted_rows += $expected_count;
                $delete_batches++;
                $last_primary_key = (int) $primary_keys[count($primary_keys) - 1];
                if (!$repository->update_run((int) ($run['id'] ?? 0), [
                    'status' => 'partial',
                    'worker_phase' => 'recovery_delete_evidence',
                    'deleted_rows' => $deleted_rows,
                    'delete_batches' => $delete_batches,
                    'delete_last_primary_key' => $last_primary_key,
                    'error_code' => '',
                    'error_message' => 'Controlled deletion of committed archive receipt keys is in progress.',
                ])) {
                    return $this->delete_failure('recovery_delete_audit_update_failed', 'A source batch was deleted but its audited delete progress could not be persisted. Stop and inspect the run before retrying.', $deleted_rows, $delete_batches, $last_primary_key);
                }
            }

            if (!$repository->update_run((int) ($run['id'] ?? 0), [
                'status' => 'partial',
                'worker_phase' => 'recovery_archive_remaining',
                'deleted_rows' => self::EXPECTED_EVIDENCE_ROWS,
                'delete_batches' => $delete_batches,
                'delete_last_primary_key' => self::EXPECTED_LAST_PRIMARY_KEY,
                'error_code' => '',
                'error_message' => 'Committed archive receipt keys were deleted; the existing worker will archive the remaining frozen scope.',
            ])) {
                return $this->delete_failure('recovery_handoff_audit_update_failed', 'All receipt-backed source keys were deleted but the archive-remaining handoff could not be persisted.', $deleted_rows, $delete_batches, $last_primary_key);
            }

            return [
                'success' => true,
                'deleted_rows' => $deleted_rows,
                'delete_batches' => $delete_batches,
                'delete_last_primary_key' => $last_primary_key,
            ];
        } catch (Throwable $error) {
            return $this->delete_failure('evidence_delete_exception', 'The controlled receipt deletion stopped before the remaining worker handoff.', $deleted_rows, $delete_batches, $last_primary_key);
        }
    }

    private function inspect_completed_state(): array
    {
        global $wpdb;

        $repository = new Kiwi_Retention_Cleanup_Run_Repository();
        $run_table = $repository->get_table_name();
        $run = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$run_table} WHERE run_id = %s LIMIT 1", self::RUN_ID), ARRAY_A);
        $source = (new Kiwi_Retention_Source_Registry())->get(self::SOURCE_KEY);
        if (!is_array($run) || !is_array($source)) {
            return ['success' => false, 'error_code' => 'postflight_context_unavailable'];
        }

        $archive = $this->inspect_archive_receipt($run, $source);
        $source_counts = $this->inspect_source_counts($source, $run, $archive);
        $batch = (array) ($archive['archive_batch'] ?? []);
        $completed = (string) ($run['status'] ?? '') === 'completed'
            && (string) ($run['worker_phase'] ?? '') === 'completed'
            && !empty($run['finished_at'])
            && (int) ($run['archived_rows'] ?? 0) === self::EXPECTED_ELIGIBLE_ROWS
            && (int) ($run['deleted_rows'] ?? 0) === self::EXPECTED_ELIGIBLE_ROWS
            && (int) ($run['archive_inserted_rows'] ?? 0) === self::EXPECTED_ELIGIBLE_ROWS
            && (int) ($run['archive_duplicate_rows'] ?? 0) === 0
            && (int) ($source_counts['frozen_scope_rows'] ?? -1) === 0
            && (string) ($batch['status'] ?? '') === 'success'
            && (int) ($batch['archived_rows'] ?? -1) === self::EXPECTED_ELIGIBLE_ROWS
            && (int) ($batch['archive_inserted_rows'] ?? -1) === self::EXPECTED_ELIGIBLE_ROWS
            && (int) ($batch['archive_duplicate_rows'] ?? -1) === 0;

        return [
            'success' => $completed,
            'run' => $this->compact_run($run),
            'archive_batch' => $batch,
            'frozen_scope_rows_remaining' => (int) ($source_counts['frozen_scope_rows'] ?? -1),
        ];
    }

    private function run_live_coverage_gate(array $source): array
    {
        return (new Kiwi_Retention_Coverage_Gate())->check_landing_page_sessions($source, self::EXPECTED_CUTOFF_VALUE);
    }

    private function live_coverage_gate_passed(array $gate): bool
    {
        return (string) ($gate['status'] ?? '') === 'passed'
            && (string) ($gate['requested_cutoff_value'] ?? '') === self::EXPECTED_CUTOFF_VALUE
            && (string) ($gate['effective_cutoff_value'] ?? '') === self::EXPECTED_CUTOFF_VALUE;
    }

    private function recorded_gate_passed(array $run): bool
    {
        $gate = json_decode((string) ($run['gate_results_json'] ?? ''), true);

        return (string) ($run['gate_status'] ?? '') === 'passed'
            && is_array($gate)
            && (string) ($gate['status'] ?? '') === 'passed'
            && (string) ($gate['requested_cutoff_value'] ?? '') === self::EXPECTED_CUTOFF_VALUE
            && (string) ($gate['effective_cutoff_value'] ?? '') === self::EXPECTED_CUTOFF_VALUE;
    }

    private function acquire_recovery_lock(): bool
    {
        if (!function_exists('set_transient') || !$this->no_competing_retention_work(false)) {
            return false;
        }

        return set_transient(self::RECOVERY_LOCK_KEY, self::RECOVERY_LOCK_VALUE, self::RECOVERY_LOCK_TTL_SECONDS);
    }

    private function no_competing_retention_work(bool $allow_recovery_lock): bool
    {
        if (!function_exists('get_transient') || !function_exists('wp_next_scheduled')) {
            return false;
        }

        $scheduler_lock = get_transient(self::RECOVERY_LOCK_KEY);
        $scheduler_ok = $scheduler_lock === false
            || ($allow_recovery_lock && $scheduler_lock === self::RECOVERY_LOCK_VALUE);

        return $scheduler_ok
            && get_transient('kiwi_retention_cleanup_worker_lock_' . self::SOURCE_KEY) === false
            && wp_next_scheduled('kiwi_retention_cleanup_worker') === false;
    }

    private function delete_failure(string $error_code, string $error_message, int $deleted_rows, int $delete_batches, int $last_primary_key): array
    {
        return [
            'success' => false,
            'mode' => 'apply',
            'changed' => true,
            'run_id' => self::RUN_ID,
            'error_code' => $error_code,
            'error_message' => $error_message,
            'deleted_rows_recorded_or_attempted' => $deleted_rows,
            'delete_batches_recorded_or_attempted' => $delete_batches,
            'delete_last_primary_key_recorded_or_attempted' => $last_primary_key,
        ];
    }

    private function compact_gate_result(array $gate): array
    {
        return [
            'status' => $gate['status'] ?? '',
            'requested_cutoff_value' => $gate['requested_cutoff_value'] ?? '',
            'effective_cutoff_value' => $gate['effective_cutoff_value'] ?? '',
            'verified_until_date' => $gate['verified_until_date'] ?? '',
            'candidate_date_count' => count((array) ($gate['candidate_dates'] ?? [])),
            'blocked_dates' => array_values((array) ($gate['blocked_dates'] ?? [])),
            'warning_dates' => array_values((array) ($gate['warning_dates'] ?? [])),
            'blocking_errors' => array_values((array) ($gate['blocking_errors'] ?? [])),
        ];
    }

    private function compact_worker_result(array $result): array
    {
        $keys = [
            'success', 'run_id', 'status', 'worker_phase', 'archive_db_path', 'archive_integrity_check',
            'archived_rows', 'archive_inserted_rows', 'archive_duplicate_rows', 'deleted_rows',
            'delete_batches', 'archive_last_primary_key', 'delete_last_primary_key', 'error_code', 'error_message',
        ];
        $compact = [];
        foreach ($keys as $key) {
            $compact[$key] = $result[$key] ?? null;
        }

        return $compact;
    }

    private function compact_run(array $run): array
    {
        $keys = [
            'id', 'run_id', 'status', 'worker_phase', 'error_code', 'started_at', 'finished_at', 'updated_at',
            'cutoff_value', 'eligible_rows', 'archived_rows', 'archive_inserted_rows', 'archive_duplicate_rows',
            'deleted_rows', 'delete_batches', 'archive_last_primary_key', 'delete_last_primary_key',
            'target_max_primary_key', 'archive_batch_id', 'archive_db_path', 'archive_integrity_check',
            'worker_runs', 'worker_last_started_at', 'worker_last_finished_at',
        ];
        $compact = [];
        foreach ($keys as $key) {
            $compact[$key] = $run[$key] ?? null;
        }

        return $compact;
    }

    private function sqlite_row(PDO $pdo, string $sql, array $parameters): ?array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function quote_sqlite_identifier(string $identifier): string
    {
        if (!$this->is_identifier($identifier)) {
            throw new InvalidArgumentException('Invalid SQLite identifier.');
        }

        return '"' . $identifier . '"';
    }

    private function is_identifier(string $value): bool
    {
        return $value !== '' && preg_match('/^[A-Za-z0-9_]+$/', $value) === 1;
    }

    private function check(array &$checks, string $name, bool $passed, string $description): void
    {
        $checks[] = [
            'name' => $name,
            'passed' => $passed,
            'description' => $description,
        ];
    }

    private function blocked(string $error_code, string $error_message): array
    {
        return [
            'success' => false,
            'mode' => 'apply_preflight',
            'changed' => false,
            'run_id' => self::RUN_ID,
            'error_code' => $error_code,
            'error_message' => $error_message,
        ];
    }

    private function emit_and_halt(array $result, int $exit_code): void
    {
        if (function_exists('get_transient')
            && function_exists('delete_transient')
            && get_transient(self::RECOVERY_LOCK_KEY) === self::RECOVERY_LOCK_VALUE
        ) {
            delete_transient(self::RECOVERY_LOCK_KEY);
        }

        $json = function_exists('wp_json_encode')
            ? wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            WP_CLI::line('{"success":false,"error_code":"json_encode_failed"}');
            WP_CLI::halt(1);
        }

        WP_CLI::line($json);
        WP_CLI::halt($exit_code);
    }

    private function fail(string $message): void
    {
        WP_CLI::error($message, false);
        WP_CLI::halt(1);
    }
}

WP_CLI::add_command('kiwi', new Kiwi_Retention_Recovery_20260724_Apply_Namespace());
$registered = WP_CLI::add_command(
    'kiwi retention-recovery-20260724',
    new Kiwi_Retention_Recovery_20260724_Apply_Command(),
    [
        'when' => 'before_wp_load',
        'shortdesc' => 'Explicitly confirmed apply runner for the interrupted 2026-07-24 retention cleanup run.',
    ]
);

if (!$registered) {
    WP_CLI::error('WP-CLI could not register the retention recovery apply command.', false);
    WP_CLI::halt(1);
}
