<?php

require_once __DIR__ . '/../includes/services/class-retention-archive-health-transport.php';

function kiwi_test_health_transport(array $fixture, string $action, string $request, array $limits = []): array
{
    $input = fopen('php://temp', 'w+b');
    fwrite($input, $request); rewind($input);
    $output = fopen('php://temp', 'w+b');
    $transport = new Kiwi_Retention_Archive_Health_Transport($fixture['service'], $limits);
    $exit = $transport->serve($action, $input, $output);
    rewind($output); $wire = stream_get_contents($output);
    fclose($input); fclose($output);
    return [$exit, $wire];
}

kiwi_run_test('Archive transport accepts only bounded versioned flat requests and reads status without mutation', function (): void {
    $f = kiwi_test_attempt_service();
    $before = $f['service']->status();
    [$exit, $wire] = kiwi_test_health_transport($f, 'status', '{"schema_version":1,"attempt_id":null}');
    kiwi_assert_same(0, $exit, 'A valid restricted status call succeeds.');
    $status = json_decode($wire, true, 512, JSON_THROW_ON_ERROR);
    kiwi_assert_same(basename($f['path']), $status['target']['generation'], 'Production selects the target.');
    kiwi_assert_same(filesize($f['path']), $status['target']['bytes'], 'Status returns current bytes without a scan.');
    foreach ([['status --attempt=x', '{}'], ['unblock', '{}'], ['status', '{"schema_version":2,"attempt_id":null}'],
        ['status', '{"schema_version":1,"attempt_id":null,"path":"/tmp/other"}'],
        ['status', '{"schema_version":1,"schema_version":1,"attempt_id":null}'],
        ['status', '{"schema_version":1,"attempt_id":null,"\\u0061ttempt_id":null}'],
        ['status', '{"schema_version":1,"attempt_id":[]}'], ['status', str_repeat(' ', 16385)],
        ['status', '{"schema_version":1,"attempt_id":null} trailing'], ['status', '[]']] as [$action, $request]) {
        [$bad_exit, $bad_wire] = kiwi_test_health_transport($f, $action, $request);
        kiwi_assert_same(2, $bad_exit, 'Unrecognized actions/fields, ambiguous JSON and oversized requests are refused.');
        kiwi_assert_same('error', json_decode($bad_wire, true)['result'], 'Rejection is a compact JSON error.');
    }
    kiwi_assert_same($before['state'], $f['service']->status()['state'], 'Read and invalid requests never mutate state.');
    kiwi_assert_same(0, $f['probe']->scans, 'Status never scans the archive.');
});

kiwi_run_test('Archive transport forced-command template refuses arguments and shell text before bootstrap', function (): void {
    $wrapper = __DIR__ . '/../tools/database/archive-health-access/production-forced-command.sh.example';
    foreach (['', 'unblock', 'status --attempt=other', 'export; printf injected', "result\nprintf injected", ' fail'] as $command) {
        $process = proc_open(['sh', $wrapper], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, ['SSH_ORIGINAL_COMMAND' => $command, 'PATH' => '/usr/bin:/bin']);
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        kiwi_assert_same(2, proc_close($process), 'The fixed entry rejects nonliteral action text before invoking PHP.');
        kiwi_assert_same('', $stdout . $stderr, 'No supplied shell text executes and bootstrap is not reached.');
    }
    $process = proc_open(['sh', $wrapper, 'extra'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, null, ['SSH_ORIGINAL_COMMAND' => 'status', 'PATH' => '/usr/bin:/bin']);
    foreach ($pipes as $pipe) { fclose($pipe); }
    kiwi_assert_same(2, proc_close($process), 'The installed forced wrapper accepts no arguments.');
});

kiwi_run_test('Archive transport keeps control responses bounded separately from small requests', function (): void {
    $f = kiwi_test_attempt_service();
    for ($i = 1; $i <= 100; $i++) {
        $f['events']->rows[$i] = ['id' => $i, 'area' => 'retention', 'event_type' => 'retention_archive_health_unavailable',
            'severity' => 'error', 'lifecycle_action' => 'raised', 'correlation_key' => str_repeat('a', 190) . $i,
            'reference_type' => 'retention_archive', 'reference_id' => basename($f['path']),
            'occurred_at' => '2026-11-12 00:00:00', 'context_json' => ['reason_code' => 'health_run_failed']];
    }
    [$exit, $wire] = kiwi_test_health_transport($f, 'status', '{"schema_version":1,"attempt_id":null}');
    kiwi_assert_same(0, $exit, 'One hundred ordinary incidents fit the control-response allowance.');
    kiwi_assert_true(strlen($wire) > 16384 && strlen($wire) <= 262144, 'Response allowance is distinct from the request bound.');
    $f['events']->rows[100]['context_json']['reason_code'] = str_repeat('x', 262144);
    [$large_exit, $large_wire] = kiwi_test_health_transport($f, 'status', '{"schema_version":1,"attempt_id":null}');
    kiwi_assert_same(2, $large_exit, 'Unexpectedly oversized stored data must not become unbounded protocol output.');
    kiwi_assert_true(strlen($large_wire) < 1024, 'Oversized status is replaced by a short error before output.');
});

kiwi_run_test('Archive transport bounds stalled input and output and retains uncertain transfer state', function (): void {
    foreach (['input-stall', 'status-stall', 'stalled', 'replace', 'grow', 'shrink', 'sidecar', 'empty-sidecar', 'failed-closure', 'proof-failure', 'proof-readback'] as $case) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/retention-health-transport.php', $case],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        kiwi_assert_true(is_resource($process), 'Controlled source fixture starts.');
        stream_set_timeout($pipes[2], 3);
        $meta = json_decode(fgets($pipes[2]), true, 512, JSON_THROW_ON_ERROR);
        $started = microtime(true); $wire = '';
        if ($case === 'input-stall') { fwrite($pipes[0], '{'); }
        elseif ($case === 'status-stall') { fwrite($pipes[0], '{"schema_version":1,"attempt_id":null}'); fclose($pipes[0]); }
        else {
            fwrite($pipes[0], json_encode(['schema_version' => 1, 'attempt_id' => str_repeat('5', 32), 'start_kind' => 'manual',
                'generation' => basename($meta['path']), 'expected_bytes' => $meta['bytes']])); fclose($pipes[0]);
            if ($case !== 'stalled') {
                $wire = fgets($pipes[1]);
                if ($case === 'replace') {
                    $bytes = file_get_contents($meta['path']); rename($meta['path'], $meta['path'] . '.old');
                    file_put_contents($meta['path'], $bytes);
                }
                if ($case === 'grow') { file_put_contents($meta['path'], 'x', FILE_APPEND); }
                if ($case === 'shrink') { file_put_contents($meta['path'], ''); }
                if ($case === 'empty-sidecar') { file_put_contents($meta['path'] . '-wal', ''); }
                if ($case === 'sidecar' || $case === 'failed-closure') { file_put_contents($meta['path'] . '-wal', 'changed'); }
            }
        }
        // In the stalled case stdout is deliberately never drained until the source
        // has ended. A blocking fwrite would hang beyond this independent test guard.
        $status_line = fgets($pipes[2]);
        if ($status_line === false) { proc_terminate($process, 9); }
        if ($case === 'input-stall') { fclose($pipes[0]); }
        $wire .= stream_get_contents($pipes[1]); fclose($pipes[1]);
        $diagnosis = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($process);
        kiwi_assert_true($status_line !== false, 'Source must end while its peer stalls: ' . $case);
        kiwi_assert_same(2, $exit, 'Unsafe source/transport has unsuccessful process exit: ' . $case . ' ' . $diagnosis);
        kiwi_assert_same(false, strpos($wire, '"type":"complete"') !== false, 'No terminal proof for an incomplete export: ' . $case);
        $status = json_decode($status_line, true, 512, JSON_THROW_ON_ERROR);
        $uncertain = in_array($case, ['failed-closure', 'proof-failure', 'proof-readback'], true);
        kiwi_assert_same($uncertain ? str_repeat('5', 32) : null, $status['state']['active_attempt_id'],
            'Only confirmed technical closure releases Pending: ' . $case);
        if (in_array($case, ['input-stall', 'status-stall', 'stalled'], true)) {
            kiwi_assert_true(microtime(true) - $started < 2, 'Stalled I/O obeys the short configured wall-clock deadline.');
        }
        if (!in_array($case, ['input-stall', 'status-stall'], true)) {
            kiwi_assert_same($case === 'proof-readback', $status['attempt']['transfer_complete'],
                'Only a committed proof survives an uncertain response: ' . $case);
        }
    }
});

kiwi_run_test('Archive transport actual SSH entry holds the OS lock and Pending through the raw stream', function (): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/retention-health-transport.php', 'normal'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    kiwi_assert_true(is_resource($process), 'The source command starts.');
    $meta = json_decode(fgets($pipes[2]), true, 512, JSON_THROW_ON_ERROR);
    fwrite($pipes[0], json_encode(['schema_version' => 1, 'attempt_id' => str_repeat('5', 32), 'start_kind' => 'manual',
        'generation' => basename($meta['path']), 'expected_bytes' => $meta['bytes']])); fclose($pipes[0]);
    $header = fgets($pipes[1]);
    kiwi_assert_same('archive', json_decode($header, true)['type'] ?? null, 'Actual command streams a protocol header.');
    $started = microtime(true);
    $pid = proc_get_status($process)['pid'];
    usleep(150000);
    $positions = [];
    foreach (glob('/proc/' . $pid . '/fd/*') ?: [] as $fd) {
        if (@readlink($fd) === $meta['path']) {
            preg_match('/^pos:\s*(\d+)/m', file_get_contents('/proc/' . $pid . '/fdinfo/' . basename($fd)), $match);
            $positions[] = (int) $match[1];
        }
    }
    kiwi_assert_same([0], $positions, 'Source pacing precedes reading the first block, not just sending it.');
    $lock = (new Kiwi_Retention_Archive_Lock())->acquire_for_archive($meta['path']);
    kiwi_assert_same(false, $lock['acquired'], 'Another process cannot acquire the source generation during transfer.');
    $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $diagnosis = stream_get_contents($pipes[2]); fclose($pipes[2]);
    kiwi_assert_same(0, proc_close($process), 'Actual SSH command exits successfully: ' . $diagnosis);
    $status = json_decode(trim($diagnosis), true, 512, JSON_THROW_ON_ERROR);
    kiwi_assert_same(str_repeat('5', 32), $status['state']['active_attempt_id'], 'Pending outlives the finished source process.');
    kiwi_assert_same(true, $status['attempt']['transfer_complete'], 'Only a complete source process records proof.');
    kiwi_assert_same('complete', json_decode(substr($body, $meta['bytes']), true)['type'], 'Terminal proof follows the exact raw-byte count.');
    kiwi_assert_true(microtime(true) - $started >= 0.4, 'An 8KiB source at16KiB/s cannot be read in an unpaced burst.');
});

kiwi_run_test('Archive transport fail safely closes only its named attempt and result awaits acceptance capability', function (): void {
    $f = kiwi_test_attempt_service(); $id = str_repeat('4', 32);
    $f['service']->with_new_attempt($id, 'manual', basename($f['path']), filesize($f['path']));
    $failure = ['schema_version' => 1, 'attempt_id' => $id, 'phase' => 'copy',
        'reason_code' => 'health_run_failed', 'message' => 'Connection ended.'];
    [$exit, $wire] = kiwi_test_health_transport($f, 'fail', json_encode($failure));
    kiwi_assert_same(0, $exit, 'An explicit failed attempt receives a safely stored closure.');
    kiwi_assert_same('technical_failure', json_decode($wire, true)['attempt']['outcome'], 'SSH fail is a technical outcome.');
    kiwi_assert_same(null, $f['service']->status()['state']['active_attempt_id'], 'Confirmed failure ends Pending.');
    $closed = $f['service']->status($id);
    $result = ['schema_version' => 1, 'attempt_id' => $id, 'generation' => basename($f['path']),
        'received_bytes' => filesize($f['path']), 'sha256' => hash_file('sha256', $f['path']),
        'transfer_complete' => true, 'check' => 'quick_check', 'check_completed' => true, 'outcome' => 'ok'];
    [$result_exit] = kiwi_test_health_transport($f, 'result', json_encode($result));
    kiwi_assert_same(2, $result_exit, 'An unavailable result capability must never acknowledge a result.');
    kiwi_assert_same($closed['attempt'], $f['service']->status($id)['attempt'], 'Rejected result preserves closed failure.');
    $activation = ['schema_version' => 1, 'attempt_id' => null, 'phase' => 'activation',
        'reason_code' => 'health_run_failed', 'message' => 'Weekly timer setup failed.'];
    [$activation_exit] = kiwi_test_health_transport($f, 'fail', json_encode($activation));
    kiwi_assert_same(0, $activation_exit, 'The exact accepted activation-failure shape is supported.');
    kiwi_assert_same($closed['state'], $f['service']->status()['state'], 'Prestart failure neither starts nor rewrites attempts.');
    foreach ([array_merge($failure, ['command' => 'unblock']), array_merge($failure, ['phase' => 'abort']),
        array_merge($failure, ['reason_code' => 'corruption']), array_merge($failure, ['message' => str_repeat('x', 301)])] as $bad) {
        kiwi_assert_same(2, kiwi_test_health_transport($f, 'fail', json_encode($bad))[0], 'Purpose-mismatched failure is rejected.');
    }
});

kiwi_run_test('Archive transport refuses active sidecars and changed targets without a valid snapshot', function (): void {
    foreach (['wal', 'journal', 'wal-link', 'size', 'generation', 'lock', 'protected', 'parallel', 'capacity', 'bytes-float', 'bytes-bool'] as $case) {
        $f = kiwi_test_attempt_service(); $id = str_repeat('2', 32);
        $request = ['schema_version' => 1, 'attempt_id' => $id, 'start_kind' => 'scheduled',
            'generation' => basename($f['path']), 'expected_bytes' => filesize($f['path'])];
        $held = null; $limits = [];
        if ($case === 'wal' || $case === 'journal') { file_put_contents($f['path'] . '-' . $case, 'active'); }
        if ($case === 'wal-link') { symlink($f['path'], $f['path'] . '-wal'); }
        if ($case === 'size') { $request['expected_bytes']++; }
        if ($case === 'generation') { $request['generation'] = 'kiwi_retention_archive_2025.sqlite'; }
        if ($case === 'lock') { $held = (new Kiwi_Retention_Archive_Lock())->acquire_for_archive($f['path']); }
        if ($case === 'protected') { file_put_contents($f['path'] . '.lock.write-blocked', "kiwi_retention_archive_write_blocked_v1\n"); }
        if ($case === 'parallel') { $f['service']->with_new_attempt(str_repeat('3', 32), 'manual', basename($f['path']), filesize($f['path'])); }
        if ($case === 'capacity') { $limits = ['source_bytes_per_second' => 1, 'copy_seconds' => 1]; }
        if ($case === 'bytes-float') { $request['expected_bytes'] = 8192.5; }
        if ($case === 'bytes-bool') { $request['expected_bytes'] = true; }
        [$exit, $wire] = kiwi_test_health_transport($f, 'export', json_encode($request), $limits);
        kiwi_assert_same(2, $exit, 'Unsafe source/target must refuse export: ' . $case);
        kiwi_assert_same(false, strpos($wire, '"type":"complete"') !== false, 'No terminal proof: ' . $case);
        kiwi_assert_same(false, strpos($wire, "SQLite format 3\0") !== false, 'No archive bytes escape a rejected start: ' . $case);
        if ($held !== null) { (new Kiwi_Retention_Archive_Lock())->release($held['handle']); }
        $status = $f['service']->status();
        kiwi_assert_same($case === 'parallel' ? str_repeat('3', 32) : null, $status['state']['active_attempt_id'],
            'Controlled failure closes only its own attempt: ' . $case);
    }
});

kiwi_run_test('Archive transport exports one raw SQLite stream with a durable generation-bound proof', function (): void {
    $f = kiwi_test_attempt_service(); $id = str_repeat('1', 32);
    $request = ['schema_version' => 1, 'attempt_id' => $id, 'start_kind' => 'manual',
        'generation' => basename($f['path']), 'expected_bytes' => filesize($f['path'])];
    $source = file_get_contents($f['path']);
    [$exit, $wire] = kiwi_test_health_transport($f, 'export', json_encode($request));
    kiwi_assert_same(0, $exit, 'Export must complete with successful process status.');
    $line_end = strpos($wire, "\n");
    $header = json_decode(substr($wire, 0, $line_end), true, 512, JSON_THROW_ON_ERROR);
    kiwi_assert_same(['schema_version' => 1, 'type' => 'archive', 'attempt_id' => $id,
        'generation' => basename($f['path']), 'bytes' => strlen($source)], $header, 'Description has exactly the fixed framing fields.');
    kiwi_assert_same($source, substr($wire, $line_end + 1, strlen($source)), 'Stream contains exact raw SQLite bytes.');
    $trailer = json_decode(substr($wire, $line_end + 1 + strlen($source)), true, 512, JSON_THROW_ON_ERROR);
    kiwi_assert_same(['schema_version' => 1, 'type' => 'complete', 'attempt_id' => $id,
        'generation' => basename($f['path']), 'bytes' => strlen($source), 'sha256' => hash('sha256', $source)], $trailer,
        'The single terminal frame binds the bytes and checksum.');
    $status = $f['service']->status($id);
    kiwi_assert_same(true, $status['attempt']['transfer_complete'], 'Production keeps its own complete transfer proof.');
    kiwi_assert_same($trailer['sha256'], $status['attempt']['sha256'], 'The stored hash matches the same streamed data.');
    kiwi_assert_same($id, $status['state']['active_attempt_id'], 'Transfer completion retains Pending until a full result.');
    kiwi_assert_same(null, $status['attempt']['outcome'], 'A successful copy is no health result.');
});
