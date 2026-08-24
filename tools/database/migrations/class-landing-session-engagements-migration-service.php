<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * External one-time rename for the shared landing-session engagement table.
 *
 * This class is loaded only by database deployment tooling. It is never part of
 * the normal WordPress runtime.
 */
final class Kiwi_Landing_Session_Engagements_Migration_Service
{
    public const SOURCE_SCHEMA_VERSION = '2026-07-20-1';
    public const TARGET_SCHEMA_VERSION = '2026-07-23-1';
    public const SOURCE_TABLE_SUFFIX = 'kiwi_premium_sms_landing_engagements';

    private const LOCK_PREFIX = 'kiwi_backend_database_apply_';
    /**
     * The complete physical order of the confirmed 2026-07-20-1 predecessor.
     *
     * This narrow compatibility rule exists only because production's fully
     * verified predecessor was created before the canonical contract order was
     * established. It does not accept arbitrary reordered schemas.
     */
    private const HISTORICAL_SOURCE_COLUMN_ORDER = [
        'id', 'created_at', 'updated_at', 'provider_key', 'service_key', 'flow_key',
        'landing_key', 'session_token', 'page_loaded_at', 'first_cta_click_at', 'last_cta_click_at', 'cta_click_count',
        'last_event_at', 'pid', 'click_id', 'tksource', 'tkzone',
        'ua_ch_supported', 'ua_ch_mobile', 'ua_ch_platform', 'ua_ch_platform_version', 'ua_ch_model', 'ua_ch_brands', 'ua_ch_full_version_list', 'user_agent',
        'first_cta1_click_at', 'last_cta1_click_at', 'cta1_click_count',
        'first_cta2_click_at', 'last_cta2_click_at', 'cta2_click_count',
        'first_cta3_click_at', 'last_cta3_click_at', 'cta3_click_count',
        'is_soft_flag', 'soft_flag_reason', 'soft_flag_rule_key', 'soft_flag_evaluated_at',
    ];
    private const MANAGED_VIEW_SUFFIXES = [
        'kiwi_v_load_to_cta_by_tksource_tkzone',
        'kiwi_v_one_for_all',
    ];

    private $mutation_started = false;

    /**
     * Expose only the legacy blocker needed by the general database apply.
     */
    public function inspect_general_apply_blocker(): array
    {
        $source_table = $this->source_table_name();
        $target_table = $this->target_table_name();
        $source_type = $this->inspect_table_type($source_table);

        if ($this->get_database_error() !== '') {
            return [[
                'kind' => 'inspection_error',
                'object' => $source_table,
                'detail' => $this->sanitize_error($this->get_database_error()),
            ]];
        }

        $target_type = $this->inspect_table_type($target_table);
        if ($this->get_database_error() !== '') {
            return [[
                'kind' => 'inspection_error',
                'object' => $target_table,
                'detail' => $this->sanitize_error($this->get_database_error()),
            ]];
        }

        if ($source_type !== null) {
            return [[
                'kind' => 'legacy_table',
                'object' => $source_table,
                'required_migration' => 'landing-session-engagements',
            ]];
        }

        if ($target_type !== null && $this->get_installed_schema_version() === self::SOURCE_SCHEMA_VERSION) {
            return [[
                'kind' => 'legacy_table',
                'object' => $target_table,
                'detail' => 'The target table exists while the predecessor schema version is still installed.',
                'required_migration' => 'landing-session-engagements',
            ]];
        }

        return [];
    }

    public function check(): array
    {
        $this->mutation_started = false;

        return $this->inspect_state('check');
    }

    public function apply(): array
    {
        return $this->run_rename('apply');
    }

    public function rollback(): array
    {
        return $this->run_rename('rollback');
    }

    private function run_rename(string $mode): array
    {
        $this->mutation_started = false;

        if (!$this->acquire_lock()) {
            return $this->failure_result($mode, 'lock', 'lock_unavailable', 'Another external database operation is already running.');
        }

        try {
            $before_state = $this->inspect_state($mode);
            $expected_state = $mode === 'apply' ? 'pending' : 'applied';
            $no_op_state = $mode === 'apply' ? 'applied' : 'pending';

            if (!empty($before_state['success']) && ($before_state['state'] ?? '') === $no_op_state) {
                $before_state['mode'] = $mode;
                $before_state['mutated'] = false;
                $before_state['no_op'] = true;

                return $before_state;
            }

            if (empty($before_state['success']) || ($before_state['state'] ?? '') !== $expected_state) {
                $before_state['mode'] = $mode;
                $before_state['mutated'] = false;
                $before_state['no_op'] = false;

                return $before_state;
            }

            if (!$this->has_lock()) {
                return $this->failure_result($mode, 'preflight', 'lock_lost', 'The database deployment lock was lost before the rename.');
            }

            $source_table = $mode === 'apply' ? $this->source_table_name() : $this->target_table_name();
            $target_table = $mode === 'apply' ? $this->target_table_name() : $this->source_table_name();
            $before_snapshot = $this->inspect_table_snapshot($source_table);

            if (isset($before_snapshot['error_code'])) {
                return $this->failure_result(
                    $mode,
                    'snapshot_before',
                    (string) $before_snapshot['error_code'],
                    (string) ($before_snapshot['error_message'] ?? 'The pre-rename snapshot failed.')
                );
            }

            $this->reset_database_error();
            $this->mutation_started = true;
            $renamed = $this->query(
                'RENAME TABLE ' . $this->quote_identifier($source_table)
                . ' TO ' . $this->quote_identifier($target_table)
            );

            if ($renamed === false || $this->get_database_error() !== '') {
                return $this->failure_result(
                    $mode,
                    'rename',
                    'rename_failed',
                    $this->get_database_error() !== '' ? $this->get_database_error() : 'The atomic table rename failed.'
                );
            }

            if (!$this->has_lock()) {
                return $this->failure_result($mode, 'verify_lock', 'lock_lost', 'The database deployment lock was lost after the rename.');
            }

            $source_type = $this->inspect_table_type($source_table);
            if ($this->get_database_error() !== '') {
                return $this->failure_result(
                    $mode,
                    'verify_tables',
                    'inspection_failed',
                    $this->get_database_error()
                );
            }

            $target_type = $this->inspect_table_type($target_table);

            if ($this->get_database_error() !== '' || $source_type !== null || $target_type !== 'BASE TABLE') {
                return $this->failure_result(
                    $mode,
                    'verify_tables',
                    'rename_postcondition_failed',
                    'The renamed table did not satisfy the required object postconditions.'
                );
            }

            $after_snapshot = $this->inspect_table_snapshot($target_table);
            if (isset($after_snapshot['error_code'])) {
                return $this->failure_result(
                    $mode,
                    'snapshot_after',
                    (string) $after_snapshot['error_code'],
                    (string) ($after_snapshot['error_message'] ?? 'The post-rename snapshot failed.')
                );
            }

            if (!$this->snapshots_match($before_snapshot, $after_snapshot)) {
                return $this->failure_result(
                    $mode,
                    'verify_snapshot',
                    'snapshot_mismatch',
                    'Row identity, AUTO_INCREMENT, columns, or indexes changed during the rename.'
                );
            }

            $view_error = $this->rebuild_managed_views($target_table);
            if ($view_error !== '') {
                return $this->failure_result(
                    $mode,
                    'rebuild_views',
                    'managed_view_rebuild_failed',
                    $view_error
                );
            }

            if (!$this->has_lock()) {
                return $this->failure_result($mode, 'verify_views', 'lock_lost', 'The database deployment lock was lost while rebuilding managed views.');
            }

            $target_version = $mode === 'apply' ? self::TARGET_SCHEMA_VERSION : self::SOURCE_SCHEMA_VERSION;
            if (!$this->persist_schema_version($target_version)) {
                return $this->failure_result(
                    $mode,
                    'persist_version',
                    'schema_version_not_persisted',
                    'The schema version could not be confirmed after the rename.'
                );
            }

            $final = $this->inspect_state($mode);
            if (empty($final['success']) || ($final['state'] ?? '') !== $no_op_state) {
                return $this->failure_result(
                    $mode,
                    'verify_final',
                    'migration_postcondition_failed',
                    'The published migration state did not pass final verification.'
                );
            }

            $final['mutated'] = true;
            $final['no_op'] = false;

            return $final;
        } finally {
            $this->release_lock();
        }
    }

    private function inspect_state(string $mode): array
    {
        $source_table = $this->source_table_name();
        $target_table = $this->target_table_name();
        $source_type = $this->inspect_table_type($source_table);

        if ($this->get_database_error() !== '') {
            return $this->failure_result($mode, 'inspect', 'inspection_failed', $this->get_database_error(), 'inspection_error');
        }

        $target_type = $this->inspect_table_type($target_table);
        if ($this->get_database_error() !== '') {
            return $this->failure_result($mode, 'inspect', 'inspection_failed', $this->get_database_error(), 'inspection_error');
        }

        if ($source_type !== null && $target_type !== null) {
            return $this->failure_result($mode, 'inspect', 'table_conflict', 'Both migration table names exist.', 'conflict');
        }

        if ($source_type === null && $target_type === null) {
            return $this->failure_result($mode, 'inspect', 'table_missing', 'Neither migration table name exists.', 'missing');
        }

        if (($source_type !== null && $source_type !== 'BASE TABLE')
            || ($target_type !== null && $target_type !== 'BASE TABLE')
        ) {
            return $this->failure_result($mode, 'inspect', 'schema_mismatch', 'The migration object is not a base table.', 'schema_mismatch');
        }

        $state = $source_type === 'BASE TABLE' ? 'pending' : 'applied';
        $table_name = $state === 'pending' ? $source_table : $target_table;
        $expected_version = $state === 'pending' ? self::SOURCE_SCHEMA_VERSION : self::TARGET_SCHEMA_VERSION;
        $installed_version = $this->get_installed_schema_version();

        if ($installed_version !== $expected_version) {
            return $this->failure_result(
                $mode,
                'inspect',
                'version_mismatch',
                'The table name and installed schema version do not form a supported migration state.',
                'version_mismatch'
            );
        }

        $snapshot = $this->inspect_table_snapshot($table_name);
        if (isset($snapshot['error_code'])) {
            $state_on_error = ($snapshot['error_code'] ?? '') === 'inspection_failed' ? 'inspection_error' : 'schema_mismatch';

            return $this->failure_result(
                $mode,
                'inspect_schema',
                (string) $snapshot['error_code'],
                (string) ($snapshot['error_message'] ?? 'The table schema could not be verified.'),
                $state_on_error
            );
        }

        $schema_error = $this->validate_snapshot_contract($snapshot);
        if ($schema_error !== '') {
            return $this->failure_result($mode, 'inspect_schema', 'schema_mismatch', $schema_error, 'schema_mismatch');
        }

        $view_error = $this->validate_managed_views();
        if ($view_error !== '') {
            return $this->failure_result($mode, 'inspect_views', 'schema_mismatch', $view_error, 'schema_mismatch');
        }

        return [
            'success' => true,
            'mode' => $mode,
            'state' => $state,
            'mutated' => false,
            'no_op' => false,
            'source_version' => self::SOURCE_SCHEMA_VERSION,
            'target_version' => self::TARGET_SCHEMA_VERSION,
            'installed_version' => $installed_version,
            'table' => $this->table_basename($table_name),
            'snapshot' => $this->snapshot_for_output($snapshot),
        ];
    }

    private function inspect_table_snapshot(string $table_name): array
    {
        global $wpdb;

        if (!is_object($wpdb)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'get_results')
            || !method_exists($wpdb, 'get_row')
            || !method_exists($wpdb, 'get_var')
        ) {
            return $this->snapshot_error('inspection_failed', 'WordPress database access is unavailable.');
        }

        $this->reset_database_error();
        $columns = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
                $table_name
            ),
            ARRAY_A
        );
        if ($this->get_database_error() !== '') {
            return $this->snapshot_error('inspection_failed', $this->get_database_error());
        }

        $this->reset_database_error();
        $indexes = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY INDEX_NAME, SEQ_IN_INDEX',
                $table_name
            ),
            ARRAY_A
        );
        if ($this->get_database_error() !== '') {
            return $this->snapshot_error('inspection_failed', $this->get_database_error());
        }

        $this->reset_database_error();
        $row_stats = $wpdb->get_row(
            'SELECT COUNT(*) AS row_count, MIN(id) AS min_id, MAX(id) AS max_id FROM ' . $this->quote_identifier($table_name),
            ARRAY_A
        );
        if ($this->get_database_error() !== '' || !is_array($row_stats)) {
            return $this->snapshot_error('inspection_failed', $this->get_database_error() !== '' ? $this->get_database_error() : 'Row snapshot returned no result.');
        }

        $this->reset_database_error();
        $auto_increment = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table_name
            )
        );
        if ($this->get_database_error() !== '') {
            return $this->snapshot_error('inspection_failed', $this->get_database_error());
        }

        return [
            'row_count' => max(0, (int) ($row_stats['row_count'] ?? 0)),
            'min_id' => $row_stats['min_id'] === null ? null : (int) $row_stats['min_id'],
            'max_id' => $row_stats['max_id'] === null ? null : (int) $row_stats['max_id'],
            'auto_increment' => $auto_increment === null ? null : (int) $auto_increment,
            'columns' => $this->normalize_metadata_rows($columns, ['COLUMN_NAME', 'COLUMN_TYPE', 'IS_NULLABLE', 'COLUMN_DEFAULT', 'EXTRA', 'ORDINAL_POSITION']),
            'indexes' => $this->normalize_metadata_rows($indexes, ['INDEX_NAME', 'NON_UNIQUE', 'SEQ_IN_INDEX', 'COLUMN_NAME', 'SUB_PART', 'INDEX_TYPE']),
        ];
    }

    private function validate_snapshot_contract(array $snapshot): string
    {
        $contract = require dirname(__DIR__) . '/schema-contract.php';
        $definition = is_array($contract)
            ? ($contract[Kiwi_Database_Table_Names::LANDING_SESSION_ENGAGEMENTS] ?? null)
            : null;

        if (!is_array($definition)) {
            return 'The canonical landing-session engagement schema contract is unavailable.';
        }

        $actual_columns = array_map(static function (array $row): string {
            return (string) ($row['COLUMN_NAME'] ?? '');
        }, (array) ($snapshot['columns'] ?? []));
        $actual_indexes = array_values(array_unique(array_map(static function (array $row): string {
            return (string) ($row['INDEX_NAME'] ?? '');
        }, (array) ($snapshot['indexes'] ?? []))));
        $expected_columns = array_values((array) ($definition['columns'] ?? []));
        $expected_indexes = array_values((array) ($definition['indexes'] ?? []));
        $expected_column_metadata = (array) ($definition['column_metadata'] ?? []);
        $expected_index_metadata = (array) ($definition['index_metadata'] ?? []);

        if (!$this->is_supported_column_order($actual_columns, $expected_columns)) {
            return 'The landing-session engagement column contract does not match exactly.';
        }

        sort($actual_indexes, SORT_STRING);
        sort($expected_indexes, SORT_STRING);
        if ($actual_indexes !== $expected_indexes) {
            return 'The landing-session engagement index contract does not match exactly.';
        }

        if (empty($expected_column_metadata) || empty($expected_index_metadata)) {
            return 'The complete landing-session engagement metadata contract is unavailable.';
        }

        $actual_column_metadata = [];
        foreach ((array) ($snapshot['columns'] ?? []) as $row) {
            $name = (string) ($row['COLUMN_NAME'] ?? '');
            $actual_column_metadata[$name] = [
                'type' => $this->normalize_column_type((string) ($row['COLUMN_TYPE'] ?? '')),
                'nullable' => strtoupper((string) ($row['IS_NULLABLE'] ?? '')) === 'YES',
                'default' => $this->normalize_column_default($row['COLUMN_DEFAULT'] ?? null),
                'extra' => $this->normalize_column_extra((string) ($row['EXTRA'] ?? '')),
            ];
        }
        ksort($actual_column_metadata, SORT_STRING);

        $normalized_expected_columns = [];
        foreach ($expected_column_metadata as $name => $metadata) {
            $name = is_string($name) ? $name : '';
            $metadata = is_array($metadata) ? $metadata : [];
            $normalized_expected_columns[$name] = [
                'type' => $this->normalize_column_type((string) ($metadata['type'] ?? '')),
                'nullable' => !empty($metadata['nullable']),
                'default' => $this->normalize_column_default($metadata['default'] ?? null),
                'extra' => $this->normalize_column_extra((string) ($metadata['extra'] ?? '')),
            ];
        }
        ksort($normalized_expected_columns, SORT_STRING);

        if ($actual_column_metadata !== $normalized_expected_columns) {
            return 'The complete landing-session engagement column metadata does not match exactly.';
        }

        $actual_index_metadata = $this->normalize_index_metadata((array) ($snapshot['indexes'] ?? []));
        $normalized_expected_indexes = [];
        foreach ($expected_index_metadata as $name => $metadata) {
            $metadata = is_array($metadata) ? $metadata : [];
            $normalized_expected_indexes[(string) $name] = [
                'unique' => !empty($metadata['unique']),
                'columns' => array_values(array_map('strval', (array) ($metadata['columns'] ?? []))),
                'sub_parts' => array_values(array_map(static function ($value) {
                    return $value === null ? null : (int) $value;
                }, (array) ($metadata['sub_parts'] ?? []))),
                'type' => strtoupper(trim((string) ($metadata['type'] ?? ''))),
            ];
        }
        ksort($normalized_expected_indexes, SORT_STRING);

        if ($actual_index_metadata !== $normalized_expected_indexes) {
            return 'The complete landing-session engagement index metadata does not match exactly.';
        }

        if (($snapshot['auto_increment'] ?? null) === null || (int) $snapshot['auto_increment'] < 1) {
            return 'The landing-session engagement AUTO_INCREMENT value is unavailable.';
        }

        return '';
    }

    private function is_supported_column_order(array $actual_columns, array $canonical_columns): bool
    {
        return $actual_columns === $canonical_columns
            || $actual_columns === self::HISTORICAL_SOURCE_COLUMN_ORDER;
    }

    private function normalize_column_type(string $type): string
    {
        $type = strtolower(trim((string) preg_replace('/\s+/', ' ', $type)));
        $type = (string) preg_replace('/\b(bigint|int|integer|smallint|mediumint)\([0-9]+\)/', '$1', $type);
        $type = (string) preg_replace('/\bdatetime\(0\)/', 'datetime', $type);

        return trim((string) preg_replace('/\s+/', ' ', $type));
    }

    private function normalize_column_default($default): ?string
    {
        if ($default === null) {
            return null;
        }

        $default = trim((string) $default);
        if (strcasecmp($default, 'NULL') === 0) {
            return null;
        }

        if (strlen($default) >= 2 && $default[0] === "'" && substr($default, -1) === "'") {
            return str_replace("''", "'", substr($default, 1, -1));
        }

        return $default;
    }

    private function normalize_column_extra(string $extra): string
    {
        $tokens = preg_split('/\s+/', strtolower(trim($extra))) ?: [];
        $tokens = array_values(array_filter($tokens, static function (string $token): bool {
            return $token !== '' && $token !== 'default_generated';
        }));

        return implode(' ', $tokens);
    }

    private function normalize_index_metadata(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $name = (string) ($row['INDEX_NAME'] ?? '');
            $sequence = max(1, (int) ($row['SEQ_IN_INDEX'] ?? 0));
            if (!isset($normalized[$name])) {
                $normalized[$name] = [
                    'unique' => (string) ($row['NON_UNIQUE'] ?? '') === '0',
                    'columns' => [],
                    'sub_parts' => [],
                    'type' => strtoupper(trim((string) ($row['INDEX_TYPE'] ?? ''))),
                ];
            }

            $normalized[$name]['columns'][$sequence - 1] = (string) ($row['COLUMN_NAME'] ?? '');
            $sub_part = $row['SUB_PART'] ?? null;
            $normalized[$name]['sub_parts'][$sequence - 1] = $sub_part === null ? null : (int) $sub_part;
        }

        foreach ($normalized as &$metadata) {
            ksort($metadata['columns'], SORT_NUMERIC);
            ksort($metadata['sub_parts'], SORT_NUMERIC);
            $metadata['columns'] = array_values($metadata['columns']);
            $metadata['sub_parts'] = array_values($metadata['sub_parts']);
        }
        unset($metadata);
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function snapshots_match(array $before, array $after): bool
    {
        foreach (['row_count', 'min_id', 'max_id', 'auto_increment', 'columns', 'indexes'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function snapshot_for_output(array $snapshot): array
    {
        return [
            'row_count' => (int) ($snapshot['row_count'] ?? 0),
            'min_id' => $snapshot['min_id'] ?? null,
            'max_id' => $snapshot['max_id'] ?? null,
            'auto_increment' => $snapshot['auto_increment'] ?? null,
            'column_count' => count((array) ($snapshot['columns'] ?? [])),
            'index_count' => count(array_unique(array_map(static function (array $row): string {
                return (string) ($row['INDEX_NAME'] ?? '');
            }, (array) ($snapshot['indexes'] ?? [])))),
            'columns_hash' => hash('sha256', serialize($snapshot['columns'] ?? [])),
            'indexes_hash' => hash('sha256', serialize($snapshot['indexes'] ?? [])),
        ];
    }

    private function normalize_metadata_rows($rows, array $keys): array
    {
        $normalized = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $entry = [];
            foreach ($keys as $key) {
                $entry[$key] = array_key_exists($key, $row) ? $row[$key] : null;
            }
            $normalized[] = $entry;
        }

        return $normalized;
    }

    private function rebuild_managed_views(string $engagement_table): string
    {
        if (!class_exists('Kiwi_Traffic_Source_Funnel_Statistics_Repository')) {
            return 'The managed analytics view repository is unavailable.';
        }

        try {
            $repository = new Kiwi_Traffic_Source_Funnel_Statistics_Repository($engagement_table);
        } catch (Throwable $error) {
            return 'The managed analytics view repository could not be initialized.';
        }

        if (!$repository->create_view()) {
            $detail = trim((string) $repository->get_last_error());

            return $detail !== '' ? $detail : 'The managed analytics views could not be rebuilt.';
        }

        return $this->validate_managed_views();
    }

    private function validate_managed_views(): string
    {
        global $wpdb;

        $prefix = is_object($wpdb) ? (string) ($wpdb->prefix ?? '') : '';
        foreach (self::MANAGED_VIEW_SUFFIXES as $suffix) {
            $view_name = $prefix . $suffix;
            $type = $this->inspect_table_type($view_name);
            if ($this->get_database_error() !== '') {
                return $this->get_database_error();
            }
            if ($type !== 'VIEW') {
                return 'A required managed analytics view is missing or has the wrong object type.';
            }

            $this->reset_database_error();
            $this->query('SELECT 1 FROM ' . $this->quote_identifier($view_name) . ' LIMIT 0');
            if ($this->get_database_error() !== '') {
                return $this->get_database_error();
            }
        }

        return '';
    }

    private function inspect_table_type(string $table_name): ?string
    {
        global $wpdb;

        $this->reset_database_error();
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            if (is_object($wpdb) && property_exists($wpdb, 'last_error')) {
                $wpdb->last_error = 'WordPress database access is unavailable.';
            }

            return null;
        }

        $type = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table_name
            )
        );

        if ($type === null || $type === false || $type === '') {
            return null;
        }

        return strtoupper((string) $type);
    }

    private function acquire_lock(): bool
    {
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return false;
        }

        return (string) $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, 0)', $this->lock_name())
        ) === '1';
    }

    private function has_lock(): bool
    {
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return false;
        }

        return (string) $wpdb->get_var(
            $wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->lock_name())
        ) === '1';
    }

    private function release_lock(): void
    {
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return;
        }

        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->lock_name()));
    }

    private function lock_name(): string
    {
        global $wpdb;

        $prefix = is_object($wpdb) ? (string) ($wpdb->prefix ?? '') : '';

        return self::LOCK_PREFIX . substr(hash('sha256', $prefix), 0, 20);
    }

    private function persist_schema_version(string $version): bool
    {
        if (!function_exists('update_option')) {
            return false;
        }

        update_option(Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION, $version, true);

        return $this->get_installed_schema_version() === $version;
    }

    private function get_installed_schema_version(): string
    {
        if (!function_exists('get_option')) {
            return '';
        }

        $version = get_option(Kiwi_Database_Deployment_Service::SCHEMA_VERSION_OPTION, '');

        return is_string($version) ? $version : '';
    }

    private function failure_result(
        string $mode,
        string $phase,
        string $error_code,
        string $error_message,
        string $state = 'error'
    ): array {
        return [
            'success' => false,
            'mode' => $mode,
            'state' => $state,
            'mutated' => $this->mutation_started,
            'no_op' => false,
            'source_version' => self::SOURCE_SCHEMA_VERSION,
            'target_version' => self::TARGET_SCHEMA_VERSION,
            'installed_version' => $this->get_installed_schema_version(),
            'phase' => $phase,
            'error_code' => $error_code,
            'error_message' => $this->sanitize_error($error_message),
        ];
    }

    private function snapshot_error(string $error_code, string $error_message): array
    {
        return [
            'error_code' => $error_code,
            'error_message' => $this->sanitize_error($error_message),
        ];
    }

    private function query(string $sql)
    {
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'query')) {
            return false;
        }

        return $wpdb->query($sql);
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

    private function source_table_name(): string
    {
        global $wpdb;

        return (string) $wpdb->prefix . self::SOURCE_TABLE_SUFFIX;
    }

    private function target_table_name(): string
    {
        return Kiwi_Database_Table_Names::landing_session_engagements();
    }

    private function table_basename(string $table_name): string
    {
        global $wpdb;

        $prefix = is_object($wpdb) ? (string) ($wpdb->prefix ?? '') : '';

        return $prefix !== '' && strpos($table_name, $prefix) === 0
            ? substr($table_name, strlen($prefix))
            : $table_name;
    }

    private function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function sanitize_error(string $error): string
    {
        $error = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', trim($error));
        $error = is_string($error) ? $error : 'Database migration failed.';
        $error = preg_replace(
            '/\b(authorization|api[_-]?key|access[_-]?token|client[_-]?secret|password|secret|token)\s*[:=]\s*[^\s,;]+/i',
            '$1=[redacted]',
            $error
        );
        $error = is_string($error) ? $error : 'Database migration failed.';
        $error = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [redacted]', $error);
        $error = is_string($error) ? $error : 'Database migration failed.';
        $error = preg_replace(
            '/\b(msisdn|subscriber[_ -]?(?:reference|identifier|id))\s*[:=]\s*[^\s,;]+/i',
            '$1=[redacted]',
            $error
        );
        $error = is_string($error) ? $error : 'Database migration failed.';
        $error = preg_replace('/(?<![A-Za-z0-9])\+?[0-9][0-9 .()\/-]{6,18}[0-9](?![A-Za-z0-9])/', '[subscriber identifier redacted]', $error);
        $error = is_string($error) ? $error : 'Database migration failed.';

        return substr($error !== '' ? $error : 'Database migration failed.', 0, 500);
    }
}
