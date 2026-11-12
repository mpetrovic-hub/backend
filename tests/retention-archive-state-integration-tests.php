<?php
// Standalone synthetic persistence checks using the existing test boundary and a local MySQL instance.
putenv('KIWI_TEST_FILTER=__independent_probe_load_only__');
ob_start();
require __DIR__ . '/run-tests.php';
ob_end_clean();
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
            if (str_starts_with($sql, 'INSERT INTO ')) { $this->insert_id = (int) $this->pdo->lastInsertId(); }
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

// Actual transport/recovery in another PHP process, restricted to this task's artificial database/files.
if (in_array($argv[1] ?? '', ['--transport', '--recover-paused', '--operator-abort',
    '--recover-reconnect-lock', '--recover-reconnect-insert', '--recover-reconnect-readback',
    '--recover-reconnect-readback-obstructed'], true)) {
    [$database, $path] = array_slice($argv, 2);
    $real = realpath($path);
    if (count($argv) !== 4 || preg_match('/^codex_test_157_[a-f0-9]+$/D', $database) !== 1
        || $real === false || !is_file($real) || is_link($path)
        || strpos($real, sys_get_temp_dir() . '/kiwi-attempt-') !== 0
        || Kiwi_Retention_Archive_Name::normalize(basename($real)) !== basename($real)) {
        throw new RuntimeException('Invalid local transport fixture.');
    }
    $db = new Archive_Health_Test_Database(aht_connection($settings, $database));
    $GLOBALS['wpdb'] = $db;
    $archive = new Kiwi_Test_Retention_Sqlite_Archive_Service();
    foreach (glob(dirname($real) . '/kiwi_retention_archive_*.sqlite') as $file) {
        $archive->archive_files[] = ['name' => basename($file), 'path' => $file];
    }
    $service = new Kiwi_Retention_Archive_Health_Service(archive_service: $archive,
        operational_event_service: new Kiwi_Operational_Event_Service(new Kiwi_Operational_Event_Repository()),
        run_repository: new Kiwi_Test_Retention_Cleanup_Run_Repository(), health_store: new Kiwi_Retention_Archive_Health_Store($db),
        check_runner: static function (string $file): array {
            $sqlite = new PDO('sqlite:file:' . $file . '?mode=ro');
            $rows = $sqlite->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
            return ['result' => $rows === ['ok'] ? 'ok' : 'corruption_detected', 'check_completed' => true,
                'reason_code' => $rows === ['ok'] ? 'sqlite_check_ok' : 'sqlite_check_reported_corruption'];
        });
    if ($argv[1] === '--transport') {
        exit((new Kiwi_Retention_Archive_Health_Transport($service))->serve((string) getenv('SSH_ORIGINAL_COMMAND'), STDIN, STDOUT));
    }
    if ($argv[1] === '--operator-abort') {
        $request = json_decode(stream_get_contents(STDIN, 16385), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($request) || array_keys($request) !== ['attempt_id', 'confirm'] || $request['confirm'] !== true
            || !is_string($request['attempt_id']) || preg_match('/^[a-f0-9]{32}$/D', $request['attempt_id']) !== 1) {
            throw new RuntimeException('Invalid explicit fixture abort.');
        }
        $answer = $service->abort($request['attempt_id'], true);
        echo json_encode($answer), "\n";
        exit($answer['_exit_code']);
    }
    if (str_starts_with($argv[1], '--recover-reconnect-')) {
        $fault = substr($argv[1], strlen('--recover-reconnect-'));
        $obstruct_restore = $fault === 'readback-obstructed';
        if ($obstruct_restore) { $fault = 'readback'; }
        $health_locked = false; $inserted = false;
        $db->before = static function (string $sql, $connection) use ($settings, $database, $real, $fault,
            $obstruct_restore, &$health_locked, &$inserted): void {
            if (str_starts_with($sql, 'SELECT GET_LOCK(') && str_contains($sql, 'kiwi_health_')) { $health_locked = true; }
            if (!$health_locked) { return; }
            $insert = str_starts_with($sql, 'INSERT INTO probe_kiwi_operational_events');
            $matches = ($fault === 'lock' && str_starts_with($sql, 'SELECT GET_LOCK(') && str_contains($sql, 'kiwi_oe_lifecycle_'))
                || ($fault === 'insert' && $insert)
                || ($fault === 'readback' && $inserted && str_starts_with($sql, 'SELECT * FROM probe_kiwi_operational_events'));
            if ($insert) { $inserted = true; }
            if (!$matches) { return; }
            $connection->before = null;
            // Drop the actual old PDO connection: MariaDB releases its connection-owned health lock.
            $connection->pdo = aht_connection($settings, $database);
            if ($obstruct_restore) { mkdir($real . '.lock.write-blocked', 0700); }
            fwrite(STDERR, "recovery_reconnected\n"); fflush(STDERR);
            fgets(STDIN);
        };
    } else { $db->before = static function (string $sql, $connection) use ($real): void {
        if (!str_starts_with($sql, 'SELECT latest.*')) { return; }
        $probe = (new Kiwi_Retention_Archive_Lock())->acquire_for_archive($real);
        if (!empty($probe['acquired'])) { $probe['handle']->release(); return; }
        $connection->before = null;
        fwrite(STDERR, "recovery_effects_ready\n"); fflush(STDERR);
        fgets(STDIN);
    }; }
    $answer = $service->unblock(basename($real), '', true);
    echo json_encode($answer), "\n";
    exit($answer['_exit_code']);
}

function aht_transport(string $database, string $path, string $action, array $request): array {
    $environment = getenv(); $environment['SSH_ORIGINAL_COMMAND'] = $action;
    $process = proc_open([PHP_BINARY, __FILE__, '--transport', $database, $path],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) { throw new RuntimeException('Synthetic transport could not start.'); }
    fwrite($pipes[0], json_encode($request)); fclose($pipes[0]);
    $wire = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $wire, $error];
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
        $event_service = new Kiwi_Operational_Event_Service(new Kiwi_Operational_Event_Repository());
        $event = ['area' => 'retention', 'severity' => 'error', 'event_type' => 'synthetic_guarded_failure',
            'correlation_key' => 'synthetic_guarded_failure', 'idempotency_key' => 'guarded_failure_1',
            'message' => 'Synthetic guarded failure.'];
        $event_lock = 'kiwi_test_failure_' . substr(hash('sha256', $database), 0, 16);
        $db->get_var($db->prepare('SELECT GET_LOCK(%s, 5)', $event_lock));
        $guard = new Kiwi_Database_Lock_Guard($event_lock, (int) $db->get_var('SELECT CONNECTION_ID()'));
        try {
            $actions = [$event_service->record_failure_action_if($event, static fn() => true, $guard),
                $event_service->record_failure_action_if($event, static fn() => true, $guard)];
            $event['idempotency_key'] = 'guarded_failure_2';
            $actions[] = $event_service->record_failure_action_if($event, static fn() => true, $guard);
            $actions[] = $event_service->record_failure_action_if($event, static fn() => true, $guard);
            aht_assert($actions === ['raised', 'raised', 'repeated', 'repeated'],
                'guarded shared failure retries confirm their original raised and repeated events');
        } finally { $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $event_lock)); }
        $events = (new Kiwi_Operational_Event_Repository())->get_recent();
        aht_assert($event_service->record_failure_action_if($event, static fn() => true, $guard) === '',
            'an expired guard cannot acknowledge a duplicate failure');
        $event['idempotency_key'] = 'guarded_failure_3';
        aht_assert($event_service->record_failure_action_if($event, static fn() => true, $guard) === ''
            && count($events) === 2 && (new Kiwi_Operational_Event_Repository())->get_recent() === $events,
            'an expired guard cannot append a new repeated failure');
        $db->pdo->exec('DELETE FROM probe_kiwi_operational_events');
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
        foreach (['lock', 'raised_insert', 'repeated_insert'] as $fault) {
            $reset(); $db->pdo->exec('DELETE FROM probe_kiwi_operational_events');
            $clock->now = new DateTimeImmutable('2026-03-01T05:00:00Z');
            $daily->freshness(true, true);
            $clock->now = new DateTimeImmutable('2026-03-09T05:00:00Z');
            if ($fault === 'repeated_insert') { $daily->freshness(); }
            $id = bin2hex(random_bytes(16));
            $accepted = $accepted_status = $accepted_events = null;
            $db->before = static function ($sql, $connection) use ($fault, $settings, $database, $other,
                $other_daily, $f, $id, &$accepted, &$accepted_status, &$accepted_events): void {
                $matches = $fault === 'lock'
                    ? str_starts_with($sql, 'SELECT GET_LOCK(') && str_contains($sql, 'kiwi_oe_lifecycle_')
                    : str_starts_with($sql, 'INSERT INTO probe_kiwi_operational_events');
                if (!$matches) { return; }
                $connection->before = null;
                $connection->pdo = aht_connection($settings, $database);
                $GLOBALS['wpdb'] = $other;
                try {
                    $proof = kiwi_test_result_export(['service' => $other_daily, 'path' => $f['path']], $id);
                    $accepted = $other_daily->accept_result($proof);
                    $accepted_status = $other_daily->status($id);
                    $accepted_events = (new Kiwi_Operational_Event_Repository())->get_recent();
                } finally { $GLOBALS['wpdb'] = $connection; }
            };
            $failed = $daily->freshness();
            $db->before = null;
            $after = $daily->status($id);
            aht_assert($failed['_exit_code'] === 2 && $accepted['_exit_code'] === 0
                && $after['state'] === $accepted_status['state'] && $after['attempt'] === $accepted_status['attempt'],
                'reconnected freshness preserves later accepted result and prior daily completion at ' . $fault);
            aht_assert($after['open_incidents'] === []
                && (new Kiwi_Operational_Event_Repository())->get_recent() === $accepted_events,
                'stale freshness cannot append overdue after a later accepted result at ' . $fault);
            aht_assert($daily->freshness()['result'] === 'ok'
                && (new Kiwi_Operational_Event_Repository())->get_recent() === $accepted_events,
                'freshness retry uses the later result without routine success events at ' . $fault);
        }
    } finally { $GLOBALS['wpdb'] = $previous_wpdb; }
    $reset();
    $db->pdo->exec('DELETE FROM probe_kiwi_operational_events');
    $id = str_repeat('a', 32);
    [$export_exit, $stream] = aht_transport($database, $f['path'], 'export', ['schema_version' => 1,
        'attempt_id' => $id, 'start_kind' => 'manual', 'generation' => basename($f['path']), 'expected_bytes' => filesize($f['path'])]);
    aht_assert($export_exit === 0, 'independent PHP transport exports a small actual SQLite source');
    $header_end = strpos($stream, "\n");
    $trailer = json_decode(substr($stream, $header_end + 1 + filesize($f['path'])), true, 512, JSON_THROW_ON_ERROR);
    $proof = ['schema_version' => 1, 'attempt_id' => $id, 'generation' => basename($f['path']),
        'received_bytes' => $trailer['bytes'], 'sha256' => $trailer['sha256'], 'transfer_complete' => true,
        'check' => 'quick_check', 'check_completed' => true, 'outcome' => 'ok'];
    [$result_exit, $ack] = aht_transport($database, $f['path'], 'result', $proof);
    $accepted = json_decode($ack, true, 512, JSON_THROW_ON_ERROR);
    aht_assert($result_exit === 0 && $accepted['outcome'] === 'ok', 'another PHP process accepts the stored source-bound result');
    [$status_exit, $status_wire] = aht_transport($database, $f['path'], 'status', ['schema_version' => 1, 'attempt_id' => $id]);
    $status = json_decode($status_wire, true, 512, JSON_THROW_ON_ERROR);
    aht_assert($status_exit === 0 && $status['attempt']['status'] === 'closed'
        && $status['attempt']['accepted_at'] === $accepted['accepted_at'], 'third process reads original durable acceptance after response loss');

    $GLOBALS['wpdb'] = $db;
    try {
        foreach (['manual_abort', 'technical_failure', 'technical_failure_manual_abort'] as $mode) {
            $reset(); $db->pdo->exec('DELETE FROM probe_kiwi_operational_events');
            $id = bin2hex(random_bytes(16));
            $daily->with_new_attempt($id, 'manual', basename($f['path']), filesize($f['path']));
            $commits = 0;
            $db->before = static function ($sql) use (&$commits): void {
                if ($sql === 'COMMIT' && ++$commits === 2) { throw new RuntimeException('controlled_closure_commit_fault'); }
            };
            $close = static fn() => $mode === 'manual_abort' ? $daily->abort($id, true)
                : $daily->fail_attempt($id, 'check', 'Synthetic interrupted check.', 'health_child_timeout');
            $failed = $close();
            $db->before = null;
            $pending = $daily->status($id);
            $events = (new Kiwi_Operational_Event_Repository())->get_recent();
            aht_assert($failed['_exit_code'] === 2 && $pending['attempt']['status'] === 'open'
                && $pending['attempt']['invalidated_at'] !== null && $pending['state']['active_attempt_id'] === $id
                && count($events) === 1 && $events[0]['lifecycle_action'] === 'raised',
                'failed final closure retains Pending after durable failure event: ' . $mode);
            $retry_close = $mode === 'technical_failure_manual_abort' ? static fn() => $daily->abort($id, true) : $close;
            $retry = $retry_close();
            aht_assert($retry['_exit_code'] === 0, 'failure closure retry confirms the original raised event: ' . $mode);
            $closed = $daily->status($id);
            $expected = array_replace($pending['attempt'], ['status' => 'closed', 'closed_at' => $closed['attempt']['closed_at']]);
            aht_assert($closed['attempt'] === $expected && $closed['attempt']['closed_at'] !== null
                && $closed['state']['active_attempt_id'] === null && $closed['state']['last_confirmed_attempt_id'] === null,
                'failure retry preserves invalidation and cause before clearing Pending: ' . $mode);
            aht_assert((new Kiwi_Operational_Event_Repository())->get_recent() === $events
                && $retry_close()['attempt'] === $closed['attempt'],
                'failure retries preserve the original event and completed closure: ' . $mode);
        }
        foreach (['lock', 'insert'] as $fault) {
            $reset(); $db->pdo->exec('DELETE FROM probe_kiwi_operational_events');
            $daily->fail_attempt(null, 'start', 'Synthetic prior Availability failure.');
            $proof = kiwi_test_result_export(['service' => $daily, 'path' => $f['path']], bin2hex(random_bytes(16)));
            $aborted = $aborted_status = $aborted_events = null;
            $db->before = static function ($sql, $connection) use ($fault, $settings, $database, $other,
                $other_daily, $proof, &$aborted, &$aborted_status, &$aborted_events): void {
                $matches = $fault === 'lock'
                    ? str_starts_with($sql, 'SELECT GET_LOCK(') && str_contains($sql, 'kiwi_oe_lifecycle_')
                    : str_starts_with($sql, 'INSERT INTO probe_kiwi_operational_events');
                if (!$matches) { return; }
                $connection->before = null;
                // Closing the real PDO connection releases its health lock before the concurrent abort.
                $connection->pdo = aht_connection($settings, $database);
                $GLOBALS['wpdb'] = $other;
                try {
                    $aborted = $other_daily->abort($proof['attempt_id'], true);
                    $aborted_status = $other_daily->status($proof['attempt_id']);
                    $aborted_events = (new Kiwi_Operational_Event_Repository())->get_recent();
                } finally { $GLOBALS['wpdb'] = $connection; }
            };
            $rejected = $daily->accept_result($proof);
            $db->before = null;
            $after = $daily->status($proof['attempt_id']);
            aht_assert($aborted['_exit_code'] === 0 && $rejected['_exit_code'] === 2
                && $after['attempt']['outcome'] === 'manual_abort' && $after['state']['last_confirmed_attempt_id'] === null,
                'result loses ownership and yields to a real concurrent abort at ' . $fault);
            aht_assert($after['state'] === $aborted_status['state'] && $after['attempt'] === $aborted_status['attempt']
                && count($after['open_incidents']) === 1
                && (new Kiwi_Operational_Event_Repository())->get_recent() === $aborted_events,
                'reconnected result cannot resolve the concurrent abort Availability at ' . $fault);
        }
        foreach (['result_commit_ack', 'closure_commit_ack', 'closure_write', 'event_readback'] as $fault) {
            $reset(); $db->pdo->exec('DELETE FROM probe_kiwi_operational_events');
            $daily->fail_attempt(null, 'start', 'Synthetic result preparation failure.');
            $proof = kiwi_test_result_export(['service' => $daily, 'path' => $f['path']], bin2hex(random_bytes(16)));
            $commits = 0;
            $db->after = static function ($sql, $result, $connection) use ($fault, &$commits) {
                if ($sql !== 'COMMIT') { return $result; }
                $commits++;
                if (($fault === 'result_commit_ack' && $commits === 1)
                    || ($fault === 'closure_commit_ack' && $commits === 2)) { return false; }
                return $result;
            };
            $event_inserted = false;
            $db->before = static function ($sql) use ($fault, &$commits, &$event_inserted): void {
                if (str_starts_with($sql, 'INSERT INTO probe_kiwi_operational_events')) { $event_inserted = true; }
                if (($fault === 'closure_write' && $commits === 1 && str_starts_with($sql, 'UPDATE probe_options'))
                    || ($fault === 'event_readback' && $event_inserted && str_starts_with($sql, 'SELECT * FROM probe_kiwi_operational_events'))) {
                    throw new RuntimeException('controlled_result_persistence_fault');
                }
            };
            $failed = $daily->accept_result($proof);
            $db->before = $db->after = null;
            aht_assert($failed['_exit_code'] === 2, 'uncertain real result persistence is never acknowledged: ' . $fault);
            [$read_exit, $wire] = aht_transport($database, $f['path'], 'status', ['schema_version' => 1, 'attempt_id' => $proof['attempt_id']]);
            $actual = json_decode($wire, true, 512, JSON_THROW_ON_ERROR);
            aht_assert($read_exit === 0 && $actual['attempt']['status'] === ($fault === 'closure_commit_ack' ? 'closed' : 'open'),
                'independent process distinguishes retained Pending and lost final acknowledgement: ' . $fault);
            $retry = $daily->accept_result($proof);
            aht_assert($retry['_exit_code'] === 0 && ($actual['attempt']['accepted_at'] === null || $retry['accepted_at'] === $actual['attempt']['accepted_at']),
                'real-database result retry finishes safely without refreshing accepted time: ' . $fault);
            aht_assert((int) $db->pdo->query("SELECT COUNT(*) FROM probe_kiwi_operational_events WHERE lifecycle_action='resolved'")->fetchColumn() === 1,
                'real-database retry appends only one Availability resolution: ' . $fault);
        }

        foreach (['duplicate', 'abort_ok', 'abort_corruption', 'lifecycle_visibility'] as $race) {
            $reset(); $db->pdo->exec('DELETE FROM probe_kiwi_operational_events');
            $daily->fail_attempt(null, 'start', 'Synthetic prior Availability failure.');
            $proof = kiwi_test_result_export(['service' => $daily, 'path' => $f['path']], bin2hex(random_bytes(16)));
            if ($race === 'abort_corruption') { $proof['outcome'] = 'corruption'; }
            $competing = null; $visible = false;
            $db->after = function ($sql, $result, $connection) use ($race, $proof, $database, $f, $other, $other_daily, &$competing, &$visible) {
                $wanted = $race === 'lifecycle_visibility' ? 'kiwi_oe_lifecycle_' : 'kiwi_health_';
                if (!str_starts_with($sql, 'SELECT RELEASE_LOCK(') || !str_contains($sql, $wanted)) { return $result; }
                $connection->after = null;
                if ($race === 'duplicate') {
                    $competing = aht_transport($database, $f['path'], 'result', $proof)[0];
                    return $result;
                }
                $GLOBALS['wpdb'] = $other;
                try {
                    if ($race === 'lifecycle_visibility') {
                        $status = $other_daily->status($proof['attempt_id']);
                        $visible = $status['attempt']['status'] === 'open' && $status['attempt']['outcome'] === 'ok'
                            && $status['state']['last_confirmed_attempt_id'] === null && $status['open_incidents'] === [];
                        $competing = $other_daily->fail_attempt(null, 'start', 'A later concurrent Availability failure.')['_exit_code'];
                    } else { $competing = $other_daily->abort($proof['attempt_id'], true)['_exit_code']; }
                } finally { $GLOBALS['wpdb'] = $connection; }
                return $result;
            };
            $answer = $daily->accept_result($proof);
            aht_assert($answer['_exit_code'] === ($race === 'abort_ok' ? 2 : 0), 'result finalization respects concurrent action: ' . $race);
            aht_assert($competing === (in_array($race, ['duplicate', 'abort_corruption'], true) ? 2 : 0),
                'independent connection or process observes the actual result guards: ' . $race);
            if ($race === 'duplicate') {
                $retry = aht_transport($database, $f['path'], 'result', $proof);
                aht_assert($retry[0] === 0 && json_decode($retry[1], true)['accepted_at'] === $answer['accepted_at'],
                    'competing duplicate can later confirm the same original acceptance');
            }
            if ($race === 'abort_ok') {
                aht_assert($daily->status($proof['attempt_id'])['attempt']['outcome'] === 'manual_abort', 'a winning safe abort cannot be overwritten by late ok');
            }
            if ($race === 'abort_corruption') { unlink($f['path'] . '.lock.write-blocked'); }
            if ($race === 'lifecycle_visibility') {
                aht_assert($visible, 'resolved Availability is committed while the result still keeps Pending');
                aht_assert(count($daily->status()['open_incidents']) === 1, 'later interleaved failure remains open after older result completion');
            }
        }
    } finally { $db->before = $db->after = null; $GLOBALS['wpdb'] = $previous_wpdb; }

    // Pause repair A after its last optimistic Pending read, while A's real generation flock is held.
    // A different connection may start B only if repair's final effects have not reserved the global health guard.
    $reset(); $db->pdo->exec('DELETE FROM probe_kiwi_operational_events');
    $next = $f['root'] . '/kiwi_retention_archive_2027.sqlite'; copy($f['path'], $next);
    $f['archive']->archive_files[] = ['name' => basename($next), 'path' => $next];
    $previous_wpdb = $GLOBALS['wpdb']; $GLOBALS['wpdb'] = $db;
    try {
        $event_service = new Kiwi_Operational_Event_Service(new Kiwi_Operational_Event_Repository());
        $gate = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator(new Kiwi_Retention_Archive_Lock(), $event_service,
            new Kiwi_Test_Retention_Cleanup_Run_Repository(), new Kiwi_Retention_Archive_Health_Store($db));
        $gate->block_after_corruption($f['path'], 'quick', 'sqlite_check_reported_corruption');
        $child = proc_open([PHP_BINARY, __FILE__, '--recover-paused', $database, $f['path']],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($child)) { throw new RuntimeException('Synthetic recovery did not start.'); }
        try {
            stream_set_timeout($pipes[2], 10);
            $ready = fgets($pipes[2]);
            aht_assert($ready === "recovery_effects_ready\n", 'repair A reaches final effects under its actual generation lock');
            $new_id = str_repeat('b', 32);
            $started = $daily->with_new_attempt($new_id, 'manual', basename($next), filesize($next));
            fwrite($pipes[0], "continue\n"); fclose($pipes[0]);
            $recovered = json_decode(stream_get_contents($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($child); $child = null;
            aht_assert(!($started['_exit_code'] === 0 && $recovered['_exit_code'] === 0),
                'repair A cannot clear protection while a newly started B attempt is open');
            if ($started['_exit_code'] === 0) {
                aht_assert(is_file($f['path'] . '.lock.write-blocked'), 'a concurrent B start leaves A protection in place');
                $daily->abort($new_id, true);
            } else {
                aht_assert($recovered['_exit_code'] === 0, 'short recovery effects can finish before a later B start');
                aht_assert($daily->with_new_attempt($new_id, 'manual', basename($next), filesize($next))['_exit_code'] === 0,
                    'a new B attempt can start after the final recovery guard is released');
                $daily->abort($new_id, true);
            }
        } finally {
            if (is_resource($child)) { proc_terminate($child, 9); foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } } proc_close($child); }
        }
    } finally { $GLOBALS['wpdb'] = $previous_wpdb; }
    foreach (['lock', 'insert', 'readback', 'sole_sentinel', 'readback-obstructed'] as $fault) {
        $reset(); $db->pdo->exec('DELETE FROM probe_kiwi_operational_events');
        $previous_wpdb = $GLOBALS['wpdb']; $GLOBALS['wpdb'] = $db;
        try {
            if ($fault === 'sole_sentinel') {
                $fixture_lock = (new Kiwi_Retention_Archive_Lock())->acquire_for_archive($f['path']);
                aht_assert($fixture_lock['handle']->persist_write_blocked(), 'sole-sentinel recovery fixture has durable file protection');
                $fixture_lock['handle']->release();
            } else {
                $gate->block_after_corruption($f['path'], 'quick', 'sqlite_check_reported_corruption');
            }
            $child = proc_open([PHP_BINARY, __FILE__, '--recover-reconnect-' . ($fault === 'sole_sentinel' ? 'lock' : $fault),
                $database, $f['path']], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($child)) { throw new RuntimeException('Synthetic reconnect recovery did not start.'); }
            try {
                stream_set_timeout($pipes[2], 10);
                aht_assert(fgets($pipes[2]) === "recovery_reconnected\n", 'recovery child loses its actual health-lock connection at ' . $fault);
                $new_id = bin2hex(random_bytes(16));
                $started = $daily->with_new_attempt($new_id, 'manual', basename($next), filesize($next));
                aht_assert($started['_exit_code'] === 0, 'independent process can acquire the released health lock for B at ' . $fault);
                fwrite($pipes[0], "continue\n"); fclose($pipes[0]);
                if ($fault === 'readback-obstructed') {
                    $diagnostic = (string) fgets($pipes[2]);
                    aht_assert((int) $db->pdo->query("SELECT COUNT(*) FROM probe_kiwi_operational_events WHERE event_type='retention_archive_corruption_detected' AND lifecycle_action IN ('raised','repeated')")->fetchColumn() === 1,
                        'technical recovery failure creates no new completed-non-ok corruption finding');
                    aht_assert(str_contains($diagnostic, 'retaining generation lock'),
                        'unavailable file restoration reports locally while retaining the existing lock');
                    $busy = (new Kiwi_Retention_Archive_Lock())->acquire_for_archive($f['path']);
                    aht_assert(empty($busy['acquired']), 'other processes cannot use A while its file protection is unconfirmed');
                    rmdir($f['path'] . '.lock.write-blocked');
                }
                $recovered = json_decode(stream_get_contents($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
                fclose($pipes[1]); fclose($pipes[2]); proc_close($child); $child = null;
                aht_assert($recovered['_exit_code'] === 2, 'lost recovery ownership never reports success at ' . $fault);
                clearstatcache(true, $f['path'] . '.lock.write-blocked');
                $open = $event_service->get_open_incidents(['event_type' => 'retention_archive_corruption_detected']);
                aht_assert(is_file($f['path'] . '.lock.write-blocked') || !empty($open),
                    'A keeps effective corruption protection while B is Pending after ' . $fault);
                if (!str_starts_with($fault, 'readback')) {
                    aht_assert(!empty($open), 'the critical Incident INSERT cannot resolve protection after ownership loss at ' . $fault);
                } else {
                    aht_assert(is_file($f['path'] . '.lock.write-blocked'), 'unconfirmed post-INSERT recovery restores the source file gate');
                }
                aht_assert($daily->status()['state']['active_attempt_id'] === $new_id, 'failed recovery preserves the independently started B attempt');
                $daily->abort($new_id, true);
            } finally {
                if (is_resource($child)) { proc_terminate($child, 9); foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } } proc_close($child); }
            }
            // After explicit resolution of B, a new operator recovery must succeed through the same guarded SQL path.
            $retry = proc_open([PHP_BINARY, __FILE__, '--recover-paused', $database, $f['path']],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $retry_pipes);
            if (!is_resource($retry)) { throw new RuntimeException('Synthetic recovery retry did not start.'); }
            fwrite($retry_pipes[0], "continue\n"); fclose($retry_pipes[0]);
            $answer = json_decode(stream_get_contents($retry_pipes[1]), true, 512, JSON_THROW_ON_ERROR);
            fclose($retry_pipes[1]); stream_get_contents($retry_pipes[2]); fclose($retry_pipes[2]);
            $retry_exit = proc_close($retry);
            aht_assert($retry_exit === 0 && $answer['_exit_code'] === 0, 'explicit retry completes the guarded recovery after ' . $fault);
            clearstatcache(true, $f['path'] . '.lock.write-blocked');
            aht_assert(!is_file($f['path'] . '.lock.write-blocked')
                && $event_service->get_open_incidents(['event_type' => 'retention_archive_corruption_detected']) === [],
                'only confirmed retry clears both source protections after ' . $fault);
        } finally { $GLOBALS['wpdb'] = $previous_wpdb; }
    }
    echo 'ARCHIVE_HEALTH_STATE_INTEGRATION_PASSED ', $GLOBALS['aht_checks'], "\n";
} finally {
    $admin->exec('DROP DATABASE ' . $database);
    kiwi_remove_directory($f['root']);
}
