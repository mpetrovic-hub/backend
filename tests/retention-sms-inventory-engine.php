<?php

// Public worker, real database/archive services, with scheduling controlled only at the OS-lock boundary.
final class Sms_Inventory_Test_Lock extends Kiwi_Retention_Archive_Lock
{
    public $before_acquire;
    public function acquire_for_archive(string $path): array
    {
        if ($this->before_acquire !== null) {
            $callback = $this->before_acquire;
            $callback($path);
        }
        return parent::acquire_for_archive($path);
    }
}

$config = new Kiwi_Config();
$archive = new Kiwi_Retention_Sqlite_Archive_Service($config);
$archive->resolve_archive_db_path();
$generationLock = new Sms_Inventory_Test_Lock();
$events = new Kiwi_Operational_Event_Service();
$gateService = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator($generationLock, $events, $runs);
$reader = new Kiwi_Retention_Archive_Reader($archive, $generationLock, $gateService);
$assignments = new Kiwi_Sms_Body_Variant_Service($config, $originals, $reader);
$worker = new Kiwi_Retention_Sms_Worker(new Kiwi_Retention_Sms_Statistics($reader, $runs,
    Kiwi_Nth_Sms_Sale_Evidence::for_assignments($assignments)), $config, new Kiwi_Retention_Source_Registry(),
    $runs, $archive, $events, $generationLock, $gateService, new Kiwi_Retention_Archive_Health_Store(), $snapshots);
$start = static function () use ($worker, $key, $runs): array {
    $result = $worker->run_source($key);
    $run = $runs->find_open_run_for_source($key);
    if (!$run || $run['run_id'] !== ($result['run_id'] ?? '')) { throw new RuntimeException('Inventory fixture start failed'); }
    return $run;
};
$step = static function (array $run) use ($worker, $key, $runs): array {
    $result = $worker->run_worker($key);
    $stored = $runs->find_run_by_id((int) $run['id']);
    if ($stored['status'] === 'failed') { throw new RuntimeException('Inventory worker failed: ' . json_encode($result)); }
    return $stored;
};
$until = static function (array $run, string $phase) use ($step): array {
    for ($i = 0; $i < 200; ++$i) {
        if ($run['worker_phase'] === $phase) { return $run; }
        if ($run['finished_at'] !== null) { break; }
        $run = $step($run);
    }
    throw new RuntimeException('Inventory fixture did not reach ' . $phase . ': ' . json_encode([$run['worker_phase'], $run['error_code']]));
};
$groupKey = ['landing_key'=>'inventory190', 'service_key'=>'sms190', 'variant_key'=>'bare_id', 'seed'=>'', 'allocation_version'=>'legacy'];
$groupId = Kiwi_Retention_Sms_Statistics::group_id($groupKey);
$case = $argv[2] ?? 'discovery';
$history = [];
foreach (str_starts_with($case, 'targeted') ? [1, 2, 3, 4, 6, 7, 8, 9, 10, 20] : [1, 2, 3, 4, 10] as $id) {
    $rowGroup = $id > 5 && $id !== 10 ? array_replace($groupKey, ['allocation_version'=>'second']) : $groupKey;
    $row = et_full_row($source, $rowGroup + ['id'=>$id, 'created_at'=>'2025-01-01 00:00:00',
        'updated_at'=>'2025-01-01 00:00:00', 'visible_token'=>'inventory'.$id, 'transaction_id'=>'txn_inventory_'.$id]);
    if ($id < 10) { $history[] = $row; }
}
$oldPath = $archive->get_archive_directory() . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2025.sqlite';
$newPath = $archive->get_archive_directory() . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2025_part_2.sqlite';
$archive->archive_snapshot_batch($source, $history, '2026-07-14 00:00:00', 'inventory-original', $oldPath);
$wpdb->query('DELETE FROM ' . $source['source_table'] . ' WHERE id<10');
$summary = $wpdb->prefix . 'kiwi_sms_body_variant_summary';
$deleting = str_starts_with($case, 'delete');
$initialActual = $deleting || str_starts_with($case, 'targeted') ? 5 : 0;
$wpdb->insert($summary, $groupKey + ['created_at'=>'2025-01-01', 'updated_at'=>'2025-01-01', 'assignments'=>$initialActual]);
if (str_starts_with($case, 'targeted')) {
    $secondGroup = array_replace($groupKey, ['allocation_version'=>'second']);
    $wpdb->insert($summary, $secondGroup + ['created_at'=>'2025-01-01', 'updated_at'=>'2025-01-01', 'assignments'=>5]);
    $run = $until($start(), 'sms_archive');
    $sourceLock = $runs->acquire_source_creation_lock($key);
    try {
        $wpdb->query('START TRANSACTION');
        et_check(Kiwi_Retention_Sms_Worker::invalidate_group($runs, $groupKey, $sourceLock),
            'A targeted review is requested through the public manual-repair contract');
        $wpdb->query('COMMIT');
    } finally { $runs->release_source_creation_lock($sourceLock); }
    $run = $step($run);
    et_check($run['worker_phase'] === 'sms_recheck', 'The worker begins its originally single-group review');
    $changeSecond = static function () use ($source, $oldPath): void {
        $sqlite = new PDO('sqlite:' . $oldPath);
        $sqlite->exec("UPDATE {$source['source_table']} SET allocation_version='moved1' WHERE id BETWEEN 6 AND 9");
    };
    if ($case === 'targeted-fast') {
        $acquisitions = 0;
        $generationLock->before_acquire = static function (string $path) use (&$acquisitions, $generationLock, $oldPath, $changeSecond): void {
            if ($path !== $oldPath || ++$acquisitions < 2) { return; }
            $generationLock->before_acquire = null;
            $changeSecond();
        };
    } else { $changeSecond(); }
    $run = $until($run, 'completed');
    $state = json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
    $secondId = Kiwi_Retention_Sms_Statistics::group_id($secondGroup);
    et_check($state['groups'][$secondId]['after']['counters']['assignments']['expected'] === 1
        && $state['groups'][$secondId]['after']['status'] === 'outside_tolerance'
        && $originals->find_assignment_by_id(20) !== null && $originals->find_assignment_by_id(10) === null,
        'An archive change during a targeted review also remeasures the other released group before deleting its original');
    return;
}
$run = $until($start(), 'sms_correction');
$state = json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
et_check($state['groups'][$groupId]['before']['counters']['assignments']['expected'] === 5,
    'The initial public worker measurement includes four historical originals and one live original');
if ($case === 'legacy') {
    unset($state['archive_basis']);
    $runs->update_run((int) $run['id'], ['gate_results_json'=>$state]);
    $run = $step($run);
    et_check($run['worker_phase'] === 'sms_recheck' && (int) $wpdb->get_var('SELECT assignments FROM ' . $summary) === 0,
        'An older saved finding without archive-basis evidence is remeasured before correction');
    $run = $until($run, 'completed');
    $state = json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
    et_check($state['groups'][$groupId]['after']['counters']['assignments']['expected'] === 5
        && (int) $run['deleted_rows'] === 1 && (int) $wpdb->get_var('SELECT assignments FROM ' . $summary) === 5,
        'A legacy checkpoint completes only after a current five-original measurement and exact receipt');
    return;
}
if ($deleting) {
    $run = $until($run, 'sms_archive');
    $run = $step($run);
    et_check((int) $run['archived_rows'] === 1 && (int) $run['deleted_rows'] === 0,
        'The public worker confirms the candidate receipt before the delete-race fixture');
}
$published = false;
$acquisitions = 0;
$generationLock->before_acquire = static function (string $path) use (&$published, &$acquisitions, $case, $generationLock, $archive, $source, $history, $oldPath, $newPath): void {
    if ($path !== $oldPath) { return; }
    if (++$acquisitions < ($case === 'discovery' ? 1 : 2)) { return; }
    $generationLock->before_acquire = null;
    if (str_contains($case, 'change') || str_contains($case, 'replace')) {
        $modified = str_contains($case, 'replace') ? $oldPath . '.replacement' : $oldPath;
        if ($modified !== $oldPath) { copy($oldPath, $modified); }
        $mtime = filemtime($oldPath);
        $size = filesize($oldPath);
        $sqlite = new PDO('sqlite:' . $modified);
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sqlite->exec("UPDATE {$source['source_table']} SET allocation_version='moved1'");
        $sqlite = null;
        touch($modified, $mtime);
        if ($modified !== $oldPath) {
            rename($oldPath, $oldPath . '.previous');
            rename($modified, $oldPath);
        }
        clearstatcache(true, $oldPath);
        et_check(filesize($oldPath) === $size && filemtime($oldPath) === $mtime,
            'The changed archive deliberately retains its length and modification timestamp');
    } else {
        $moved = array_map(static fn(array $row): array => array_replace($row, ['allocation_version'=>'reassigned']), $history);
        $archive->archive_snapshot_batch($source, $moved, '2026-07-14 00:00:00', 'inventory-newer', $newPath);
    }
    $published = true;
};
$run = $step($run);
et_check($published && $run['worker_phase'] === 'sms_recheck'
    && (int) $wpdb->get_var('SELECT assignments FROM ' . $summary) === $initialActual
    && (int) $run['deleted_rows'] === 0 && $originals->find_assignment_by_id(10) !== null,
    'Changed archive evidence at the ' . $case . ' lock boundary forces remeasurement before stale correction or deletion');
$run = $until($run, 'sms_archive');
$state = json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
et_check($state['groups'][$groupId]['after']['counters']['assignments']['expected'] === 1
    && (int) $wpdb->get_var('SELECT assignments FROM ' . $summary) === ($deleting ? 5 : 1),
    'The resumed worker uses the newly evidenced one-original total and never lowers a counter');
$releasedFinding = $state['groups'][$groupId];
$run = $until($run, 'completed');
et_check((int) $run['deleted_rows'] === ($deleting ? 0 : 1)
    && ($originals->find_assignment_by_id(10) === null) === !$deleting,
    'An unchanged continuation respects the fresh group result through verified copy and delete');
$completedState = json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
et_check($completedState['groups'][$groupId] === $releasedFinding,
    'Confirmed writes by this package retain the measured release without an unnecessary new review');
