<?php

class Kiwi_Test_Freshness_Event_Repository extends Kiwi_Test_One_Failure_Operational_Event_Repository
{
    public $fail_readback = false;
    private $unreadable = false;

    public function insert_event(array $event): int
    {
        $id = parent::insert_event($event);
        $this->unreadable = $this->fail_readback;
        return $id;
    }

    public function find_latest_by_correlation_key(string $key): ?array
    {
        if ($this->unreadable) {
            $this->unreadable = false;
            throw new RuntimeException('Synthetic readback failed: password=never-log-this');
        }
        return parent::find_latest_by_correlation_key($key);
    }
}

function kiwi_test_freshness_service(string $now): array
{
    $f = kiwi_test_attempt_service();
    $f['events'] = new Kiwi_Test_Freshness_Event_Repository();
    $f['clock'] = (object) ['now' => new DateTimeImmutable($now)];
    $f['service'] = new Kiwi_Retention_Archive_Health_Service(archive_service: $f['archive'],
        operational_event_service: new Kiwi_Operational_Event_Service($f['events']),
        run_repository: new Kiwi_Test_Retention_Cleanup_Run_Repository(), health_store: $f['store'],
        clock: static fn() => $f['clock']->now,
        check_runner: static function () use ($f): array { $f['probe']->scans++; throw new RuntimeException('Unexpected archive scan.'); });
    return $f;
}

/** Stored version-1 result fixture; actual authenticated acceptance belongs to ticket #162. */
function kiwi_test_freshness_result_fixture(array $f, string $id, string $outcome, bool $closed = true): void
{
    $started = $f['service']->with_new_attempt($id, 'scheduled', basename($f['path']), filesize($f['path']));
    kiwi_assert_same(0, $started['_exit_code'], 'Result fixture requires its real durable attempt.');
    $f['store']->update($id, static function (array $view) use ($f, $id, $outcome, $closed): array {
        $state = $view['state'];
        $attempt = $view['attempt'];
        $now = $f['clock']->now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $attempt['received_bytes'] = $attempt['expected_bytes'];
        $attempt['sha256'] = str_repeat('a', 64);
        $attempt['transfer_complete'] = true;
        $attempt['transfer_completed_at'] = $now;
        $attempt['outcome'] = $outcome;
        $attempt['result_fingerprint'] = str_repeat('b', 64);
        $attempt['received_at'] = $now;
        if ($closed) {
            $attempt['status'] = 'closed';
            $attempt['accepted_at'] = $now;
            $attempt['closed_at'] = $now;
            $state['active_attempt_id'] = null;
            $state['last_confirmed_attempt_id'] = $id;
        }
        return ['state' => $state, 'attempt' => $attempt];
    });
}

kiwi_run_test('Archive freshness activates explicitly once and keeps daily execution separate from archive evidence', function (): void {
    $f = kiwi_test_freshness_service('2026-03-20T22:30:00Z');
    kiwi_assert_same(2, $f['service']->freshness()['_exit_code'], 'Unactivated monitoring must not silently start.');
    kiwi_assert_same(2, $f['service']->freshness(true)['_exit_code'], 'First activation requires confirmation.');
    kiwi_assert_same(null, $f['service']->status()['state']['activated_at'], 'Rejected commands leave activation absent.');
    kiwi_assert_same(0, $f['service']->freshness(true, true)['_exit_code'], 'Confirmed activation also completes the daily check.');
    $activated = $f['service']->status();
    kiwi_assert_same('2026-03-20T22:30:00.000000Z', $activated['state']['activated_at'], 'Activation is durable UTC.');
    kiwi_assert_same('2026-03-20T22:30:00.000000Z', $activated['state']['last_freshness_completed_at'], 'Only the daily execution is confirmed.');
    kiwi_assert_same(null, $activated['last_confirmed_attempt'], 'Activation is not an archive finding.');
    $f['clock']->now = new DateTimeImmutable('2026-03-23T05:00:00Z');
    kiwi_assert_same(0, $f['service']->freshness(true, true)['_exit_code'], 'Repeated confirmed activation remains usable.');
    $status = $f['service']->status();
    kiwi_assert_same($activated['state']['activated_at'], $status['state']['activated_at'], 'Activation must never reset.');
    kiwi_assert_same('2026-03-23T05:00:00.000000Z', $status['state']['last_freshness_completed_at'], 'Daily execution has its own timestamp.');
    kiwi_assert_same([], $status['open_incidents'], 'Ordinary fresh execution creates no event.');
    kiwi_assert_same(0, $f['probe']->scans, 'Freshness never starts SQLite.');
});

kiwi_run_test('Archive freshness only fully accepted VPS findings reset the later weekly deadline', function (): void {
    foreach (['ok', 'corruption'] as $outcome) {
        $f = kiwi_test_freshness_service('2026-03-01T05:00:00Z');
        $f['service']->freshness(true, true);
        $f['clock']->now = new DateTimeImmutable('2026-03-08T05:00:00Z');
        $f['service']->fail_attempt(null, 'start', 'Synthetic skipped start.');
        $failed_id = str_repeat('3', 32);
        $f['service']->with_new_attempt($failed_id, 'scheduled', basename($f['path']), filesize($f['path']));
        $f['service']->fail_attempt($failed_id, 'check', 'Synthetic incomplete scan.', 'health_child_timeout');
        $abort_id = str_repeat('4', 32);
        $f['service']->with_new_attempt($abort_id, 'manual', basename($f['path']), filesize($f['path']));
        $f['service']->abort($abort_id, true);
        $diagnosis = new Kiwi_Retention_Archive_Health_Service(archive_service: $f['archive'], health_store: $f['store'],
            operational_event_service: new Kiwi_Operational_Event_Service($f['events']),
            check_runner: static fn() => ['result' => 'ok', 'reason_code' => 'sqlite_check_ok', 'check_completed' => true]);
        kiwi_assert_same('ok', $diagnosis->diagnose(basename($f['path']), 'quick')['result'], 'Small diagnostic boundary succeeds without VPS acceptance.');
        $f['clock']->now = new DateTimeImmutable('2026-03-09T05:00:00Z');
        kiwi_assert_same('overdue', $f['service']->freshness()['result'], 'Skip, timeout, abort and local diagnosis do not reset activation day.');
        kiwi_assert_same(null, $f['service']->status()['last_confirmed_attempt'], 'No technical or diagnostic outcome becomes confirmed evidence.');
        $f['clock']->now = new DateTimeImmutable('2026-03-10T23:15:00Z');
        $id = str_repeat('5', 32);
        kiwi_test_freshness_result_fixture($f, $id, $outcome);
        if ($outcome === 'corruption') {
            file_put_contents($f['path'] . '.lock.write-blocked', "kiwi_retention_archive_write_blocked_v1\n");
            (new Kiwi_Operational_Event_Service($f['events']))->record_failure([
                'area' => 'retention', 'severity' => 'critical', 'event_type' => 'retention_archive_corruption_detected',
                'correlation_key' => 'corruption_fixture', 'message' => 'Synthetic confirmed corruption.',
            ]);
        }
        $accepted = $f['service']->status()['last_confirmed_attempt'];
        $f['clock']->now = new DateTimeImmutable('2026-03-18T05:00:00Z');
        kiwi_assert_same('ok', $f['service']->freshness()['result'], 'Both complete outcomes reset the Berlin date to March 11.');
        $f['clock']->now = new DateTimeImmutable('2026-03-19T05:00:00Z');
        kiwi_assert_same('overdue', $f['service']->freshness()['result'], 'An absent later weekly run becomes overdue on March 19.');
        $status = $f['service']->status();
        kiwi_assert_same($accepted, $status['last_confirmed_attempt'], 'Daily checks do not redatestamp or replace confirmed evidence.');
        kiwi_assert_same('2026-03-01T05:00:00.000000Z', $status['state']['activated_at'], 'The original activation survives later findings.');
        if ($outcome === 'corruption') {
            kiwi_assert_same(false, $status['target']['protection']['allowed'], 'A fresh corruption finding retains its write block.');
            kiwi_assert_true(in_array('critical', array_column($status['open_incidents'], 'severity'), true), 'Daily monitoring retains the independent corruption incident.');
        }
    }
});

kiwi_run_test('Archive freshness does not count a stored definitive finding before safe acceptance', function (): void {
    $f = kiwi_test_freshness_service('2026-03-01T05:00:00Z');
    $f['service']->freshness(true, true);
    $f['clock']->now = new DateTimeImmutable('2026-03-08T05:00:00Z');
    kiwi_test_freshness_result_fixture($f, str_repeat('6', 32), 'ok', false);
    $f['clock']->now = new DateTimeImmutable('2026-03-09T05:00:00Z');
    kiwi_assert_same('overdue', $f['service']->freshness()['result'], 'An open result without safe acceptance is not a freshness anchor.');
    $status = $f['service']->status();
    kiwi_assert_same(str_repeat('6', 32), $status['state']['active_attempt_id'], 'Daily monitoring cannot release Pending.');
    kiwi_assert_same(null, $status['last_confirmed_attempt'], 'The incomplete acceptance remains unconfirmed.');
});

kiwi_run_test('Archive freshness reads a concurrently accepted result without overwriting its newer state', function (): void {
    $f = kiwi_test_freshness_service('2026-03-01T05:00:00Z');
    $f['service']->freshness(true, true);
    $f['clock']->now = new DateTimeImmutable('2026-03-09T05:00:00Z');
    $id = str_repeat('8', 32);
    $f['db']->before_query = static function (string $sql, $db) use ($f, $id): void {
        if (strpos($sql, 'SELECT GET_LOCK(') !== 0) { return; }
        $db->before_query = null;
        kiwi_test_freshness_result_fixture($f, $id, 'ok');
    };
    kiwi_assert_same('ok', $f['service']->freshness()['result'], 'A completed result just before the daily lock prevents a stale overdue event.');
    $status = $f['service']->status();
    kiwi_assert_same($id, $status['state']['last_confirmed_attempt_id'], 'Daily completion preserves the newer result reference.');
    kiwi_assert_same(1, $status['state']['last_attempt_sequence'], 'Daily completion preserves the attempt sequence.');
    kiwi_assert_same([], $status['open_incidents'], 'No stale error is inserted from the older activation date.');
});

kiwi_run_test('Archive freshness failed storage or event confirmation leaves the prior daily completion intact', function (): void {
    foreach (['event_write', 'event_readback', 'state_write', 'state_read', 'lost_lock'] as $fault) {
        $f = kiwi_test_freshness_service('2026-03-01T05:00:00Z');
        $f['service']->freshness(true, true);
        $before = $f['service']->status()['state'];
        $f['clock']->now = new DateTimeImmutable('2026-03-09T05:00:00Z');
        if ($fault === 'event_write') { $f['events']->fail_next_insert = true; }
        if ($fault === 'event_readback') { $f['events']->fail_readback = true; }
        if ($fault === 'state_write') { $f['db']->fail_matching = 'UPDATE fixture_options'; }
        if ($fault === 'state_read') { $f['db']->fail_matching = 'SELECT option_value'; }
        if ($fault === 'lost_lock') {
            $f['db']->before_query = static function (string $sql, $db): void {
                if ($sql === 'START TRANSACTION') { $db->owner = null; }
            };
        }
        $log = $f['root'] . '/php-error.log';
        $previous_log = ini_get('error_log');
        ini_set('error_log', $log);
        try { $failed = $f['service']->freshness(); } finally { ini_set('error_log', $previous_log); }
        $f['db']->fail_matching = ''; $f['db']->before_query = null;
        kiwi_assert_same(2, $failed['_exit_code'], 'No daily acknowledgement after ' . $fault);
        kiwi_assert_same($before, $f['service']->status()['state'], 'Failed daily job must not update execution evidence: ' . $fault);
        $diagnosis = file_get_contents($log);
        kiwi_assert_true(strpos($diagnosis, 'Archive health freshness did not complete:') !== false, 'Failure is diagnosed in PHP log.');
        kiwi_assert_same(false, strpos($diagnosis, 'never-log-this'), 'Raw database failures are not exposed.');
        foreach ($f['events']->rows as $event) {
            kiwi_assert_same('health_check_overdue', json_decode($event['context_json'], true)['reason_code'], 'No extra daily-job incident is created.');
        }
    }
});

kiwi_run_test('Archive freshness status shows only current retention errors and preserves closed history', function (): void {
    $f = kiwi_test_freshness_service('2026-03-01T05:00:00Z');
    $events = new Kiwi_Operational_Event_Service($f['events']);
    $base = ['area' => 'retention', 'event_type' => 'retention_fixture', 'message' => 'Synthetic status incident.'];
    $events->record_failure($base + ['severity' => 'warning', 'correlation_key' => 'only_warning']);
    $events->record_failure($base + ['severity' => 'critical', 'correlation_key' => 'corruption_fixture']);
    $events->record_failure($base + ['severity' => 'error', 'correlation_key' => 'closed_fixture']);
    $events->record_recovery($base + ['severity' => 'info', 'correlation_key' => 'closed_fixture']);
    $status = $f['service']->status();
    kiwi_assert_same(['corruption_fixture'], array_column($status['open_incidents'], 'correlation_key'), 'Only latest raised/repeated error or critical entries are open.');
    kiwi_assert_same(null, $status['state']['last_freshness_completed_at'], 'Even known incidents do not prove that a daily check ran.');
    kiwi_assert_same(4, count($f['events']->rows), 'Read-only status preserves the append-only history.');
});

kiwi_run_test('Archive freshness operator command validates activation flags and preserves process exit evidence', function (): void {
    foreach (['freshness_activate' => 0, 'freshness' => 0, 'freshness_unconfirmed' => 2,
        'freshness_unknown' => 2, 'freshness_flag_value' => 2, 'freshness_confirm_only' => 2] as $case => $exit) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/retention-health-command.php', $case],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        kiwi_assert_true(is_resource($process), 'CLI fixture must run.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        kiwi_assert_same($exit, proc_close($process), 'Actual CLI freshness exit: ' . $case . ' ' . $error);
        $result = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
        kiwi_assert_same('freshness', $result['command'], 'Errors must identify the daily job, not a full archive scan.');
        kiwi_assert_same(false, array_key_exists('_exit_code', $result), 'Exit status is communicated by the process.');
        if ($exit === 0) {
            kiwi_assert_true(is_string($result['state']['activated_at']), 'Successful invocation exposes persistent activation.');
            kiwi_assert_true(is_string($result['state']['last_freshness_completed_at']), 'Successful invocation exposes completion.');
        } else {
            kiwi_assert_true(strpos($error, 'Archive health freshness did not complete:') !== false, 'Failed daily job uses the PHP error log.');
        }
    }
});

kiwi_run_test('Archive freshness reports a never started check on Berlin day eight across calendar transitions', function (): void {
    foreach ([
        ['2026-01-31T22:59:00Z', '2026-02-07T05:00:00Z', '2026-02-08T05:00:00Z'],
        ['2026-12-31T23:15:00Z', '2027-01-08T05:00:00Z', '2027-01-09T05:00:00Z'],
        ['2026-03-21T23:15:00Z', '2026-03-29T05:00:00Z', '2026-03-30T05:00:00Z'],
        ['2026-10-18T21:59:00Z', '2026-10-25T05:00:00Z', '2026-10-26T05:00:00Z'],
    ] as [$activation, $day_seven, $day_eight]) {
        $f = kiwi_test_freshness_service($activation);
        $f['service']->freshness(true, true);
        $f['clock']->now = new DateTimeImmutable($day_seven);
        kiwi_assert_same('ok', $f['service']->freshness()['result'], 'Seven Berlin calendar days are not overdue.');
        kiwi_assert_same([], $f['service']->status()['open_incidents'], 'No failure before day eight.');
        $f['clock']->now = new DateTimeImmutable($day_eight);
        $result = $f['service']->freshness();
        kiwi_assert_same('overdue', $result['result'], 'Day eight reports overdue even before 192 elapsed hours.');
        kiwi_assert_same(0, $result['_exit_code'], 'A durably reported overdue finding is a completed daily job.');
        $status = $f['service']->status();
        kiwi_assert_same(null, $status['state']['last_confirmed_attempt_id'], 'A never started week remains without archive evidence.');
        kiwi_assert_same(1, count($status['open_incidents']), 'One existing Availability lifecycle is open.');
        $event = $status['open_incidents'][0];
        kiwi_assert_same('retention_archive_health_unavailable', $event['event_type'], 'Use the existing Availability type.');
        kiwi_assert_same('error', $event['severity'], 'Overdue is a technical error.');
        kiwi_assert_same('health_check_overdue', $event['reason_code'], 'Expose the agreed reason.');
        kiwi_assert_same('Seit mindestens acht Kalendertagen liegt kein bestätigter Archivbefund vor.', $event['message'], 'Operator sees the agreed readable message.');
        kiwi_assert_same(true, $status['target']['protection']['allowed'], 'Overdue alone does not block retention.');
    }
});
