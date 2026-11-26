<?php

if (!defined('ABSPATH')) { exit; }

/** One source-owned run freezes its cutoff, candidate ceiling and settings at admission. */
class Kiwi_Retention_Sms_Worker extends Kiwi_Retention_Worker_Support
{
    private $statistics;

    public function __construct(Kiwi_Retention_Sms_Statistics $statistics, Kiwi_Config $config,
        Kiwi_Retention_Source_Registry $registry, Kiwi_Retention_Cleanup_Run_Repository $runs,
        Kiwi_Retention_Sqlite_Archive_Service $archives, Kiwi_Operational_Event_Service $events,
        Kiwi_Retention_Archive_Lock $archive_lock, Kiwi_Retention_Corruption_Safety_Gate_Coordinator $gate,
        Kiwi_Retention_Archive_Health_Store $health)
    {
        parent::__construct($config, $registry, $runs, $archives, $events, $archive_lock, $gate, $health);
        $this->statistics = $statistics;
    }

    public function run_source(string $source_key, string $triggered_by = 'cron'): array
    {
        $lock = null;
        try {
            $lock = $this->run_repository->acquire_source_creation_lock($source_key);
            if ($lock === null || $this->has_lock($source_key)) {
                return $this->retry_start($triggered_by, 'source_lock_active');
            }
            if (!$this->mark_stale_runs($source_key)) {
                return $this->retry_start($triggered_by, 'stale_run_detection_failed');
            }
            $open = $this->run_repository->find_open_run_for_source($source_key);
            if ($open !== null) { return $this->scheduled($open); }
            $this->set_lock($source_key);
            try {
                return $this->start($source_key, $triggered_by, $lock);
            } finally {
                $this->clear_lock($source_key);
            }
        } catch (Throwable $error) {
            return $this->retry_start($triggered_by, 'sms_run_start_failed');
        } finally {
            if (is_string($lock)) { $this->run_repository->release_source_creation_lock($lock); }
        }
    }

    private function start(string $key, string $trigger, string $lock): array
    {
        global $wpdb;
        $source = $this->source_registry->get($key);
        $settings = $this->config->get_retention_source_settings($key);
        $started = $this->current_time_mysql();
        $days = $this->resolve_retention_days($settings, $source);
        $cutoff = (new DateTimeImmutable(substr($started, 0, 10), new DateTimeZone('UTC')))
            ->modify('-' . $days . ' days')->format('Y-m-d') . ' 00:00:00';
        $run = ['run_id' => $this->generate_run_id(), 'source_key' => $key, 'source_table' => $source['source_table'],
            'enabled' => $settings['enabled'], 'dry_run' => $settings['dry_run'], 'started_at' => $started,
            'triggered_by' => $this->normalize_triggered_by($trigger), 'cutoff_column' => $source['cutoff_column'],
            'retention_days_effective' => $days, 'cutoff_value' => $cutoff, 'status' => 'skipped',
            'finished_at' => $started, 'worker_phase' => 'cleanup_disabled', 'gate_status' => 'skipped'];
        if (!empty($settings['enabled'])) {
            $max = $wpdb->get_var('SELECT COALESCE(MAX(id),0) FROM ' . $source['source_table']);
            if ($max === null || (string) $wpdb->last_error !== '') { throw new RuntimeException('sms_candidate_ceiling_failed'); }
            $run['target_max_primary_key'] = (int) $max;
            $state = $this->statistics->begin($cutoff, (int) $max, $settings);
            $run['eligible_rows'] = array_sum(array_column($state['groups'], 'eligible_rows'));
            $run['gate_results_json'] = $state;
            $run['worker_phase'] = 'sms_statistics';
            $run['status'] = 'pending';
            $run['gate_status'] = 'pending';
            $run['finished_at'] = null;
        }
        $id = $this->run_repository->create_run_with_source_lock($run, $lock);
        if ($id <= 0) { return $this->retry_start($trigger, 'run_audit_create_failed'); }
        return !empty($run['enabled']) ? $this->scheduled($run)
            : ['success' => true, 'run_id' => $run['run_id'], 'status' => 'skipped', 'error_code' => 'cleanup_disabled'];
    }

    public function run_worker(string $key): array
    {
        $lock = null;
        $worker_lock = false;
        try {
            $lock = $this->run_repository->acquire_source_creation_lock($key);
            if ($lock === null || $this->has_worker_lock($key)) { return $this->scheduled([], 'worker_lock_active'); }
            $run = $this->run_repository->find_open_run_for_source($key);
            if (!$run) { return ['success' => true, 'status' => 'skipped', 'error_code' => 'no_open_run']; }
            $this->set_worker_lock($key);
            $worker_lock = true;
            $checkpoint = $this->run_repository->checkpoint_for_stored_run($run);
            if (empty($run['dry_run'])) {
                // Live deletion needs the fresh correction, release and receipt check before every package.
                return $this->finish_run((int) $run['id'], ['success' => false, 'run_id' => $run['run_id'],
                    'status' => 'failed', 'gate_status' => 'blocked', 'error_code' => 'sms_live_release_required']);
            }
            $state = json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
            if (($state['version'] ?? 0) !== 1 || !isset($state['progress']['phase'], $state['groups'], $state['tolerance'])) {
                throw new RuntimeException('sms_audit_invalid');
            }
            $remaining = $this->config->get_retention_worker_row_limit();
            $deadline = microtime(true) + $this->config->get_retention_worker_time_limit_seconds();
            do {
                $cost = $this->statistics->advance($state, min(250, $remaining));
                $remaining -= max(1, $cost);
            } while ($state['progress']['phase'] !== 'done' && $remaining > 0 && microtime(true) < $deadline);
            $done = $state['progress']['phase'] === 'done';
            $update = ['gate_results_json' => $state, 'worker_runs' => (int) $run['worker_runs'] + 1,
                'worker_last_finished_at' => $this->current_time_mysql(), 'status' => $done ? 'completed' : 'running',
                'worker_phase' => $done ? 'completed' : 'sms_statistics'];
            if ($done) {
                $update['finished_at'] = $this->current_time_mysql();
                $update['gate_status'] = 'passed';
                foreach ($state['groups'] as $group) {
                    if ($group['before']['status'] !== 'passed') { $update['gate_status'] = 'blocked'; break; }
                }
            }
            if (!$this->run_repository->update_run_checkpoint((int) $run['id'], $update, $checkpoint)) {
                return $this->scheduled($run, 'sms_audit_write_failed');
            }
            return $done ? ['success' => true, 'run_id' => $run['run_id'], 'status' => 'completed', 'worker_phase' => 'completed']
                : $this->scheduled($run);
        } catch (Throwable $error) {
            if (!isset($run['id'])) { return $this->scheduled([], 'sms_audit_read_failed'); }
            return $this->finish_run((int) $run['id'], ['success' => false, 'run_id' => $run['run_id'],
                'status' => 'failed', 'gate_status' => 'blocked',
                'error_code' => 'sms_statistics_failed', 'error_message' => 'The SMS statistics audit could not be verified.']);
        } finally {
            if ($worker_lock) { $this->clear_worker_lock($key); }
            if (is_string($lock)) { $this->run_repository->release_source_creation_lock($lock); }
        }
    }

    private function retry_start(string $trigger, string $reason): array
    {
        return $this->scheduled([], $reason) + ['retry_source' => true, 'triggered_by' => $this->normalize_triggered_by($trigger)];
    }

    private function scheduled(array $run, string $reason = ''): array
    {
        return ['success' => true, 'run_id' => $run['run_id'] ?? '', 'status' => $run['status'] ?? 'pending',
            'worker_phase' => $run['worker_phase'] ?? 'sms_statistics',
            'schedule_worker' => true, 'reschedule_delay_seconds' => $this->config->get_retention_worker_reschedule_delay_seconds(),
            'error_code' => $reason];
    }
}
