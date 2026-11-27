<?php

require_once ABSPATH . 'includes/repositories/class-sales-repository.php';

$sales = new Kiwi_Sales_Repository();
$sales->create_table();
(new Kiwi_Retention_Cleanup_Run_Repository())->create_table();
require __DIR__ . '/retention-sms-sale-concurrency-engine.php';
$data = ['sale_reference' => 'sms-185-distinct', 'sale_type' => 'premium_sms_one_off', 'status' => 'completed'];
$first = $sales->upsert_with_result($data);
$update = $sales->upsert_with_result($data + ['operator_name' => 'Updated']);
et_check($first['inserted'] === true && $update['inserted'] === false
    && (int) $first['row']['id'] > 0 && $first['row']['id'] === $update['row']['id']
    && $update['row']['operator_name'] === 'Updated', 'A successful insert is distinguishable from an update of the same persisted sale');
$afterFailure = $sales->upsert_with_result(array_merge($data, ['sale_reference' => '185-observation-lost']), static function () use ($wpdb) {
    $wpdb->query('ROLLBACK');
    throw new RuntimeException('simulated transaction loss during optional statistics observation');
});
et_check(!empty($afterFailure['row']['id']) && !empty($sales->find_by_sale_reference('185-observation-lost')['id'])
    && empty($afterFailure['observation']), 'An optional observation cannot lose the authoritative sale even when the transaction is discarded');

require_once ABSPATH . 'includes/repositories/class-sms-body-variant-repository.php';
$variants = new Kiwi_Sms_Body_Variant_Repository();
$variants->create_table();
$group = ['landing_key' => 'lp185', 'service_key' => 'nth_fr_one_off', 'session_token' => 'session185', 'variant_key' => 'bare_id'];
$assignment = $variants->insert_if_new($group + ['transaction_id' => 'txn_first_abcdefgh', 'visible_token' => 'firstabcdefgh'])['row'];
$other = $variants->insert_if_new($group + ['transaction_id' => 'txn_other_abcdefgh', 'visible_token' => 'otherabcdefgh'])['row'];
et_check($variants->mark_event_by_landing_session('lp185', 'session185', 'cta1') === false
    && $variants->mark_event_by_landing_session('lp185', 'session185', 'sms_handoff_attempted') === false
    && (int) $variants->get_summary_rows()[0]['cta1'] === 0, 'An ambiguous live landing/session pair cannot mark CTA1 or handoff for either original');

require_once ABSPATH . 'includes/services/class-sms-body-variant-service.php';
require_once ABSPATH . 'includes/providers/nth/class-nth-premium-sms-normalizer.php';
require_once ABSPATH . 'includes/repositories/class-nth-event-repository.php';
require_once ABSPATH . 'includes/repositories/class-nth-flow-transaction-repository.php';
require_once ABSPATH . 'includes/providers/nth/class-nth-sms-sale-evidence.php';
$events = new Kiwi_Nth_Event_Repository();
$flows = new Kiwi_Nth_Flow_Transaction_Repository();
$events->create_table();
$flows->create_table();
$config = new Engagement_Test_Config();
$config->root = sys_get_temp_dir() . '/kiwi-sms-booking-' . bin2hex(random_bytes(8));
$archive = new Kiwi_Retention_Sqlite_Archive_Service($config);
$lock = new Kiwi_Retention_Archive_Lock();
$reader = new Kiwi_Retention_Archive_Reader($archive, $lock, new Kiwi_Retention_Corruption_Safety_Gate_Coordinator($lock, new Engagement_Test_Events()));
$lookup = new Kiwi_Sms_Body_Variant_Service($config, $variants, $reader);
$proof = new Kiwi_Nth_Sms_Sale_Evidence($flows, $events, $lookup);
$mo = ['provider' => 'nth', 'service_key' => $group['service_key'], 'event_type' => 'mo_callback',
    'direction' => 'inbound', 'keyword' => 'PLAY', 'message_text' => 'PLAY txn_first_abcdefgh', 'dedupe_key' => 'mo-185'];
$moRow = $events->insert_if_new($mo)['row'];
$sale = $sales->upsert(['sale_reference' => '185-flow', 'provider_key' => 'nth', 'service_key' => $group['service_key'],
    'sale_type' => 'premium_sms_one_off', 'status' => 'completed', 'transaction_id' => $other['transaction_id']]);
$flow = ['flow_reference' => '185-flow', 'sale_reference' => '185-flow', 'service_key' => $group['service_key'],
    'sale_id' => (int) $sale['id'], 'mo_event_id' => (int) $moRow['id'], 'keyword' => 'PLAY'];
$flowId = $flows->create($flow);
$found = $proof->resolve($sale);
et_check($found['status'] === 'found' && $found['source'] === 'mysql' && $found['assignment']['id'] === $assignment['id'],
    'The original direct transaction ID in the linked MO proves the live assignment despite a different sale attribution ID');
$original_table = Kiwi_Retention_Source_Registry::sms_body_variant_assignments()['source_table'];
$summary_table = $wpdb->prefix . 'kiwi_sms_body_variant_summary';
$wpdb->update($original_table, ['service_key' => strtoupper($group['service_key'])], ['id' => $assignment['id']]);
et_check($proof->resolve($sale)['status'] === 'found', 'Native case-equivalent service spellings preserve a proven sale original');
$wpdb->query("ALTER TABLE {$summary_table} MODIFY service_key VARCHAR(100) COLLATE utf8mb4_bin NOT NULL DEFAULT ''");
Kiwi_Sms_Body_Variant_Repository::group_comparison(true);
et_check($proof->resolve($sale)['status'] === 'not_found', 'A binary Summary service key keeps the two service spellings separate');
$wpdb->query("ALTER TABLE {$summary_table} MODIFY service_key VARCHAR(100) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT ''");
Kiwi_Sms_Body_Variant_Repository::group_comparison(true);
$wpdb->update($original_table, ['service_key' => 'different_service'], ['id' => $assignment['id']]);
et_check($proof->resolve($sale)['status'] === 'not_found', 'An original belonging to a different service cannot prove a sale');
$wpdb->update($original_table, ['service_key' => $group['service_key']], ['id' => $assignment['id']]);
$foreignMo = array_merge($mo, ['dedupe_key' => 'different-mo', 'message_text' => 'PLAY otherabcdefgh']);
$wpdb->update('t72_kiwi_nth_events', ['normalized_payload' => json_encode($foreignMo)], ['id' => $moRow['id']]);
et_check($proof->resolve($sale)['status'] === 'ambiguous', 'A normalized MO payload with a different event identity cannot prove this sale');
$wpdb->update('t72_kiwi_nth_events', ['normalized_payload' => json_encode($mo)], ['id' => $moRow['id']]);

$contextMo = array_merge($mo, ['message_text' => 'PLAY firstabcdefgh']);
$snapshot = array_merge($flow, ['id' => $flowId, 'sale_id' => 0, 'meta_json' => json_encode(['initial_event' => $contextMo])]);
$report = ['provider' => 'nth', 'service_key' => $sale['service_key'], 'event_type' => 'notification_callback', 'direction' => 'inbound',
    'external_request_id' => '185-flow', 'is_success' => true, 'is_terminal' => true];
$sale['context_json'] = json_encode(['transaction' => $snapshot, 'report_event' => $report]);
$wpdb->query('DELETE FROM t72_kiwi_nth_flow_transactions');
$wpdb->query('DELETE FROM t72_kiwi_nth_events');
et_check(($proof->resolve($sale)['assignment']['id'] ?? null) === $assignment['id'],
    'The stored sale snapshot proves its own transaction, MO and complete visible code after the operational NTH rows disappear');
$unproven = $sale;
unset($unproven['context_json']);
et_check($proof->resolve($unproven)['status'] === 'not_found', 'A fallback sale transaction ID alone never proves a variant');

require_once ABSPATH . 'includes/providers/nth/class-nth-client.php';
require_once ABSPATH . 'includes/services/class-shared-sales-recorder.php';
require_once ABSPATH . 'includes/services/class-nth-fr-one-off-service.php';
$normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);
$nth = new Kiwi_Nth_Fr_One_Off_Service($config, $normalizer, new Kiwi_Nth_Client($config), $events, $flows,
    new Kiwi_Shared_Sales_Recorder($sales), null, null, $lookup);
$createFlow = static function (string $key, string $text) use ($mo, $events, $flows, $group): void {
    $incoming = array_merge($mo, ['dedupe_key' => 'mo-' . $key, 'message_text' => $text]);
    $event = $events->insert_if_new($incoming)['row'];
    $flows->create(['flow_reference' => $key, 'sale_reference' => $key, 'service_key' => $group['service_key'], 'keyword' => 'PLAY',
        'mo_event_id' => (int) $event['id'], 'external_message_id' => $key, 'meta_json' => ['initial_event' => $incoming]]);
};
$reportFor = static fn (string $key, string $reportId): array => ['message_id' => $key, 'report_id' => $reportId,
    'status' => 'delivered', 'shortcode' => '84072', 'keyword' => 'PLAY'];
$createFlow('live-sale-185', 'PLAY firstabcdefgh');
$confirmed = $nth->handle_notification($group['service_key'], $reportFor('live-sale-185', 'r1'));
et_check(!empty($confirmed['sale']['id']) && !empty($confirmed['success'])
    && !empty($variants->find_assignment_by_id((int) $assignment['id'])['conv_recorded_at'])
    && (int) $variants->get_summary_rows()[0]['conv'] === 1, 'A newly confirmed sale marks its live original once without a click attribution');

$createFlow('another-live-sale-185', 'PLAY txn_first_abcdefgh');
$nth->handle_notification($group['service_key'], $reportFor('another-live-sale-185', 'r1b'));
et_check((int) $variants->get_summary_rows()[0]['conv'] === 1, 'Another confirmed sale for the same live assignment cannot mark it twice');
$source = Kiwi_Retention_Source_Registry::sms_body_variant_assignments();
$path = $archive->resolve_archive_db_path();
$archive->archive_snapshot_batch($source, [$variants->find_assignment_by_id((int) $assignment['id'])],
    '2026-10-13 00:00:00', '185-before-delete', $path);
$wpdb->query('DELETE FROM ' . $source['source_table'] . ' WHERE id=' . (int) $assignment['id']);
$nth->handle_notification($group['service_key'], $reportFor('live-sale-185', 'r2'));
et_check((int) $variants->get_summary_rows()[0]['conv'] === 1, 'A repeat after full archival adds no contribution for a sale first stored live');
$createFlow('archived-sale-185', 'PLAY txn_first_abcdefgh');
$afterArchive = $nth->handle_notification($group['service_key'], $reportFor('archived-sale-185', 'r3'));
et_check(!empty($afterArchive['sale']['id']) && (int) $variants->get_summary_rows()[0]['conv'] === 2,
    'A new confirmed sale for the archived direct transaction ID increments the verified group');

$createFlow('archived-sale-185-b', 'PLAY firstabcdefgh');
$nth->handle_notification($group['service_key'], $reportFor('archived-sale-185-b', 'r4'));
$nth->handle_notification($group['service_key'], $reportFor('archived-sale-185-b', 'r4'));
$nth->handle_notification($group['service_key'], $reportFor('archived-sale-185-b', 'r5'));
$updated = $variants->get_summary_rows()[0];
et_check((int) $updated['conv'] === 3 && (float) $updated['conv_cr'] === 150.0,
    'A distinct archived sale credits the group and its rate once despite repeated and updated confirmations');

$failing = new class($config, $variants, $reader) extends Kiwi_Sms_Body_Variant_Service {
    public function record_confirmed_sale(array $proof, array $absent_before_insert): bool {
        throw new RuntimeException('synthetic statistics failure');
    }
};
$failingNth = new Kiwi_Nth_Fr_One_Off_Service($config, $normalizer, new Kiwi_Nth_Client($config), $events, $flows,
    new Kiwi_Shared_Sales_Recorder($sales), null, null, $failing);
$createFlow('retained-sale-185', 'PLAY firstabcdefgh');
$saved = $failingNth->handle_notification($group['service_key'], $reportFor('retained-sale-185', 'r6'));
$nth->handle_notification($group['service_key'], $reportFor('retained-sale-185', 'r7'));
et_check(!empty($saved['success']) && (int) $sales->find_by_sale_reference('retained-sale-185')['id'] === (int) $saved['sale']['id']
    && (int) $variants->get_summary_rows()[0]['conv'] === 3, 'A post-save statistics failure preserves the confirmed sale, and a later repeat never recounts it');

$archive->archive_snapshot_batch($source, [$other], '2026-10-13 00:00:00', '185-second-assignment', $path);
$switching = new class($sales, $wpdb, $source['source_table'], (int) $other['id']) extends Kiwi_Shared_Sales_Recorder {
    private $db;
    private $table;
    private $id;
    public function __construct($sales, $db, $table, $id) {
        parent::__construct($sales); $this->db = $db; $this->table = $table; $this->id = $id;
    }
    public function record_successful_one_off_sale_with_result(array $transaction, array $report_event, ?callable $observe_insert = null): array {
        $saved = parent::record_successful_one_off_sale_with_result($transaction, $report_event, $observe_insert);
        $this->db->query('DELETE FROM ' . $this->table . ' WHERE id=' . $this->id);
        return $saved;
    }
};
$switchNth = new Kiwi_Nth_Fr_One_Off_Service($config, $normalizer, new Kiwi_Nth_Client($config), $events, $flows,
    $switching, null, null, $lookup);
$createFlow('transition-sale-185', 'PLAY otherabcdefgh');
$transition = $switchNth->handle_notification($group['service_key'], $reportFor('transition-sale-185', 'r8'));
et_check(!empty($transition['success']) && (int) $transition['sale']['id'] > 0 && (int) $variants->get_summary_rows()[0]['conv'] === 3,
    'A sale inserted before the concurrent original deletion remains valid and cannot create a direct archived contribution');

$before = $variants->insert_if_new($group + ['transaction_id' => 'txn_before_delete_185', 'visible_token' => 'beforedelete185'])['row'];
$archive->archive_snapshot_batch($source, [$before], '2026-10-13 00:00:00', '185-before-sale-insert', $path);
$deleteFirst = new class($sales, et_connection($settings, $database), $source['source_table'], (int) $before['id']) extends Kiwi_Shared_Sales_Recorder {
    public function __construct($sales, private PDO $db, private string $table, private int $id) { parent::__construct($sales); }
    public function record_successful_one_off_sale_with_result(array $txn, array $report, ?callable $observe_insert = null): array {
        $this->db->exec('DELETE FROM ' . $this->table . ' WHERE id=' . $this->id);
        return parent::record_successful_one_off_sale_with_result($txn, $report, $observe_insert);
    }
};
$beforeNth = new Kiwi_Nth_Fr_One_Off_Service($config, $normalizer, new Kiwi_Nth_Client($config), $events, $flows, $deleteFirst, null, null, $lookup);
$createFlow('sale-after-delete-185', 'PLAY beforedelete185');
$after = $beforeNth->handle_notification($group['service_key'], $reportFor('sale-after-delete-185', 'r9'));
et_check(!empty($after['success']) && (int) $variants->get_summary_rows()[0]['conv'] === 4,
    'A concurrent original deletion committed before the actual first sale insert earns the archived contribution');

require __DIR__ . '/retention-sms-landing-events-engine.php';
$oldProof = ['status' => 'found', 'source' => 'mysql', 'assignment' => $assignment,
    'original_code' => ['transaction_id' => $assignment['transaction_id']]];
$replacement = $variants->insert_if_new($group + ['transaction_id' => $assignment['transaction_id'], 'visible_token' => 'new-original-185'])['row'];
$credited = $lookup->record_confirmed_sale($oldProof, ['code' => $oldProof['original_code'], 'live_id' => (int) $assignment['id']]);
et_check(!$credited && $variants->find_assignment_by_id((int) $replacement['id'])['conv_recorded_at'] === null,
    'A stale proof for a removed original cannot mark a replacement row with a reused transaction code');
$contender = et_connection($settings, $database);
$contender->beginTransaction();
try {
    $contender->query('SELECT id FROM ' . $source['source_table'] . ' WHERE id=' . (int) $replacement['id'] . ' FOR UPDATE')->fetchColumn();
    $createFlow('busy-original-185', 'PLAY new-original-185');
    $blocked = $nth->handle_notification($group['service_key'], $reportFor('busy-original-185', 'r10'));
    et_check(!empty($blocked['success']) && $sales->find_by_sale_reference('busy-original-185')['id'] === $blocked['sale']['id']
        && $variants->find_assignment_by_id((int) $replacement['id'])['conv_recorded_at'] === null,
        'An independently locked original cannot delay or veto the committed sale while the optional observation is unavailable');
} finally {
    $contender->rollBack();
}

$code = ['transaction_id' => 'txn_reused_after_retention', 'visible_token' => 'RepeatedFullCode'];
$run_repo = new Kiwi_Retention_Cleanup_Run_Repository();
$wpdb->query('ALTER TABLE ' . $source['source_table'] . ' AUTO_INCREMENT=10000');
foreach (['first' => '2022', 'second' => '2023'] as $version => $year) {
    $row = $variants->insert_if_new($group + $code + ['allocation_version' => $version])['row'];
    $generation = dirname($path) . DIRECTORY_SEPARATOR . Kiwi_Retention_Archive_Name::build($year);
    $batch = 'reused-' . $version;
    $archive->archive_snapshot_batch($source, [$row], '2027-01-01 00:00:00', $batch, $generation);
    if ($version === 'second') {
        $independent = array_replace($row, ['id' => 9000700, 'visible_token' => 'IndependentStoredCode', 'transaction_id' => 'txn_independent_stored']);
        $archive->archive_snapshot_batch($source, [$independent], '2027-01-01 00:00:00', 'independent-existing-batch', $generation);
        $page = [];
        foreach (range(1000,1499) as $id) {
            $page[] = array_replace($row, ['id' => $id, 'visible_token' => 'Other' . $id, 'transaction_id' => 'txn_other_' . $id]);
        }
        $archive->archive_snapshot_batch($source, $page, '2027-01-01 00:00:00', $batch, $generation);
    }
    $run_repo->create_run(['run_id' => $batch, 'source_key' => $source['source_key'], 'source_table' => $source['source_table'],
        'status' => 'completed', 'enabled' => 1, 'dry_run' => 0, 'deleted_rows' => 1, 'archive_db_path' => $generation,
        'gate_results_json' => ['deleted_batches' => [['batch_id' => $batch, 'group' => array_intersect_key($row,
            array_flip(Kiwi_Sms_Body_Variant_Repository::SUMMARY_GROUP_COLUMNS)), 'ids' => [(int)$row['id']]]]]]);
    $wpdb->query('DELETE FROM ' . $source['source_table'] . ' WHERE id=' . (int)$row['id']);
}
$lookup_codes = static fn(): array => [$lookup->resolve_assignment_from_visible_token($code['visible_token'])['status'],
    $lookup->resolve_assignment_from_transaction_id($code['transaction_id'])['status']];
et_check($lookup_codes() === ['ambiguous', 'ambiguous'], 'Distinct original IDs can reuse both full codes across successive MySQL deletions');
foreach (['file', 'row', 'row_and_receipt', 'batch'] as $loss) {
    if ($loss === 'file') { rename($generation, $generation . '.missing'); }
    else {
        $sqlite = new PDO('sqlite:' . $generation);
        if ($loss === 'batch') {
            $sqlite->prepare('DELETE FROM ' . $source['source_table'] . ' WHERE _archive_batch_id=?')->execute([$batch]);
            $sqlite->prepare('DELETE FROM archive_batch_rows WHERE archive_batch_id=?')->execute([$batch]);
            $sqlite->prepare('DELETE FROM archive_batches WHERE archive_batch_id=?')->execute([$batch]);
        } else {
            // The missing original is beyond the first 500 keys. The table and batch totals still match.
            $sqlite->exec('UPDATE ' . $source['source_table'] . " SET _source_pk=9000710,id=9000710,
                visible_token='OtherStoredCode',transaction_id='txn_other_stored' WHERE _source_pk=" . (int)$row['id']);
            if ($loss === 'row_and_receipt') {
                $sqlite->prepare('UPDATE archive_batch_rows SET source_pk=9000710 WHERE archive_batch_id=? AND source_pk=?')
                    ->execute([$batch, $row['id']]);
            }
        }
        $sqlite = null;
    }
    try {
        et_check($lookup_codes() === ['read_error', 'read_error'], 'Losing the only searchable second code cannot make the first ID unique: ' . $loss);
        foreach ($code as $field => $value) {
            $ref = 'reuse-' . $field . '-' . $loss;
            $createFlow($ref, 'PLAY ' . $value);
            $saved = $nth->handle_notification($group['service_key'], $reportFor($ref, $ref . '-report'));
            et_check(!empty($saved['sale']['id']) && !empty($saved['success'])
                && (int)$wpdb->get_var("SELECT SUM(conv) FROM {$summary_table} WHERE allocation_version IN ('first','second')") === 0,
                'The confirmed sale survives without crediting either possible group: ' . $field . ' / ' . $loss);
        }
    } finally {
        if ($loss === 'file') { rename($generation . '.missing', $generation); }
        else {
            $sqlite = new PDO('sqlite:' . $generation);
            $sqlite->exec('DELETE FROM ' . $source['source_table'] . ' WHERE _source_pk=9000710');
            if ($loss === 'row_and_receipt') {
                $sqlite->prepare('UPDATE archive_batch_rows SET source_pk=? WHERE archive_batch_id=? AND source_pk=9000710')
                    ->execute([$row['id'], $batch]);
            }
            $sqlite = null;
            if ($loss === 'batch') { $archive->archive_snapshot_batch($source, $page, '2027-01-01 00:00:00', $batch, $generation); }
            $archive->archive_snapshot_batch($source, [$row], '2027-01-01 00:00:00', $batch, $generation);
        }
    }
    et_check($lookup_codes() === ['ambiguous', 'ambiguous'], 'Returning the original evidence reveals both matching IDs again: ' . $loss);
}
$reader->close();
foreach (glob($archive->get_archive_directory() . '/*') ?: [] as $file) { unlink($file); }
rmdir($archive->get_archive_directory());
rmdir($config->root);
