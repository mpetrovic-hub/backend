<?php

require_once ABSPATH . 'includes/repositories/class-sms-body-variant-repository.php';
require_once ABSPATH . 'includes/services/class-retention-sms-archive-batches.php';

function et_sms_batch_fixture(): array
{
    global $wpdb;
    $fixture = et_fixture(new Engagement_Test_Config());
    $root = $fixture['config']->root;
    register_shutdown_function(static function () use ($root): void {
        foreach (glob($root . '/sqlite/*') ?: [] as $file) { if (is_file($file) || is_link($file)) { unlink($file); } }
        if (is_dir($root . '/sqlite')) { rmdir($root . '/sqlite'); }
        if (is_dir($root)) { rmdir($root); }
    });
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
et_check($result['status'] === 'wait' && !is_file($f['path'])
    && (int)$other->query('SELECT COUNT(*) FROM '.$f['source']['source_table'])->fetchColumn() === 1,
    'A target generation already observed by the statistics gate cannot be recreated from a partial SMS batch');

$f = et_sms_batch_fixture();
et_sms_batch_insert($f,6,$f['passed_group']);
et_sms_batch_step($f);
unlink($f['path']);
$missing = et_sms_batch_step($f);
et_check($missing['status'] === 'wait' && !is_file($f['path'])
    && (int)$f['repo']->find_run_by_id($f['run_id'])['deleted_rows'] === 0,
    'An entire generation missing after committed archive progress stops deletion and remains absent');

$f = et_sms_batch_fixture();
et_sms_batch_insert($f,6,$f['passed_group']);
$row = $f['repo']->find_run_by_id($f['run_id']);
$state = json_decode($row['gate_results_json'],true,512,JSON_THROW_ON_ERROR);
$state['required_archive_paths'] = [dirname($f['path']).DIRECTORY_SEPARATOR.'kiwi_retention_archive_2024.sqlite'];
$f['repo']->update_run($f['run_id'],['gate_results_json'=>$state]);
$missing = et_sms_batch_step($f);
et_check($missing['status'] === 'wait' && $missing['reason'] === 'sms_archive_confirmed_file_missing'
    && !is_file($f['path']), 'Loss of a different generation used to establish the group stops a new target write');

$f = et_sms_batch_fixture();
et_sms_batch_insert($f,6,$f['passed_group']);
$handle = $f['lock']->acquire_for_archive($f['path'])['handle'];
$locked = et_sms_batch_step($f);
$f['lock']->release($handle);
et_check($locked['status'] === 'wait' && !is_file($f['path']), 'Another generation lock stops the first SQLite write');
et_sms_batch_step($f);
$wpdb->query('DROP TABLE IF EXISTS t72_kiwi_operational_events');
(new Kiwi_Operational_Event_Repository())->create_table();
$health = new Kiwi_Retention_Archive_Health_Service($f['config'],$f['archive'],$f['lock'],new Kiwi_Operational_Event_Service(),
    run_repository: $f['repo']);
$attempt = bin2hex(random_bytes(16));
clearstatcache(true,$f['path']);
$opened = $health->with_new_attempt($attempt,'manual',basename($f['path']),filesize($f['path']));
$waiting = et_sms_batch_step($f);
et_check($opened['_exit_code'] === 0 && $waiting['status'] === 'wait' && $waiting['reason']==='health_attempt_pending'
    && (int)$other->query('SELECT COUNT(*) FROM '.$f['source']['source_table'])->fetchColumn()===1,
    'A real health attempt established after the archive checkpoint keeps its original in MySQL');
$health->abort($attempt,true);
$handle = $f['lock']->acquire_for_archive($f['path'])['handle'];
$handle->persist_write_blocked();
$f['lock']->release($handle);
et_check(et_sms_batch_step($f)['status'] === 'wait' && (int)$f['repo']->find_run_by_id($f['run_id'])['deleted_rows']===0,
    'A corruption sentinel still prevents deletion once the Pending attempt has closed');
$handle = $f['lock']->acquire_for_archive($f['path'])['handle'];
$handle->clear_write_blocked();
$f['lock']->release($handle);
et_check((int)et_sms_batch_complete($f)['deleted_rows'] === 1, 'A fresh safety check resumes only after both existing gates allow it');

$f = et_sms_batch_fixture();
et_sms_batch_insert($f,6,$f['passed_group']);
$f['archive']->after_commit = static function () use ($f): void {
    $f['archive']->after_commit = null;
    throw new RuntimeException('fixture_discarded_sqlite_commit_ack');
};
$unconfirmed = et_sms_batch_step($f);
$audit = $f['repo']->find_run_by_id($f['run_id']);
$nosql = new PDO('sqlite:'.$f['path']);
et_check($unconfirmed['status']==='failed' && (int)$audit['archived_rows']===0 && (int)$audit['deleted_rows']===0
    && (int)$nosql->query('SELECT COUNT(*) FROM archive_batch_rows')->fetchColumn()===1,
    'Loss of acknowledgement after the SQLite commit leaves a resumption record and never deletes an unconfirmed ID');
$nosql = null;
$resumed = et_sms_batch_complete($f);
$nosql = new PDO('sqlite:'.$f['path']);
et_check((int)$resumed['archived_rows']===1 && (int)$resumed['deleted_rows']===1
    && (int)$nosql->query('SELECT COUNT(*) FROM archive_batches')->fetchColumn()===1
    && (int)$nosql->query('SELECT COUNT(*) FROM archive_batch_rows')->fetchColumn()===1,
    'A retry of the stored package reuses the SQLite commit without a second archive or deletion');
$nosql = null;

$f = et_sms_batch_fixture();
et_sms_batch_insert($f,6,$f['passed_group']);
et_sms_batch_step($f);
$auditTable = $f['repo']->get_table_name();
$other->exec("CREATE TRIGGER deny_sms_delete_audit BEFORE UPDATE ON {$auditTable} FOR EACH ROW
    BEGIN IF NEW.deleted_rows>OLD.deleted_rows THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic rejected audit'; END IF; END");
$failed = et_sms_batch_step($f);
$row = $f['repo']->find_run_by_id($f['run_id']);
et_check($failed['status']==='failed' && (int)$row['archived_rows']===1 && (int)$row['deleted_rows']===0
    && (int)$row['delete_last_primary_key']===0 && empty(json_decode($row['gate_results_json'],true)['deleted_batches'])
    && (int)$other->query('SELECT COUNT(*) FROM '.$f['source']['source_table'])->fetchColumn()===1,
    'A failed deletion checkpoint rolls back the actual MySQL DELETE, manifest and delete cursor together');
$other->exec('DROP TRIGGER deny_sms_delete_audit');
$finished = et_sms_batch_complete($f);
$manifest = json_decode($finished['gate_results_json'],true)['deleted_batches'];
et_check((int)$finished['archived_rows']===1 && (int)$finished['deleted_rows']===1 && $manifest[0]['ids']===[6],
    'After that rollback, exactly one proven deletion and one manifest member commit on retry');

$f = et_sms_batch_fixture();
et_sms_batch_insert($f,6,$f['passed_group']);
et_sms_batch_step($f);
$other->exec("UPDATE ".$f['source']['source_table']." SET allocation_version='changed-group' WHERE id=6");
et_sms_batch_step($f);
$recheck = et_sms_batch_step($f);
$row = $f['repo']->find_run_by_id($f['run_id']);
$state = json_decode($row['gate_results_json'],true,512,JSON_THROW_ON_ERROR);
$newGroup = array_replace($f['passed_group'],['allocation_version'=>'changed-group']);
$newKey = Kiwi_Retention_Sms_Statistics::group_id($newGroup);
$oldKey = Kiwi_Retention_Sms_Statistics::group_id($f['passed_group']);
et_check($recheck['status']==='recheck' && array_values($state['recheck_groups'])===[$f['passed_group'],$newGroup]
    && (int)$row['deleted_rows']===0 && et_sms_batch_step($f)['status']==='recheck',
    'A version move invalidates both group findings durably and prevents reuse after another worker invocation');
$state['groups'][$newKey] = array_replace($state['groups'][$oldKey],['key'=>$newGroup]);
unset($state['recheck_groups']);
$f['repo']->update_run($f['run_id'],['gate_results_json'=>$state]);
$finished = et_sms_batch_complete($f);
$manifest = json_decode($finished['gate_results_json'],true)['deleted_batches'];
$nosql = new PDO('sqlite:'.$f['path']);
et_check($manifest[0]['group']===$newGroup && $manifest[0]['ids']===[6]
    && $nosql->query('SELECT allocation_version FROM '.$f['source']['source_table'].' WHERE _source_pk=6')->fetchColumn()==='changed-group',
    'Only the freshly released new group obtains the recopy and exact MySQL deletion acknowledgement');
$nosql = null;

$f = et_sms_batch_fixture();
et_sms_batch_insert($f,6,$f['passed_group']);
et_sms_batch_step($f);
$baseRun = $f['repo']->find_run_by_id($f['run_id']);
$oldConnection = (int)$wpdb->get_var('SELECT CONNECTION_ID()');
$noop = $f['repo']->update_run_transaction_checkpoint($f['run_id'],
    ['archived_rows'=>$baseRun['archived_rows']], $f['repo']->checkpoint_for_stored_run($baseRun), $oldConnection);
et_check($noop === false, 'Even an identical audit write cannot report transactional success outside its owning transaction');

$f['repo'] = new class($settings,$database) extends Kiwi_Retention_Cleanup_Run_Repository {
    private $settings;
    private $database;
    public function __construct($settings,$database) { $this->settings=$settings; $this->database=$database; }
    public function update_run_transaction_checkpoint(int $id,array $data,array $expected,int $connection_id): bool {
        global $wpdb;
        // Replacing the only connection holding the uncommitted DELETE also drops its named source lock.
        $wpdb->pdo = et_connection($this->settings,$this->database);
        return parent::update_run_transaction_checkpoint($id,$data,$expected,$connection_id);
    }
};
$f['batch_worker'] = new Kiwi_Retention_Sms_Archive_Batches($f['config'],$f['registry'],$f['repo'],$f['archive'],$f['events'],$f['lock'],$f['gate']);
$lost = et_sms_batch_step($f);
$retryRow = $f['repo']->find_run_by_id($f['run_id']);
et_check($lost['status']==='failed' && $oldConnection!==(int)$wpdb->get_var('SELECT CONNECTION_ID()')
    && (int)$retryRow['deleted_rows']===0 && empty(json_decode($retryRow['gate_results_json'],true)['deleted_batches'])
    && (int)$other->query('SELECT COUNT(*) FROM '.$f['source']['source_table'])->fetchColumn()===1,
    'A lost MariaDB connection between DELETE and checkpoint cannot invent a deletion manifest on reconnect');

$f = et_sms_batch_fixture();
et_sms_batch_insert($f,6,$f['passed_group']);
et_sms_batch_step($f);
$f['repo'] = new class($other,$f['source']['source_table']) extends Kiwi_Retention_Cleanup_Run_Repository {
    private $observer;
    private $table;
    private $lose_ack = true;
    public function __construct($observer,string $table) { $this->observer=$observer; $this->table=$table; }
    public function find_run_by_id(int $id): ?array {
        if ($this->lose_ack && (int)$this->observer->query('SELECT COUNT(*) FROM '.$this->table)->fetchColumn()===0) {
            $this->lose_ack=false;
            return null;
        }
        return parent::find_run_by_id($id);
    }
};
$f['batch_worker'] = new Kiwi_Retention_Sms_Archive_Batches($f['config'],$f['registry'],$f['repo'],$f['archive'],$f['events'],$f['lock'],$f['gate']);
$unknown = et_sms_batch_step($f);
$final = et_sms_batch_complete($f);
$manifest=json_decode($final['gate_results_json'],true)['deleted_batches'];
et_check($unknown['status']==='failed' && (int)$final['deleted_rows']===1 && count($manifest)===1 && $manifest[0]['ids']===[6],
    'A lost post-commit acknowledgement resumes from the exact already committed deletion and never repeats it');

$f = et_sms_batch_fixture();
foreach ([2,4,6,8] as $id) { et_sms_batch_insert($f,$id,$f['passed_group']); }
et_sms_batch_step($f,4);
$recorded = $f['repo']->find_run_by_id($f['run_id']);
et_check((int)$recorded['archived_rows']===4 && (int)$recorded['deleted_rows']===0, 'All four receipts are durable before a budget change');
et_sms_batch_step($f,1);
$firstDelete = $f['repo']->find_run_by_id($f['run_id']);
et_check((int)$firstDelete['deleted_rows']===1 && (int)$firstDelete['delete_last_primary_key']===2,
    'A smaller resumed budget processes only the next archived original in ID order');
$done = et_sms_batch_complete($f,1);
$manifest = json_decode($done['gate_results_json'],true)['deleted_batches'];
et_check((int)$done['archived_rows']===4 && (int)$done['deleted_rows']===4 && count($manifest)===1 && $manifest[0]['ids']===[2,4,6,8],
    'Split deletion of one saved batch merges exact membership into its matching group receipt');

$f = et_sms_batch_fixture();
foreach ([2,4,6,8] as $id) { et_sms_batch_insert($f,$id,$f['passed_group']); }
$f['archive']->after_commit = static function () use ($f): void {
    $f['archive']->after_commit = null;
    throw new RuntimeException('fixture_unacknowledged_archive');
};
et_sms_batch_step($f,4);
et_sms_batch_step($f,1);
$recovered = $f['repo']->find_run_by_id($f['run_id']);
et_check((int)$recovered['archived_rows']===1 && (int)$recovered['deleted_rows']===0,
    'Recovery reads back only a bounded prefix of an unacknowledged larger archive batch');
$done = et_sms_batch_complete($f,1);
et_check((int)$done['archived_rows']===4 && (int)$done['deleted_rows']===4 && (int)$done['archive_inserted_rows']===4,
    'The remaining capped prefixes recover the same four SQLite receipts and delete each original once');

$f = et_sms_batch_fixture();
$groupThree = array_replace($f['passed_group'], ['allocation_version'=>'third-group']);
et_sms_batch_insert($f,2,$f['passed_group']);
et_sms_batch_insert($f,12,$groupThree);
$row = $f['repo']->find_run_by_id($f['run_id']);
$state = json_decode($row['gate_results_json'],true,512,JSON_THROW_ON_ERROR);
$gid = Kiwi_Retention_Sms_Statistics::group_id($groupThree);
$state['groups'][$gid] = array_replace($state['groups'][Kiwi_Retention_Sms_Statistics::group_id($f['passed_group'])],['key'=>$groupThree]);
unset($state['groups'][$gid]['after']['counters']['assignments']);
$f['repo']->update_run($f['run_id'],['gate_results_json'=>$state]);
$invalid = et_sms_batch_step($f,1);
et_check($invalid['status']==='failed' && !is_file($f['path']) && (int)$other->query('SELECT COUNT(*) FROM '.$f['source']['source_table'])->fetchColumn()===2,
    'An unconfirmable saved release in a later group stops the whole run before archiving an earlier passed group');

$f = et_sms_batch_fixture();
et_sms_batch_insert($f,2,$f['passed_group']);
et_sms_batch_insert($f,3,$f['passed_group']);
et_sms_batch_step($f);
$nosql = new PDO('sqlite:'.$f['path']);
$nosql->exec('DELETE FROM archive_batch_rows WHERE source_pk=2');
$nosql->exec('DELETE FROM '.$f['source']['source_table'].' WHERE _source_pk=3');
$nosql = null;
et_sms_batch_step($f);
et_check((int)$other->query('SELECT COUNT(*) FROM '.$f['source']['source_table'])->fetchColumn()===2,
    'Neither a missing single-ID receipt nor a missing archive row authorizes DELETE');
$repaired = et_sms_batch_complete($f);
$nosql = new PDO('sqlite:'.$f['path']);
$repairedCount = $nosql->query('SELECT COUNT(*) FROM '.$f['source']['source_table'].' s JOIN archive_batch_rows r
    ON r.archive_batch_id=s._archive_batch_id AND r.source_pk=s._source_pk')->fetchColumn();
et_check((int)$repairedCount===2 && (int)$repaired['archived_rows']===2 && (int)$repaired['deleted_rows']===2,
    'A fresh snapshot transaction restores both missing individual proofs in the chosen generation before deletion');
$nosql = null;
