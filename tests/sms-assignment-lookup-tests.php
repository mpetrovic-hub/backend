<?php

kiwi_run_test('SMS assignment lookup requires the complete visible code', function (): void {
    $repository = new Kiwi_Test_Sms_Body_Variant_Repository();
    $repository->insert_if_new([
        'transaction_id' => 'txn_original_12345678', 'visible_token' => 'HeroAB12345678',
        'variant_key' => 'game_word', 'seed' => 'Hero',
    ]);
    $service = new Kiwi_Sms_Body_Variant_Service(new Kiwi_Test_Config(), $repository);
    kiwi_assert_same('txn_original_12345678', $service->resolve_transaction_id_from_visible_token('HeroAB12345678'),
        'The original full visible code resolves in MySQL.');
    kiwi_assert_same('', $service->resolve_transaction_id_from_visible_token('HeroAB!12345678'),
        'Removing a character must never turn a different SMS code into an assignment.');
});

function kiwi_sms_lookup_row(int $id, string $token, string $transaction_id, string $version = 'legacy'): array
{
    return [
        'id' => $id, 'created_at' => '2025-01-02 10:00:00', 'updated_at' => '2025-01-03 10:00:00',
        'landing_key' => 'lp-test', 'service_key' => 'nth_fr_one_off_jplay', 'provider_key' => 'nth',
        'flow_key' => 'one-off', 'country' => 'FR', 'keyword' => 'JPLAY', 'shortcode' => '84072',
        'pid' => '', 'click_id' => '', 'session_token' => 'session-' . $id, 'transaction_id' => $transaction_id,
        'visible_token' => $token, 'variant_key' => 'game_word', 'seed' => 'Hero', 'allocation_version' => $version,
        'sms_body' => 'JPLAY ' . $token, 'cta1_recorded_at' => null, 'handoff_attempted_at' => null,
        'handoff_hidden_at' => null, 'handoff_no_hide_at' => null, 'handoff_returned_at' => null,
        'conv_recorded_at' => null, 'raw_context' => null,
    ];
}

function kiwi_sms_lookup_fixture(callable $test): void
{
    global $wpdb;
    $previous = $wpdb;
    $wpdb = new class extends Kiwi_Test_Wpdb_Sms_Body_Variant {
        public $audit_read_failed = false;

        public function prepare($query, ...$args)
        {
            // These lookup fixtures have archived copies but no productive cleanup audit rows.
            if (str_contains($query, 'FROM ' . $this->prefix . 'kiwi_retention_cleanup_runs')) { return $query; }
            return parent::prepare($query, ...$args);
        }

        public function get_results($statement, $output = null): array
        {
            if (is_string($statement) && str_contains($statement, 'FROM ' . $this->prefix . 'kiwi_retention_cleanup_runs')) {
                $this->last_error = $this->audit_read_failed ? 'fixture audit unavailable' : '';
                return [];
            }
            return parent::get_results($statement, $output);
        }
    };
    $root = kiwi_create_temp_directory('kiwi_sms_lookup');
    try {
        $config = new Kiwi_Test_Retention_Sqlite_Archive_Config($root);
        $archive = new Kiwi_Retention_Sqlite_Archive_Service($config);
        $lock = new Kiwi_Retention_Archive_Lock();
        $events = new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository());
        $reader = new Kiwi_Retention_Archive_Reader($archive, $lock, new Kiwi_Retention_Corruption_Safety_Gate_Coordinator($lock, $events));
        $service = new Kiwi_Sms_Body_Variant_Service($config, new Kiwi_Sms_Body_Variant_Repository(), $reader);
        $test($service, $archive, $wpdb);
    } finally {
        if (isset($reader)) { $reader->close(); }
        $wpdb = $previous;
        kiwi_remove_directory($root);
    }
}

function kiwi_sms_lookup_archive(Kiwi_Retention_Sqlite_Archive_Service $archive, string $name, array $rows): string
{
    $archive->resolve_archive_db_path();
    $path = $archive->get_archive_directory() . DIRECTORY_SEPARATOR . $name;
    $source = Kiwi_Retention_Source_Registry::sms_body_variant_assignments();
    $archive->archive_snapshot_batch($source, $rows, '2026-01-01 00:00:00', $name . '-' . $rows[0]['id'], $path);
    return $path;
}

kiwi_run_test('SMS assignment lookup resolves an archived full code older than 90 days', function (): void {
    kiwi_sms_lookup_fixture(function ($service, $archive): void {
        kiwi_sms_lookup_archive($archive, 'kiwi_retention_archive_2025.sqlite', [
            kiwi_sms_lookup_row(7, 'HeroLate0123', 'txn_archived_12345678'),
        ]);
        kiwi_assert_same('txn_archived_12345678', $service->resolve_transaction_id_from_visible_token('HeroLate0123'),
            'The complete code on a late SMS resolves to the original transaction with its real SQLite receipt.');
    });
});

kiwi_run_test('SMS assignment lookup reports a missing newer copy with a known exact ID receipt', function (): void {
    kiwi_sms_lookup_fixture(function ($service, $archive): void {
        $row = kiwi_sms_lookup_row(7, 'HeroLate0123', 'txn_archived_12345678');
        kiwi_sms_lookup_archive($archive, 'kiwi_retention_archive_2025.sqlite', [$row]);
        $row['transaction_id'] = 'txn_newer_12345678';
        $path = kiwi_sms_lookup_archive($archive, 'kiwi_retention_archive_2026.sqlite', [$row]);
        $db = new PDO('sqlite:' . $path);
        $db->exec('DELETE FROM wp_kiwi_sms_body_variant_assignments WHERE _source_pk=7');
        $db = null;
        $result = $service->resolve_assignment_from_visible_token('HeroLate0123');
        kiwi_assert_same('read_error', $result['status'], 'A later receipt proves the older copy alone is incomplete.');
        kiwi_assert_same('archive', $result['source'], 'The evidence gap belongs to the archive.');
    });
});

kiwi_run_test('SMS assignment lookup uses live ownership and the latest verified year and numeric generation', function (): void {
    kiwi_sms_lookup_fixture(function ($service, $archive, $db): void {
        $old = kiwi_sms_lookup_row(7, 'HeroOld7', 'txn_archive_old_0007');
        $current = kiwi_sms_lookup_row(7, 'HeroCurrent7', 'txn_archive_current_0007', 'v2');
        kiwi_sms_lookup_archive($archive, 'kiwi_retention_archive_2025.sqlite', [$old]);
        kiwi_sms_lookup_archive($archive, 'kiwi_retention_archive_2026_part_2.sqlite', [$old]);
        kiwi_sms_lookup_archive($archive, 'kiwi_retention_archive_2026_part_10.sqlite', [$current]);
        kiwi_assert_same('not_found', $service->resolve_assignment_from_visible_token('HeroOld7')['status'],
            'Two stale versions of one ID cannot resolve its superseded token.');
        $result = $service->resolve_assignment_from_visible_token('HeroCurrent7');
        kiwi_assert_same(['found', 'archive', 7, 'txn_archive_current_0007', 'v2'],
            [$result['status'], $result['source'], $result['assignment']['id'], $result['assignment']['transaction_id'], $result['assignment']['allocation_version']],
            'The current source ID, transaction and variant group come from the verified newest generation.');

        $db->tables['wp_kiwi_sms_body_variant_assignments'][7] = kiwi_sms_lookup_row(7, 'HeroLive7', 'txn_mysql_0007', 'v3');
        kiwi_assert_same('not_found', $service->resolve_assignment_from_visible_token('HeroCurrent7')['status'],
            'A live ID under a different code supersedes even the latest archive alias.');
        $result = $service->resolve_assignment_from_visible_token('HeroLive7');
        kiwi_assert_same(['mysql', 'txn_mysql_0007', 'v3'],
            [$result['source'], $result['assignment']['transaction_id'], $result['assignment']['allocation_version']],
            'The live original row and its current group take precedence.');
    });
});

/** Drive the existing NTH MO and terminal notification entry points using synthetic inputs. */
function kiwi_sms_lookup_nth(Kiwi_Sms_Body_Variant_Service $assignments, string $sms_code, bool $pending_click): string
{
    $service_key = 'nth_fr_one_off_jplay';
    $config = new Kiwi_Test_Config(100, 0, 0, [], [], [$service_key => [
        'country' => 'FR', 'flow' => 'one-off', 'shortcode' => '84072', 'keyword' => 'JPLAY*',
        'price' => 450, 'currency' => 'EUR', 'operator_nwc_map' => ['20801' => '20801'],
    ]]);
    $client = new Kiwi_Test_Nth_Client([[
        'success' => true, 'status_code' => 200, 'request' => [], 'error' => '',
        'body' => '<res><resultCode>100</resultCode><messageId>archived-mo</messageId><messageRef>{{flow_reference}}</messageRef><sessionId>{{session_id}}</sessionId></res>',
    ]]);
    $clicks = new Kiwi_Test_Click_Attribution_Repository();
    if ($pending_click) {
        $clicks->upsert_capture(['tracking_token' => 'test-click', 'transaction_id' => 'txn_pending_12345678',
            'provider_key' => 'nth', 'service_key' => $service_key, 'session_ref' => 'click-reference']);
    }
    $sales = new Kiwi_Test_Shared_Sales_Recorder();
    $service = new Kiwi_Nth_Fr_One_Off_Service($config, new Kiwi_Nth_Premium_Sms_Normalizer($config),
        $client, new Kiwi_Test_Nth_Event_Repository(), new Kiwi_Test_Nth_Flow_Transaction_Repository(), $sales,
        new Kiwi_Conversion_Attribution_Resolver($clicks, new Kiwi_Test_Affiliate_Postback_Dispatcher($config)), null, $assignments);
    $mo = $service->handle_inbound_mo($service_key, ['Encrypted_MSISDN' => 'enc-local', 'Business_Number' => '84072',
        'Message' => 'JPLAY+' . $sms_code, 'keyword' => 'JPLAY', 'NWC' => '20801', 'request_id' => 'click-reference']);
    kiwi_assert_same(true, $mo['success'], 'The valid SMS can submit the normal NTH request.');
    $confirmation = $service->handle_notification($service_key, ['message_id' => 'archived-mo', 'status' => 'delivered',
        'encrypted_msisdn' => 'enc-local', 'shortcode' => '84072', 'keyword' => 'JPLAY']);
    kiwi_assert_same(true, $confirmation['success'], 'Normal terminal NTH confirmation succeeds.');
    kiwi_assert_same(1, count($sales->calls), 'NTH records the confirmed sale once.');
    return $client->calls[0]['transaction']['flow_reference'];
}

kiwi_run_test('SMS assignment lookup reaches the NTH fallback for missing ambiguous and failed reads', function (): void {
    kiwi_sms_lookup_fixture(function ($service, $archive, $wpdb): void {
        $token = 'HeroRef1234';
        $path = kiwi_sms_lookup_archive($archive, 'kiwi_retention_archive_2025.sqlite', [
            kiwi_sms_lookup_row(1, $token, 'txn_one_12345678'),
        ]);
        $assert_fallback = static function (string $code, string $status, string $source = '') use ($service): void {
            $result = $service->resolve_assignment_from_visible_token($code);
            kiwi_assert_same($status, $result['status'], 'The public lookup distinguishes ' . $status . ' / ' . $source);
            if ($source !== '') {
                kiwi_assert_same($source, $result['source'], 'Read outcome identifies the store.');
            }
            kiwi_assert_true(strpos(kiwi_sms_lookup_nth($service, $code, true), 'txn_pending_12345678-') === 0,
                'A unique pending click supplies the fallback reference for ' . $status);
            $generated = kiwi_sms_lookup_nth($service, $code, false);
            kiwi_assert_true(preg_match('/^txn_[0-9a-f-]+-[0-9a-f]{12}$/D', $generated) === 1,
                'Without a matching click, NTH generates a fresh transaction for ' . $status);
        };
        $assert_fallback('HeroNone1234', 'not_found');
        kiwi_assert_true(strpos(kiwi_sms_lookup_nth($service, $token, true), 'txn_one_12345678-') === 0,
            'An archive hit on the late MO wins over its different click reference.');
        $wpdb->last_error = 'injected MySQL read failure';
        $assert_fallback($token, 'read_error', 'mysql');
        kiwi_assert_true(strpos(kiwi_sms_lookup_nth($service, 'txn_direct_12345678', true), 'txn_direct_12345678-') === 0,
            'A matching txn_ in the SMS still works with unavailable assignment storage.');
        $wpdb->last_error = '';
        $wpdb->audit_read_failed = true;
        kiwi_assert_same('read_error', $service->resolve_assignment_from_visible_token($token)['status'],
            'An unreadable cleanup history prevents an unverified archive hit.');
        $wpdb->audit_read_failed = false;
        $wpdb->last_error = '';
        kiwi_sms_lookup_archive($archive, 'kiwi_retention_archive_2026.sqlite', [
            kiwi_sms_lookup_row(2, $token, 'txn_two_12345678'),
        ]);
        $assert_fallback($token, 'ambiguous', 'archive');
        $db = new PDO('sqlite:' . $path);
        $db->exec('DELETE FROM archive_batch_rows');
        $db = null;
        $assert_fallback($token, 'read_error', 'archive');
        $bad_path = $archive->get_archive_directory() . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2027.sqlite';
        file_put_contents($bad_path, 'unreadable SQLite database');
        $assert_fallback('HeroNone1234', 'read_error', 'archive');
        unlink($bad_path);
        $wpdb->tables['wp_kiwi_sms_body_variant_assignments'][3] = kiwi_sms_lookup_row(3, $token, 'txn_live_unique_1234');
        kiwi_assert_true(strpos(kiwi_sms_lookup_nth($service, $token, true), 'txn_live_unique_1234-') === 0,
            'A unique live lookup ends before opening the damaged archive.');
        $wpdb->tables['wp_kiwi_sms_body_variant_assignments'][4] = kiwi_sms_lookup_row(4, $token, 'txn_live_other_1234');
        $assert_fallback($token, 'ambiguous', 'mysql');
    });
});

kiwi_run_test('SMS assignment lookup respects an active archive lock and corruption block', function (): void {
    kiwi_sms_lookup_fixture(function ($service, $archive): void {
        $path = kiwi_sms_lookup_archive($archive, 'kiwi_retention_archive_2025.sqlite', [
            kiwi_sms_lookup_row(1, 'HeroLocked1', 'txn_locked_12345678'),
        ]);
        $lock = new Kiwi_Retention_Archive_Lock();
        $held = $lock->acquire_for_archive($path);
        try {
            kiwi_assert_same('read_error', $service->resolve_assignment_from_visible_token('HeroLocked1')['status'],
                'A generation lock prevents a competing archive read.');
            kiwi_assert_true($held['handle']->persist_write_blocked(), 'The fixture marks the shared corruption block.');
        } finally {
            $lock->release($held['handle']);
        }
        kiwi_assert_same('read_error', $service->resolve_assignment_from_visible_token('HeroLocked1')['status'],
            'Releasing the lock leaves the pre-existing corruption block effective.');
    });
});

kiwi_run_test('SMS assignment lookup requires a completed batch for its verified row', function (): void {
    kiwi_sms_lookup_fixture(function ($service, $archive): void {
        $path = kiwi_sms_lookup_archive($archive, 'kiwi_retention_archive_2025.sqlite', [
            kiwi_sms_lookup_row(7, 'HeroUnfinished7', 'txn_unfinished_0007'),
        ]);
        $db = new PDO('sqlite:' . $path);
        $db->exec("UPDATE archive_batches SET status='failed', finished_at=NULL");
        $db = null;
        kiwi_assert_same('read_error', $service->resolve_assignment_from_visible_token('HeroUnfinished7')['status'],
            'An exact ID recorded by an unfinished or failed batch is insufficient evidence.');
    });
});
