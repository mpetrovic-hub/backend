<?php

// Public schedule/worker checks with an isolated MariaDB and a real annual SQLite archive.
foreach (['repositories/class-sms-body-variant-repository.php', 'repositories/class-sales-repository.php',
    'repositories/class-nth-event-repository.php', 'repositories/class-nth-flow-transaction-repository.php',
    'providers/nth/class-nth-premium-sms-normalizer.php', 'providers/nth/class-nth-sms-sale-evidence.php',
    'services/class-sms-body-variant-service.php'] as $file) {
    require_once ABSPATH . 'includes/' . $file;
}
$root = sys_get_temp_dir() . '/kiwi-test-sms-live-' . bin2hex(random_bytes(8));
register_shutdown_function(static function () use ($root): void {
    foreach (glob($root . '/sqlite/*') ?: [] as $file) { if (is_file($file) || is_link($file)) { unlink($file); } }
    if (is_dir($root . '/sqlite')) { rmdir($root . '/sqlite'); }
    if (is_dir($root)) { rmdir($root); }
});
define('KIWI_RETENTION_ARCHIVE_ROOT', $root);
define('KIWI_RETENTION_WORKER_ROW_LIMIT', 3);
$key = Kiwi_Retention_Source_Registry::SOURCE_SMS_BODY_VARIANT_ASSIGNMENTS;
$source = Kiwi_Retention_Source_Registry::sms_body_variant_assignments();
$originals = new Kiwi_Sms_Body_Variant_Repository();
$runs = new Kiwi_Retention_Cleanup_Run_Repository();
$snapshots = new Kiwi_Retention_Table_Growth_Snapshot_Repository();
foreach ([$originals, $runs, $snapshots, new Kiwi_Nth_Event_Repository(), new Kiwi_Nth_Flow_Transaction_Repository(),
    new Kiwi_Sales_Repository(), new Kiwi_Operational_Event_Repository()] as $repository) {
    $repository->create_table();
}
$plugin = new class extends Kiwi_Plugin {
    public function __construct() {}
    protected function log_retention_cleanup(string $message): void {}
};
$GLOBALS['retention_test_options']['kiwi_retention_settings'] = [$key => ['enabled'=>true,'dry_run'=>false,'retention_days'=>90]];
$start = static function () use ($plugin, $key, $runs, $wpdb): array {
    $result = $plugin->run_retention_cleanup_scheduler_daily()['source_results'][$key];
    if (empty($result['run_id'])) { throw new RuntimeException('Missing live run: ' . json_encode($result)); }
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $runs->get_table_name() . ' WHERE run_id=%s', $result['run_id']), ARRAY_A);
};
$step = static function (array $run) use ($plugin, $key, $runs): array {
    $plugin->run_retention_cleanup_worker($key);
    return $runs->find_run_by_id((int) $run['id']);
};
$until = static function (array $run, callable $condition) use ($step): array {
    for ($i = 0; $i < 200; ++$i) {
        if ($condition($run)) { return $run; }
        if ($run['finished_at'] !== null) { break; }
        $run = $step($run);
    }
    throw new RuntimeException('Live run stopped before its expected checkpoint: ' . json_encode([$run['status'], $run['worker_phase'], $run['error_code']]));
};
$sample = $start();
et_check(!empty($sample['archive_db_path']) && (int)$sample['dry_run'] === 0 && $sample['cutoff_value'] === '2026-07-14 00:00:00',
    'A live run freezes its archive target and the WordPress-calendar cutoff before any package');
$size = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $snapshots->get_table_name() . ' WHERE cleanup_run_id=%s AND snapshot_phase=%s',
    $sample['run_id'], 'before_cleanup'), ARRAY_A);
et_check(is_array($size) && $size['source_key'] === $key && (int)$size['row_count_total'] === 0 && $size['min_cutoff_value'] === null,
    'The empty SMS source records a genuine before-cleanup growth snapshot for the same run');
$sample = $until($sample, static fn(array $row): bool => $row['finished_at'] !== null);
et_check($sample['status'] === 'completed' && (int)$sample['deleted_rows'] === 0 && (int)$wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . $snapshots->get_table_name() . ' WHERE cleanup_run_id=%s', $sample['run_id'])) === 2,
    'A zero-candidate live run finishes with both real growth measurements and without a deletion');
$summary = $wpdb->prefix . 'kiwi_sms_body_variant_summary';
$full = static function (int $id, string $version, string $created) use ($source): array {
    return et_full_row($source, ['id'=>$id, 'created_at'=>$created,'updated_at'=>$created,
        'landing_key'=>'fixture187','service_key'=>'sms187','variant_key'=>'bare_id','seed'=>'',
        'allocation_version'=>$version,'transaction_id'=>'txn_live187_'.$id,'visible_token'=>'live187'.$id]);
};
$full(11,'legacy','2025-12-10 01:00:00');
$full(12,'blocked','2025-12-12 02:00:00');
$full(13,'legacy','2026-10-01 03:00:00');
$wpdb->insert($summary, ['created_at'=>'2025-12-10','updated_at'=>'2026-10-01','landing_key'=>'fixture187',
    'service_key'=>'sms187','variant_key'=>'bare_id','seed'=>'','allocation_version'=>'legacy','assignments'=>2]);
$run = $start();
$run = $until($run, static fn(array $row): bool => $row['worker_phase'] === 'sms_correction');
$gate = json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
$byVersion = static function (array $gate): array {
    $groups=[];
    foreach ($gate['groups'] as $group) { $groups[$group['key']['allocation_version']]=$group; }
    return $groups;
};
$group = $byVersion($gate)['legacy'];
et_check($group['before']['mysql_baseline']['first_created_at']==='2025-12-10 01:00:00'
    && $group['before']['mysql_baseline']['last_created_at']==='2026-10-01 03:00:00'
    && $group['before']['mysql_baseline']['counters']['assignments']===2
    && !file_exists($run['archive_db_path']), 'The productive audit preserves the original live time range and counters before the first SQLite write');
$run = $until($run, static fn(array $row): bool => $row['worker_phase'] === 'sms_archive');
$released = json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
$both = $byVersion($released);
et_check($run['gate_status']==='partial' && $both['legacy']['after']['status']==='passed'
    && isset($both['legacy']['before'], $both['legacy']['correction']) && $both['blocked']['after']['status']==='incomplete'
    && !file_exists($run['archive_db_path']), 'The group release and the independent missing-summary block are saved before the first archive batch');
$legacyId = Kiwi_Retention_Sms_Statistics::group_id($both['legacy']['key']);
$blockedId = Kiwi_Retention_Sms_Statistics::group_id($both['blocked']['key']);
$originalFinding = $released['groups'][$legacyId]['after'];
$lock = $runs->acquire_source_creation_lock($key);
try {
    $wpdb->query('START TRANSACTION');
    $wpdb->update($summary, ['assignments'=>7], ['allocation_version'=>'legacy']);
    et_check(Kiwi_Retention_Sms_Worker::invalidate_group($runs, $both['legacy']['key'], $lock),
        'An explicitly known manual change invalidates only its currently released group under the shared source lock');
    $wpdb->query('COMMIT');
} finally { $runs->release_source_creation_lock($lock); }
$run = $step($run);
et_check($run['worker_phase'] === 'sms_recheck' && !file_exists($run['archive_db_path']),
    'A known change starts a fresh group check before the next archive action');
$run = $until($run, static fn(array $row): bool => $row['worker_phase'] === 'sms_archive');
$rechecked=json_decode($run['gate_results_json'],true,512,JSON_THROW_ON_ERROR);
et_check($rechecked['groups'][$legacyId]['after']['status'] === 'outside_tolerance'
    && $rechecked['groups'][$legacyId]['after']['counters']['assignments']['actual']===7
    && $rechecked['groups'][$legacyId]['reviews'][0]['after'] === $originalFinding
    && $rechecked['groups'][$blockedId]['after'] === $released['groups'][$blockedId]['after'],
    'The changed group receives a fresh before/correction/after while the separate group keeps its own finding');
