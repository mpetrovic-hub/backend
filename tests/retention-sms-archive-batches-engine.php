<?php

require_once ABSPATH . 'includes/repositories/class-sms-body-variant-repository.php';
require_once ABSPATH . 'includes/services/class-retention-sms-archive-batches.php';

function et_sms_batch_fixture(): array
{
    global $wpdb;
    $fixture = et_fixture(new Engagement_Test_Config());
    $wpdb->query('DROP TABLE IF EXISTS t72_kiwi_sms_body_variant_assignments, t72_kiwi_sms_body_variant_summary');
    (new Kiwi_Sms_Body_Variant_Repository())->create_table();
    $source = Kiwi_Retention_Source_Registry::sms_body_variant_assignments();
    $first = ['landing_key'=>'sms-batch','service_key'=>'sms-test','variant_key'=>'game_word','seed'=>'Hero','allocation_version'=>'legacy'];
    $blocked = array_replace($first, ['allocation_version'=>'blocked']);
    $groups = [];
    foreach ([[$first,'passed'],[$blocked,'outside_tolerance']] as [$key,$status]) {
        $after = ['status'=>$status, 'reasons'=>[], 'counters'=>array_fill_keys(
            array_keys(Kiwi_Retention_Sms_Statistics::MARKERS), ['status'=>$status])];
        $groups[Kiwi_Retention_Sms_Statistics::group_id($key)] = ['key'=>$key,'before'=>['status'=>$status],
            'correction'=>[],'after'=>$after];
    }
    $scope = ['run_id'=>'r187-', 'cutoff_value'=>'2026-07-14 00:00:00',
        'target_max_primary_key'=>12, 'archive_db_path'=>$fixture['archive']->resolve_archive_db_path()];
    $state = ['version'=>1, 'progress'=>['phase'=>'released'], 'groups'=>$groups, 'archive_scope'=>$scope];
    $run_id = $fixture['repo']->create_run($scope + ['source_key'=>$source['source_key'], 'source_table'=>$source['source_table'],
        'cutoff_column'=>'created_at', 'dry_run'=>0, 'enabled'=>1, 'triggered_by'=>'cron', 'status'=>'running',
        'worker_phase'=>'sms_archive', 'gate_status'=>'partial', 'gate_results_json'=>$state]);
    $fixture['batch_worker'] = new Kiwi_Retention_Sms_Archive_Batches($fixture['config'], $fixture['registry'], $fixture['repo'],
        $fixture['archive'], $fixture['events'], $fixture['lock'], $fixture['gate']);
    $fixture['source'] = $source;
    $fixture['path'] = $scope['archive_db_path'];
    $fixture['run_id'] = $run_id;
    $fixture['passed_group'] = $first;
    $fixture['blocked_group'] = $blocked;
    return $fixture;
}

function et_sms_batch_insert(array $f, int $id, array $group, string $date='2026-07-01 00:00:00'): array
{
    return et_full_row($f['source'], $group + ['id'=>$id,'created_at'=>$date,'updated_at'=>$date,
        'visible_token'=>'block'.$id,'transaction_id'=>'txn_packet_'.$id,'raw_context'=>'{"body":"Ünicode"}']);
}

function et_sms_batch_step(array $f, int $limit=2): array
{
    $lock = $f['repo']->acquire_source_creation_lock($f['source']['source_key']);
    if (!$lock) { throw new RuntimeException('SMS fixture source lock unavailable'); }
    try {
        $run = $f['repo']->find_run_by_id($f['run_id']);
        return $f['batch_worker']->advance($run, $limit, $lock);
    } finally { $f['repo']->release_source_creation_lock($lock); }
}

function et_sms_batch_complete(array $f, int $limit=2): array
{
    for ($i=0; $i<40; ++$i) {
        $result = et_sms_batch_step($f, $limit);
        if ($result['status'] === 'done') { return $f['repo']->find_run_by_id($f['run_id']); }
        if ($result['status'] !== 'more') { throw new RuntimeException('unexpected SMS batch result '.json_encode($result)); }
    }
    throw new RuntimeException('SMS batch fixture did not finish');
}

$f = et_sms_batch_fixture();
et_sms_batch_insert($f, 2, $f['passed_group']);
et_sms_batch_insert($f, 4, $f['blocked_group']);
et_sms_batch_insert($f, 8, $f['passed_group']);
et_sms_batch_insert($f, 11, $f['passed_group'], '2026-07-14 00:00:00');
et_sms_batch_insert($f, 13, $f['passed_group']);
$run = et_sms_batch_complete($f);
$live = array_map('intval', $wpdb->get_col('SELECT id FROM '.$f['source']['source_table'].' ORDER BY id'));
et_check($live === [4,11,13] && (int)$run['archived_rows'] === 2 && (int)$run['deleted_rows'] === 2,
    'Only two eligible IDs of the released group leave MySQL; cutoff, ceiling and independently blocked group survive');

$archived = new PDO('sqlite:'.$f['path']);
$proofRows = $archived->query('SELECT s.*,b.archive_db_path,b.status AS batch_status FROM '.$f['source']['source_table'].' s
    JOIN archive_batch_rows r ON s._archive_batch_id=r.archive_batch_id AND s.id=r.source_pk
    JOIN archive_batches b ON r.archive_batch_id=b.archive_batch_id ORDER BY s.id')->fetchAll(PDO::FETCH_ASSOC);
$deletions = json_decode($run['gate_results_json'],true,512,JSON_THROW_ON_ERROR)['deleted_batches'];
et_check(array_map('intval',array_column($proofRows,'id')) === [2,8] && array_column($proofRows,'raw_context') === ['{"body":"Ünicode"}','{"body":"Ünicode"}']
    && array_column($proofRows,'archive_db_path') === [$f['path'],$f['path']] && array_column($proofRows,'batch_status') === ['success','success'],
    'The chosen SQLite generation contains the exact IDs, full source content and their successfully committed receipts');
foreach ($deletions as $batch) {
    foreach ($batch['ids'] as $id) {
        $byId = array_column($proofRows,null,'id');
        et_check($batch['group'] === $f['passed_group'] && $byId[$id]['_archive_batch_id'] === $batch['batch_id'], 'Each MySQL deletion lists its exact SQLite batch and group');
    }
}
et_check(array_merge(...array_column($deletions,'ids')) === [2,8], 'Deletion manifest follows increasing original IDs without attributing skipped IDs to the run');
$archived = null;

$f = et_sms_batch_fixture();
$before = et_sms_batch_insert($f,5,$f['passed_group']);
et_check(et_sms_batch_step($f)['status'] === 'more', 'First released step confirms an archive batch');
$copied = $f['repo']->find_run_by_id($f['run_id']);
et_check((int)$copied['archive_last_primary_key'] === 5 && (int)$copied['archived_rows'] === 1
    && (int)$copied['deleted_rows'] === 0 && (int)$copied['delete_last_primary_key'] === 0
    && (int)$other->query('SELECT COUNT(*) FROM '.$f['source']['source_table'].' WHERE id=5')->fetchColumn() === 1,
    'A second MariaDB connection sees committed archive progress while the verified original still exists');
$other->exec("UPDATE ".$f['source']['source_table']." SET raw_context='changed since checkpoint',cta1_recorded_at='2026-10-12 09:00:00' WHERE id=5");
$nosql = new PDO('sqlite:'.$f['path']);
$nosql->exec("UPDATE ".$f['source']['source_table']." SET raw_context='changed since checkpoint',cta1_recorded_at='2026-10-12 09:00:00' WHERE id=5");
$nosql = null;
et_sms_batch_step($f);
et_check((int)$other->query('SELECT COUNT(*) FROM '.$f['source']['source_table'].' WHERE id=5')->fetchColumn() === 1,
    'Content changed on both sides after the confirmed checkpoint requires fresh proof before DELETE');
$changedRun = et_sms_batch_complete($f);
$nosql = new PDO('sqlite:'.$f['path']);
et_check($nosql->query('SELECT raw_context FROM '.$f['source']['source_table'].' WHERE id=5')->fetchColumn() === 'changed since checkpoint'
    && (int)$changedRun['archived_rows'] === 1 && (int)$changedRun['deleted_rows'] === 1,
    'Refreshing a changed version preserves its new contents and counts an original ID once');
$nosql = null;

$f = et_sms_batch_fixture();
et_sms_batch_insert($f,7,$f['passed_group']);
$row = $f['repo']->find_run_by_id($f['run_id']);
$state = json_decode($row['gate_results_json'],true,512,JSON_THROW_ON_ERROR);
unset($state['groups'][Kiwi_Retention_Sms_Statistics::group_id($f['passed_group'])]['after']['counters']['conv']);
$f['repo']->update_run($f['run_id'],['gate_results_json'=>$state]);
$incomplete = et_sms_batch_step($f);
et_check($incomplete['status'] === 'failed' && !is_file($f['path'])
    && (int)$other->query('SELECT COUNT(*) FROM '.$f['source']['source_table'])->fetchColumn() === 1,
    'A stored passed label with a missing individual finding cannot start even the first archive copy');

$f = et_sms_batch_fixture();
et_sms_batch_insert($f,7,$f['passed_group']);
et_sms_batch_step($f);
$wpdb->pdo->exec('ALTER TABLE '.$f['source']['source_table'].' ENGINE=MyISAM');
$unsupported = et_sms_batch_step($f);
et_check($unsupported['status'] === 'failed' && (int)$other->query('SELECT COUNT(*) FROM '.$f['source']['source_table'])->fetchColumn()===1
    && (int)$f['repo']->find_run_by_id($f['run_id'])['deleted_rows']===0,
    'Deletion and its acknowledgement require transactional support on both participating tables');

$f = et_sms_batch_fixture();
et_sms_batch_insert($f,3,$f['passed_group']);
et_sms_batch_step($f);
$copied = $f['repo']->find_run_by_id($f['run_id']);
$nosql = new PDO('sqlite:'.$f['path']);
$nosql->exec("UPDATE archive_batches SET cutoff_value='2026-07-01 00:00:00'");
$nosql = null;
et_sms_batch_step($f);
et_check((int)$other->query('SELECT COUNT(*) FROM '.$f['source']['source_table'])->fetchColumn()===1,
    'A receipt with a different frozen cutoff cannot authorize an original deletion');
$finished = et_sms_batch_complete($f);
$nosql = new PDO('sqlite:'.$f['path']);
$currentBatch = $nosql->query('SELECT _archive_batch_id FROM '.$f['source']['source_table'])->fetchColumn();
$cutoff = $nosql->query('SELECT cutoff_value FROM archive_batches WHERE archive_batch_id='.$nosql->quote($currentBatch))->fetchColumn();
et_check($currentBatch !== $copied['archive_batch_id'] && $cutoff === $finished['cutoff_value']
    && (int)$finished['archived_rows']===1 && (int)$finished['deleted_rows']===1,
    'A new committed receipt in the same generation repairs the affected live copy without counting its ID again');
$nosql = null;

$f = et_sms_batch_fixture();
et_sms_batch_insert($f,6,$f['passed_group']);
$row = $f['repo']->find_run_by_id($f['run_id']);
$state = json_decode($row['gate_results_json'],true,512,JSON_THROW_ON_ERROR);
$state['archive_target_existed'] = true;
$f['repo']->update_run($f['run_id'],['gate_results_json'=>$state]);
$result = et_sms_batch_step($f);
et_check($result['status'] === 'failed' && !is_file($f['path'])
    && (int)$other->query('SELECT COUNT(*) FROM '.$f['source']['source_table'])->fetchColumn() === 1,
    'A target generation already observed by the statistics gate cannot be recreated from a partial SMS batch');

$f = et_sms_batch_fixture();
et_sms_batch_insert($f,6,$f['passed_group']);
et_sms_batch_step($f);
unlink($f['path']);
$missing = et_sms_batch_step($f);
et_check($missing['status'] === 'failed' && !is_file($f['path'])
    && (int)$f['repo']->find_run_by_id($f['run_id'])['deleted_rows'] === 0,
    'An entire generation missing after committed archive progress stops deletion and remains absent');
