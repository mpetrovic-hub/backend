<?php

// Runs inside the synthetic MariaDB/SQLite SMS assignment fixture.
function et_sms_codes_after_archive(bool $binary_visible, bool $binary_txn): void
{
    global $wpdb;
    foreach (['repositories/class-sales-repository.php', 'repositories/class-nth-event-repository.php',
        'repositories/class-nth-flow-transaction-repository.php', 'providers/nth/class-nth-premium-sms-normalizer.php',
        'providers/nth/class-nth-sms-sale-evidence.php', 'providers/nth/class-nth-client.php',
        'services/class-shared-sales-recorder.php', 'services/class-nth-fr-one-off-service.php'] as $file) {
        require_once ABSPATH . 'includes/' . $file;
    }

    $previous_prefix = $wpdb->prefix;
    $mode = ($binary_visible ? 'binary' : 'ci') . '_' . ($binary_txn ? 'binary' : 'ci');
    $wpdb->prefix = 'sms_codes_' . $mode . '_';
    $config = new Engagement_Test_Config();
    $config->root = sys_get_temp_dir() . '/kiwi-code-collation-' . bin2hex(random_bytes(8));
    $store = new Kiwi_Retention_Sqlite_Archive_Service($config);
    $lock = new Kiwi_Retention_Archive_Lock();
    $guard = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator($lock, new Engagement_Test_Events());
    $reader = new Kiwi_Retention_Archive_Reader($store, $lock, $guard);
    try {
        $variants = new Kiwi_Sms_Body_Variant_Repository();
        $sales = new Kiwi_Sales_Repository();
        $flows = new Kiwi_Nth_Flow_Transaction_Repository();
        $events = new Kiwi_Nth_Event_Repository();
        foreach ([$variants, $sales, $flows, $events, new Kiwi_Retention_Cleanup_Run_Repository()] as $repository) { $repository->create_table(); }
        $source = Kiwi_Retention_Source_Registry::sms_body_variant_assignments();
        $table = $source['source_table'];
        $visible_collation = $binary_visible ? 'utf8mb4_bin' : 'utf8mb4_unicode_520_ci';
        $txn_collation = $binary_txn ? 'utf8mb4_bin' : 'utf8mb4_unicode_520_ci';
        $wpdb->pdo->exec("ALTER TABLE {$table}
            MODIFY visible_token VARCHAR(140) CHARACTER SET utf8mb4 COLLATE {$visible_collation} NOT NULL,
            MODIFY transaction_id VARCHAR(120) CHARACTER SET utf8mb4 COLLATE {$txn_collation} NOT NULL");
        $lookup = new Kiwi_Sms_Body_Variant_Service($config, $variants, $reader);
        $group = ['landing_key' => 'lp-codes', 'service_key' => 'nth_fr_one_off', 'variant_key' => 'game_word'];
        $upper = $variants->insert_if_new($group + ['seed' => 'upper', 'transaction_id' => 'txn_CaseAlpha991',
            'visible_token' => 'HeroAlpha991'])['row'];
        $lower = $variants->insert_if_new($group + ['seed' => 'lower', 'transaction_id' => $binary_txn ? 'txn_casealpha991' : 'txn_Beta000992',
            'visible_token' => $binary_visible ? 'heroalpha991' : 'HeroBeta992'])['row'];
        $check = static function (string $stage) use ($lookup, $upper, $lower, $binary_visible, $binary_txn, $mode): void {
            foreach (['visible_token' => [$binary_visible, 'HEROALPHA991', 'resolve_assignment_from_visible_token'],
                'transaction_id' => [$binary_txn, 'txn_CASEALPHA991', 'resolve_assignment_from_transaction_id']] as $column => [$binary, $third, $resolve]) {
                $results = [];
                foreach ([$upper[$column], $lower[$column], $third] as $code) {
                    $result = $lookup->$resolve($code);
                    $results[] = [$result['status'], (int) ($result['assignment']['id'] ?? 0)];
                }
                $a = ['found', (int) $upper['id']];
                $b = ['found', (int) $lower['id']];
                et_check($results === [$a, $b, $binary ? ['not_found', 0] : $a],
                    "{$mode}: {$stage} preserves the original IDs and native matching for {$column}");
            }
        };
        $check('live MySQL');
        $path = $store->resolve_archive_db_path();
        $store->archive_snapshot_batch($source, [$upper, $lower], '2026-10-13 00:00:00', 'case-test', $path);
        $wpdb->pdo->exec('DELETE FROM ' . $table);
        $check('verified SQLite');

        $nth = new Kiwi_Nth_Fr_One_Off_Service($config, new Kiwi_Nth_Premium_Sms_Normalizer($config),
            new Kiwi_Nth_Client($config), $events, $flows, new Kiwi_Shared_Sales_Recorder($sales), null, null, $lookup);
        $expected = ['upper' => 0, 'lower' => 0];
        foreach ([$upper['visible_token'], $upper['transaction_id'], $lower['visible_token'], $lower['transaction_id']] as $number => $code) {
            $ref = 'case-sale-' . $number;
            $mo = ['provider' => 'nth', 'event_type' => 'mo_callback', 'direction' => 'inbound',
                'service_key' => $group['service_key'], 'keyword' => 'PLAY', 'message_text' => 'PLAY ' . $code, 'dedupe_key' => $ref];
            $event = $events->insert_if_new($mo)['row'];
            $flows->create(['flow_reference' => $ref, 'sale_reference' => $ref, 'service_key' => $group['service_key'],
                'keyword' => 'PLAY', 'external_message_id' => $ref, 'mo_event_id' => (int) $event['id'],
                'meta_json' => ['initial_event' => $mo]]);
            $result = $nth->handle_notification($group['service_key'], ['message_id' => $ref, 'report_id' => 'report-' . $ref,
                'status' => 'delivered', 'shortcode' => '84072', 'keyword' => 'PLAY']);
            et_check(!empty($result['success']) && !empty($result['sale']['id']), "{$mode}: NTH confirms sale for {$code}");
            ++$expected[$number < 2 ? 'upper' : 'lower'];
            $counters = array_column($variants->get_summary_rows(), 'conv', 'seed');
            et_check((int) $counters['upper'] === $expected['upper'] && (int) $counters['lower'] === $expected['lower'],
                "{$mode}: only the archived original group for {$code} gains this sale");
        }

        $sqlite = new PDO('sqlite:' . $path);
        $sqlite->prepare('DELETE FROM archive_batch_rows WHERE source_pk=?')->execute([$lower['id']]);
        $sqlite = null;
        foreach (['visible_token' => 'resolve_assignment_from_visible_token', 'transaction_id' => 'resolve_assignment_from_transaction_id'] as $column => $resolve) {
            $untouched = $lookup->$resolve($upper[$column]);
            $damaged = $lookup->$resolve($lower[$column]);
            et_check(($untouched['assignment']['id'] ?? null) === (int) $upper['id'] && $damaged['status'] === 'read_error',
                "{$mode}: a missing receipt for a genuinely different {$column} leaves the intact original available");
        }
    } finally {
        $reader->close();
        $wpdb->prefix = $previous_prefix;
        foreach (glob($store->get_archive_directory() . '/*') ?: [] as $file) { unlink($file); }
        if (is_dir($store->get_archive_directory())) { rmdir($store->get_archive_directory()); }
        if (is_dir($config->root)) { rmdir($config->root); }
    }
}

et_sms_codes_after_archive(true, true);
et_sms_codes_after_archive(false, false);
et_sms_codes_after_archive(true, false);
