<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Explicit database deployment boundary for WP-CLI.
 *
 * This file is intentionally not loaded by includes/bootstrap.php. Normal
 * WordPress requests must never invoke schema or one-time data changes.
 */
class Kiwi_Database_Deployment_Service
{
    public const SCHEMA_VERSION_OPTION = 'kiwi_backend_db_schema_version';
    public const TARGET_SCHEMA_VERSION = '2026-07-20-1';

    private const LOCK_PREFIX = 'kiwi_backend_database_apply_';

    private $schema_steps;
    private $schema_contract;
    private $mutation_started = false;

    public function __construct(?array $schema_steps = null, ?array $schema_contract = null)
    {
        $this->schema_steps = is_array($schema_steps) ? $schema_steps : $this->build_schema_steps();
        $this->schema_contract = is_array($schema_contract) ? $schema_contract : $this->build_schema_contract();
    }

    public function status(): array
    {
        $inspection = $this->inspect_schema();
        $installed_version = $this->get_installed_schema_version();
        $drift = (array) ($inspection['drift'] ?? []);

        if ($installed_version !== self::TARGET_SCHEMA_VERSION) {
            $drift[] = [
                'kind' => 'version_mismatch',
                'object' => self::SCHEMA_VERSION_OPTION,
                'expected' => self::TARGET_SCHEMA_VERSION,
                'actual' => $installed_version,
            ];
        }

        return [
            'success' => empty($drift),
            'mode' => 'status',
            'ready' => empty($drift),
            'mutated' => false,
            'target_version' => self::TARGET_SCHEMA_VERSION,
            'installed_version' => $installed_version,
            'drift' => $drift,
        ];
    }

    public function apply(): array
    {
        $this->mutation_started = false;

        if (!$this->acquire_lock()) {
            return $this->failure_result(
                'lock',
                'lock_unavailable',
                'Another external database apply is already running.'
            );
        }

        try {
            $before = $this->inspect_schema();
            $preflight_drift = (array) ($before['drift'] ?? []);
            $inspection_errors = array_values(array_filter(
                $preflight_drift,
                static function (array $drift): bool {
                    return ($drift['kind'] ?? '') === 'inspection_error';
                }
            ));

            if (!empty($inspection_errors)) {
                $result = $this->failure_result(
                    'preflight',
                    'schema_inspection_failed',
                    'Schema inspection failed; no database changes were applied.'
                );
                $result['drift'] = $inspection_errors;

                return $result;
            }

            $object_type_mismatches = array_values(array_filter(
                $preflight_drift,
                static function (array $drift): bool {
                    return ($drift['kind'] ?? '') === 'object_type_mismatch';
                }
            ));

            if (!empty($object_type_mismatches)) {
                $result = $this->failure_result(
                    'preflight',
                    'object_type_mismatch',
                    'Object type mismatch requires a reviewed migration-specific external artifact.'
                );
                $result['drift'] = $object_type_mismatches;

                return $result;
            }

            $legacy_drift = array_values(array_filter(
                $preflight_drift,
                static function (array $drift): bool {
                    return ($drift['kind'] ?? '') === 'legacy_column';
                }
            ));

            if (!empty($legacy_drift)) {
                $result = $this->failure_result(
                    'preflight',
                    'legacy_migration_required',
                    'Legacy schema requires a reviewed migration-specific external artifact.'
                );
                $result['drift'] = $legacy_drift;

                return $result;
            }

            foreach ($this->schema_steps as $step) {
                $name = trim((string) ($step['name'] ?? 'schema_step'));
                $repository = $step['repository'] ?? null;
                $objects = array_values(array_filter((array) ($step['objects'] ?? []), 'is_string'));

                if (!is_object($repository) || !method_exists($repository, 'create_table') || empty($objects)) {
                    return $this->failure_result(
                        'apply:' . $name,
                        'invalid_schema_step',
                        'Schema apply configuration is incomplete.'
                    );
                }

                $this->reset_database_error();
                $this->mutation_started = true;

                try {
                    $repository->create_table();
                } catch (Throwable $error) {
                    return $this->failure_result(
                        'apply:' . $name,
                        'schema_command_failed',
                        $error->getMessage()
                    );
                }

                $database_error = $this->get_database_error();
                if ($database_error !== '') {
                    return $this->failure_result(
                        'apply:' . $name,
                        'schema_command_failed',
                        $database_error
                    );
                }

                $step_inspection = $this->inspect_objects($objects);
                if (!empty($step_inspection['drift'])) {
                    $result = $this->failure_result(
                        'verify:' . $name,
                        'schema_postcondition_failed',
                        'A schema command completed without satisfying its required postconditions.'
                    );
                    $result['drift'] = $step_inspection['drift'];

                    return $result;
                }
            }

            $this->reset_database_error();

            try {
                $this->seed_defaults();
            } catch (Throwable $error) {
                return $this->failure_result(
                    'seed:device_model_brand_map',
                    'seed_failed',
                    $error->getMessage()
                );
            }

            if ($this->get_database_error() !== '') {
                return $this->failure_result(
                    'seed:device_model_brand_map',
                    'seed_failed',
                    $this->get_database_error()
                );
            }

            $final_schema = $this->inspect_schema();
            if (!empty($final_schema['drift'])) {
                $result = $this->failure_result(
                    'verify:final',
                    'schema_postcondition_failed',
                    'Final schema verification reported drift.'
                );
                $result['drift'] = $final_schema['drift'];

                return $result;
            }

            if (!$this->persist_schema_version(self::TARGET_SCHEMA_VERSION)
                || $this->get_installed_schema_version() !== self::TARGET_SCHEMA_VERSION
            ) {
                return $this->failure_result(
                    'persist_version',
                    'schema_version_not_persisted',
                    'The target schema version could not be confirmed after successful verification.'
                );
            }

            $status = $this->status();
            if (!$status['ready']) {
                return $this->failure_result(
                    'verify:published_version',
                    'schema_postcondition_failed',
                    'Published schema version did not pass the final status check.'
                );
            }

            $status['mode'] = 'apply';
            $status['mutated'] = true;

            return $status;
        } finally {
            $this->release_lock();
        }
    }

    protected function inspect_schema(): array
    {
        $inspection = $this->inspect_contract($this->schema_contract);
        $contract_drift = (array) ($inspection['drift'] ?? []);
        $inspection['drift'] = array_merge(
            $contract_drift,
            $this->inspect_seed_drift($contract_drift)
        );

        return $inspection;
    }

    protected function inspect_objects(array $objects): array
    {
        $contract = [];

        foreach ($objects as $object) {
            if (isset($this->schema_contract[$object])) {
                $contract[$object] = $this->schema_contract[$object];
            }
        }

        return $this->inspect_contract($contract);
    }

    protected function acquire_lock(): bool
    {
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return false;
        }

        $result = $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, 0)', $this->get_lock_name())
        );

        return (string) $result === '1';
    }

    protected function release_lock(): void
    {
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return;
        }

        $wpdb->get_var(
            $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->get_lock_name())
        );
    }

    protected function seed_defaults(): void
    {
        (new Kiwi_Device_Model_Brand_Map_Repository())->seed_default_mappings();
    }

    protected function get_installed_schema_version(): string
    {
        if (!function_exists('get_option')) {
            return '';
        }

        $version = get_option(self::SCHEMA_VERSION_OPTION, '');

        return is_string($version) ? $version : '';
    }

    protected function persist_schema_version(string $schema_version): bool
    {
        if (!function_exists('update_option')) {
            return false;
        }

        update_option(self::SCHEMA_VERSION_OPTION, $schema_version, true);

        return $this->get_installed_schema_version() === $schema_version;
    }

    private function inspect_contract(array $contract): array
    {
        global $wpdb;

        $drift = [];

        if (!is_object($wpdb)
            || !method_exists($wpdb, 'get_var')
            || !method_exists($wpdb, 'get_results')
            || !method_exists($wpdb, 'prepare')
        ) {
            return [
                'drift' => [[
                    'kind' => 'inspection_error',
                    'object' => 'database',
                    'detail' => 'WordPress database access is unavailable.',
                ]],
            ];
        }

        foreach ($contract as $suffix => $definition) {
            $object_name = (string) $wpdb->prefix . $suffix;
            $expected_type = ($definition['type'] ?? 'table') === 'view' ? 'VIEW' : 'BASE TABLE';
            $this->reset_database_error();
            $actual_type = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                    $object_name
                )
            );

            if ($this->get_database_error() !== '') {
                $drift[] = [
                    'kind' => 'inspection_error',
                    'object' => $object_name,
                    'detail' => $this->sanitize_error($this->get_database_error()),
                ];
                continue;
            }

            if ($actual_type === null || $actual_type === false || $actual_type === '') {
                $drift[] = [
                    'kind' => $expected_type === 'VIEW' ? 'missing_view' : 'missing_table',
                    'object' => $object_name,
                ];
                continue;
            }

            if (strtoupper((string) $actual_type) !== $expected_type) {
                $drift[] = [
                    'kind' => 'object_type_mismatch',
                    'object' => $object_name,
                    'expected' => $expected_type,
                    'actual' => strtoupper((string) $actual_type),
                ];
                continue;
            }

            $column_rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                    $object_name
                ),
                ARRAY_A
            );
            $columns = $this->collect_result_values($column_rows, 'COLUMN_NAME');

            if ($this->get_database_error() !== '') {
                $drift[] = [
                    'kind' => 'inspection_error',
                    'object' => $object_name,
                    'detail' => $this->sanitize_error($this->get_database_error()),
                ];
                continue;
            }

            foreach ((array) ($definition['columns'] ?? []) as $column) {
                if (!in_array($column, $columns, true)) {
                    $drift[] = [
                        'kind' => 'missing_column',
                        'object' => $object_name,
                        'column' => $column,
                    ];
                }
            }

            foreach ((array) ($definition['legacy_columns'] ?? []) as $column) {
                if (in_array($column, $columns, true)) {
                    $drift[] = [
                        'kind' => 'legacy_column',
                        'object' => $object_name,
                        'column' => $column,
                    ];
                }
            }

            if ($expected_type !== 'BASE TABLE') {
                continue;
            }

            $index_rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                    $object_name
                ),
                ARRAY_A
            );
            $indexes = $this->collect_result_values($index_rows, 'INDEX_NAME');

            if ($this->get_database_error() !== '') {
                $drift[] = [
                    'kind' => 'inspection_error',
                    'object' => $object_name,
                    'detail' => $this->sanitize_error($this->get_database_error()),
                ];
                continue;
            }

            foreach ((array) ($definition['indexes'] ?? []) as $index) {
                if (!in_array($index, $indexes, true)) {
                    $drift[] = [
                        'kind' => 'missing_index',
                        'object' => $object_name,
                        'index' => $index,
                    ];
                }
            }
        }

        return ['drift' => $drift];
    }

    private function inspect_seed_drift(array $contract_drift = []): array
    {
        global $wpdb;

        if (!isset($this->schema_contract['kiwi_device_model_brand_map'])
            || !is_object($wpdb)
            || !method_exists($wpdb, 'get_results')
            || !method_exists($wpdb, 'prepare')
        ) {
            return [];
        }

        $repository = new Kiwi_Device_Model_Brand_Map_Repository();
        $mappings = $repository->get_default_model_brand_mappings();
        $model_keys = array_values(array_filter(array_map(
            static function (array $mapping): string {
                return trim((string) ($mapping['model_key'] ?? ''));
            },
            $mappings
        )));

        if (empty($model_keys)) {
            return [];
        }

        $table_name = (string) $wpdb->prefix . 'kiwi_device_model_brand_map';
        foreach ($contract_drift as $drift) {
            if (($drift['object'] ?? '') !== $table_name) {
                continue;
            }

            $kind = (string) ($drift['kind'] ?? '');
            if (in_array($kind, ['missing_table', 'object_type_mismatch', 'inspection_error'], true)) {
                return [];
            }

            if ($kind === 'missing_column' && in_array(($drift['column'] ?? ''), ['model_key', 'brand'], true)) {
                return [];
            }
        }

        $placeholders = implode(', ', array_fill(0, count($model_keys), '%s'));
        $this->reset_database_error();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT model_key, brand FROM {$table_name} WHERE model_key IN ({$placeholders})",
                ...$model_keys
            ),
            ARRAY_A
        );

        if ($this->get_database_error() !== '') {
            return [[
                'kind' => 'inspection_error',
                'object' => $table_name,
                'detail' => $this->sanitize_error($this->get_database_error()),
            ]];
        }

        $actual = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $actual[(string) ($row['model_key'] ?? '')] = (string) ($row['brand'] ?? '');
        }

        $drift = [];
        foreach ($mappings as $mapping) {
            $model_key = (string) ($mapping['model_key'] ?? '');
            $brand = trim((string) ($actual[$model_key] ?? ''));

            if ($brand === '' || $brand === '(unknown)') {
                $drift[] = [
                    'kind' => 'missing_seed',
                    'object' => $table_name,
                    'model_key' => $model_key,
                ];
            }
        }

        return $drift;
    }

    private function collect_result_values($rows, string $key): array
    {
        $values = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || !array_key_exists($key, $row)) {
                continue;
            }

            $value = trim((string) $row[$key]);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    private function failure_result(string $phase, string $error_code, string $error_message): array
    {
        return [
            'success' => false,
            'mode' => 'apply',
            'ready' => false,
            'mutated' => $this->mutation_started,
            'target_version' => self::TARGET_SCHEMA_VERSION,
            'installed_version' => $this->get_installed_schema_version(),
            'phase' => $phase,
            'error_code' => $error_code,
            'error_message' => $this->sanitize_error($error_message),
            'drift' => [],
        ];
    }

    private function get_lock_name(): string
    {
        global $wpdb;

        $prefix = is_object($wpdb) ? (string) ($wpdb->prefix ?? '') : '';

        return self::LOCK_PREFIX . substr(hash('sha256', $prefix), 0, 20);
    }

    private function reset_database_error(): void
    {
        global $wpdb;

        if (is_object($wpdb) && property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
    }

    private function get_database_error(): string
    {
        global $wpdb;

        return is_object($wpdb) ? trim((string) ($wpdb->last_error ?? '')) : '';
    }

    private function sanitize_error(string $error): string
    {
        $error = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', trim($error));
        $error = is_string($error) ? $error : 'Database operation failed.';
        $error = preg_replace(
            '/\b(authorization|api[_-]?key|access[_-]?token|client[_-]?secret|password|secret|token)\s*[:=]\s*[^\s,;]+/i',
            '$1=[redacted]',
            $error
        );
        $error = is_string($error) ? $error : 'Database operation failed.';
        $error = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [redacted]', $error);
        $error = is_string($error) ? $error : 'Database operation failed.';
        $error = preg_replace(
            '/\b(msisdn|subscriber[_ -]?(?:reference|identifier|id))\s*[:=]\s*[^\s,;]+/i',
            '$1=[redacted]',
            $error
        );
        $error = is_string($error) ? $error : 'Database operation failed.';
        $error = preg_replace(
            '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?-----END [A-Z0-9 ]*PRIVATE KEY-----/is',
            '[private key redacted]',
            $error
        );
        $error = is_string($error) ? $error : 'Database operation failed.';
        $error = preg_replace('/(?<![A-Za-z0-9])\+?[0-9][0-9 .()\/-]{6,18}[0-9](?![A-Za-z0-9])/', '[subscriber identifier redacted]', $error);
        $error = is_string($error) ? $error : 'Database operation failed.';

        return substr($error !== '' ? $error : 'Database operation failed.', 0, 500);
    }

    private function build_schema_steps(): array
    {
        return [
            ['name' => 'dimoco_operator_lookup_callbacks', 'repository' => new Kiwi_Dimoco_Callback_Operator_Lookup_Repository(), 'objects' => ['kiwi_dimoco_operator_lookup_callbacks']],
            ['name' => 'dimoco_refund_callbacks', 'repository' => new Kiwi_Dimoco_Callback_Refund_Repository(), 'objects' => ['kiwi_dimoco_refund_callbacks']],
            ['name' => 'dimoco_blacklist_callbacks', 'repository' => new Kiwi_Dimoco_Callback_Blacklist_Repository(), 'objects' => ['kiwi_dimoco_blacklist_callbacks']],
            ['name' => 'device_model_brand_map', 'repository' => new Kiwi_Device_Model_Brand_Map_Repository(), 'objects' => ['kiwi_device_model_brand_map']],
            ['name' => 'landing_page_sessions', 'repository' => new Kiwi_Landing_Page_Session_Repository(), 'objects' => ['kiwi_landing_page_sessions']],
            ['name' => 'nth_events', 'repository' => new Kiwi_Nth_Event_Repository(), 'objects' => ['kiwi_nth_events']],
            ['name' => 'nth_flow_transactions', 'repository' => new Kiwi_Nth_Flow_Transaction_Repository(), 'objects' => ['kiwi_nth_flow_transactions']],
            ['name' => 'click_attributions', 'repository' => new Kiwi_Click_Attribution_Repository(), 'objects' => ['kiwi_click_attributions']],
            ['name' => 'sales', 'repository' => new Kiwi_Sales_Repository(), 'objects' => ['kiwi_sales']],
            ['name' => 'landing_kpi_summary', 'repository' => new Kiwi_Landing_Kpi_Summary_Repository(), 'objects' => ['kiwi_landing_kpi_summary']],
            ['name' => 'landing_handoff_events', 'repository' => new Kiwi_Landing_Handoff_Event_Repository(), 'objects' => ['kiwi_landing_handoff_events']],
            ['name' => 'sms_body_variants', 'repository' => new Kiwi_Sms_Body_Variant_Repository(), 'objects' => ['kiwi_sms_body_variant_assignments', 'kiwi_sms_body_variant_summary']],
            ['name' => 'premium_sms_landing_engagements', 'repository' => new Kiwi_Premium_Sms_Landing_Engagement_Repository(), 'objects' => ['kiwi_premium_sms_landing_engagements']],
            ['name' => 'premium_sms_fraud_signals', 'repository' => new Kiwi_Premium_Sms_Fraud_Signal_Repository(), 'objects' => ['kiwi_premium_sms_fraud_signals']],
            ['name' => 'operational_events', 'repository' => new Kiwi_Operational_Event_Repository(), 'objects' => ['kiwi_operational_events']],
            ['name' => 'retention_cleanup_runs', 'repository' => new Kiwi_Retention_Cleanup_Run_Repository(), 'objects' => ['kiwi_retention_cleanup_runs']],
            ['name' => 'retention_table_growth_snapshots', 'repository' => new Kiwi_Retention_Table_Growth_Snapshot_Repository(), 'objects' => ['kiwi_retention_table_growth_snapshots']],
            ['name' => 'landing_funnel_daily_summary', 'repository' => new Kiwi_Landing_Funnel_Daily_Summary_Repository(), 'objects' => ['kiwi_landing_funnel_daily_summary']],
            ['name' => 'landing_funnel_daily_tkzone_summary', 'repository' => new Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository(), 'objects' => ['kiwi_landing_funnel_daily_tkzone_summary']],
            ['name' => 'traffic_source_funnel_views', 'repository' => new Kiwi_Traffic_Source_Funnel_Statistics_Repository(), 'objects' => ['kiwi_v_load_to_cta_by_tksource_tkzone', 'kiwi_v_one_for_all']],
        ];
    }

    private function build_schema_contract(): array
    {
        $contract = require __DIR__ . '/schema-contract.php';

        return is_array($contract) ? $contract : [];
    }
}
