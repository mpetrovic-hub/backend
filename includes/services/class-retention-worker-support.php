<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Shared worker dependencies and lifecycle helpers; no source or worker flow. */
abstract class Kiwi_Retention_Worker_Support
{
    private const LOCK_PREFIX = 'kiwi_retention_cleanup_lock_';
    private const WORKER_LOCK_PREFIX = 'kiwi_retention_cleanup_worker_lock_';
    private const STALE_RUN_AFTER_MINUTES = 30;

    protected $config;
    protected $source_registry;
    protected $run_repository;
    protected $archive_service;
    protected $archive_lock;
    protected $operational_event_service;
    protected $corruption_safety_gate;

    public function __construct(
        ?Kiwi_Config $config = null,
        ?Kiwi_Retention_Source_Registry $source_registry = null,
        ?Kiwi_Retention_Cleanup_Run_Repository $run_repository = null,
        ?Kiwi_Retention_Sqlite_Archive_Service $archive_service = null,
        ?Kiwi_Operational_Event_Service $operational_event_service = null,
        ?Kiwi_Retention_Archive_Lock $archive_lock = null,
        ?Kiwi_Retention_Corruption_Safety_Gate_Coordinator $corruption_safety_gate = null
    ) {
        $this->config = $config ?? new Kiwi_Config();
        $this->source_registry = $source_registry ?? new Kiwi_Retention_Source_Registry();
        $this->run_repository = $run_repository ?? new Kiwi_Retention_Cleanup_Run_Repository();
        $this->archive_service = $archive_service ?? new Kiwi_Retention_Sqlite_Archive_Service($this->config);
        $this->archive_lock = $archive_lock ?? new Kiwi_Retention_Archive_Lock();
        $this->operational_event_service = $operational_event_service ?? new Kiwi_Operational_Event_Service();
        $this->corruption_safety_gate = $corruption_safety_gate
            ?? new Kiwi_Retention_Corruption_Safety_Gate_Coordinator(
                $this->archive_lock, $this->operational_event_service, $this->run_repository
            );
    }

    protected function mark_stale_runs(string $source_key): bool
    {
        try {
            $stale_runs = $this->run_repository->mark_stale_unfinished_runs(
                $source_key,
                self::STALE_RUN_AFTER_MINUTES
            );
            if ($stale_runs === null) {
                return false;
            }

            foreach ($stale_runs as $stale_run) {
                $run_id = (string) ($stale_run['run_id'] ?? '');
                if ($run_id === '') {
                    continue;
                }

                $this->operational_event_service->record_failure([
                    'area' => 'retention',
                    'severity' => 'error',
                    'event_type' => 'retention_cleanup_timeout',
                    'correlation_key' => 'retention_' . $source_key,
                    'idempotency_key' => 'retention_stale_' . $run_id,
                    'reference_type' => 'retention_cleanup_run',
                    'reference_id' => $run_id,
                    'message' => 'Retention cleanup run was marked failed after its heartbeat became stale.',
                    'raw_error_text' => 'cron_timeout_suspected',
                    'context' => [
                        'source_key' => $source_key,
                        'worker_phase' => (string) ($stale_run['worker_phase'] ?? ''),
                        'audit_id' => (int) ($stale_run['id'] ?? 0),
                        'error_code' => 'cron_timeout_suspected',
                    ],
                ]);
            }

            return true;
        } catch (Throwable $error) {
            return false;
        }
    }

    protected function finish_run(int $run_db_id, array $result): array
    {
        $update = array_merge($result, [
            'finished_at' => $this->current_time_mysql(),
        ]);
        unset($update['success'], $update['run_id'], $update['schedule_worker'], $update['reschedule_worker'], $update['reschedule_delay_seconds']);

        if ($run_db_id > 0) {
            try {
                $updated = $this->run_repository->update_run($run_db_id, $update);
            } catch (Throwable $error) {
                $updated = false;
            }

            if (!$updated) {
                $audit_failure = $result;
                $audit_failure['success'] = false;
                $audit_failure['status'] = 'failed';
                $audit_failure['audit_persisted'] = false;
                $audit_failure['cleanup_status_before_audit_failure'] = (string) ($result['status'] ?? '');
                $audit_failure['cleanup_error_code'] = (string) ($result['error_code'] ?? '');
                $audit_failure['cleanup_error_message'] = (string) ($result['error_message'] ?? '');
                $audit_failure['error_code'] = 'run_audit_update_failed';
                $audit_failure['error_message'] = 'Retention cleanup finished but the audit run update could not be persisted.';

                return $audit_failure;
            }
        }

        return $result;
    }

    protected function resolve_retention_days(array $settings, array $source): int
    {
        $minimum = max(1, (int) ($source['retention_days_min'] ?? 1));
        $default = max($minimum, (int) ($source['retention_days_default'] ?? $minimum));

        return max($minimum, (int) ($settings['retention_days'] ?? $default));
    }

    protected function normalize_triggered_by(string $triggered_by): string
    {
        $triggered_by = strtolower(trim($triggered_by));

        return in_array($triggered_by, ['cron', 'manual', 'wp_cli'], true) ? $triggered_by : 'manual';
    }

    protected function generate_run_id(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return 'retention_' . str_replace('-', '', wp_generate_uuid4());
        }

        return 'retention_' . gmdate('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 12);
    }

    protected function generate_archive_batch_id(string $source_key): string
    {
        return $source_key . '_' . gmdate('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 8);
    }

    protected function has_lock(string $source_key): bool
    {
        return function_exists('get_transient')
            && get_transient(self::LOCK_PREFIX . $source_key) !== false;
    }

    protected function set_lock(string $source_key): void
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

    protected function clear_lock(string $source_key): void
    {
        if (function_exists('delete_transient')) {
            delete_transient(self::LOCK_PREFIX . $source_key);
        }
    }

    protected function has_worker_lock(string $source_key): bool
    {
        return function_exists('get_transient')
            && get_transient(self::WORKER_LOCK_PREFIX . $source_key) !== false;
    }

    protected function set_worker_lock(string $source_key): void
    {
        if (!function_exists('set_transient')) {
            return;
        }

        set_transient(
            self::WORKER_LOCK_PREFIX . $source_key,
            '1',
            $this->config->get_retention_worker_lock_ttl_seconds()
        );
    }

    protected function clear_worker_lock(string $source_key): void
    {
        if (function_exists('delete_transient')) {
            delete_transient(self::WORKER_LOCK_PREFIX . $source_key);
        }
    }

    protected function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
