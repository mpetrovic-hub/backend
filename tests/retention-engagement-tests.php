<?php

// Standalone real-engine checks. Credentials stay in a private local file.
// PHPRC must enable PDO mysql/sqlite and be inherited by the child processes.
define('ABSPATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
$GLOBALS['engagement_test_now'] = '2026-10-12 12:00:00';
$GLOBALS['engagement_test_transients'] = [];
function current_time($type, $gmt = false) { return $GLOBALS['engagement_test_now']; }
function get_option($key, $default = false) { return $default; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function get_transient($key) { return $GLOBALS['engagement_test_transients'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['engagement_test_transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['engagement_test_transients'][$key]); return true; }
function dbDelta($sql) { global $wpdb; if ($wpdb->query($sql) === false) { throw new RuntimeException('fixture_schema_failed'); } }

foreach (['core/class-config.php', 'core/class-database-table-names.php',
    'repositories/interface-statistics-read-repository.php',
    'repositories/class-landing-session-engagement-repository.php', 'repositories/class-landing-page-session-repository.php',
    'repositories/class-landing-funnel-daily-summary-repository.php', 'repositories/class-landing-funnel-daily-tkzone-summary-repository.php',
    'repositories/class-retention-cleanup-run-repository.php', 'repositories/class-retention-table-growth-snapshot-repository.php',
    'services/class-landing-session-engagement-evaluator-interface.php', 'services/class-retention-source-registry.php',
    'services/class-retention-archive-name.php', 'services/class-retention-archive-write-block.php',
    'services/class-retention-archive-lock.php', 'services/class-retention-sqlite-archive-service.php',
    'services/class-retention-coverage-gate.php', 'services/class-operational-event-service.php',
    'services/class-retention-corruption-safety-gate-coordinator.php', 'services/class-retention-cleanup-service.php',
    'services/class-retention-archive-reader.php', 'services/class-retention-engagement-worker.php'] as $file) {
    require_once ABSPATH . 'includes/' . $file;
}
require_once ABSPATH . 'tools/database/migrations/class-retention-archive-read-indexes-migration-service.php';

final class Engagement_Test_Wpdb
{
    public $prefix = 't72_';
    public $last_error = '';
    public $insert_id = 0;
    public $pdo;
    public $fail_audit_once = false;
    public $queries = [];
    public $maximum_limit = 0;
    public $source_page_delay_us = 0;
    public $last_failure_code = 0;
    public $fail_summary_reads = false;
    public $fail_after_reads = false;
    public $fail_open_audit_reads = 0;
    public $fail_run_audit_reads = 0;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }
    public function get_charset_collate() { return 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci'; }
    public function prepare(string $sql, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback('/%([sd])/', function ($match) use (&$i, $args) {
            $value = $args[$i++];
            return $match[1] === 'd' ? (string) (int) $value : $this->pdo->quote((string) $value);
        }, $sql);
    }
    public function query(string $sql)
    {
        $this->last_error = '';
        try {
            if ($this->fail_audit_once && preg_match('/^UPDATE t72_kiwi_retention_cleanup_runs/', $sql)) {
                $this->fail_audit_once = false; throw new RuntimeException('fixture_audit_failure');
            }
            $result = $this->pdo->exec($sql);
            $this->insert_id = (int) $this->pdo->lastInsertId();
            return $result;
        } catch (Throwable $error) { $this->last_failure_code = (int) ($error instanceof PDOException ? ($error->errorInfo[1] ?? 0) : 0); $this->last_error = 'controlled_fixture_query_failure'; return false; }
    }
    public function get_results(string $sql, $output = ARRAY_A): array
    {
        if (preg_match('/LIMIT (\d+)\s*$/i', $sql, $match)) { $this->maximum_limit = max($this->maximum_limit, (int) $match[1]); }
        if ($this->source_page_delay_us > 0 && strpos($sql, 'SELECT e.*') === 0) { usleep($this->source_page_delay_us); }
        $this->last_error = '';
        if (preg_match('/^SELECT\s+\*\s+FROM\s+t72_kiwi_retention_cleanup_runs\s+WHERE/i', $sql)) {
            $failure = strpos($sql, 'source_key =') !== false ? 'fail_open_audit_reads' : 'fail_run_audit_reads';
            if ($this->$failure > 0) { $this->$failure--; $this->last_error = 'controlled_audit_read_failure'; return []; }
        }
        if ($this->fail_after_reads && strpos($sql, 'SELECT id FROM t72_kiwi_landing_session_engagements WHERE id>') === 0) {
            $this->last_error = 'controlled_after_read_failure'; return [];
        }
        if ($this->fail_summary_reads && strpos($sql, 'SELECT id,page_loaded_sessions') === 0) {
            $this->last_error = 'controlled_fixture_read_failure'; return [];
        }
        try { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $error) { $this->last_error = 'controlled_fixture_read_failure'; return []; }
    }
    public function get_row(string $sql, $output = ARRAY_A): ?array { $rows = $this->get_results($sql); return $rows[0] ?? null; }
    public function get_var(string $sql) { $row = $this->get_row($sql); return $row === null ? null : reset($row); }
    public function get_col(string $sql): array { return array_map(static function ($row) { return reset($row); }, $this->get_results($sql)); }
    public function insert(string $table, array $row, array $formats = []): bool
    {
        $values = array_map(fn($v) => $v === null ? 'NULL' : $this->pdo->quote((string) $v), array_values($row));
        return $this->query('INSERT INTO ' . $table . ' (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', $values) . ')') !== false;
    }
    public function update(string $table, array $row, array $where, array $formats = [], array $where_formats = [])
    {
        $set = []; $clauses = [];
        foreach ($row as $name => $value) { $set[] = '`' . $name . '`=' . ($value === null ? 'NULL' : $this->pdo->quote((string) $value)); }
        foreach ($where as $name => $value) { $clauses[] = '`' . $name . '`' . ($value === null ? ' IS NULL' : '=' . $this->pdo->quote((string) $value)); }
        return $this->query('UPDATE ' . $table . ' SET ' . implode(',', $set) . ' WHERE ' . implode(' AND ', $clauses));
    }
}

final class Engagement_Test_Config extends Kiwi_Config
{
    public $root;
    public $enabled = true;
    public $dry_run = true;
    public $row_limit = 50000;
    public $time_limit = 60;
    public $pids = null;
    public function get_retention_archive_root(): string { return $this->root; }
    public function get_retention_source_settings(string $key): array { return ['enabled' => $this->enabled, 'dry_run' => $this->dry_run, 'retention_days' => 14]; }
    public function get_retention_worker_row_limit(): int { return $this->row_limit; }
    public function get_retention_worker_time_limit_seconds(): int { return $this->time_limit; }
    public function get_landing_funnel_tkzone_summary_pids(): array { return $this->pids ?? parent::get_landing_funnel_tkzone_summary_pids(); }
}
final class Engagement_Test_Events extends Kiwi_Operational_Event_Service
{
    public $incidents = [];
    public function __construct() {}
    public function get_open_incidents(array $filters = [], int $limit = 100): ?array { return $this->incidents; }
}
final class Engagement_Test_Evaluator implements Kiwi_Landing_Session_Engagement_Evaluator_Interface
{
    public function evaluate(array $row): array { return ['is_soft_flag' => 0]; }
}
class Engagement_Test_Archive extends Kiwi_Retention_Sqlite_Archive_Service
{
    public $before_commit;
    public $after_commit;
    public function archive_snapshot_batch(array $source, array $rows, string $cutoff, string $batch, string $path): array
    {
        if ($this->before_commit !== null) { ($this->before_commit)(); }
        $result = parent::archive_snapshot_batch($source, $rows, $cutoff, $batch, $path);
        if ($this->after_commit !== null) { ($this->after_commit)($result); }
        return $result;
    }
}
final class Engagement_Test_Worker extends Kiwi_Retention_Engagement_Worker
{
    public function cutoff(int $days): string { return $this->build_cutoff_value($days); }
}
final class Engagement_Test_Run_Repository extends Kiwi_Retention_Cleanup_Run_Repository
{
    public $after_checkpoint_write;
    public $before_delete_audit;
    public $before_open_return;
    public function find_open_run_for_source(string $key): ?array
    {
        $run = parent::find_open_run_for_source($key);
        if ($this->before_open_return !== null) {
            $callback = $this->before_open_return; $this->before_open_return = null; $callback();
        }
        return $run;
    }
    public function update_run_checkpoint(int $id, array $data, array $expected): bool
    {
        if ($this->before_delete_audit !== null && (int) ($data['delete_last_primary_key'] ?? 0) > 0) {
            $callback = $this->before_delete_audit; $this->before_delete_audit = null; $callback();
        }
        $result = parent::update_run_checkpoint($id, $data, $expected);
        if ($result && $this->after_checkpoint_write !== null) {
            $callback = $this->after_checkpoint_write; $this->after_checkpoint_write = null;
            $callback($id, $data);
        }
        return $result;
    }
}

function et_check($condition, string $label): void
{
    if (!$condition) { throw new RuntimeException('FAILED: ' . $label); }
    $GLOBALS['et_checks'] = ($GLOBALS['et_checks'] ?? 0) + 1;
    echo '[PASS] ', $label, "\n";
}
function et_connection(array $settings, string $database = ''): PDO
{
    $pdo = new PDO($settings['dsn'] . ($database === '' ? '' : ';dbname=' . $database), $settings['username'], $settings['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
    return $pdo;
}
function et_engagement(int $id, string $created = '2026-07-06 12:00:00', array $changes = []): array
{
    return array_merge(['id' => $id, 'created_at' => $created, 'updated_at' => '2026-09-01 12:00:00',
        'last_event_at' => $created, 'landing_key' => 'fixture-landing', 'session_token' => 'fixture-' . $id,
        'page_loaded_at' => $created, 'first_cta1_click_at' => $created, 'cta1_click_count' => 2], $changes);
}
function et_session(int $id, int $engagement_id, string $date = '2026-07-06 12:00:00', array $changes = []): array
{
    return array_merge(['id' => $id, 'created_at' => $date, 'landing_key' => 'fixture-landing',
        'session_token' => 'fixture-' . $engagement_id, 'pid' => '106'], $changes);
}
function et_fixture(Engagement_Test_Config $config): array
{
    global $wpdb;
    $GLOBALS['engagement_test_transients'] = [];
    foreach (['kiwi_landing_session_engagements', 'kiwi_landing_page_sessions', 'kiwi_retention_cleanup_runs',
        'kiwi_landing_funnel_daily_summary', 'kiwi_landing_funnel_daily_tkzone_summary'] as $name) { $wpdb->pdo->exec('DROP TABLE IF EXISTS t72_' . $name); }
    (new Kiwi_Landing_Session_Engagement_Repository())->create_table();
    (new Kiwi_Landing_Page_Session_Repository())->create_table();
    (new Kiwi_Retention_Cleanup_Run_Repository())->create_table();
    (new Kiwi_Landing_Funnel_Daily_Summary_Repository())->create_table();
    (new Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository($config))->create_table();
    $config->root = sys_get_temp_dir() . '/codex-retention-72-' . bin2hex(random_bytes(8));
    mkdir($config->root . '/sqlite', 0700, true);
    $events = new Engagement_Test_Events();
    $lock = new Kiwi_Retention_Archive_Lock();
    $repo = new Engagement_Test_Run_Repository();
    $gate = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator($lock, $events, $repo);
    $archive = new Engagement_Test_Archive($config);
    $registry = new Kiwi_Retention_Source_Registry();
    $worker = new Engagement_Test_Worker($config, $registry, $repo, null, $archive, null, $events, $lock, $gate);
    return compact('events', 'lock', 'repo', 'gate', 'archive', 'registry', 'worker', 'config');
}
function et_spawn_writer(string $database): array
{
    $environment = getenv(); $environment['KIWI_RETENTION_TEST_DATABASE'] = $database;
    $process = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, '--writer'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) { throw new RuntimeException('fixture_writer_spawn_failed'); }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    return ['process' => $process, 'out' => $pipes[1], 'err' => $pipes[2]];
}
function et_wait_lock_waits(PDO $pdo, int $expected): bool
{
    $deadline = microtime(true) + 4;
    do {
        $waits = $pdo->query("SHOW GLOBAL STATUS LIKE 'Innodb_row_lock_current_waits'")->fetch(PDO::FETCH_ASSOC);
        if ((int) ($waits['Value'] ?? 0) >= $expected) { return true; }
        usleep(20000);
    } while (microtime(true) < $deadline);
    return false;
}
function et_join_writer(array $child): void
{
    stream_set_blocking($child['out'], true); stream_set_blocking($child['err'], true);
    $output = stream_get_contents($child['out']); $error = stream_get_contents($child['err']);
    fclose($child['out']); fclose($child['err']);
    $exit = proc_close($child['process']);
    et_check($exit === 0 && strpos($output, 'writer_complete') !== false && $error === '', 'Real concurrent writer finishes without errors');
}
function et_full_row(array $source, array $row): array
{
    global $wpdb;
    $wpdb->insert($source['source_table'], $row);
    $result = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $source['source_table'] . ' WHERE id=%d', $row['id']));
    if ($result === null) { throw new RuntimeException('fixture_insert_failed'); }
    return $result;
}
function et_summary(Engagement_Test_Config $config, string $date, int $pages, int $clicks, int $cta_sessions = -1): void
{
    global $wpdb;
    foreach (['main', 'tkzone'] as $mode) {
        $row = ['metric_date' => $date, 'dimension_hash' => hash('sha256', $mode . $date),
            'created_at' => '2026-10-12 12:00:00', 'updated_at' => '2026-10-12 12:00:00',
            'page_loaded_sessions' => $pages, 'cta1_sessions' => $cta_sessions < 0 ? $pages : $cta_sessions,
            'cta1_click_events' => $clicks];
        if ($mode === 'tkzone') { $row['pid_set_hash'] = $config->get_landing_funnel_tkzone_summary_pid_set_hash(); }
        if (!$wpdb->insert('t72_kiwi_landing_funnel_daily_' . ($mode === 'main' ? '' : 'tkzone_') . 'summary', $row)) {
            throw new RuntimeException('fixture_summary_insert_failed');
        }
    }
}
function et_archive_sessions(array $fixture, array $rows): string
{
    global $wpdb;
    $source = $fixture['registry']->get('landing_page_sessions');
    foreach ($rows as $row) { et_full_row($source, $row); }
    $path = $fixture['archive']->resolve_archive_db_path();
    $result = $fixture['archive']->archive_primary_key_chunk($source, '2026-09-28 00:00:00', 'fixture-sessions-' . bin2hex(random_bytes(5)), 0, max(array_column($rows, 'id')), 500, 60, $path);
    if (empty($result['success'])) { throw new RuntimeException('fixture_session_archive_failed'); }
    $wpdb->pdo->exec('DELETE FROM ' . $source['source_table']);
    return $path;
}
function et_finish(array $fixture, int $calls = 200): array
{
    $fixture['worker']->run_source('landing_session_engagements', 'manual');
    for ($i = 0; $i < $calls; $i++) {
        $fixture['worker']->run_worker('landing_session_engagements');
        $open = $fixture['repo']->find_open_run_for_source('landing_session_engagements');
        if ($open === null) {
            global $wpdb;
            return $wpdb->get_row('SELECT * FROM t72_kiwi_retention_cleanup_runs ORDER BY id DESC LIMIT 1');
        }
    }
    throw new RuntimeException('fixture_worker_did_not_finish');
}
function et_state(array $run): array { return json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR); }

function et_recovery_scenarios(PDO $other): void
{
    global $wpdb;
    foreach (['same', 'year', 'part'] as $generation) {
        $GLOBALS['engagement_test_now'] = '2026-10-12 12:00:00';
        $config = new Engagement_Test_Config(); $config->dry_run = false;
        $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
        et_full_row($source, et_engagement(1, '2026-04-23 12:00:00', ['user_agent' => 'Before']));
        $fixture['archive']->after_commit = function () use ($other, $source) {
            $other->exec('UPDATE ' . $source['source_table'] . " SET user_agent='After' WHERE id=1");
        };
        $first = et_finish($fixture); $fixture['archive']->after_commit = null;
        $old_path = $first['archive_db_path']; $old_hash = hash_file('sha256', $old_path);
        $expected = $wpdb->get_row('SELECT * FROM ' . $source['source_table'] . ' WHERE id=1');
        if ($generation === 'year') { $GLOBALS['engagement_test_now'] = '2027-01-12 12:00:00'; }
        if ($generation === 'part') {
            copy($old_path, dirname($old_path) . '/kiwi_retention_archive_2026_part_2.sqlite');
        }
        $second = et_finish($fixture);
        $reader = new Kiwi_Retention_Archive_Reader($fixture['archive'], $fixture['lock'], $fixture['gate']);
        $copies = $reader->copies($source, 1);
        $chosen = $copies[array_key_last($copies)]; $reader->close();
        et_check((int) $first['deleted_rows'] === 0 && (int) $second['deleted_rows'] === 1
            && Kiwi_Retention_Archive_Reader::snapshot($source, $chosen) === Kiwi_Retention_Archive_Reader::snapshot($source, $expected),
            'Mutable renewal chooses a verified complete current snapshot across ' . $generation . ' generation');
        et_check(array_key_last($copies) === $second['archive_db_path'] && count($copies) === ($generation === 'same' ? 1 : 2),
            'Source-ID versions are ranked by actual archive year and numeric generation: ' . $generation);
        if ($generation !== 'same') {
            et_check(hash_file('sha256', $old_path) === $old_hash, 'Older engagement generation remains byte unchanged');
        }
    }
    $GLOBALS['engagement_test_now'] = '2026-10-12 12:00:00';
    $config = new Engagement_Test_Config(); $config->dry_run = false;
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    for ($i = 1; $i <= 4; $i++) { et_full_row($source, et_engagement($i, '2026-04-23 12:00:00')); }
    $fixture['archive']->after_commit = function () { throw new RuntimeException('controlled_after_commit'); };
    $fixture['worker']->run_source('landing_session_engagements'); $fixture['worker']->run_worker('landing_session_engagements');
    $fixture['archive']->after_commit = null; $config->row_limit = 1;
    for ($i = 0; $i < 4; $i++) {
        $fixture['worker']->run_worker('landing_session_engagements');
        $open = $fixture['repo']->find_open_run_for_source('landing_session_engagements');
        et_check((int) $open['deleted_rows'] === $i + 1 && et_state($open)['last_worker_rows'] <= 1,
            'Smaller resumed budget consumes committed receipt prefix exactly once');
    }
    $run = et_finish($fixture);
    et_check((int) $run['archived_rows'] === 4 && (int) $run['deleted_rows'] === 4,
        'Committed receipt page finishes without double-counting after budget reduction');

    $config = new Engagement_Test_Config(); $config->dry_run = false; $config->row_limit = 1;
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    foreach ([1, 2] as $i) { et_full_row($source, et_engagement($i, '2026-04-23 12:00:00')); }
    $fixture['archive']->after_commit = function () { throw new RuntimeException('controlled_after_commit'); };
    $fixture['worker']->run_source('landing_session_engagements');
    for ($i = 0; $i < 10; $i++) {
        $fixture['worker']->run_worker('landing_session_engagements');
        $run = $fixture['repo']->find_open_run_for_source('landing_session_engagements');
        if (isset(et_state($run)['pending_batch']['receipt_ids'])) { break; }
    }
    $fixture['archive']->after_commit = null; $after_a = null;
    $fixture['repo']->before_open_return = function () use ($fixture, &$after_a) {
        $fixture['worker']->run_worker('landing_session_engagements');
        $after_a = $fixture['repo']->find_open_run_for_source('landing_session_engagements');
    };
    $fixture['worker']->run_worker('landing_session_engagements');
    $after_b = $fixture['repo']->find_open_run_for_source('landing_session_engagements');
    et_check((int) $after_a['deleted_rows'] === 1 && (int) $after_b['deleted_rows'] === 2
        && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $source['source_table']) === 0,
        'Interleaved worker rereads durable progress rather than rewinding actual delete counts');
    et_check(!$fixture['repo']->update_run_checkpoint((int) $after_b['id'], ['deleted_rows' => 0], $after_a)
        && (int) $fixture['repo']->find_run_by_id((int) $after_b['id'])['deleted_rows'] === 2,
        'Atomic audit checkpoint rejects a stale write on the real MySQL connection');

    $config = new Engagement_Test_Config(); $config->dry_run = false;
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1, '2026-04-23 12:00:00'));
    $fixture['archive']->after_commit = function () { global $wpdb; $wpdb->fail_after_reads = true; };
    $fixture['worker']->run_source('landing_session_engagements'); $fixture['worker']->run_worker('landing_session_engagements');
    $open = $fixture['repo']->find_open_run_for_source('landing_session_engagements');
    et_check($open !== null && (int) $open['deleted_rows'] === 1 && $open['finished_at'] === null,
        'After-count query failure keeps committed deletion and an open recovery checkpoint');
    $wpdb->fail_after_reads = false; $fixture['archive']->after_commit = null;
    $run = et_finish($fixture);
    et_check($run['status'] === 'completed' && (int) $run['deleted_rows'] === 1 && (int) $run['archived_rows'] === 1,
        'Final counting recovers without another archive or delete count');
}

function et_checkpoint_readback_scenario(PDO $other): void
{
    global $wpdb;
    $fixture = et_fixture(new Engagement_Test_Config()); $source = $fixture['registry']->get('landing_session_engagements');
    for ($i = 1; $i <= 501; $i++) {
        et_full_row($source, et_engagement($i, '2026-07-06 12:00:00', ['updated_at' => '2026-10-01 12:00:00']));
    }
    $written_id = 0;
    $fixture['repo']->after_checkpoint_write = function ($id, $data) use ($other, &$written_id) {
        $written_id = $id; $state = $data['gate_results_json'];
        $state['candidate_cursor'] = 501; $state['counts']['rows_before'] = 501;
        $advance = $other->prepare('UPDATE t72_kiwi_retention_cleanup_runs SET worker_runs=worker_runs+1, gate_results_json=? WHERE id=?');
        $advance->execute([json_encode($state, JSON_THROW_ON_ERROR), $id]);
    };
    $fixture['worker']->run_source('landing_session_engagements'); $fixture['worker']->run_worker('landing_session_engagements');
    $run = $fixture['repo']->find_run_by_id($written_id);
    et_check((int) $run['worker_runs'] === 2 && et_state($run)['candidate_cursor'] === 501 && $run['finished_at'] === null,
        'Advancement between successful checkpoint write and readback is never adopted by stale local state');
    $run = et_finish($fixture);
    et_check($run['status'] === 'completed' && et_state($run)['counts']['rows_before'] === 501,
        'Recovery continues from the other worker checkpoint without recounting preparation');
}

function et_pending_budget_scenario(): void
{
    global $wpdb;
    $config = new Engagement_Test_Config(); $config->dry_run = false;
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    for ($i = 1; $i <= 4; $i++) { et_full_row($source, et_engagement($i, '2026-04-23 12:00:00')); }
    $fixture['archive']->before_commit = function () { throw new RuntimeException('controlled_before_commit'); };
    $fixture['worker']->run_source('landing_session_engagements'); $fixture['worker']->run_worker('landing_session_engagements');
    $fixture['archive']->before_commit = null; $config->row_limit = 1;
    for ($i = 1; $i <= 4; $i++) {
        $fixture['worker']->run_worker('landing_session_engagements');
        $run = $wpdb->get_row('SELECT * FROM t72_kiwi_retention_cleanup_runs ORDER BY id DESC LIMIT 1');
        et_check((int) $run['deleted_rows'] === $i && (int) $run['archived_rows'] === $i
            && et_state($run)['last_worker_rows'] <= 1,
            'Smaller resumed budget consumes an uncommitted snapshot prefix exactly once');
    }
    $run = et_finish($fixture);
    et_check($run['status'] === 'completed' && (int) $run['deleted_rows'] === 4,
        'Uncommitted snapshot page completes after bounded recovery without skipped source IDs');
}

function et_tiny_coverage_scenario(): void
{
    global $wpdb;
    $config = new Engagement_Test_Config(); $config->row_limit = 1;
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1));
    et_archive_sessions($fixture, [et_session(11, 1), et_session(12, 1, '2026-07-06 12:00:00', ['pid' => '999'])]);
    et_summary($config, '2026-07-06', 1, 2);
    $fixture['worker']->run_source('landing_session_engagements');
    for ($i = 0; $i < 40; $i++) {
        $fixture['worker']->run_worker('landing_session_engagements');
        $run = $wpdb->get_row('SELECT * FROM t72_kiwi_retention_cleanup_runs ORDER BY id DESC LIMIT 1');
        if (isset(et_state($run)['last_worker_rows'])) {
            et_check(et_state($run)['last_worker_rows'] <= 1, 'One-row budget bounds normal coverage continuation');
        }
        if ($run['finished_at'] !== null) { break; }
    }
    et_check($run['status'] === 'completed' && $run['gate_status'] === 'passed'
        && et_state($run)['daily_results']['2026-07-06']['modes']['tkzone']['metrics']['page_loaded_sessions']['raw'] === 1,
        'One-row budget completes a non-historical full-day Main and TK lookup without duplicate counting');
}

function et_receipt_payload_scenario(PDO $other): void
{
    global $wpdb;
    $config = new Engagement_Test_Config(); $config->dry_run = false;
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1, '2026-04-23 12:00:00', ['user_agent' => 'Original-receipt-payload']));
    $fixture['archive']->after_commit = function () { throw new RuntimeException('controlled_after_commit'); };
    $fixture['worker']->run_source('landing_session_engagements'); $fixture['worker']->run_worker('landing_session_engagements');
    $open = $fixture['repo']->find_open_run_for_source('landing_session_engagements');
    $fixture['archive']->after_commit = null;
    $other->exec('UPDATE ' . $source['source_table'] . " SET user_agent='Changed-receipt-payload' WHERE id=1");
    $changed = $wpdb->get_row('SELECT * FROM ' . $source['source_table'] . ' WHERE id=1');
    $fixture['archive']->archive_snapshot_batch($source, [$changed], $open['cutoff_value'], 'fixture-newer-payload', $open['archive_db_path']);
    $fixture['worker']->run_worker('landing_session_engagements');
    $run = $fixture['repo']->find_run_by_id((int) $open['id']);
    et_check((int) $run['deleted_rows'] === 0 && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $source['source_table']) === 1
        && $run['error_code'] === 'engagement_snapshot_hash_mismatch',
        'A stale batch receipt never authorizes a different complete payload even when archive and MySQL now agree');
}

function et_audit_lookup_scenario(string $step): void
{
    global $wpdb;
    $config = new Engagement_Test_Config(); $config->dry_run = false;
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1, '2026-04-23 12:00:00'));
    $fixture['worker']->run_source('landing_session_engagements');
    $before = $fixture['repo']->find_open_run_for_source('landing_session_engagements');
    if ($step === 'open') { $wpdb->fail_open_audit_reads = 1; }
    elseif ($step === 'recheck') { $wpdb->fail_run_audit_reads = 2; }
    else {
        $fixture['archive']->after_commit = function () {
            global $wpdb; $wpdb->fail_after_reads = true; $wpdb->fail_run_audit_reads = 1;
        };
    }
    $result = null;
    try { $result = $fixture['worker']->run_worker('landing_session_engagements'); }
    catch (Throwable $error) { /* Assert below that the production worker contained the read failure. */ }
    et_check(is_array($result) && !$result['success'] && !empty($result['schedule_worker'])
        && !empty($result['reschedule_worker']) && $result['reschedule_delay_seconds'] === 60,
        'Transient ' . $step . ' audit lookup failure returns a scheduled retry rather than escaping');
    $wpdb->fail_open_audit_reads = 0; $wpdb->fail_run_audit_reads = 0; $wpdb->fail_after_reads = false;
    $fixture['archive']->after_commit = null;
    $persisted = $fixture['repo']->find_run_by_id((int) $before['id']);
    et_check($step === 'recovery' ? ((int) $persisted['deleted_rows'] === 1 && $persisted['finished_at'] === null)
        : $persisted === $before, 'Audit lookup outage retains the exact durable ' . $step . ' recovery state');
    $run = et_finish($fixture);
    et_check($run['status'] === 'completed' && (int) $run['archived_rows'] === 1 && (int) $run['deleted_rows'] === 1,
        'Scheduled ' . $step . ' audit retry finishes the same run exactly once');
}

function et_version_day_scenarios(): void
{
    global $wpdb;
    $GLOBALS['engagement_test_now'] = '2026-10-12 12:00:00';
    $fixture = et_fixture(new Engagement_Test_Config()); $source = $fixture['registry']->get('landing_session_engagements');
    $full = et_full_row($source, et_engagement(1));
    et_full_row($source, et_engagement(2));
    $base = $fixture['archive']->resolve_archive_db_path();
    $fixture['archive']->archive_snapshot_batch($source, [$full], '2026-09-28 00:00:00', 'version-base', $base);
    $part2 = dirname($base) . '/kiwi_retention_archive_2026_part_2.sqlite';
    $part10 = dirname($base) . '/kiwi_retention_archive_2026_part_10.sqlite';
    $middle = $full; $middle['cta1_click_count'] = 7; $middle['updated_at'] = '2026-09-15 12:00:00';
    $latest = $full; $latest['cta1_click_count'] = 3;
    $fixture['archive']->archive_snapshot_batch($source, [$middle], '2026-09-28 00:00:00', 'version-2', $part2);
    $fixture['archive']->archive_snapshot_batch($source, [$latest], '2026-09-28 00:00:00', 'version-10', $part10);
    touch($base, time() + 3600); // Neither mtime nor updated_at is the rank.
    $wpdb->pdo->exec('UPDATE ' . $source['source_table'] . " SET cta1_click_count=9,updated_at='2026-10-01 12:00:00' WHERE id=1");
    et_archive_sessions($fixture, [et_session(11, 1), et_session(12, 2)]);
    et_summary($fixture['config'], '2026-07-06', 2, 11);
    $run = et_finish($fixture);
    $modes = et_state($run)['daily_results']['2026-07-06']['modes'];
    et_check($run['gate_status'] === 'passed' && $modes['main']['metrics']['cta1_click_events']['raw'] === 11
        && $modes['tkzone']['metrics']['cta1_click_events']['raw'] === 11,
        'Full-day Main and TK metrics let MySQL win over all archived engagement versions');
    $wpdb->pdo->exec('DELETE FROM ' . $source['source_table'] . ' WHERE id=1');
    $wpdb->pdo->exec('UPDATE t72_kiwi_landing_funnel_daily_summary SET cta1_click_events=5');
    $wpdb->pdo->exec('UPDATE t72_kiwi_landing_funnel_daily_tkzone_summary SET cta1_click_events=5');
    $run = et_finish($fixture);
    $modes = et_state($run)['daily_results']['2026-07-06']['modes'];
    et_check($run['gate_status'] === 'passed' && $modes['main']['metrics']['cta1_click_events']['raw'] === 5
        && $modes['tkzone']['metrics']['cta1_click_events']['raw'] === 5
        && $modes['main']['metrics']['page_loaded_sessions']['raw'] === 2,
        'Complete day counts each archived source ID once using numeric part 10 before part 2');
    $sqlite = new PDO('sqlite:' . $part10);
    $sqlite->exec("DELETE FROM archive_batch_rows WHERE archive_batch_id='version-10' AND source_pk=1"); $sqlite = null;
    $run = et_finish($fixture);
    et_check($run['status'] === 'failed' && $run['error_code'] === 'archive_reader_receipt_missing'
        && (int) $run['archived_rows'] === 0 && (int) $run['deleted_rows'] === 0,
        'Invalid newest engagement receipt blocks instead of falling back to an older version');

    $fixture = et_fixture(new Engagement_Test_Config()); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1));
    $base = et_archive_sessions($fixture, [et_session(11, 1, '2026-07-06 12:00:00', ['user_agent' => 'Before'])]);
    $sessions = $fixture['registry']->get('landing_page_sessions');
    et_full_row($sessions, et_session(11, 1, '2026-07-06 12:00:00', ['user_agent' => 'After']));
    $fixture['archive']->archive_primary_key_chunk($sessions, '2026-09-28 00:00:00', 'conflicting-immutable-session', 0, 11, 500, 60,
        dirname($base) . '/kiwi_retention_archive_2027.sqlite');
    $wpdb->pdo->exec('DELETE FROM ' . $sessions['source_table']);
    et_summary($fixture['config'], '2026-07-06', 1, 2);
    $run = et_finish($fixture);
    et_check($run['status'] === 'failed' && $run['error_code'] === 'archive_reader_snapshot_conflict'
        && (int) $run['archived_rows'] === 0 && (int) $run['deleted_rows'] === 0,
        'Immutable session conflict still compares complete snapshots beyond the five counting fields');
}

if (!in_array('mysql', PDO::getAvailableDrivers(), true) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('These tests require real PDO mysql and sqlite; no silent skip.');
}
$settings_file = getenv('KIWI_RETENTION_TEST_CREDENTIALS_FILE');
if (!$settings_file || !is_file($settings_file)) { throw new RuntimeException('Private local test credentials file is required.'); }
$settings = json_decode(file_get_contents($settings_file), true, 512, JSON_THROW_ON_ERROR);
if (!is_string($settings['dsn'] ?? null)
    || preg_match('/^mysql:host=(?:127\.0\.0\.1|localhost);port=([1-9][0-9]{0,4});charset=utf8mb4$/D', $settings['dsn'], $dsn_match) !== 1
    || (int) $dsn_match[1] > 65535
) { throw new RuntimeException('Only an explicit loopback TCP test instance is allowed.'); }

if (($argv[1] ?? '') === '--writer') {
    $database = getenv('KIWI_RETENTION_TEST_DATABASE');
    if (preg_match('/^codex_test_72_[a-z0-9_]+$/D', $database) !== 1) { throw new RuntimeException('Invalid private test database.'); }
    $wpdb = new Engagement_Test_Wpdb(et_connection($settings, $database));
    try {
        (new Kiwi_Landing_Session_Engagement_Repository(new Engagement_Test_Evaluator()))
            ->upsert_event(['landing_key' => 'fixture-landing', 'session_token' => 'fixture-1', 'cta_step' => 'cta1'], 'cta_click');
    } catch (Throwable $error) { fwrite(STDERR, 'fixture_database_error_' . $wpdb->last_failure_code . "\n"); exit(1); }
    echo "writer_complete\n";
    exit;
}

$database = 'codex_test_72_' . bin2hex(random_bytes(8));
$admin = et_connection($settings);
$admin->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$wpdb = new Engagement_Test_Wpdb(et_connection($settings, $database));
$other = et_connection($settings, $database);
try {
    foreach (['open', 'recheck', 'recovery'] as $step) {
        if (in_array('--audit-lookup=' . $step, $argv, true)) {
            et_audit_lookup_scenario($step); echo 'AUDIT_LOOKUP_TEST_PASSED ', $step, "\n"; return;
        }
    }
    $boundaries = ['checkpoint' => 'et_checkpoint_readback_scenario', 'pending' => 'et_pending_budget_scenario',
        'coverage' => 'et_tiny_coverage_scenario', 'receipt' => 'et_receipt_payload_scenario'];
    foreach ($boundaries as $name => $test) {
        if (in_array('--boundary=' . $name, $argv, true)) {
            $test($other); echo 'BOUNDARY_TEST_PASSED ', $name, "\n"; return;
        }
    }
    et_check(strpos($admin->query('SELECT VERSION()')->fetchColumn(), '11.8.9-MariaDB') === 0, 'Exact temporary MariaDB 11.8.9 engine');
    $fixture = et_fixture(new Engagement_Test_Config());
    $source = $fixture['registry']->get('landing_session_engagements');
    et_check(array_keys($source['archive_columns']) === array_column($wpdb->get_results('SHOW COLUMNS FROM ' . $source['source_table']), 'Field'), 'Complete engagement schema maps every source field');
    et_check($source['cutoff_column'] === 'updated_at', 'Retention uses updated_at');
    et_check((new Kiwi_Config())->get_default_retention_settings()['landing_session_engagements'] === ['enabled' => false, 'dry_run' => true, 'retention_days' => 14], 'Existing configuration stays disabled and dry-run by default');
    foreach (['UTC', 'Europe/Berlin', 'America/New_York'] as $timezone) {
        date_default_timezone_set($timezone);
        foreach (['2026-10-12 12:00:00' => '2026-09-28 00:00:00', '2026-03-30 12:00:00' => '2026-03-16 00:00:00'] as $now => $cutoff) {
            $GLOBALS['engagement_test_now'] = $now;
            et_check($fixture['worker']->cutoff(14) === $cutoff, 'WordPress calendar cutoff independent of PHP timezone and DST');
        }
    }
    $GLOBALS['engagement_test_now'] = '2026-10-12 12:00:00';
    date_default_timezone_set('UTC');
    et_check($fixture['worker']->cutoff(1) === '2026-10-05 00:00:00', 'Retention minimum is seven complete days');
    foreach ([['page_loaded_sessions', 100, 98, true], ['page_loaded_sessions', 100, 97, false],
        ['page_loaded_sessions', 98, 100, true], ['page_loaded_sessions', 149, 146, false],
        ['cta1_click_events', 25, 24, true], ['cta1_click_events', 25, 20, true], ['cta1_click_events', 25, 19, false],
        ['cta1_click_events', 1000, 980, true], ['cta1_click_events', 1000, 979, false]] as [$metric, $raw, $summary, $expected]) {
        et_check(Kiwi_Retention_Engagement_Worker::compare_day([$metric => $raw], [$metric => $summary], true)['ok'] === $expected, 'Exact daily tolerance boundary ' . $metric);
    }
    et_check(!Kiwi_Retention_Engagement_Worker::compare_day([], [], false)['ok'], 'Zero metrics never replace missing coverage');
    foreach (['2026-04-22' => false, '2026-04-23' => true, '2026-07-05' => true, '2026-07-06' => false] as $date => $expected) {
        et_check(Kiwi_Retention_Engagement_Worker::historical($date) === $expected, 'Historical exception has fixed boundaries');
    }

    $fixture = et_fixture(new Engagement_Test_Config());
    $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1));
    et_full_row($source, et_engagement(2, '2026-07-06 12:00:00', ['updated_at' => '2026-10-01 12:00:00']));
    et_archive_sessions($fixture, [et_session(11, 1), et_session(12, 2)]);
    et_summary($fixture['config'], '2026-07-06', 2, 4);
    $run = et_finish($fixture);
    et_check($run['status'] === 'completed' && $run['gate_status'] === 'passed', 'Real-engine dry-run completes full day coverage');
    et_check((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $source['source_table']) === 2 && (int) $run['archived_rows'] === 0, 'Dry-run leaves original data and archive unchanged');
    $state = et_state($run);
    et_check($state['daily_results']['2026-07-06']['modes']['main']['metrics']['cta1_click_events']['raw'] === 4, 'Recently changed retained engagement remains in full-day metrics');
    et_check($state['counts']['changed_deferred'] === 1, 'Dry-run reports old-created rows protected by a recent update');

    $fixture = et_fixture(new Engagement_Test_Config());
    $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1));
    $run = et_finish($fixture);
    et_check($run['error_code'] === 'coverage_gate_failed' && (int) $run['archived_rows'] === 0, 'Missing Main and TK coverage block before archival');

    $config = new Engagement_Test_Config(); $config->dry_run = false;
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1, '2026-04-23 12:00:00'));
    et_full_row($source, et_engagement(2, '2026-04-23 12:00:00'));
    et_full_row($fixture['registry']->get('landing_page_sessions'), et_session(11, 2, '2026-04-23 12:00:00'));
    $run = et_finish($fixture);
    et_check((int) $run['deleted_rows'] === 1 && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $source['source_table']) === 1, 'Historical exception preserves live-session protection');
    et_check((int) $run['archived_rows'] === 1, 'Session-protected row is not archived');

    $config = new Engagement_Test_Config(); $config->dry_run = false;
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    $original = et_full_row($source, et_engagement(1, '2026-04-23 12:00:00', ['user_agent' => null]));
    $fixture['archive']->after_commit = function () use ($other, $source) { $other->exec('UPDATE ' . $source['source_table'] . " SET user_agent='' WHERE id=1"); };
    $run = et_finish($fixture);
    et_check((int) $run['deleted_rows'] === 0 && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $source['source_table']) === 1, 'NULL-to-empty-string change blocks snapshot delete');

    $config = new Engagement_Test_Config(); $config->dry_run = false;
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1, '2026-04-23 12:00:00'));
    $fixture['archive']->after_commit = function () use ($other) {
        $other->exec("INSERT INTO t72_kiwi_landing_page_sessions (created_at,landing_key,session_token,pid) VALUES ('2026-10-12 12:00:00','FIXTURE-LANDING','FIXTURE-1','106')");
    };
    $run = et_finish($fixture);
    et_check((int) $run['deleted_rows'] === 0, 'Case-variant native MySQL session inserted between archive and delete prevents delete');

    $config = new Engagement_Test_Config(); $config->dry_run = false;
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1, '2026-04-23 12:00:00'));
    et_full_row($source, et_engagement(2, '2026-04-23 12:00:00'));
    et_full_row($fixture['registry']->get('landing_page_sessions'), et_session(11, 2, '2026-04-23 12:00:00'));
    $fixture['archive']->after_commit = function () { throw new RuntimeException('fixture_interrupted_after_sqlite_commit'); };
    $fixture['worker']->run_source('landing_session_engagements');
    $fixture['worker']->run_worker('landing_session_engagements');
    $fixture['archive']->after_commit = null;
    $run = et_finish($fixture);
    et_check((int) $run['deleted_rows'] === 1 && (int) $run['archived_rows'] === 1, 'Committed receipt resumes without double archive/delete counts');
    et_check(et_state($run)['counts']['session_deferred'] === 1, 'Interrupted page resumes without double-counting session deferrals');

    $fixture = et_fixture(new Engagement_Test_Config());
    $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1, '2026-04-23 12:00:00'));
    $writer = new Kiwi_Landing_Session_Engagement_Repository(new Engagement_Test_Evaluator());
    $writer->upsert_event(['landing_key' => 'fixture-landing', 'session_token' => 'fixture-1', 'cta_step' => 'cta1'], 'cta_click');
    et_check((int) $wpdb->get_var('SELECT cta1_click_count FROM ' . $source['source_table'] . ' WHERE id=1') === 3, 'Transactional writer preserves existing counters');
    $wpdb->pdo->exec('DELETE FROM ' . $source['source_table'] . ' WHERE id=1');
    $writer->upsert_event(['landing_key' => 'fixture-landing', 'session_token' => 'fixture-1', 'cta_step' => 'cta1'], 'cta_click');
    et_check((int) $wpdb->get_var('SELECT cta1_click_count FROM ' . $source['source_table']) === 1, 'Writer safely recreates a row after cleanup');
    $new_row = $wpdb->get_row('SELECT * FROM ' . $source['source_table']);
    et_check($new_row['ua_ch_brands'] === '' && $new_row['ua_ch_full_version_list'] === '' && $new_row['user_agent'] === '',
        'New transactional rows preserve the existing empty UA defaults');

    $fixture = et_fixture(new Engagement_Test_Config()); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1, '2026-04-23 12:00:00'));
    $other->beginTransaction();
    $other->query('SELECT * FROM ' . $source['source_table'] . ' WHERE id=1 FOR UPDATE')->fetchAll();
    $child_one = et_spawn_writer($database); $child_two = et_spawn_writer($database);
    $waiters_observed = et_wait_lock_waits($wpdb->pdo, 2);
    et_check($waiters_observed, 'Two real writers wait under the same existing unique-key lock');
    $other->commit();
    et_join_writer($child_one); et_join_writer($child_two);
    et_check((int) $wpdb->get_var('SELECT cta1_click_count FROM ' . $source['source_table'] . ' WHERE id=1') === 4, 'Concurrent writers cannot lose a counter update');

    $config = new Engagement_Test_Config(); $config->dry_run = false;
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1, '2026-04-23 12:00:00'));
    $waiting_writer = null;
    $fixture['repo']->before_delete_audit = function () use (&$waiting_writer, $database, $other) {
        $waiting_writer = et_spawn_writer($database);
        et_check(et_wait_lock_waits($other, 1), 'Writer waits under the actual cleanup delete transaction');
    };
    $run = et_finish($fixture);
    et_join_writer($waiting_writer);
    et_check((int) $run['deleted_rows'] === 1 && (int) $wpdb->get_var('SELECT cta1_click_count FROM ' . $source['source_table']) === 1,
        'Writer recreates after actual cleanup commit instead of updating a vanished ID');

    $config = new Engagement_Test_Config(); $config->dry_run = false;
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1, '2026-04-23 12:00:00'));
    $fixture['archive']->after_commit = function () { global $wpdb; $wpdb->fail_audit_once = true; };
    $fixture['worker']->run_source('landing_session_engagements');
    $fixture['worker']->run_worker('landing_session_engagements');
    et_check((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $source['source_table']) === 1, 'Audit failure after delete rolls back the source transaction');
    $fixture['archive']->after_commit = null;
    $run = et_finish($fixture);
    et_check((int) $run['deleted_rows'] === 1 && (int) $run['archived_rows'] === 1, 'Audit failure resumes from committed SQLite receipt exactly once');

    $fixture = et_fixture(new Engagement_Test_Config());
    $path = et_archive_sessions($fixture, [et_session(11, 1)]);
    $session_source = $fixture['registry']->get('landing_page_sessions');
    $index_name = array_key_first(Kiwi_Retention_Archive_Reader::read_indexes($session_source));
    $sqlite = new PDO('sqlite:' . $path); $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->exec('DROP INDEX "' . $index_name . '"'); $sqlite = null;
    $migration = new Kiwi_Retention_Archive_Read_Indexes_Migration_Service($fixture['archive'], $fixture['registry'], $fixture['lock'], $fixture['gate']);
    $before = hash_file('sha256', $path); $status = $migration->status(basename($path));
    et_check($status['success'] && !$status['ready'] && hash_file('sha256', $path) === $before, 'External status reports missing index without archive writes');
    $applied = $migration->apply(basename($path));
    et_check($applied['success'] && $applied['changed'] === 1 && $migration->status(basename($path))['ready'], 'Real SQLite status apply status builds exact covering pair index');
    et_check($migration->apply(basename($path))['changed'] === 0, 'External archive preparation is idempotent');
    $sqlite = new PDO('sqlite:' . $path);
    $plan = $sqlite->query("EXPLAIN QUERY PLAN SELECT landing_key,session_token,created_at,_source_pk,pid FROM \"{$session_source['source_table']}\" WHERE landing_key='FIXTURE-LANDING' COLLATE NOCASE AND session_token='FIXTURE-1' COLLATE NOCASE AND created_at>='2026-07-06' AND created_at<'2026-07-07' AND (created_at,_source_pk)>('2026-07-06',0) ORDER BY created_at,_source_pk LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    et_check(strpos(implode(' ', array_column($plan, 'detail')), 'COVERING INDEX') !== false, 'Actual session lookup uses the covering pair index');
    $payload_before = $sqlite->query('SELECT * FROM ' . $session_source['source_table'])->fetchAll(PDO::FETCH_ASSOC);
    $batches_before = $sqlite->query('SELECT * FROM archive_batches ORDER BY archive_batch_id')->fetchAll(PDO::FETCH_ASSOC);
    $receipts_before = $sqlite->query('SELECT * FROM archive_batch_rows ORDER BY archive_batch_id,source_pk')->fetchAll(PDO::FETCH_ASSOC);
    foreach (['landing_key,session_token,created_at,_source_pk,pid',
        'landing_key COLLATE NOCASE,session_token,created_at,_source_pk,pid',
        'landing_key COLLATE NOCASE DESC,session_token COLLATE NOCASE,created_at,_source_pk,pid',
        'landing_key COLLATE NOCASE,session_token COLLATE NOCASE,created_at COLLATE NOCASE,_source_pk,pid',
        'landing_key COLLATE NOCASE,session_token COLLATE NOCASE,created_at,_source_pk'] as $definition) {
        $sqlite->exec('DROP INDEX "' . $index_name . '"');
        $sqlite->exec('CREATE INDEX "' . $index_name . '" ON "' . $session_source['source_table'] . '" (' . $definition . ')');
        $sqlite = null; // Finish fixture writes/checkpoint before immutable reads.
        $before = hash_file('sha256', $path);
        $status = $migration->status(basename($path));
        et_check($status['success'] && !$status['ready'] && $status['indexes'][$index_name]['replacement_required']
            && hash_file('sha256', $path) === $before, 'Status detects wrong read-index definition without archive writes');
        $reader = new Kiwi_Retention_Archive_Reader($fixture['archive'], $fixture['lock'], $fixture['gate']);
        $rejected = false;
        try { $reader->pair_page($session_source, $path, et_engagement(1), [], 500); }
        catch (RuntimeException $error) { $rejected = $error->getMessage() === 'archive_reader_indexes_missing'; }
        $reader->close();
        et_check($rejected, 'Runtime rejects wrong read index without repair or fallback scan');
        $replacement = $migration->apply(basename($path));
        et_check($replacement['success'] && $replacement['changed'] === 1 && $migration->status(basename($path))['ready'],
            'Only external apply replaces same-named wrong index with the exact NOCASE contract');
        $sqlite = new PDO('sqlite:' . $path);
        et_check($sqlite->query('SELECT * FROM ' . $session_source['source_table'])->fetchAll(PDO::FETCH_ASSOC) === $payload_before
            && $sqlite->query('SELECT * FROM archive_batches ORDER BY archive_batch_id')->fetchAll(PDO::FETCH_ASSOC) === $batches_before
            && $sqlite->query('SELECT * FROM archive_batch_rows ORDER BY archive_batch_id,source_pk')->fetchAll(PDO::FETCH_ASSOC) === $receipts_before,
            'Index replacement preserves payloads and receipts byte exactly');
    }
    foreach (['UNIQUE' => '', '' => ' WHERE pid IS NOT NULL'] as $unique => $predicate) {
        $sqlite->exec('DROP INDEX "' . $index_name . '"');
        $sqlite->exec('CREATE ' . $unique . ' INDEX "' . $index_name . '" ON "' . $session_source['source_table']
            . '" (landing_key COLLATE NOCASE,session_token COLLATE NOCASE,created_at,_source_pk,pid)' . $predicate);
        $sqlite = null;
        et_check(!$migration->status(basename($path))['ready'] && $migration->apply(basename($path))['changed'] === 1,
            'External preparation detects and replaces UNIQUE or partial read indexes');
        $sqlite = new PDO('sqlite:' . $path);
    }
    $source_id_unique = array_filter($sqlite->query('PRAGMA index_list("' . $session_source['source_table'] . '")')->fetchAll(PDO::FETCH_ASSOC),
        static function ($index) { return (int) $index['unique'] === 1; });
    et_check(count($source_id_unique) === 1 && $sqlite->query('PRAGMA index_info("' . reset($source_id_unique)['name'] . '")')->fetchAll(PDO::FETCH_ASSOC)[0]['name'] === '_source_pk',
        'External replacement preserves the original source-PK unique index');
    $sqlite->exec('DROP INDEX "' . $index_name . '"'); $sqlite = null;
    $migration->apply(basename($path));
    $held = $fixture['lock']->acquire_for_archive($path);
    et_check($migration->apply(basename($path))['reason_code'] === 'archive_lock_active', 'External preparation defers under generation lock');
    $fixture['lock']->release($held['handle']);

    $config = new Engagement_Test_Config(); $config->dry_run = false;
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    $full = et_full_row($source, et_engagement(1, '2026-04-23 12:00:00', ['user_agent' => 'CaseSensitive', 'ua_ch_brands' => null]));
    $path = $fixture['archive']->resolve_archive_db_path();
    $fixture['archive']->archive_snapshot_batch($source, [$full], '2026-09-28 00:00:00', 'fixture-first', $path);
    $wpdb->pdo->exec('UPDATE ' . $source['source_table'] . " SET cta1_click_count=7,user_agent='casesensitive',ua_ch_brands='' WHERE id=1");
    $fresh = $wpdb->get_row('SELECT * FROM ' . $source['source_table'] . ' WHERE id=1');
    $fixture['archive']->archive_snapshot_batch($source, [$fresh], '2026-09-28 00:00:00', 'fixture-second', $path);
    $sqlite = new PDO('sqlite:' . $path);
    $archived = $sqlite->query('SELECT * FROM ' . $source['source_table'])->fetch(PDO::FETCH_ASSOC);
    et_check(Kiwi_Retention_Archive_Reader::snapshot($source, $archived) === Kiwi_Retention_Archive_Reader::snapshot($source, $fresh), 'Mutable re-archive updates every field of the same source ID');
    et_check((int) $sqlite->query('SELECT COUNT(*) FROM ' . $source['source_table'])->fetchColumn() === 1, 'Re-archive deduplicates rather than inserting a second source ID');
    $day_plan = $sqlite->query('EXPLAIN QUERY PLAN SELECT * FROM ' . $source['source_table'] . " WHERE created_at>='2026-04-23' AND created_at<'2026-04-24' AND (created_at,_source_pk)>('2026-04-23',0) ORDER BY created_at,_source_pk LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    et_check(strpos(implode(' ', array_column($day_plan, 'detail')), '_retention_day') !== false && strpos(implode(' ', array_column($day_plan, 'detail')), 'TEMP B-TREE') === false,
        'Real engagement day pages use the required index without a sort scan');
    $sqlite = null;
    $copy_path = dirname($path) . '/kiwi_retention_archive_2026_part_2.sqlite'; copy($path, $copy_path);
    // Decision 10 explicitly permits a newer fully committed mutable version.
    $later = $fresh; $later['cta1_click_count'] = 8;
    $fixture['archive']->archive_snapshot_batch($source, [$later], '2026-09-28 00:00:00', 'fixture-later-generation', $copy_path);
    $reader = new Kiwi_Retention_Archive_Reader($fixture['archive'], $fixture['lock'], $fixture['gate']);
    $copies = $reader->copies($source, 1);
    $reader->close();
    et_check(count($copies) === 2 && realpath(array_key_last($copies)) === realpath($copy_path)
        && (int) $copies[array_key_last($copies)]['cta1_click_count'] === 8,
        'Decision 10 permits verified older engagement versions and ranks the latest generation');
    $outside = false;
    try { $reader->open(dirname($fixture['config']->root) . '/kiwi_retention_archive_2026.sqlite'); }
    catch (RuntimeException $error) { $outside = true; }
    et_check($outside, 'Reader rejects a path outside the configured archive root');

    $config = new Engagement_Test_Config(); $config->enabled = false;
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1));
    $disabled = $fixture['worker']->run_source('landing_session_engagements');
    et_check($disabled['error_code'] === 'cleanup_disabled' && count($fixture['archive']->list_archive_files()) === 0
        && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $source['source_table']) === 1, 'Disabled source never opens archive/delete work');

    $fixture = et_fixture(new Engagement_Test_Config()); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1, '2026-04-23 12:00:00', ['updated_at' => '2026-09-28 00:00:00']));
    et_full_row($source, et_engagement(2, '2026-04-23 12:00:00', ['updated_at' => '2026-09-27 23:59:59']));
    $run = et_finish($fixture);
    et_check(et_state($run)['counts']['age_candidates'] === 1 && et_state($run)['counts']['released'] === 1,
        'Strict updated_at cutoff excludes the exact midnight boundary');

    $fixture = et_fixture(new Engagement_Test_Config()); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1)); et_archive_sessions($fixture, [et_session(11, 1)]);
    et_summary($fixture['config'], '2026-07-06', 1, 2);
    $wpdb->pdo->exec('DELETE FROM t72_kiwi_landing_funnel_daily_tkzone_summary');
    $run = et_finish($fixture);
    et_check($run['error_code'] === 'coverage_gate_failed' && (int) $run['archived_rows'] === 0, 'Missing TK alone blocks a day with valid Main coverage');

    $fixture = et_fixture(new Engagement_Test_Config()); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1)); et_archive_sessions($fixture, [et_session(11, 1)]);
    et_summary($fixture['config'], '2026-07-06', 1, 2);
    $wpdb->fail_summary_reads = true;
    $run = et_finish($fixture); $wpdb->fail_summary_reads = false;
    et_check($run['status'] === 'failed' && $run['error_code'] === 'engagement_query_failed' && (int) $run['archived_rows'] === 0,
        'Technical summary read errors cannot release cleanup');

    $config = new Engagement_Test_Config(); $config->pids = ['Affiliate'];
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1));
    et_archive_sessions($fixture, [et_session(11, 1, '2026-07-06 12:00:00', ['pid' => 'not-allowed', 'browser' => 'Browser-A', 'tkzone' => 'zone-a']),
        et_session(12, 1, '2026-07-06 12:00:00', ['pid' => 'affiliate', 'browser' => 'Browser-B', 'tkzone' => 'zone-b'])]);
    et_summary($config, '2026-07-06', 1, 2);
    $run = et_finish($fixture);
    et_check($run['gate_status'] === 'passed' && et_state($run)['daily_results']['2026-07-06']['modes']['tkzone']['metrics']['page_loaded_sessions']['raw'] === 1,
        'PID filter runs before pair combining; equal timestamps and different dimensions add no blocker');

    $fixture = et_fixture(new Engagement_Test_Config()); $source = $fixture['registry']->get('landing_session_engagements');
    $full = et_full_row($source, et_engagement(1));
    et_full_row($source, et_engagement(2));
    $path = $fixture['archive']->resolve_archive_db_path();
    $fixture['archive']->archive_snapshot_batch($source, [$full], '2026-09-28 00:00:00', 'fixture-old-version', $path);
    $wpdb->pdo->exec('UPDATE ' . $source['source_table'] . " SET cta1_click_count=3,updated_at='2026-10-01 12:00:00' WHERE id=1");
    et_archive_sessions($fixture, [et_session(11, 1), et_session(12, 2)]);
    et_summary($fixture['config'], '2026-07-06', 2, 5);
    $run = et_finish($fixture);
    et_check(et_state($run)['daily_results']['2026-07-06']['modes']['main']['metrics']['cta1_click_events']['raw'] === 5,
        'Live source ID wins over its older archived engagement snapshot');
    $wpdb->pdo->exec('DELETE FROM ' . $source['source_table'] . ' WHERE id=1');
    $wpdb->pdo->exec('UPDATE t72_kiwi_landing_funnel_daily_summary SET cta1_click_events=4');
    $wpdb->pdo->exec('UPDATE t72_kiwi_landing_funnel_daily_tkzone_summary SET cta1_click_events=4');
    $run = et_finish($fixture);
    et_check(et_state($run)['daily_results']['2026-07-06']['modes']['main']['metrics']['cta1_click_events']['raw'] === 4,
        'Previously archived engagements remain in the next complete-day gate');
    et_full_row($source, et_engagement(3, '2026-07-06 12:00:00', ['cta1_click_count' => 9]));
    $run = et_finish($fixture);
    et_check(et_state($run)['daily_results']['2026-07-06']['modes']['main']['metrics']['cta1_click_events']['raw'] === 4,
        'Orphan engagement does not invent canonical sessions or clicks');

    $fixture = et_fixture(new Engagement_Test_Config());
    $path = et_archive_sessions($fixture, [et_session(11, 1)]);
    $migration = new Kiwi_Retention_Archive_Read_Indexes_Migration_Service($fixture['archive'], $fixture['registry'], $fixture['lock'], $fixture['gate']);
    $wpdb->pdo->exec('ALTER TABLE t72_kiwi_retention_cleanup_runs ENGINE=Aria');
    et_check($migration->status(basename($path))['reason_code'] === 'engagement_transaction_engine_required',
        'External preparation rejects a nontransactional cleanup audit');

    foreach (['page_loaded_sessions', 'cta1_sessions', 'cta1_click_events', 'cta2_sessions', 'cta2_click_events', 'cta3_sessions', 'cta3_click_events'] as $metric) {
        et_check(Kiwi_Retention_Engagement_Worker::compare_day([$metric => 1000], [$metric => 980], true)['ok']
            && !Kiwi_Retention_Engagement_Worker::compare_day([$metric => 1000], [$metric => 979], true)['ok'], 'Every daily metric uses its own inclusive exact percentage boundary');
    }
    et_check(!Kiwi_Retention_Engagement_Worker::compare_day(['cta1_click_events' => 6], ['cta2_click_events' => 6], true)['ok'],
        'Opposite metric deviations cannot cancel each other');

    $fixture = et_fixture(new Engagement_Test_Config()); $source = $fixture['registry']->get('landing_session_engagements');
    $wpdb->maximum_limit = 0;
    $wpdb->pdo->beginTransaction();
    for ($first = 1; $first <= 50001; $first += 500) {
        $values = [];
        for ($id = $first; $id < min($first + 500, 50002); $id++) {
            $values[] = "('2026-04-23 12:00:00','2026-09-01 12:00:00','2026-04-23 12:00:00','fixture-landing','fixture-{$id}')";
        }
        $wpdb->pdo->exec('INSERT INTO ' . $source['source_table'] . ' (created_at,updated_at,last_event_at,landing_key,session_token) VALUES ' . implode(',', $values));
    }
    $wpdb->pdo->commit();
    $fixture['worker']->run_source('landing_session_engagements');
    $fixture['worker']->run_worker('landing_session_engagements');
    $open = $fixture['repo']->find_open_run_for_source('landing_session_engagements'); $checkpoint = et_state($open);
    et_check($checkpoint['candidate_cursor'] === 50000 && $checkpoint['last_worker_rows'] === 50000 && $checkpoint['phase'] === 'candidates', 'Preparation checkpoints exactly at 50000 of 50001 rows');
    et_check($wpdb->maximum_limit <= 500, 'All worker source reader and summary pages stay within 500 rows');
    $run = et_finish($fixture);
    et_check($run['status'] === 'completed' && et_state($run)['counts']['released'] === 50001 && (int) $run['archived_rows'] === 0,
        'Large dry-run resumes all eligibility and final counting without archival');
    $fixture['config']->time_limit = 1; $wpdb->source_page_delay_us = 220000;
    $fixture['worker']->run_source('landing_session_engagements');
    $started = microtime(true); $fixture['worker']->run_worker('landing_session_engagements'); $elapsed = microtime(true) - $started;
    $open = $fixture['repo']->find_open_run_for_source('landing_session_engagements');
    et_check($elapsed >= 1 && $elapsed < 2 && et_state($open)['last_worker_rows'] < 50000 && (int) $open['archived_rows'] === 0,
        'Real elapsed-time budget pauses bounded preparation before destructive work');
    $wpdb->source_page_delay_us = 0;

    // Keep the current MySQL pair/day contract when data moves to SQLite.
    $fixture = et_fixture(new Engagement_Test_Config()); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1));
    et_full_row($fixture['registry']->get('landing_page_sessions'), et_session(11, 1, '2026-07-06 12:00:00', ['pid' => '999']));
    et_full_row($fixture['registry']->get('landing_page_sessions'), et_session(12, 1, '2026-07-06 12:00:00', ['pid' => '106', 'session_token' => 'FIXTURE-1']));
    $mysql_membership = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$source['source_table']} e WHERE EXISTS (
        SELECT 1 FROM t72_kiwi_landing_page_sessions s WHERE s.landing_key=e.landing_key
        AND s.session_token=e.session_token AND s.pid='106' AND s.created_at>='2026-07-06' AND s.created_at<'2026-07-07')");
    et_check($mysql_membership === 1, 'Current MySQL collation combines case variants before TK counting');
    $path = $fixture['archive']->resolve_archive_db_path();
    $sessions = $fixture['registry']->get('landing_page_sessions');
    $fixture['archive']->archive_primary_key_chunk($sessions, '2026-09-28 00:00:00', 'fixture-case-pairs', 0, 12, 500, 60, $path);
    $wpdb->pdo->exec('DELETE FROM ' . $sessions['source_table']);
    et_summary($fixture['config'], '2026-07-06', 1, 2);
    $run = et_finish($fixture);
    $observed = et_state($run)['daily_results']['2026-07-06']['modes']['tkzone']['metrics']['page_loaded_sessions']['raw'];
    echo json_encode(['contract_probe' => 'mysql_archive_pair_equivalence', 'mysql_tk_membership' => $mysql_membership,
        'archive_tk_membership' => $observed, 'gate_status' => $run['gate_status'], 'archived_rows' => (int) $run['archived_rows']]), "\n";
    et_check($observed === $mysql_membership && $run['gate_status'] === 'passed', 'Archive pair matching preserves current MySQL case-equivalence contract');

    // A live case-variant pair protects before archive, under native MySQL rules.
    $config = new Engagement_Test_Config(); $config->dry_run = false;
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    et_full_row($source, et_engagement(1, '2026-04-23 12:00:00'));
    et_full_row($fixture['registry']->get('landing_page_sessions'), et_session(11, 1, '2026-10-12 12:00:00',
        ['landing_key' => 'FIXTURE-LANDING', 'session_token' => 'FIXTURE-1']));
    $run = et_finish($fixture);
    et_check((int) $run['archived_rows'] === 0 && (int) $run['deleted_rows'] === 0
        && et_state($run)['counts']['session_deferred'] === 1, 'Native MySQL case-variant session protects before archival');

    // Case-tolerant membership must never weaken complete snapshot comparison.
    foreach (['user_agent', 'landing_key', 'session_token'] as $field) {
        $config = new Engagement_Test_Config(); $config->dry_run = false;
        $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
        et_full_row($source, et_engagement(1, '2026-04-23 12:00:00', ['user_agent' => 'CaseSensitive']));
        $value = ['user_agent' => 'casesensitive', 'landing_key' => 'FIXTURE-LANDING', 'session_token' => 'FIXTURE-1'][$field];
        $fixture['archive']->after_commit = function () use ($other, $source, $field, $value) {
            $other->exec('UPDATE ' . $source['source_table'] . ' SET ' . $field . '=' . $other->quote($value) . ' WHERE id=1');
        };
        $run = et_finish($fixture);
        et_check((int) $run['archived_rows'] === 1 && (int) $run['deleted_rows'] === 0
            && et_state($run)['counts']['changed_deferred'] === 1,
            'Case-only ' . $field . ' payload change blocks byte-exact delete');
    }

    // Equivalent ASCII pairs remain one membership across pages/checkpoints.
    $config = new Engagement_Test_Config(); $config->row_limit = 125;
    $fixture = et_fixture($config); $source = $fixture['registry']->get('landing_session_engagements');
    $sessions = $fixture['registry']->get('landing_page_sessions');
    et_full_row($source, et_engagement(1));
    for ($i = 1; $i <= 603; $i++) {
        et_full_row($sessions, et_session($i, 1, $i === 602 ? '2026-07-07 12:00:00' : '2026-07-06 12:00:00',
            ['landing_key' => $i === 603 ? 'different-landing' : ($i % 2 ? 'FIXTURE-LANDING' : 'fixture-landing'),
             'session_token' => $i % 3 ? 'FIXTURE-1' : 'fixture-1', 'pid' => $i === 601 ? '106' : '999']));
    }
    $mysql_pages = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sessions['source_table']} s WHERE landing_key='fixture-landing'
        AND session_token='fixture-1' AND created_at>='2026-07-06' AND created_at<'2026-07-07'");
    $path = $fixture['archive']->resolve_archive_db_path(); $cursor = 0;
    while ($cursor < 603) {
        $page = $fixture['archive']->archive_primary_key_chunk($sessions, '2026-09-28 00:00:00', 'fixture-nocase-page-' . $cursor,
            $cursor, 603, 500, 60, $path);
        if (empty($page['success']) || $page['last_primary_key'] <= $cursor) { throw new RuntimeException('fixture_paged_archive_failed'); }
        $cursor = $page['last_primary_key'];
    }
    $wpdb->pdo->exec('DELETE FROM ' . $sessions['source_table']);
    $reader = new Kiwi_Retention_Archive_Reader($fixture['archive'], $fixture['lock'], $fixture['gate']);
    $cursor = []; $ids = [];
    do {
        $page = $reader->pair_page($sessions, $path, et_engagement(1), $cursor, 500);
        foreach ($page as $row) { $ids[] = (int) $row['id']; $cursor = ['created_at' => $row['created_at'], 'id' => (int) $row['id']]; }
    } while ($page);
    $reader->close();
    et_check($mysql_pages === 601 && $ids === range(1, 601), 'NOCASE pair keyset pages preserve all ASCII variants without duplicate or missing IDs');
    et_summary($config, '2026-07-06', 1, 2);
    $run = et_finish($fixture);
    $daily = et_state($run)['daily_results']['2026-07-06'];
    et_check($run['gate_status'] === 'passed' && $daily['modes']['main']['metrics']['page_loaded_sessions']['raw'] === 1
        && $daily['modes']['tkzone']['metrics']['page_loaded_sessions']['raw'] === 1,
        'Equivalent ASCII session pairs count once and find allowed PID after a checkpoint');
    et_check((int) $run['archived_rows'] === 0 && (int) $run['deleted_rows'] === 0, 'ASCII-variant complete Dry-run changes no originals');

    // Document the deliberately bounded approximation, without normalizing data.
    $sqlite = new PDO('sqlite::memory:');
    foreach ([['Token-AbC', 'TOKEN-abc', true, true], ['ä', 'Ä', true, false],
        ['café', 'cafe', true, false], ['token ', 'token', true, false], ['a-b', 'ab', false, false]] as [$left, $right, $mysql_expected, $sqlite_expected]) {
        $native = $other->prepare('SELECT CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_520_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_520_ci');
        $native->execute([$left, $right]);
        $limited = $sqlite->prepare('SELECT ? = ? COLLATE NOCASE'); $limited->execute([$left, $right]);
        et_check((bool) $native->fetchColumn() === $mysql_expected && (bool) $limited->fetchColumn() === $sqlite_expected,
            'Controlled NOCASE ASCII/Unicode/space boundary agrees with documented limitation');
    }
    foreach (['main', 'tkzone'] as $mode) {
        $fixture = et_fixture(new Engagement_Test_Config()); $source = $fixture['registry']->get('landing_session_engagements');
        et_full_row($source, et_engagement(1));
        et_archive_sessions($fixture, [et_session(11, 1, '2026-07-06 12:00:00',
            ['landing_key' => 'FIXTURE-LANDING', 'session_token' => 'FIXTURE-1'])]);
        et_summary($fixture['config'], '2026-07-06', 1, 2);
        $wpdb->pdo->exec('UPDATE t72_kiwi_landing_funnel_daily_' . ($mode === 'main' ? '' : 'tkzone_') . 'summary SET cta1_click_events=8');
        $run = et_finish($fixture); $modes = et_state($run)['daily_results']['2026-07-06']['modes'];
        et_check($run['gate_status'] === 'failed' && !$modes[$mode]['ok'] && $modes[$mode === 'main' ? 'tkzone' : 'main']['ok']
            && (int) $run['archived_rows'] === 0 && (int) $run['deleted_rows'] === 0,
            'NOCASE membership preserves separate ' . $mode . ' higher-deviation cleanup gate');
    }

    et_recovery_scenarios($other);
    foreach ($boundaries as $test) { $test($other); }
    foreach (['open', 'recheck', 'recovery'] as $step) { et_audit_lookup_scenario($step); }
    et_version_day_scenarios();
    echo 'REAL_ENGINE_TESTS_PASSED ', $GLOBALS['et_checks'], "\n";
} finally {
    $wpdb->query('ROLLBACK');
    $other = null;
    if (preg_match('/^codex_test_72_[a-z0-9_]+$/D', $database) === 1) { $admin->exec('DROP DATABASE ' . $database); }
}
