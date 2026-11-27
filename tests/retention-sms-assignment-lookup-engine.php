<?php

// Runs in the real-engine test runner's freshly created local database.
require_once ABSPATH . 'includes/repositories/class-sms-body-variant-repository.php';
require_once ABSPATH . 'includes/services/class-sms-body-variant-service.php';

$config = new Engagement_Test_Config();
$config->root = sys_get_temp_dir() . '/kiwi-test-sms-lookup-' . bin2hex(random_bytes(8));
$archive = new Kiwi_Retention_Sqlite_Archive_Service($config);
$lock = new Kiwi_Retention_Archive_Lock();
$gate = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator($lock, new Engagement_Test_Events());
$reader = new Kiwi_Retention_Archive_Reader($archive, $lock, $gate);
$repo = new Kiwi_Sms_Body_Variant_Repository();
$lookup = new Kiwi_Sms_Body_Variant_Service($config, $repo, $reader);
$source = Kiwi_Retention_Source_Registry::sms_body_variant_assignments();
$table = $source['source_table'];
try {
    $repo->create_table();
    $runs = new Kiwi_Retention_Cleanup_Run_Repository();
    $runs->create_table();
    et_check(array_keys($source['archive_columns']) === array_column($wpdb->get_results('SHOW COLUMNS FROM ' . $table), 'Field'),
        'The archive schema includes every field of the actual SMS assignment table in order');
    $row = et_full_row($source, ['id' => 19, 'created_at' => '2025-07-01 13:00:00', 'updated_at' => '2025-07-02 13:00:00',
        'transaction_id' => 'txn_archive_00000019', 'visible_token' => 'HeroAbC19', 'allocation_version' => 'old']);
    $path = $archive->resolve_archive_db_path();
    $archive->archive_snapshot_batch($source, [$row], '2026-01-01 00:00:00', 'sms-fixture-19', $path);
    $wpdb->update($table, ['visible_token' => 'LiveAbC19', 'allocation_version' => 'current'], ['id' => 19]);
    $live = $lookup->resolve_assignment_from_visible_token('liveabc19');
    et_check($live['status'] === 'found' && $live['source'] === 'mysql' && $live['assignment']['allocation_version'] === 'current',
        'The native MySQL collation resolves the complete live token to its current group');
    et_check($lookup->resolve_assignment_from_visible_token('heroabc19')['status'] === 'not_found',
        'The active original ID disqualifies its older archived token');
    $wpdb->query('DELETE FROM ' . $table . ' WHERE id=19');
    $archived = $lookup->resolve_assignment_from_visible_token('heroabc19');
    et_check($archived['status'] === 'found' && $archived['source'] === 'archive' && $archived['assignment']['id'] === 19
        && $archived['assignment']['transaction_id'] === 'txn_archive_00000019' && $archived['assignment']['allocation_version'] === 'old',
        'After a successful MySQL miss, the guarded SQLite read resolves the late token and original group');
    $sqlite = new PDO('sqlite:' . $path);
    $change_path = $sqlite->prepare('UPDATE archive_batches SET archive_db_path=? WHERE archive_batch_id=?');
    $change_path->execute([$path . '.other', 'sms-fixture-19']);
    foreach ([$lookup->resolve_assignment_from_visible_token('HeroAbC19'),
        $lookup->resolve_assignment_from_transaction_id('txn_archive_00000019')] as $unverified) {
        et_check($unverified['status'] === 'read_error' && $unverified['source'] === 'archive',
            'Neither a visible nor a direct assignment code can use a receipt certifying a different file');
    }
    $change_path->execute([$path, 'sms-fixture-19']);
    unset($change_path, $sqlite);

    $newer = array_replace($row, ['visible_token' => 'NewHero19', 'transaction_id' => 'txn_changed_00000019',
        'allocation_version' => 'newer']);
    $generation = Kiwi_Retention_Archive_Name::parse(basename($path));
    $next = dirname($path) . DIRECTORY_SEPARATOR . Kiwi_Retention_Archive_Name::build($generation['year'], $generation['generation'] + 1);
    $batch = [$newer];
    for ($id = 30; $id <= 38; ++$id) {
        $batch[] = array_replace($newer, ['id' => $id, 'transaction_id' => 'txn_restored_000000' . $id,
            'visible_token' => 'Restored' . $id]);
    }
    $archive->archive_snapshot_batch($source, $batch, '2026-01-01 00:00:00', 'sms-newer-19', $next);
    $runs->create_run(['run_id' => 'lookup-newer-19', 'source_key' => $source['source_key'], 'source_table' => $table,
        'status' => 'completed', 'dry_run' => 0, 'enabled' => 1, 'deleted_rows' => count($batch), 'archive_db_path' => $next,
        'gate_results_json' => ['deleted_batches' => [['batch_id' => 'sms-newer-19',
            'group' => array_intersect_key($newer, array_flip(Kiwi_Sms_Body_Variant_Repository::SUMMARY_GROUP_COLUMNS)),
            'ids' => array_column($batch, 'id')]]]]);
    foreach (array_slice($batch, 1) as $restored) { $wpdb->insert($table, $restored); }
    et_check($lookup->resolve_assignment_from_visible_token('HeroAbC19')['status'] === 'not_found'
        && $lookup->resolve_assignment_from_visible_token('NewHero19')['status'] === 'found',
        'Only the replaced original code resolves while its verified later generation is present');
    rename($next, $next . '.missing');
    foreach ([$lookup->resolve_assignment_from_visible_token('HeroAbC19'),
        $lookup->resolve_assignment_from_transaction_id('txn_archive_00000019'),
        $lookup->resolve_assignment_from_visible_token('NewHero19')] as $result) {
        et_check($result['status'] === 'read_error' && $result['source'] === 'archive',
            'An absent required generation sends both obsolete codes and an unprovable miss to the archive fallback');
    }
    $independent = et_full_row($source, array_replace($row,
        ['id' => 42, 'transaction_id' => 'txn_other_00000042', 'visible_token' => 'Other42']));
    et_check($lookup->resolve_assignment_from_visible_token('Other42')['source'] === 'mysql',
        'An independent live code remains authoritative while one historical generation is missing');
    $archive->archive_snapshot_batch($source, [$independent], '2026-01-01 00:00:00', 'sms-other-42', $path);
    $wpdb->query('DELETE FROM ' . $table . ' WHERE id=42');
    $wpdb->insert($table, $newer);
    $independent_match = $lookup->resolve_assignment_from_visible_token('Other42');
    et_check($independent_match['status'] === 'found' && $independent_match['source'] === 'archive'
        && (int) $independent_match['assignment']['id'] === 42,
        'An independent archive code is provable when all ten deleted IDs of the missing file are authoritative in MySQL again');
    $recovered = dirname($path) . DIRECTORY_SEPARATOR . Kiwi_Retention_Archive_Name::build($generation['year'], $generation['generation'] + 2);
    $archive->archive_snapshot_batch($source, [$newer], '2026-01-01 00:00:00', 'sms-recovered-19', $recovered);
    $wpdb->query('DELETE FROM ' . $table . ' WHERE id=19');
    et_check($lookup->resolve_assignment_from_transaction_id('txn_other_00000042')['status'] === 'found'
        && $lookup->resolve_assignment_from_visible_token('HeroAbC19')['status'] === 'not_found'
        && $lookup->resolve_assignment_from_visible_token('NewHero19')['assignment']['allocation_version'] === 'newer',
        'A verified still newer generation restores complete code lookup for both archived IDs');
    $wpdb->query('DROP TABLE ' . $table);
    $error = $lookup->resolve_assignment_from_visible_token('heroabc19');
    et_check($error['status'] === 'read_error' && $error['source'] === 'mysql',
        'An actual MySQL query error goes to fallback even with an otherwise valid SQLite hit');
} finally {
    $reader->close();
    foreach (glob($archive->get_archive_directory() . '/*') ?: [] as $file) { unlink($file); }
    if (is_dir($archive->get_archive_directory())) { rmdir($archive->get_archive_directory()); }
    if (is_dir($config->root)) { rmdir($config->root); }
}

require __DIR__ . '/retention-sms-code-collation-engine.php';
