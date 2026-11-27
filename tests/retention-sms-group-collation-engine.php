<?php

// Every preview goes through the regular daily scheduler and worker in the disposable test database.
$version = 'collation';
$upper = $insert(4003, '2025-05-01 00:00:00', $version, ['seed' => 'Hero']);
$lower = $insert(4004, '2025-05-01 00:00:00', $version, ['seed' => 'hero']);
$old_spelling = $insert(4005, '2025-05-01 00:00:00', $version, ['seed' => 'hErO']);
$archive->archive_snapshot_batch($source, [$old_spelling], '2026-07-14 00:00:00', 'mixed-collation', $new);
$wpdb->query('DELETE FROM ' . $source['source_table'] . ' WHERE id=4005');
$wpdb->insert($summary, array_replace($summary_row, ['allocation_version' => $version, 'seed' => 'Hero', 'assignments' => 3]));
$inspect = static function () use ($preview, $version): array {
    $run = $preview(['enabled'=>true]);
    $state = json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
    $groups = [];
    foreach ($state['groups'] as $group) {
        if ($group['key']['allocation_version'] !== $version) { continue; }
        $groups[$group['key']['seed']] = [$group['eligible_rows'], $group['before']['counters']['assignments']['expected'],
            $group['before']['counters']['assignments']['actual'], $group['before']['status']];
    }
    ksort($groups);
    return [$state, $groups];
};
[$insensitive_state,$insensitive] = $inspect();
et_check(array_values($insensitive) === [[2,3,3,'passed']], 'A case-insensitive five-part summary counts both live spellings and their archive group');
$wpdb->pdo->exec("ALTER TABLE {$summary} MODIFY seed VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''");
$wpdb->update($summary, ['assignments'=>12], ['seed'=>'Hero','allocation_version'=>$version]);
$wpdb->insert($summary, array_replace($summary_row,['allocation_version'=>$version,'seed'=>'hero','assignments'=>1]));
[, $mixed] = $inspect();
et_check($mixed === ['Hero'=>[1,1,12,'outside_tolerance'],'hero'=>[1,1,1,'passed']],
    'The installed binary summary index keeps two candidate groups even when the source column folds case');
$wpdb->pdo->exec("ALTER TABLE {$source['source_table']} MODIFY seed VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''");
[, $binary] = $inspect();
et_check($binary === $mixed, 'Binary source and summary keep the held and released groups independent');

$assert_changed = static function (array $saved): void {
    try { Kiwi_Retention_Sms_Statistics::verify_group_schema($saved); }
    catch (RuntimeException $error) {
        et_check($error->getMessage() === 'sms_group_schema_changed', 'A resumed run detects the changed group comparison contract');
        return;
    }
    throw new RuntimeException('A stored release survived a changed group index');
};
$assert_changed($insensitive_state);
$archived = $archive->fetch_verified_snapshots($source,$new,'mixed-collation',[4005])['rows'][0];
$receipt_group = Kiwi_Retention_Sms_Statistics::group_key($upper);
$run_proof = ['run_id'=>'collation-history','source_key'=>$source['source_key'],'source_table'=>$source['source_table'],
    'status'=>'completed','dry_run'=>0,'started_at'=>'2026-07-14 01:00:00','finished_at'=>'2026-07-14 02:00:00','archive_db_path'=>$new];
$batch = ['batch_id'=>'mixed-collation','group'=>$receipt_group,'ids'=>[4005]];
$proof = new Kiwi_Retention_Sms_Deletion_Evidence($runs);
et_check($proof->completed_bound($run_proof,$batch,$archived,$new,$receipt_group) === null,
    'A deletion acknowledgement for differently cased binary seed does not prove the archive original');
$probe_key = array_replace($receipt_group,['seed'=>'UnseenCase']);
$select_count = static fn () => (int)$wpdb->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
$start_queries = $select_count();
for ($repeat=0; $repeat<100; ++$repeat) { Kiwi_Retention_Sms_Statistics::group_id($probe_key); }
et_check($select_count() - $start_queries === 1, 'One hundred originals sharing a previously unseen group need one weight query');

$wpdb->pdo->exec("DELETE FROM {$summary} WHERE BINARY seed='hero' AND allocation_version='collation'");
$wpdb->pdo->exec("ALTER TABLE {$summary} MODIFY seed VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT ''");
$wpdb->update($summary, ['assignments'=>3], ['seed'=>'Hero','allocation_version'=>$version]);
[, $ci_summary] = $inspect();
et_check(array_values($ci_summary) === [[2,3,3,'passed']],
    'A binary assignment column still combines native-equivalent keys of the actual case-insensitive summary');
et_check($proof->completed_bound($run_proof,$batch,$archived,$new,$receipt_group) === '2026-07-14 02:00:00',
    'The identical deletion acknowledgement identifies the original under the actual case-insensitive summary contract');
$word = static fn (string $seed): string => Kiwi_Retention_Sms_Statistics::group_id(array_replace($receipt_group,['seed'=>$seed]));
et_check($word('Hero') === $word('hErO ') && $word('Straße') === $word('STRASSE'),
    'Padded Unicode comparisons use database weights for significant characters and accents');
$wpdb->pdo->exec("ALTER TABLE {$summary} MODIFY seed VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_nopad_ci NOT NULL DEFAULT ''");
Kiwi_Retention_Sms_Statistics::current_group_schema();
et_check($word('Hero') === $word('hero') && $word('Hero') !== $word('Hero '),
    'A non-padded case-insensitive index distinguishes a trailing space while preserving its native case equality');
$wpdb->pdo->exec("ALTER TABLE {$summary} DROP INDEX variant_summary");
$failed = $plugin->run_retention_cleanup_scheduler_daily()['source_results'][$source['source_key']];
et_check(empty($failed['run_id']) && !empty($failed['retry_source']),
    'Missing five-column uniqueness cannot start a new run or grant a group release');
