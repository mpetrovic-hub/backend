<?php

// Runs in the test runner's private, randomly named MariaDB database.
require_once ABSPATH . 'includes/repositories/class-sms-body-variant-repository.php';

$summary_table = $wpdb->prefix . 'kiwi_sms_body_variant_summary';
if (($argv[1] ?? '') === '--sms-correction-peer') {
    // Hold the normal increment until the second connection is actually trying to correct this row.
    $row_id = (int) getenv('KIWI_SMS_CORRECTION_ROW');
    $observer_id = (int) getenv('KIWI_SMS_CORRECTION_CONNECTION');
    $pending = $wpdb->pdo->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=? AND DB=DATABASE()');
    $wpdb->pdo->beginTransaction();
    try {
        if ($wpdb->query("UPDATE {$summary_table} SET cta1=cta1+1 WHERE id={$row_id} AND cta1=97") !== 1) {
            throw new RuntimeException('Expected one pending producer increment');
        }
        echo "held\n";
        flush();
        $deadline = microtime(true) + 4;
        do {
            $pending->execute([$observer_id]);
            $sql = (string) $pending->fetchColumn();
            $observed = stripos(ltrim($sql), 'UPDATE ' . $summary_table . ' ') === 0;
            if ($observed) { break; }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (!$observed) { throw new RuntimeException('Expected a concurrent correction write'); }
        $wpdb->pdo->commit();
        echo "increment_committed\n";
    } finally {
        if ($wpdb->pdo->inTransaction()) { $wpdb->pdo->rollBack(); }
    }
    return;
}

$variants = new Kiwi_Sms_Body_Variant_Repository();
$variants->create_table();
$group = ['landing_key' => 'q27', 'service_key' => 'sms', 'variant_key' => 'game_word',
    'seed' => 'Hero', 'allocation_version' => 'legacy'];
$targets = ['assignments' => 200, 'cta1' => 100, 'handoff_attempted' => 50, 'handoff_hidden' => 25,
    'handoff_no_hide' => 15, 'handoff_returned' => 10, 'conv' => 20];
$source = Kiwi_Retention_Source_Registry::sms_body_variant_assignments()['source_table'];
$markers = array_diff_key(Kiwi_Retention_Sms_Statistics::MARKERS, ['assignments' => true]);
$cols = array_merge(['created_at','updated_at','transaction_id','visible_token'], array_keys($group), array_values($markers));
$seed = $wpdb->pdo->prepare("INSERT INTO {$source} (" . implode(',', $cols) . ') VALUES ('
    . implode(',', array_fill(0, count($cols), '?')) . ')');
$date = '2026-01-01 12:00:00';
$wpdb->pdo->beginTransaction();
for ($id = 1; $id <= $targets['assignments']; ++$id) {
    $values = array_merge([$date, $date, 'txn_q27_' . $id, 'HeroQ27' . $id], array_values($group));
    foreach ($markers as $counter => $field) { $values[] = $id <= $targets[$counter] ? $date : null; }
    $seed->execute($values);
}
$wpdb->pdo->commit();
$columns = ['COUNT(*) AS assignments'];
foreach ($markers as $counter => $field) { $columns[] = "COUNT({$field}) AS {$counter}"; }
$evidenced = array_map('intval', $wpdb->get_row('SELECT ' . implode(',', $columns) . " FROM {$source}"));
et_check($evidenced === $targets, 'The original event rows already prove 100 first clicks and all seven known totals');
$wpdb->insert($summary_table, $group + ['created_at' => '2026-01-01', 'updated_at' => '2026-02-01', 'cta1' => 97] + $targets);
$read = static function () use ($variants, $group): array {
    return $variants->get_summary_rows($group)[0];
};
$measure = static function () use ($read, $evidenced): array {
    $row = $read();
    $before = ['summary_id' => $row['id'], 'status' => 'passed', 'reasons' => [], 'counters' => []];
    foreach ($evidenced as $key => $expected) {
        $actual = (int) $row[$key];
        $status = abs($expected - $actual) > 2 ? 'outside_tolerance' : 'passed';
        $before['counters'][$key] = ['actual' => $actual, 'expected' => $expected, 'status' => $status];
        if ($status !== 'passed') { $before['status'] = $status; }
    }
    return $before;
};

$finding = $measure();
$result = $variants->correct_summary_counters($group, $finding);
$row = $read();
et_check((int) $row['cta1'] === 100 && $result['counters']['cta1']['status'] === 'corrected',
    'The proved target replaces the still-current 97 clicks and the correction is recorded');
et_check([(float) $row['cta1_cr'], (float) $row['handoff_hidden_cr'], (float) $row['conv_cr'],
    (float) $row['conv_per_cta1_cr'], (float) $row['conv_per_hidden_cr']] === [50.0, 50.0, 10.0, 20.0, 80.0],
    'All five rates reflect the saved counters after a successful correction');

$wpdb->update($summary_table, ['cta1' => 97], ['id' => $row['id']]);
$incomplete = $measure();
$incomplete['status'] = 'incomplete';
$incomplete['reasons'] = [['code' => 'deleted_original_receipt_missing_or_mismatched']];
$blocked = $variants->correct_summary_counters($group, $incomplete);
et_check((int) $read()['cta1'] === 97 && $blocked['status'] === 'skipped' && $blocked['counters']['cta1']['reason'] === 'group_incomplete',
    'An incomplete original history forbids correction of the group even if a tentative target looks higher');

$ambiguous = $measure();
$ambiguous['counters']['conv']['expected'] = null;
$ambiguous['counters']['conv']['possible_expected'] = [19, 20];
$ambiguous['counters']['conv']['status'] = 'passed';
$uncertain = $variants->correct_summary_counters($group, $ambiguous);
et_check($uncertain['counters']['conv'] === ['status' => 'skipped', 'reason' => 'sale_time_uncertain']
    && $uncertain['counters']['cta1']['status'] === 'corrected' && (int) $read()['conv'] === 20,
    'Unknown sale timing is audited separately, while an independent proved click deficit is corrected');

$wpdb->update($summary_table, ['cta1' => 97], ['id' => $row['id']]);
$finding = $measure();
$environment = getenv();
$environment['KIWI_RETENTION_TEST_DATABASE'] = $database;
$environment['KIWI_SMS_CORRECTION_ROW'] = (string) $row['id'];
$environment['KIWI_SMS_CORRECTION_CONNECTION'] = (string) $wpdb->get_var('SELECT CONNECTION_ID()');
$child = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), realpath($_SERVER['SCRIPT_FILENAME']), '--sms-correction-peer'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
if (!is_resource($child)) { throw new RuntimeException('Unable to start correction peer'); }
fclose($pipes[0]);
try {
    et_check(trim((string) fgets($pipes[1])) === 'held', 'A second connection has the original producer increment in progress');
    $result = $variants->correct_summary_counters($group, $finding);
    et_check(trim((string) fgets($pipes[1])) === 'increment_committed', 'The writer committed 98 while the correction statement waited');
} finally {
    fclose($pipes[1]);
    $diagnosis = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($child) !== 0) { throw new RuntimeException('Correction peer failed: ' . $diagnosis); }
}
et_check((int) $read()['cta1'] === 98 && $result['counters']['cta1'] === ['status' => 'skipped', 'reason' => 'summary_changed'],
    'The conditional correction preserves 98 and records the skipped 97-to-100 attempt');

$wpdb->update($summary_table, ['cta1' => 97], ['id' => $row['id']]);
$corrected = $variants->correct_summary_counters($group, $measure());
$other->exec("UPDATE {$summary_table} SET cta1=cta1+1 WHERE id=" . (int) $row['id']);
et_check($corrected['counters']['cta1']['status'] === 'corrected' && (int) $read()['cta1'] === 101,
    'An already-evidenced increment arriving after the committed correction produces the accepted 101');

$too_high = $variants->correct_summary_counters($group, $measure());
et_check((int) $read()['cta1'] === 101 && $too_high['counters']['cta1']['status'] === 'skipped',
    'A counter above the proved total is never reduced');

$wpdb->update($summary_table, ['cta1' => 97], ['id' => $row['id']]);
$unknown = $measure();
$unknown['counters']['cta1']['status'] = 'incomplete';
$result = $variants->correct_summary_counters($group, $unknown);
et_check((int) $read()['cta1'] === 97 && $result['counters']['cta1']['reason'] === 'counter_unproven',
    'A counter marked incomplete cannot be repaired from a tentative number');

$missing = $measure();
$missing['summary_id'] = null;
$not_measured = $variants->correct_summary_counters($group, $missing);
et_check($not_measured['counters']['cta1']['reason'] === 'summary_row_missing' && (int) $read()['cta1'] === 97,
    'An absent measured summary identity cannot give permission to update a later row');
$mistyped_group = $group;
unset($mistyped_group['seed']);
$insufficient = $variants->correct_summary_counters($mistyped_group, $measure());
et_check($insufficient['counters']['cta1']['reason'] === 'group_key_incomplete' && (int) $read()['cta1'] === 97,
    'An incomplete five-part group identity is rejected before any write');

$wpdb->update($summary_table, ['cta1' => 99], ['id' => $row['id']]);
$within = $measure();
$trigger = $wpdb->prefix . 'fail_sms_correction_test';
$wpdb->pdo->exec("CREATE TRIGGER {$trigger} BEFORE UPDATE ON {$summary_table} FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='controlled correction failure'");
try {
    $rejected = $variants->correct_summary_counters($group, $within);
} finally {
    $wpdb->pdo->exec("DROP TRIGGER {$trigger}");
}
$fresh = $measure();
et_check($rejected['status'] === 'failed' && $rejected['counters']['cta1']['reason'] === 'summary_write_failed'
    && $fresh['counters']['cta1']['actual'] === 99 && $fresh['status'] === 'passed',
    'A database-rejected correction is identified and the measured residual of one is still within tolerance');

$wpdb->update($summary_table, ['assignments' => 198, 'cta1' => 96], ['id' => $row['id']]);
$within = $measure();
$other->exec("UPDATE {$summary_table} SET cta1=cta1+1, handoff_no_hide=handoff_no_hide+1 WHERE id=" . (int) $row['id']);
$partial = $variants->correct_summary_counters($group, $within);
$readback = $read();
et_check($partial['counters']['assignments']['status'] === 'corrected' && $partial['counters']['cta1']['status'] === 'skipped'
    && (int) $readback['assignments'] === 200 && (int) $readback['cta1'] === 97 && (int) $readback['handoff_no_hide'] === 16
    && (float) $readback['cta1_cr'] === 48.5, 'Each correction preserves concurrent peer counters and rates use the values actually stored');

$other_group = array_replace($group, ['allocation_version' => 'v2']);
$wpdb->insert($summary_table, $other_group + ['created_at' => '2026-01-01', 'updated_at' => '2026-02-01', 'cta1' => 97] + $targets);
$wrong_group = $variants->correct_summary_counters($other_group, $measure());
et_check($wrong_group['counters']['cta1']['reason'] === 'summary_changed'
    && (int) $variants->get_summary_rows($other_group)[0]['cta1'] === 97 && (int) $read()['cta1'] === 97,
    'The measured row identity and all five group dimensions must match to correct an existing row');


$too_large = $measure();
$too_large['counters']['cta1']['expected'] = 4294967296;
$old_sql_mode = (string) $wpdb->get_var('SELECT @@SESSION.sql_mode');
try {
    $wpdb->pdo->exec("SET SESSION sql_mode=''");
    $overflow = $variants->correct_summary_counters($group, $too_large);
} finally {
    $wpdb->pdo->exec('SET SESSION sql_mode=' . $wpdb->pdo->quote($old_sql_mode));
}
et_check((int) $read()['cta1'] === 97 && $overflow['counters']['cta1'] === ['status' => 'failed', 'reason' => 'counter_out_of_range'],
    'An unrepresentable target cannot be silently clipped into a successful counter update');


$wpdb->update($summary_table, array_fill_keys(array_keys($targets), 0), ['id' => $row['id']]);
$all = $variants->correct_summary_counters($group, $measure());
$readback = $read();
foreach ($targets as $counter => $target) {
    et_check((int) $readback[$counter] === $target && $all['counters'][$counter]['status'] === 'corrected',
        'A proved deficit can be corrected independently for ' . $counter);
}
et_check([(float) $readback['cta1_cr'], (float) $readback['handoff_hidden_cr'], (float) $readback['conv_cr'],
    (float) $readback['conv_per_cta1_cr'], (float) $readback['conv_per_hidden_cr']] === [50.0, 50.0, 10.0, 20.0, 80.0],
    'All seven corrected totals and all five final rates agree even when the earlier denominators were zero');
$wpdb->update($summary_table, ['cta1' => 97], ['id' => $row['id']]);

$guard = 'kiwi-test-q27-' . $database;
$other->query('SELECT GET_LOCK(' . $other->quote($guard) . ',0)')->fetchColumn();
try {
    $unowned = $variants->correct_summary_counters($group, $measure(), $guard);
    et_check($unowned['counters']['cta1'] === ['status' => 'failed', 'reason' => 'source_lock_lost'] && (int) $read()['cta1'] === 97,
        'A connection without the source lock cannot change even a matching group and current value');
} finally {
    $other->query('SELECT RELEASE_LOCK(' . $other->quote($guard) . ')')->fetchColumn();
}
et_check((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $guard)) === 1, 'The correcting connection owns its source lock');
try {
    $owned = $variants->correct_summary_counters($group, $measure(), $guard);
    et_check($owned['counters']['cta1']['status'] === 'corrected' && (int) $read()['cta1'] === 100,
        'The owning connection can correct the same proved deficit');
} finally {
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $guard));
}
$wpdb->update($summary_table, ['cta1' => 97], ['id' => $row['id']]);
$measured_before_delete = $measure();
$wpdb->pdo->exec("DELETE FROM {$summary_table} WHERE id=" . (int) $row['id']);
$absent = $variants->correct_summary_counters($group, $measured_before_delete);
et_check($absent['counters']['cta1']['reason'] === 'summary_changed' && $variants->get_summary_rows($group) === [],
    'A summary removed after measurement is never recreated by a correction');
