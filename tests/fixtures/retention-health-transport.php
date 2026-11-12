<?php

putenv('KIWI_TEST_FILTER=__transport_fixture_load_only__');
ob_start(); require dirname(__DIR__) . '/run-tests.php'; ob_end_clean();
WP_CLI::reset_runtime(); WP_CLI::$exit_process = true;
$f = null;
register_shutdown_function(static function () use (&$f): void {
    if ($f !== null) {
        // Reset controlled SQL-boundary faults solely to inspect what survived.
        $f['db']->before_query = null; $f['db']->fail_matching = '';
        $status = $f['service']->status(str_repeat('5', 32));
        if ($status['_exit_code'] !== 0) { $status = $f['service']->status(); }
        fwrite(STDERR, json_encode($status) . "\n");
    }
});
$f = kiwi_test_attempt_service();
$case = $argv[1] ?? 'normal';
$rate = 16384; $copy_seconds = 5;
if ($case === 'status-stall') {
    for ($i = 1; $i <= 100; $i++) {
        $f['events']->rows[$i] = ['id' => $i, 'area' => 'retention', 'event_type' => 'retention_archive_health_unavailable',
            'severity' => 'error', 'lifecycle_action' => 'raised', 'correlation_key' => str_repeat('a', 190) . $i,
            'reference_type' => str_repeat('a', 190), 'reference_id' => str_repeat('b', 190),
            'occurred_at' => '2026-11-12 00:00:00', 'context_json' => ['reason_code' => 'health_run_failed']];
    }
}
if ($case === 'stalled') {
    $sqlite = new PDO('sqlite:' . $f['path']);
    $sqlite->exec('CREATE TABLE payload (data BLOB); INSERT INTO payload VALUES (zeroblob(524288))');
    $sqlite = null;
    $rate = 4194304; $copy_seconds = 0.4;
}
if ($case === 'proof-failure' || $case === 'proof-readback') {
    $f['db']->before_query = static function ($sql, $db) use ($case): void {
        if (strpos($sql, 'UPDATE fixture_options') === 0 && strpos($sql, '"transfer_complete":true') !== false) {
            if ($case === 'proof-failure') { $db->fail_matching = 'UPDATE fixture_options'; }
            else {
                $db->before_query = static function ($sql, $db): void {
                    if ($sql === 'COMMIT') { $db->fail_matching = 'SELECT option_value'; }
                };
            }
        }
    };
}
if ($case === 'failed-closure') { $f['events']->fail_next_insert = true; }
define('KIWI_RETENTION_ARCHIVE_HEALTH_SOURCE_BYTES_PER_SECOND', $rate);
define('KIWI_RETENTION_ARCHIVE_HEALTH_COPY_SECONDS', $copy_seconds);
define('KIWI_RETENTION_ARCHIVE_HEALTH_CONTROL_SECONDS', 0.4);
clearstatcache(true, $f['path']);
fwrite(STDERR, json_encode(['path' => $f['path'], 'bytes' => filesize($f['path'])]) . "\n");
putenv('SSH_ORIGINAL_COMMAND=' . (in_array($case, ['input-stall', 'status-stall'], true) ? 'status' : 'export'));
$command = new Kiwi_Retention_Archive_Health_Command(service_factory: static fn() => $f['service']);
$command->ssh([], []);
