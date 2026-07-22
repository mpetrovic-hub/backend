<?php

class Kiwi_Test_Database_Deployment_Wpdb
{
    public $prefix = 'abc_';
    public $last_error = '';
    public $objects = [];
    public $queries = [];
    public $lock_available = true;
    public $lock_held = false;
    public $row_counts = [];
    public $summary_totals = [];
    public $table_inspection_error_for = '';
    public $column_inspection_error_for = '';
    public $seed_inspection_error = false;

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        return [
            'query' => (string) $query,
            'args' => $args,
        ];
    }

    public function get_var($statement)
    {
        [$query, $args] = $this->unpack($statement);
        $this->queries[] = $query;

        if (strpos($query, 'SELECT GET_LOCK(') === 0) {
            if (!$this->lock_available || $this->lock_held) {
                return 0;
            }

            $this->lock_held = true;

            return 1;
        }

        if (strpos($query, 'SELECT RELEASE_LOCK(') === 0) {
            $this->lock_held = false;

            return 1;
        }

        if (strpos($query, 'SELECT TABLE_TYPE FROM information_schema.TABLES') === 0) {
            $object_name = (string) ($args[0] ?? '');

            if ($object_name === $this->table_inspection_error_for) {
                $this->last_error = 'information_schema table access denied; password=must-not-leak; MSISDN=436641234567';

                return null;
            }

            return $this->objects[$object_name]['type'] ?? null;
        }

        return null;
    }

    public function get_results($statement, $output = ARRAY_A)
    {
        [$query, $args] = $this->unpack($statement);
        $this->queries[] = $query;
        $object_name = (string) ($args[0] ?? '');

        if (strpos($query, 'SELECT COLUMN_NAME FROM information_schema.COLUMNS') === 0) {
            if ($object_name === $this->column_inspection_error_for) {
                $this->last_error = 'information_schema access denied; password=must-not-leak; MSISDN=436641234567';

                return [];
            }

            return array_map(static function (string $column): array {
                return ['COLUMN_NAME' => $column];
            }, (array) ($this->objects[$object_name]['columns'] ?? []));
        }

        if (strpos($query, 'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS') === 0) {
            return array_map(static function (string $index): array {
                return ['INDEX_NAME' => $index];
            }, (array) ($this->objects[$object_name]['indexes'] ?? []));
        }

        if (strpos($query, 'SELECT model_key, brand FROM ') === 0) {
            if ($this->seed_inspection_error) {
                $this->last_error = 'seed read denied; password=must-not-leak; MSISDN=436641234567';
            }

            return [];
        }

        return [];
    }

    private function unpack($statement): array
    {
        if (!is_array($statement)) {
            return [(string) $statement, []];
        }

        return [
            (string) ($statement['query'] ?? ''),
            (array) ($statement['args'] ?? []),
        ];
    }
}

class Kiwi_Test_Database_Schema_Step
{
    public $calls = 0;

    private $wpdb;
    private $object_name;
    private $definition;
    private $mode;

    public function __construct(
        Kiwi_Test_Database_Deployment_Wpdb $wpdb,
        string $object_name,
        array $definition,
        string $mode = 'success'
    ) {
        $this->wpdb = $wpdb;
        $this->object_name = $object_name;
        $this->definition = $definition;
        $this->mode = $mode;
    }

    public function create_table(): void
    {
        $this->calls++;

        if ($this->mode === 'command_failure') {
            $this->wpdb->last_error = 'Commands out of sync; password=must-not-leak; MSISDN=436641234567';

            return;
        }

        $columns = (array) ($this->definition['columns'] ?? []);
        $indexes = (array) ($this->definition['indexes'] ?? []);

        if ($this->mode === 'postcondition_failure') {
            $columns = array_values(array_filter($columns, static function (string $column): bool {
                return $column !== 'required_column';
            }));
        }

        $this->wpdb->objects[$this->object_name] = [
            'type' => 'BASE TABLE',
            'columns' => $columns,
            'indexes' => $indexes,
        ];
    }
}

class Kiwi_Test_Database_Deployment_Service extends Kiwi_Database_Deployment_Service
{
    protected function seed_defaults(): void
    {
    }
}

function kiwi_test_database_contract(): array
{
    return [
        'kiwi_test_table' => [
            'columns' => ['id', 'required_column'],
            'indexes' => ['PRIMARY', 'required_index'],
            'legacy_columns' => ['legacy_column'],
        ],
    ];
}

function kiwi_test_database_service(
    Kiwi_Test_Database_Deployment_Wpdb $wpdb,
    string $mode = 'success'
): array {
    $contract = kiwi_test_database_contract();
    $step = new Kiwi_Test_Database_Schema_Step(
        $wpdb,
        'abc_kiwi_test_table',
        $contract['kiwi_test_table'],
        $mode
    );
    $service = new Kiwi_Test_Database_Deployment_Service(
        [[
            'name' => 'test_table',
            'repository' => $step,
            'objects' => ['kiwi_test_table'],
        ]],
        $contract
    );

    return [$service, $step];
}

kiwi_run_test('Kiwi database status is read-only and verifies real schema postconditions', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
    $contract = kiwi_test_database_contract()['kiwi_test_table'];
    $wpdb->objects['abc_kiwi_test_table'] = [
        'type' => 'BASE TABLE',
        'columns' => $contract['columns'],
        'indexes' => $contract['indexes'],
    ];
    $GLOBALS['kiwi_test_options'] = [
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => Kiwi_Database_Deployment_Service::TARGET_SCHEMA_VERSION,
    ];
    [$service] = kiwi_test_database_service($wpdb);

    $result = $service->status();

    kiwi_assert_same(true, $result['ready'], 'Expected matching real postconditions and version to be ready.');
    kiwi_assert_same(false, $result['mutated'], 'Expected status to remain read-only.');
    kiwi_assert_true(!empty($wpdb->queries), 'Expected status to inspect information_schema.');
    kiwi_assert_same([], array_values(array_filter($wpdb->queries, static function (string $query): bool {
        return strpos(ltrim($query), 'SELECT ') !== 0;
    })), 'Expected status to execute SELECT statements only.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi database status reports missing tables, columns, and indexes', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
    $GLOBALS['kiwi_test_options'] = [];
    [$service] = kiwi_test_database_service($wpdb);

    $missing_table = $service->status();
    kiwi_assert_true(in_array('missing_table', array_column($missing_table['drift'], 'kind'), true), 'Expected a missing table to block status.');

    $wpdb->objects['abc_kiwi_test_table'] = [
        'type' => 'BASE TABLE',
        'columns' => ['id'],
        'indexes' => ['PRIMARY'],
    ];
    $missing_members = $service->status();
    $kinds = array_column($missing_members['drift'], 'kind');

    kiwi_assert_true(in_array('missing_column', $kinds, true), 'Expected a missing column to block status.');
    kiwi_assert_true(in_array('missing_index', $kinds, true), 'Expected a missing index to block status.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi database apply fails closed when preflight inspection errors', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
    $contract = kiwi_test_database_contract()['kiwi_test_table'];
    $wpdb->objects['abc_kiwi_test_table'] = [
        'type' => 'BASE TABLE',
        'columns' => $contract['columns'],
        'indexes' => $contract['indexes'],
    ];
    $wpdb->column_inspection_error_for = 'abc_kiwi_test_table';
    $wpdb->row_counts['abc_kiwi_test_table'] = 41465;
    $GLOBALS['kiwi_test_options'] = [
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => 'old-version',
    ];
    [$service, $step] = kiwi_test_database_service($wpdb);

    $result = $service->apply();

    kiwi_assert_same('schema_inspection_failed', $result['error_code'], 'Expected inspection failures to block generic apply.');
    kiwi_assert_same(0, $step->calls, 'Expected no schema command after a preflight inspection failure.');
    kiwi_assert_same(41465, $wpdb->row_counts['abc_kiwi_test_table'], 'Expected active data to remain unchanged.');
    kiwi_assert_same('old-version', $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION], 'Expected failed preflight to preserve the installed version.');
    kiwi_assert_same(false, $wpdb->lock_held, 'Expected the external lock to be released.');
    kiwi_assert_same('inspection_error', $result['drift'][0]['kind'] ?? '', 'Expected the inspection drift to be retained for diagnosis.');
    kiwi_assert_true(strpos((string) ($result['drift'][0]['detail'] ?? ''), 'must-not-leak') === false, 'Expected inspection errors to be sanitized.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi database apply distinguishes table inspection errors from missing schema', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
    $wpdb->table_inspection_error_for = 'abc_kiwi_test_table';
    $wpdb->row_counts['abc_kiwi_test_table'] = 41465;
    $GLOBALS['kiwi_test_options'] = [
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => 'old-version',
    ];
    [$service, $step] = kiwi_test_database_service($wpdb);

    $result = $service->apply();

    kiwi_assert_same('schema_inspection_failed', $result['error_code'], 'Expected table lookup failures to block generic apply.');
    kiwi_assert_same(0, $step->calls, 'Expected no schema command after a table inspection failure.');
    kiwi_assert_same(41465, $wpdb->row_counts['abc_kiwi_test_table'], 'Expected active data to remain unchanged.');
    kiwi_assert_same('old-version', $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION], 'Expected failed table inspection to preserve the installed version.');
    kiwi_assert_same(false, $wpdb->lock_held, 'Expected the external lock to be released.');
    kiwi_assert_same('inspection_error', $result['drift'][0]['kind'] ?? '', 'Expected a table lookup failure to remain an inspection error.');
    kiwi_assert_true(strpos((string) ($result['drift'][0]['detail'] ?? ''), 'must-not-leak') === false, 'Expected table inspection errors to be sanitized.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi database apply fails closed when seed inspection errors', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
    $seed_contract = [
        'columns' => ['id', 'model_key', 'brand'],
        'indexes' => ['PRIMARY', 'model_key'],
    ];
    $wpdb->objects['abc_kiwi_device_model_brand_map'] = [
        'type' => 'BASE TABLE',
        'columns' => $seed_contract['columns'],
        'indexes' => $seed_contract['indexes'],
    ];
    $wpdb->seed_inspection_error = true;
    $GLOBALS['kiwi_test_options'] = [
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => 'old-version',
    ];
    $step = new Kiwi_Test_Database_Schema_Step(
        $wpdb,
        'abc_kiwi_device_model_brand_map',
        $seed_contract
    );
    $service = new Kiwi_Test_Database_Deployment_Service(
        [[
            'name' => 'device_model_brand_map',
            'repository' => $step,
            'objects' => ['kiwi_device_model_brand_map'],
        ]],
        ['kiwi_device_model_brand_map' => $seed_contract]
    );

    $result = $service->apply();

    kiwi_assert_same('schema_inspection_failed', $result['error_code'], 'Expected seed lookup failures to block generic apply.');
    kiwi_assert_same(0, $step->calls, 'Expected no schema command after a seed inspection failure.');
    kiwi_assert_same('old-version', $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION], 'Expected failed seed inspection to preserve the installed version.');
    kiwi_assert_same(false, $wpdb->lock_held, 'Expected the external lock to be released.');
    kiwi_assert_same('inspection_error', $result['drift'][0]['kind'] ?? '', 'Expected a seed lookup failure to remain an inspection error.');
    kiwi_assert_true(strpos((string) ($result['drift'][0]['detail'] ?? ''), 'must-not-leak') === false, 'Expected seed inspection errors to be sanitized.');

    unset($wpdb->objects['abc_kiwi_device_model_brand_map']);
    $missing_status = $service->status();
    kiwi_assert_true(in_array('missing_table', array_column($missing_status['drift'], 'kind'), true), 'Expected a known missing seed table to remain bootstrap drift.');
    kiwi_assert_true(!in_array('inspection_error', array_column($missing_status['drift'], 'kind'), true), 'Expected missing-table bootstrap not to attempt a seed read.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi database apply refuses legacy structures without mutating them', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
    $contract = kiwi_test_database_contract()['kiwi_test_table'];
    $wpdb->objects['abc_kiwi_test_table'] = [
        'type' => 'BASE TABLE',
        'columns' => array_merge($contract['columns'], ['legacy_column']),
        'indexes' => $contract['indexes'],
    ];
    $wpdb->row_counts['abc_kiwi_test_table'] = 41465;
    $GLOBALS['kiwi_test_options'] = [
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => 'old-version',
    ];
    [$service, $step] = kiwi_test_database_service($wpdb);

    $result = $service->apply();

    kiwi_assert_same('legacy_migration_required', $result['error_code'], 'Expected generic apply to refuse legacy data transformations.');
    kiwi_assert_same(0, $step->calls, 'Expected no schema command after legacy preflight drift.');
    kiwi_assert_same(41465, $wpdb->row_counts['abc_kiwi_test_table'], 'Expected active data to remain unchanged.');
    kiwi_assert_same('old-version', $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION], 'Expected failed preflight to preserve the installed version.');
    kiwi_assert_same(false, $wpdb->lock_held, 'Expected the external lock to be released.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi database apply rejects a concurrent external runner', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
    $wpdb->lock_available = false;
    $GLOBALS['kiwi_test_options'] = [
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => 'old-version',
    ];
    [$service, $step] = kiwi_test_database_service($wpdb);

    $result = $service->apply();

    kiwi_assert_same('lock_unavailable', $result['error_code'], 'Expected the second apply to stop at the exclusive lock.');
    kiwi_assert_same(0, $step->calls, 'Expected lock contention to prevent schema commands.');
    kiwi_assert_same('old-version', $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION], 'Expected lock contention to preserve the installed version.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi database apply preserves version and data after command failure', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
    $wpdb->row_counts['abc_kiwi_test_table'] = 41465;
    $GLOBALS['kiwi_test_options'] = [
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => 'old-version',
    ];
    [$service] = kiwi_test_database_service($wpdb, 'command_failure');

    $result = $service->apply();

    kiwi_assert_same('schema_command_failed', $result['error_code'], 'Expected command errors to fail apply.');
    kiwi_assert_same('old-version', $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION], 'Expected command failure not to persist the target version.');
    kiwi_assert_same(41465, $wpdb->row_counts['abc_kiwi_test_table'], 'Expected unrelated active data to remain unchanged after command failure.');
    kiwi_assert_true(strpos($result['error_message'], 'must-not-leak') === false, 'Expected credential-like error content to be redacted.');
    kiwi_assert_true(strpos($result['error_message'], '436641234567') === false, 'Expected raw subscriber identifiers to be redacted.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi database apply preserves version when postconditions fail', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
    $GLOBALS['kiwi_test_options'] = [
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => 'old-version',
    ];
    [$service] = kiwi_test_database_service($wpdb, 'postcondition_failure');

    $result = $service->apply();

    kiwi_assert_same('schema_postcondition_failed', $result['error_code'], 'Expected missing postconditions to fail apply.');
    kiwi_assert_same('old-version', $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION], 'Expected failed postconditions not to persist the target version.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi database apply bootstraps an empty schema and persists version last', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
    $GLOBALS['kiwi_test_options'] = [];
    [$service, $step] = kiwi_test_database_service($wpdb);

    $result = $service->apply();

    kiwi_assert_same(true, $result['success'], 'Expected empty-schema bootstrap to pass after verified creation.');
    kiwi_assert_same(true, $result['ready'], 'Expected final status to be ready.');
    kiwi_assert_same(true, $result['mutated'], 'Expected apply to report mutation.');
    kiwi_assert_same(1, $step->calls, 'Expected the schema step to run once.');
    kiwi_assert_same(Kiwi_Database_Deployment_Service::TARGET_SCHEMA_VERSION, $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION] ?? '', 'Expected target version only after verified schema creation.');
    kiwi_assert_same(false, $wpdb->lock_held, 'Expected the external lock to be released after success.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi database deployment contract covers every canonical repository object', function (): void {
    $contract = require __DIR__ . '/../tools/database/schema-contract.php';
    $expected_objects = [
        'kiwi_click_attributions',
        'kiwi_device_model_brand_map',
        'kiwi_dimoco_blacklist_callbacks',
        'kiwi_dimoco_operator_lookup_callbacks',
        'kiwi_dimoco_refund_callbacks',
        'kiwi_landing_funnel_daily_summary',
        'kiwi_landing_funnel_daily_tkzone_summary',
        'kiwi_landing_handoff_events',
        'kiwi_landing_kpi_summary',
        'kiwi_landing_page_sessions',
        'kiwi_nth_events',
        'kiwi_nth_flow_transactions',
        'kiwi_operational_events',
        'kiwi_premium_sms_fraud_signals',
        'kiwi_premium_sms_landing_engagements',
        'kiwi_retention_cleanup_runs',
        'kiwi_retention_table_growth_snapshots',
        'kiwi_sales',
        'kiwi_sms_body_variant_assignments',
        'kiwi_sms_body_variant_summary',
        'kiwi_v_load_to_cta_by_tksource_tkzone',
        'kiwi_v_one_for_all',
    ];
    $actual_objects = array_keys(is_array($contract) ? $contract : []);
    sort($expected_objects, SORT_STRING);
    sort($actual_objects, SORT_STRING);

    kiwi_assert_same($expected_objects, $actual_objects, 'Expected the external status contract to cover every canonical table and view.');
    kiwi_assert_true(new Kiwi_Database_Deployment_Service() instanceof Kiwi_Database_Deployment_Service, 'Expected every canonical repository step to construct outside normal runtime.');
});

kiwi_run_test('Kiwi normal runtime contains no schema mutation path', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
    $wpdb->row_counts['abc_kiwi_landing_funnel_daily_summary'] = 41465;
    $wpdb->summary_totals['abc_kiwi_landing_funnel_daily_summary'] = [
        'sessions' => 123456,
        'sales' => 789,
    ];
    $GLOBALS['kiwi_test_options'] = [
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => 'unrelated-later-version',
    ];
    $plugin_source = (string) file_get_contents(__DIR__ . '/../includes/core/class-plugin.php');
    $bootstrap_source = (string) file_get_contents(__DIR__ . '/../includes/bootstrap.php');
    $plugin = new Kiwi_Plugin(dirname(__DIR__), 'https://example.test/plugin/');

    $plugin->register();

    kiwi_assert_true(strpos($plugin_source, 'dbDelta') === false, 'Expected the normal plugin runtime not to call dbDelta.');
    kiwi_assert_true(strpos($plugin_source, 'ensure_schema_if_needed') === false, 'Expected the global runtime schema gate to be removed.');
    kiwi_assert_true(strpos($plugin_source, 'CREATE TEMPORARY TABLE') === false, 'Expected the dangerous temporary rollup migration to be deleted.');
    kiwi_assert_true(strpos($plugin_source, 'DELETE FROM {$table_name}') === false, 'Expected the destructive delete/reinsert migration to be deleted.');
    kiwi_assert_true(strpos($bootstrap_source, 'tools/database') === false, 'Expected the external runner not to be loaded by normal bootstrap.');
    kiwi_assert_same([], $wpdb->queries, 'Expected an unrelated stored schema version not to trigger database inspection or mutation during normal registration.');
    kiwi_assert_same(41465, $wpdb->row_counts['abc_kiwi_landing_funnel_daily_summary'], 'Expected current summary row count to remain unchanged during normal registration.');
    kiwi_assert_same(['sessions' => 123456, 'sales' => 789], $wpdb->summary_totals['abc_kiwi_landing_funnel_daily_summary'], 'Expected current summary totals to remain unchanged during normal registration.');

    $wpdb = $previous_wpdb;
});
