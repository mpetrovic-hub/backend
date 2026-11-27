<?php

// Observe the real scheduled worker, its MySQL findings, and the append-only incident lifecycle.
$summary = $wpdb->prefix . 'kiwi_sms_body_variant_summary';
$events = (new Kiwi_Operational_Event_Repository())->get_table_name();
$type = 'retention_cleanup_group_blocked';
$group = ['landing_key'=>'test188', 'service_key'=>'sms188', 'variant_key'=>'game_word', 'seed'=>'Hero', 'allocation_version'=>'v1'];
$other_group = array_replace($group, ['seed'=>'Other']);
$original = static function (int $id, array $key) use ($source): void {
    et_full_row($source, $key + ['id'=>$id, 'created_at'=>'2025-11-01 00:00:00', 'updated_at'=>'2025-11-02 00:00:00',
        'transaction_id'=>'txn_incident_0000' . $id, 'visible_token'=>'IncidentCode' . $id]);
};
$finish = static function (array $run) use ($until): array {
    return $until($run, static fn(array $row): bool => $row['finished_at'] !== null);
};
$history = static fn(): array => $wpdb->get_results($wpdb->prepare("SELECT * FROM {$events} WHERE event_type=%s ORDER BY id", $type), ARRAY_A);
$original(1,$group);
$original(2,$other_group);
foreach ([[$group,12],[$other_group,1]] as [$key_data,$actual]) {
    $wpdb->insert($summary, $key_data + ['assignments'=>$actual, 'created_at'=>'2025-11-01', 'updated_at'=>'2025-11-02']);
}
$run=$finish($start());
$entries=$history();
et_check(count($entries)===1 && $entries[0]['lifecycle_action']==='raised' && $entries[0]['area']==='retention'
    && $entries[0]['severity']==='error' && $entries[0]['reference_type']==='retention_cleanup_run'
    && $entries[0]['reference_id']===$run['run_id'], 'The live worker raises an error for the blocked group with its saved run reference');

$event=$entries[0];
$context=json_decode($event['context_json'],true,512,JSON_THROW_ON_ERROR);
et_check($context['group']===$group && $context['reason_code']==='outside_tolerance'
    && $context['counters']['assignments']===['expected'=>1,'actual'=>12,'distance'=>11,'limit'=>2,'status'=>'outside_tolerance']
    && str_contains($event['message'],'Hero') && str_contains($event['message'],'assignments')
    && str_contains($event['message'],'12') && str_contains($event['message'],'1') && str_contains($event['message'],'2'),
    'The error gives the full group, exact measured count and limit, and a reason readable in status');

$open = static function () use ($type): array {
    $view = (new Kiwi_Retention_Archive_Health_Service())->status();
    et_check($view['_exit_code']===0, 'The existing archive-health status reads the production incident list');
    return array_values(array_filter($view['open_incidents'], static fn($row) => $row['event_type']===$type));
};
et_check(array_column($open(),'reference_id')===[$run['run_id']], 'The open group and its exact run appear in retention status');
$second=$finish($start());
$entries=$history();
et_check(array_column($entries,'lifecycle_action')===['raised','repeated'] && $entries[1]['reference_id']===$second['run_id']
    && $entries[1]['correlation_key']===$event['correlation_key'], 'A fresh persisted failed assessment repeats the same group incident');
$wpdb->update($summary,['assignments'=>1],$group);
$third=$finish($start());
$entries=$history();
et_check(array_column($entries,'lifecycle_action')===['raised','repeated','resolved']
    && $entries[2]['reference_id']===$third['run_id'] && $entries[2]['correlation_key']===$event['correlation_key']
    && $open()===[], 'A freshly persisted passing assessment resolves the same group and removes it from the open status');

$beforeFailure = count($history());
$missing = array_replace($group, ['allocation_version'=>'v2-missing']);
$allowed = array_replace($group, ['allocation_version'=>'v2-ready']);
$original(11,$missing);
$original(12,$allowed);
$wpdb->insert($summary, $allowed + ['assignments'=>1,'created_at'=>'2025-11-01','updated_at'=>'2025-11-02']);
$GLOBALS['retention_test_options']['kiwi_retention_settings'][$key]['dry_run'] = true;
$finish($start());
et_check(count($history())===$beforeFailure, 'A dry-run with a missing Summary never publishes a productive incident');
$GLOBALS['retention_test_options']['kiwi_retention_settings'][$key]['dry_run'] = false;
$audit = $runs->get_table_name();
$wpdb->pdo->exec("CREATE TRIGGER incident_audit_fault BEFORE UPDATE ON {$audit} FOR EACH ROW BEGIN
    IF NEW.worker_phase='sms_archive' AND OLD.worker_phase<>'sms_archive' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit unavailable'; END IF; END");
$held = $until($start(), static fn(array $row): bool => ($GLOBALS['sms_live_last_step']['error_code']??'')==='sms_audit_unconfirmed');
et_check($held['worker_phase']==='sms_remeasure' && count($history())===$beforeFailure,
    'An unconfirmed release cannot publish an incident even after the worker computed a blocking group result');
$wpdb->pdo->exec('DROP TRIGGER incident_audit_fault');
$wpdb->pdo->exec("CREATE TRIGGER incident_store_fault BEFORE INSERT ON {$events} FOR EACH ROW BEGIN
    IF NEW.event_type='retention_cleanup_group_blocked' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic event unavailable'; END IF; END");
$failedReport = $finish($held);
$pub = json_decode($failedReport['gate_results_json'],true,512,JSON_THROW_ON_ERROR);
$failedGroup = $pub['groups'][Kiwi_Retention_Sms_Statistics::group_id($missing)];
et_check(count($history())===$beforeFailure && $failedGroup['after']['status']==='incomplete'
    && $originals->find_assignment_by_id(11)!==null && $originals->find_assignment_by_id(12)===null,
    'Event-only insert failure preserves the missing-Summary block and permits the independently released group');
$wpdb->pdo->exec('DROP TRIGGER incident_store_fault');
$retry=$finish($start());
$entries=$history();
$last=end($entries);
et_check($last['lifecycle_action']==='raised' && $last['reference_id']===$retry['run_id']
    && str_contains($last['message'],'Statistikzeile') && json_decode($last['context_json'],true)['reason_code']==='summary_row_missing',
    'The next regular run reports the still blocked group after the earlier event write failed');
$wpdb->pdo->exec("CREATE TRIGGER incident_recovery_fault BEFORE INSERT ON {$events} FOR EACH ROW BEGIN
    IF NEW.event_type='retention_cleanup_group_blocked' AND NEW.lifecycle_action='resolved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic recovery unavailable'; END IF; END");
$wpdb->insert($summary,$missing + ['assignments'=>1,'created_at'=>'2025-11-01','updated_at'=>'2025-11-02']);
$passed=$finish($start());
et_check($passed['gate_status']==='passed' && $originals->find_assignment_by_id(11)===null && count($open())===1,
    'A failed recovery report permits the confirmed removal of the last eligible original');
$wpdb->pdo->exec('DROP TRIGGER incident_recovery_fault');
$empty=$finish($start());
$entries=$history();
et_check((int)$empty['eligible_rows']===0 && end($entries)['lifecycle_action']==='resolved' && end($entries)['reference_id']===$passed['run_id']
    && $open()===[], 'A later zero-candidate run retries a lost resolution against the original passing audit');

$wpdb->pdo->exec("ALTER TABLE {$source['source_table']} MODIFY seed VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''");
$wpdb->pdo->exec("ALTER TABLE {$summary} MODIFY seed VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''");
$upper = array_replace($group, ['seed'=>'Twin', 'allocation_version'=>'v3']);
$lower = array_replace($upper, ['seed'=>'twin']);
$original(21,$upper);
$original(22,$lower);
$raiseBoth = $finish($start());
$openBoth = $open();
et_check(count($openBoth)===2 && count(array_unique(array_column($openBoth, 'correlation_key')))===2,
    'Two seeds distinct under the installed UNIQUE key receive independent open incidents');
foreach ([$upper,$lower] as $key_data) {
    $wpdb->insert($summary,$key_data + ['assignments'=>1,'created_at'=>'2025-11-01','updated_at'=>'2025-11-02']);
}
$wpdb->pdo->exec("CREATE TRIGGER incident_lifecycle_fault BEFORE INSERT ON {$events} FOR EACH ROW BEGIN
    IF NEW.event_type='retention_cleanup_group_blocked' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic lifecycle unavailable'; END IF; END");
$oldPass = $finish($start());
$original(23,$lower);
$wpdb->update($summary,['assignments'=>12],$lower);
$newBlock = $finish($start());
et_check($oldPass['gate_status']==='passed' && $newBlock['gate_status']==='blocked',
    'A failed event write can leave an older pass followed by a newer authoritative block');
$wpdb->update($source['source_table'],['created_at'=>'2026-10-12 00:00:00'],['id'=>23]);
$wpdb->pdo->exec('DROP TRIGGER incident_lifecycle_fault');
$zero = $finish($start());
$stillOpen = $open();
et_check((int)$zero['eligible_rows']===0 && count($stillOpen)===1 && $stillOpen[0]['reference_id']===$raiseBoth['run_id']
    && str_contains($stillOpen[0]['message'],'twin'), 'A zero-candidate retry resolves only the binary group whose most recent saved decision still passed');

$pending = array_replace($group, ['allocation_version'=>'replay']);
$original(31,$pending);
$wpdb->pdo->exec("CREATE TRIGGER incident_checkpoint_fault BEFORE UPDATE ON {$audit} FOR EACH ROW BEGIN
    IF JSON_LENGTH(OLD.gate_results_json,'$.pending_group_events')>0 AND JSON_EXTRACT(NEW.gate_results_json,'$.pending_group_events') IS NULL
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic acknowledgement unavailable'; END IF; END");
$interrupted = $until($start(), static fn(array $row): bool => $row['worker_phase']==='sms_archive');
for ($n=0; $n<3; ++$n) { $interrupted=$step($interrupted); }
$onlyReplay=static fn(): array => array_values(array_filter($history(),static fn($e) => $e['reference_id']===$interrupted['run_id']));
et_check(count($onlyReplay())===1 && !empty(json_decode($interrupted['gate_results_json'],true)['pending_group_events']),
    'Retrying an event after its reporting checkpoint failed keeps a single published failure for that run');
$wpdb->pdo->exec('DROP TRIGGER incident_checkpoint_fault');
$completed = $finish($interrupted);
et_check(count($onlyReplay())===1 && $completed['status']==='completed',
    'A new worker confirms the pending report and completes the interrupted run without another incident');
