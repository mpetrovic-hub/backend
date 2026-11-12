<?php
// Small schema fixture for the existing real PHP transport; loopback artificial databases only.
$settings = json_decode(file_get_contents((string) getenv('KIWI_RETENTION_TEST_CREDENTIALS_FILE')), true, 512, JSON_THROW_ON_ERROR);
$database = $argv[2] ?? '';
if (!preg_match('/^mysql:host=(?:127\.0\.0\.1|localhost);port=([1-9][0-9]{0,4});charset=utf8mb4$/D', $settings['dsn'] ?? '', $match)
    || (int) $match[1] > 65535 || !preg_match('/^codex_test_157_[a-f0-9]+$/D', $database)
    || !in_array($argv[1] ?? '', ['setup', 'drop'], true)) { throw new RuntimeException('Invalid local runner fixture.'); }
$pdo = new PDO($settings['dsn'], $settings['username'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($argv[1] === 'drop') { $pdo->exec('DROP DATABASE ' . $database); exit; }
$pdo->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE ' . $database);
$pdo->exec('CREATE TABLE probe_options (option_name varchar(191) PRIMARY KEY, option_value longtext NOT NULL, autoload varchar(20) NOT NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE probe_kiwi_operational_events (
    id bigint unsigned AUTO_INCREMENT PRIMARY KEY, occurred_at datetime NOT NULL, created_at datetime NOT NULL,
    area varchar(64) NOT NULL, severity varchar(20) NOT NULL, event_type varchar(100) NOT NULL,
    lifecycle_action varchar(20) NOT NULL, idempotency_key varchar(191) UNIQUE, correlation_key varchar(191) NOT NULL,
    reference_type varchar(64) NOT NULL, reference_id varchar(191) NOT NULL, message varchar(500) NOT NULL,
    raw_error_text text, context_json longtext) ENGINE=InnoDB');
$initial = json_encode(['format_version'=>1, 'revision'=>0, 'activated_at'=>null, 'active_attempt_id'=>null,
    'last_confirmed_attempt_id'=>null, 'last_freshness_completed_at'=>null, 'last_attempt_sequence'=>0]);
$pdo->prepare('INSERT INTO probe_options VALUES (?, ?, ?)')->execute(['kiwi_retention_archive_health_state', $initial, 'no']);
