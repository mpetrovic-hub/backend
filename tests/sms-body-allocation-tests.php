<?php

kiwi_run_test('SMS allocation covers all 100 buckets with exact weights and all CTA forms', function (): void {
    $repository = new Kiwi_Test_Sms_Body_Variant_Repository();
    $service = new Kiwi_Sms_Body_Variant_Service(new Kiwi_Test_Config(), $repository);
    $adapter = new Kiwi_Nth_Primary_Cta_Adapter($service);
    $expected = ['' => 10, 'BonusJeux' => 20, 'TopJeux' => 20, 'JouerPlus' => 20,
        'AccederJeux' => 8, 'JeuxMax' => 8, 'AccederMaintenant' => 8, 'GameQuest' => 6];
    $counts = array_fill_keys(array_keys($expected), 0);
    $buckets = [];
    for ($i = 0; $i < 10000 && count($buckets) < 100; $i++) {
        $transaction = 'txn_allocation_test_' . $i;
        $bucket = hexdec(substr(hash('sha256', 'fr_sms_v2|' . $transaction), 0, 8)) % 100;
        if (isset($buckets[$bucket])) { continue; }
        $buckets[$bucket] = true;
        $entry = $service->resolve_allocation($transaction);
        kiwi_assert_true(array_key_exists($entry['seed'], $expected), 'Only the eight approved seeds may receive new assignments.');
        $counts[$entry['seed']]++;
        kiwi_assert_same('fr_sms_v2', $entry['allocation_version'], 'Allocation must be versioned.');
        kiwi_assert_true($entry['variant_key'] !== 'bare_id', 'No new bare-id allocation.');
        if ($entry['seed'] === 'AccederMaintenant') {
            kiwi_assert_same('download_phrase', $entry['variant_key'], 'Download category must be explicit.');
        }
        foreach (['lp2-fr', 'lp5-fr', 'lp6-fr'] as $landing_key) {
            $landing = ['key' => $landing_key, 'provider' => 'nth', 'country' => 'FR', 'flow' => 'nth-fr-one-off',
                'service_key' => 'nth_fr_one_off_jplay', 'shortcode' => '84072', 'keyword' => 'JPLAY'];
            $href = $adapter->build_primary_cta_href($landing, [], ['transaction_id' => $transaction]);
            $token = $entry['seed'] === '' ? $transaction : $entry['seed'] . substr($transaction, 4);
            kiwi_assert_same('sms:84072?body=' . rawurlencode('JPLAY ' . $token), $href, 'Adapter must produce the exact approved SMS body.');
            kiwi_assert_same($transaction, $service->resolve_transaction_id_from_visible_token($token), 'Every form must resolve for callbacks.');
        }
    }
    kiwi_assert_same(100, count($buckets), 'Test must cover every hash bucket.');
    kiwi_assert_same($expected, $counts, 'Weights must match exactly over all 100 buckets.');
    kiwi_assert_same(100, count($repository->assignments), 'Repeated requests must reuse their assignment.');
});

kiwi_run_test('SMS allocation preserves historical bodies and excludes other countries and flows', function (): void {
    $repository = new Kiwi_Test_Sms_Body_Variant_Repository();
    $service = new Kiwi_Sms_Body_Variant_Service(new Kiwi_Test_Config(), $repository);
    $landing = ['key' => 'lp5-fr', 'provider' => 'nth', 'country' => 'FR', 'flow' => 'nth-fr-one-off', 'service_key' => 'nth_fr_one_off_jplay'];
    $repository->insert_if_new(['transaction_id' => 'txn_historical', 'visible_token' => 'historical',
        'variant_key' => 'bare_id', 'sms_body' => 'JPLAY historical'] + $landing);
    $result = $service->build_variant_body('JPLAY', '84072', $landing, [], ['transaction_id' => 'txn_historical']);
    kiwi_assert_same('JPLAY historical', $result['body'], 'Existing body must never be reassigned.');
    kiwi_assert_same('legacy', $result['assignment']['allocation_version'], 'Historical version remains legacy.');
    foreach ([['country' => 'PL'], ['flow' => 'pin'], ['provider' => 'dimoco']] as $override) {
        kiwi_assert_same(null, $service->build_variant_body('JPLAY', '84072', array_merge($landing, $override), [], ['transaction_id' => 'txn_excluded']), 'Exclude other integrations and countries.');
    }
});

kiwi_run_test('Actual SMS repository keeps late legacy events separate from new allocation', function (): void {
    global $wpdb;
    $previous = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Sms_Body_Variant();
    try {
        $repository = new Kiwi_Sms_Body_Variant_Repository();
        foreach (['legacy', 'fr_sms_v2'] as $version) {
            $assignment = ['transaction_id' => 'txn_' . $version, 'visible_token' => 'BonusJeux' . $version,
                'landing_key' => 'lp5-fr', 'service_key' => 'nth_fr_one_off_jplay', 'variant_key' => 'cta_phrase', 'seed' => 'BonusJeux'];
            if ($version !== 'legacy') { $assignment['allocation_version'] = $version; }
            $result = $repository->insert_if_new($assignment);
            kiwi_assert_same($version, $result['row']['allocation_version'], 'Persist supplied version or default legacy.');
        }
        foreach (['cta1', 'sms_handoff_attempted', 'sms_handoff_hidden', 'sms_handoff_no_hide', 'sms_handoff_returned', 'conv'] as $event) {
            kiwi_assert_true($repository->mark_event_by_transaction_id('txn_legacy', $event), 'Late legacy event must succeed.');
            kiwi_assert_same(false, $repository->mark_event_by_transaction_id('txn_legacy', $event), 'Duplicate legacy event must not increment.');
        }
        $rows = $repository->get_summary_rows();
        kiwi_assert_same(2, count($rows), 'Same seed and landing must have separate summary rows per version.');
        kiwi_assert_same(1, count($repository->get_summary_rows(['allocation_version' => 'legacy'])), 'Version filter must isolate legacy summary.');
        $versions = array_column($rows, null, 'allocation_version');
        kiwi_assert_same(1, $versions['legacy']['conv'], 'Late conversion stays in legacy.');
        kiwi_assert_same(0, $versions['fr_sms_v2']['conv'], 'New allocation is not contaminated.');
        kiwi_assert_same(100.0, $versions['legacy']['conv_cr'], 'Legacy rate remains correct.');
        kiwi_assert_same(0.0, (float) $versions['fr_sms_v2']['conv_cr'], 'New allocation rate remains zero.');
        kiwi_assert_true($repository->mark_event_by_transaction_id('txn_fr_sms_v2', 'conv'), 'New version receives its own event.');
        kiwi_assert_same(1, $repository->get_summary_rows()[0]['conv'], 'New event must not increment legacy.');
        $result = $repository->insert_if_new(['transaction_id' => 'txn_download_test', 'visible_token' => 'AccederMaintenantdownload_test',
            'landing_key' => 'lp6-fr', 'service_key' => 'nth_fr_one_off_jplay', 'variant_key' => 'download_phrase',
            'seed' => 'AccederMaintenant', 'allocation_version' => 'fr_sms_v2']);
        kiwi_assert_true($result['inserted'], 'Actual repository accepts download_phrase.');
    } finally { $wpdb = $previous; }
});

class Kiwi_Test_Sms_Allocation_Deployment_Wpdb extends Kiwi_Test_Database_Deployment_Wpdb
{
    public $key_columns = ['landing_key', 'service_key', 'variant_key', 'seed'];
    public $unique = true;
    public $sub_part = null;
    public $fail_alter = false;
    public $ignore_alter = false;
    public $fail_key_inspection = false;
    public $alter_count = 0;
    public $historical_totals = ['assignments' => 200, 'conv' => 12];

    public function get_results($statement, $output = ARRAY_A)
    {
        $sql = is_array($statement) ? $statement['query'] : $statement;
        if (strpos($sql, 'SELECT COLUMN_NAME, NON_UNIQUE, SUB_PART') === 0) {
            $this->queries[] = $sql;
            if ($this->fail_key_inspection) {
                $this->last_error = 'inspection unavailable';
                return null;
            }
            return array_map(function (string $column): array {
                return ['COLUMN_NAME' => $column, 'NON_UNIQUE' => $this->unique ? 0 : 1, 'SUB_PART' => $this->sub_part];
            }, $this->key_columns);
        }
        return parent::get_results($statement, $output);
    }

    public function query($statement)
    {
        $this->queries[] = $statement;
        if (strpos($statement, 'ALTER TABLE') !== 0) { throw new RuntimeException('Unexpected schema mutation.'); }
        $this->alter_count++;
        kiwi_assert_true($this->lock_held, 'Key replacement must hold the external apply lock.');
        kiwi_assert_true(strpos($statement, 'DROP INDEX variant_summary') !== false, 'Replace only the approved index.');
        kiwi_assert_true(strpos($statement, 'ADD UNIQUE KEY variant_summary (landing_key, service_key, variant_key, seed, allocation_version)') !== false, 'New key must include the version.');
        if ($this->fail_alter) { $this->last_error = 'simulated ALTER failure'; return false; }
        if (!$this->ignore_alter) {
            $this->key_columns = ['landing_key', 'service_key', 'variant_key', 'seed', 'allocation_version'];
            $this->objects['abc_kiwi_sms_body_variant_summary']['columns'][] = 'allocation_version';
        }
        return 0;
    }
}

kiwi_run_test('SMS schema apply upgrades the legacy key under lock and verifies repeated apply', function (): void {
    global $wpdb;
    $previous = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Sms_Allocation_Deployment_Wpdb();
    try {
        $all = require __DIR__ . '/../tools/database/schema-contract.php';
        $definition = $all['kiwi_sms_body_variant_summary'];
        $wpdb->objects['abc_kiwi_sms_body_variant_summary'] = ['type' => 'BASE TABLE',
            'columns' => array_values(array_diff($definition['columns'], ['allocation_version'])), 'indexes' => $definition['indexes']];
        $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION] = '2026-07-23-1';
        $step = new Kiwi_Test_Database_Schema_Step($wpdb, 'abc_kiwi_sms_body_variant_summary', $definition);
        $service = new Kiwi_Test_Database_Deployment_Service([['name' => 'sms', 'repository' => $step,
            'objects' => ['kiwi_sms_body_variant_summary']]], ['kiwi_sms_body_variant_summary' => $definition]);
        $before = $service->status();
        kiwi_assert_same(false, $before['ready'], 'Old key must never produce green status.');
        kiwi_assert_same(0, $wpdb->alter_count, 'Status is read-only.');
        $totals = $wpdb->historical_totals;
        $result = $service->apply();
        kiwi_assert_same(true, $result['ready'], 'Upgraded key and columns must pass status.');
        kiwi_assert_same(1, $wpdb->alter_count, 'One atomic key replacement.');
        kiwi_assert_same($totals, $wpdb->historical_totals, 'Summary history must remain untouched.');
        kiwi_assert_same(true, $service->apply()['ready'], 'Repeated apply must pass.');
        kiwi_assert_same(1, $wpdb->alter_count, 'Repeated apply must not replace the key again.');
        kiwi_assert_same(false, $wpdb->lock_held, 'Apply releases lock.');
    } finally { $wpdb = $previous; }
});

kiwi_run_test('SMS schema rejects malformed keys and fails closed on inspection command and verification failures', function (): void {
    global $wpdb;
    $previous = $wpdb ?? null;
    try {
        foreach (['nonunique', 'prefix', 'wrong_columns', 'inspection', 'alter_failure', 'postcondition', 'lock'] as $mode) {
            $wpdb = new Kiwi_Test_Sms_Allocation_Deployment_Wpdb();
            $all = require __DIR__ . '/../tools/database/schema-contract.php';
            $definition = $all['kiwi_sms_body_variant_summary'];
            $wpdb->objects['abc_kiwi_sms_body_variant_summary'] = ['type' => 'BASE TABLE', 'columns' => $definition['columns'], 'indexes' => $definition['indexes']];
            if ($mode === 'nonunique') { $wpdb->unique = false; }
            if ($mode === 'prefix') { $wpdb->sub_part = 10; }
            if ($mode === 'wrong_columns') { $wpdb->key_columns = ['service_key', 'landing_key']; }
            if ($mode === 'inspection') { $wpdb->fail_key_inspection = true; }
            if ($mode === 'alter_failure') { $wpdb->fail_alter = true; }
            if ($mode === 'postcondition') { $wpdb->ignore_alter = true; }
            if ($mode === 'lock') { $wpdb->lock_available = false; }
            $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION] = '2026-07-23-1';
            $step = new Kiwi_Test_Database_Schema_Step($wpdb, 'abc_kiwi_sms_body_variant_summary', $definition);
            $service = new Kiwi_Test_Database_Deployment_Service([['name' => 'sms', 'repository' => $step,
                'objects' => ['kiwi_sms_body_variant_summary']]], ['kiwi_sms_body_variant_summary' => $definition]);
            $result = $service->apply();
            kiwi_assert_same(false, $result['success'], 'Failed schema transition must not be successful: ' . $mode);
            kiwi_assert_same(0, $step->calls, 'Stop before dependent schema work: ' . $mode);
            kiwi_assert_same('2026-07-23-1', $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION], 'Failure must preserve version evidence.');
            kiwi_assert_same(false, $wpdb->lock_held, 'Failure must release lock.');
        }
    } finally { $wpdb = $previous; }
});

kiwi_run_test('SMS schema new installation creates the canonical versioned key without legacy ALTER', function (): void {
    global $wpdb;
    $previous = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Sms_Allocation_Deployment_Wpdb();
    try {
        $wpdb->key_columns = [];
        $all = require __DIR__ . '/../tools/database/schema-contract.php';
        $definition = $all['kiwi_sms_body_variant_summary'];
        $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION] = '';
        $step = new class($wpdb, $definition) {
            private $db;
            private $definition;
            public function __construct($db, array $definition) { $this->db = $db; $this->definition = $definition; }
            public function create_table(): void {
                $this->db->objects['abc_kiwi_sms_body_variant_summary'] = ['type' => 'BASE TABLE',
                    'columns' => $this->definition['columns'], 'indexes' => $this->definition['indexes']];
                $this->db->key_columns = $this->definition['versioned_summary_key'];
            }
        };
        $service = new Kiwi_Test_Database_Deployment_Service([['name' => 'sms', 'repository' => $step,
            'objects' => ['kiwi_sms_body_variant_summary']]], ['kiwi_sms_body_variant_summary' => $definition]);
        kiwi_assert_same(true, $service->apply()['ready'], 'New schema must pass the same versioned postconditions.');
        kiwi_assert_same(0, $wpdb->alter_count, 'No legacy key replacement on a new installation.');
    } finally { $wpdb = $previous; }
});
