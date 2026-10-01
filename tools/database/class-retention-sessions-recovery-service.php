<?php

if (!defined('ABSPATH')) { exit; }

final class Kiwi_Recovery_Main_Summary_Repository extends Kiwi_Landing_Funnel_Daily_Summary_Repository
{
    private $table;
    public function __construct(string $table) { $this->table = Kiwi_Retention_Recovery_SQL::identifier($table); }
    public function get_table_name(): string { return $this->table; }
}

final class Kiwi_Recovery_Tkzone_Summary_Repository extends Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository
{
    private $table;
    public function __construct(string $table, Kiwi_Config $config)
    {
        parent::__construct($config);
        $this->table = Kiwi_Retention_Recovery_SQL::identifier($table);
    }
    public function get_table_name(): string { return $this->table; }
}

/** External recovery only. Never loaded by the normal WordPress bootstrap. */
final class Kiwi_Retention_Sessions_Recovery_Service
{
    private const CTA = ['cta1_sessions', 'cta1_click_events', 'cta2_sessions', 'cta2_click_events', 'cta3_sessions', 'cta3_click_events'];
    private $config;
    private $reader;
    private $journal;
    private $temporary = [];
    private $transaction = false;
    private $named_lock = '';
    private $timeouts = [];
    private $reconnect_retries = null;

    public function __construct(?Kiwi_Config $config = null, ?Kiwi_Retention_Recovery_Handoff_Reader $reader = null, ?callable $journal = null)
    {
        $this->config = $config ?: new Kiwi_Config();
        $this->reader = $reader ?: new Kiwi_Retention_Recovery_Handoff_Reader($this->config);
        $this->journal = $journal;
    }

    public function preview(string $from, string $to, string $cutoff): array
    {
        return $this->reconcile($from, $to, $cutoff, '');
    }

    public function apply(string $from, string $to, string $cutoff, string $expected_preview, int $max_workers = 1): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $expected_preview) !== 1 || !is_callable($this->journal)
            || $max_workers < 1 || $max_workers > 20) {
            return $this->failure('reviewed_preview_and_journal_required');
        }
        $result = $this->reconcile($from, $to, $cutoff, $expected_preview);
        if (empty($result['success']) || empty($result['run_id'])) { return $result; }
        $workers = $this->resume($result['run_id'], $expected_preview, $max_workers);
        return array_merge($result, $workers, ['summary_published' => true]);
    }

    public function resume(string $run_id, string $expected_preview, int $max_workers = 1): array
    {
        global $wpdb;
        $completed = false;
        try {
            if ((bool) ini_get('opcache.enable_cli')) { throw new RuntimeException('recovery_cli_opcode_cache_enabled'); }
            if (!$run_id || preg_match('/^[a-f0-9]{64}$/D', $expected_preview) !== 1
                || $max_workers < 1 || $max_workers > 20 || !is_callable($this->journal)) {
                throw new RuntimeException('recovery_resume_arguments_invalid');
            }
            $this->acquire_named_lock();
            $this->bound_lock_waits();
            $run = $this->recovery_run($run_id, $expected_preview);
            $workers = [];
            $cleanup = new Kiwi_Retention_Cleanup_Service($this->config);
            for ($i = 0; $i < $max_workers && $run['status'] !== 'completed'; $i++) {
                $open = (new Kiwi_Retention_Cleanup_Run_Repository())->find_open_run_for_source('landing_page_sessions');
                if (!$open || $open['run_id'] !== $run_id) { throw new RuntimeException('recovery_run_not_open'); }
                $worker = $cleanup->run_worker_for_run('landing_page_sessions', $run_id);
                if (($worker['run_id'] ?? '') !== $run_id || empty($worker['success'])) {
                    throw new RuntimeException('recovery_worker_failed');
                }
                $workers[] = array_intersect_key($worker, array_flip([
                    'run_id', 'status', 'worker_phase', 'archived_rows', 'deleted_rows', 'error_code',
                ]));
                $run = $this->recovery_run($run_id, $expected_preview);
                call_user_func($this->journal, ['phase' => 'worker_finished', 'run_id' => $run_id, 'result' => end($workers)]);
                if (!empty($worker['reschedule_delay_seconds'])) { break; }
            }
            $completed = $run['status'] === 'completed';
            $verification = $completed ? $this->verify_completion($run) : [];
            return ['success' => true, 'status' => $completed ? 'completed' : $run['status'],
                'run_id' => $run_id, 'workers' => $workers, 'verification' => $verification,
                'archived_rows' => (int) $run['archived_rows'], 'deleted_rows' => (int) $run['deleted_rows'],
                'resume_required' => !$completed];
        } catch (Throwable $error) {
            return $this->failure($this->safe_error($error), ['run_id' => $run_id, 'resume_required' => !$completed]);
        } finally { $this->release_named_lock(); $this->restore_lock_waits(); }
    }

    private function reconcile(string $from, string $to, string $cutoff, string $expected): array
    {
        global $wpdb;
        $apply = $expected !== '';
        $committed = false;
        $commit_requested = false;
        $report = ['mode' => $apply ? 'apply' : 'preview', 'summary_published' => false];
        try {
            $dates = $this->dates($from, $to);
            $settings = $this->config->get_retention_source_settings('landing_page_sessions');
            $source = (new Kiwi_Retention_Source_Registry())->get('landing_page_sessions');
            $days = max((int) $source['retention_days_min'], (int) $settings['retention_days']);
            $normal_cutoff = gmdate('Y-m-d', strtotime(substr(current_time('mysql'), 0, 10) . ' -' . $days . ' days')) . ' 00:00:00';
            if ($cutoff !== $normal_cutoff || $to >= substr($cutoff, 0, 10)) {
                throw new RuntimeException('recovery_cutoff_mismatch');
            }
            if ((bool) ini_get('opcache.enable_cli')) { throw new RuntimeException('recovery_cli_opcode_cache_enabled'); }
            if ($apply) { $this->acquire_named_lock(); $this->bound_lock_waits(); }
            $this->reader->acquire();
            $session_table = Kiwi_Retention_Recovery_SQL::identifier($source['source_table']);
            $main = Kiwi_Retention_Recovery_SQL::identifier((new Kiwi_Landing_Funnel_Daily_Summary_Repository())->get_table_name());
            $tk = Kiwi_Retention_Recovery_SQL::identifier((new Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository($this->config))->get_table_name());
            $handoffs = Kiwi_Retention_Recovery_SQL::identifier($wpdb->prefix . 'kiwi_landing_handoff_events');
            foreach ([$session_table, Kiwi_Database_Table_Names::landing_session_engagements(), $handoffs,
                $wpdb->prefix . 'kiwi_sales', $main, $tk, $wpdb->prefix . 'kiwi_retention_cleanup_runs',
                $wpdb->prefix . 'kiwi_retention_table_growth_snapshots', $wpdb->prefix . 'kiwi_operational_events'] as $table) {
                $rows = Kiwi_Retention_Recovery_SQL::rows($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $table));
                if (count($rows) !== 1 || strtoupper((string) $rows[0]['Engine']) !== 'INNODB') {
                    throw new RuntimeException('recovery_transactional_schema_required');
                }
            }
            $snapshot = $this->temporary_table($handoffs);
            $candidate_main = $this->temporary_table($main);
            $candidate_tk = $this->temporary_table($tk);
            Kiwi_Retention_Recovery_SQL::execute('SET TRANSACTION ISOLATION LEVEL ' . ($apply ? 'SERIALIZABLE' : 'REPEATABLE READ'));
            Kiwi_Retention_Recovery_SQL::execute($apply ? 'START TRANSACTION' : 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            $this->transaction = true;
            // wpdb must not silently reconnect and retry a write outside this transaction.
            if (isset($wpdb->reconnect_retries)) {
                $this->reconnect_retries = $wpdb->reconnect_retries;
                $wpdb->reconnect_retries = 0;
            }
            $open = (new Kiwi_Retention_Cleanup_Run_Repository())->find_open_run_for_source('landing_page_sessions');
            if ($open) { throw new RuntimeException('session_cleanup_run_already_open'); }
            $earliest = Kiwi_Retention_Recovery_SQL::rows($wpdb->prepare("SELECT MIN(created_at) AS earliest FROM $session_table WHERE created_at < %s", $cutoff));
            if (empty($earliest[0]['earliest'])) { throw new RuntimeException('recovery_raw_scope_empty'); }
            $first = substr($earliest[0]['earliest'], 0, 10) . ' 00:00:00';
            $handoff_to = gmdate('Y-m-d', strtotime(substr($cutoff, 0, 10) . ' +1 day')) . ' 00:00:00';
            $snapshot_evidence = $this->reader->snapshot($first, $handoff_to, $snapshot);
            foreach ([$main => $candidate_main, $tk => $candidate_tk] as $original => $candidate) {
                Kiwi_Retention_Recovery_SQL::execute($wpdb->prepare(
                    "INSERT INTO $candidate SELECT * FROM $original WHERE metric_date >= %s AND metric_date < %s",
                    substr($first, 0, 10), substr($cutoff, 0, 10)
                ));
                Kiwi_Retention_Recovery_SQL::execute($wpdb->prepare("DELETE FROM $candidate WHERE metric_date BETWEEN %s AND %s", $from, $to));
            }
            $context = new Kiwi_Landing_Funnel_Read_Context(['handoffs' => $snapshot,
                'main_summary' => $candidate_main, 'tkzone_summary' => $candidate_tk], [], $dates);
            $main_service = new Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service(new Kiwi_Recovery_Main_Summary_Repository($candidate_main), $context);
            $tk_service = new Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Aggregation_Service(new Kiwi_Recovery_Tkzone_Summary_Repository($candidate_tk, $this->config), $this->config, $context);
            foreach ($dates as $date) {
                $start = $date . ' 00:00:00';
                $end = gmdate('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00';
                $late_end = gmdate('Y-m-d', strtotime($date . ' +2 days')) . ' 00:00:00';
                Kiwi_Retention_Recovery_SQL::execute($wpdb->prepare($main_service->build_refresh_insert_sql(),
                    [$start, $end, $start, $end, $start, $late_end, $date, $date, $date]));
                $pids = $this->config->get_landing_funnel_tkzone_summary_pids();
                if ($pids) {
                    Kiwi_Retention_Recovery_SQL::execute($wpdb->prepare($tk_service->build_refresh_insert_sql($pids),
                        $tk_service->build_refresh_insert_params($date, $start, $end, $late_end, $pids)));
                }
            }
            $gate = (new Kiwi_Retention_Coverage_Gate($this->config, $context))->check_landing_page_sessions($source, $cutoff);
            $changes = [];
            $blockers = [];
            $daily = [];
            foreach (['main' => [$main, $candidate_main], 'tkzone' => [$tk, $candidate_tk]] as $kind => $pair) {
                $before = $this->summary_rows($pair[0], $from, $to);
                $after = $this->summary_rows($pair[1], $from, $to);
                $comparison = $this->compare($kind, $before, $after);
                $changes = array_merge($changes, $comparison['changes']);
                $blockers = array_merge($blockers, $comparison['blockers']);
                $daily[$kind] = ['before' => $this->daily($before), 'candidate' => $this->daily($after)];
            }
            if ($gate['status'] !== 'passed' || array_diff($dates, $gate['deep_checked_dates'])) { $blockers[] = 'coverage_gate_not_fully_passed'; }
            if (empty($settings['enabled']) || !empty($settings['dry_run'])) { $blockers[] = 'retention_settings_not_enabled_for_execution'; }
            $input = $this->input_evidence($first, $handoff_to, $cutoff, $from, $to, $settings, $snapshot_evidence);
            $token = Kiwi_Retention_Recovery_SQL::digest(['input' => $input, 'changes' => $changes, 'daily' => $daily]);
            $report = array_merge($report, ['success' => true, 'ready_to_apply' => !$blockers,
                'from_date' => $from, 'to_date' => $to, 'cutoff' => $cutoff, 'preview_sha256' => $token,
                'input_evidence' => $input, 'daily_values' => $daily, 'changes' => $changes,
                'blockers' => $blockers, 'gate' => $gate,
                'metric_changed_dates' => array_values(array_unique(array_column($changes, 'metric_date')))]);
            if (!$apply) { return $report; }
            if (!hash_equals($expected, $token)) { throw new RuntimeException('reviewed_preview_changed'); }
            if ($blockers) { throw new RuntimeException('recovery_preview_blocked'); }
            call_user_func($this->journal, ['phase' => 'prepared', 'preview' => $report]);
            $this->reader->assert_unchanged();
            foreach ([$main => $candidate_main, $tk => $candidate_tk] as $original => $candidate) {
                $assignments = [];
                $differences = [];
                foreach (self::CTA as $column) {
                    $assignments[] = "o.$column = c.$column";
                    $differences[] = "o.$column <> c.$column";
                }
                $assignments[] = 'o.updated_at = NOW()';
                Kiwi_Retention_Recovery_SQL::execute($wpdb->prepare(
                    "UPDATE $original o INNER JOIN $candidate c ON c.metric_date = o.metric_date AND c.dimension_hash = o.dimension_hash
                     SET " . implode(', ', $assignments) . " WHERE o.metric_date BETWEEN %s AND %s AND (" . implode(' OR ', $differences) . ')',
                    $from, $to
                ));
            }
            $audit = ['kind' => 'landing_sessions_recovery_v1', 'preview_sha256' => $token,
                'from_date' => $from, 'to_date' => $to, 'cutoff' => $cutoff,
                'eligible_ids_sha256' => $input['eligible_ids_sha256'], 'code_sha256' => $input['code_sha256']];
            $published_context = new Kiwi_Landing_Funnel_Read_Context(['handoffs' => $snapshot], $audit, $dates);
            $published_gate = new Kiwi_Retention_Coverage_Gate($this->config, $published_context);
            $checked = $published_gate->check_landing_page_sessions($source, $cutoff);
            if ($checked['status'] !== 'passed') { throw new RuntimeException('published_gate_failed'); }
            $start = (new Kiwi_Retention_Cleanup_Service($this->config, null, null, null, null, $published_gate))->run_source('landing_page_sessions', 'manual');
            if (empty($start['success']) || !in_array($start['status'], ['pending', 'completed'], true)
                || ($start['cutoff_value'] ?? '') !== $cutoff || ($start['gate_status'] ?? '') !== 'passed') {
                throw new RuntimeException('manual_retention_start_failed');
            }
            $this->reader->assert_unchanged();
            $report['run_id'] = $start['run_id'];
            call_user_func($this->journal, ['phase' => 'commit_requested', 'run_id' => $start['run_id'], 'preview_sha256' => $token]);
            $commit_requested = true;
            Kiwi_Retention_Recovery_SQL::execute('COMMIT');
            $this->transaction = false;
            $committed = true;
            $report['summary_published'] = true;
            $report['commit_outcome'] = 'confirmed';
            $report['run_id'] = $start['run_id'];
            $report['status'] = $start['status'];
            call_user_func($this->journal, ['phase' => 'published_and_started', 'run_id' => $start['run_id'], 'preview_sha256' => $token]);
            return $report;
        } catch (Throwable $error) {
            return array_merge($report, $this->failure($this->safe_error($error)), [
                'summary_published' => $committed ? true : ($commit_requested ? null : false),
                'commit_outcome' => $committed ? 'confirmed' : ($commit_requested ? 'unknown' : 'not_committed'),
            ]);
        } finally {
            if ($this->transaction) { $wpdb->query('ROLLBACK'); $this->transaction = false; }
            if ($this->reconnect_retries !== null) {
                $wpdb->reconnect_retries = $this->reconnect_retries;
                $this->reconnect_retries = null;
            }
            foreach ($this->temporary as $table) { $wpdb->query("DROP TEMPORARY TABLE IF EXISTS $table"); }
            $this->temporary = [];
            $this->reader->release();
            $this->release_named_lock();
            $this->restore_lock_waits();
        }
    }

    private function input_evidence(string $first, string $handoff_to, string $cutoff, string $from, string $to, array $settings, array $handoffs): array
    {
        global $wpdb;
        $conditions = [
            $wpdb->prefix . 'kiwi_landing_page_sessions' => $wpdb->prepare('created_at < %s', $cutoff),
            Kiwi_Database_Table_Names::landing_session_engagements() => $wpdb->prepare('created_at >= %s AND created_at < %s', $first, $cutoff),
            $wpdb->prefix . 'kiwi_sales' => $wpdb->prepare('attribution_metric_date >= %s AND attribution_metric_date < %s', substr($first, 0, 10), substr($cutoff, 0, 10)),
            $wpdb->prefix . 'kiwi_landing_funnel_daily_summary' => $wpdb->prepare('metric_date >= %s AND metric_date < %s', substr($first, 0, 10), substr($cutoff, 0, 10)),
            $wpdb->prefix . 'kiwi_landing_funnel_daily_tkzone_summary' => $wpdb->prepare('metric_date >= %s AND metric_date < %s', substr($first, 0, 10), substr($cutoff, 0, 10)),
        ];
        $tables = [];
        foreach ($conditions as $table => $where) {
            $tables[$table] = $this->table_digest($table, $where);
        }
        $ids = array_map('intval', array_column(Kiwi_Retention_Recovery_SQL::rows($wpdb->prepare(
            'SELECT id FROM ' . $wpdb->prefix . 'kiwi_landing_page_sessions WHERE created_at < %s ORDER BY id', $cutoff
        )), 'id'));
        $files = $this->runtime_files();
        $identity = Kiwi_Retention_Recovery_SQL::rows('SELECT DATABASE() AS database_name');
        return ['from_date' => $from, 'to_date' => $to, 'cutoff' => $cutoff, 'database_prefix' => $wpdb->prefix,
            'database_sha256' => Kiwi_Retention_Recovery_SQL::digest($identity),
            'settings' => $settings, 'tkzone_pids' => $this->config->get_landing_funnel_tkzone_summary_pids(),
            'worker_limits' => ['rows' => $this->config->get_retention_worker_row_limit(),
                'seconds' => $this->config->get_retention_worker_time_limit_seconds(),
                'batch' => $this->config->get_retention_default_batch_limit()],
            'source' => (new Kiwi_Retention_Source_Registry())->get('landing_page_sessions'),
            'tables' => $tables, 'handoffs' => $handoffs, 'eligible_rows' => count($ids),
            'eligible_ids_sha256' => Kiwi_Retention_Recovery_SQL::digest($ids),
            'runtime_files' => $files, 'code_sha256' => Kiwi_Retention_Recovery_SQL::digest($files)];
    }

    private function runtime_files(): array
    {
        $classes = [Kiwi_Config::class, Kiwi_Database_Table_Names::class, Kiwi_Landing_Funnel_Read_Context::class,
            Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service::class, Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Aggregation_Service::class,
            Kiwi_Retention_Coverage_Gate::class, Kiwi_Retention_Source_Registry::class, Kiwi_Retention_Cleanup_Service::class,
            Kiwi_Retention_Sqlite_Archive_Service::class, Kiwi_Retention_Archive_Lock::class, Kiwi_Retention_Archive_Check_Supervisor::class,
            Kiwi_Retention_Corruption_Safety_Gate_Coordinator::class, Kiwi_Retention_Cleanup_Run_Repository::class,
            Kiwi_Retention_Table_Growth_Snapshot_Repository::class, Kiwi_Operational_Event_Service::class,
            Kiwi_Operational_Event_Repository::class, Kiwi_Retention_Archive_Name::class, Kiwi_Retention_Archive_Write_Block::class,
            Kiwi_Landing_Funnel_Daily_Summary_Repository::class, Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository::class,
            self::class, Kiwi_Retention_Recovery_Handoff_Reader::class, Kiwi_Retention_Recovery_SQL::class];
        $files = [];
        foreach ($classes as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            $files[basename($file)] = hash_file('sha256', $file);
        }
        $files['kiwi-retention-archive-health.php'] = hash_file('sha256', __DIR__ . '/kiwi-retention-archive-health.php');
        $files['kiwi-retention-sessions-recovery.php'] = hash_file('sha256', __DIR__ . '/kiwi-retention-sessions-recovery.php');
        ksort($files);
        return $files;
    }

    private function table_digest(string $table, string $where): array
    {
        $hash = hash_init('sha256');
        $last = 0;
        $count = 0;
        do {
            $rows = Kiwi_Retention_Recovery_SQL::rows("SELECT * FROM $table WHERE ($where) AND id > $last ORDER BY id LIMIT 1000");
            foreach ($rows as $row) { hash_update($hash, Kiwi_Retention_Recovery_SQL::digest($row)); $last = (int) $row['id']; $count++; }
        } while (count($rows) === 1000);
        return ['rows' => $count, 'sha256' => hash_final($hash)];
    }

    private function compare(string $kind, array $before, array $after): array
    {
        $old = [];
        $new = [];
        foreach ($before as $row) { $old[$row['metric_date'] . '/' . $row['dimension_hash']] = $row; }
        foreach ($after as $row) { $new[$row['metric_date'] . '/' . $row['dimension_hash']] = $row; }
        if (array_keys($old) !== array_keys($new)) { return ['changes' => [], 'blockers' => [$kind . '_dimensions_changed']]; }
        $changes = [];
        $blockers = [];
        foreach ($old as $key => $row) {
            foreach ($row as $column => $value) {
                if (in_array($column, ['id', 'created_at', 'updated_at'], true) || $value === $new[$key][$column]) { continue; }
                if (!in_array($column, self::CTA, true)) { $blockers[] = $kind . '/' . $key . '/' . $column . '_changed'; continue; }
                $changes[] = ['summary' => $kind, 'metric_date' => $row['metric_date'], 'dimension_hash' => $row['dimension_hash'],
                    'metric' => $column, 'before' => (int) $value, 'after' => (int) $new[$key][$column]];
            }
        }
        return ['changes' => $changes, 'blockers' => $blockers];
    }

    private function daily(array $rows): array
    {
        $daily = [];
        foreach ($rows as $row) {
            $date = $row['metric_date'];
            foreach (array_merge(self::CTA, ['sessions', 'page_loaded_sessions', 'handoff_attempts', 'handoff_successes', 'handoff_fails', 'sales', 'sales_amount_minor']) as $metric) {
                $daily[$date][$metric] = ($daily[$date][$metric] ?? 0) + (int) $row[$metric];
            }
        }
        return $daily;
    }

    private function summary_rows(string $table, string $from, string $to): array
    {
        global $wpdb;
        return Kiwi_Retention_Recovery_SQL::rows($wpdb->prepare("SELECT * FROM $table WHERE metric_date BETWEEN %s AND %s ORDER BY metric_date, dimension_hash", $from, $to));
    }

    private function temporary_table(string $source): string
    {
        $table = 'kiwi_recovery_' . bin2hex(random_bytes(10));
        Kiwi_Retention_Recovery_SQL::execute("CREATE TEMPORARY TABLE $table LIKE $source");
        $this->temporary[] = $table;
        return $table;
    }

    private function dates(string $from, string $to): array
    {
        foreach ([$from, $to] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) { throw new RuntimeException('recovery_dates_invalid'); }
        }
        $days = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to));
        if ($days->invert || $days->days > 30) { throw new RuntimeException('recovery_range_invalid'); }
        $dates = [];
        for ($date = new DateTimeImmutable($from); $date->format('Y-m-d') <= $to; $date = $date->modify('+1 day')) { $dates[] = $date->format('Y-m-d'); }
        return $dates;
    }

    private function recovery_run(string $run_id, string $expected): array
    {
        global $wpdb;
        $table = (new Kiwi_Retention_Cleanup_Run_Repository())->get_table_name();
        $rows = Kiwi_Retention_Recovery_SQL::rows($wpdb->prepare("SELECT * FROM $table WHERE run_id = %s", $run_id));
        $run = $rows[0] ?? null;
        $gate = $run ? json_decode((string) $run['gate_results_json'], true) : [];
        if (!$run || count($rows) !== 1 || $run['source_key'] !== 'landing_page_sessions'
            || $run['triggered_by'] !== 'manual' || $run['gate_status'] !== 'passed'
            || ($gate['recovery']['kind'] ?? '') !== 'landing_sessions_recovery_v1'
            || ($gate['recovery']['preview_sha256'] ?? '') !== $expected
            || ($gate['recovery']['cutoff'] ?? '') !== $run['cutoff_value']
            || ($gate['recovery']['code_sha256'] ?? '') !== Kiwi_Retention_Recovery_SQL::digest($this->runtime_files())
            || !in_array($run['status'], ['pending', 'partial', 'completed'], true)) {
            throw new RuntimeException('recovery_run_identity_mismatch');
        }
        return $run;
    }

    private function verify_completion(array $run): array
    {
        global $wpdb;
        $lock_service = new Kiwi_Retention_Archive_Lock();
        $path = (new Kiwi_Retention_Sqlite_Archive_Service($this->config))->resolve_existing_archive_db_path_read_only($run['archive_db_path']);
        $lock = $lock_service->acquire_for_archive($path);
        if (empty($lock['acquired'])) { throw new RuntimeException('archive_lock_active'); }
        try {
            $pdo = Kiwi_Retention_Recovery_SQL::open_archive($path);
            $source_table = Kiwi_Retention_Recovery_SQL::identifier($run['source_table']);
            $batch = $pdo->prepare('SELECT * FROM archive_batches WHERE archive_batch_id = ?');
            $batch->execute([$run['archive_batch_id']]);
            $batch = $batch->fetch(PDO::FETCH_ASSOC);
            if (!$batch || $batch['source_key'] !== 'landing_page_sessions' || $batch['source_table'] !== $source_table
                || $batch['cutoff_value'] !== $run['cutoff_value'] || $batch['status'] !== 'success'
                || (int) $batch['archived_rows'] !== (int) $run['archived_rows']) {
                throw new RuntimeException('completion_batch_invalid');
            }
            $query = $pdo->prepare("SELECT r.source_pk, a.id, a.created_at FROM archive_batch_rows r
                LEFT JOIN \"$source_table\" a ON a._source_pk = r.source_pk WHERE r.archive_batch_id = ? ORDER BY r.source_pk");
            $query->execute([$run['archive_batch_id']]);
            $ids = [];
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                if ((int) $row['source_pk'] !== (int) $row['id'] || $row['created_at'] >= $run['cutoff_value']
                    || (int) $row['id'] > (int) $run['target_max_primary_key']) { throw new RuntimeException('completion_receipt_invalid'); }
                $ids[] = (int) $row['source_pk'];
            }
            $gate = json_decode($run['gate_results_json'], true);
            $remaining = Kiwi_Retention_Recovery_SQL::rows($wpdb->prepare("SELECT COUNT(*) AS remaining FROM $source_table WHERE created_at < %s AND id <= %d", $run['cutoff_value'], $run['target_max_primary_key']));
            if (count($ids) !== (int) $run['eligible_rows'] || count($ids) !== (int) $run['archived_rows']
                || count($ids) !== (int) $run['deleted_rows'] || (int) $remaining[0]['remaining'] !== 0
                || (int) $run['archive_last_primary_key'] !== (int) $run['delete_last_primary_key']
                || !$ids || max($ids) !== (int) $run['archive_last_primary_key']
                || Kiwi_Retention_Recovery_SQL::digest($ids) !== $gate['recovery']['eligible_ids_sha256']) {
                throw new RuntimeException('completion_evidence_mismatch');
            }
            return ['receipt_verified' => true, 'exact_source_ids_verified' => true, 'remaining_frozen_scope_rows' => 0];
        } finally { $lock_service->release($lock['handle']); }
    }

    private function acquire_named_lock(): void
    {
        global $wpdb;
        $name = 'kiwi_sessions_recovery_' . substr(hash('sha256', (string) $wpdb->prefix), 0, 32);
        $rows = Kiwi_Retention_Recovery_SQL::rows($wpdb->prepare('SELECT GET_LOCK(%s, 0) AS acquired', $name));
        if ((int) $rows[0]['acquired'] !== 1) { throw new RuntimeException('recovery_lock_active'); }
        $this->named_lock = $name;
    }

    private function release_named_lock(): void
    {
        global $wpdb;
        if ($this->named_lock) { $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->named_lock)); $this->named_lock = ''; }
    }

    private function bound_lock_waits(): void
    {
        $rows = Kiwi_Retention_Recovery_SQL::rows('SELECT @@SESSION.innodb_lock_wait_timeout AS innodb, @@SESSION.lock_wait_timeout AS metadata_wait');
        $this->timeouts = $rows[0];
        Kiwi_Retention_Recovery_SQL::execute('SET SESSION innodb_lock_wait_timeout = 5');
        Kiwi_Retention_Recovery_SQL::execute('SET SESSION lock_wait_timeout = 5');
    }

    private function restore_lock_waits(): void
    {
        global $wpdb;
        if ($this->timeouts) {
            $wpdb->query('SET SESSION innodb_lock_wait_timeout = ' . (int) $this->timeouts['innodb']);
            $wpdb->query('SET SESSION lock_wait_timeout = ' . (int) $this->timeouts['metadata_wait']);
            $this->timeouts = [];
        }
    }

    private function safe_error(Throwable $error): string
    {
        return preg_match('/^[a-z][a-z0-9_]+$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'recovery_operation_failed';
    }

    private function failure(string $code, array $extra = []): array
    {
        return array_merge(['success' => false, 'error_code' => $code], $extra);
    }
}
