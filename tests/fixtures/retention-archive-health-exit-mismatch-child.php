<?php

$payload_raw = isset($argv[2]) ? base64_decode((string) $argv[2], true) : false;
$payload = is_string($payload_raw) ? json_decode($payload_raw, true) : null;
$archive_path = is_array($payload) ? (string) ($payload['archive_path'] ?? '') : '';
$readiness_path = is_array($payload) ? (string) ($payload['readiness_path'] ?? '') : '';
$lock = @fopen($archive_path . '.lock', 'c+');
if (!is_resource($lock)
    || !@flock($lock, LOCK_SH | LOCK_NB)
) {
    exit(3);
}
register_shutdown_function(static function (string $path): void {
    @unlink($path);
}, $readiness_path);
if (@file_put_contents($readiness_path, 'locked', LOCK_EX) !== 6) {
    exit(3);
}

echo '{"result":"ok","reason_code":"sqlite_check_ok","check_started":true}';
exit(2);
