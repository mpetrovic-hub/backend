<?php

function et_sms_deletion_evidence(): void
{
    global $wpdb;

    require_once ABSPATH . 'includes/repositories/class-sms-body-variant-repository.php';
    require_once ABSPATH . 'includes/services/class-retention-sms-deletion-evidence.php';
    $prefix = $wpdb->prefix;
    $wpdb->prefix = 'proof186_';
    $config = new Engagement_Test_Config();
    $config->root = sys_get_temp_dir() . '/kiwi-sms-deletion-' . bin2hex(random_bytes(8));
    $archive = new Kiwi_Retention_Sqlite_Archive_Service($config);
    $lock = new Kiwi_Retention_Archive_Lock();
    $guard = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator($lock, new Engagement_Test_Events());
    $reader = new Kiwi_Retention_Archive_Reader($archive, $lock, $guard);
    try {
        $runs = new Kiwi_Retention_Cleanup_Run_Repository();
        $runs->create_table();
        (new Kiwi_Sms_Body_Variant_Repository())->create_table();
        $source = Kiwi_Retention_Source_Registry::sms_body_variant_assignments();
        $group = ['landing_key' => 'lp186', 'service_key' => 'service186', 'variant_key' => 'game_word',
            'seed' => '', 'allocation_version' => 'legacy'];
        $row = et_full_row($source, $group + ['id' => 7, 'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00', 'transaction_id' => 'txn_original_186', 'visible_token' => 'Hero186']);
        $path = $archive->resolve_archive_db_path();
        $archive->archive_snapshot_batch($source, [$row], '2026-07-02 00:00:00', 'batch-186', $path);
        $verified = $reader->copies($source, 7, null, true)[$path];
        $wpdb->pdo->exec('DELETE FROM ' . $source['source_table'] . ' WHERE id=7');
        $batch = ['batch_id' => 'batch-186', 'group' => $group, 'ids' => [7]];
        $finish = '2026-10-12 12:01:00';
        $run = ['run_id' => 'real-186', 'source_key' => $source['source_key'], 'source_table' => $source['source_table'],
            'status' => 'completed', 'enabled' => 1, 'dry_run' => 0, 'started_at' => '2026-10-12 12:00:00',
            'finished_at' => $finish, 'archive_db_path' => $path, 'deleted_rows' => 1,
            'gate_results_json' => ['deleted_batches' => [$batch]]];
        $evidence = new Kiwi_Retention_Sms_Deletion_Evidence();
        et_check($evidence->finished_after_deletion($verified, $path, $group) === null,
            'A verified SMS archive copy alone cannot date the MySQL removal');
        $runs->create_run($run);
        et_check((new Kiwi_Retention_Sms_Deletion_Evidence())->finished_after_deletion($verified, $path, $group) === $finish,
            'A completed productive run with the exact SQLite batch, group and deleted original ID supplies its finish time');
        $second = ['batch_id' => 'interrupted-186', 'group' => $group, 'ids' => [9]];
        $runs->create_run(array_replace($run, ['run_id' => 'interrupted-186', 'status' => 'failed',
            'gate_results_json' => ['deleted_batches' => [$second]]]));
        $cursor = [];
        $seen = [];
        $visit = static function (array $seen_run, array $seen_batch) use (&$seen): void {
            $seen[$seen_run['run_id']] = $seen_batch['ids'];
        };
        $history = new Kiwi_Retention_Sms_Deletion_Evidence();
        et_check(!$history->visit_page($cursor, 1, $visit) && $seen === ['real-186' => [7]],
            'A bounded audit page exposes the exact deleted ID and stops at its cursor');
        $runs->create_run(array_replace($run, ['run_id' => 'after-scope-186']));
        et_check($history->visit_page($cursor, 1, $visit) && $seen === ['real-186' => [7], 'interrupted-186' => [9]],
            'The frozen audit scope includes interrupted deletions without adopting a newer run');
        $other_group = array_replace($group, ['allocation_version' => 'v2']);
        $malformed = ['batch_id' => 'bad-id', 'group' => $other_group, 'ids' => [0]];
        $runs->create_run(array_replace($run, ['run_id' => 'partial-186', 'status' => 'running', 'finished_at' => null,
            'deleted_rows' => 2, 'gate_results_json' => ['deleted_batches' => [$second, $malformed]]]));
        $issues = [];
        $collect = static function (array $row, ?array $key, string $reason) use (&$issues): void {
            $issues[$row['run_id']][] = [$key, $reason];
        };
        $seen = [];
        $history->visit($visit, $collect);
        et_check($seen['partial-186'] === [9] && $issues['partial-186'] === [[$other_group, 'sms_deletion_ids_invalid']],
            'Malformed IDs block the evidenced affected group while other committed deletions remain available');
        $runs->create_run(array_replace($run, ['run_id' => 'unknown-member-186', 'deleted_rows' => 2]));
        $history->visit($visit, $collect);
        et_check($issues['unknown-member-186'] === [[null, 'sms_deletion_count_mismatch']],
            'A run claiming two deletions but listing one cannot account for the missing original');
        // Isolate each proposed bound. No later correctly matched run can mask a false positive.
        $bound_for = static function (array $overrides, array $copy, array $key) use ($wpdb, $runs, $run, $path): ?string {
            $wpdb->pdo->exec('DELETE FROM ' . $runs->get_table_name());
            $runs->create_run(array_replace($run, $overrides));
            return (new Kiwi_Retention_Sms_Deletion_Evidence())->finished_after_deletion($copy, $path, $key);
        };
        foreach ([
            'dry-run' => ['dry_run' => 1],
            'another retention source' => ['source_key' => 'landing_handoff_events'],
            'other archive file' => ['archive_db_path' => $path . '.previous'],
            'interrupted productive run' => ['status' => 'failed'],
            'ongoing productive run' => ['status' => 'running', 'finished_at' => null],
            'inconsistent end before the run started' => ['finished_at' => '2026-10-12 11:59:59'],
            'copy of the same ID in an older batch' => ['gate_results_json' => ['deleted_batches' =>
                [array_replace($batch, ['batch_id' => 'superseded'])]]],
            'other original ID within the same cursor' => ['delete_last_primary_key' => 700,
                'gate_results_json' => ['deleted_batches' => [array_replace($batch, ['ids' => [6]])]]],
            'other distribution generation' => ['gate_results_json' => ['deleted_batches' =>
                [array_replace($batch, ['group' => $other_group])]]],
        ] as $case => $change) {
            et_check($bound_for($change, $verified, $group) === null, 'No SMS deletion time from ' . $case);
        }
        et_check($bound_for([], array_replace($verified, ['_source_pk' => 6]), $group) === null,
            'A SQLite source receipt must belong to the same original ID');
        et_check($bound_for([], array_replace($verified, ['seed' => 'different']), $group) === null,
            'A verified archive version must carry the group on the actual deletion acknowledgement');
        $reason = '';
        try { $bound_for(['deleted_rows' => 2], $verified, $group); }
        catch (RuntimeException $error) { $reason = $error->getMessage(); }
        et_check($reason === 'sms_deletion_count_mismatch', 'A corrupt deletion manifest cannot release a sale as safely later');
        $wpdb->pdo->exec('DELETE FROM ' . $runs->get_table_name());
        $runs->create_run(array_replace($run, ['deleted_rows' => 0]));
        $issues = [];
        $history->visit($visit, $collect);
        et_check($issues['real-186'] === [[$group, 'sms_deletion_count_mismatch']],
            'A named group claiming an ID despite zero reported deletions is specifically incomplete');
        $wpdb->pdo->exec('DELETE FROM ' . $runs->get_table_name());
        $runs->create_run(array_replace($run, ['deleted_rows' => 2, 'gate_results_json' => ['deleted_batches' =>
            [$batch, array_replace($second, ['group' => $other_group, 'ids' => [7]])]]]));
        $issues = [];
        $history->visit($visit, $collect);
        et_check($issues['real-186'] === [[$group, 'sms_deletion_id_repeated'], [$other_group, 'sms_deletion_id_repeated']],
            'The same deleted ID attributed twice in a run blocks both affected groups');
        $wpdb->pdo->exec('DELETE FROM ' . $runs->get_table_name());
        $runs->create_run($run);
        $runs->create_run(array_replace($run, ['run_id' => 'conflicting-owner-186']));
        $reason = '';
        try { $history->finished_after_deletion($verified, $path, $group); }
        catch (RuntimeException $error) { $reason = $error->getMessage(); }
        et_check($reason === 'sms_deletion_conflicting_runs', 'Two different runs cannot own the same exact deletion receipt');
        $wpdb->pdo->exec('DROP TABLE ' . $runs->get_table_name());
        $reason = '';
        try { $history->visit($visit, $collect); }
        catch (RuntimeException $error) { $reason = $error->getMessage(); }
        et_check($reason === 'sms_deletion_audit_read_failed', 'An unreadable MariaDB audit is a concrete coverage error');
    } finally {
        $reader->close();
        $wpdb->prefix = $prefix;
        foreach (glob($archive->get_archive_directory() . '/*') ?: [] as $file) { unlink($file); }
        if (is_dir($archive->get_archive_directory())) { rmdir($archive->get_archive_directory()); }
        if (is_dir($config->root)) { rmdir($config->root); }
    }
}
