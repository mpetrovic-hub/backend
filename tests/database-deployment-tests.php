<?php

if (!defined('WP_CLI')) {
    define('WP_CLI', true);
}

$GLOBALS['kiwi_test_did_actions'] = [];

if (!function_exists('did_action')) {
    function did_action($hook): int
    {
        return (int) ($GLOBALS['kiwi_test_did_actions'][(string) $hook] ?? 0);
    }
}

class Kiwi_Test_WP_CLI_Halt_Exception extends RuntimeException
{
    public $return_code;

    public function __construct(int $return_code)
    {
        parent::__construct('WP-CLI halted.', $return_code);
        $this->return_code = $return_code;
    }
}

class Kiwi_Test_WP_CLI_Runner
{
    public $load_wordpress_calls = 0;
    public $invoke_plugins_loaded = true;
    public $plugins_loaded_count = 1;
    public $init_count = 0;
    public $continued_to_init = false;

    public function load_wordpress(): void
    {
        $this->load_wordpress_calls++;

        if (!$this->invoke_plugins_loaded) {
            return;
        }

        $GLOBALS['kiwi_test_did_actions']['plugins_loaded'] = $this->plugins_loaded_count;
        $GLOBALS['kiwi_test_did_actions']['init'] = $this->init_count;

        foreach (WP_CLI::$wp_hooks['plugins_loaded'] ?? [] as $callback) {
            $callback();
        }

        $GLOBALS['kiwi_test_did_actions']['init'] = 1;
        $this->continued_to_init = true;
    }
}

class WP_CLI
{
    public static $commands = [];
    public static $wp_hooks = [];
    public static $lines = [];
    public static $errors = [];
    public static $halt_codes = [];
    public static $allow_hook_registration = true;
    public static $runner;

    public static function add_command($name, $callable, $args = []): bool
    {
        self::$commands[(string) $name] = [
            'callable' => $callable,
            'args' => (array) $args,
        ];

        return true;
    }

    public static function add_wp_hook($tag, $callback, $priority = 10, $accepted_args = 1): bool
    {
        if (!self::$allow_hook_registration) {
            return false;
        }

        self::$wp_hooks[(string) $tag][] = $callback;

        return true;
    }

    public static function get_runner(): Kiwi_Test_WP_CLI_Runner
    {
        if (!(self::$runner instanceof Kiwi_Test_WP_CLI_Runner)) {
            self::$runner = new Kiwi_Test_WP_CLI_Runner();
        }

        return self::$runner;
    }

    public static function error($message, $exit = true): void
    {
        self::$errors[] = (string) $message;

        if ($exit) {
            self::halt(1);
        }
    }

    public static function halt($return_code): void
    {
        $return_code = (int) $return_code;
        self::$halt_codes[] = $return_code;

        throw new Kiwi_Test_WP_CLI_Halt_Exception($return_code);
    }

    public static function line($message = ''): void
    {
        self::$lines[] = (string) $message;
    }

    public static function reset_runtime(): void
    {
        self::$wp_hooks = [];
        self::$lines = [];
        self::$errors = [];
        self::$halt_codes = [];
        self::$allow_hook_registration = true;
        self::$runner = new Kiwi_Test_WP_CLI_Runner();
        $GLOBALS['kiwi_test_did_actions'] = [];
    }
}

class Kiwi_Test_Incomplete_WP_CLI
{
    public static function add_command($name, $callable, $args = []): bool
    {
        return true;
    }
}

class Kiwi_Test_Database_Command_Service
{
    public $calls = [];
    private $result;

    public function __construct(array $result)
    {
        $this->result = $result;
    }

    public function status(): array
    {
        $this->calls[] = 'status';

        return $this->result;
    }

    public function apply(): array
    {
        $this->calls[] = 'apply';

        return $this->result;
    }
}

require_once __DIR__ . '/../tools/database/kiwi-database.php';

function kiwi_test_expect_cli_halt(callable $callback, int $expected_code): void
{
    $caught = null;

    try {
        $callback();
    } catch (Kiwi_Test_WP_CLI_Halt_Exception $error) {
        $caught = $error;
    }

    kiwi_assert_true($caught instanceof Kiwi_Test_WP_CLI_Halt_Exception, 'Expected WP-CLI execution to halt explicitly.');
    kiwi_assert_same($expected_code, $caught->return_code, 'Expected the planned WP-CLI exit code.');
}

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
    public $seed_inspection_error_when_columns_missing = false;
    public $seed_rows = [];

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
            $seed_columns = (array) ($this->objects['abc_kiwi_device_model_brand_map']['columns'] ?? []);
            $required_seed_columns_missing = !in_array('model_key', $seed_columns, true)
                || !in_array('brand', $seed_columns, true);

            if ($this->seed_inspection_error
                || ($this->seed_inspection_error_when_columns_missing && $required_seed_columns_missing)
            ) {
                $this->last_error = 'seed read denied; password=must-not-leak; MSISDN=436641234567';

                return [];
            }

            return $this->seed_rows;
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
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => '2026-05-12-1',
    ];
    [$service, $step] = kiwi_test_database_service($wpdb);

    $result = $service->apply();

    kiwi_assert_same('schema_inspection_failed', $result['error_code'], 'Expected inspection failures to block generic apply.');
    kiwi_assert_same(0, $step->calls, 'Expected no schema command after a preflight inspection failure.');
    kiwi_assert_same(41465, $wpdb->row_counts['abc_kiwi_test_table'], 'Expected active data to remain unchanged.');
    kiwi_assert_same('2026-05-12-1', $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION], 'Expected failed preflight to preserve the installed version.');
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
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => '2026-05-12-1',
    ];
    [$service, $step] = kiwi_test_database_service($wpdb);

    $result = $service->apply();

    kiwi_assert_same('schema_inspection_failed', $result['error_code'], 'Expected table lookup failures to block generic apply.');
    kiwi_assert_same(0, $step->calls, 'Expected no schema command after a table inspection failure.');
    kiwi_assert_same(41465, $wpdb->row_counts['abc_kiwi_test_table'], 'Expected active data to remain unchanged.');
    kiwi_assert_same('2026-05-12-1', $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION], 'Expected failed table inspection to preserve the installed version.');
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
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => '2026-05-12-1',
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
    kiwi_assert_same('2026-05-12-1', $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION], 'Expected failed seed inspection to preserve the installed version.');
    kiwi_assert_same(false, $wpdb->lock_held, 'Expected the external lock to be released.');
    kiwi_assert_same('inspection_error', $result['drift'][0]['kind'] ?? '', 'Expected a seed lookup failure to remain an inspection error.');
    kiwi_assert_true(strpos((string) ($result['drift'][0]['detail'] ?? ''), 'must-not-leak') === false, 'Expected seed inspection errors to be sanitized.');

    unset($wpdb->objects['abc_kiwi_device_model_brand_map']);
    $missing_status = $service->status();
    kiwi_assert_true(in_array('missing_table', array_column($missing_status['drift'], 'kind'), true), 'Expected a known missing seed table to remain bootstrap drift.');
    kiwi_assert_true(!in_array('inspection_error', array_column($missing_status['drift'], 'kind'), true), 'Expected missing-table bootstrap not to attempt a seed read.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi database apply repairs missing seed query columns before inspecting seeds', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
    $seed_contract = [
        'columns' => ['id', 'model_key', 'brand'],
        'indexes' => ['PRIMARY', 'model_key'],
    ];
    $wpdb->objects['abc_kiwi_device_model_brand_map'] = [
        'type' => 'BASE TABLE',
        'columns' => ['id', 'model_key'],
        'indexes' => $seed_contract['indexes'],
    ];
    $wpdb->seed_inspection_error_when_columns_missing = true;
    $wpdb->seed_rows = (new Kiwi_Device_Model_Brand_Map_Repository())->get_default_model_brand_mappings();
    $GLOBALS['kiwi_test_options'] = [
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => '2026-05-12-1',
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

    kiwi_assert_same(true, $result['success'], 'Expected additive seed-column drift to be repaired before seed inspection.');
    kiwi_assert_same(1, $step->calls, 'Expected dbDelta-compatible schema repair to run once.');
    kiwi_assert_true(!in_array('inspection_error', array_column($result['drift'], 'kind'), true), 'Expected no false seed inspection error after schema repair.');
    kiwi_assert_same(Kiwi_Database_Deployment_Service::TARGET_SCHEMA_VERSION, $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION] ?? '', 'Expected target version after verified additive repair.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi database apply blocks object type mismatches before mutation', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
    $view_contract = [
        'type' => 'view',
        'columns' => ['id'],
    ];
    $wpdb->objects['abc_kiwi_test_view'] = [
        'type' => 'BASE TABLE',
        'columns' => ['id'],
        'indexes' => ['PRIMARY'],
    ];
    $wpdb->row_counts['abc_kiwi_test_view'] = 23;
    $GLOBALS['kiwi_test_options'] = [
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => '2026-05-12-1',
    ];
    $step = new Kiwi_Test_Database_Schema_Step(
        $wpdb,
        'abc_kiwi_test_view',
        $view_contract
    );
    $service = new Kiwi_Test_Database_Deployment_Service(
        [[
            'name' => 'test_view',
            'repository' => $step,
            'objects' => ['kiwi_test_view'],
        ]],
        ['kiwi_test_view' => $view_contract]
    );

    $result = $service->apply();

    kiwi_assert_same('object_type_mismatch', $result['error_code'], 'Expected table/view mismatches to require an explicit migration artifact.');
    kiwi_assert_same(0, $step->calls, 'Expected no schema command after an object type mismatch.');
    kiwi_assert_same(23, $wpdb->row_counts['abc_kiwi_test_view'], 'Expected mismatched object data to remain unchanged.');
    kiwi_assert_same('2026-05-12-1', $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION], 'Expected blocked type mismatch to preserve the installed version.');
    kiwi_assert_same(false, $wpdb->lock_held, 'Expected the external lock to be released.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi database apply blocks newer and unknown schema versions', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;

    foreach (['2026-07-21-1', 'future-release'] as $installed_version) {
        $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
        $contract = kiwi_test_database_contract()['kiwi_test_table'];
        $wpdb->objects['abc_kiwi_test_table'] = [
            'type' => 'BASE TABLE',
            'columns' => $contract['columns'],
            'indexes' => $contract['indexes'],
        ];
        $GLOBALS['kiwi_test_options'] = [
            Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => $installed_version,
        ];
        [$service, $step] = kiwi_test_database_service($wpdb);

        $result = $service->apply();

        kiwi_assert_same('schema_version_newer_or_unknown', $result['error_code'], 'Expected a newer or unknown installed version to block apply.');
        kiwi_assert_same(0, $step->calls, 'Expected no schema command after a version downgrade guard.');
        kiwi_assert_same($installed_version, $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION], 'Expected blocked apply to preserve newer or unknown version evidence.');
        kiwi_assert_same(false, $result['mutated'], 'Expected the version guard to stop before mutation.');
        kiwi_assert_same(false, $wpdb->lock_held, 'Expected the external lock to be released.');
    }

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
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => '2026-05-12-1',
    ];
    [$service, $step] = kiwi_test_database_service($wpdb);

    $result = $service->apply();

    kiwi_assert_same('legacy_migration_required', $result['error_code'], 'Expected generic apply to refuse legacy data transformations.');
    kiwi_assert_same(0, $step->calls, 'Expected no schema command after legacy preflight drift.');
    kiwi_assert_same(41465, $wpdb->row_counts['abc_kiwi_test_table'], 'Expected active data to remain unchanged.');
    kiwi_assert_same('2026-05-12-1', $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION], 'Expected failed preflight to preserve the installed version.');
    kiwi_assert_same(false, $wpdb->lock_held, 'Expected the external lock to be released.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi database apply rejects a concurrent external runner', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
    $wpdb->lock_available = false;
    $GLOBALS['kiwi_test_options'] = [
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => '2026-05-12-1',
    ];
    [$service, $step] = kiwi_test_database_service($wpdb);

    $result = $service->apply();

    kiwi_assert_same('lock_unavailable', $result['error_code'], 'Expected the second apply to stop at the exclusive lock.');
    kiwi_assert_same(0, $step->calls, 'Expected lock contention to prevent schema commands.');
    kiwi_assert_same('2026-05-12-1', $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION], 'Expected lock contention to preserve the installed version.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi database apply preserves version and data after command failure', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
    $wpdb->row_counts['abc_kiwi_test_table'] = 41465;
    $GLOBALS['kiwi_test_options'] = [
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => '2026-05-12-1',
    ];
    [$service] = kiwi_test_database_service($wpdb, 'command_failure');

    $result = $service->apply();

    kiwi_assert_same('schema_command_failed', $result['error_code'], 'Expected command errors to fail apply.');
    kiwi_assert_same('2026-05-12-1', $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION], 'Expected command failure not to persist the target version.');
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
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => '2026-05-12-1',
    ];
    [$service] = kiwi_test_database_service($wpdb, 'postcondition_failure');

    $result = $service->apply();

    kiwi_assert_same('schema_postcondition_failed', $result['error_code'], 'Expected missing postconditions to fail apply.');
    kiwi_assert_same('2026-05-12-1', $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION], 'Expected failed postconditions not to persist the target version.');

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

kiwi_run_test('Kiwi database runner registers only the early WP-CLI command surface', function (): void {
    $runner_source = file_get_contents(__DIR__ . '/../tools/database/kiwi-database.php');

    kiwi_assert_true(is_string($runner_source), 'Expected the external database runner source to be readable.');
    kiwi_assert_true(isset(WP_CLI::$commands['kiwi']), 'Expected the repository-owned Kiwi command container.');
    kiwi_assert_true(isset(WP_CLI::$commands['kiwi database']), 'Expected the database command to be registered.');
    kiwi_assert_same(
        'before_wp_load',
        WP_CLI::$commands['kiwi database']['args']['when'] ?? '',
        'Expected database commands to invoke before WordPress loads normally.'
    );
    kiwi_assert_true(
        WP_CLI::$commands['kiwi database']['callable'] instanceof Kiwi_Database_Command,
        'Expected the registered database command object.'
    );
    kiwi_assert_same([], WP_CLI::$wp_hooks, 'Expected loading through --require to register no WordPress hook yet.');
    kiwi_assert_same([], WP_CLI::$lines, 'Expected loading through --require to produce no command result.');
    kiwi_assert_same([], WP_CLI::$errors, 'Expected loading through --require to produce no error.');
    kiwi_assert_same(true, kiwi_database_cli_has_required_api(WP_CLI::class), 'Expected the WP-CLI 2.12-compatible API surface to pass.');
    kiwi_assert_same(false, kiwi_database_cli_has_required_api(Kiwi_Test_Incomplete_WP_CLI::class), 'Expected incomplete WP-CLI APIs to fail closed.');
    kiwi_assert_true(strpos($runner_source, "WP_CLI::add_wp_hook(") !== false, 'Expected repository-owned plugins_loaded scheduling.');
    kiwi_assert_true(strpos($runner_source, "WP_CLI::get_runner()") !== false, 'Expected repository-owned WordPress loading.');
});

kiwi_run_test('Kiwi database status runs after plugins load and halts before init', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
    WP_CLI::reset_runtime();
    $command = WP_CLI::$commands['kiwi database']['callable'];

    kiwi_test_expect_cli_halt(static function () use ($command): void {
        $command->status([], []);
    }, 1);

    $result = json_decode(WP_CLI::$lines[0] ?? '', true);
    $mutating_queries = array_values(array_filter(
        $wpdb->queries,
        static function (string $query): bool {
            return preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|GET_LOCK|RELEASE_LOCK)\b/i', $query) === 1;
        }
    ));

    kiwi_assert_same(1, WP_CLI::$runner->load_wordpress_calls, 'Expected WordPress to load exactly once.');
    kiwi_assert_same(1, count(WP_CLI::$wp_hooks['plugins_loaded'] ?? []), 'Expected one plugins_loaded callback.');
    kiwi_assert_same(1, did_action('plugins_loaded'), 'Expected execution after plugins_loaded.');
    kiwi_assert_same(0, did_action('init'), 'Expected explicit halt before init.');
    kiwi_assert_same(false, WP_CLI::$runner->continued_to_init, 'Expected no normal init continuation.');
    kiwi_assert_true(is_array($result), 'Expected structured JSON from status.');
    kiwi_assert_same('status', $result['mode'] ?? '', 'Expected the status mode to execute.');
    kiwi_assert_same(false, $result['mutated'] ?? null, 'Expected status to remain read-only.');
    kiwi_assert_true(!empty($wpdb->queries), 'Expected status to inspect real postconditions.');
    kiwi_assert_same([], $mutating_queries, 'Expected status not to issue mutating SQL or obtain the apply lock.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi database runner fails before service work on lifecycle and hook errors', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $cases = [
        'before_plugins_loaded' => [0, 0, true],
        'after_init' => [1, 1, true],
        'hook_not_reached' => [1, 0, false],
    ];

    foreach ($cases as $name => [$plugins_loaded_count, $init_count, $invoke_hook]) {
        $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
        WP_CLI::reset_runtime();
        WP_CLI::$runner->plugins_loaded_count = $plugins_loaded_count;
        WP_CLI::$runner->init_count = $init_count;
        WP_CLI::$runner->invoke_plugins_loaded = $invoke_hook;
        $command = WP_CLI::$commands['kiwi database']['callable'];

        kiwi_test_expect_cli_halt(static function () use ($command): void {
            $command->status([], []);
        }, 1);

        kiwi_assert_same([], $wpdb->queries, "Expected {$name} to fail before schema inspection.");
        kiwi_assert_same([], WP_CLI::$lines, "Expected {$name} to produce no false status result.");
        kiwi_assert_true(!empty(WP_CLI::$errors), "Expected {$name} to expose a stable CLI error.");
    }

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi database runner fails closed for missing classes and JSON errors', function (): void {
    $missing_class_service = new Kiwi_Test_Database_Command_Service(['success' => true]);
    $missing_class_command = new Kiwi_Database_Command(
        ['Kiwi_Test_Missing_Database_Class'],
        static function () use ($missing_class_service) {
            return $missing_class_service;
        }
    );

    WP_CLI::reset_runtime();
    kiwi_test_expect_cli_halt(static function () use ($missing_class_command): void {
        $missing_class_command->status([], []);
    }, 1);
    kiwi_assert_same([], $missing_class_service->calls, 'Expected missing plugin classes to block the service.');
    kiwi_assert_same([], WP_CLI::$lines, 'Expected no result after missing plugin classes.');

    $json_service = new Kiwi_Test_Database_Command_Service([
        'success' => true,
        'mode' => 'status',
        'mutated' => false,
    ]);
    $json_command = new Kiwi_Database_Command(
        [],
        static function () use ($json_service) {
            return $json_service;
        },
        static function (array $result) {
            return false;
        }
    );

    WP_CLI::reset_runtime();
    kiwi_test_expect_cli_halt(static function () use ($json_command): void {
        $json_command->status([], []);
    }, 1);
    kiwi_assert_same(['status'], $json_service->calls, 'Expected status to execute once before the JSON failure.');
    kiwi_assert_same(
        ['{"success":false,"error_code":"json_encode_failed"}'],
        WP_CLI::$lines,
        'Expected a stable machine-readable JSON failure.'
    );
    kiwi_assert_same(0, did_action('init'), 'Expected JSON failure to halt before init.');
});

kiwi_run_test('Kiwi database runner maps safe results to explicit exit codes', function (): void {
    foreach ([true => 0, false => 1] as $success => $exit_code) {
        $service = new Kiwi_Test_Database_Command_Service([
            'success' => (bool) $success,
            'mode' => 'status',
            'mutated' => false,
        ]);
        $command = new Kiwi_Database_Command(
            [],
            static function () use ($service) {
                return $service;
            }
        );

        WP_CLI::reset_runtime();
        kiwi_test_expect_cli_halt(static function () use ($command): void {
            $command->status([], []);
        }, $exit_code);

        kiwi_assert_same(['status'], $service->calls, 'Expected exactly one explicit status call.');
        kiwi_assert_same(0, did_action('init'), 'Expected every result to halt before init.');
        kiwi_assert_same(false, WP_CLI::$runner->continued_to_init, 'Expected no result path to continue into init.');
    }
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
