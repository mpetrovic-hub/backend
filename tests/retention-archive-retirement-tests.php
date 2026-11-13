<?php

kiwi_run_test('Archive retirement refuses every regular check without reading or changing protection', function (): void {
    foreach (['quick', 'integrity', '', 'unsupported'] as $mode) {
        $f = kiwi_test_attempt_service();
        $before = $f['service']->status();
        $events = $f['events']->rows;

        $result = $f['service']->check($mode);

        kiwi_assert_same(0, $f['probe']->scans, 'A retired regular start must not invoke a Hostinger SQLite scan.');
        kiwi_assert_same(2, $result['_exit_code'], 'An old regular command is refused, never acknowledged as a healthy check.');
        kiwi_assert_same('error', $result['result'], 'The refusal uses the existing error response.');
        kiwi_assert_same('health_run_failed', $result['reason_code'], 'Retirement adds no new event reason taxonomy.');
        kiwi_assert_true(strpos($result['message'] ?? '', 'kiwi-retention-archive-health run --manual') !== false,
            'The old command explains the explicit new VPS start.');
        kiwi_assert_same($before, $f['service']->status(), 'Retirement preserves durable state and protection.');
        kiwi_assert_same($events, $f['events']->rows, 'Retirement produces no false Availability or success history.');
    }
});

kiwi_run_test('Archive retirement keeps Pending confirmed results and independent corruption protection', function (): void {
    foreach (['pending', 'ok', 'corruption', 'replacement'] as $state) {
        $f = kiwi_test_attempt_service();
        $id = str_repeat('f', 32);
        $request = kiwi_test_result_export($f, $id);
        if ($state !== 'pending') {
            $request['outcome'] = $state === 'replacement' ? 'ok' : $state;
            kiwi_assert_same(0, $f['service']->accept_result($request)['_exit_code'], 'Prepare a confirmed finding.');
        }
        if ($state === 'replacement') {
            kiwi_assert_same(true, Kiwi_Retention_Archive_Write_Block::persist_replacement_transition(
                $f['path'] . '.lock', 'kiwi_retention_archive_2025.sqlite'), 'Prepare the independent replacement gate.');
        }
        $before = $f['service']->status($id); $events = $f['events']->rows;
        foreach (['quick', 'integrity'] as $mode) {
            kiwi_assert_same(2, $f['service']->check($mode)['_exit_code'], 'The retired start cannot bypass protection.');
            kiwi_assert_same($before, $f['service']->status($id), 'Retirement changes neither Pending nor confirmed evidence or corruption gates.');
            kiwi_assert_same($events, $f['events']->rows, 'Retirement cannot resolve or repeat existing Incidents.');
        }
        kiwi_assert_same(0, $f['probe']->scans, 'No retained diagnosis scanner is used as an implicit fallback.');
    }
});

kiwi_run_test('Archive retirement CLI refuses old modes and bootstrap failure creates no regular incident', function (): void {
    foreach (['retired_quick', 'retired_integrity', 'retired_missing', 'retired_invalid', 'retired_missing_class'] as $case) {
        $process = kiwi_run_retention_process([PHP_BINARY, __DIR__ . '/fixtures/retention-health-command.php', $case]);
        kiwi_assert_same(2, $process['exit_code'], 'The actual old CLI process refuses: ' . $case);
        $result = json_decode(trim($process['stdout']), true, 512, JSON_THROW_ON_ERROR);
        kiwi_assert_same('check', $result['command'], 'The response identifies the retired command.');
        kiwi_assert_same(false, isset($result['_exit_code']), 'The exit code belongs to the process.');
        kiwi_assert_same(false, isset($result['incident_action']), 'Old bootstrap cannot produce ordinary-check Availability events.');
        if ($case === 'retired_missing_class') {
            kiwi_assert_same('required_class_missing', $result['reason_code'], 'Bootstrap reports its own sanitized failure, without a scheduled-check fallback.');
        } else {
            kiwi_assert_same('health_run_failed', $result['reason_code'], 'Retirement keeps the existing technical reason catalogue.');
            kiwi_assert_true(strpos($result['message'] ?? '', 'kiwi-retention-archive-health run --manual') !== false, 'The CLI directs explicit starts to the VPS.');
        }
    }
});

kiwi_run_test('Archive retirement keeps actual retained bootstrap command identity', function (): void {
    $recorder = new Kiwi_Retention_Archive_Health_Bootstrap_Recorder();
    foreach (['diagnose', 'unblock', 'status', 'abort', 'ssh', 'freshness'] as $command) {
        $result = $recorder->record('required_class_missing', $command);
        kiwi_assert_same($command, $result['command'], 'Retained bootstrap failures must not be labeled as retired checks.');
        kiwi_assert_same(2, $result['_exit_code'], 'Bootstrap failure remains a technical error.');
    }
});

kiwi_run_test('Archive retirement preserves target and unreadable-protection refusal at the export boundary', function (): void {
    foreach (['lookup', 'discovery', 'transition', 'incident'] as $fault) {
        $f = kiwi_test_attempt_service();
        $before = $f['store']->read();
        $runs = $fault === 'lookup' ? new Kiwi_Test_Failing_Archive_State_Run_Repository() : new Kiwi_Test_Retention_Cleanup_Run_Repository();
        $archive = $fault === 'discovery' ? new Kiwi_Test_Failing_Archive_Discovery_Service() : $f['archive'];
        $events = $fault === 'incident' ? new Kiwi_Test_Unreadable_Operational_Event_Service() : new Kiwi_Operational_Event_Service($f['events']);
        if ($fault === 'transition') {
            file_put_contents(Kiwi_Retention_Archive_Write_Block::get_replacement_transition_path($f['path'] . '.lock'), "truncated-transition-state\n");
        }
        $f['service'] = new Kiwi_Retention_Archive_Health_Service(archive_service: $archive, health_store: $f['store'],
            run_repository: $runs, operational_event_service: $events,
            check_runner: static function (): array { throw new RuntimeException('Unexpected Production scan.'); });
        [$exit, $wire] = kiwi_test_health_transport($f, 'export', json_encode(['schema_version' => 1,
            'attempt_id' => str_repeat('e', 32), 'start_kind' => 'manual', 'generation' => basename($f['path']),
            'expected_bytes' => filesize($f['path'])]));
        kiwi_assert_same(2, $exit, 'Unreadable source/protection refuses the actual export: ' . $fault);
        kiwi_assert_same(false, strpos($wire, "SQLite format 3\0") !== false, 'Rejected source cannot emit archive bytes.');
        kiwi_assert_same(false, strpos($wire, '"type":"complete"') !== false, 'No false source proof is emitted.');
        kiwi_assert_same($before, $f['store']->read(), 'Rejected start creates no attempt or confirmed health.');
        kiwi_assert_same([], $f['events']->rows, 'Read/export refusal itself never invents corruption evidence.');
    }
});
