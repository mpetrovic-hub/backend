<?php

declare(strict_types=1);

$sandbox = realpath($argv[1] ?? '');
if (PHP_SAPI !== 'cli' || !$sandbox || defined('ABSPATH')
    || @file_get_contents($sandbox . '/SANDBOX_ONLY') !== "retention-sessions-recovery-tests-only\n"
    || is_link($sandbox . '/mariadb.sock') || filetype($sandbox . '/mariadb.sock') !== 'socket') {
    throw new RuntimeException('Use the integration shell runner with its private server.');
}
require __DIR__ . '/fixtures/retention-sessions-recovery-bootstrap.php';
require __DIR__ . '/fixtures/retention-sessions-recovery-data.php';
foreach (['class-retention-recovery-sql.php', 'class-retention-recovery-handoff-reader.php', 'class-retention-sessions-recovery-service.php'] as $file) {
    require __DIR__ . '/../tools/database/' . $file;
}
$admin = new PDO('mysql:unix_socket=' . $sandbox . '/mariadb.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
sandbox_assert($admin->query("SHOW VARIABLES LIKE 'skip_networking'")->fetch(PDO::FETCH_ASSOC)['Value'] === 'ON', 'TCP must be disabled.');

function persistent_state(): array
{
    global $wpdb;
    $state = [];
    foreach ($wpdb->get_col('SHOW TABLES') as $table) {
        $state[$table] = $wpdb->get_results("SELECT * FROM $table ORDER BY id", ARRAY_A);
    }
    return $state;
}

function recovery(array $fixture, ?callable $journal = null): Kiwi_Retention_Sessions_Recovery_Service
{
    return new Kiwi_Retention_Sessions_Recovery_Service($fixture['config'], null, $journal ?: static function (array $record): void {});
}

function preview(array $fixture): array { return recovery($fixture)->preview(FROM_DATE, TO_DATE, CUTOFF); }

$results = [];
try {
    $fixture = fixture('happy');
    $before = persistent_state();
    $archive_hash = hash_file('sha256', $fixture['archive']);
    $report = preview($fixture);
    sandbox_assert(!empty($report['success']) && !empty($report['ready_to_apply']), 'Preview must pass: ' . json_encode($report));
    sandbox_assert(persistent_state() === $before, 'Preview changed persistent tables.');
    sandbox_assert(hash_file('sha256', $fixture['archive']) === $archive_hash, 'Preview changed the archive.');
    sandbox_assert($report['gate']['status'] === 'passed' && count($report['gate']['deep_checked_dates']) === 8, 'All eight dates must be deeply checked.');
    sandbox_assert(count($report['changes']) === 2 && $report['changes'][0]['metric'] === 'cta1_click_events', 'Expected the two stale CTA metrics.');
    $again = preview($fixture);
    sandbox_assert($again['preview_sha256'] === $report['preview_sha256'], 'Unchanged preview must have a stable fingerprint.');
    $results['preview_no_persistent_writes'] = ['passed' => true, 'changes' => $report['changes'], 'deep_dates' => $report['gate']['deep_checked_dates']];
    echo "[PASS] Read-only preview, eight deep checks and stable fingerprint\n";

    $events = [];
    $service = recovery($fixture, static function (array $event) use (&$events): void { $events[] = $event; });
    $applied = $service->apply(FROM_DATE, TO_DATE, CUTOFF, $report['preview_sha256'], 1);
    sandbox_assert(!empty($applied['success']) && !empty($applied['summary_published']) && !empty($applied['resume_required']), 'Apply must start a partial run: ' . json_encode($applied));
    $run_id = $applied['run_id'];
    $partial_state = persistent_state();
    $wrong_worker = (new Kiwi_Retention_Cleanup_Service($fixture['config']))->run_worker_for_run('landing_page_sessions', 'another-run');
    sandbox_assert(empty($wrong_worker['success']) && $wrong_worker['error_code'] === 'cleanup_run_identity_mismatch'
        && persistent_state() === $partial_state, 'Worker must refuse a different expected run before archive/delete.');
    $results['worker_requires_expected_run'] = ['passed' => true, 'persistent_state_unchanged' => true];
    for ($i = 0; $i < 10 && !empty($applied['resume_required']); $i++) {
        $applied = $service->resume($run_id, $report['preview_sha256'], 1);
        sandbox_assert(!empty($applied['success']), 'Resume failed: ' . json_encode($applied));
    }
    sandbox_assert($applied['status'] === 'completed' && $applied['archived_rows'] === 24 && $applied['deleted_rows'] === 24
        && !empty($applied['verification']['exact_source_ids_verified']), 'Receipt-backed completion required.');
    sandbox_assert((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SESSIONS) === 1, 'Newer session must survive.');
    $done_again = $service->resume($run_id, $report['preview_sha256'], 1);
    sandbox_assert($done_again['status'] === 'completed' && !$done_again['workers'], 'Completed resume must not start another run.');
    foreach ([MAIN_TABLE, TK_TABLE] as $table) {
        $current = $wpdb->get_results("SELECT * FROM $table ORDER BY id", ARRAY_A);
        foreach ($before[$table] as $i => $old) {
            sandbox_assert($old['id'] === $current[$i]['id'] && $old['created_at'] === $current[$i]['created_at'], 'Summary IDs and creation times must survive repair.');
        }
        $outside_before = array_values(array_filter($before[$table], static function ($row) { return $row['metric_date'] < FROM_DATE || $row['metric_date'] > TO_DATE; }));
        $outside_after = $wpdb->get_results("SELECT * FROM $table WHERE metric_date < '2026-08-30' OR metric_date > '2026-09-06' ORDER BY id", ARRAY_A);
        sandbox_assert($outside_before === $outside_after, 'Repair changed outside summaries.');
    }
    $actions = $wpdb->get_col("SELECT lifecycle_action FROM wp_kiwi_operational_events WHERE event_type = 'retention_cleanup_skipped' ORDER BY id");
    $results['apply_resume_completed'] = ['passed' => true, 'status' => $applied['status'],
        'archived_rows' => 24, 'deleted_rows' => 24, 'verification' => $applied['verification'], 'journal_events' => count($events)];
    echo "[PASS] Atomic apply and normal workers resume to verified completed\n";

    foreach (['multiple-generations', 'generation-overlap'] as $scenario) {
        $fixture = fixture($scenario);
        $before = persistent_state();
        $multiple = preview($fixture);
        sandbox_assert(!empty($multiple['ready_to_apply']), 'Multiple-generation preview failed: ' . json_encode($multiple));
        $evidence = $multiple['input_evidence']['handoffs'];
        sandbox_assert(count($evidence['archives']) === 2 && count($evidence['batches']) === 2 && $evidence['snapshot_rows'] === 34,
            'Expected two independent generations and all 34 handoffs.');
        sandbox_assert(persistent_state() === $before, 'Generation preview changed persistent data.');
        $results[$scenario] = ['passed' => true, 'generations' => 2, 'snapshot_rows' => 34, 'duplicates' => $evidence['identical_duplicates']];
        echo '[PASS] ' . $scenario . "\n";
    }

    $fixture = fixture('restricted-reader');
    $original_db = $wpdb;
    $database = $wpdb->pdo->query('SELECT DATABASE()')->fetchColumn();
    $reader_name = 'recovery_reader_' . bin2hex(random_bytes(5));
    $admin->exec("CREATE USER '$reader_name'@'localhost' IDENTIFIED BY 'local-test-only'");
    $admin->exec("GRANT SELECT, CREATE TEMPORARY TABLES ON `$database`.* TO '$reader_name'@'localhost'");
    $pdo = new PDO('mysql:unix_socket=' . $sandbox . '/mariadb.sock;dbname=' . $database, $reader_name, 'local-test-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $wpdb = new Sandbox_Wpdb($pdo);
    $limited = preview($fixture);
    sandbox_assert(!empty($limited['ready_to_apply']), 'Restricted-reader preview failed: ' . json_encode($limited));
    $denied = false;
    try { $pdo->exec('UPDATE ' . MAIN_TABLE . ' SET cta1_click_events = 0'); } catch (PDOException $error) { $denied = true; }
    sandbox_assert($denied, 'Restricted reader must have no persistent update permission.');
    $wpdb = $original_db;
    $results['restricted_reader_preview'] = ['passed' => true, 'persistent_update_denied' => true];
    echo "[PASS] Preview with SELECT and temporary-table permissions only\n";

    $faults = ['missing_receipt', 'missing_archive_row', 'missing_archive', 'live_conflict', 'logical_duplicate',
        'missing_audit', 'audit_count_mismatch', 'orphan_batch', 'blocked_archive', 'locked_archive',
        'corrupt_archive', 'non_cta_change', 'changed_preview', 'publish_failure', 'journal_failure', 'wrong_run', 'concurrent_change'];
    foreach ($faults as $fault) {
        $fixture = fixture($fault);
        $report = preview($fixture);
        sandbox_assert(!empty($report['ready_to_apply']), 'Baseline preview failed for ' . $fault . ': ' . json_encode($report));
        if (in_array($fault, ['missing_receipt', 'missing_archive_row', 'orphan_batch'], true)) {
            $tamper = new PDO('sqlite:' . $fixture['archive']);
            if ($fault === 'missing_receipt') { $tamper->exec('DELETE FROM archive_batch_rows WHERE source_pk = 2'); }
            elseif ($fault === 'missing_archive_row') { $tamper->exec('DELETE FROM wp_kiwi_landing_handoff_events WHERE _source_pk = 2'); }
            else { $tamper->exec("UPDATE archive_batches SET archive_batch_id = 'orphan'"); }
            $tamper = null;
        } elseif ($fault === 'missing_archive') { rename($fixture['archive'], $fixture['archive'] . '.removed'); }
        elseif ($fault === 'live_conflict' || $fault === 'logical_duplicate') {
            $archive = Kiwi_Retention_Recovery_SQL::open_archive($fixture['archive']);
            $row = $archive->query('SELECT * FROM wp_kiwi_landing_handoff_events WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            foreach (['_source_pk', '_archive_batch_id', '_archived_at'] as $key) { unset($row[$key]); }
            if ($fault === 'live_conflict') { $row['elapsed_ms'] = 999; } else { $row['id'] = 999; }
            sandbox_insert(HANDOFFS, $row);
        } elseif ($fault === 'missing_audit') { sandbox_sql("DELETE FROM wp_kiwi_retention_cleanup_runs WHERE source_key = 'landing_handoff_events'"); }
        elseif ($fault === 'audit_count_mismatch') { sandbox_sql("UPDATE wp_kiwi_retention_cleanup_runs SET archived_rows = 33 WHERE source_key = 'landing_handoff_events'"); }
        elseif ($fault === 'blocked_archive') { sandbox_assert(Kiwi_Retention_Archive_Write_Block::persist($fixture['archive'] . '.lock'), 'Fixture block failed.'); }
        elseif ($fault === 'locked_archive') { $held = (new Kiwi_Retention_Archive_Lock())->acquire_for_archive($fixture['archive']); }
        elseif ($fault === 'corrupt_archive') { file_put_contents($fixture['archive'], 'broken'); }
        elseif ($fault === 'non_cta_change') { sandbox_sql("UPDATE " . MAIN_TABLE . " SET handoff_attempts = 0 WHERE metric_date = '2026-08-30'"); }
        elseif ($fault === 'changed_preview') { sandbox_sql("UPDATE " . ENGAGEMENTS . " SET cta1_click_count = cta1_click_count + 1 WHERE id = 1"); }
        $before = persistent_state();
        $lock_proved = false;
        $journal = static function (array $event) use ($fault, &$lock_proved): void {
            global $wpdb, $sandbox;
            if ($fault === 'journal_failure') { throw new RuntimeException('recovery_report_write_failed'); }
            if ($fault === 'concurrent_change' && $event['phase'] === 'prepared') {
                $db = $wpdb->pdo->query('SELECT DATABASE()')->fetchColumn();
                $other = new PDO('mysql:unix_socket=' . $sandbox . '/mariadb.sock;dbname=' . $db, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $other->exec('SET innodb_lock_wait_timeout = 1');
                try { $other->exec('UPDATE ' . ENGAGEMENTS . ' SET cta1_click_count = 1 WHERE id = 1'); }
                catch (PDOException $error) { $lock_proved = strpos($error->getMessage(), '1205') !== false; }
                sandbox_assert($lock_proved, 'Concurrent change must be blocked by serializable reads.');
                throw new RuntimeException('concurrent_probe_finished'); // Roll back this fixture after the lock proof.
            }
        };
        $service = recovery($fixture, $journal);
        $wpdb->fail_tkzone_insert = $fault === 'publish_failure';
        if ($fault === 'wrong_run') { $outcome = $service->resume('another-run', $report['preview_sha256'], 1); }
        elseif (in_array($fault, ['changed_preview', 'publish_failure', 'journal_failure', 'concurrent_change'], true)) {
            $outcome = $service->apply(FROM_DATE, TO_DATE, CUTOFF, $report['preview_sha256'], 1);
        } else { $outcome = $service->preview(FROM_DATE, TO_DATE, CUTOFF); }
        $wpdb->fail_tkzone_insert = false;
        if ($fault === 'locked_archive') { (new Kiwi_Retention_Archive_Lock())->release($held['handle']); }
        sandbox_assert(empty($outcome['success']) || empty($outcome['ready_to_apply']), 'Expected blocked outcome: ' . $fault);
        sandbox_assert(persistent_state() === $before, 'Fault changed persistent state: ' . $fault);
        if ($fault === 'changed_preview') { sandbox_assert($outcome['error_code'] === 'reviewed_preview_changed', 'Wrong stale-preview reason.'); }
        $results[$fault] = ['passed' => true, 'blocked_reason' => $outcome['error_code'] ?? $outcome['blockers'],
            'persistent_state_unchanged' => true, 'concurrent_write_blocked' => $lock_proved];
        echo '[PASS] ' . $fault . "\n";
    }
    $fixture = fixture('lost-commit-ack');
    $reviewed = preview($fixture);
    $events = [];
    $service = recovery($fixture, static function (array $event) use (&$events): void { $events[] = $event; });
    $wpdb->lose_commit_ack = true;
    $uncertain = $service->apply(FROM_DATE, TO_DATE, CUTOFF, $reviewed['preview_sha256'], 1);
    sandbox_assert(empty($uncertain['success']) && $uncertain['summary_published'] === null
        && $uncertain['commit_outcome'] === 'unknown' && !empty($uncertain['run_id']), 'Lost acknowledgement must retain uncertainty and the run ID.');
    sandbox_assert(in_array('commit_requested', array_column($events, 'phase'), true), 'Run identity must be durable before commit.');
    for ($i = 0; $i < 10; $i++) {
        $resumed = $service->resume($uncertain['run_id'], $reviewed['preview_sha256'], 1);
        sandbox_assert(!empty($resumed['success']), 'Confirmed persisted run must resume after lost acknowledgement.');
        if ($resumed['status'] === 'completed') { break; }
    }
    sandbox_assert($resumed['status'] === 'completed', 'Resume must finish the original uncertain run.');
    $results['lost_commit_acknowledgement'] = ['passed' => true, 'commit_outcome' => 'unknown', 'same_run_resumed_to_completed' => true];
    echo "[PASS] Lost commit acknowledgement retains run identity and resumes safely\n";
    $tamper = new PDO('sqlite:' . $fixture['archive']);
    $tamper->exec("DELETE FROM archive_batch_rows WHERE source_pk = 2 AND archive_batch_id IN (SELECT archive_batch_id FROM archive_batches WHERE source_key = 'landing_page_sessions')");
    $tamper = null;
    $invalid_completion = $service->resume($uncertain['run_id'], $reviewed['preview_sha256'], 1);
    sandbox_assert(empty($invalid_completion['success']) && $invalid_completion['error_code'] === 'completion_evidence_mismatch', 'Completed status must not substitute for exact receipt verification.');
    $results['completed_receipt_reverification'] = ['passed' => true, 'missing_receipt_rejected' => true];
    echo "[PASS] Completed status is rechecked against exact receipts\n";

    $fixture = fixture('scope-guards');
    $before = persistent_state();
    foreach ([['2026-08-30', '2026-09-06', '2026-09-08 00:00:00'],
        ['2026-08-32', TO_DATE, CUTOFF], ['2026-07-01', TO_DATE, CUTOFF]] as $arguments) {
        $invalid = recovery($fixture)->preview(...$arguments);
        sandbox_assert(empty($invalid['success']), 'Invalid cutoff/range must be rejected.');
    }
    sandbox_assert(persistent_state() === $before, 'Argument guards changed data.');
    $results['explicit_range_and_cutoff_guards'] = ['passed' => true, 'persistent_state_unchanged' => true];
    echo "[PASS] Explicit valid dates, bounded range and normal cutoff required\n";

    $fixture = fixture('cli');
    define('KIWI_RETENTION_ARCHIVE_ROOT', $fixture['config']->get_retention_archive_root());
    require __DIR__ . '/fixtures/retention-sessions-recovery-cli.php';
    sandbox_assert(WP_CLI::$registration[1]['when'] === 'before_wp_load', 'CLI must register before WordPress loads.');
    $private = $sandbox . '/private';
    mkdir($private, 0700);
    $options = ['from' => FROM_DATE, 'to' => TO_DATE, 'cutoff' => CUTOFF, 'report' => $private . '/preview.jsonl'];
    $before = persistent_state();
    $cli = run_cli_preview($options);
    sandbox_assert($cli['exit'] === 0 && !empty($cli['result']['ready_to_apply']) && !WP_CLI::$init_reached, 'CLI preview must halt successfully before init: ' . json_encode($cli));
    sandbox_assert(persistent_state() === $before && (fileperms($options['report']) & 0777) === 0600, 'CLI preview must preserve tables and keep a private report.');
    $record = json_decode(trim(file_get_contents($options['report'])), true);
    sandbox_assert($record['phase'] === 'result' && $record['result']['preview_sha256'] === $cli['result']['preview_sha256'], 'JSONL report must match stdout.');
    $saved_report = file_get_contents($options['report']);
    $duplicate = run_cli_preview($options);
    sandbox_assert($duplicate['exit'] === 1 && file_get_contents($options['report']) === $saved_report, 'CLI must refuse report overwrite.');
    $unsafe = run_cli_preview(array_merge($options, ['report' => ABSPATH . 'unsafe-recovery-report.jsonl']));
    sandbox_assert($unsafe['exit'] === 1 && !is_file(ABSPATH . 'unsafe-recovery-report.jsonl'), 'CLI must reject reports in the web root.');
    $results['cli_early_private_preview'] = ['passed' => true, 'halts_before_init' => true, 'report_matches_stdout' => true, 'rejects_report_overwrite_and_webroot' => true];
    echo "[PASS] Early WP-CLI preview, private journal, overwrite and webroot guards\n";
    $scenario_count = count($results);
    $results['validation'] = ['base_commit' => getenv('KIWI_RECOVERY_TEST_BASE_COMMIT') ?: 'unknown',
        'php' => PHP_VERSION, 'mariadb' => $admin->query('SELECT VERSION()')->fetchColumn(),
        'production_access' => false, 'synthetic_data_only' => true,
        'runtime_files' => $cli['result']['input_evidence']['runtime_files']];
    file_put_contents($sandbox . '/result.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo 'Passed ' . $scenario_count . " integration scenarios.\n";
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . "\n");
    exit(1);
}
