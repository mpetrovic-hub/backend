<?php

require_once __DIR__ . '/fixtures/retention-health-state-wpdb.php';

kiwi_run_test('Archive attempts operator commands preserve status abort and process exit contract', function (): void {
    foreach (['status' => 0, 'attempt' => 0, 'abort' => 2, 'confirmed' => 0, 'unknown_argument' => 2] as $case => $exit) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/retention-health-command.php', $case],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        kiwi_assert_true(is_resource($process), 'CLI fixture must run.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        kiwi_assert_same($exit, proc_close($process), 'Actual CLI process exit: ' . $case . ' ' . $error);
        $result = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
        kiwi_assert_same(false, array_key_exists('_exit_code', $result), 'Exit code belongs to the process, not output JSON.');
        if ($case === 'status' || $case === 'attempt') {
            kiwi_assert_same(str_repeat('7', 32), $result['state']['active_attempt_id'], 'Status must expose the exact durable attempt.');
            kiwi_assert_same(null, $result['state']['last_freshness_completed_at'], 'Status does not invent a daily run.');
        }
        if ($case === 'attempt') { kiwi_assert_same(str_repeat('7', 32), $result['attempt']['attempt_id'], 'Explicit attempt status selects the requested record.'); }
        if ($case === 'confirmed') { kiwi_assert_same('manual_abort', $result['attempt']['outcome'], 'Confirmed abort follows the shared durable lifecycle.'); }
    }
});

kiwi_run_test('Archive attempts status distinguishes provisioned idle state from missing storage', function (): void {
    $events = new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository());
    $store = new Kiwi_Retention_Archive_Health_Store(new Kiwi_Test_Health_State_Wpdb());
    $service = new Kiwi_Retention_Archive_Health_Service(operational_event_service: $events, health_store: $store);
    $status = $service->status();
    kiwi_assert_same(0, $status['_exit_code'], 'Prepared idle state must be readable.');
    kiwi_assert_same(null, $status['state']['active_attempt_id'], 'Prepared idle has no Pending.');
    kiwi_assert_same(null, $status['state']['last_freshness_completed_at'], 'Status must not invent daily execution.');
    $missing = new Kiwi_Retention_Archive_Health_Service(operational_event_service: $events,
        health_store: new Kiwi_Retention_Archive_Health_Store(new Kiwi_Test_Health_State_Wpdb(false)));
    kiwi_assert_same(2, $missing->status()['_exit_code'], 'Missing state must fail closed without initialization.');
});

kiwi_run_test('Archive attempts Pending prevents recovery before any verification scan', function (): void {
    $f = kiwi_test_attempt_service();
    $id = str_repeat('f', 32);
    $f['service']->with_new_attempt($id, 'manual', basename($f['path']), filesize($f['path']));
    $result = $f['service']->unblock(basename($f['path']), '', true);
    kiwi_assert_same('health_attempt_pending', $result['reason_code'], 'Unblock must require a separate safe abort.');
    kiwi_assert_same(0, $f['probe']->scans, 'Recovery must not start verification while any attempt is open.');
});

kiwi_run_test('Archive attempts replacement rechecks Pending after acquiring both generation locks', function (): void {
    $f = kiwi_test_attempt_service();
    $replacement = $f['root'] . '/kiwi_retention_archive_2026_part_2.sqlite';
    copy($f['path'], $replacement);
    $f['archive']->archive_files[] = ['name' => basename($replacement), 'path' => $replacement];
    $f['archive']->new_archive_db_path = $replacement;
    file_put_contents($f['path'] . '.lock.write-blocked', "kiwi_retention_archive_write_blocked_v1\n");
    $lock = new class extends Kiwi_Retention_Archive_Lock {
        public $before_acquire;
        public function acquire_for_archive(string $path): array {
            if ($this->before_acquire !== null) { ($this->before_acquire)($path); }
            return parent::acquire_for_archive($path);
        }
    };
    $id = str_repeat('6', 32);
    $lock->before_acquire = static function (string $path) use ($lock, $f, $replacement, $id): void {
        if ($path !== $replacement) { return; }
        $lock->before_acquire = null;
        $start = $f['service']->with_new_attempt($id, 'manual', basename($replacement), filesize($replacement));
        kiwi_assert_same(0, $start['_exit_code'], 'Fixture establishes Pending between source and replacement acquisition.');
    };
    $runs = new class extends Kiwi_Test_Retention_Cleanup_Run_Repository {
        public function terminalize_open_run_for_manual_replacement(string $old, string $replacement): bool { return true; }
    };
    $recovery = new Kiwi_Retention_Archive_Health_Service(archive_service: $f['archive'], lock_service: $lock,
        operational_event_service: new Kiwi_Operational_Event_Service($f['events']),
        run_repository: $runs, health_store: $f['store'],
        check_runner: static fn() => ['result' => 'ok', 'reason_code' => 'sqlite_check_ok', 'check_completed' => true]);
    $result = $recovery->unblock(basename($f['path']), basename($replacement), true);
    kiwi_assert_same('health_attempt_pending', $result['reason_code'], 'Replacement must refuse an intervening open attempt.');
    kiwi_assert_same($id, $f['service']->status()['state']['active_attempt_id'], 'Replacement must preserve the concurrent Pending.');
    kiwi_assert_same(true, is_file($f['path'] . '.lock.write-blocked'), 'Source corruption block remains untouched.');
});

kiwi_run_test('Archive attempts Pending blocks and resumes Session and Handoff archive and delete calls', function (): void {
    global $wpdb;
    $previous = $wpdb ?? null;
    try {
        $wpdb = (object) ['prefix' => 'wp_'];
        foreach ([['landing_page_sessions', 'write'], ['landing_page_sessions', 'receipt'],
            ['landing_handoff_events', 'write'], ['landing_handoff_events', 'receipt']] as [$source, $boundary]) {
            $GLOBALS['kiwi_test_transients'] = [];
            $GLOBALS['kiwi_test_options'] = ['kiwi_retention_settings' => [$source => ['enabled' => true, 'dry_run' => false, 'retention_days' => 21]]];
            $f = kiwi_test_attempt_service();
            $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
            $archive = $f['archive'];
            $archive->new_archive_db_path = $f['path'];
            $archive->archived_primary_keys = [1, 2];
            $archive->chunks[] = ['success' => true, 'archive_db_path' => $f['path'],
                'archived_rows' => 2, 'archive_inserted_rows' => 2, 'archive_duplicate_rows' => 0,
                'archived_primary_keys' => [1, 2], 'last_primary_key' => 2, 'has_more' => false];
            $corruption_gate = new Kiwi_Test_Sequenced_Retention_Corruption_Safety_Gate_Coordinator();
            $corruption_gate->block_on_inspection = $boundary === 'receipt' ? 3 : 0;
            $cleanup = new Kiwi_Test_Retention_Cleanup_Service(new Kiwi_Config(), new Kiwi_Retention_Source_Registry(), $runs,
                new Kiwi_Test_Retention_Table_Growth_Snapshot_Repository(), $archive,
                new Kiwi_Test_Retention_Coverage_Gate(['status' => 'passed']),
                corruption_safety_gate: $corruption_gate, health_store: $f['store']);
            $cleanup->eligible_rows = 2; $cleanup->target_max_primary_key = 2;
            $cleanup->delete_result = ['deleted_rows' => 2, 'delete_batches' => 1];
            $cleanup->run_source($source, 'cron');
            if ($boundary === 'receipt') {
                $cleanup->run_worker($source);
                kiwi_assert_same(1, count($archive->chunk_calls), 'Fixture pauses after a committed receipt.');
                kiwi_assert_same([], $cleanup->deleted_primary_keys, 'Fixture retains receipt-backed rows for resumed delete.');
            }
            $id = bin2hex(random_bytes(16));
            $f['service']->with_new_attempt($id, 'manual', basename($f['path']), filesize($f['path']));
            $blocked = $cleanup->run_worker($source);
            kiwi_assert_same('health_attempt_pending', $blocked['error_code'] ?? '', 'Pending must be observed at existing worker boundary: ' . $source);
            kiwi_assert_same($boundary === 'receipt' ? 1 : 0, count($archive->chunk_calls), 'Pending prevents new archive writes: ' . $source);
            kiwi_assert_same([], $cleanup->deleted_primary_keys, 'Pending prevents deletes: ' . $source);
            $raw = $f['db']->pdo->query("SELECT option_value FROM fixture_options WHERE option_name='kiwi_retention_archive_health_attempt_" . $id . "'")->fetchColumn();
            $malformed = json_decode($raw, true); $malformed['generation'] .= ' ';
            $f['db']->pdo->exec('UPDATE fixture_options SET option_value = ' . $f['db']->pdo->quote(json_encode($malformed))
                . " WHERE option_name='kiwi_retention_archive_health_attempt_" . $id . "'");
            $unreadable = $cleanup->run_worker($source);
            kiwi_assert_same('health_state_unavailable', $unreadable['error_code'] ?? '', 'Malformed generation cannot turn Pending into a clear gate.');
            kiwi_assert_same([], $cleanup->deleted_primary_keys, 'Unclear state preserves receipt-backed rows.');
            $f['db']->pdo->exec('UPDATE fixture_options SET option_value = ' . $f['db']->pdo->quote($raw)
                . " WHERE option_name='kiwi_retention_archive_health_attempt_" . $id . "'");
            $f['service']->abort($id, true);
            $cleanup->run_worker($source);
            kiwi_assert_true(count($archive->chunk_calls) >= 1, 'Safe abort permits resumed archive: ' . $source);
            kiwi_assert_same([1, 2], array_values(array_unique($cleanup->deleted_primary_keys)), 'Receipt-backed delete resumes: ' . $source);
        }
    } finally { $wpdb = $previous; }
});

kiwi_run_test('Archive attempts failed writes reconnects and uncertain commit never release an export', function (): void {
    foreach (['INSERT INTO', 'COMMIT', 'reconnect', 'readback'] as $fault) {
        $f = kiwi_test_attempt_service();
        $used = false;
        if (in_array($fault, ['INSERT INTO', 'COMMIT'], true)) { $f['db']->fail_matching = $fault; }
        if ($fault === 'reconnect') {
            $f['db']->before_query = static function ($sql, $db): void {
                if (strpos($sql, 'INSERT INTO') === 0) { $db->connection++; $db->owner = null; $db->before_query = null; }
            };
        }
        if ($fault === 'readback') {
            $f['db']->before_query = static function ($sql, $db): void {
                if ($sql === 'COMMIT') {
                    $db->before_query = static function ($sql, $db): void {
                        if (strpos($sql, 'SELECT option_value') === 0) { $db->fail_matching = 'SELECT option_value'; }
                    };
                }
            };
        }
        $result = $f['service']->with_new_attempt(str_repeat('d', 32), 'manual', basename($f['path']), filesize($f['path']),
            static function () use (&$used): array { $used = true; return ['_exit_code' => 0]; });
        kiwi_assert_same(2, $result['_exit_code'], 'Fault must fail the command: ' . $fault);
        kiwi_assert_same(false, $used, 'No archive operation may occur without confirmed begin: ' . $fault);
        $f['db']->fail_matching = ''; $f['db']->before_query = null;
        $status = $f['service']->status();
        kiwi_assert_same($fault === 'readback' ? str_repeat('d', 32) : null, $status['state']['active_attempt_id'],
            'An uncertain committed begin retains Pending; failed transaction leaves no attempt.');
    }
});

kiwi_run_test('Archive attempts exact state comparison rejects stale concurrent mutation', function (): void {
    $f = kiwi_test_attempt_service();
    $mutated = false;
    $f['db']->before_query = static function ($sql, $db) use (&$mutated): void {
        if (!$mutated && strpos($sql, 'UPDATE fixture_options SET') === 0) {
            $mutated = true;
            $db->pdo->exec("UPDATE fixture_options SET option_value = replace(option_value, '\"revision\":0', '\"revision\":9') WHERE option_name='kiwi_retention_archive_health_state'");
        }
    };
    $result = $f['service']->with_new_attempt(str_repeat('e', 32), 'manual', basename($f['path']), filesize($f['path']));
    kiwi_assert_same(2, $result['_exit_code'], 'CAS must not overwrite a changed previous JSON value.');
    kiwi_assert_same(null, $f['service']->status()['state']['active_attempt_id'], 'State and attempt writes roll back together.');
});

kiwi_run_test('Archive attempts explicit abort stores invalidation and availability before releasing Pending', function (): void {
    $f = kiwi_test_attempt_service();
    $id = str_repeat('c', 32);
    $f['service']->with_new_attempt($id, 'manual', basename($f['path']), filesize($f['path']));
    kiwi_assert_same(2, $f['service']->abort($id, false)['_exit_code'], 'Manual abort requires confirmation.');
    $f['events']->fail_next_insert = true;
    $failed = $f['service']->abort($id, true);
    kiwi_assert_same(2, $failed['_exit_code'], 'Unsaved availability must not release Pending.');
    kiwi_assert_same($id, $f['service']->status()['state']['active_attempt_id'], 'Pending survives incomplete effects.');
    $f['events']->fail_next_insert = false;
    $finished = $f['service']->abort($id, true);
    kiwi_assert_same(0, $finished['_exit_code'], 'Retry must safely finish the same invalidation.');
    $saved = $f['service']->status($id);
    kiwi_assert_same(null, $saved['state']['active_attempt_id'], 'Confirmed abort releases only Pending.');
    kiwi_assert_same('closed', $saved['attempt']['status'], 'Closed state remains readable.');
    kiwi_assert_same('manual_abort', $saved['attempt']['outcome'], 'Outcome is distinct from closed.');
    kiwi_assert_same(null, $saved['state']['last_confirmed_attempt_id'], 'Abort does not manufacture a checked archive.');
    kiwi_assert_same(1, count($f['events']->rows), 'Exactly one availability event survives retry.');
    $again = $f['service']->abort($id, true);
    kiwi_assert_same($saved['attempt']['closed_at'], $again['attempt']['closed_at'], 'A lost acknowledgement preserves original closure time.');
    kiwi_assert_same(1, count($f['events']->rows), 'Repeated confirmation must not write another event.');
});

kiwi_run_test('Archive attempts malformed or contradictory persisted state remains blocked', function (): void {
    $f = kiwi_test_attempt_service();
    $id = str_repeat('9', 32);
    $f['service']->with_new_attempt($id, 'manual', basename($f['path']), filesize($f['path']));
    $initial = $f['service']->status($id);
    foreach (['missing field', 'incompatible version', 'invalid calendar', 'invalidated without outcome',
        'result without outcome', 'closed while active', 'unaccepted confirmed reference', 'sequence conflict'] as $fault) {
        $state = $initial['state']; $attempt = $initial['attempt'];
        if ($fault === 'missing field') { unset($attempt['received_at']); }
        if ($fault === 'incompatible version') { $state['format_version'] = 2; }
        if ($fault === 'invalid calendar') { $attempt['started_at'] = '2026-02-30T12:00:00Z'; }
        if ($fault === 'invalidated without outcome') { $attempt['invalidated_at'] = '2026-11-12T12:00:00Z'; }
        if ($fault === 'result without outcome') { $attempt['result_fingerprint'] = str_repeat('a', 64); $attempt['received_at'] = $attempt['started_at']; }
        if ($fault === 'closed while active') { $attempt['status'] = 'closed'; $attempt['closed_at'] = $attempt['started_at']; }
        if ($fault === 'unaccepted confirmed reference') { $state['last_confirmed_attempt_id'] = $id; }
        if ($fault === 'sequence conflict') { $attempt['attempt_sequence']++; }
        foreach ([Kiwi_Retention_Archive_Health_Store::STATE_OPTION => $state,
            Kiwi_Retention_Archive_Health_Store::ATTEMPT_PREFIX . $id => $attempt] as $name => $data) {
            $statement = $f['db']->pdo->prepare('UPDATE fixture_options SET option_value = ? WHERE option_name = ?');
            $statement->execute([json_encode($data), $name]);
        }
        kiwi_assert_same(2, $f['service']->status($id)['_exit_code'], 'Operator status must reject ' . $fault);
        kiwi_assert_same('health_state_unavailable', $f['store']->pending_gate(basename($f['path']))['reason_code'], 'Unclear state must block work: ' . $fault);
    }
});

kiwi_run_test('Archive attempts abort cannot overwrite stored corruption or remove other protection', function (): void {
    $f = kiwi_test_attempt_service();
    $id = str_repeat('8', 32);
    $f['service']->with_new_attempt($id, 'manual', basename($f['path']), filesize($f['path']));
    $attempt = $f['service']->status($id)['attempt'];
    $attempt['received_bytes'] = $attempt['expected_bytes'];
    $attempt['sha256'] = str_repeat('a', 64);
    $attempt['transfer_complete'] = true;
    $attempt['transfer_completed_at'] = $attempt['started_at'];
    $attempt['outcome'] = 'corruption';
    $attempt['result_fingerprint'] = str_repeat('b', 64);
    $attempt['received_at'] = $attempt['started_at'];
    $statement = $f['db']->pdo->prepare('UPDATE fixture_options SET option_value = ? WHERE option_name = ?');
    $statement->execute([json_encode($attempt), Kiwi_Retention_Archive_Health_Store::ATTEMPT_PREFIX . $id]);
    kiwi_assert_same(2, $f['service']->abort($id, true)['_exit_code'], 'Stored corruption requires its original result effects, not abort.');
    kiwi_assert_same($attempt, $f['service']->status($id)['attempt'], 'Abort must not overwrite the evidence or close Pending.');

    $other = kiwi_test_attempt_service();
    $other['service']->with_new_attempt($id, 'manual', basename($other['path']), filesize($other['path']));
    file_put_contents($other['path'] . '.lock.write-blocked', "kiwi_retention_archive_write_blocked_v1\n");
    kiwi_assert_same(0, $other['service']->abort($id, true)['_exit_code'], 'Safe abort can close Pending independently of corruption protection.');
    kiwi_assert_same(false, $other['service']->status()['target']['protection']['allowed'], 'Abort leaves an independent corruption write block intact.');
});

function kiwi_test_attempt_service(?Kiwi_Test_Health_State_Wpdb $db = null): array
{
    $db = $db ?? new Kiwi_Test_Health_State_Wpdb();
    $root = sys_get_temp_dir() . '/kiwi-attempt-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    register_shutdown_function(static function () use ($root): void { kiwi_remove_directory($root); });
    $path = $root . '/kiwi_retention_archive_2026.sqlite';
    $sqlite = new PDO('sqlite:' . $path);
    $sqlite->exec('CREATE TABLE fixture (id INTEGER PRIMARY KEY)');
    $sqlite = null;
    $archive = new Kiwi_Test_Retention_Sqlite_Archive_Service();
    $archive->archive_files = [['name' => basename($path), 'path' => $path]];
    $events = new Kiwi_Test_One_Failure_Operational_Event_Repository();
    $store = new Kiwi_Retention_Archive_Health_Store($db);
    $probe = (object) ['scans' => 0];
    $service = new Kiwi_Retention_Archive_Health_Service(archive_service: $archive,
        operational_event_service: new Kiwi_Operational_Event_Service($events),
        run_repository: new Kiwi_Test_Retention_Cleanup_Run_Repository(), health_store: $store,
        check_runner: static function () use ($probe): array { $probe->scans++; return ['status' => 'ok', 'completed' => true]; });
    return compact('db', 'root', 'path', 'archive', 'events', 'store', 'service', 'probe');
}

kiwi_run_test('Archive attempts begin is durable before use and rejects another start', function (): void {
    $fixture = kiwi_test_attempt_service();
    $id = str_repeat('a', 32);
    $service = $fixture['service'];
    $observed = false;
    $result = $service->with_new_attempt($id, 'manual', basename($fixture['path']), filesize($fixture['path']),
        function (array $attempt, string $path, Kiwi_Retention_Archive_Lock_Handle $lock) use ($service, &$observed, $id): array {
            $status = $service->status($id);
            kiwi_assert_same($id, $status['state']['active_attempt_id'], 'Pending must already be durable before using the file.');
            kiwi_assert_same('open', $status['attempt']['status'], 'Start must remain open.');
            $probe = (new Kiwi_Retention_Archive_Lock())->acquire_for_archive($path);
            kiwi_assert_same(false, $probe['acquired'], 'The actual OS lock remains held.');
            $observed = true;
            return ['result' => 'started', '_exit_code' => 0];
        });
    kiwi_assert_same(0, $result['_exit_code'], 'Begin must confirm successful storage.');
    kiwi_assert_same(true, $observed, 'The operation must run only after persistence.');
    $second = $service->with_new_attempt(str_repeat('b', 32), 'manual', basename($fixture['path']), filesize($fixture['path']));
    kiwi_assert_same(2, $second['_exit_code'], 'Only one attempt may be open.');
    kiwi_assert_same(1, $service->status()['state']['last_attempt_sequence'], 'Rejected start cannot consume a sequence.');
});
