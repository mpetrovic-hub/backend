<?php

// Exercise the installed MySQL group identity through the same source admission, correction and packages.
$mode = $argv[1];
$binary = in_array($mode, ['--sms-live-binary', '--sms-live-mixed-binary'], true);
$summary = $wpdb->prefix . 'kiwi_sms_body_variant_summary';
$originalTable = $source['source_table'];
$sourceBinary = in_array($mode, ['--sms-live-binary', '--sms-live-mixed-ci'], true);
foreach ([$originalTable => $sourceBinary, $summary => $binary] as $table => $caseSensitive) {
    if ($caseSensitive) {
        $wpdb->pdo->exec("ALTER TABLE {$table} MODIFY seed VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''");
    }
}
$common = ['landing_key' => 'case-test', 'service_key' => 'case-service', 'variant_key' => 'game_word',
    'allocation_version' => 'legacy'];
foreach ([41 => 'Hero', 42 => 'hero'] as $id => $seed) {
    et_full_row($source, $common + ['id' => $id, 'seed' => $seed, 'created_at' => '2025-01-01 00:00:00',
        'updated_at' => '2025-01-01 00:00:00', 'transaction_id' => 'txn_case_187_' . $id,
        'visible_token' => 'CaseToken' . $id]);
}
$storedCounts = $binary ? ['Hero' => 12, 'hero' => 0] : ['Hero' => 0];
foreach ($storedCounts as $seed => $assignments) {
    $wpdb->insert($summary, $common + ['seed' => $seed, 'assignments' => $assignments,
        'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00']);
}
$summaryCollation = $binary ? 'utf8mb4_bin' : 'utf8mb4_unicode_520_ci';
$control = $wpdb->pdo->query("SELECT seed COLLATE {$summaryCollation} AS summary_seed,COUNT(*) AS n
    FROM {$originalTable} GROUP BY summary_seed ORDER BY BINARY summary_seed")->fetchAll(PDO::FETCH_KEY_PAIR);
et_check(count($control) === count($storedCounts), 'The real group index distinguishes exactly the expected seed spellings');
$run = $until($start(), static fn(array $row): bool => $row['worker_phase'] === 'sms_archive');
$findings = json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR)['groups'];
if (in_array($mode, ['--sms-live-schema-change', '--sms-live-delete-schema-change'], true)) {
    $already_archived = $mode === '--sms-live-delete-schema-change';
    if ($already_archived) {
        $run = $until($run, static fn(array $row): bool => (int)$row['archived_rows'] === 2 && (int)$row['deleted_rows'] === 0);
    }
    foreach ([$originalTable, $summary] as $table) {
        $wpdb->pdo->exec("ALTER TABLE {$table} MODIFY seed VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''");
    }
    $run = $step($run);
    et_check($run['status'] === 'failed' && $run['error_code'] === 'sms_group_schema_changed'
        && (int)$run['archived_rows'] === ($already_archived ? 2 : 0) && (int)$run['deleted_rows'] === 0
        && is_file($run['archive_db_path']) === $already_archived && (int)$wpdb->get_var("SELECT COUNT(*) FROM {$originalTable}") === 2,
        'Changed database group equality closes the old release, preserves confirmed copy progress and retains both originals');
    return;
}
et_check((int) $run['eligible_rows'] === 2 && count($findings) === count($control),
    'The live release contains every candidate and exactly one finding per real MySQL group');
$observed = [];
foreach ($findings as $group) {
    $seed = $group['key']['seed'];
    $observed[$seed] = [(int)$group['before']['counters']['assignments']['actual'],
        (int)$group['after']['counters']['assignments']['actual'], $group['after']['status']];
}
if ($binary) {
    et_check($observed['Hero'] === [12,12,'outside_tolerance'] && $observed['hero'] === [0,1,'passed'],
        'Q27 corrects only the lowercase seed while the distinct uppercase seed stays blocked at 12');
} else {
    et_check(array_values($observed) === [[0,2,'passed']],
        'Both spellings contribute to the single case-insensitive summary and one correction');
}
$manualSeed = $binary ? 'Hero' : 'hErO';
$lock = $runs->acquire_source_creation_lock($key);
try {
    $wpdb->query('START TRANSACTION');
    et_check(Kiwi_Retention_Sms_Worker::invalidate_group($runs, $common + ['seed'=>$manualSeed], $lock),
        'A known manual change marks the corresponding actual MySQL group for a new comparison');
    $wpdb->query('COMMIT');
} finally { $runs->release_source_creation_lock($lock); }
$run = $step($run);
$run = $until($run, static fn(array $row): bool => $row['worker_phase'] === 'sms_archive');
$checked = json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR)['groups'];
$revisited = array_values(array_filter($checked, static fn(array $group): bool => isset($group['reviews'])));
et_check(count($checked)===count($findings) && count($revisited)===1
    && (!$binary || $revisited[0]['key']['seed']==='Hero'), 'Only the selected database group was reassessed, even when another spelling was supplied');
$run = $until($run, static fn(array $row): bool => $row['finished_at'] !== null);
$remaining = array_map('intval',$wpdb->pdo->query("SELECT id FROM {$originalTable} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$archived = new PDO('sqlite:' . $run['archive_db_path']);
$members = array_map('intval',$archived->query("SELECT id FROM {$originalTable} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$gate = json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
$deleted = [];
foreach ($gate['deleted_batches'] as $batch) { $deleted = array_merge($deleted, $batch['ids']); }
sort($deleted, SORT_NUMERIC);
$expected = $binary ? [42] : [41,42];
et_check($remaining === ($binary ? [41] : []) && $members === $expected && $deleted === $expected
    && (int)$run['deleted_rows'] === count($expected), 'The archive, deletion receipt and surviving MySQL originals agree with the released physical group');
$proof = new Kiwi_Retention_Sms_Deletion_Evidence();
$row = $archived->query("SELECT * FROM {$originalTable} WHERE id=42")->fetch(PDO::FETCH_ASSOC);
et_check($proof->finished_after_deletion($row, $run['archive_db_path'], $common + ['seed'=>'hero']) === $run['finished_at']
    && $proof->finished_after_deletion($row, $run['archive_db_path'], $common + ['seed'=>'Hero']) === ($binary ? null : $run['finished_at']),
    'A later sale can use the exact deleted ID with each spelling only when the database treats the two groups as equal');
$archived = null;
