<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Operational_Event_Cleanup_Service
{
    private const LOCK_KEY = 'kiwi_operational_event_cleanup_lock';
    private const LOCK_TTL_SECONDS = 300;
    private const CORRELATION_KEY = 'operational_events_cleanup';

    private $config;
    private $repository;
    private $event_service;

    public function __construct(
        ?Kiwi_Config $config = null,
        ?Kiwi_Operational_Event_Repository $repository = null,
        ?Kiwi_Operational_Event_Service $event_service = null
    ) {
        $this->config = $config instanceof Kiwi_Config ? $config : new Kiwi_Config();
        $this->repository = $repository instanceof Kiwi_Operational_Event_Repository
            ? $repository
            : new Kiwi_Operational_Event_Repository();
        $this->event_service = $event_service instanceof Kiwi_Operational_Event_Service
            ? $event_service
            : new Kiwi_Operational_Event_Service($this->repository);
    }

    public function run(): array
    {
        if ($this->has_lock()) {
            return ['success' => true, 'status' => 'lock_skipped', 'deleted_rows' => 0, 'schedule_worker' => false];
        }

        $this->set_lock();

        try {
            $retention_days = $this->config->get_operational_events_retention_days();
            $batch_size = $this->config->get_operational_events_cleanup_batch_size();
            $cutoff = $this->build_cutoff($retention_days);
            $deleted_rows = $this->repository->delete_created_before($cutoff, $batch_size);
            $this->event_service->record_recovery([
                'area' => 'cron',
                'severity' => 'info',
                'event_type' => 'operational_event_cleanup_failed',
                'correlation_key' => self::CORRELATION_KEY,
                'idempotency_key' => 'operational_event_cleanup_recovered_' . str_replace([' ', ':'], ['', ''], $this->current_time_mysql()),
                'reference_type' => 'operational_event_cleanup',
                'reference_id' => 'daily',
                'message' => 'Operational event cleanup recovered after an earlier failure.',
            ]);

            return [
                'success' => true,
                'status' => 'completed',
                'deleted_rows' => $deleted_rows,
                'cutoff' => $cutoff,
                'schedule_worker' => $deleted_rows >= $batch_size,
                'reschedule_delay_seconds' => 60,
            ];
        } catch (Throwable $error) {
            $recorded = $this->event_service->record_failure([
                'area' => 'cron',
                'severity' => 'error',
                'event_type' => 'operational_event_cleanup_failed',
                'correlation_key' => self::CORRELATION_KEY,
                'reference_type' => 'operational_event_cleanup',
                'reference_id' => 'daily',
                'message' => 'Operational event cleanup failed.',
                'raw_error_text' => $error->getMessage(),
            ]);

            if (!$recorded) {
                error_log('[kiwi-operational-events] Cleanup failed and the event table was not writable.');
            }

            return [
                'success' => false,
                'status' => 'failed',
                'deleted_rows' => 0,
                'schedule_worker' => false,
                'error_message' => 'Operational event cleanup failed.',
            ];
        } finally {
            $this->clear_lock();
        }
    }

    protected function build_cutoff(int $retention_days): string
    {
        $today = substr($this->current_time_mysql(), 0, 10);
        $timestamp = strtotime($today . ' -' . max(1, $retention_days) . ' days');

        return ($timestamp === false ? $today : gmdate('Y-m-d', $timestamp)) . ' 00:00:00';
    }

    private function has_lock(): bool
    {
        return function_exists('get_transient') && get_transient(self::LOCK_KEY) !== false;
    }

    private function set_lock(): void
    {
        if (function_exists('set_transient')) {
            set_transient(self::LOCK_KEY, '1', self::LOCK_TTL_SECONDS);
        }
    }

    private function clear_lock(): void
    {
        if (function_exists('delete_transient')) {
            delete_transient(self::LOCK_KEY);
        }
    }

    private function current_time_mysql(): string
    {
        return function_exists('current_time') ? (string) current_time('mysql') : gmdate('Y-m-d H:i:s');
    }
}
