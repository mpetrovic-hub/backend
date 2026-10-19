<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Source boundary for mutable engagement; the shared sources keep their flow. */
class Kiwi_Retention_Engagement_Worker extends Kiwi_Retention_Worker_Support
{
    private $checkpoint;
    private const METRICS = ['page_loaded_sessions', 'cta1_sessions', 'cta1_click_events',
        'cta2_sessions', 'cta2_click_events', 'cta3_sessions', 'cta3_click_events'];

    public function run_source(string $source_key, string $triggered_by = 'cron'): array
    {
        $source = $this->source_registry->get($source_key);
        if (!is_array($source) || empty($source['mutable_snapshot'])) {
            throw new RuntimeException('engagement_source_invalid');
        }
        if (!$this->mark_stale_runs($source_key)) {
            return $this->source_retry($triggered_by, 'stale_run_detection_failed');
        }
        try { $open = $this->run_repository->find_open_run_for_source($source_key); }
        catch (Throwable $error) { return $this->source_retry($triggered_by, 'open_run_lookup_failed'); }
        if (is_array($open)) {
            return $this->scheduled($open);
        }
        if ($this->has_lock($source_key)) {
            return $this->source_retry($triggered_by, 'lock_active');
        }
        try { $creation_lock = $this->run_repository->acquire_source_creation_lock($source_key); }
        catch (Throwable $error) { return $this->source_retry($triggered_by, 'source_creation_lock_failed'); }
        if ($creation_lock === null) {
            return $this->source_retry($triggered_by, 'source_creation_lock_active');
        }
        $this->set_lock($source_key);
        try {
            // Re-read after acquiring the same source lock used by the scheduler.
            try { $open = $this->run_repository->find_open_run_for_source($source_key); }
            catch (Throwable $error) { return $this->source_retry($triggered_by, 'open_run_lookup_failed'); }
            if (is_array($open)) {
                return $this->scheduled($open);
            }
            $settings = $this->config->get_retention_source_settings($source_key);
            $days = $this->resolve_retention_days($settings, $source);
            $cutoff = $this->build_cutoff_value($days);
            $enabled = !empty($settings['enabled']);
            $target = 0;
            if ($enabled) {
                global $wpdb;
                $target = (int) $wpdb->get_var('SELECT id FROM ' . $source['source_table'] . ' ORDER BY id DESC LIMIT 1');
                $this->assert_query_ok();
            }
            $state = ['contract_version' => 1, 'phase' => 'candidates', 'candidate_cursor' => 0,
                'days' => [], 'day_index' => 0, 'files' => null,
                'pid_set_hash' => $this->config->get_landing_funnel_tkzone_summary_pid_set_hash(),
                'counts' => ['rows_before' => 0, 'age_candidates' => 0, 'updated_at_protected' => 0, 'preparation_session_deferred' => 0,
                    'session_deferred' => 0, 'changed_deferred' => 0, 'already_absent' => 0, 'rows_after' => 0],
                'daily_results' => []];
            $run = ['run_id' => $this->generate_run_id(), 'source_key' => $source_key,
                'source_table' => $source['source_table'], 'enabled' => $enabled,
                'dry_run' => !empty($settings['dry_run']), 'retention_days_effective' => $days,
                'cutoff_column' => 'updated_at', 'cutoff_value' => $cutoff, 'target_max_primary_key' => $target,
                'triggered_by' => $this->normalize_triggered_by($triggered_by),
                'started_at' => $this->current_time_mysql(), 'status' => $enabled ? 'pending' : 'skipped',
                'gate_status' => $enabled ? 'pending' : 'skipped', 'worker_phase' => 'engagement_preparing',
                'archive_batch_id' => $this->generate_archive_batch_id($source_key), 'gate_results_json' => $state];
            if (!$enabled) {
                // Disabled starts are terminal at the guarded INSERT. A
                // separate finalizing UPDATE would leave an orphan on failure.
                $run['finished_at'] = $run['started_at'];
                $run['worker_phase'] = 'cleanup_disabled';
                $run['error_code'] = 'cleanup_disabled';
            }
            $id = $this->run_repository->create_run_with_source_lock($run, $creation_lock);
            if ($id <= 0) {
                return $this->source_retry($triggered_by, 'run_audit_create_failed');
            }
            $run['id'] = $id;
            if (!$enabled) {
                return ['success' => true, 'run_id' => $run['run_id'],
                    'status' => 'skipped', 'gate_status' => 'skipped', 'worker_phase' => 'cleanup_disabled',
                    'error_code' => 'cleanup_disabled', 'gate_results_json' => $state];
            }
            return $this->scheduled($run);
        } catch (Throwable $error) {
            // Before a durable run exists, retry the requested source start.
            // A committed insert is found on that retry instead of duplicated.
            return $this->source_retry($triggered_by, 'source_preparation_failed');
        } finally {
            $this->clear_lock($source_key);
            try { $this->run_repository->release_source_creation_lock($creation_lock); }
            catch (Throwable $error) { /* A lost connection releases its server lock. */ }
        }
    }

    public function run_worker(string $source_key): array
    {
        $source = $this->source_registry->get($source_key);
        try { $run = $this->run_repository->find_open_run_for_source($source_key); }
        catch (Throwable $error) { return $this->scheduled([], 'open_run_lookup_failed'); }
        if (!is_array($run)) {
            return ['success' => true, 'status' => 'skipped', 'error_code' => 'no_open_cleanup_run'];
        }
        if ($this->has_worker_lock($source_key)) {
            return $this->scheduled($run, 'worker_lock_active');
        }
        if (($run['status'] ?? '') === 'blocked') {
            return ['success' => false, 'status' => 'blocked', 'error_code' => $run['error_code'] ?? 'cleanup_blocked'];
        }
        $this->set_worker_lock($source_key);
        $reader = new Kiwi_Retention_Archive_Reader($this->archive_service, $this->archive_lock, $this->corruption_safety_gate);
        $generation_handle = null;
        global $wpdb;
        try {
            // The initial open-run read precedes the transient lock. Another
            // worker may have advanced it before this invocation acquires it.
            try { $run = $this->run_repository->find_run_by_id((int) $run['id']); }
            catch (Throwable $error) { return $this->scheduled($run, 'run_audit_recheck_failed'); }
            if (!is_array($run) || ($run['finished_at'] ?? null) !== null) {
                return ['success' => true, 'status' => 'skipped', 'error_code' => 'no_open_cleanup_run'];
            }
            if (($run['status'] ?? '') === 'blocked') {
                return ['success' => false, 'status' => 'blocked', 'error_code' => $run['error_code'] ?? 'cleanup_blocked'];
            }
            $this->checkpoint = $this->run_repository->checkpoint_for_stored_run($run);
            $state = $this->read_state($run);
            if ($state['pid_set_hash'] !== $this->config->get_landing_funnel_tkzone_summary_pid_set_hash()) {
                throw new RuntimeException('engagement_pid_policy_changed');
            }
            $budget = ['rows' => 0, 'limit' => min(50000, $this->config->get_retention_worker_row_limit()),
                'deadline' => microtime(true) + min(60, $this->config->get_retention_worker_time_limit_seconds())];
            $run['worker_runs'] = (int) ($run['worker_runs'] ?? 0) + 1;
            $run['worker_last_started_at'] = $this->current_time_mysql();
            $worker_number = $run['worker_runs'];
            $worker_started_at = $run['worker_last_started_at'];
            $run['status'] = 'running';
            if ($state['phase'] === 'candidates') {
                $this->prepare($source, $run, $state, $reader, $budget);
            }
            if ($state['phase'] === 'coverage' && $this->available($budget)) {
                $this->coverage($source, $run, $state, $reader, $budget);
            }
            $reader->close();
            if ($state['phase'] === 'gate_failed') {
                $run['gate_status'] = 'failed';
                return $this->finish_state($run, $state, 'skipped', 'coverage_gate_failed');
            }
            if ($state['phase'] === 'archive' && !empty($run['dry_run'])) {
                $state['phase'] = 'dry_eligibility';
                $state['dry_cursor'] = 0;
                $state['counts']['released'] = 0;
            }
            if ($state['phase'] === 'dry_eligibility' && $this->available($budget)) {
                $this->dry_eligibility($source, $run, $state, $budget);
            }
            if ($state['phase'] === 'archive' && $this->available($budget)) {
                if (($run['archive_db_path'] ?? '') === '') {
                    $run['archive_db_path'] = $this->archive_service->resolve_archive_db_path();
                    $this->save($run, $state);
                }
                $path = $this->archive_service->resolve_archive_db_path($run['archive_db_path']);
                $lock = $this->archive_lock->acquire_for_archive($path);
                if (empty($lock['success']) || empty($lock['acquired'])) {
                    // Do not rewrite a possibly newer audit on lock deferral.
                    return $this->scheduled($run, (string) ($lock['error_code'] ?? 'archive_lock_active'));
                }
                $generation_handle = $lock['handle'];
                $locked_run = $this->run_repository->find_run_by_id((int) $run['id']);
                if (!is_array($locked_run) || ($locked_run['finished_at'] ?? null) !== null
                    || !in_array($locked_run['status'], ['pending', 'running', 'partial'], true)
                ) {
                    throw new RuntimeException('engagement_run_terminal_under_lock');
                }
                if ($this->run_repository->checkpoint_for_stored_run($locked_run) !== $this->checkpoint) {
                    return $this->scheduled($locked_run, 'engagement_checkpoint_advanced');
                }
                $run = $locked_run;
                $state = $this->read_state($locked_run);
                $run['worker_runs'] = $worker_number;
                $run['worker_last_started_at'] = $worker_started_at;
                $this->check_safety($path);
                $this->archive_and_delete($source, $run, $state, $budget, $generation_handle);
            }
            if ($state['phase'] === 'after' && $this->available($budget)) {
                $this->count_after($source, $run, $state, $budget);
            }
            if ($state['phase'] === 'done') {
                $released = !empty($run['dry_run']) ? $state['counts']['released'] : (int) ($run['deleted_rows'] ?? 0);
                $state['counts']['changed_deferred'] = max($state['counts']['changed_deferred'], $state['counts']['age_candidates']
                    - $released - $state['counts']['session_deferred'] - $state['counts']['already_absent']);
                $state['counts']['changed_deferred'] += $state['counts']['updated_at_protected'];
                $state['counts']['released'] = $released;
                return $this->finish_state($run, $state, 'completed', '');
            }
            $state['last_worker_rows'] = $budget['rows'];
            $this->save($run, $state);
            return $this->scheduled($run);
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            $reader->close();
            $code = preg_match('/^[a-z][a-z0-9_]*$/D', $error->getMessage()) ? $error->getMessage() : 'engagement_worker_failed';
            try { $persisted = $this->run_repository->find_run_by_id((int) $run['id']); }
            catch (Throwable $audit_error) { return $this->scheduled($run, 'engagement_audit_recheck_failed'); }
            if (!is_array($persisted) || ($persisted['finished_at'] ?? null) !== null) {
                return ['success' => false, 'error_code' => 'engagement_audit_recheck_failed'];
            }
            // COMMIT acknowledgement may be lost, or another worker may have
            // advanced the checkpoint. Never write an earlier state over it.
            if ($this->run_repository->checkpoint_for_stored_run($persisted) !== $this->checkpoint) {
                return $this->scheduled($persisted, 'engagement_checkpoint_advanced');
            }
            if ($code === 'archive_lock_active') { return $this->scheduled($persisted, $code); }
            // Preserve recovery after destructive work and through final counts.
            if (isset($state) && in_array($state['phase'], ['archive', 'after', 'done'], true)) {
                $run = $persisted;
                $state = $this->read_state($persisted);
                $run['worker_runs'] = max((int) $run['worker_runs'], (int) ($worker_number ?? 0));
                $run['error_code'] = $code;
                $run['error_message'] = 'Engagement worker paused at a verified archive/delete boundary.';
                try { $this->save($run, $state); } catch (Throwable $audit_error) {
                    return $this->scheduled($persisted, 'engagement_audit_checkpoint_failed');
                }
                return $this->scheduled($run, $code);
            }
            return $this->finish_state($run, $state ?? $this->read_state($persisted), 'failed', $code);
        } finally {
            $reader->close();
            $this->archive_lock->release($generation_handle);
            $this->clear_worker_lock($source_key);
        }
    }

    protected function build_cutoff_value(int $retention_days): string
    {
        $date = substr($this->current_time_mysql(), 0, 10);
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))
            ->modify('-' . max(7, $retention_days) . ' days')->format('Y-m-d') . ' 00:00:00';
    }

    private function prepare(array $source, array &$run, array &$state, Kiwi_Retention_Archive_Reader $reader, array &$budget): void
    {
        global $wpdb;
        while ($this->available($budget)) {
            $rows = $this->source_page($source, (int) $state['candidate_cursor'], (int) $run['target_max_primary_key'], $this->page_limit($budget));
            if (!$rows) {
                $state['days'] = array_keys($state['days']);
                sort($state['days'], SORT_STRING);
                $state['files'] = array_column($reader->files(true), 'path');
                $state['phase'] = 'coverage';
                $run['eligible_rows'] = $state['counts']['age_candidates'];
                return;
            }
            foreach ($rows as $row) {
                if (!$this->available($budget)) { return; }
                $budget['rows']++;
                $state['counts']['rows_before']++;
                $state['candidate_cursor'] = (int) $row['id'];
                if (strcmp($row['updated_at'], $run['cutoff_value']) >= 0) {
                    if (strcmp($row['created_at'], $run['cutoff_value']) < 0) { $state['counts']['updated_at_protected']++; }
                    continue;
                }
                $state['counts']['age_candidates']++;
                $date = substr($row['created_at'], 0, 10);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
                    throw new RuntimeException('engagement_created_date_invalid');
                }
                $state['days'][$date] = true;
                if (!empty($row['_retention_live_session'])) {
                    $state['counts']['preparation_session_deferred']++;
                }
                $reader->copies($source, (int) $row['id']);
            }
            $this->save($run, $state);
        }
    }

    private function coverage(array $source, array &$run, array &$state, Kiwi_Retention_Archive_Reader $reader, array &$budget): void
    {
        if (array_column($reader->files(true), 'path') !== $state['files']) {
            throw new RuntimeException('archive_inventory_changed');
        }
        $sessions = $this->source_registry->get(Kiwi_Retention_Source_Registry::SOURCE_LANDING_PAGE_SESSIONS);
        while ($this->available($budget) && $state['day_index'] < count($state['days'])) {
            $date = $state['days'][$state['day_index']];
            if ($this->historical($date)) {
                $state['daily_results'][$date] = ['historical_exception' => true, 'ok' => true];
                $state['day_index']++;
                continue;
            }
            if (!isset($state['day'])) {
                $zero = array_fill_keys(self::METRICS, 0);
                $state['day'] = ['stage' => 'raw', 'origin' => 0, 'cursor' => ['id' => 0, 'created_at' => $date . ' 00:00:00'],
                    'raw' => ['main' => $zero, 'tkzone' => $zero],
                    'summary' => ['main' => $zero, 'tkzone' => $zero], 'summary_rows' => ['main' => 0, 'tkzone' => 0]];
            }
            $day =& $state['day'];
            if ($day['stage'] === 'raw') {
                $origin = (int) $day['origin'];
                if ($origin > count($state['files'])) {
                    $day['stage'] = 'main';
                    $day['summary_cursor'] = 0;
                    continue;
                }
                $rows = $origin === 0
                    ? $this->live_day_page($source, $date, $day['cursor'], 1)
                    : $reader->day_page($source, $state['files'][$origin - 1], $date, $day['cursor'], 1);
                if (!$rows) {
                    $day['origin']++;
                    $day['cursor'] = ['id' => 0, 'created_at' => $date . ' 00:00:00'];
                    continue;
                }
                $row = $rows[0];
                $canonical = $this->canonical($source, $row, $origin, $state['files'], $reader);
                if ($canonical !== null) {
                    $signature = hash('sha256', json_encode(Kiwi_Retention_Archive_Reader::snapshot($source, $canonical), JSON_THROW_ON_ERROR));
                    if (!isset($day['lookup']) || $day['lookup']['signature'] !== $signature) {
                        $budget['rows']++;
                        $day['lookup'] = ['signature' => $signature, 'origin' => 0, 'id' => 0,
                            'cursor' => ['id' => 0, 'created_at' => $date . ' 00:00:00'], 'main' => false, 'tkzone' => false];
                    }
                    if (!$this->session_pair($sessions, $canonical, $day['lookup'], $state['files'], $reader, $budget)) {
                        return;
                    }
                    foreach (['main', 'tkzone'] as $mode) {
                        if ($day['lookup'][$mode]) {
                            $day['raw'][$mode]['page_loaded_sessions'] += $canonical['page_loaded_at'] === null ? 0 : 1;
                            foreach (['cta1', 'cta2', 'cta3'] as $step) {
                                $day['raw'][$mode][$step . '_sessions'] += $canonical['first_' . $step . '_click_at'] === null ? 0 : 1;
                                $day['raw'][$mode][$step . '_click_events'] += (int) $canonical[$step . '_click_count'];
                            }
                        }
                    }
                    unset($day['lookup']);
                } else { $budget['rows']++; }
                $day['cursor'] = ['id' => (int) $row['id'], 'created_at' => $row['created_at']];
                continue;
            }
            $mode = $day['stage'];
            $rows = $this->summary_page($mode, $date, (int) $day['summary_cursor'], $this->page_limit($budget));
            foreach ($rows as $row) {
                if (!$this->available($budget)) { return; }
                $budget['rows']++;
                foreach (self::METRICS as $metric) { $day['summary'][$mode][$metric] += (int) $row[$metric]; }
                $day['summary_rows'][$mode]++;
                $day['summary_cursor'] = (int) $row['id'];
            }
            if ($rows) { continue; }
            if ($mode === 'main') {
                $day['stage'] = 'tkzone';
                $day['summary_cursor'] = 0;
                continue;
            }
            $result = ['historical_exception' => false, 'ok' => true, 'modes' => []];
            foreach (['main', 'tkzone'] as $checked_mode) {
                $comparison = self::compare_day($day['raw'][$checked_mode], $day['summary'][$checked_mode], $day['summary_rows'][$checked_mode] > 0);
                $result['modes'][$checked_mode] = $comparison;
                $result['ok'] = $result['ok'] && $comparison['ok'];
            }
            $state['daily_results'][$date] = $result;
            unset($state['day']);
            if (!$result['ok']) {
                $state['phase'] = 'gate_failed';
                return;
            }
            $state['day_index']++;
            $this->save($run, $state);
        }
        if ($state['day_index'] === count($state['days'])) {
            if (array_column($reader->files(true), 'path') !== $state['files']) {
                throw new RuntimeException('archive_inventory_changed');
            }
            $state['phase'] = 'archive';
            $state['archive_scan_cursor'] = 0;
            $run['gate_status'] = 'passed';
        }
    }

    private function session_pair(array $source, array $engagement, array &$lookup, array $files, Kiwi_Retention_Archive_Reader $reader, array &$budget): bool
    {
        global $wpdb;
        if ($engagement['landing_key'] === '' || $engagement['session_token'] === '') { return true; }
        $date = substr($engagement['created_at'], 0, 10);
        while ($this->available($budget) && $lookup['origin'] <= count($files)) {
            $origin = (int) $lookup['origin'];
            if ($origin === 0) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    'SELECT id,landing_key,session_token,created_at,pid FROM ' . $source['source_table'] . ' WHERE landing_key=%s AND session_token=%s
                     AND created_at>=%s AND created_at<%s AND id>%d ORDER BY id LIMIT %d',
                    $engagement['landing_key'], $engagement['session_token'], $date . ' 00:00:00',
                    (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
                    $lookup['id'], $this->page_limit($budget)
                ), ARRAY_A);
                $this->assert_query_ok();
            } else {
                $rows = $reader->pair_page($source, $files[$origin - 1], $engagement, $lookup['cursor'], $this->page_limit($budget));
            }
            if (!$rows) {
                $lookup['origin']++;
                $lookup['id'] = 0;
                $lookup['cursor'] = ['id' => 0, 'created_at' => $date . ' 00:00:00'];
                continue;
            }
            foreach ($rows as $row) {
                if (!$this->available($budget)) { return false; }
                $budget['rows']++;
                $lookup['id'] = (int) $row['id'];
                $lookup['cursor'] = ['id' => (int) $row['id'], 'created_at' => $row['created_at']];
                $canonical = $this->canonical($source, $row, $origin, $files, $reader);
                if ($canonical !== null && substr($canonical['created_at'], 0, 10) === $date) {
                    $lookup['main'] = true;
                    $pid = substr(preg_replace('/[^A-Za-z0-9._~:-]/', '', trim((string) $canonical['pid'])), 0, 191);
                    // PID values/allowlist are ASCII-normalized; match the
                    // existing case-insensitive MariaDB IN predicate.
                    $lookup['tkzone'] = $lookup['tkzone'] || in_array(strtolower($pid), array_map('strtolower', $this->config->get_landing_funnel_tkzone_summary_pids()), true);
                }
            }
        }
        return $lookup['origin'] > count($files);
    }

    private function canonical(array $source, array $row, int $origin, array $files, Kiwi_Retention_Archive_Reader $reader): ?array
    {
        if ($source['source_key'] === Kiwi_Retention_Source_Registry::SOURCE_LANDING_PAGE_SESSIONS) {
            return $this->canonical_session($source, $row, $origin, $files, $reader);
        }
        return $this->canonical_engagement($source, $row, $origin, $files, $reader);
    }

    private function canonical_session(array $source, array $row, int $origin, array $files, Kiwi_Retention_Archive_Reader $reader): ?array
    {
        global $wpdb;
        $live = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $source['source_table'] . ' WHERE id=%d LIMIT 1', $row['id']), ARRAY_A);
        $this->assert_query_ok();
        $copies = $reader->session_copies($source, (int) $row['id'], $live);
        if ($origin === 0) {
            return $live;
        }
        if ($live !== null) { return null; }
        // Immutable Sessions retain the first copy in validated inventory order.
        return array_key_first($copies) === $files[$origin - 1] ? $row : null;
    }

    private function canonical_engagement(array $source, array $row, int $origin, array $files, Kiwi_Retention_Archive_Reader $reader): ?array
    {
        // Verify every copy and its receipt even when MySQL has a live row.
        $copies = $reader->copies($source, (int) $row['id']);
        $live = $this->live_by_id($source, (int) $row['id']);
        if ($origin === 0) { return $live; }
        if ($live !== null) { return null; }
        // Inventory is ordered by validated year, then numeric generation.
        return array_key_last($copies) === $files[$origin - 1]
            ? Kiwi_Retention_Archive_Reader::snapshot($source, $row) : null;
    }

    public static function compare_day(array $raw, array $summary, bool $coverage_present): array
    {
        $result = ['ok' => $coverage_present, 'coverage_present' => $coverage_present, 'metrics' => []];
        foreach (self::METRICS as $metric) {
            $raw_value = (int) ($raw[$metric] ?? 0);
            $summary_value = (int) ($summary[$metric] ?? 0);
            $basis = max(abs($raw_value), abs($summary_value));
            $difference = abs($raw_value - $summary_value);
            $cta = $metric !== 'page_loaded_sessions';
            // Exact integer comparison to 2%, without multiplication overflow
            // or a percent-ceiling that would admit an extra event.
            $whole_allowance = intdiv($basis, 100) * 2 + intdiv(($basis % 100) * 2, 100);
            $ok = $coverage_present && ($difference <= $whole_allowance || ($cta && $difference <= 5));
            $result['metrics'][$metric] = ['raw' => $raw_value, 'summary' => $summary_value,
                'difference' => $difference, 'difference_percent' => $basis === 0 ? 0 : 100 * ($difference / $basis),
                'allowed_difference' => max($cta ? 5 : 0, 0.02 * $basis), 'ok' => $ok];
            $result['ok'] = $result['ok'] && $ok;
        }
        return $result;
    }

    public static function historical(string $date): bool
    {
        return strcmp($date, '2026-04-23') >= 0 && strcmp($date, '2026-07-06') < 0;
    }

    private function archive_and_delete(array $source, array &$run, array &$state, array &$budget, Kiwi_Retention_Archive_Lock_Handle $handle): void
    {
        global $wpdb;
        $path = $run['archive_db_path'];
        while ($this->available($budget)) {
            $new_pending = false;
            $this->check_safety($path);
            // This also discovers a receipt committed before its audit cursor.
            $receipt_ids = is_file($path) ? $this->archive_service->fetch_archived_primary_key_batch(
                $source, $path, $run['archive_batch_id'], (int) ($run['delete_last_primary_key'] ?? 0), $this->page_limit($budget)
            ) : [];
            if ($receipt_ids) {
                $pending_ids = $state['pending_batch']['receipt_ids'] ?? [];
                if ($receipt_ids !== array_slice($pending_ids, 0, count($receipt_ids))) {
                    throw new RuntimeException('engagement_receipt_checkpoint_mismatch');
                }
                $snapshots = $this->archive_service->fetch_verified_snapshots($source, $path, $run['archive_batch_id'], $receipt_ids);
                $budget['rows'] += count($receipt_ids);
                $this->delete_snapshots($source, $run, $state, $snapshots, $path);
                continue;
            }
            if (!isset($state['pending_batch'])) {
                $rows = $this->source_page($source, (int) $state['archive_scan_cursor'], (int) $run['target_max_primary_key'], $this->page_limit($budget));
                if (!$rows) {
                    $state['phase'] = 'after';
                    $state['after_cursor'] = 0;
                    $state['after_target'] = (int) $wpdb->get_var('SELECT id FROM ' . $source['source_table'] . ' ORDER BY id DESC LIMIT 1');
                    $this->assert_query_ok();
                    return;
                }
                $pending = ['through' => (int) $state['archive_scan_cursor'], 'hashes' => [],
                    'receipt_ids' => null, 'session_deferred' => 0, 'changed_deferred' => 0, 'already_absent' => 0];
                foreach ($rows as $row) {
                    if (!$this->available($budget)) { break; }
                    $budget['rows']++;
                    $pending['through'] = (int) $row['id'];
                    if (strcmp($row['updated_at'], $run['cutoff_value']) >= 0) { continue; }
                    if (!empty($row['_retention_live_session'])) { $pending['session_deferred']++; continue; }
                    $pending['hashes'][(int) $row['id']] = hash('sha256', json_encode(Kiwi_Retention_Archive_Reader::snapshot($source, $row), JSON_THROW_ON_ERROR));
                }
                $state['pending_batch'] = $pending;
                $new_pending = true;
                // Bound pending IDs and hashes to this <=500-row page. No raw
                // subscriber/session values are stored in the checkpoint.
                $this->save($run, $state);
            }
            // A freshly scanned page already used this invocation's budget.
            // Uncommitted IDs restored from an older checkpoint must use the
            // current budget, leaving any suffix for a later invocation.
            $ids = array_slice(array_keys($state['pending_batch']['hashes']), 0,
                $new_pending ? 500 : $this->page_limit($budget));
            if (!$new_pending) { $budget['rows'] += count($ids); }
            $reader = new Kiwi_Retention_Archive_Reader($this->archive_service, $this->archive_lock, $this->corruption_safety_gate);
            try {
                foreach ($ids as $id) { $reader->copies($source, (int) $id, $handle); }
            } finally { $reader->close(); }
            $this->check_safety($path);
            // Re-read eligibility immediately before committing the snapshots.
            $fresh = [];
            foreach ($ids as $id) {
                $signature = $state['pending_batch']['hashes'][$id];
                $current = $this->live_by_id($source, (int) $id);
                if ($current === null) { $state['pending_batch']['already_absent']++; }
                elseif (hash('sha256', json_encode(Kiwi_Retention_Archive_Reader::snapshot($source, $current), JSON_THROW_ON_ERROR)) !== $signature
                    || strcmp($current['updated_at'], $run['cutoff_value']) >= 0
                ) { $state['pending_batch']['changed_deferred']++; }
                elseif ($this->live_session_exists($current)) { $state['pending_batch']['session_deferred']++; }
                else { $fresh[] = $current; continue; }
                unset($state['pending_batch']['hashes'][$id]);
            }
            $state['pending_batch']['receipt_ids'] = array_map('intval', array_column($fresh, 'id'));
            $this->save($run, $state);
            if ($fresh) {
                $snapshots = $this->archive_service->archive_snapshot_batch($source, $fresh, $run['cutoff_value'], $run['archive_batch_id'], $path);
                $this->delete_snapshots($source, $run, $state, $snapshots, $path);
            } else {
                if (!$state['pending_batch']['hashes']) { $this->finish_pending_page($state); }
                $this->save($run, $state);
            }
        }
    }

    private function delete_snapshots(array $source, array &$run, array &$state, array $snapshots, string $path): void
    {
        global $wpdb;
        foreach ($snapshots['rows'] as $row) {
            $expected = $state['pending_batch']['hashes'][(int) $row['id']] ?? '';
            $actual = hash('sha256', json_encode(Kiwi_Retention_Archive_Reader::snapshot($source, $row), JSON_THROW_ON_ERROR));
            if ($expected === '' || !hash_equals($expected, $actual)) {
                throw new RuntimeException('engagement_snapshot_hash_mismatch');
            }
        }
        $this->check_safety($path);
        if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('engagement_delete_transaction_failed'); }
        $next_run = $run;
        $next_state = $state;
        $previous_checkpoint = $this->checkpoint;
        try {
            foreach ($snapshots['rows'] as $archived) {
                $id = (int) $archived['id'];
                $current = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $source['source_table'] . ' WHERE id=%d FOR UPDATE', $id), ARRAY_A);
                $this->assert_query_ok();
                $next_run['archived_rows'] = (int) ($next_run['archived_rows'] ?? 0) + 1;
                if ($current === null) {
                    $next_state['counts']['already_absent']++;
                } elseif (Kiwi_Retention_Archive_Reader::snapshot($source, $current) !== Kiwi_Retention_Archive_Reader::snapshot($source, $archived)
                    || strcmp($current['updated_at'], $run['cutoff_value']) >= 0
                ) {
                    $next_state['counts']['changed_deferred']++;
                } elseif ($this->live_session_exists($current)) {
                    $next_state['counts']['session_deferred']++;
                } else {
                    $this->check_safety($path);
                    $clauses = ['e.id=%d', 'e.updated_at<%s'];
                    $args = [$id, $run['cutoff_value']];
                    foreach ($source['archive_columns'] as $column => $type) {
                        if ($archived[$column] === null) {
                            $clauses[] = 'e.' . $column . ' IS NULL';
                        } else {
                            $clauses[] = $type === 'INTEGER' ? 'e.' . $column . ' <=> %d' : 'BINARY e.' . $column . ' <=> BINARY %s';
                            $args[] = $archived[$column];
                        }
                    }
                    $sessions = $this->source_registry->get(Kiwi_Retention_Source_Registry::SOURCE_LANDING_PAGE_SESSIONS)['source_table'];
                    $clauses[] = 'NOT EXISTS (SELECT 1 FROM ' . $sessions . ' s WHERE s.landing_key=e.landing_key AND s.session_token=e.session_token)';
                    $deleted = $wpdb->query($wpdb->prepare('DELETE e FROM ' . $source['source_table'] . ' e WHERE ' . implode(' AND ', $clauses), ...$args));
                    if ($deleted === false) { throw new RuntimeException('engagement_snapshot_delete_failed'); }
                    if ($deleted === 1) {
                        $next_run['deleted_rows'] = (int) ($next_run['deleted_rows'] ?? 0) + 1;
                    } else { $next_state['counts']['session_deferred']++; }
                }
                $next_run['delete_last_primary_key'] = max((int) ($next_run['delete_last_primary_key'] ?? 0), $id);
                $next_run['archive_last_primary_key'] = max((int) ($next_run['archive_last_primary_key'] ?? 0), $id);
            }
            $next_run['archive_inserted_rows'] = (int) ($next_run['archive_inserted_rows'] ?? 0) + $snapshots['archive_inserted_count'];
            $next_run['archive_duplicate_rows'] = (int) ($next_run['archive_duplicate_rows'] ?? 0) + $snapshots['archive_duplicate_count'];
            $next_run['delete_batches'] = (int) ($next_run['delete_batches'] ?? 0) + 1;
            $handled_ids = array_map('intval', array_column($snapshots['rows'], 'id'));
            $next_state['pending_batch']['receipt_ids'] = array_slice($next_state['pending_batch']['receipt_ids'], count($handled_ids));
            foreach ($handled_ids as $id) { unset($next_state['pending_batch']['hashes'][$id]); }
            if (!$next_state['pending_batch']['receipt_ids'] && !$next_state['pending_batch']['hashes']) {
                $this->finish_pending_page($next_state);
            }
            // MySQL delete and its audit cursor commit atomically. An audit
            // failure rolls back deletes; the SQLite receipt remains resumable.
            $this->save($next_run, $next_state);
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('engagement_delete_commit_failed'); }
            $run = $next_run;
            $state = $next_state;
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            $this->checkpoint = $previous_checkpoint;
            throw $error;
        }
    }

    private function count_after(array $source, array &$run, array &$state, array &$budget): void
    {
        global $wpdb;
        while ($this->available($budget)) {
            $rows = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . $source['source_table']
                . ' WHERE id>%d AND id<=%d ORDER BY id LIMIT %d', $state['after_cursor'], $state['after_target'], $this->page_limit($budget)));
            $this->assert_query_ok();
            if (!$rows) { $state['phase'] = 'done'; return; }
            $budget['rows'] += count($rows);
            $state['counts']['rows_after'] += count($rows);
            $state['after_cursor'] = (int) end($rows);
            $this->save($run, $state);
        }
    }

    private function finish_pending_page(array &$state): void
    {
        foreach (['session_deferred', 'changed_deferred', 'already_absent'] as $count) {
            $state['counts'][$count] += $state['pending_batch'][$count];
        }
        $state['archive_scan_cursor'] = $state['pending_batch']['through'];
        unset($state['pending_batch']);
    }

    private function dry_eligibility(array $source, array &$run, array &$state, array &$budget): void
    {
        global $wpdb;
        while ($this->available($budget)) {
            $rows = $this->source_page($source, $state['dry_cursor'], (int) $run['target_max_primary_key'], $this->page_limit($budget));
            if (!$rows) {
                $state['phase'] = 'after';
                $state['after_cursor'] = 0;
                $state['after_target'] = (int) $wpdb->get_var('SELECT id FROM ' . $source['source_table'] . ' ORDER BY id DESC LIMIT 1');
                $this->assert_query_ok();
                return;
            }
            foreach ($rows as $row) {
                if (!$this->available($budget)) { return; }
                $budget['rows']++;
                $state['dry_cursor'] = (int) $row['id'];
                if (strcmp($row['updated_at'], $run['cutoff_value']) >= 0) { continue; }
                if (!empty($row['_retention_live_session'])) { $state['counts']['session_deferred']++; }
                else { $state['counts']['released']++; }
            }
            $this->save($run, $state);
        }
    }

    private function live_day_page(array $source, string $date, array $cursor, int $limit): array
    {
        global $wpdb;
        $next = (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        $args = [$date . ' 00:00:00', $next . ' 00:00:00'];
        $keyset = '';
        if ($cursor['id'] > 0) {
            $previous = $this->live_by_id($source, (int) $cursor['id']);
            if ($previous === null) { throw new RuntimeException('engagement_day_cursor_disappeared'); }
            $keyset = ' AND (created_at, landing_key, session_token, id) > (%s, %s, %s, %d)';
            $args = array_merge($args, [$previous['created_at'], $previous['landing_key'], $previous['session_token'], $previous['id']]);
        }
        $args[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $source['source_table']
            . ' WHERE created_at>=%s AND created_at<%s' . $keyset . ' ORDER BY created_at, landing_key, session_token, id LIMIT %d', ...$args), ARRAY_A);
        $this->assert_query_ok();
        return $rows;
    }

    private function source_page(array $source, int $cursor, int $target, int $limit): array
    {
        global $wpdb;
        $sessions = $this->source_registry->get(Kiwi_Retention_Source_Registry::SOURCE_LANDING_PAGE_SESSIONS)['source_table'];
        $rows = $wpdb->get_results($wpdb->prepare('SELECT e.*, EXISTS(SELECT 1 FROM ' . $sessions
            . ' s WHERE s.landing_key=e.landing_key AND s.session_token=e.session_token) AS _retention_live_session
            FROM ' . $source['source_table'] . ' e WHERE e.id>%d AND e.id<=%d ORDER BY e.id LIMIT %d', $cursor, $target, $limit), ARRAY_A);
        $this->assert_query_ok();
        return $rows;
    }

    private function live_by_id(array $source, int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $source['source_table'] . ' WHERE id=%d LIMIT 1', $id), ARRAY_A);
        $this->assert_query_ok();
        return is_array($row) ? $row : null;
    }

    private function live_session_exists(array $row): bool
    {
        global $wpdb;
        $sessions = $this->source_registry->get(Kiwi_Retention_Source_Registry::SOURCE_LANDING_PAGE_SESSIONS)['source_table'];
        $id = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $sessions . ' WHERE landing_key=%s AND session_token=%s LIMIT 1', $row['landing_key'], $row['session_token']));
        $this->assert_query_ok();
        return $id !== null;
    }

    private function summary_page(string $mode, string $date, int $cursor, int $limit): array
    {
        global $wpdb;
        $table = $wpdb->prefix . ($mode === 'main' ? 'kiwi_landing_funnel_daily_summary' : 'kiwi_landing_funnel_daily_tkzone_summary');
        $filter = $mode === 'tkzone' ? ' AND pid_set_hash=%s' : '';
        $args = [$date, $cursor];
        if ($mode === 'tkzone') { $args[] = $this->config->get_landing_funnel_tkzone_summary_pid_set_hash(); }
        $args[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id,' . implode(',', self::METRICS) . ' FROM ' . $table
            . ' WHERE metric_date=%s AND id>%d' . $filter . ' ORDER BY id LIMIT %d', ...$args), ARRAY_A);
        $this->assert_query_ok();
        return $rows;
    }

    private function check_safety(string $path): void
    {
        if (empty($this->corruption_safety_gate->inspect($path, false)['allowed'])) {
            throw new RuntimeException('engagement_corruption_gate_blocked');
        }
    }

    private function read_state(array $run): array
    {
        $state = $run['gate_results_json'] ?? null;
        if (is_string($state)) { $state = json_decode($state, true); }
        if (!is_array($state) || ($state['contract_version'] ?? null) !== 1) {
            throw new RuntimeException('engagement_checkpoint_invalid');
        }
        return $state;
    }

    private function save(array &$run, array $state): void
    {
        $phase = in_array($state['phase'], ['candidates', 'coverage'], true) ? 'engagement_preparing'
            : ($state['phase'] === 'archive' ? 'engagement_archive' : 'engagement_after');
        $run['worker_phase'] = $phase;
        $run['status'] = 'partial';
        $run['gate_results_json'] = $state;
        $run['worker_last_finished_at'] = $this->current_time_mysql();
        $data = $run;
        unset($data['id']);
        if (!$this->run_repository->update_run_checkpoint((int) $run['id'], $data, $this->checkpoint)) {
            throw new RuntimeException('engagement_audit_checkpoint_failed');
        }
        $this->checkpoint = $this->run_repository->checkpoint_after_update($data, $this->checkpoint);
    }

    private function finish_state(array $run, array $state, string $status, string $code): array
    {
        $result = array_merge($run, ['success' => $code === '' || $code === 'coverage_gate_failed',
            'status' => $status, 'worker_phase' => $status === 'failed' ? 'failed' : 'completed', 'gate_results_json' => $state,
            'error_code' => $code, 'error_message' => $code === '' ? '' : ($status === 'failed'
                ? 'Engagement preparation stopped without releasing archive/delete work.' : 'Engagement daily coverage did not release cleanup.'),
            'archive_integrity_check' => empty($run['dry_run']) ? 'receipt_verified_external_health_deferred' : '',
            'worker_last_finished_at' => $this->current_time_mysql()]);
        $result['finished_at'] = $this->current_time_mysql();
        unset($result['id']);
        try { $updated = $this->run_repository->update_run_checkpoint((int) $run['id'], $result, $this->checkpoint); }
        catch (Throwable $error) { $updated = false; }
        return $updated ? $result : $this->scheduled($run, 'engagement_audit_finalize_failed');
    }

    private function scheduled(array $run, string $code = ''): array
    {
        return ['success' => $code === '', 'run_id' => $run['run_id'] ?? '', 'status' => $run['status'] ?? 'partial',
            'worker_phase' => $run['worker_phase'] ?? 'engagement_preparing', 'schedule_worker' => true,
            'reschedule_worker' => true, 'reschedule_delay_seconds' => $this->config->get_retention_worker_reschedule_delay_seconds(),
            'error_code' => $code];
    }

    private function source_retry(string $triggered_by, string $code): array
    {
        return array_merge($this->scheduled([], $code), ['retry_source' => true,
            'triggered_by' => $this->normalize_triggered_by($triggered_by)]);
    }

    private function available(array $budget): bool
    {
        return $budget['rows'] < $budget['limit'] && microtime(true) < $budget['deadline'];
    }

    private function page_limit(array $budget): int { return min(500, max(1, $budget['limit'] - $budget['rows'])); }

    private function assert_query_ok(): void
    {
        global $wpdb;
        if ((string) ($wpdb->last_error ?? '') !== '') { throw new RuntimeException('engagement_query_failed'); }
    }
}
