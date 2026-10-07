<?php

/* Local-only driver for complete, read-only archive-copy measurements. */
define('ABSPATH', dirname(__DIR__, 2) . '/');
require_once ABSPATH . 'includes/core/class-config.php';
require_once ABSPATH . 'includes/services/class-retention-archive-check-supervisor.php';

$archive = (string) ($argv[1] ?? '');
$rate = (int) ($argv[2] ?? 0);
if ($rate !== 700 || (new Kiwi_Config())->get_retention_archive_health_timeout_seconds() !== 7200) {
    exit(2);
}
$before = getenv();
$result = (new Kiwi_Retention_Archive_Check_Supervisor())->run($archive, 'quick');
if ($before !== getenv()) { exit(2); }
echo json_encode(['supervision_budget_seconds' => 7200, 'parent_environment_unchanged' => true, 'outcome' => $result]);
exit($result['result'] === 'ok' && !empty($result['check_completed']) ? 0 : 2);
