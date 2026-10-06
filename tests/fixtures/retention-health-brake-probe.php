<?php

/* Private fixture driver: argv[1] deliberately is not the production entrypoint. */
define('WP_CLI', true);
final class WP_CLI
{
    public static function add_command(...$arguments): bool { return true; }
    public static function add_wp_hook(...$arguments): bool { return true; }
    public static function get_runner() { return null; }
    public static function halt(int $exit_code): void { exit($exit_code); }
    public static function line(string $line): void { echo $line; }
}
require_once __DIR__ . '/../../tools/database/kiwi-retention-archive-health.php';

$payload = json_decode((string) base64_decode((string) ($argv[3] ?? ''), true), true);
$archive = (string) ($payload['archive_path'] ?? '');
$rate = $payload['rate'] ?? null;
$action = (string) ($payload['action'] ?? 'check');
try {
    $guard = new Kiwi_Retention_Archive_Health_Read_Brake($archive, $rate);
    $pdo = $guard->open_verified_archive();
    if ($action === 'change_rate') {
        $control = new PDO('sqlite::memory:');
        $control->query("SELECT kiwi_health_brake_control_v1('target', 350)");
    } elseif ($action === 'lose_configuration') {
        putenv('KIWI_HEALTH_RANDOM_ADVICE');
    } elseif ($action === 'replace_target') {
        rename($archive, $archive . '.prior');
        rename((string) ($payload['replacement_path'] ?? ''), $archive);
    }
    $rows = $pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
    $guard->verify_after_check($pdo);
    echo json_encode(['result' => 'ok', 'rows' => $rows, 'phases' => $guard->probe_evidence()]);
} catch (Throwable $error) {
    $reason = in_array($error->getMessage(), [
        'health_brake_unavailable', 'health_brake_configuration_invalid',
        'health_brake_probe_failed', 'health_brake_target_unverified',
    ], true) ? $error->getMessage() : 'health_brake_target_unverified';
    echo json_encode(['result' => 'error', 'reason_code' => $reason, 'check_completed' => false]);
    exit(2);
}
