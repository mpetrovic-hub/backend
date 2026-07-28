<?php

/**
 * One-time, read-only preflight for the interrupted 2026-07-24 retention run.
 *
 * Load only through WP-CLI's global --require option. The command halts after
 * plugins_loaded and before init so no website or cron runtime hooks execute.
 *
 * This artifact deliberately has no apply command. It cannot update MySQL,
 * SQLite, WordPress options, transients, scheduled events, or archive files.
 */

function kiwi_retention_recovery_preflight_cli_has_required_api(string $class_name): bool
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

if (!defined('WP_CLI') || !WP_CLI || !kiwi_retention_recovery_preflight_cli_has_required_api('WP_CLI')) {
    if (defined('STDERR')) {
        fwrite(
            STDERR,
            "This recovery preflight requires WP-CLI 2.12 core APIs and must be loaded through --require.\n"
        );
    }

    exit(1);
}

final class Kiwi_Retention_Recovery_20260724_Namespace
{
}

final class Kiwi_Retention_Recovery_20260724_Preflight_Command
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

    /**
     * Inspect all recovery gates. This command intentionally has no apply mode.
     */
    public function preflight(array $args, array $assoc_args): void
    {
        $runner = WP_CLI::get_runner();
        if (!is_object($runner) || !method_exists($runner, 'load_wordpress')) {
            $this->fail('WP-CLI cannot provide the required WordPress loader.');
        }

        $executed = false;
        $hook_added = WP_CLI::add_wp_hook(
            'plugins_loaded',
            function () use (&$executed): void {
                $executed = true;
                $this->execute();
            }
        );

        if (!$hook_added) {
            $this->fail('WP-CLI could not register the recovery preflight lifecycle hook.');
        }

        $runner->load_wordpress();

        if (!$executed) {
            $this->fail('WordPress did not reach plugins_loaded; no recovery preflight was executed.');
        }

        $this->fail('The recovery preflight returned without stopping before WordPress init.');
    }

    private function execute(): void
    {
        if (!function_exists('did_action')
            || did_action('plugins_loaded') < 1
            || did_action('init') > 0
        ) {
            $this->fail('The recovery preflight must execute after plugins_loaded and before WordPress init.');
        }

        foreach ([
            'Kiwi_Config',
            'Kiwi_Retention_Source_Registry',
            'Kiwi_Retention_Cleanup_Run_Repository',
        ] as $required_class) {
            if (!class_exists($required_class)) {
                $this->fail('Kiwi Backend must be active and fully loaded before the recovery preflight runs.');
            }
        }

        try {
            $result = $this->inspect();
        } catch (Throwable $error) {
            $result = [
                'success' => false,
                'mode' => 'preflight',
                'run_id' => self::RUN_ID,
                'error_code' => 'recovery_preflight_failed',
                'error_message' => 'The read-only recovery preflight could not complete safely.',
            ];
        }

        $this->emit_and_halt($result, empty($result['success']) ? 1 : 0);
    }

    private function inspect(): array
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
            $wpdb->prepare(
                "SELECT * FROM {$run_table} WHERE run_id = %s LIMIT 1",
                self::RUN_ID
            ),
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

        $checks = [];
        $this->check($checks, 'fixed_run_identity',
            (string) ($run['run_id'] ?? '') === self::RUN_ID
            && (string) ($run['source_key'] ?? '') === self::SOURCE_KEY
            && (string) ($run['source_table'] ?? '') === $source_table
            && (string) ($run['cutoff_column'] ?? '') === $cutoff_column,
            'The fixed run identity and source definition match.'
        );
        $this->check($checks, 'interruption_state',
            $this->is_expected_interruption_state($run),
            'The run is in the expected interrupted archive phase.'
        );
        $this->check($checks, 'frozen_scope',
            (string) ($run['cutoff_value'] ?? '') === self::EXPECTED_CUTOFF_VALUE
            && (int) ($run['eligible_rows'] ?? 0) === self::EXPECTED_ELIGIBLE_ROWS
            && (int) ($run['target_max_primary_key'] ?? 0) === self::EXPECTED_TARGET_MAX_PRIMARY_KEY,
            'The frozen cutoff, eligible-row count, and target primary key match.'
        );
        $this->check($checks, 'empty_audit_progress',
            (int) ($run['archived_rows'] ?? 0) === 0
            && (int) ($run['deleted_rows'] ?? 0) === 0
            && (int) ($run['archive_last_primary_key'] ?? 0) === 0
            && (int) ($run['delete_last_primary_key'] ?? 0) === 0
            && trim((string) ($run['archive_db_path'] ?? '')) === '',
            'The audit contains no falsely advanced archive or delete progress.'
        );
        $this->check($checks, 'no_active_retention_lock', !$this->has_active_retention_lock(),
            'No scheduler or worker retention lock is active.'
        );
        $this->check($checks, 'no_scheduled_retention_worker', !$this->has_scheduled_worker(),
            'No retention worker single event is scheduled.'
        );

        $coverage = $this->inspect_recorded_coverage_gate($run);
        $this->check($checks, 'recorded_coverage_gate_passed',
            (string) ($coverage['status'] ?? '') === 'passed'
            && (string) ($coverage['recorded_status'] ?? '') === 'passed'
            && (string) ($coverage['requested_cutoff_value'] ?? '') === self::EXPECTED_CUTOFF_VALUE
            && (string) ($coverage['effective_cutoff_value'] ?? '') === self::EXPECTED_CUTOFF_VALUE,
            'The interrupted run recorded a passed coverage gate for the frozen cutoff.'
        );

        $archive = $this->inspect_archive_evidence($run);
        foreach ((array) ($archive['checks'] ?? []) as $check) {
            $checks[] = $check;
        }

        $source_counts = $this->inspect_source_counts(
            $source_table,
            $primary_key,
            $cutoff_column,
            (string) ($run['cutoff_value'] ?? ''),
            (int) ($run['target_max_primary_key'] ?? 0),
            (int) ($archive['evidence_rows'] ?? 0),
            (int) ($archive['evidence_first_primary_key'] ?? 0),
            (int) ($archive['evidence_last_primary_key'] ?? 0)
        );
        foreach ((array) ($source_counts['checks'] ?? []) as $check) {
            $checks[] = $check;
        }

        $failed_checks = array_values(array_filter($checks, static function (array $check): bool {
            return empty($check['passed']);
        }));

        return [
            'success' => empty($failed_checks),
            'mode' => 'preflight',
            'read_only' => true,
            'run_id' => self::RUN_ID,
            'apply_available' => false,
            'run' => $this->compact_run($run),
            'coverage_gate' => $coverage,
            'archive_evidence' => $this->without_primary_keys($archive),
            'source' => $source_counts,
            'checks' => $checks,
            'blocking_checks' => array_values(array_map(static function (array $check): string {
                return (string) ($check['name'] ?? 'unknown_check');
            }, $failed_checks)),
            'next_step' => empty($failed_checks)
                ? 'Preflight passed. No data changed; a separately reviewed apply runner is required before any recovery write.'
                : 'Preflight blocked. Do not attempt recovery writes until every blocking check is understood and resolved.',
        ];
    }

    private function inspect_archive_evidence(array $run): array
    {
        $checks = [];
        $year = substr((string) ($run['started_at'] ?? ''), 0, 4);
        if (preg_match('/^\d{4}$/', $year) !== 1) {
            $this->check($checks, 'archive_year_valid', false, 'The interrupted run has a valid archive year.');

            return ['checks' => $checks];
        }

        $archive_root = rtrim((string) (new Kiwi_Config())->get_retention_archive_root(), '/\\');
        $archive_db_path = $archive_root . DIRECTORY_SEPARATOR . 'sqlite'
            . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_' . $year . '.sqlite';
        $this->check($checks, 'archive_file_readable', is_file($archive_db_path) && is_readable($archive_db_path),
            'The expected SQLite archive file exists and is readable.'
        );
        if (!is_file($archive_db_path) || !is_readable($archive_db_path) || !class_exists('PDO')) {
            $this->check($checks, 'sqlite_pdo_available', class_exists('PDO'), 'PDO is available for read-only SQLite inspection.');

            return [
                'archive_db_path' => $archive_db_path,
                'checks' => $checks,
            ];
        }

        $this->check($checks, 'sqlite_pdo_available', true, 'PDO is available for read-only SQLite inspection.');

        try {
            $pdo = new PDO('sqlite:' . $archive_db_path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA query_only = ON');
            $batch = $this->sqlite_row(
                $pdo,
                'SELECT archive_batch_id, status, archived_rows, archive_inserted_rows, archive_duplicate_rows, started_at, finished_at, archive_db_path
                 FROM archive_batches
                 WHERE archive_batch_id = :archive_batch_id',
                [':archive_batch_id' => self::ARCHIVE_BATCH_ID]
            );
            $evidence = $this->sqlite_row(
                $pdo,
                'SELECT COUNT(*) AS evidence_rows,
                        MIN(source_pk) AS first_primary_key,
                        MAX(source_pk) AS last_primary_key
                 FROM archive_batch_rows
                 WHERE archive_batch_id = :archive_batch_id',
                [':archive_batch_id' => self::ARCHIVE_BATCH_ID]
            );
        } catch (Throwable $error) {
            $this->check($checks, 'sqlite_evidence_readable', false, 'The expected SQLite batch evidence can be read.');

            return [
                'archive_db_path' => $archive_db_path,
                'checks' => $checks,
            ];
        }

        $evidence_row_count = (int) ($evidence['evidence_rows'] ?? 0);
        $evidence_first_primary_key = (int) ($evidence['first_primary_key'] ?? 0);
        $evidence_last_primary_key = (int) ($evidence['last_primary_key'] ?? 0);

        $this->check($checks, 'sqlite_evidence_readable', true, 'The expected SQLite batch evidence can be read.');
        $this->check($checks, 'archive_batch_identity', is_array($batch)
            && (string) ($batch['archive_batch_id'] ?? '') === self::ARCHIVE_BATCH_ID
            && (string) ($batch['status'] ?? '') === 'running'
            && (int) ($batch['archived_rows'] ?? -1) === 0
            && (int) ($batch['archive_inserted_rows'] ?? -1) === 0
            && (int) ($batch['archive_duplicate_rows'] ?? -1) === 0
            && empty($batch['finished_at']),
            'The SQLite batch has the expected interrupted, not falsely completed state.'
        );
        $this->check($checks, 'sqlite_evidence_range', $evidence_row_count === self::EXPECTED_EVIDENCE_ROWS
            && $evidence_first_primary_key === self::EXPECTED_FIRST_PRIMARY_KEY
            && $evidence_last_primary_key === self::EXPECTED_LAST_PRIMARY_KEY
            && $evidence_row_count === ($evidence_last_primary_key - $evidence_first_primary_key + 1),
            'The SQLite evidence contains the expected 50,000 unique, gap-free primary keys and range.'
        );

        $this->check($checks, 'archive_evidence_committed', $evidence_row_count === self::EXPECTED_EVIDENCE_ROWS,
            'The committed archive_batch_rows primary-key evidence is present; this is the archive-before-delete receipt.'
        );

        return [
            'archive_db_path' => $archive_db_path,
            'archive_batch' => is_array($batch) ? $batch : [],
            'evidence_rows' => $evidence_row_count,
            'evidence_first_primary_key' => $evidence_first_primary_key,
            'evidence_last_primary_key' => $evidence_last_primary_key,
            'checks' => $checks,
        ];
    }

    private function inspect_source_counts(
        string $source_table,
        string $primary_key,
        string $cutoff_column,
        string $cutoff_value,
        int $target_max_primary_key,
        int $evidence_row_count,
        int $evidence_first_primary_key,
        int $evidence_last_primary_key
    ): array {
        global $wpdb;

        $checks = [];
        if ($cutoff_value !== self::EXPECTED_CUTOFF_VALUE || $target_max_primary_key !== self::EXPECTED_TARGET_MAX_PRIMARY_KEY) {
            $this->check($checks, 'source_scope_valid', false, 'The source scope is valid for exact recovery checks.');

            return ['checks' => $checks];
        }

        $eligible_rows = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$source_table}
                 WHERE {$cutoff_column} < %s
                   AND {$primary_key} <= %d",
                $cutoff_value,
                $target_max_primary_key
            )
        );
        $this->check($checks, 'frozen_source_row_count', $eligible_rows === self::EXPECTED_ELIGIBLE_ROWS,
            'All 66,418 rows from the frozen source scope are still present before recovery.'
        );

        $source_evidence = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS matching_evidence_rows,
                        MIN({$primary_key}) AS first_primary_key,
                        MAX({$primary_key}) AS last_primary_key
                 FROM {$source_table}
                 WHERE {$cutoff_column} < %s
                   AND {$primary_key} >= %d
                   AND {$primary_key} <= %d",
                $cutoff_value,
                $evidence_first_primary_key,
                $evidence_last_primary_key
            ),
            ARRAY_A
        );
        $matching_evidence_rows = (int) ($source_evidence['matching_evidence_rows'] ?? 0);
        $matching_first_primary_key = (int) ($source_evidence['first_primary_key'] ?? 0);
        $matching_last_primary_key = (int) ($source_evidence['last_primary_key'] ?? 0);

        $this->check($checks, 'source_rows_match_evidence', $evidence_row_count === self::EXPECTED_EVIDENCE_ROWS
            && $matching_evidence_rows === $evidence_row_count
            && $matching_first_primary_key === $evidence_first_primary_key
            && $matching_last_primary_key === $evidence_last_primary_key,
            'Every archived evidence primary key still exists in the exact frozen MySQL source scope.'
        );

        return [
            'frozen_scope_rows' => $eligible_rows,
            'matching_evidence_rows' => $matching_evidence_rows,
            'matching_evidence_first_primary_key' => $matching_first_primary_key,
            'matching_evidence_last_primary_key' => $matching_last_primary_key,
            'remaining_after_evidence_delete' => max(0, $eligible_rows - $matching_evidence_rows),
            'checks' => $checks,
        ];
    }

    private function inspect_recorded_coverage_gate(array $run): array
    {
        $gate_results = json_decode((string) ($run['gate_results_json'] ?? ''), true);
        if (!is_array($gate_results)) {
            return [
                'status' => 'failed',
                'blocking_errors' => ['recorded_gate_results_invalid'],
                'recheck_performed' => false,
            ];
        }

        return [
            'status' => (string) ($run['gate_status'] ?? ''),
            'recorded_status' => (string) ($gate_results['status'] ?? ''),
            'requested_cutoff_value' => (string) ($gate_results['requested_cutoff_value'] ?? ''),
            'effective_cutoff_value' => (string) ($gate_results['effective_cutoff_value'] ?? ''),
            'verified_until_date' => (string) ($gate_results['verified_until_date'] ?? ''),
            'blocked_dates' => array_values((array) ($gate_results['blocked_dates'] ?? [])),
            'warning_dates' => array_values((array) ($gate_results['warning_dates'] ?? [])),
            'blocking_errors' => array_values((array) ($gate_results['blocking_errors'] ?? [])),
            'recheck_performed' => false,
            'recheck_required_before_apply' => true,
        ];
    }

    private function has_active_retention_lock(): bool
    {
        if (!function_exists('get_transient')) {
            return true;
        }

        return get_transient('kiwi_retention_cleanup_lock_' . self::SOURCE_KEY) !== false
            || get_transient('kiwi_retention_cleanup_worker_lock_' . self::SOURCE_KEY) !== false;
    }

    private function has_scheduled_worker(): bool
    {
        return function_exists('wp_next_scheduled')
            && wp_next_scheduled('kiwi_retention_cleanup_worker') !== false;
    }

    private function is_expected_interruption_state(array $run): bool
    {
        $status = (string) ($run['status'] ?? '');
        $error_code = (string) ($run['error_code'] ?? '');
        $unfinished = empty($run['finished_at']);
        $fresh_interruption = $status === 'running' && $unfinished && $error_code === '';
        $stale_interruption = $status === 'failed'
            && $error_code === 'cron_timeout_suspected'
            && (string) ($run['worker_phase'] ?? '') === 'archive_running';

        return ($fresh_interruption || $stale_interruption)
            && (string) ($run['worker_phase'] ?? '') === 'archive_running'
            && (int) ($run['worker_runs'] ?? 0) === 1;
    }

    private function compact_run(array $run): array
    {
        $keys = [
            'id', 'run_id', 'status', 'worker_phase', 'error_code', 'started_at',
            'finished_at', 'updated_at', 'cutoff_value', 'eligible_rows',
            'archived_rows', 'deleted_rows', 'archive_last_primary_key',
            'delete_last_primary_key', 'target_max_primary_key', 'archive_batch_id',
            'archive_db_path', 'worker_runs', 'worker_last_started_at',
            'worker_last_finished_at',
        ];
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $run[$key] ?? null;
        }

        return $result;
    }

    private function without_primary_keys(array $archive): array
    {
        unset($archive['checks']);

        return $archive;
    }

    private function sqlite_row(PDO $pdo, string $sql, array $parameters): ?array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function sqlite_rows(PDO $pdo, string $sql, array $parameters): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
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
            'mode' => 'preflight',
            'read_only' => true,
            'run_id' => self::RUN_ID,
            'apply_available' => false,
            'error_code' => $error_code,
            'error_message' => $error_message,
        ];
    }

    private function emit_and_halt(array $result, int $exit_code): void
    {
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

WP_CLI::add_command('kiwi', new Kiwi_Retention_Recovery_20260724_Namespace());
$registered = WP_CLI::add_command(
    'kiwi retention-recovery-20260724',
    new Kiwi_Retention_Recovery_20260724_Preflight_Command(),
    [
        'when' => 'before_wp_load',
        'shortdesc' => 'Read-only preflight for the interrupted 2026-07-24 retention cleanup run.',
    ]
);

if (!$registered) {
    WP_CLI::error('WP-CLI could not register the retention recovery preflight command.', false);
    WP_CLI::halt(1);
}
