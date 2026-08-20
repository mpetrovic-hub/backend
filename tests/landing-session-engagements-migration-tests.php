<?php

require_once __DIR__ . '/../tools/database/migrations/class-landing-session-engagements-migration-service.php';
require_once __DIR__ . '/../tools/database/migrations/landing-session-engagements.php';

class Kiwi_Test_Landing_Session_Engagements_Migration_Wpdb
{
    public $prefix = 'abc_';
    public $last_error = '';
    public $objects = [];
    public $queries = [];
    public $lock_available = true;
    public $lock_held = false;
    public $rename_failure = false;
    public $lock_lost_after_rename = false;
    public $inspection_error_for = '';
    public $snapshot_mismatch_after_rename = false;
    public $prepared_statements = [];

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $statement = [
            'query' => (string) $query,
            'args' => $args,
        ];
        $this->prepared_statements[] = $statement;

        return $statement;
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

        if (strpos($query, 'SELECT IS_USED_LOCK(') === 0) {
            return $this->lock_held ? 1 : 0;
        }

        if (strpos($query, 'SELECT RELEASE_LOCK(') === 0) {
            $this->lock_held = false;

            return 1;
        }

        if (strpos($query, 'SELECT TABLE_TYPE FROM information_schema.TABLES') === 0) {
            $table_name = (string) ($args[0] ?? '');
            if ($table_name === $this->inspection_error_for) {
                $this->last_error = 'inspection denied; password=must-not-leak; MSISDN=436641234567';

                return null;
            }

            return $this->objects[$table_name]['type'] ?? null;
        }

        if (strpos($query, 'SELECT AUTO_INCREMENT FROM information_schema.TABLES') === 0) {
            $table_name = (string) ($args[0] ?? '');

            return $this->objects[$table_name]['auto_increment'] ?? null;
        }

        return null;
    }

    public function get_results($statement, $output = ARRAY_A)
    {
        [$query, $args] = $this->unpack($statement);
        $this->queries[] = $query;
        $table_name = (string) ($args[0] ?? '');

        if ($table_name === $this->inspection_error_for) {
            $this->last_error = 'metadata denied; token=must-not-leak; subscriber_reference=436641234567';

            return [];
        }

        if (strpos($query, 'SELECT COLUMN_NAME, COLUMN_TYPE') === 0) {
            return (array) ($this->objects[$table_name]['columns'] ?? []);
        }

        if (strpos($query, 'SELECT INDEX_NAME, NON_UNIQUE') === 0) {
            return (array) ($this->objects[$table_name]['indexes'] ?? []);
        }

        return [];
    }

    public function get_row($statement, $output = ARRAY_A)
    {
        [$query] = $this->unpack($statement);
        $this->queries[] = $query;

        if (!preg_match('/FROM `([^`]+)`$/', trim($query), $matches)) {
            return null;
        }

        $table_name = (string) $matches[1];
        if ($table_name === $this->inspection_error_for) {
            $this->last_error = 'row snapshot denied; secret=must-not-leak';

            return null;
        }

        $object = (array) ($this->objects[$table_name] ?? []);

        return [
            'row_count' => (int) ($object['row_count'] ?? 0),
            'min_id' => $object['min_id'] ?? null,
            'max_id' => $object['max_id'] ?? null,
        ];
    }

    public function query($sql)
    {
        $sql = (string) $sql;
        $this->queries[] = $sql;

        if (!preg_match('/^RENAME TABLE `([^`]+)` TO `([^`]+)`$/', $sql, $matches)) {
            $this->last_error = 'unsupported query';

            return false;
        }

        if ($this->rename_failure) {
            $this->last_error = 'rename denied; password=must-not-leak; MSISDN=436641234567';

            return false;
        }

        $source = (string) $matches[1];
        $target = (string) $matches[2];
        if (!isset($this->objects[$source]) || isset($this->objects[$target])) {
            $this->last_error = 'rename state conflict';

            return false;
        }

        $this->objects[$target] = $this->objects[$source];
        unset($this->objects[$source]);

        if ($this->snapshot_mismatch_after_rename) {
            $this->objects[$target]['row_count']++;
        }

        if ($this->lock_lost_after_rename) {
            $this->lock_held = false;
        }

        return true;
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

class Kiwi_Test_Landing_Session_Engagements_Migration_Command_Service
{
    public $calls = [];
    private $result;

    public function __construct(array $result)
    {
        $this->result = $result;
    }

    public function check(): array
    {
        $this->calls[] = 'check';

        return $this->result;
    }

    public function apply(): array
    {
        $this->calls[] = 'apply';

        return $this->result;
    }

    public function rollback(): array
    {
        $this->calls[] = 'rollback';

        return $this->result;
    }
}

function kiwi_test_landing_session_engagements_migration_table(): array
{
    $contract = require __DIR__ . '/../tools/database/schema-contract.php';
    $definition = $contract[Kiwi_Database_Table_Names::LANDING_SESSION_ENGAGEMENTS];
    $columns = [];
    foreach ((array) $definition['columns'] as $index => $column) {
        $columns[] = [
            'COLUMN_NAME' => $column,
            'COLUMN_TYPE' => $column === 'id' ? 'bigint(20) unsigned' : 'varchar(191)',
            'IS_NULLABLE' => $column === 'page_loaded_at' ? 'YES' : 'NO',
            'COLUMN_DEFAULT' => null,
            'EXTRA' => $column === 'id' ? 'auto_increment' : '',
            'ORDINAL_POSITION' => $index + 1,
        ];
    }

    $indexes = [];
    foreach ((array) $definition['indexes'] as $index) {
        $indexes[] = [
            'INDEX_NAME' => $index,
            'NON_UNIQUE' => $index === 'PRIMARY' ? '0' : '1',
            'SEQ_IN_INDEX' => '1',
            'COLUMN_NAME' => 'id',
            'SUB_PART' => null,
            'INDEX_TYPE' => 'BTREE',
        ];
    }

    return [
        'type' => 'BASE TABLE',
        'columns' => $columns,
        'indexes' => $indexes,
        'row_count' => 3,
        'min_id' => 7,
        'max_id' => 41,
        'auto_increment' => 73,
    ];
}

function kiwi_test_landing_session_engagements_migration_state(string $state): Kiwi_Test_Landing_Session_Engagements_Migration_Wpdb
{
    $wpdb = new Kiwi_Test_Landing_Session_Engagements_Migration_Wpdb();
    $source = $wpdb->prefix . Kiwi_Landing_Session_Engagements_Migration_Service::SOURCE_TABLE_SUFFIX;
    $target = $wpdb->prefix . Kiwi_Database_Table_Names::LANDING_SESSION_ENGAGEMENTS;
    $table = kiwi_test_landing_session_engagements_migration_table();

    if (in_array($state, ['pending', 'conflict'], true)) {
        $wpdb->objects[$source] = $table;
    }
    if (in_array($state, ['applied', 'conflict'], true)) {
        $wpdb->objects[$target] = $table;
    }

    $GLOBALS['kiwi_test_options'] = [
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => $state === 'applied'
            ? Kiwi_Landing_Session_Engagements_Migration_Service::TARGET_SCHEMA_VERSION
            : Kiwi_Landing_Session_Engagements_Migration_Service::SOURCE_SCHEMA_VERSION,
    ];
    $GLOBALS['kiwi_test_update_option_fail'] = false;

    return $wpdb;
}

kiwi_run_test('Kiwi landing-session engagement migration check is read-only for pending and applied states', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    foreach (['pending', 'applied'] as $state) {
        $wpdb = kiwi_test_landing_session_engagements_migration_state($state);
        $result = (new Kiwi_Landing_Session_Engagements_Migration_Service())->check();

        kiwi_assert_same(true, $result['success'], 'Expected a supported migration state to pass check.');
        kiwi_assert_same($state, $result['state'], 'Expected check to report the exact migration state.');
        kiwi_assert_same(false, $result['mutated'], 'Expected check to remain read-only.');
        kiwi_assert_same(3, $result['snapshot']['row_count'], 'Expected check to expose the verified row count.');
        kiwi_assert_same([], array_values(array_filter($wpdb->queries, static function (string $query): bool {
            return preg_match('/\b(?:GET_LOCK|RELEASE_LOCK|RENAME|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\b/i', $query) === 1;
        })), 'Expected check to execute read-only inspection queries only.');
    }

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi landing-session engagement migration fails closed for conflict missing schema and version states', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $cases = [
        'conflict' => 'conflict',
        'missing' => 'missing',
        'version_mismatch' => 'version_mismatch',
        'schema_mismatch' => 'schema_mismatch',
        'object_type_mismatch' => 'schema_mismatch',
    ];

    foreach ($cases as $case => $expected_state) {
        $wpdb = kiwi_test_landing_session_engagements_migration_state(
            $case === 'conflict' ? 'conflict' : ($case === 'missing' ? 'missing' : 'pending')
        );

        if ($case === 'version_mismatch') {
            $GLOBALS['kiwi_test_options'][Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION] = '2026-07-19-1';
        }
        if ($case === 'schema_mismatch') {
            $source = $wpdb->prefix . Kiwi_Landing_Session_Engagements_Migration_Service::SOURCE_TABLE_SUFFIX;
            array_pop($wpdb->objects[$source]['indexes']);
        }
        if ($case === 'object_type_mismatch') {
            $source = $wpdb->prefix . Kiwi_Landing_Session_Engagements_Migration_Service::SOURCE_TABLE_SUFFIX;
            $wpdb->objects[$source]['type'] = 'VIEW';
        }

        $result = (new Kiwi_Landing_Session_Engagements_Migration_Service())->check();

        kiwi_assert_same(false, $result['success'], "Expected {$case} to fail check.");
        kiwi_assert_same($expected_state, $result['state'], "Expected {$case} to expose a stable state.");
        kiwi_assert_same(false, $result['mutated'], "Expected {$case} not to mutate the database.");
    }

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi landing-session engagement migration sanitizes query errors without mutation', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = kiwi_test_landing_session_engagements_migration_state('pending');
    $source = $wpdb->prefix . Kiwi_Landing_Session_Engagements_Migration_Service::SOURCE_TABLE_SUFFIX;
    $wpdb->inspection_error_for = $source;

    $result = (new Kiwi_Landing_Session_Engagements_Migration_Service())->check();

    kiwi_assert_same(false, $result['success'], 'Expected a failed metadata query to fail check.');
    kiwi_assert_same('inspection_error', $result['state'], 'Expected a stable inspection-error state.');
    kiwi_assert_same(false, $result['mutated'], 'Expected query errors not to mutate the database.');
    kiwi_assert_true(strpos($result['error_message'], 'must-not-leak') === false, 'Expected query errors to redact credentials.');
    kiwi_assert_true(strpos($result['error_message'], '436641234567') === false, 'Expected query errors to redact subscriber identifiers.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi landing-session engagement migration apply preserves identity and is idempotent', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = kiwi_test_landing_session_engagements_migration_state('pending');
    $service = new Kiwi_Landing_Session_Engagements_Migration_Service();
    $first = $service->apply();
    $second = $service->apply();
    $target = $wpdb->prefix . Kiwi_Database_Table_Names::LANDING_SESSION_ENGAGEMENTS;

    kiwi_assert_same(true, $first['success'], 'Expected apply to succeed from the exact predecessor state.');
    kiwi_assert_same('applied', $first['state'], 'Expected apply to publish the applied state.');
    kiwi_assert_same(true, $first['mutated'], 'Expected first apply to report the rename mutation.');
    kiwi_assert_same(false, $first['no_op'], 'Expected first apply not to be a no-op.');
    kiwi_assert_same(3, $wpdb->objects[$target]['row_count'], 'Expected row count to survive the rename.');
    kiwi_assert_same(73, $wpdb->objects[$target]['auto_increment'], 'Expected AUTO_INCREMENT to survive the rename.');
    kiwi_assert_same(Kiwi_Landing_Session_Engagements_Migration_Service::TARGET_SCHEMA_VERSION, get_option(Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION), 'Expected target version only after postconditions pass.');
    kiwi_assert_same(true, $second['success'], 'Expected repeated apply to succeed.');
    kiwi_assert_same(true, $second['no_op'], 'Expected repeated apply to be a no-op.');
    kiwi_assert_same(false, $second['mutated'], 'Expected repeated apply not to mutate.');
    kiwi_assert_same(false, $wpdb->lock_held, 'Expected the shared database lock to be released.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi landing-session engagement migration rollback preserves identity and is idempotent', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = kiwi_test_landing_session_engagements_migration_state('applied');
    $service = new Kiwi_Landing_Session_Engagements_Migration_Service();
    $first = $service->rollback();
    $second = $service->rollback();
    $source = $wpdb->prefix . Kiwi_Landing_Session_Engagements_Migration_Service::SOURCE_TABLE_SUFFIX;

    kiwi_assert_same(true, $first['success'], 'Expected rollback to succeed from the exact applied state.');
    kiwi_assert_same('pending', $first['state'], 'Expected rollback to restore the predecessor state.');
    kiwi_assert_same(true, $first['mutated'], 'Expected first rollback to report the rename mutation.');
    kiwi_assert_same(3, $wpdb->objects[$source]['row_count'], 'Expected row count to survive rollback.');
    kiwi_assert_same(73, $wpdb->objects[$source]['auto_increment'], 'Expected AUTO_INCREMENT to survive rollback.');
    kiwi_assert_same(Kiwi_Landing_Session_Engagements_Migration_Service::SOURCE_SCHEMA_VERSION, get_option(Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION), 'Expected predecessor version only after rollback postconditions pass.');
    kiwi_assert_same(true, $second['success'], 'Expected repeated rollback to succeed.');
    kiwi_assert_same(true, $second['no_op'], 'Expected repeated rollback to be a no-op.');
    kiwi_assert_same(false, $second['mutated'], 'Expected repeated rollback not to mutate.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi landing-session engagement migration exposes fail-closed lock rename and version failures', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;

    $wpdb = kiwi_test_landing_session_engagements_migration_state('pending');
    $wpdb->lock_available = false;
    $locked = (new Kiwi_Landing_Session_Engagements_Migration_Service())->apply();
    kiwi_assert_same('lock_unavailable', $locked['error_code'], 'Expected lock contention to stop before mutation.');
    kiwi_assert_same(false, $locked['mutated'], 'Expected lock contention not to mutate.');

    $wpdb = kiwi_test_landing_session_engagements_migration_state('pending');
    $wpdb->rename_failure = true;
    $rename_failed = (new Kiwi_Landing_Session_Engagements_Migration_Service())->apply();
    kiwi_assert_same('rename_failed', $rename_failed['error_code'], 'Expected rename failure to stay explicit.');
    kiwi_assert_true(strpos($rename_failed['error_message'], 'must-not-leak') === false, 'Expected rename errors to redact credentials.');
    kiwi_assert_true(strpos($rename_failed['error_message'], '436641234567') === false, 'Expected rename errors to redact subscriber identifiers.');
    kiwi_assert_same(Kiwi_Landing_Session_Engagements_Migration_Service::SOURCE_SCHEMA_VERSION, get_option(Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION), 'Expected rename failure to preserve the predecessor version.');

    $wpdb = kiwi_test_landing_session_engagements_migration_state('pending');
    $wpdb->snapshot_mismatch_after_rename = true;
    $snapshot_failed = (new Kiwi_Landing_Session_Engagements_Migration_Service())->apply();
    kiwi_assert_same('snapshot_mismatch', $snapshot_failed['error_code'], 'Expected changed row identity to fail the post-rename snapshot.');
    kiwi_assert_same(true, $snapshot_failed['mutated'], 'Expected snapshot failure after rename to expose the partial mutation.');
    kiwi_assert_same(Kiwi_Landing_Session_Engagements_Migration_Service::SOURCE_SCHEMA_VERSION, get_option(Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION), 'Expected snapshot failure not to publish the target version.');

    $wpdb = kiwi_test_landing_session_engagements_migration_state('pending');
    $wpdb->lock_lost_after_rename = true;
    $lock_lost = (new Kiwi_Landing_Session_Engagements_Migration_Service())->apply();
    kiwi_assert_same('lock_lost', $lock_lost['error_code'], 'Expected lock loss after rename to fail closed.');
    kiwi_assert_same(true, $lock_lost['mutated'], 'Expected lock loss after rename to expose the partial mutation.');
    kiwi_assert_same(Kiwi_Landing_Session_Engagements_Migration_Service::SOURCE_SCHEMA_VERSION, get_option(Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION), 'Expected lock loss not to publish the target version.');

    $wpdb = kiwi_test_landing_session_engagements_migration_state('pending');
    $GLOBALS['kiwi_test_update_option_fail'] = true;
    $version_failed = (new Kiwi_Landing_Session_Engagements_Migration_Service())->apply();
    kiwi_assert_same('schema_version_not_persisted', $version_failed['error_code'], 'Expected version persistence failure to expose the partial state.');
    kiwi_assert_same(true, $version_failed['mutated'], 'Expected version persistence failure after rename to report mutation.');
    kiwi_assert_same(Kiwi_Landing_Session_Engagements_Migration_Service::SOURCE_SCHEMA_VERSION, get_option(Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION), 'Expected failed persistence not to claim the target version.');
    $GLOBALS['kiwi_test_update_option_fail'] = false;

    $lock_statements = array_values(array_filter($wpdb->prepared_statements, static function (array $statement): bool {
        return preg_match('/SELECT (?:GET_LOCK|IS_USED_LOCK|RELEASE_LOCK)\(/', (string) ($statement['query'] ?? '')) === 1;
    }));
    $lock_arguments = array_values(array_unique(array_map(static function (array $statement): string {
        return (string) ($statement['args'][0] ?? '');
    }, $lock_statements)));
    kiwi_assert_same(1, count($lock_arguments), 'Expected migration lock acquire, ownership checks, and release to share one lock name.');
    kiwi_assert_same(
        'kiwi_backend_database_apply_' . substr(hash('sha256', $wpdb->prefix), 0, 20),
        $lock_arguments[0] ?? '',
        'Expected the migration to use the general database deployment lock namespace.'
    );

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi general database apply refuses the legacy engagement table before schema work', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Database_Deployment_Wpdb();
    $contract = kiwi_test_database_contract();
    $step = new Kiwi_Test_Database_Schema_Step($wpdb, 'abc_kiwi_test_table', $contract['kiwi_test_table']);
    $GLOBALS['kiwi_test_options'] = [
        Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION => '2026-07-20-1',
    ];
    $service = new Kiwi_Test_Database_Deployment_Service(
        [[
            'name' => 'test_table',
            'repository' => $step,
            'objects' => ['kiwi_test_table'],
        ]],
        $contract,
        static function (): array {
            return [[
                'kind' => 'legacy_table',
                'object' => 'abc_' . Kiwi_Landing_Session_Engagements_Migration_Service::SOURCE_TABLE_SUFFIX,
            ]];
        }
    );

    $result = $service->apply();

    kiwi_assert_same('legacy_migration_required', $result['error_code'], 'Expected general apply to refuse the historical rename.');
    kiwi_assert_same(0, $step->calls, 'Expected general apply not to create a parallel empty target table.');
    kiwi_assert_same(false, $result['mutated'], 'Expected legacy preflight to stop before mutation.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi general database apply uses the real migration blocker for the predecessor table', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = kiwi_test_landing_session_engagements_migration_state('pending');
    $contract = kiwi_test_database_contract();
    $step = new class {
        public $calls = 0;

        public function create_table(): void
        {
            $this->calls++;
        }
    };
    $service = new Kiwi_Test_Database_Deployment_Service(
        [[
            'name' => 'test_table',
            'repository' => $step,
            'objects' => ['kiwi_test_table'],
        ]],
        $contract,
        static function (): array {
            return (new Kiwi_Landing_Session_Engagements_Migration_Service())->inspect_general_apply_blocker();
        }
    );

    $result = $service->apply();

    kiwi_assert_same('legacy_migration_required', $result['error_code'], 'Expected the production migration inspector to block the predecessor table.');
    kiwi_assert_same(0, $step->calls, 'Expected the real blocker to stop before schema mutation.');
    kiwi_assert_same(false, $result['mutated'], 'Expected the real blocker not to mutate the database.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi landing-session engagement repository uses the neutral evaluator contract', function (): void {
    $service = new Kiwi_Premium_Sms_Landing_Engagement_Soft_Flag_Service(new Kiwi_Test_Config());
    $plugin_source = (string) file_get_contents(__DIR__ . '/../includes/core/class-plugin.php');

    kiwi_assert_true($service instanceof Kiwi_Landing_Session_Engagement_Evaluator_Interface, 'Expected the Premium-SMS policy to implement the neutral evaluator contract.');
    kiwi_assert_true(class_exists('Kiwi_Landing_Session_Engagement_Repository'), 'Expected the generic repository class.');
    kiwi_assert_same(false, class_exists('Kiwi_Premium_Sms_Landing_Engagement_Repository'), 'Expected no legacy repository alias.');
    kiwi_assert_contains('new Kiwi_Premium_Sms_Landing_Engagement_Soft_Flag_Service($config)', $plugin_source, 'Expected runtime composition to inject the concrete Premium-SMS evaluator.');
    kiwi_assert_true(strpos($plugin_source, 'premium_sms_landing_engagement_repository') === false, 'Expected the old runtime container key to be removed.');
});

kiwi_run_test('Kiwi landing-session engagement writes fail without an injected evaluator', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new class {
        public $prefix = 'abc_';

        public function prepare($query, ...$args)
        {
            return ['query' => $query, 'args' => $args];
        }

        public function get_row($statement, $output = ARRAY_A)
        {
            return null;
        }
    };
    $caught = null;

    try {
        (new Kiwi_Landing_Session_Engagement_Repository())->upsert_event([
            'landing_key' => 'lp-test',
            'session_token' => 'synthetic-session',
        ], 'page_loaded', '2026-08-20 12:00:00');
    } catch (RuntimeException $error) {
        $caught = $error;
    }

    kiwi_assert_true($caught instanceof RuntimeException, 'Expected productive writes without an evaluator to fail closed.');
    kiwi_assert_contains('evaluator is required', $caught->getMessage(), 'Expected the missing dependency to be explicit.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi landing-session engagement migration command registers the early WP-CLI surface', function (): void {
    kiwi_assert_true(isset(WP_CLI::$commands['kiwi database migration']), 'Expected the standalone migration namespace to be registered.');
    kiwi_assert_true(isset(WP_CLI::$commands['kiwi database migration landing-session-engagements']), 'Expected the migration command to be registered.');
    kiwi_assert_same(
        'before_wp_load',
        WP_CLI::$commands['kiwi database migration landing-session-engagements']['args']['when'] ?? '',
        'Expected the migration command to load before normal WordPress startup.'
    );

    foreach ([
        'check' => [true, 0],
        'apply' => [false, 1],
        'rollback' => [true, 0],
    ] as $mode => [$success, $exit_code]) {
        $service = new Kiwi_Test_Landing_Session_Engagements_Migration_Command_Service([
            'success' => $success,
            'mode' => $mode,
            'state' => $success ? 'pending' : 'error',
            'mutated' => false,
        ]);
        $command = new Kiwi_Landing_Session_Engagements_Migration_Command(
            [],
            static function () use ($service) {
                return $service;
            }
        );

        WP_CLI::reset_runtime();
        kiwi_test_expect_cli_halt(static function () use ($command, $mode): void {
            $command->{$mode}([], []);
        }, $exit_code);

        kiwi_assert_same([$mode], $service->calls, 'Expected one explicit migration command call.');
        kiwi_assert_same(0, did_action('init'), 'Expected migration commands to halt before init.');
        kiwi_assert_same(false, WP_CLI::$runner->continued_to_init, 'Expected no migration result to continue into init.');
    }
});
