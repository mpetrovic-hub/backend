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
$wpdb->query('UPDATE ' . $source['source_table'] . ' SET service_key=UPPER(service_key) WHERE id IN (2100,2111)');
$timed = $originals->find_assignment_by_id(2100);
$unknown = $originals->find_assignment_by_id(2111);
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
$confirmed_sale = static function (string $ref, string $code) use ($events, $sales, $flows): array {
    $mo = $events->insert_if_new(['provider'=>'nth','service_key'=>'sms','event_type'=>'mo_callback','direction'=>'inbound',
        'keyword'=>'PLAY','message_text'=>'PLAY ' . $code,'dedupe_key'=>$ref])['row'];
    $sale = $sales->upsert(['sale_reference'=>$ref,'provider_key'=>'nth','service_key'=>'sms','sale_type'=>'premium_sms_one_off',
        'status'=>'completed','transaction_id'=>'txn_unrelated_click']);
    $flows->create(['flow_reference'=>$ref,'sale_reference'=>$ref,'service_key'=>'sms','keyword'=>'PLAY',
        'sale_id'=>(int)$sale['id'],'mo_event_id'=>(int)$mo['id']]);
    return $sale;
};
foreach ([2100,2111] as $n) {
    $sale = $confirmed_sale('timed-sale-' . $n, 'code' . $n);
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
$sales_table = $sales->get_table_name_for_schema();
$wpdb->query("ALTER TABLE {$sales_table} MODIFY service_key VARCHAR(100) COLLATE utf8mb4_bin NOT NULL DEFAULT ''");
$wpdb->query("UPDATE {$source['source_table']} SET service_key=UPPER(service_key)");
$compare(10, [10,11], 'passed');
$compare(8, [10,11], 'outside_tolerance');
$wpdb->query("UPDATE {$source['source_table']} SET service_key=LOWER(service_key)");
$wpdb->query("ALTER TABLE {$sales_table} MODIFY service_key VARCHAR(100) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT ''");
if (in_array('--sms-live-time', $argv, true)) {
    et_create_health_options();
    foreach ([8 => 'outside_tolerance', 10 => 'passed'] as $actual => $status) {
        $wpdb->update($summary, ['conv' => $actual], ['allocation_version' => 'timed']);
        $live = $preview(['enabled' => true, 'dry_run' => false]);
        $state = json_decode($live['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
        $group = $state['groups'][Kiwi_Retention_Sms_Statistics::group_id($timed_key)];
        $incident = $wpdb->get_row($wpdb->prepare('SELECT message,context_json,lifecycle_action FROM '
            . (new Kiwi_Operational_Event_Repository())->get_table_name() . ' WHERE reference_id=%s AND correlation_key=%s',
            $live['run_id'], 'retention_sms_group_' . Kiwi_Retention_Sms_Statistics::group_id($timed_key)), ARRAY_A);
        $details = json_decode($incident['context_json'] ?? '{}', true)['counters']['conv'] ?? [];
        if ($actual === 8) {
            et_check($incident['lifecycle_action'] === 'raised' && $details['possible_expected_range'] === [10,11]
                && $details['failing_comparison'] === ['expected'=>11,'distance'=>3,'limit'=>2,'status'=>'outside_tolerance']
                && str_contains($incident['message'], 'möglicher Sollwert 11'),
                'The group incident identifies the failing alternative for a time-uncertain confirmed sale');
        } else {
            et_check($incident['lifecycle_action'] === 'resolved' && $details['possible_expected_range'] === [10,11],
                'The saved assessment that passes every possible total resolves the same conv incident');
        }
        $left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$source['source_table']} WHERE allocation_version='timed'");
        et_check($group['correction']['counters']['conv'] === ['status' => 'skipped', 'reason' => 'sale_time_uncertain']
            && $group['after']['counters']['conv']['actual'] === $actual && $group['after']['status'] === $status
            && $group['after']['counters']['conv']['possible_expected'] === [10, 11],
            'Live uncertain sale timing keeps conv=' . $actual . ' and records the fresh result for both exact possibilities');
        et_check($left === ($actual === 8 ? 9 : 0),
            'Actual ' . $actual . ' admits deletion only when each possible conv total is within tolerance');
    }
    return;
}
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
$missing_file = $new . '.test-missing';
rename($new, $missing_file);
try {
    $audit = $preview(['enabled' => true]);
    $old_group = $read_group($audit, 'original-group');
    $latest_group = $read_group($audit, 'legacy');
    et_check($old_group['status'] === 'passed' && $old_group['counters']['assignments']['expected'] === 1
        && $latest_group['status'] === 'incomplete',
        'A missing newer file cannot reassign an original ID to the group of an older remaining copy');
} finally {
    rename($missing_file, $new);
}
$archive->archive_snapshot_batch($source, [$member], '2026-07-14 00:00:00', 'after-change', $new);

$insert(2300, '2025-01-01 00:00:00', 'lost-file');
$gone = $insert(2301, '2025-01-01 00:00:00', 'lost-file');
$lost_archive = dirname($new) . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2019.sqlite';
$archive->archive_snapshot_batch($source, [$gone], '2026-07-14 00:00:00', 'lost-2301', $lost_archive);
$wpdb->query('DELETE FROM ' . $source['source_table'] . ' WHERE id=2301');
$wpdb->insert($summary, $summary_row + ['allocation_version' => 'lost-file', 'assignments' => 2]);
$runs->create_run(['run_id' => 'lost-2301', 'source_key' => $key, 'source_table' => $source['source_table'],
    'status' => 'completed', 'enabled' => 1, 'dry_run' => 0, 'archive_db_path' => $lost_archive, 'deleted_rows' => 1,
    'gate_results_json' => ['deleted_batches' => [['batch_id' => 'lost-2301', 'group' => array_replace($timed_key,
        ['allocation_version' => 'lost-file']), 'ids' => [2301]]]]]);
$confirmed_sale('confirmed-with-unmatched-code', 'NoOriginal2301');
$other = $insert(2302, '2025-01-01 00:00:00', 'original-group');
$live = $insert(2303, '2025-01-01 00:00:00', 'original-group', ['visible_token' => 'NoOriginal2301']);
$archive->archive_snapshot_batch($source, [$other, $live], '2026-07-14 00:00:00', 'independent-2302', $old);
$wpdb->query('DELETE FROM ' . $source['source_table'] . ' WHERE id=2302');
$wpdb->update($source['source_table'], ['visible_token' => 'DifferentCurrentCode'], ['id' => 2303]);
$wpdb->update($summary, ['assignments' => 3], ['allocation_version' => 'original-group']);
$complete = $preview(['enabled' => true]);
$intact = $read_group($complete, 'timed');
$independent = $read_group($complete, 'original-group');
rename($lost_archive, $lost_archive . '.missing');
try {
    $audit = $preview(['enabled' => true]);
    $loss = $read_group($audit, 'lost-file');
    $candidate = $read_group($audit, 'timed');
    et_check($intact['status'] === 'passed' && $candidate['status'] === 'incomplete' && $loss['status'] === 'incomplete'
        && in_array('sale_original_read_error', array_column($candidate['reasons'], 'code'), true)
        && in_array('sale_original_read_error', array_column($loss['reasons'], 'code'), true)
        && $independent['status'] === 'passed' && $read_group($audit, 'original-group') === $independent,
        'Only possible sale candidates and the absent original group lose proof when an archived code is unknown');
} finally {
    rename($lost_archive . '.missing', $lost_archive);
}
$returned = $preview(['enabled' => true]);
et_check($read_group($returned, 'timed') === $intact && $read_group($returned, 'lost-file')['status'] === 'passed',
    'Restoring the actual code evidence recovers the possible timed totals and the missing group');
$archive_version = static function (int $id, string $version, string $path) use ($insert,$archive,$source,$runs,$key,$timed_key,$wpdb): void {
    $restored = $insert($id, '2025-01-01 00:00:00', $version, ['visible_token' => $version . $id]);
    $batch = 'restored-' . $id . '-' . $version;
    $archive->archive_snapshot_batch($source, [$restored], '2026-07-14 00:00:00', $batch, $path);
    $runs->create_run(['run_id' => $batch, 'source_key' => $key, 'source_table' => $source['source_table'],
        'status' => str_ends_with($version, 'former') ? 'completed' : 'failed', 'enabled' => 1, 'dry_run' => 0, 'started_at' => '2026-07-14 00:00:00',
        'finished_at' => '2026-07-14 02:00:00', 'archive_db_path' => $path, 'deleted_rows' => 1,
        'gate_results_json' => ['deleted_batches' => [['batch_id' => $batch, 'group' => array_replace($timed_key,
            ['allocation_version' => $version]), 'ids' => [$id]]]]]);
    $wpdb->query('DELETE FROM ' . $source['source_table'] . ' WHERE id=' . $id);
};
$versions = [];
foreach (['former' => 2016, 'current' => 2017] as $version => $year) {
    $insert($year + 3000, '2025-01-01 00:00:00', $version);
    $path = dirname($new) . DIRECTORY_SEPARATOR . Kiwi_Retention_Archive_Name::build((string)$year);
    $archive_version(2399, $version, $path);
    $wpdb->insert($summary, $summary_row + ['allocation_version' => $version, 'assignments' => $version === 'former' ? 1 : 2]);
    $versions[$version] = $path;
}
foreach ($versions as $absent => $path) {
    rename($path, $path . '.missing');
    try {
        $audit = $preview(['enabled' => true]);
        $former = $read_group($audit, 'former');
        $current = $read_group($audit, 'current');
        et_check($former['status'] === 'passed' && $former['counters']['assignments']['expected'] === 1
            && ($absent === 'former' ? $current['status'] === 'passed' && $current['counters']['assignments']['expected'] === 2
                : $current['status'] === 'incomplete'),
            'Only loss of the required current version can block a repeatedly archived original: missing ' . $absent);
    } finally { rename($path . '.missing', $path); }
}
$same = dirname($new) . DIRECTORY_SEPARATOR . Kiwi_Retention_Archive_Name::build('2018');
foreach (['same-former' => 2400, 'same-current' => 2401] as $version => $live_id) {
    $insert($live_id, '2025-01-01 00:00:00', $version);
    $archive_version(2398, $version, $same);
    $wpdb->insert($summary, $summary_row + ['allocation_version' => $version, 'assignments' => $version === 'same-former' ? 1 : 2]);
    if ($version === 'same-former') { copy($same, $same . '.former'); }
}
$lookup = new Kiwi_Sms_Body_Variant_Service($config, $originals);
$current_run = $preview(['enabled' => true]);
et_check($read_group($current_run, 'same-former')['status'] === 'passed' && $read_group($current_run, 'same-current')['status'] === 'passed'
    && $lookup->resolve_assignment_from_visible_token('same-current2398')['status'] === 'found',
    'The later acknowledged payload for a reused ID supersedes its earlier batch in the same file');
rename($same, $same . '.latest');
rename($same . '.former', $same);
try {
    $old_run = $preview(['enabled' => true]);
    et_check($read_group($old_run, 'same-former')['status'] === 'passed' && $read_group($old_run, 'same-current')['status'] === 'incomplete'
        && $lookup->resolve_assignment_from_visible_token('same-former2398')['status'] === 'read_error',
        'Restoring the earlier same-file batch cannot revive its code or count its superseded ID in the former group');
} finally {
    rename($same, $same . '.former');
    rename($same . '.latest', $same);
    unlink($same . '.former');
}
require_once __DIR__ . '/retention-sms-deletion-evidence-engine.php';
et_sms_deletion_evidence();
require __DIR__ . '/retention-sms-group-collation-engine.php';
