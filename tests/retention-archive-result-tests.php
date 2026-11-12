<?php

function kiwi_test_result_export(array $fixture, string $id): array
{
    [$exit, $wire] = kiwi_test_health_transport($fixture, 'export', json_encode([
        'schema_version' => 1, 'attempt_id' => $id, 'start_kind' => 'manual',
        'generation' => basename($fixture['path']), 'expected_bytes' => filesize($fixture['path']),
    ]));
    kiwi_assert_same(0, $exit, 'The small source export must complete before a result can be submitted.');
    $header_end = strpos($wire, "\n");
    $header = json_decode(substr($wire, 0, $header_end), true, 512, JSON_THROW_ON_ERROR);
    $proof = json_decode(substr($wire, $header_end + 1 + $header['bytes']), true, 512, JSON_THROW_ON_ERROR);
    return ['schema_version' => 1, 'attempt_id' => $id, 'generation' => $header['generation'],
        'received_bytes' => $proof['bytes'], 'sha256' => $proof['sha256'], 'transfer_complete' => true,
        'check' => 'quick_check', 'check_completed' => true, 'outcome' => 'ok'];
}

kiwi_run_test('Kiwi_Operational_Event_Service confirms recovery readback and retries uncertain closure without duplicate history', function (): void {
    $repository = new Kiwi_Test_Freshness_Event_Repository();
    $service = new Kiwi_Operational_Event_Service($repository);
    $event = ['area' => 'retention', 'severity' => 'error', 'event_type' => 'retention_archive_health_unavailable',
        'correlation_key' => 'synthetic_recovery_readback', 'message' => 'Synthetic unavailable operation.'];
    kiwi_assert_same('none', $service->record_recovery_action($event), 'No open incident requires no routine success event.');
    kiwi_assert_same([], $repository->rows, 'No-open recovery stays side-effect free.');
    kiwi_assert_same('raised', $service->record_failure_action($event), 'The prior failure is confirmed.');
    $repository->fail_readback = true;
    kiwi_assert_same('', $service->record_recovery_action($event), 'An unreadable recovery cannot be reported as confirmed.');
    $repository->fail_readback = false;
    kiwi_assert_same('none', $service->record_recovery_action($event), 'Retry reads the actually stored resolution and needs no second close event.');
    kiwi_assert_same(['raised', 'resolved'], array_column(array_values($repository->rows), 'lifecycle_action'), 'Unknown acknowledgement never duplicates append-only recovery.');
    kiwi_assert_same([], $service->get_open_incidents(), 'The original resolution remains effective.');
});

kiwi_run_test('Archive results accept a source-bound completed ok and retain its confirmed receipt', function (): void {
    $f = kiwi_test_attempt_service();
    $id = str_repeat('1', 32);
    $request = kiwi_test_result_export($f, $id);
    $result = $f['service']->accept_result($request);
    kiwi_assert_same(0, $result['_exit_code'], 'A complete matching result is accepted.');
    kiwi_assert_same('accepted', $result['result'], 'The acknowledgement confirms processing.');
    kiwi_assert_same('ok', $result['outcome'], 'The accepted finding is separate from processing success.');
    $status = $f['service']->status($id);
    kiwi_assert_same(null, $status['state']['active_attempt_id'], 'Pending ends after confirmed acceptance.');
    kiwi_assert_same($id, $status['state']['last_confirmed_attempt_id'], 'The confirmed pointer selects this attempt.');
    kiwi_assert_same('closed', $status['attempt']['status'], 'The completed attempt remains available to status.');
    kiwi_assert_same($status['attempt']['accepted_at'], $result['accepted_at'], 'Acknowledgement uses the stored time.');
    kiwi_assert_same($status['attempt']['result_fingerprint'], $result['result_fingerprint'], 'Status exposes the same receipt identity.');
    kiwi_assert_same([], $f['events']->rows, 'Ordinary ok has no routine success event.');
    kiwi_assert_same(false, $result['protection']['pending'], 'The receipt exposes remaining generation protection.');
});

kiwi_run_test('Archive results preserve corruption fallback and never overwrite an unfinished corruption finding', function (): void {
    foreach (['sentinel', 'incident', 'both'] as $fault) {
        $f = kiwi_test_attempt_service();
        $id = str_repeat('7', 32);
        $request = kiwi_test_result_export($f, $id); $request['outcome'] = 'corruption';
        $marker = $f['path'] . '.lock.write-blocked';
        if ($fault !== 'incident') { mkdir($marker); }
        if ($fault !== 'sentinel') { $f['events']->fail_next_insert = true; }
        $response = $f['service']->accept_result($request);
        kiwi_assert_same($fault === 'both' ? 2 : 0, $response['_exit_code'], 'At least one confirmed durable corruption gate is necessary: ' . $fault);
        $status = $f['service']->status($id);
        if ($fault === 'both') {
            kiwi_assert_same('corruption', $status['attempt']['outcome'], 'The finding survives failed protection effects.');
            kiwi_assert_same('open', $status['attempt']['status'], 'Unsecured corruption retains Pending.');
            kiwi_assert_same(2, $f['service']->fail_attempt($id, 'result', 'Synthetic abort.')['_exit_code'], 'Technical closure cannot overwrite known corruption.');
            kiwi_assert_same(2, $f['service']->abort($id, true)['_exit_code'], 'Manual abort cannot overwrite known corruption.');
        }
        if (is_dir($marker)) { rmdir($marker); }
        if ($fault === 'sentinel') {
            kiwi_assert_same(true, $f['service']->status($id)['target']['protection']['incident_open'], 'The incident alone remains a durable gate after the blocked sentinel path is removed.');
        }
        if ($fault === 'incident') {
            kiwi_assert_same(true, is_file($marker), 'The durable sentinel protects when incident insertion fails.');
        }
        $f['events']->fail_next_insert = false;
        kiwi_assert_same(0, $f['service']->accept_result($request)['_exit_code'], 'The same result can safely finish or confirm its original processing.');
        kiwi_assert_same(null, $f['service']->status($id)['state']['active_attempt_id'], 'Confirmed fallback releases only Pending.');
    }
});

kiwi_run_test('Archive results bind a year-rollover finding to its original generation and preserve independent gates', function (): void {
    $f = kiwi_test_attempt_service();
    $id = str_repeat('8', 32);
    $request = kiwi_test_result_export($f, $id);
    $next = $f['root'] . '/kiwi_retention_archive_2027.sqlite';
    copy($f['path'], $next);
    $f['archive']->archive_files[] = ['name' => basename($next), 'path' => $next];
    file_put_contents($f['path'] . '.lock.write-blocked', "kiwi_retention_archive_write_blocked_v1\n");
    $accepted = $f['service']->accept_result($request);
    kiwi_assert_same(0, $accepted['_exit_code'], 'An already selected generation remains the result target.');
    kiwi_assert_same(basename($f['path']), $accepted['generation'], 'A result for A is not a finding for B.');
    kiwi_assert_same(true, $accepted['protection']['corruption_write_blocked'], 'Ordinary ok never clears independent corruption protection.');
    kiwi_assert_same(basename($next), $f['service']->status()['target']['generation'], 'Current target selection can independently advance.');
    $new_id = str_repeat('9', 32);
    $second = $f['service']->with_new_attempt($new_id, 'manual', basename($next), filesize($next),
        function () use ($f, $request): array { return $f['service']->accept_result($request); });
    kiwi_assert_same(0, $second['_exit_code'], 'Old closed duplicate acknowledgement requires no new result effects.');
    kiwi_assert_same($new_id, $f['service']->status()['state']['active_attempt_id'], 'Old duplicate cannot close the new attempt.');
    kiwi_assert_same($id, $f['service']->status()['state']['last_confirmed_attempt_id'], 'New open attempt is not promoted by an old acknowledgement.');
});

kiwi_run_test('Archive results reject foreign partial malformed and invalidated findings without effects', function (): void {
    $f = kiwi_test_attempt_service();
    $id = str_repeat('5', 32);
    $request = kiwi_test_result_export($f, $id);
    $before = $f['service']->status($id);
    foreach ([['attempt_id' => str_repeat('6', 32)], ['generation' => 'kiwi_retention_archive_2027.sqlite'],
        ['received_bytes' => $request['received_bytes'] + 1], ['received_bytes' => (float) $request['received_bytes']],
        ['sha256' => str_repeat('0', 64)], ['sha256' => strtoupper($request['sha256'])],
        ['transfer_complete' => false], ['check_completed' => false], ['check' => 'integrity_check'],
        ['outcome' => 'timeout'], ['schema_version' => 2], ['extra' => 'field']] as $invalid) {
        kiwi_assert_same(2, $f['service']->accept_result(array_replace($request, $invalid))['_exit_code'], 'Unverified or foreign evidence is not accepted.');
        kiwi_assert_same($before, $f['service']->status($id), 'Rejected evidence leaves authoritative state unchanged.');
        kiwi_assert_same([], $f['events']->rows, 'Rejected evidence introduces no new incident taxonomy.');
    }
    kiwi_assert_same(0, $f['service']->abort($id, true)['_exit_code'], 'Explicit abort safely invalidates this attempt.');
    $aborted = $f['service']->status($id);
    $events = $f['events']->rows;
    kiwi_assert_same(2, $f['service']->accept_result($request)['_exit_code'], 'A late valid copy cannot revive an invalidated attempt.');
    kiwi_assert_same($aborted, $f['service']->status($id), 'Late arrival preserves the abort.');
    kiwi_assert_same($events, $f['events']->rows, 'Late arrival creates no additional event.');

    $partial = kiwi_test_attempt_service();
    $partial['service']->with_new_attempt($id, 'manual', basename($partial['path']), filesize($partial['path']));
    kiwi_assert_same(2, $partial['service']->accept_result($request)['_exit_code'], 'Client proof cannot replace missing own source proof.');
    kiwi_assert_same($id, $partial['service']->status()['state']['active_attempt_id'], 'An incomplete source remains Pending.');
});

kiwi_run_test('Archive results keep Pending through required event and final state failures', function (): void {
    foreach (['event', 'result-write', 'closure-write', 'result-readback', 'closure-readback'] as $fault) {
        $f = kiwi_test_attempt_service();
        $f['service']->fail_attempt(null, 'start', 'Synthetic prior failure.');
        usleep(1000);
        $id = str_repeat('6', 32);
        $request = kiwi_test_result_export($f, $id);
        if ($fault === 'event') { $f['events']->fail_next_insert = true; }
        $write_phase = 0;
        if ($fault !== 'event') {
            $f['db']->before_query = static function ($sql, $db) use ($fault, &$write_phase): void {
                if (strpos($sql, 'SELECT GET_LOCK') === 0) { $write_phase++; }
                $target = strpos($fault, 'closure') === 0 ? 2 : 1;
                if ($write_phase === $target && substr($fault, -5) === 'write' && strpos($sql, 'UPDATE fixture_options') === 0) {
                    $db->fail_matching = 'UPDATE fixture_options';
                }
                if ($write_phase === $target && substr($fault, -8) === 'readback' && $sql === 'COMMIT') {
                    $db->fail_matching = 'SELECT option_value';
                }
            };
        }
        kiwi_assert_same(2, $f['service']->accept_result($request)['_exit_code'], 'Unconfirmed processing never sends accepted: ' . $fault);
        $f['db']->before_query = null; $f['db']->fail_matching = ''; $f['events']->fail_next_insert = false;
        $status = $f['service']->status($id);
        if ($fault === 'closure-readback') {
            kiwi_assert_same('closed', $status['attempt']['status'], 'A committed closure with lost readback can be discovered independently.');
        } else {
            kiwi_assert_same($id, $status['state']['active_attempt_id'], 'Incomplete required effects retain Pending: ' . $fault);
            kiwi_assert_same(null, $status['state']['last_confirmed_attempt_id'], 'Incomplete outcome is not the confirmed archive finding.');
        }
        $received_at = $status['attempt']['received_at'];
        kiwi_assert_same(0, $f['service']->accept_result($request)['_exit_code'], 'An identical result finishes only missing effects.');
        $finished = $f['service']->status($id);
        if ($received_at !== null) { kiwi_assert_same($received_at, $finished['attempt']['received_at'], 'Original receipt time survives retry.'); }
        kiwi_assert_same(['raised', 'resolved'], array_column(array_values($f['events']->rows), 'lifecycle_action'), 'A retry does not duplicate resolved history.');
    }
});

kiwi_run_test('Archive results reject contradictory persisted JSON instead of clearing Pending', function (): void {
    foreach (['active_attempt_id', '\\u0061ctive_attempt_id'] as $key) {
        $f = kiwi_test_attempt_service();
        kiwi_test_result_export($f, str_repeat('4', 32));
        $raw = $f['db']->pdo->query("SELECT option_value FROM fixture_options WHERE option_name='kiwi_retention_archive_health_state'")->fetchColumn();
        $ambiguous = substr($raw, 0, -1) . ',"' . $key . '":null}';
        $write = $f['db']->pdo->prepare("UPDATE fixture_options SET option_value=? WHERE option_name='kiwi_retention_archive_health_state'");
        $write->execute([$ambiguous]);
        kiwi_assert_same(2, $f['service']->status()['_exit_code'], 'A shadowed persisted field is not a readable empty state.');
        kiwi_assert_same(false, $f['store']->pending_gate()['allowed'], 'Contradictory stored JSON never releases dependent work.');
    }
});

kiwi_run_test('Archive results accept completed corruption only with durable generation protection', function (): void {
    $f = kiwi_test_attempt_service();
    $id = str_repeat('3', 32);
    $request = kiwi_test_result_export($f, $id);
    $request['outcome'] = 'corruption';
    [$exit, $wire] = kiwi_test_health_transport($f, 'result', json_encode($request));
    kiwi_assert_same(0, $exit, 'Accepted corruption is a completed protocol operation.');
    $receipt = json_decode($wire, true, 512, JSON_THROW_ON_ERROR);
    kiwi_assert_same('corruption', $receipt['outcome'], 'The finding is explicitly corruption.');
    kiwi_assert_same(true, $receipt['protection']['corruption_write_blocked'], 'The generation write block is durable before acknowledgement.');
    kiwi_assert_same(true, $receipt['protection']['corruption_incident_open'], 'The existing critical incident is visible.');
    $status = $f['service']->status($id);
    kiwi_assert_same($id, $status['state']['last_confirmed_attempt_id'], 'A completed corruption check is a confirmed finding.');
    kiwi_assert_same(null, $status['state']['active_attempt_id'], 'Durable corruption protection can replace Pending.');
    kiwi_assert_same('sqlite_check_reported_corruption', $status['open_incidents'][0]['reason_code'], 'Existing corruption reason remains unchanged.');
    kiwi_assert_same(2, $f['service']->abort($id, true)['_exit_code'], 'Abort cannot overwrite saved corruption.');
    $events = $f['events']->rows;
    kiwi_assert_same(0, $f['service']->accept_result($request)['_exit_code'], 'A duplicate only repeats its original acknowledgement.');
    kiwi_assert_same($events, $f['events']->rows, 'Duplicate corruption produces no new incident row.');
});

kiwi_run_test('Archive results acknowledge reordered duplicate JSON without new time state or events', function (): void {
    $f = kiwi_test_attempt_service();
    $f['service']->fail_attempt(null, 'start', 'Synthetic unavailable start.');
    usleep(2000);
    $id = str_repeat('2', 32);
    $request = kiwi_test_result_export($f, $id);
    [$exit, $wire] = kiwi_test_health_transport($f, 'result', json_encode($request));
    kiwi_assert_same(0, $exit, 'The actual result transport acknowledges the first acceptance.');
    $first = json_decode($wire, true, 512, JSON_THROW_ON_ERROR);
    $before = $f['service']->status($id);
    $events = $f['events']->rows;
    kiwi_assert_same(['raised', 'resolved'], array_column(array_values($events), 'lifecycle_action'), 'A qualified result resolves the open failure once.');
    usleep(2000);
    [$again_exit, $again_wire] = kiwi_test_health_transport($f, 'result', json_encode(array_reverse($request, true), JSON_PRETTY_PRINT));
    kiwi_assert_same(0, $again_exit, 'Formatting does not make an identical result new.');
    kiwi_assert_same($first, json_decode($again_wire, true, 512, JSON_THROW_ON_ERROR), 'The original acceptance is acknowledged again.');
    kiwi_assert_same($before, $f['service']->status($id), 'Duplicate acceptance does not increment revision or refresh timestamps.');
    kiwi_assert_same($events, $f['events']->rows, 'Duplicate acceptance produces no additional events.');
});

kiwi_run_test('Archive results preserve newer Availability evidence and activation failures without changing accepted health', function (): void {
    $f = kiwi_test_attempt_service();
    $id = str_repeat('a', 32);
    $request = kiwi_test_result_export($f, $id);
    usleep(2000);
    $f['service']->fail_attempt(null, 'start', 'A later synthetic start could not proceed.');
    kiwi_assert_same(0, $f['service']->accept_result($request)['_exit_code'], 'An older complete result is still valid archive evidence.');
    $before = $f['service']->status($id);
    kiwi_assert_same(1, count($before['open_incidents']), 'Older ok cannot resolve a later-started Availability failure.');
    kiwi_assert_same(['raised'], array_column(array_values($f['events']->rows), 'lifecycle_action'), 'No false recovery is appended.');
    $state = $before['state']; $attempt = $before['attempt'];
    usleep(2000);
    [$exit, $wire] = kiwi_test_health_transport($f, 'fail', json_encode(['schema_version' => 1, 'attempt_id' => null,
        'phase' => 'activation', 'reason_code' => 'health_run_failed', 'message' => 'Synthetic resource limit setup failed.']));
    kiwi_assert_same(0, $exit, 'The existing fail command accepts an explicit technical activation failure.');
    $after = $f['service']->status($id);
    kiwi_assert_same($state, $after['state'], 'Activation No-Go creates no Pending or new freshness evidence.');
    kiwi_assert_same($attempt, $after['attempt'], 'The accepted finding and original times survive activation No-Go.');
    kiwi_assert_same($before['target']['protection'], $after['target']['protection'], 'Availability alone does not change retention protection.');
    kiwi_assert_same(['raised', 'repeated'], array_column(array_values($f['events']->rows), 'lifecycle_action'), 'Activation uses the existing append-only lifecycle.');
    kiwi_assert_same(0, $f['service']->accept_result($request)['_exit_code'], 'A closed duplicate can still confirm its original receipt.');
    kiwi_assert_same($after, $f['service']->status($id), 'A delayed duplicate cannot resolve activation No-Go.');
});

kiwi_run_test('Archive results resolve overdue monitoring once and retain freshness and old failure ordering', function (): void {
    foreach (['ok', 'corruption'] as $outcome) {
        $f = kiwi_test_freshness_service('2026-03-01T05:00:00Z');
        $f['service']->freshness(true, true);
        $f['clock']->now = new DateTimeImmutable('2026-03-09T05:00:00Z');
        kiwi_assert_same('overdue', $f['service']->freshness()['result'], 'The established deadline raises the existing overdue incident.');
        $old_id = str_repeat('b', 32);
        $f['service']->with_new_attempt($old_id, 'manual', basename($f['path']), filesize($f['path']));
        $f['service']->fail_attempt($old_id, 'check', 'Synthetic incomplete old scan.', 'health_child_timeout');
        usleep(2000);
        $f['clock']->now = new DateTimeImmutable('2026-03-10T05:00:00Z');
        $request = kiwi_test_result_export($f, str_repeat('c', 32)); $request['outcome'] = $outcome;
        kiwi_assert_same(0, $f['service']->accept_result($request)['_exit_code'], 'Both definitive findings resolve an older Availability incident.');
        $status = $f['service']->status();
        $availability = array_filter($status['open_incidents'], static fn($event) => $event['event_type'] === 'retention_archive_health_unavailable');
        kiwi_assert_same([], array_values($availability), 'No overdue or technical Availability incident remains.');
        kiwi_assert_same('2026-03-10T05:00:00.000000Z', $status['last_confirmed_attempt']['accepted_at'], 'Freshness uses the accepted result time.');
        $events = $f['events']->rows;
        kiwi_assert_same(1, count(array_filter($events, static fn($event) => $event['lifecycle_action'] === 'resolved')), 'Qualified recovery appends exactly one resolution.');
        $f['service']->fail_attempt($old_id, 'check', 'Late duplicate of the old incomplete scan.', 'health_child_timeout');
        kiwi_assert_same($events, $f['events']->rows, 'An already safely closed older failure never reopens recovered Availability.');
        $f['clock']->now = new DateTimeImmutable('2026-03-17T05:00:00Z');
        kiwi_assert_same('ok', $f['service']->freshness()['result'], 'Accepted corruption is complete evidence for monitoring freshness too.');
        kiwi_assert_same($events, $f['events']->rows, 'Ordinary freshness success adds no routine event.');
    }
});

kiwi_run_test('Archive results never replay old corruption effects after verified manual recovery', function (): void {
    $f = kiwi_test_attempt_service();
    $id = str_repeat('d', 32);
    $request = kiwi_test_result_export($f, $id); $request['outcome'] = 'corruption';
    $accepted = $f['service']->accept_result($request);
    kiwi_assert_same(0, $accepted['_exit_code'], 'Synthetic completed corruption is accepted before recovery.');
    $recovery = new Kiwi_Retention_Archive_Health_Service(archive_service: $f['archive'], health_store: $f['store'],
        operational_event_service: new Kiwi_Operational_Event_Service($f['events']),
        run_repository: new Kiwi_Test_Retention_Cleanup_Run_Repository(),
        check_runner: static fn() => ['result' => 'ok', 'check_completed' => true, 'reason_code' => 'sqlite_check_ok']);
    kiwi_assert_same(0, $recovery->unblock(basename($f['path']), '', true)['_exit_code'], 'Explicit verified repair removes the prior corruption protection.');
    $status = $f['service']->status($id); $events = $f['events']->rows;
    $duplicate = $f['service']->accept_result($request);
    kiwi_assert_same(0, $duplicate['_exit_code'], 'Old closed finding only confirms its original processing.');
    kiwi_assert_same($accepted['accepted_at'], $duplicate['accepted_at'], 'Recovery does not redate old acceptance.');
    kiwi_assert_same(false, $duplicate['protection']['corruption_write_blocked'], 'The receipt accurately reflects the later repaired protection.');
    kiwi_assert_same(false, $duplicate['protection']['corruption_incident_open'], 'Old corruption never reopens the repaired incident.');
    kiwi_assert_same($status, $f['service']->status($id), 'No state or protection changes on the old duplicate.');
    kiwi_assert_same($events, $f['events']->rows, 'No repeated or raised corruption is appended.');
});

kiwi_run_test('Archive results keep actual retention writes and deletes protected through failed and corrupt completion', function (): void {
    global $wpdb;
    $previous = $wpdb ?? null;
    try {
        $wpdb = (object) ['prefix' => 'wp_'];
        foreach (['ok', 'corruption'] as $outcome) {
            $source = 'landing_page_sessions';
            $GLOBALS['kiwi_test_transients'] = [];
            $GLOBALS['kiwi_test_options'] = ['kiwi_retention_settings' => [$source => ['enabled' => true, 'dry_run' => false, 'retention_days' => 21]]];
            $f = kiwi_test_attempt_service();
            $archive = $f['archive']; $archive->new_archive_db_path = $f['path'];
            $archive->archived_primary_keys = [1, 2];
            $archive->chunks[] = ['success' => true, 'archive_db_path' => $f['path'], 'archived_rows' => 2,
                'archive_inserted_rows' => 2, 'archive_duplicate_rows' => 0, 'archived_primary_keys' => [1, 2],
                'last_primary_key' => 2, 'has_more' => false];
            $cleanup = new Kiwi_Test_Retention_Cleanup_Service(new Kiwi_Config(), new Kiwi_Retention_Source_Registry(),
                new Kiwi_Test_Retention_Cleanup_Run_Repository(), new Kiwi_Test_Retention_Table_Growth_Snapshot_Repository(),
                $archive, new Kiwi_Test_Retention_Coverage_Gate(['status' => 'passed']),
                corruption_safety_gate: new Kiwi_Retention_Corruption_Safety_Gate_Coordinator(
                    operational_event_service: new Kiwi_Operational_Event_Service($f['events']), health_store: $f['store']),
                health_store: $f['store']);
            $cleanup->eligible_rows = 2; $cleanup->target_max_primary_key = 2;
            $cleanup->delete_result = ['deleted_rows' => 2, 'delete_batches' => 1];
            $cleanup->run_source($source, 'cron');
            $request = kiwi_test_result_export($f, str_repeat('e', 32)); $request['outcome'] = $outcome;
            $f['db']->fail_matching = 'UPDATE fixture_options';
            kiwi_assert_same(2, $f['service']->accept_result($request)['_exit_code'], 'Failed result storage retains the attempt.');
            $f['db']->fail_matching = '';
            kiwi_assert_same('health_attempt_pending', $cleanup->run_worker($source)['error_code'], 'The existing worker sees durable Pending after result failure.');
            kiwi_assert_same([], $archive->chunk_calls, 'No archive write is permitted while result storage is uncertain.');
            kiwi_assert_same([], $cleanup->deleted_primary_keys, 'No source row is deleted while result storage is uncertain.');
            kiwi_assert_same(0, $f['service']->accept_result($request)['_exit_code'], 'The same complete finding can be accepted after storage recovery.');
            $worker = $cleanup->run_worker($source);
            if ($outcome === 'corruption') {
                kiwi_assert_same('archive_corruption_write_blocked', $worker['error_code'], 'Durable corruption protection takes over after Pending ends.');
                kiwi_assert_same([], $archive->chunk_calls, 'Confirmed corruption still prevents archive writes.');
                kiwi_assert_same([], $cleanup->deleted_primary_keys, 'Confirmed corruption still prevents source deletes.');
            } else {
                kiwi_assert_same(1, count($archive->chunk_calls), 'Accepted ok permits the existing receipt-backed worker to resume.');
                $cleanup->run_worker($source);
                kiwi_assert_same([1, 2], $cleanup->deleted_primary_keys, 'Accepted ok permits only the normal receipt-verified deletes.');
            }
        }
    } finally { $wpdb = $previous; }
});
