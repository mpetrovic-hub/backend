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
