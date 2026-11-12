<?php
// Standalone synthetic persistence checks using the existing test boundary and a local MySQL instance.
putenv('KIWI_TEST_FILTER=__independent_probe_load_only__');
require __DIR__ . '/run-tests.php';
$credentials = getenv('KIWI_RETENTION_TEST_CREDENTIALS_FILE');
if (!$credentials || !is_file($credentials)) { throw new RuntimeException('Private local test credentials are required.'); }
$settings = json_decode(file_get_contents($credentials), true, 512, JSON_THROW_ON_ERROR);
if (!is_string($settings['dsn'] ?? null)
    || preg_match('/^mysql:host=(?:127\.0\.0\.1|localhost);port=([1-9][0-9]{0,4});charset=utf8mb4$/D', $settings['dsn'], $match) !== 1
    || (int) $match[1] > 65535) { throw new RuntimeException('An explicit loopback test database is required.'); }
function aht_connection(array $settings, string $name = ''): PDO {
    return new PDO($settings['dsn'] . ($name === '' ? '' : ';dbname=' . $name), $settings['username'], $settings['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
}
final class Archive_Health_Test_Database {
    public $options = 'probe_options';
    public $prefix = 'probe_';
    public $last_error = '';
    public $pdo;
    public $before = null;
    public $after = null;
    public $insert_id = 0;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }
    public function prepare(string $sql, ...$args): string {
        return preg_replace_callback('/%[sd]/', function ($m) use (&$args) {
            $value = array_shift($args);
            return $m[0] === '%d' ? (string)(int)$value : $this->pdo->quote((string)$value);
        }, $sql);
    }
    public function query(string $sql) {
        $this->last_error = '';
        try {
            if ($this->before) { ($this->before)($sql, $this); }
            $result = $this->pdo->exec($sql);
            if ($this->after) { $result = ($this->after)($sql, $result, $this); }
            return $result;
        } catch (Throwable $e) { $this->last_error = 'controlled_probe_fault'; return false; }
    }
    public function get_row(string $sql, $format = ARRAY_A): ?array {
        $this->last_error = '';
        try {
            if ($this->before) { ($this->before)($sql, $this); }
            $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
            if ($this->after) { $row = ($this->after)($sql, $row, $this); }
            return $row === false ? null : $row;
        } catch (Throwable $e) { $this->last_error = 'controlled_probe_fault'; return null; }
    }
    public function get_var(string $sql) { $row = $this->get_row($sql); return $row === null ? null : reset($row); }
    public function get_results(string $sql, $format = ARRAY_A): array {
        $this->last_error = '';
        try {
            if ($this->before) { ($this->before)($sql, $this); }
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $this->last_error = 'controlled_probe_fault'; return []; }
    }
    public function insert(string $table, array $row, array $format): bool {
        $this->last_error = '';
        try {
            $sql = 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES ('
                . implode(',', array_fill(0, count($row), '?')) . ')';
            if ($this->before) { ($this->before)($sql, $this); }
            $result = $this->pdo->prepare($sql)->execute(array_values($row));
            $this->insert_id = (int) $this->pdo->lastInsertId();
            return $result;
        } catch (Throwable $e) { $this->last_error = 'controlled_probe_fault'; return false; }
    }
}
function aht_assert(bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException('FAIL ' . $label); }
    $GLOBALS['aht_checks'] = ($GLOBALS['aht_checks'] ?? 0) + 1;
    echo "[PASS] $label\n";
}
function aht_service(Archive_Health_Test_Database $db, array $f): Kiwi_Retention_Archive_Health_Service {
    return new Kiwi_Retention_Archive_Health_Service(archive_service: $f['archive'],
        operational_event_service: new Kiwi_Operational_Event_Service($f['events']),
        run_repository: new Kiwi_Test_Retention_Cleanup_Run_Repository(),
        health_store: new Kiwi_Retention_Archive_Health_Store($db));
}

// A separate process holds the real source lock after its durable start. It does not auto-close Pending.
if (($argv[1] ?? '') === '--hold-source') {
    [$database, $path, $id] = array_slice($argv, 2);
    if (preg_match('/^codex_test_157_[a-f0-9]+$/D', $database) !== 1 || !is_file($path)) {
        throw new RuntimeException('Invalid local source fixture.');
    }
    $archive = new Kiwi_Test_Retention_Sqlite_Archive_Service();
    $archive->archive_files = [['name' => basename($path), 'path' => $path]];
    $events = new Kiwi_Test_One_Failure_Operational_Event_Repository();
    $service = aht_service(new Archive_Health_Test_Database(aht_connection($settings, $database)), compact('archive', 'events'));
    $result = $service->with_new_attempt($id, 'manual', basename($path), filesize($path), static function (): array {
        echo "source_ready\n"; flush();
        fgets(STDIN);
        return ['_exit_code' => 0];
    });
    exit($result['_exit_code']);
}

function aht_spawn_source(string $database, string $path, string $id): array {
    $process = proc_open([PHP_BINARY, __FILE__, '--hold-source', $database, $path, $id],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Unable to start synthetic source.'); }
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $output = ''; $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $output .= stream_get_contents($pipes[1]);
        if (strpos($output, "source_ready\n") !== false) { return [$process, $pipes]; }
        if (!proc_get_status($process)['running']) { break; }
        usleep(10000);
    }
    aht_stop_source([$process, $pipes]);
    throw new RuntimeException('Synthetic source did not reach the durable start boundary.');
}

function aht_stop_source(array $child): void {
    [$process, $pipes] = $child;
    if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
    foreach ($pipes as $pipe) { fclose($pipe); }
    proc_close($process);
}

$database = 'codex_test_157_' . bin2hex(random_bytes(6));
$admin = aht_connection($settings);
$admin->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$f = kiwi_test_attempt_service();
try {
    $db = new Archive_Health_Test_Database(aht_connection($settings, $database));
    $other = new Archive_Health_Test_Database(aht_connection($settings, $database));
    $db->pdo->exec('CREATE TABLE probe_options (option_name varchar(191) PRIMARY KEY, option_value longtext NOT NULL, autoload varchar(20) NOT NULL) ENGINE=InnoDB');
    $initial = json_encode(['format_version'=>1,'revision'=>0,'activated_at'=>null,'active_attempt_id'=>null,
        'last_confirmed_attempt_id'=>null,'last_freshness_completed_at'=>null,'last_attempt_sequence'=>0]);
    $reset = function () use ($db, $initial): void {
        $db->before = $db->after = null;
        $db->pdo->exec('DELETE FROM probe_options');
        $statement = $db->pdo->prepare('INSERT INTO probe_options VALUES (?, ?, ?)');
        $statement->execute([Kiwi_Retention_Archive_Health_Store::STATE_OPTION, $initial, 'no']);
    };
    $reset();
    $service = aht_service($db, $f);
    $observer = aht_service($other, $f);
    $id = str_repeat('1', 32);
    $seen = false;
    $result = $service->with_new_attempt($id, 'manual', basename($f['path']), filesize($f['path']),
        function () use (&$seen, $observer, $id): array {
            $seen = $observer->status($id)['state']['active_attempt_id'] === $id;
            return ['_exit_code'=>0];
        });
    aht_assert($result['_exit_code'] === 0 && $seen, 'independent connection sees committed Pending before export callback');
    $f['events']->fail_next_insert = true;
    aht_assert($service->abort($id, true)['_exit_code'] === 2, 'event failure prevents abort acknowledgement');
    aht_assert($observer->status()['state']['active_attempt_id'] === $id, 'failed abort retains real committed Pending');
    aht_assert($service->abort($id, true)['_exit_code'] === 0, 'retry handles unchanged attempt row with MySQL affected_rows=0');
    $closed = $observer->status($id);
    aht_assert($closed['attempt']['status'] === 'closed' && $closed['state']['active_attempt_id'] === null, 'confirmed abort closes persisted attempt');
    $again = $service->abort($id, true);
    aht_assert($again['attempt']['closed_at'] === $closed['attempt']['closed_at'], 'repeat abort preserves original completion');

    $reset();
    $db->before = function ($query, $connection) use ($other): void {
        if (str_starts_with($query, 'SELECT GET_LOCK(')) {
            $connection->before = null;
            $other->pdo->query($query)->fetchColumn();
        }
    };
    $started = microtime(true);
    $blocked = $service->with_new_attempt(bin2hex(random_bytes(16)), 'manual', basename($f['path']), filesize($f['path']));
    $other->pdo->query('SELECT RELEASE_ALL_LOCKS()');
    aht_assert($blocked['_exit_code'] === 2 && microtime(true)-$started < 6.5,
        'competing database writer prevents start within five-second budget');
    aht_assert($observer->status()['state']['active_attempt_id'] === null, 'unstarted attempt leaves no Pending');

    foreach (['reconnect','commit_ack','readback','stale_json'] as $fault) {
        $reset();
        $used = false;
        if ($fault === 'reconnect') {
            $db->before = function ($sql, $connection) use ($settings, $database) {
                if (str_starts_with($sql, 'INSERT INTO')) {
                    $connection->before = null;
                    $connection->pdo = aht_connection($settings, $database);
                }
            };
        } elseif ($fault === 'commit_ack') {
            $db->after = function ($sql, $result, $connection) {
                if ($sql === 'COMMIT') { $connection->after = null; return false; }
                return $result;
            };
        } elseif ($fault === 'readback') {
            $db->after = function ($sql, $result, $connection) {
                if ($sql === 'COMMIT') {
                    $connection->after = null;
                    $connection->before = function ($query, $c) {
                        if (str_starts_with($query, 'SELECT option_value')) {
                            $c->before = null; throw new RuntimeException('controlled_readback_fault');
                        }
                    };
                }
                return $result;
            };
        } else {
            $db->before = function ($sql, $connection) {
                if (str_starts_with($sql, 'UPDATE probe_options')) {
                    $connection->before = null;
                    $connection->pdo->exec("UPDATE probe_options SET option_value = REPLACE(option_value, '\"revision\":0', '\"revision\":9') WHERE option_name='kiwi_retention_archive_health_state'");
                }
            };
        }
        $id = bin2hex(random_bytes(16));
        $result = $service->with_new_attempt($id, 'manual', basename($f['path']), filesize($f['path']),
            function () use (&$used): array { $used = true; return ['_exit_code'=>0]; });
        $db->before = $db->after = null;
        aht_assert($result['_exit_code'] === 2 && !$used, 'no export after ' . $fault);
        $status = $observer->status();
        $expected = in_array($fault, ['commit_ack','readback'], true) ? $id : null;
        aht_assert($status['state']['active_attempt_id'] === $expected, 'safe durable state after ' . $fault);
    }
    foreach (['crash', 'abort_while_alive'] as $case) {
        $reset();
        $id = bin2hex(random_bytes(16));
        $child = aht_spawn_source($database, $f['path'], $id);
        try {
            $status = $observer->status($id);
            aht_assert($status['state']['active_attempt_id'] === $id && $status['target']['generation_locked'] === true,
                'other process exposes committed Pending and actual OS lock: ' . $case);
            $second = $service->with_new_attempt(bin2hex(random_bytes(16)), 'scheduled', basename($f['path']), filesize($f['path']));
            aht_assert($second['_exit_code'] === 2 && $observer->status()['state']['last_attempt_sequence'] === 1,
                'overlapping starts cannot create a second attempt: ' . $case);
            if ($case === 'abort_while_alive') {
                aht_assert($service->abort($id, true)['_exit_code'] === 0, 'manual abort can invalidate a still living source');
                aht_assert($observer->status()['target']['generation_locked'] === true,
                    'manual abort cannot release another living process OS lock');
                aht_assert($service->with_new_attempt(bin2hex(random_bytes(16)), 'manual', basename($f['path']), filesize($f['path']))['_exit_code'] === 2,
                    'new archive work cannot overlap an aborted but living source');
            }
        } finally { aht_stop_source($child); }
        $status = $observer->status($id);
        aht_assert($status['target']['generation_locked'] === false, 'actual process end releases the OS lock: ' . $case);
        if ($case === 'crash') {
            aht_assert($status['state']['active_attempt_id'] === $id, 'source process crash does not expire Pending');
            aht_assert($service->with_new_attempt(bin2hex(random_bytes(16)), 'manual', basename($f['path']), filesize($f['path']))['_exit_code'] === 2,
                'durable Pending prevents a fresh start after process death');
            aht_assert($service->abort($id, true)['_exit_code'] === 0, 'explicit abort safely completes the crashed source attempt');
        }
        $next = $service->with_new_attempt(bin2hex(random_bytes(16)), 'manual', basename($f['path']), filesize($f['path']));
        aht_assert($next['_exit_code'] === 0 && $next['attempt']['attempt_sequence'] === 2,
            'new work starts only after Pending and the actual process lock end: ' . $case);
    }
    $reset();
    $db->pdo->exec('CREATE TABLE probe_kiwi_operational_events (
        id bigint unsigned AUTO_INCREMENT PRIMARY KEY, occurred_at datetime NOT NULL, created_at datetime NOT NULL,
        area varchar(64) NOT NULL, severity varchar(20) NOT NULL, event_type varchar(100) NOT NULL,
        lifecycle_action varchar(20) NOT NULL, idempotency_key varchar(191) UNIQUE, correlation_key varchar(191) NOT NULL,
        reference_type varchar(64) NOT NULL, reference_id varchar(191) NOT NULL, message varchar(500) NOT NULL,
        raw_error_text text, context_json longtext) ENGINE=InnoDB');
    $clock = (object) ['now' => new DateTimeImmutable('2026-03-01T05:00:00Z')];
    $freshness_service = static function ($connection) use ($f, $clock): Kiwi_Retention_Archive_Health_Service {
        return new Kiwi_Retention_Archive_Health_Service(archive_service: $f['archive'],
            operational_event_service: new Kiwi_Operational_Event_Service(new Kiwi_Operational_Event_Repository()),
            run_repository: new Kiwi_Test_Retention_Cleanup_Run_Repository(),
            health_store: new Kiwi_Retention_Archive_Health_Store($connection), clock: static fn() => $clock->now);
    };
    $daily = $freshness_service($db);
    $other_daily = $freshness_service($other);
    $previous_wpdb = $GLOBALS['wpdb'];
    $GLOBALS['wpdb'] = $db;
    try {
        aht_assert($daily->freshness(true, true)['_exit_code'] === 0, 'real database confirms first freshness activation');
        $clock->now = new DateTimeImmutable('2026-03-09T05:00:00Z');
        $visible_before_completion = false;
        $interleaved_failure = null;
        $db->after = function ($sql, $result, $connection) use ($other, $other_daily, &$visible_before_completion, &$interleaved_failure) {
            if (str_starts_with($sql, 'SELECT RELEASE_LOCK(') && str_contains($sql, 'kiwi_oe_lifecycle_')) {
                $connection->after = null;
                $GLOBALS['wpdb'] = $other;
                try {
                    $status = $other_daily->status();
                    $visible_before_completion = count($status['open_incidents']) === 1
                        && $status['open_incidents'][0]['reason_code'] === 'health_check_overdue'
                        && $status['state']['last_freshness_completed_at'] === '2026-03-01T05:00:00.000000Z';
                    $interleaved_failure = $other_daily->fail_attempt(null, 'start', 'Concurrent synthetic start failure.');
                } finally { $GLOBALS['wpdb'] = $connection; }
            }
            return $result;
        };
        $completed = $daily->freshness();
        aht_assert($visible_before_completion, 'overdue event is committed before lifecycle unlock and daily completion');
        aht_assert($interleaved_failure['_exit_code'] === 0 && $interleaved_failure['incident_action'] === 'repeated',
            'concurrent prestart failure observes the already durable overdue lifecycle');
        aht_assert($completed['_exit_code'] === 0, 'daily completion safely follows the committed event');
        $status = $daily->status();
        aht_assert(count($status['open_incidents']) === 1 && $status['open_incidents'][0]['reason_code'] === 'health_run_failed',
            'later interleaved failure remains the current open incident');

        foreach (['event_write', 'event_readback', 'state_write', 'lost_lock', 'reconnect', 'stale_revision', 'commit_ack', 'readback'] as $fault) {
            $reset();
            $db->pdo->exec('DELETE FROM probe_kiwi_operational_events');
            $clock->now = new DateTimeImmutable('2026-03-01T05:00:00Z');
            $daily->freshness(true, true);
            $clock->now = new DateTimeImmutable('2026-03-09T05:00:00Z');
            if (in_array($fault, ['event_write', 'event_readback', 'state_write'], true)) {
                $inserted = false;
                $db->before = function ($sql, $connection) use ($fault, &$inserted): void {
                    if (str_starts_with($sql, 'INSERT INTO probe_kiwi_operational_events')) {
                        if ($fault === 'event_write') { throw new RuntimeException('controlled_event_write_fault'); }
                        $inserted = true;
                    }
                    if (($fault === 'event_readback' && $inserted && str_starts_with($sql, 'SELECT * FROM probe_kiwi_operational_events'))
                        || ($fault === 'state_write' && str_starts_with($sql, 'UPDATE probe_options'))) {
                        throw new RuntimeException('controlled_persistence_fault');
                    }
                };
            } else {
                $db->after = function ($sql, $result, $connection) use ($fault, $settings, $database, $other) {
                    if (in_array($fault, ['lost_lock', 'reconnect', 'stale_revision'], true)
                        && str_starts_with($sql, 'SELECT RELEASE_LOCK(') && str_contains($sql, 'kiwi_oe_lifecycle_')) {
                        $connection->after = null;
                        if ($fault === 'lost_lock') { $connection->pdo->query('SELECT RELEASE_ALL_LOCKS()'); }
                        if ($fault === 'reconnect') { $connection->pdo = aht_connection($settings, $database); }
                        if ($fault === 'stale_revision') {
                            $other->pdo->exec("UPDATE probe_options SET option_value = REPLACE(option_value, '\"revision\":1', '\"revision\":99') WHERE option_name='kiwi_retention_archive_health_state'");
                        }
                    }
                    if ($sql === 'COMMIT' && in_array($fault, ['commit_ack', 'readback'], true)) {
                        $connection->after = null;
                        if ($fault === 'commit_ack') { return false; }
                        $connection->before = static function ($query, $c): void {
                            if (str_starts_with($query, 'SELECT option_value')) {
                                $c->before = null; throw new RuntimeException('controlled_readback_fault');
                            }
                        };
                    }
                    return $result;
                };
            }
            $failed = $daily->freshness();
            $db->before = $db->after = null;
            aht_assert($failed['_exit_code'] === 2, 'freshness never acknowledges uncertain persistence: ' . $fault);
            $GLOBALS['wpdb'] = $other;
            try { $actual = $other_daily->status(); } finally { $GLOBALS['wpdb'] = $db; }
            $expected_completion = in_array($fault, ['commit_ack', 'readback'], true)
                ? '2026-03-09T05:00:00.000000Z' : '2026-03-01T05:00:00.000000Z';
            aht_assert($actual['state']['last_freshness_completed_at'] === $expected_completion,
                'independent read distinguishes unchanged completion from lost post-commit acknowledgement: ' . $fault);
            if ($fault === 'stale_revision') {
                aht_assert($actual['state']['revision'] === 99, 'daily completion cannot overwrite a changed pre-transaction state');
            }
        }
    } finally { $GLOBALS['wpdb'] = $previous_wpdb; }
    echo 'ARCHIVE_HEALTH_STATE_INTEGRATION_PASSED ', $GLOBALS['aht_checks'], "\n";
} finally {
    $admin->exec('DROP DATABASE ' . $database);
    kiwi_remove_directory($f['root']);
}
