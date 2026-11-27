<?php

if (!defined('ABSPATH')) { exit; }

/** One source-owned run freezes its cutoff, candidate ceiling and settings at admission. */
class Kiwi_Retention_Sms_Worker extends Kiwi_Retention_Worker_Support
{
    private $statistics;
    private $snapshots;

    public function __construct(Kiwi_Retention_Sms_Statistics $statistics, Kiwi_Config $config,
        Kiwi_Retention_Source_Registry $registry, Kiwi_Retention_Cleanup_Run_Repository $runs,
        Kiwi_Retention_Sqlite_Archive_Service $archives, Kiwi_Operational_Event_Service $events,
        Kiwi_Retention_Archive_Lock $archive_lock, Kiwi_Retention_Corruption_Safety_Gate_Coordinator $gate,
        Kiwi_Retention_Archive_Health_Store $health, Kiwi_Retention_Table_Growth_Snapshot_Repository $snapshots)
    {
        parent::__construct($config, $registry, $runs, $archives, $events, $archive_lock, $gate, $health);
        $this->statistics = $statistics;
        $this->snapshots = $snapshots;
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
            if (empty($run['dry_run'])) {
                $run['archive_db_path'] = $this->archive_service->resolve_archive_db_path();
                $run['gate_results_json']['archive_scope'] = $this->archive_scope($run);
            }
            $run['worker_phase'] = 'sms_statistics';
            $run['status'] = 'pending';
            $run['gate_status'] = 'pending';
            $run['finished_at'] = null;
        }
        $id = $this->run_repository->create_run_with_source_lock($run, $lock);
        if ($id <= 0) { return $this->retry_start($trigger, 'run_audit_create_failed'); }
        if (!empty($run['enabled']) && empty($run['dry_run'])) {
            $this->capture_growth($run, 'before_cleanup');
        }
        return !empty($run['enabled']) ? $this->scheduled($run)
            : ['success' => true, 'run_id' => $run['run_id'], 'status' => 'skipped', 'error_code' => 'cleanup_disabled'];
    }

    private function archive_scope(array $run): array
    {
        return ['run_id' => (string) $run['run_id'], 'cutoff_value' => (string) $run['cutoff_value'],
            'target_max_primary_key' => (int) $run['target_max_primary_key'], 'archive_db_path' => (string) $run['archive_db_path']];
    }

    /** The source lock serializes these lookups and makes a repeated callback idempotent. */
    private function capture_growth(array $run, string $phase): void
    {
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $this->snapshots->get_table_name()
            . ' WHERE source_key=%s AND cleanup_run_id=%s AND snapshot_phase=%s LIMIT 1',
            $run['source_key'], $run['run_id'], $phase));
        if ((string) $wpdb->last_error !== '') { throw new RuntimeException('sms_growth_lookup_failed'); }
        if ($existing !== null) { return; }
        $source = $this->source_registry->get($run['source_key']);
        $eligible = $phase === 'before_cleanup' ? $run['eligible_rows'] : $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$source['source_table']} WHERE created_at<%s", $run['cutoff_value']));
        if ($eligible === null || (string) $wpdb->last_error !== '') { throw new RuntimeException('sms_growth_count_failed'); }
        if ($this->snapshots->capture_snapshot($source, $phase, (int) $run['retention_days_effective'], $run['cutoff_value'],
            (int) $eligible, $run['run_id'], (string) ($run['archive_batch_id'] ?? ''), (int) ($run['archived_rows'] ?? 0),
            (int) ($run['deleted_rows'] ?? 0)) <= 0) { throw new RuntimeException('sms_growth_write_failed'); }
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
            $state = json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
            if (($state['version'] ?? 0) !== 1 || !isset($state['progress']['phase'], $state['groups'], $state['tolerance'])) {
                throw new RuntimeException('sms_audit_invalid');
            }
            if (empty($run['dry_run'])) { return $this->live_step($run, $state, $lock); }
            $done = $this->measure($state);
            $update = ['gate_results_json' => $state, 'worker_runs' => (int) $run['worker_runs'] + 1,
                'worker_last_finished_at' => $this->current_time_mysql(), 'status' => $done ? 'completed' : 'running',
                'worker_phase' => $done ? 'completed' : 'sms_statistics'];
            if ($done) {
                Kiwi_Retention_Sms_Statistics::verify_group_schema($state);
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
            if (empty($run['dry_run'])) {
                // Preserve the evidence (including exact earlier deletions) even when its JSON cannot be decoded.
                $reason = $error->getMessage() === 'sms_group_schema_changed' ? 'sms_group_schema_changed' : 'sms_run_evidence_failed';
                $stored = $this->store_progress($run, [], 'failed', ['status' => 'failed', 'gate_status' => 'blocked',
                    'gate_results_json' => $run['gate_results_json'], 'finished_at' => $this->current_time_mysql(),
                    'error_code' => $reason, 'error_message' => 'The SMS run evidence could not be verified.']);
                return $stored === null ? $this->audit_wait($run) : ['success' => false, 'run_id' => $run['run_id'],
                    'status' => 'failed', 'error_code' => $reason];
            }
            return $this->finish_run((int) $run['id'], ['success' => false, 'run_id' => $run['run_id'],
                'status' => 'failed', 'gate_status' => 'blocked',
                'error_code' => 'sms_statistics_failed', 'error_message' => 'The SMS statistics audit could not be verified.']);
        } finally {
            if ($worker_lock) { $this->clear_worker_lock($key); }
            if (is_string($lock)) { $this->run_repository->release_source_creation_lock($lock); }
        }
    }

    private function measure(array &$state): bool
    {
        $remaining = $this->config->get_retention_worker_row_limit();
        $deadline = microtime(true) + $this->config->get_retention_worker_time_limit_seconds();
        do {
            $cost = $this->statistics->advance($state, min(250, $remaining));
            $remaining -= max(1, $cost);
        } while ($state['progress']['phase'] !== 'done' && $remaining > 0 && microtime(true) < $deadline);
        return $state['progress']['phase'] === 'done';
    }

    private function live_step(array $run, array $state, string $lock): array
    {
        Kiwi_Retention_Sms_Statistics::verify_group_schema($state);
        $source = $this->source_registry->get($run['source_key']);
        if (empty($run['enabled']) || $run['source_table'] !== $source['source_table'] || $run['cutoff_column'] !== $source['cutoff_column']
            || ($state['archive_scope'] ?? null) !== $this->archive_scope($run)) {
            throw new RuntimeException('sms_frozen_scope_changed');
        }
        if ((int) $run['worker_runs'] === 0) { $this->capture_growth($run, 'before_cleanup'); }
        if (!$state['groups']) {
            if ((int) $run['eligible_rows'] !== 0) { throw new RuntimeException('sms_eligible_groups_missing'); }
            return $this->complete($run, $state);
        }
        if ($run['worker_phase'] === 'sms_correcting') { return $this->interrupt_correction($run, $state); }
        $inventory = $this->inventory_transition($run, $state);
        if ($inventory !== null) { return $inventory; }
        switch ($run['worker_phase']) {
            case 'sms_archive':
                if (!empty($state['recheck_groups'])) { return $this->start_recheck($run, $state); }
                return $this->archive($run, $state, $lock);
            case 'sms_recheck':
                return $this->review($run, $state);
            case 'sms_correction':
                return $this->correct($run, $state, $lock);
            case 'sms_remeasure':
                return $this->after($run, $state);
            case 'sms_statistics':
                break;
            default:
                throw new RuntimeException('sms_phase_invalid');
        }
        $done = $this->measure($state);
        if ($done) {
            $state['required_archive_paths'] = $state['progress']['files'] ?? [];
            $state['archive_target_existed'] = in_array($run['archive_db_path'], $state['required_archive_paths'], true);
            foreach ($state['groups'] as &$group) {
                $group['initial_mysql_baseline'] = $group['before']['mysql_baseline'];
            }
            unset($group);
            $state['progress'] = ['phase' => 'correct', 'cursor' => 0, 'groups' => array_keys($state['groups'])];
        }
        return $this->continue_with($run, $state, $done ? 'sms_correction' : 'sms_statistics');
    }

    /** Remember observed generations even across a resumed or targeted recheck. */
    private function inventory_transition(array $run, array $state): ?array
    {
        $required = $state['required_archive_paths'] ?? null;
        if ($required === null) { return null; }
        if (!is_array($required)) { throw new RuntimeException('sms_archive_inventory_invalid'); }
        $target = $run['archive_db_path'];
        if (!empty($state['archive_target_existed']) || (int) $run['archived_rows'] > 0 || (int) $run['deleted_rows'] > 0) {
            $required[] = $target;
        }
        try {
            $current = array_column($this->archive_service->list_archive_files(), 'path');
        } catch (Throwable $error) {
            return $this->wait_for_inventory($run, $state);
        }
        if (array_diff($required, $current)) { return $this->wait_for_inventory($run, $state); }
        $added = array_diff($current, $required);
        if (isset($state['pending_archive']) || (int) $run['archived_rows'] > 0) {
            $added = array_diff($added, [$target]);
        }
        if ($added || !empty($state['inventory_interrupted'])) {
            unset($state['inventory_interrupted']);
            $state['required_archive_paths'] = $current;
            $state['archive_target_existed'] = in_array($target, $current, true);
            foreach ($state['groups'] as $id => $group) { $state['recheck_groups'][$id] = $group['key']; }
            return $this->start_recheck($run, $state);
        }
        return null;
    }

    private function wait_for_inventory(array $run, array $state): array
    {
        if (empty($state['inventory_interrupted'])) {
            $state['inventory_interrupted'] = true;
            $stored = $this->store_progress($run, $state, $run['worker_phase'],
                ['gate_status' => 'blocked', 'error_code' => 'sms_archive_history_missing']);
            if ($stored === null) { return $this->audit_wait($run); }
            $run = $stored;
        }
        return $this->audit_wait($run, 'sms_archive_history_missing');
    }

    /**
     * Called inside a separately authorized manual repair transaction, with the SMS source lock
     * held until commit. The repair and invalidation then become visible to the worker together.
     */
    public static function invalidate_group(Kiwi_Retention_Cleanup_Run_Repository $runs, array $key, string $lock): bool
    {
        global $wpdb;
        $source_key = Kiwi_Retention_Source_Registry::SOURCE_SMS_BODY_VARIANT_ASSIGNMENTS;
        try {
            foreach (Kiwi_Sms_Body_Variant_Repository::SUMMARY_GROUP_COLUMNS as $column) {
                if (!is_string($key[$column] ?? null)) { return false; }
            }
            if (!$runs->owns_source_creation_lock($source_key, $lock)) { return false; }
            $connection = (int) $wpdb->get_var('SELECT IF(@@session.in_transaction=1,CONNECTION_ID(),0)');
            if ($connection < 1 || (string) $wpdb->last_error !== '') { return false; }
            $run = $runs->find_open_run_for_source($source_key);
            if ($run === null || !empty($run['dry_run'])) { return true; }
            $state = json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
            Kiwi_Retention_Sms_Statistics::verify_group_schema($state);
            $id = Kiwi_Retention_Sms_Statistics::group_id($key);
            if (!isset($state['groups'][$id])) { return true; }
            $state['recheck_groups'][$id] = Kiwi_Retention_Sms_Statistics::group_key($key);
            $update = ['gate_results_json' => $state];
            $checkpoint = $runs->checkpoint_for_stored_run($run);
            $runs->update_run_transaction_checkpoint((int) $run['id'], $update, $checkpoint, $connection);
            $fresh = $runs->find_run_by_id((int) $run['id']);
            return $fresh !== null && $fresh['finished_at'] === null
                && $runs->checkpoint_for_stored_run($fresh) === $runs->checkpoint_after_update($update, $checkpoint)
                && (int) $wpdb->get_var('SELECT IF(@@session.in_transaction=1,CONNECTION_ID(),0)') === $connection
                && $runs->owns_source_creation_lock($source_key, $lock);
        } catch (Throwable $error) {
            return false;
        }
    }

    private function start_recheck(array $run, array $state): array
    {
        $selected = [];
        foreach ($state['recheck_groups'] as $id => $key) {
            if (Kiwi_Retention_Sms_Statistics::group_id($key) !== $id) { throw new RuntimeException('sms_recheck_key_invalid'); }
            if (!isset($state['groups'][$id])) {
                // A candidate can move group while the original ID and cutoff scope remain frozen.
                $state['groups'][$id] = ['key' => $key, 'eligible_rows' => 0];
            }
            $group = &$state['groups'][$id];
            if (isset($group['before'], $group['correction'], $group['after'])) {
                $group['reviews'][] = ['before' => $group['before'], 'correction' => $group['correction'], 'after' => $group['after']];
            }
            unset($group['before'], $group['correction'], $group['after']);
            $selected[$id] = $group;
            unset($group);
        }
        unset($state['recheck_groups']);
        $state['measurement'] = $this->statistics->new_measurement($selected, $state['tolerance'], $state['group_schema']);
        $state['progress'] = ['phase' => 'review'];
        return $this->continue_with($run, $state, 'sms_recheck', ['gate_status' => 'pending', 'error_code' => '', 'error_message' => '']);
    }

    private function review(array $run, array $state): array
    {
        if (!$this->measure($state['measurement'])) { return $this->continue_with($run, $state, 'sms_recheck'); }
        $selected = array_keys($state['measurement']['groups']);
        foreach ($state['measurement']['groups'] as $id => $group) {
            $state['groups'][$id]['before'] = $group['before'];
            if (!isset($state['groups'][$id]['initial_mysql_baseline'])) {
                $state['groups'][$id]['initial_mysql_baseline'] = $group['before']['mysql_baseline'];
            }
        }
        unset($state['measurement']);
        $state['progress'] = ['phase' => 'correct', 'cursor' => 0, 'groups' => $selected];
        return $this->continue_with($run, $state, 'sms_correction');
    }

    private function correct(array $run, array $state, string $lock): array
    {
        $progress = $state['progress'];
        if ($progress['phase'] !== 'correct' || !is_int($progress['cursor']) || !is_array($progress['groups'])) {
            throw new RuntimeException('sms_correction_cursor_invalid');
        }
        $limit = min(25, $this->config->get_retention_worker_row_limit());
        $batch = array_slice($progress['groups'], $progress['cursor'], $limit);
        if (!$batch) { throw new RuntimeException('sms_correction_groups_missing'); }
        // Persist the write intent first. If PHP exits mid-attempt, another invocation must not replay it.
        foreach ($batch as $id) {
            if (!isset($state['groups'][$id]['before'], $state['groups'][$id]['key'])
                || Kiwi_Retention_Sms_Statistics::group_id($state['groups'][$id]['key']) !== $id) {
                throw new RuntimeException('sms_correction_group_invalid');
            }
            $state['groups'][$id]['correction'] = ['status' => 'unconfirmed', 'reason' => 'correction_attempt_started'];
        }
        $state['progress']['in_flight'] = $batch;
        $reserved = $this->store_progress($run, $state, 'sms_correcting', []);
        if ($reserved === null) { return $this->audit_wait($run); }
        $repository = new Kiwi_Sms_Body_Variant_Repository();
        $deadline = microtime(true) + $this->config->get_retention_worker_time_limit_seconds();
        foreach ($batch as $id) {
            Kiwi_Retention_Sms_Statistics::verify_group_schema($state);
            $group = &$state['groups'][$id];
            if (isset($state['recheck_groups'][$id])) {
                $group['correction'] = ['status' => 'skipped', 'reason' => 'group_basis_changed'];
            } else {
                try {
                    $group['correction'] = $repository->correct_summary_counters($group['key'], $group['before'], $lock);
                } catch (Throwable $error) {
                    $group['correction'] = ['status' => 'failed', 'reason' => 'summary_correction_failed'];
                }
            }
            unset($group);
            ++$state['progress']['cursor'];
            if (microtime(true) >= $deadline) { break; }
        }
        unset($state['progress']['in_flight']);
        if ($state['progress']['cursor'] < count($progress['groups'])) {
            return $this->continue_with($reserved, $state, 'sms_correction');
        }
        $selected = array_intersect_key($state['groups'], array_flip($progress['groups']));
        $state['measurement'] = $this->statistics->new_measurement($selected, $state['tolerance'], $state['group_schema']);
        $state['progress'] = ['phase' => 'remeasure'];
        return $this->continue_with($reserved, $state, 'sms_remeasure');
    }

    private function interrupt_correction(array $run, array $state): array
    {
        foreach ($state['progress']['in_flight'] ?? [] as $id) {
            $state['groups'][$id]['correction'] = ['status' => 'unconfirmed', 'reason' => 'correction_interrupted'];
        }
        $stored = $this->store_progress($run, $state, 'sms_interrupted', ['status' => 'failed', 'gate_status' => 'blocked',
            'error_code' => 'sms_correction_interrupted', 'finished_at' => $this->current_time_mysql()]);
        return $stored === null ? $this->audit_wait($run) : ['success' => false, 'run_id' => $run['run_id'],
            'status' => 'failed', 'error_code' => 'sms_correction_interrupted'];
    }

    private function after(array $run, array $state): array
    {
        if (!$this->measure($state['measurement'])) {
            return $this->continue_with($run, $state, 'sms_remeasure');
        }
        foreach ($state['measurement']['groups'] as $id => $group) {
            $state['groups'][$id]['after'] = $group['before'];
        }
        $observed = $state['measurement']['progress']['files'] ?? [];
        $state['required_archive_paths'] = array_values(array_unique(array_merge($state['required_archive_paths'] ?? [], $observed)));
        $state['archive_target_existed'] = !empty($state['archive_target_existed']) || in_array($run['archive_db_path'], $observed, true);
        unset($state['measurement']);
        $state['progress'] = ['phase' => 'released'];
        return $this->continue_with($run, $state, 'sms_archive', ['gate_status' => $this->release_status($state['groups'])]);
    }

    private function archive(array $run, array $state, string $lock): array
    {
        if ($state['progress']['phase'] !== 'released') { throw new RuntimeException('sms_group_release_missing'); }
        foreach ($state['groups'] as $id => $group) {
            if ($id !== Kiwi_Retention_Sms_Statistics::group_id($group['key']) || !isset($group['before'], $group['correction'], $group['after']['status'])) {
                throw new RuntimeException('sms_group_release_missing');
            }
        }
        if ($this->release_status($state['groups']) === 'blocked' && empty($state['pending_archive'])) {
            return $this->complete($run, $state);
        }
        $batches = new Kiwi_Retention_Sms_Archive_Batches($this->config, $this->source_registry, $this->run_repository,
            $this->archive_service, $this->operational_event_service, $this->archive_lock, $this->corruption_safety_gate, $this->health_store);
        $result = $batches->advance($run, min(500, $this->config->get_retention_worker_row_limit()), $lock);
        if ($result['status'] === 'recheck' || ($result['reason'] ?? '') === 'sms_archive_confirmed_file_missing') {
            $fresh = $this->run_repository->find_run_by_id((int) $run['id']);
            if (!$fresh || $fresh['finished_at'] !== null || $fresh['worker_phase'] !== 'sms_archive') { return $this->audit_wait($run); }
            $state = json_decode($fresh['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
            if (($result['reason'] ?? '') === 'sms_archive_confirmed_file_missing') {
                return $this->wait_for_inventory($fresh, $state);
            }
            foreach ($result['groups'] ?? [$result['group']] as $key) {
                $state['recheck_groups'][Kiwi_Retention_Sms_Statistics::group_id($key)] = $key;
            }
            return $this->start_recheck($fresh, $state);
        }
        if ($result['status'] === 'failed') {
            return $this->audit_wait($run, $result['reason'] ?? 'sms_package_failed');
        }
        if (in_array($result['status'], ['more', 'wait'], true)) {
            return $this->scheduled($run, $result['reason'] ?? '');
        }
        if ($result['status'] !== 'done') { throw new RuntimeException('sms_archive_result_unknown'); }
        // A package owns its own archive/delete checkpoints. Finalize only the state read back after it.
        $fresh = $this->run_repository->find_run_by_id((int) $run['id']);
        if (!$fresh || $fresh['finished_at'] !== null || $fresh['worker_phase'] !== 'sms_archive') { return $this->audit_wait($run); }
        $state = json_decode($fresh['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
        if (($state['archive_scope'] ?? null) !== $this->archive_scope($fresh)
            || ($state['progress']['phase'] ?? '') !== 'released' || !empty($state['recheck_groups']) || !empty($state['pending_archive'])) {
            return $this->audit_wait($fresh);
        }
        return $this->complete($fresh, $state);
    }

    private function complete(array $run, array $state): array
    {
        $this->capture_growth($run, 'after_cleanup');
        $stored = $this->store_progress($run, $state, 'completed', ['status' => 'completed',
            'gate_status' => $this->release_status($state['groups']), 'finished_at' => $this->current_time_mysql()]);
        return $stored !== null ? ['success' => true, 'run_id' => $run['run_id'], 'status' => 'completed', 'worker_phase' => 'completed']
            : $this->audit_wait($run);
    }

    private function release_status(array $groups): string
    {
        $passed = 0;
        foreach ($groups as $group) {
            if (($group['after']['status'] ?? '') === 'passed') { ++$passed; }
        }
        if ($passed === count($groups)) { return 'passed'; }
        return $passed === 0 ? 'blocked' : 'partial';
    }

    private function continue_with(array $run, array $state, string $phase, array $fields = []): array
    {
        $stored = $this->store_progress($run, $state, $phase, $fields);
        return $stored !== null ? $this->scheduled($stored) : $this->audit_wait($run);
    }

    private function store_progress(array $run, array $state, string $phase, array $fields): ?array
    {
        $json = array_key_exists('gate_results_json', $fields) ? $fields['gate_results_json'] : wp_json_encode($state);
        if (!is_string($json) || $json === '') { return null; }
        $update = $fields + ['gate_results_json' => $json, 'worker_runs' => (int) $run['worker_runs'] + 1,
            'worker_last_finished_at' => $this->current_time_mysql(), 'status' => 'running', 'worker_phase' => $phase];
        $prior = $this->run_repository->checkpoint_for_stored_run($run);
        $expected = $this->run_repository->checkpoint_after_update($update, $prior);
        try {
            $this->run_repository->update_run_checkpoint((int) $run['id'], $update, $prior);
        } catch (Throwable $error) {
            // The database may have committed an update whose acknowledgement was lost.
        }
        try {
            $stored = $this->run_repository->find_run_by_id((int) $run['id']);
        } catch (Throwable $error) {
            return null;
        }
        if (!$stored || $this->run_repository->checkpoint_for_stored_run($stored) !== $expected
            || $this->archive_scope($stored) !== $this->archive_scope($run)
            || $stored['source_key'] !== $run['source_key'] || $stored['source_table'] !== $run['source_table']
            || $stored['worker_phase'] !== $phase || $stored['status'] !== $update['status']
            || ($stored['finished_at'] ?? null) !== ($update['finished_at'] ?? null)
            || $stored['gate_status'] !== ($update['gate_status'] ?? $run['gate_status'])) {
            return null;
        }
        return $stored;
    }

    private function audit_wait(array $run, string $reason = 'sms_audit_unconfirmed'): array
    {
        return ['success' => false, 'status' => 'blocked', 'error_code' => $reason]
            + $this->scheduled($run);
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
