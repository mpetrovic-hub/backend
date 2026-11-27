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
et_create_health_options();
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
    $GLOBALS['sms_live_last_step'] = $plugin->run_retention_cleanup_worker($key);
    return $runs->find_run_by_id((int) $run['id']);
};
$until = static function (array $run, callable $condition) use ($step): array {
    for ($i = 0; $i < 200; ++$i) {
        if ($condition($run)) { return $run; }
        if ($run['finished_at'] !== null) { break; }
        $run = $step($run);
    }
    throw new RuntimeException('Live run stopped before its expected checkpoint: ' . json_encode([$run['status'], $run['worker_phase'],
        $run['error_code'], $GLOBALS['sms_live_last_step'] ?? []]));
};
if (($argv[1] ?? '') === '--sms-group-incidents') {
    require __DIR__ . '/retention-sms-group-incidents-engine.php';
    return;
}
if (in_array($argv[1] ?? '', ['--sms-live-binary', '--sms-live-ci', '--sms-live-mixed-binary', '--sms-live-mixed-ci',
    '--sms-live-schema-change', '--sms-live-delete-schema-change'], true)) {
    require __DIR__ . '/retention-sms-live-group-identity-engine.php';
    return;
}
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
    et_check(!Kiwi_Retention_Sms_Worker::invalidate_group($runs, $both['legacy']['key'], $lock),
        'Manual repair invalidation requires an actual repair transaction on the source-lock connection');
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
$run = $until($run, static fn(array $row): bool => $row['finished_at'] !== null);
et_check((int)$run['deleted_rows']===0 && !is_file($run['archive_db_path']),
    'A completed live run with only blocked groups keeps all originals and creates no archive file');

$wpdb->update($summary, ['assignments'=>2], ['allocation_version'=>'legacy']);
$archive = new Kiwi_Retention_Sqlite_Archive_Service(new Kiwi_Config());
$otherArchive = $archive->get_archive_directory() . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2025.sqlite';
$archive->archive_snapshot_batch($source, [$originals->find_assignment_by_id(11)], '2026-07-14 00:00:00',
    'live-original-187', $otherArchive);
$loss=$until($start(), static fn(array $row): bool => $row['worker_phase']==='sms_archive');
rename($otherArchive, $otherArchive . '.saved');
$loss=$step($loss);
et_check(!is_file($loss['archive_db_path']) && (int)$loss['deleted_rows']===0 && $originals->find_assignment_by_id(11)!==null,
    'Loss of another observed archive generation invalidates the release before any new copy or deletion');
rename($otherArchive . '.saved', $otherArchive);
$loss=$step($loss);
et_check($loss['worker_phase']==='sms_recheck', 'A returned archive generation requires fresh evidence before the old run can resume');
$loss=$until($loss,static fn(array $row): bool => $row['finished_at']!==null);
et_check($loss['status']==='completed' && (int)$loss['deleted_rows']===1 && $originals->find_assignment_by_id(11)===null
    && $originals->find_assignment_by_id(12)!==null && $originals->find_assignment_by_id(13)!==null,
    'After a full recheck the genuine eligible ID alone is archived and deleted');
// The source-ID is still reconstructible from live and archived originals after the earlier run.
$full(14,'legacy','2024-01-04 00:00:00');
$full(15,'second','2024-01-05 00:00:00');
$wpdb->insert($summary,['created_at'=>'2024-01-01','updated_at'=>'2024-01-02','landing_key'=>'fixture187',
    'service_key'=>'sms187','variant_key'=>'bare_id','seed'=>'','allocation_version'=>'second','assignments'=>1]);
$interrupted=$until($start(), static fn(array $row): bool => $row['worker_phase']==='sms_correction');
$audit=$runs->get_table_name();
$wpdb->pdo->exec("CREATE TRIGGER sms_fixture_lost_result BEFORE UPDATE ON {$audit} FOR EACH ROW
    BEGIN IF OLD.worker_phase='sms_correcting' AND NEW.worker_phase='sms_remeasure'
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='unavailable audit'; END IF; END");
$interrupted=$step($interrupted);
et_check($interrupted['worker_phase']==='sms_correcting' && (int)$wpdb->get_var("SELECT assignments FROM {$summary} WHERE allocation_version='legacy'")===3
    && (int)$interrupted['archived_rows']===0 && (int)$interrupted['deleted_rows']===0,
    'A durable correction whose result cannot be saved cannot archive any group, including another passing group');
$wpdb->pdo->exec('DROP TRIGGER sms_fixture_lost_result');
$interrupted=$step($interrupted);
et_check($interrupted['status']==='failed' && $interrupted['error_code']==='sms_correction_interrupted'
    && $originals->find_assignment_by_id(14)!==null && $originals->find_assignment_by_id(15)!==null,
    'A worker restart closes the unconfirmed attempt and never resumes on its old findings');
$retry=$until($start(),static fn(array $row): bool => $row['worker_phase']==='sms_archive');
$body=json_decode($retry['gate_results_json'],true,512,JSON_THROW_ON_ERROR);
$read=$byVersion($body);
et_check($retry['run_id']!==$interrupted['run_id'] && $read['legacy']['before']['counters']['assignments']['actual']===3
    && $read['legacy']['after']['counters']['assignments']['actual']===3 && $read['legacy']['correction']['counters']['assignments']['status']==='skipped',
    'The next regular run reads the already saved correction before a new attempt, and cannot count it twice');
$lock=$runs->acquire_source_creation_lock($key);
try {
    $wpdb->query('START TRANSACTION');
    $wpdb->update($summary,['cta1'=>9],['allocation_version'=>'legacy']);
    et_check(Kiwi_Retention_Sms_Worker::invalidate_group($runs,$read['legacy']['key'],$lock),'Known change is part of the same manual transaction');
    $wpdb->query('COMMIT');
} finally { $runs->release_source_creation_lock($lock); }
$retry=$until($retry,static fn(array $row):bool=>$row['worker_phase']==='sms_remeasure');
$wpdb->pdo->exec("CREATE TRIGGER sms_fixture_block_release BEFORE UPDATE ON {$audit} FOR EACH ROW
    BEGIN IF NEW.worker_phase='sms_archive' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='unavailable release'; END IF; END");
for ($i=0;$i<30;$i++) {
    $retry=$step($retry);
    if (($GLOBALS['sms_live_last_step']['error_code'] ?? '')==='sms_audit_unconfirmed') {break;}
}
et_check(($GLOBALS['sms_live_last_step']['error_code']??'')==='sms_audit_unconfirmed' && $retry['worker_phase']==='sms_remeasure'
    && (int)$retry['archived_rows']===0 && $originals->find_assignment_by_id(15)!==null,
    'A required release that fails to persist holds the different already passed group as well');
$wpdb->pdo->exec('DROP TRIGGER sms_fixture_block_release');
$retry=$until($retry,static fn(array $row):bool=>$row['worker_phase']==='sms_archive');
// Normal writes after release do not force a full group recount on the next package.
$beforeNormal=json_decode($retry['gate_results_json'],true,512,JSON_THROW_ON_ERROR);
et_check($originals->mark_event_by_transaction_id('txn_live187_15','cta1'), 'A normal post-release click commits on a proven group');
$wpdb->query($wpdb->prepare("UPDATE {$audit} SET updated_at='2020-01-01' WHERE id=%d",(int)$retry['id']));
$continued=$start();
et_check($continued['run_id']===$retry['run_id'], 'A stale wake-up with a stored release resumes the same frozen archive run');
$continued=$until($continued,static fn(array $row):bool=>$row['finished_at']!==null);
$final=json_decode($continued['gate_results_json'],true,512,JSON_THROW_ON_ERROR);
$secondId=Kiwi_Retention_Sms_Statistics::group_id($read['second']['key']);
et_check($continued['status']==='completed' && (int)$continued['deleted_rows']===1
    && $final['groups'][$secondId]['after']===$beforeNormal['groups'][$secondId]['after']
    && $originals->find_assignment_by_id(15)===null && $originals->find_assignment_by_id(14)!==null,
    'The normal event preserves the saved group release, its original is archived and the blocked group stays in MySQL');
$late = new PDO('sqlite:'.$continued['archive_db_path']);
et_check((bool)$late->query('SELECT cta1_recorded_at FROM '.$source['source_table'].' WHERE id=15')->fetchColumn(),
    'Row-level receipt proof catches the current click marker without invalidating the group');
$late=null;
$full(16,'second','2024-01-03 00:30:00');
$replacementRun=$until($start(),static fn(array $row):bool=>$row['worker_phase']==='sms_archive');
$alternative=dirname($replacementRun['archive_db_path']).DIRECTORY_SEPARATOR.'kiwi_retention_archive_2026_part_2.sqlite';
et_check($runs->terminalize_open_run_for_manual_replacement($replacementRun['archive_db_path'],$alternative),
    'The existing archive replacement transaction can close the open SMS run');
$workerResult=$plugin->run_retention_cleanup_worker($key);
$oldRun=$runs->find_run_by_id((int)$replacementRun['id']);
et_check(($workerResult['error_code']??'')==='no_open_run' && $oldRun['status']==='failed'
    && $oldRun['error_code']==='archive_manually_replaced' && $originals->find_assignment_by_id(16)!==null,
    'A pending SMS worker cannot revive the frozen run or delete its original after an externally verified replacement');
