<?php

require_once ABSPATH . 'includes/repositories/class-sms-body-variant-repository.php';
require_once ABSPATH . 'includes/repositories/class-sales-repository.php';
require_once ABSPATH . 'includes/repositories/class-nth-flow-transaction-repository.php';
require_once ABSPATH . 'includes/repositories/class-nth-event-repository.php';
require_once ABSPATH . 'includes/providers/nth/class-nth-premium-sms-normalizer.php';
require_once ABSPATH . 'includes/providers/nth/class-nth-sms-sale-evidence.php';
require_once ABSPATH . 'includes/services/class-sms-body-variant-service.php';

$root = sys_get_temp_dir() . '/kiwi-test-sms-preview-' . bin2hex(random_bytes(8));
register_shutdown_function(static function () use ($root): void {
    foreach (glob($root . '/sqlite/*') ?: [] as $file) { if (is_file($file) || is_link($file)) { unlink($file); } }
    if (is_dir($root . '/sqlite')) { rmdir($root . '/sqlite'); }
    if (is_dir($root)) { rmdir($root); }
});
define('KIWI_RETENTION_ARCHIVE_ROOT', $root);
define('KIWI_RETENTION_WORKER_ROW_LIMIT', 3);
$source = Kiwi_Retention_Source_Registry::sms_body_variant_assignments();
$key = $source['source_key'];
$originals = new Kiwi_Sms_Body_Variant_Repository();
$runs = new Kiwi_Retention_Cleanup_Run_Repository();
foreach ([$originals, $runs, new Kiwi_Retention_Table_Growth_Snapshot_Repository(),
    new Kiwi_Nth_Event_Repository(), new Kiwi_Nth_Flow_Transaction_Repository(),
    new Kiwi_Sales_Repository(), new Kiwi_Operational_Event_Repository()] as $repository) {
    $repository->create_table();
}
$plugin = new class extends Kiwi_Plugin {
    public function __construct() {}
    protected function log_retention_cleanup(string $message): void {}
};
$preview = static function (array $settings = []) use ($plugin, $key, $runs): array {
    global $wpdb;
    $GLOBALS['retention_test_options']['kiwi_retention_settings'] = [$key => $settings];
    $output = $plugin->run_retention_cleanup_scheduler_daily()['source_results'][$key];
    et_check(!empty($output['run_id']), 'Daily scheduler identifies its SMS audit');
    for ($i = 0; $i < 150; $i++) {
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $runs->get_table_name() . ' WHERE run_id=%s', $output['run_id']));
        if ($row && $row['finished_at'] !== null) { return $row; }
        $plugin->run_retention_cleanup_worker($key);
    }
    throw new RuntimeException('SMS preview did not finish.');
};
$disabled = $preview();
et_check($disabled['status'] === 'skipped' && (int) $disabled['retention_days_effective'] === 90,
    'The default source is disabled with a 90-day retention period');
$minimum = $preview(['retention_days' => 1]);
et_check((int) $minimum['retention_days_effective'] === 7, 'The existing seven-day minimum applies to this source');
$insert = static function (int $id, string $created, string $version = 'legacy', array $fields = []) use ($source): array {
    return et_full_row($source, $fields + ['id' => $id, 'created_at' => $created, 'updated_at' => $created,
        'landing_key' => 'preview', 'service_key' => 'sms', 'variant_key' => 'bare_id', 'seed' => '',
        'allocation_version' => $version, 'transaction_id' => 'txn_preview_' . $id, 'visible_token' => 'code' . $id]);
};
$insert(1, '2026-07-13 23:59:59', 'legacy', ['conv_recorded_at' => '2026-07-14 10:00:00']);
$insert(2, '2026-07-14 00:00:00');
$insert(3, '2026-07-14 00:00:01');
$summary = $wpdb->prefix . 'kiwi_sms_body_variant_summary';
$wpdb->insert($summary, ['created_at' => '2026-07-13 00:00:00', 'updated_at' => '2026-07-14 00:00:00',
    'landing_key' => 'preview', 'service_key' => 'sms', 'variant_key' => 'bare_id',
    'seed' => '', 'allocation_version' => 'legacy', 'assignments' => 3, 'conv' => 1]);
$row = $preview(['enabled' => true]);
et_check($row['status'] === 'completed' && $row['cutoff_value'] === '2026-07-14 00:00:00'
    && (int) $row['dry_run'] === 1 && (int) $row['eligible_rows'] === 1
    && (int) $row['archived_rows'] === 0 && (int) $row['deleted_rows'] === 0,
    'Only the preceding row is eligible and a completed preview archives and deletes zero rows');
$gate = json_decode($row['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
$group = reset($gate['groups']);
et_check($group['before']['counters']['assignments']['expected'] === 3
    && $group['before']['counters']['conv']['actual'] === 1 && $group['before']['status'] === 'passed',
    'The affected group includes all live originals on both sides of the cutoff');

$config = new Kiwi_Config();
$archive = new Kiwi_Retention_Sqlite_Archive_Service($config);
$archive->resolve_archive_db_path();
$old = $archive->get_archive_directory() . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2025.sqlite';
$new = $archive->get_archive_directory() . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite';
$four = $insert(4, '2025-06-01 01:00:00', 'old');
$five = $insert(5, '2025-06-01 01:00:00', 'legacy', ['handoff_hidden_at' => '2025-06-02 10:00:00']);
$archive->archive_snapshot_batch($source, [$four, $five], '2026-07-14 00:00:00', 'sms-old', $old);
$wpdb->update($source['source_table'], ['allocation_version' => 'legacy', 'cta1_recorded_at' => '2025-06-02 09:00:00'], ['id' => 4]);
$four = $originals->find_assignment_by_id(4);
$archive->archive_snapshot_batch($source, [$four], '2026-07-14 00:00:00', 'sms-new', $new);
$wpdb->query('DELETE FROM ' . $source['source_table'] . ' WHERE id=4');
$wpdb->update($source['source_table'], ['handoff_hidden_at' => null], ['id' => 5]);
$row = $preview(['enabled' => true]);
$gate = json_decode($row['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
$group = reset($gate['groups']);
et_check($group['before']['counters']['assignments']['expected'] === 5
    && $group['before']['counters']['cta1']['expected'] === 1
    && $group['before']['counters']['handoff_hidden']['expected'] === 0,
    'The newest verified generation determines archived ID 4, and the live ID 5 overrides its earlier copy');

$read_group = static function (array $run, string $version): array {
    $gate = json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
    foreach ($gate['groups'] as $entry) {
        if ($entry['key']['allocation_version'] === $version) { return $entry['before']; }
    }
    throw new RuntimeException('Expected preview group ' . $version);
};

$counter_names = ['assignments', 'cta1', 'handoff_attempted', 'handoff_hidden', 'handoff_no_hide', 'handoff_returned', 'conv'];
$fields = ['cta1_recorded_at', 'handoff_attempted_at', 'handoff_hidden_at', 'handoff_no_hide_at', 'handoff_returned_at', 'conv_recorded_at'];
$bulk = $wpdb->pdo->prepare('INSERT INTO ' . $source['source_table'] .
    ' (created_at,updated_at,landing_key,service_key,variant_key,seed,allocation_version,transaction_id,visible_token,'
    . implode(',', $fields) . ') VALUES (?,?,' . "'preview','sms','bare_id','','boundary',?,?," . implode(',', array_fill(0, count($fields), '?')) . ')');
$wpdb->pdo->beginTransaction();
for ($i = 0; $i < 1000; ++$i) {
    $date = $i === 0 ? '2026-07-13 23:00:00' : '2026-10-01 13:00:00';
    $bulk->execute(array_merge([$date, $date, 'txn_boundary_' . $i, 'boundary' . $i], array_fill(0, count($fields), $date)));
}
$wpdb->pdo->commit();
$summary_row = ['created_at' => '2026-10-01 12:00:00', 'updated_at' => '2026-10-01 12:00:00',
    'landing_key' => 'preview', 'service_key' => 'sms', 'variant_key' => 'bare_id', 'seed' => ''];
$wpdb->insert($summary, $summary_row + ['allocation_version' => 'boundary']);
foreach ([980 => 'passed', 1020 => 'passed', 1021 => 'outside_tolerance', 979 => 'outside_tolerance'] as $actual => $result) {
    $wpdb->update($summary, array_fill_keys($counter_names, $actual), ['allocation_version' => 'boundary']);
    $before = $read_group($preview(['enabled' => true]), 'boundary');
    foreach ($counter_names as $counter) {
        $got = $before['counters'][$counter];
        et_check($got['expected'] === 1000 && $got['actual'] === $actual && $got['distance'] === abs($actual - 1000)
            && $got['limit'] == 20 && $got['status'] === $result, $counter . ' correctly compares ' . $actual . ' against 1000');
    }
}
$wpdb->pdo->exec('DELETE FROM ' . $source['source_table'] . " WHERE allocation_version='boundary' AND CAST(SUBSTRING(visible_token,9) AS UNSIGNED)>=124");
$wpdb->update($summary, ['assignments' => 127], ['allocation_version' => 'boundary']);
$trimmed = $read_group($preview(['enabled' => true]), 'boundary')['counters']['assignments'];
et_check($trimmed['expected'] === 124 && $trimmed['limit'] === 2.48 && $trimmed['distance'] === 3
    && $trimmed['status'] === 'outside_tolerance', 'The fractional percentage limit is not rounded up');
$custom = $read_group($preview(['enabled' => true, 'tolerance_percent' => 2.5, 'tolerance_absolute' => 3]), 'boundary');
et_check($custom['counters']['assignments']['limit'] === 3.1 && $custom['counters']['assignments']['status'] === 'passed',
    'Configured fractional percentage and absolute tolerances are used by the scheduler');
$wpdb->update($summary, ['handoff_returned' => 2], ['allocation_version' => 'legacy']);
$zero = $read_group($preview(['enabled' => true]), 'legacy')['counters']['handoff_returned'];
et_check($zero['expected'] === 0 && $zero['actual'] === 2 && $zero['limit'] == 2 && $zero['status'] === 'passed',
    'The inclusive absolute tolerance on a proven zero leaves its actual value intact');
$wpdb->update($summary, ['handoff_returned' => 3], ['allocation_version' => 'legacy']);
$zero = $read_group($preview(['enabled' => true]), 'legacy')['counters']['handoff_returned'];
et_check($zero['expected'] === 0 && $zero['actual'] === 3 && $zero['status'] === 'outside_tolerance',
    'An excess beyond the absolute threshold on zero fails the counter');
$insert(2001, '2025-06-01 01:00:00', 'no_summary');
$missing = $read_group($preview(['enabled' => true]), 'no_summary');
et_check($missing['status'] === 'incomplete' && $missing['reasons'][0]['code'] === 'summary_row_missing'
    && $missing['counters']['conv']['expected'] === 0 && $missing['counters']['conv']['actual'] === null,
    'A known zero target is distinguishable from the missing stored summary row');

$timed = $insert(2100, '2025-06-01 00:00:00', 'timed');
$timed_key = ['landing_key' => 'preview','service_key' => 'sms','variant_key' => 'bare_id','seed' => '', 'allocation_version' => 'timed'];
for ($n = 2101; $n < 2110; ++$n) {
    $insert($n, '2025-06-01 12:00:00', 'timed', ['conv_recorded_at' => '2025-06-02 09:00:00']);
}
$unknown = $insert(2111, '2025-06-01 00:00:00', 'timed');
$archive->archive_snapshot_batch($source, [$timed, $unknown], '2026-07-14 00:00:00', 'timed-batch', $new);
$wpdb->query('DELETE FROM ' . $source['source_table'] . ' WHERE id IN(2100,2111)');
$archive_db = new PDO('sqlite:' . $new);
$archive_db->exec('UPDATE ' . $source['source_table'] . " SET _archived_at='2026-07-14 01:00:00' WHERE _source_pk IN(2100,2111)");
$archive_db = null;
$runs->create_run(['run_id' => 'prior-timed', 'source_key' => $key, 'source_table' => $source['source_table'],
    'status' => 'completed','enabled' => 1, 'dry_run' => 0, 'started_at' => '2026-07-14 00:00:00',
    'finished_at' => '2026-07-14 02:00:00', 'archive_db_path' => $new, 'archived_rows' => 2, 'deleted_rows' => 1,
    'gate_results_json' => ['deleted_batches' => [['batch_id' => 'timed-batch', 'group' => $timed_key, 'ids' => [2100]]]]]);
$wpdb->insert($summary, $summary_row + ['allocation_version'=>'timed','assignments'=>11,'conv'=>10]);
$events = new Kiwi_Nth_Event_Repository();
$flows = new Kiwi_Nth_Flow_Transaction_Repository();
$sales = new Kiwi_Sales_Repository();
foreach ([2100,2111] as $n) {
    $ref = 'timed-sale-' . $n;
    $mo = $events->insert_if_new(['provider'=>'nth','service_key'=>'sms','event_type'=>'mo_callback','direction'=>'inbound',
        'keyword'=>'PLAY','message_text'=>'PLAY code' . $n,'dedupe_key'=>'timed-mo-' . $n])['row'];
    $sale = $sales->upsert(['sale_reference'=>$ref,'provider_key'=>'nth','service_key'=>'sms','sale_type'=>'premium_sms_one_off',
        'status'=>'completed','transaction_id'=>'txn_unrelated_click']);
    $flows->create(['flow_reference'=>$ref,'sale_reference'=>$ref,'service_key'=>'sms','keyword'=>'PLAY',
        'sale_id'=>(int)$sale['id'],'mo_event_id'=>(int)$mo['id']]);
    $wpdb->update($sales->get_table_name_for_schema(), ['created_at'=>'2026-07-14 02:00:01'], ['id'=>$sale['id']]);
}
$compare = static function (int $actual, array $expected, string $status) use ($wpdb,$summary,$read_group,$preview): void {
    $wpdb->update($summary,['conv'=>$actual],['allocation_version'=>'timed']);
    $before = $read_group($preview(['enabled'=>true]), 'timed');
    $conv = $before['counters']['conv'];
    et_check($before['status'] === $status && $conv['actual'] === $actual && $conv['expected'] === null
        && $conv['possible_expected'] === $expected && array_column($conv['comparisons'],'expected') === $expected,
        'Every possible conv target ' . implode('/', $expected) . ' is assessed for actual ' . $actual);
};
$compare(10, [10,11], 'passed');
$compare(8, [10,11], 'outside_tolerance');
$wpdb->update($sales->get_table_name_for_schema(), ['created_at'=>'2026-07-14 02:00:00'], ['sale_reference'=>'timed-sale-2100']);
$compare(10, [9,10,11], 'passed');
$archive_db = new PDO('sqlite:' . $new);
$archive_db->exec('UPDATE ' . $source['source_table'] . " SET _archived_at='unknown' WHERE _source_pk=2111");
$archive_db = null;
$compare(10, [9,10,11], 'passed');
$wpdb->update($sales->get_table_name_for_schema(), ['created_at'=>'2026-07-14 02:00:01'], ['sale_reference'=>'timed-sale-2100']);
$archive_db = new PDO('sqlite:' . $new);
$archive_db->exec('UPDATE ' . $source['source_table'] . " SET _archived_at='' WHERE _source_pk=2100");
$archive_db = null;
$compare(10, [10,11], 'passed');
$wpdb->pdo->exec("SET SESSION sql_mode=''");
$wpdb->update($sales->get_table_name_for_schema(), ['created_at'=>'0000-00-00 00:00:00'], ['sale_reference'=>'timed-sale-2100']);
$compare(10, [9,10,11], 'passed');

$wpdb->pdo->exec('SET SESSION sql_mode=DEFAULT');
$insert(2200, '2025-01-01 00:00:00', 'original-group');
$member = $insert(2201, '2025-01-01 00:00:00', 'original-group');
$wpdb->insert($summary, $summary_row + ['allocation_version'=>'original-group', 'assignments'=>1]);
$archive->archive_snapshot_batch($source, [$member], '2026-07-14 00:00:00', 'before-change', $old);
$wpdb->update($source['source_table'], ['allocation_version'=>'legacy'], ['id'=>2201]);
$member = $originals->find_assignment_by_id(2201);
$archive->archive_snapshot_batch($source, [$member], '2026-07-14 00:00:00', 'after-change', $new);
$wpdb->query('DELETE FROM ' . $source['source_table'] . ' WHERE id=2201');
$runs->create_run(['run_id'=>'prior-missing', 'source_key'=>$key, 'source_table'=>$source['source_table'],
    'status'=>'completed','enabled'=>1,'dry_run'=>0,'started_at'=>'2026-07-14 00:00:00',
    'finished_at'=>'2026-07-14 02:00:00','archive_db_path'=>$new,'archived_rows'=>1,'deleted_rows'=>1,
    'gate_results_json'=>['deleted_batches'=>[['batch_id'=>'after-change',
        'group'=>array_replace($timed_key,['allocation_version'=>'legacy']),'ids'=>[2201]]]]]);
$archive_db = new PDO('sqlite:' . $new);
$archive_db->exec('DELETE FROM ' . $source['source_table'] . ' WHERE _source_pk=2201');
$archive_db = null;
$audit = $preview(['enabled'=>true]);
$damaged = $read_group($audit,'legacy');
et_check($damaged['status'] === 'incomplete' && $damaged['counters']['assignments']['expected'] === null
    && str_contains(json_encode($damaged['reasons']), '2201'), 'The expected current ID with a missing row has a concrete incomplete finding');
$old_group = $read_group($audit,'original-group');
et_check($old_group['status'] === 'passed' && $old_group['counters']['assignments']['expected'] === 1,
    'An older copy cannot add a phantom contribution to its former group when the newer original is missing');
require_once __DIR__ . '/retention-sms-deletion-evidence-engine.php';
et_sms_deletion_evidence();
