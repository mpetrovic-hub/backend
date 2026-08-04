<?php

$payload_raw = base64_decode((string) ($argv[2] ?? ''), true);
$payload = is_string($payload_raw) ? json_decode($payload_raw, true) : null;
$archive = is_array($payload) ? (string) ($payload['archive_path'] ?? '') : '';
$readiness = is_array($payload) ? (string) ($payload['readiness_path'] ?? '') : '';
$write_state = static function (string $path, string $state): bool {
    $handle = @fopen($path, 'c+b');
    if (!is_resource($handle)) {
        return false;
    }

    try {
        return @rewind($handle)
            && @ftruncate($handle, 0)
            && @fwrite($handle, $state) === strlen($state)
            && @fflush($handle);
    } finally {
        @fclose($handle);
    }
};
$lock = @fopen($archive . '.lock', 'c+');
$result = ['result' => 'error', 'reason_code' => 'fixture_failed', 'check_completed' => false];
$exit_code = 2;

if (is_resource($lock)
    && @flock($lock, LOCK_EX | LOCK_NB)
    && $write_state($readiness, 'locked')
) {
    $write_state($readiness, 'corruption_gate_required');
    $deadline = microtime(true) + 2.0;
    $state = '';
    $restored_after_forced_failure = false;
    do {
        usleep(20000);
        if (!$restored_after_forced_failure && is_dir($readiness)) {
            usleep(200000);
            @rmdir($readiness);
            $restored_after_forced_failure = true;
        }
        $state = (string) @file_get_contents($readiness);
    } while (!in_array($state, ['corruption_gate_persisted', 'corruption_gate_failed'], true)
        && microtime(true) < $deadline);

    if ($state === 'corruption_gate_persisted') {
        $result = [
            'result' => 'corruption_detected',
            'reason_code' => 'sqlite_check_reported_corruption',
            'check_completed' => true,
            'write_blocked' => false,
            'incident_open' => true,
        ];
        $exit_code = 0;
    } else {
        $result = [
            'result' => 'error',
            'reason_code' => 'corruption_gate_persist_failed',
            'check_completed' => true,
        ];
    }
    @flock($lock, LOCK_UN);
}
if (is_resource($lock)) {
    @fclose($lock);
}

echo json_encode($result, JSON_UNESCAPED_SLASHES);
exit($exit_code);
