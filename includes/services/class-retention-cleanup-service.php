<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Retention_Cleanup_Service
{
    private const LOCK_PREFIX = 'kiwi_retention_cleanup_lock_';

    private $config;
    private $source_registry;
    private $run_repository;
    private $snapshot_repository;
    private $archive_service;
    private $coverage_gate;

    public function __construct(
        ?Kiwi_Config $config = null,
        ?Kiwi_Retention_Source_Registry $source_registry = null,
        ?Kiwi_Retention_Cleanup_Run_Repository $run_repository = null,
        ?Kiwi_Retention_Table_Growth_Snapshot_Repository $snapshot_repository = null,
        ?Kiwi_Retention_Sqlite_Archive_Service $archive_service = null,
        ?Kiwi_Retention_Coverage_Gate $coverage_gate = null
    ) {
        $this->config = $config instanceof Kiwi_Config ? $config : new Kiwi_Config();
        $this->source_registry = $source_registry instanceof Kiwi_Retention_Source_Registry
            ? $source_registry
            : new Kiwi_Retention_Source_Registry();
        $this->run_repository = $run_repository instanceof Kiwi_Retention_Cleanup_Run_Repository
            ? $run_repository
            : new Kiwi_Retention_Cleanup_Run_Repository();
        $this->snapshot_repository = $snapshot_repository instanceof Kiwi_Retention_Table_Growth_Snapshot_Repository
            ? $snapshot_repository
            : new Kiwi_Retention_Table_Growth_Snapshot_Repository();
        $this->archive_service = $archive_service instanceof Kiwi_Retention_Sqlite_Archive_Service
            ? $archive_service
            : new Kiwi_Retention_Sqlite_Archive_Service($this->config);
        $this->coverage_gate = $coverage_gate instanceof Kiwi_Retention_Coverage_Gate
            ? $coverage_gate
            : new Kiwi_Retention_Coverage_Gate($this->config);
    }

    public function run_source(string $source_key, string $triggered_by = 'cron'): array
    {
        $source = $this->source_registry->get($source_key);

        if (!is_array($source)) {
            return [
                'success' => false,
                'status' => 'failed',
                'error_code' => 'unknown_source',
                'error_message' => 'Unknown retention source: ' . $source_key,
            ];
        }

        $settings = $this->config->get_retention_source_settings($source_key);
        $enabled = !empty($settings['enabled']);
        $dry_run = !empty($settings['dry_run']);
        $retention_days = $this->resolve_retention_days($settings, $source);
        $cutoff_value = $this->build_cutoff_value($retention_days);
        $run_id = $this->generate_run_id();
        $archive_batch_id = $this->generate_archive_batch_id($source_key);
        $started_at = $this->current_time_mysql();
        $eligible_rows = $this->count_eligible_rows($source, $cutoff_value);
        $run_db_id = $this->run_repository->create_run([
            'run_id' => $run_id,
            'source_key' => $source_key,
            'source_table' => (string) ($source['source_table'] ?? ''),
            'status' => 'skipped',
            'triggered_by' => $this->normalize_triggered_by($triggered_by),
            'enabled' => $enabled,
            'dry_run' => $dry_run,
            'started_at' => $started_at,
            'retention_days_effective' => $retention_days,
            'cutoff_column' => (string) ($source['cutoff_column'] ?? ''),
            'cutoff_value' => $cutoff_value,
            'eligible_rows' => $eligible_rows,
            'archive_batch_id' => $archive_batch_id,
        ]);

        if ($run_db_id <= 0) {
            return [
                'success' => false,
                'run_id' => $run_id,
                'status' => 'failed',
                'error_code' => 'run_audit_create_failed',
                'error_message' => 'Retention cleanup aborted because the audit run row could not be created.',
                'eligible_rows' => $eligible_rows,
            ];
        }

        if ($this->has_lock($source_key)) {
            return $this->finish_run($run_db_id, [
                'success' => true,
                'run_id' => $run_id,
                'status' => 'skipped',
                'error_code' => 'lock_active',
                'error_message' => 'Retention cleanup skipped because lock is active.',
            ]);
        }

        $this->set_lock($source_key);

        try {
            $this->snapshot_repository->capture_snapshot(
                $source,
                'before_cleanup',
                $retention_days,
                $cutoff_value,
                $eligible_rows,
                $run_id,
                '',
                0,
                0
            );

            if (!$enabled) {
                $this->snapshot_repository->capture_snapshot(
                    $source,
                    'after_cleanup',
                    $retention_days,
                    $cutoff_value,
                    $eligible_rows,
                    $run_id,
                    '',
                    0,
                    0
                );

                return $this->finish_run($run_db_id, [
                    'success' => true,
                    'run_id' => $run_id,
                    'status' => 'skipped',
                    'gate_status' => 'skipped',
                    'error_code' => 'cleanup_disabled',
                    'error_message' => 'Retention cleanup is disabled for this source.',
                ]);
            }

            $gate_results = $this->coverage_gate->check_landing_page_sessions($source, $cutoff_value);
            $gate_status = (string) ($gate_results['status'] ?? 'failed');

            if ($dry_run) {
                $this->snapshot_repository->capture_snapshot(
                    $source,
                    'after_cleanup',
                    $retention_days,
                    $cutoff_value,
                    $eligible_rows,
                    $run_id,
                    '',
                    0,
                    0
                );

                return $this->finish_run($run_db_id, [
                    'success' => true,
                    'run_id' => $run_id,
                    'status' => 'success',
                    'gate_status' => $gate_status,
                    'gate_results_json' => $gate_results,
                    'eligible_rows' => $eligible_rows,
                ]);
            }

            if ($gate_status !== 'passed') {
                $this->snapshot_repository->capture_snapshot(
                    $source,
                    'after_cleanup',
                    $retention_days,
                    $cutoff_value,
                    $eligible_rows,
                    $run_id,
                    '',
                    0,
                    0
                );

                return $this->finish_run($run_db_id, [
                    'success' => true,
                    'run_id' => $run_id,
                    'status' => 'skipped',
                    'gate_status' => $gate_status,
                    'gate_results_json' => $gate_results,
                    'error_code' => 'coverage_gate_failed',
                    'error_message' => 'Retention cleanup skipped because summary coverage gate failed.',
                ]);
            }

            $archive_result = $this->archive_service->archive_eligible_rows(
                $source,
                $cutoff_value,
                $archive_batch_id,
                $this->config->get_retention_default_batch_limit()
            );
            $archived_rows = (int) ($archive_result['archived_rows'] ?? 0);
            $archive_inserted_rows = (int) ($archive_result['archive_inserted_rows'] ?? 0);
            $archive_duplicate_rows = (int) ($archive_result['archive_duplicate_rows'] ?? 0);
            $archive_integrity_check = (string) ($archive_result['archive_integrity_check'] ?? '');

            if (empty($archive_result['success']) || $archived_rows !== $eligible_rows) {
                $this->snapshot_repository->capture_snapshot(
                    $source,
                    'after_cleanup',
                    $retention_days,
                    $cutoff_value,
                    $eligible_rows,
                    $run_id,
                    $archive_batch_id,
                    $archived_rows,
                    0
                );

                return $this->finish_run($run_db_id, [
                    'success' => false,
                    'run_id' => $run_id,
                    'status' => 'failed',
                    'gate_status' => $gate_status,
                    'gate_results_json' => $gate_results,
                    'eligible_rows' => $eligible_rows,
                    'archived_rows' => $archived_rows,
                    'archive_inserted_rows' => $archive_inserted_rows,
                    'archive_duplicate_rows' => $archive_duplicate_rows,
                    'archive_batch_id' => $archive_batch_id,
                    'archive_db_path' => (string) ($archive_result['archive_db_path'] ?? ''),
                    'archive_integrity_check' => $archive_integrity_check,
                    'error_code' => (string) ($archive_result['error_code'] ?? 'archive_count_mismatch'),
                    'error_message' => (string) ($archive_result['error_message'] ?? 'Archived row count did not match eligible row count.'),
                ]);
            }

            $delete_result = $this->delete_eligible_rows(
                $source,
                $cutoff_value,
                $this->config->get_retention_default_batch_limit()
            );
            $deleted_rows = (int) ($delete_result['deleted_rows'] ?? 0);
            $delete_batches = (int) ($delete_result['delete_batches'] ?? 0);

            $this->snapshot_repository->capture_snapshot(
                $source,
                'after_cleanup',
                $retention_days,
                $cutoff_value,
                $eligible_rows,
                $run_id,
                $archive_batch_id,
                $archived_rows,
                $deleted_rows
            );

            return $this->finish_run($run_db_id, [
                'success' => true,
                'run_id' => $run_id,
                'status' => 'success',
                'gate_status' => $gate_status,
                'gate_results_json' => $gate_results,
                'eligible_rows' => $eligible_rows,
                'archived_rows' => $archived_rows,
                'archive_inserted_rows' => $archive_inserted_rows,
                'archive_duplicate_rows' => $archive_duplicate_rows,
                'deleted_rows' => $deleted_rows,
                'delete_batches' => $delete_batches,
                'archive_batch_id' => $archive_batch_id,
                'archive_db_path' => (string) ($archive_result['archive_db_path'] ?? ''),
                'archive_integrity_check' => $archive_integrity_check,
            ]);
        } catch (Throwable $error) {
            return $this->finish_run($run_db_id, [
                'success' => false,
                'run_id' => $run_id,
                'status' => 'failed',
                'error_code' => 'cleanup_exception',
                'error_message' => $error->getMessage(),
            ]);
        } finally {
            $this->clear_lock($source_key);
        }
    }

    protected function count_eligible_rows(array $source, string $cutoff_value): int
    {
        global $wpdb;

        $source_table = (string) ($source['source_table'] ?? '');
        $cutoff_column = (string) ($source['cutoff_column'] ?? '');

        if (!$this->is_identifier($source_table) || !$this->is_identifier($cutoff_column)) {
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$source_table} WHERE {$cutoff_column} < %s",
                $cutoff_value
            )
        );
    }

    protected function delete_eligible_rows(array $source, string $cutoff_value, int $batch_limit): array
    {
        global $wpdb;

        $source_table = (string) ($source['source_table'] ?? '');
        $primary_key = (string) ($source['primary_key'] ?? '');
        $cutoff_column = (string) ($source['cutoff_column'] ?? '');
        $batch_limit = max(1, $batch_limit);
        $deleted_rows = 0;
        $delete_batches = 0;

        if (!$this->is_identifier($source_table)
            || !$this->is_identifier($primary_key)
            || !$this->is_identifier($cutoff_column)
        ) {
            return [
                'deleted_rows' => 0,
                'delete_batches' => 0,
            ];
        }

        while (true) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT {$primary_key} AS id
                     FROM {$source_table}
                     WHERE {$cutoff_column} < %s
                     ORDER BY {$primary_key} ASC
                     LIMIT %d",
                    $cutoff_value,
                    $batch_limit
                ),
                ARRAY_A
            );

            if (!is_array($rows) || empty($rows)) {
                break;
            }

            $ids = array_values(array_filter(array_map(static function (array $row): int {
                return (int) ($row['id'] ?? 0);
            }, $rows)));

            if (empty($ids)) {
                break;
            }

            $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$source_table} WHERE {$primary_key} IN ({$placeholders})",
                    ...$ids
                )
            );

            if ($deleted === false) {
                throw new RuntimeException('Retention delete query failed.');
            }

            $deleted_rows += (int) $deleted;
            $delete_batches++;

            if (count($ids) < $batch_limit) {
                break;
            }
        }

        return [
            'deleted_rows' => $deleted_rows,
            'delete_batches' => $delete_batches,
        ];
    }

    protected function build_cutoff_value(int $retention_days): string
    {
        $current_date = substr($this->current_time_mysql(), 0, 10);
        $timestamp = strtotime($current_date . ' -' . max(1, $retention_days) . ' days');

        return ($timestamp === false ? $current_date : gmdate('Y-m-d', $timestamp)) . ' 00:00:00';
    }

    private function finish_run(int $run_db_id, array $result): array
    {
        $update = array_merge($result, [
            'finished_at' => $this->current_time_mysql(),
        ]);
        unset($update['success'], $update['run_id']);

        if ($run_db_id > 0) {
            $this->run_repository->update_run($run_db_id, $update);
        }

        return $result;
    }

    private function resolve_retention_days(array $settings, array $source): int
    {
        $minimum = max(1, (int) ($source['retention_days_min'] ?? 1));
        $default = max($minimum, (int) ($source['retention_days_default'] ?? $minimum));

        return max($minimum, (int) ($settings['retention_days'] ?? $default));
    }

    private function normalize_triggered_by(string $triggered_by): string
    {
        $triggered_by = strtolower(trim($triggered_by));

        return in_array($triggered_by, ['cron', 'manual', 'wp_cli'], true) ? $triggered_by : 'manual';
    }

    private function generate_run_id(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return 'retention_' . str_replace('-', '', wp_generate_uuid4());
        }

        return 'retention_' . gmdate('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 12);
    }

    private function generate_archive_batch_id(string $source_key): string
    {
        return $source_key . '_' . gmdate('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 8);
    }

    private function has_lock(string $source_key): bool
    {
        return function_exists('get_transient')
            && get_transient(self::LOCK_PREFIX . $source_key) !== false;
    }

    private function set_lock(string $source_key): void
    {
        if (!function_exists('set_transient')) {
            return;
        }

        set_transient(
            self::LOCK_PREFIX . $source_key,
            '1',
            $this->config->get_retention_lock_ttl_seconds()
        );
    }

    private function clear_lock(string $source_key): void
    {
        if (function_exists('delete_transient')) {
            delete_transient(self::LOCK_PREFIX . $source_key);
        }
    }

    private function is_identifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1;
    }

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
