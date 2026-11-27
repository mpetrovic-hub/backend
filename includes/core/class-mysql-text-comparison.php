<?php

if (!defined('ABSPATH')) { exit; }

/** MySQL comparison weights for text values read from other stores, using the installation's actual column definitions. */
final class Kiwi_Mysql_Text_Comparison
{
    private static $connection;
    private static $instances = [];
    private $definitions;
    private $fingerprint;
    private $keys = [];

    public static function for_columns(string $table, array $columns, bool $refresh = false, bool $require_unique = false): self
    {
        global $wpdb;
        if (self::$connection !== $wpdb) {
            self::$connection = $wpdb;
            self::$instances = [];
        }
        $slot = json_encode([$table, $columns, $require_unique], JSON_THROW_ON_ERROR);
        $old = self::$instances[$slot] ?? null;
        if ($old && !$refresh) { return $old; }
        unset(self::$instances[$slot]);
        $current = new self($table, $columns, $require_unique);
        return self::$instances[$slot] = $old && $old->fingerprint === $current->fingerprint ? $old : $current;
    }

    private function __construct(string $table, array $columns, bool $require_unique)
    {
        global $wpdb;
        if (!$columns || count(array_unique($columns)) !== count($columns)) { throw new RuntimeException('mysql_comparison_columns_invalid'); }
        foreach ($columns as $column) { self::identifier($column); }
        $params = implode(',', array_fill(0, count($columns), '%s'));
        $sql = "SELECT COLUMN_NAME AS field_name, DATA_TYPE AS field_type, CHARACTER_SET_NAME AS charset_name,
                COLLATION_NAME AS collation_name, CHARACTER_MAXIMUM_LENGTH AS max_length, IS_NULLABLE AS nullable,
                DATABASE() AS database_name, VERSION() AS engine_version
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME IN ({$params})";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $table, ...$columns), ARRAY_A);
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '' || count($rows) !== count($columns)) {
            throw new RuntimeException('mysql_comparison_metadata_unavailable');
        }
        $rows = array_column($rows, null, 'field_name');
        $definitions = [];
        $padding = [];
        foreach ($columns as $column) {
            $r = $rows[$column] ?? [];
            if (!in_array($r['field_type'] ?? '', ['char','varchar'], true) || (int) ($r['max_length'] ?? 0) < 1
                || ($require_unique && ($r['nullable'] ?? '') !== 'NO')) {
                throw new RuntimeException('mysql_comparison_column_definition_invalid');
            }
            $charset = self::identifier($r['charset_name']);
            $collation = self::identifier($r['collation_name']);
            $definitions[$column] = ['charset' => $charset, 'collation' => $collation, 'length' => (int) $r['max_length']];
            // MariaDB and MySQL both implement padded and non-padded collations. Ask the server which applies.
            $padding[$collation] = "(CONVERT('a' USING {$charset}) COLLATE {$collation} = CONVERT('a ' USING {$charset}) COLLATE {$collation}) AS `{$collation}`";
        }
        $pads = $wpdb->get_row('SELECT ' . implode(',', $padding), ARRAY_A);
        if (!is_array($pads) || (string) ($wpdb->last_error ?? '') !== '') { throw new RuntimeException('mysql_comparison_padding_unavailable'); }
        foreach ($definitions as &$definition) {
            $pad = (string) ($pads[$definition['collation']] ?? '');
            if ($pad !== '0' && $pad !== '1') { throw new RuntimeException('mysql_comparison_padding_unavailable'); }
            $definition['pad'] = $pad === '1';
        }
        unset($definition);
        if ($require_unique) { $this->verify_unique_key($table, $columns); }
        $this->definitions = $definitions;
        $meta = reset($rows);
        $this->fingerprint = hash('sha256', json_encode([$meta['database_name'], $meta['engine_version'], $table, $definitions], JSON_THROW_ON_ERROR));
    }

    private function verify_unique_key(string $table, array $columns): void
    {
        global $wpdb;
        $parts = $wpdb->get_results($wpdb->prepare('SELECT INDEX_NAME AS index_name, COLUMN_NAME AS field_name,
                SUB_PART AS prefix_length FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND NON_UNIQUE=0 ORDER BY INDEX_NAME,SEQ_IN_INDEX', $table), ARRAY_A);
        if (!is_array($parts) || (string) ($wpdb->last_error ?? '') !== '') { throw new RuntimeException('mysql_comparison_unique_key_unavailable'); }
        $indexes = [];
        foreach ($parts as $part) { $indexes[$part['index_name']][] = $part['prefix_length'] === null ? $part['field_name'] : null; }
        sort($columns);
        foreach ($indexes as $fields) {
            sort($fields);
            if ($fields === $columns) { return; }
        }
        throw new RuntimeException('mysql_comparison_unique_key_missing');
    }

    public function fingerprint(): string { return $this->fingerprint; }

    /** Use the target column's comparison when another table stores the same logical dimension differently. */
    public function column_sql(string $column, string $qualified_column, ?self $source = null): string
    {
        $parts = explode('.', $qualified_column);
        if (count($parts) > 2 || end($parts) !== $column || !isset($this->definitions[$column])) {
            throw new RuntimeException('mysql_comparison_column_invalid');
        }
        $field = implode('.', array_map(static fn ($name) => '`' . self::identifier($name) . '`', $parts));
        $d = $this->definitions[$column];
        if ($source !== null && ($source->definitions[$column]['collation'] ?? '') === $d['collation']) { return $field; }
        $converted = "CONVERT({$field} USING {$d['charset']}) COLLATE {$d['collation']}";
        if ($source !== null && $source->definitions[$column]['charset'] !== $d['charset']) {
            // An unrepresentable source spelling cannot be silently folded onto a different group.
            return "IF(BINARY CONVERT({$converted} USING utf8mb4)=BINARY CONVERT({$field} USING utf8mb4), {$converted}, NULL)";
        }
        return $converted;
    }

    public function key(array $row): string
    {
        global $wpdb;
        $values = [];
        foreach ($this->definitions as $column => $definition) {
            if (!is_string($row[$column] ?? null)) { throw new RuntimeException('mysql_comparison_value_invalid'); }
            $values[$column] = $row[$column];
        }
        $raw = json_encode($values, JSON_THROW_ON_ERROR);
        if (isset($this->keys[$raw])) { return $this->keys[$raw]; }
        $inputs = $weights = $checks = [];
        foreach ($this->definitions as $column => $d) {
            $inputs[] = "%s AS `{$column}`";
            $value = $this->column_sql($column, 'input.' . $column);
            $pad = $d['pad'] ? ' AS CHAR(' . $d['length'] . ')' : '';
            $weights[] = "SHA2(WEIGHT_STRING({$value}{$pad}),256) AS `{$column}`";
            $checks[] = "CHAR_LENGTH({$value})<=" . $d['length'];
            $checks[] = "BINARY CONVERT({$value} USING utf8mb4)=BINARY CONVERT(input.`{$column}` USING utf8mb4)";
        }
        $sql = 'SELECT ' . implode(',', $weights) . ' FROM (SELECT ' . implode(',', $inputs) . ') input WHERE ' . implode(' AND ', $checks);
        $result = $wpdb->get_row($wpdb->prepare($sql, ...array_values($values)), ARRAY_A);
        if (!is_array($result) || (string) ($wpdb->last_error ?? '') !== '' || array_keys($result) !== array_keys($values)) {
            throw new RuntimeException('mysql_comparison_weight_unavailable');
        }
        foreach ($result as $digest) {
            if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/iD', $digest)) { throw new RuntimeException('mysql_comparison_weight_unavailable'); }
        }
        if (count($this->keys) >= 4096) { $this->keys = []; }
        return $this->keys[$raw] = hash('sha256', implode('', $result));
    }

    private static function identifier($name): string
    {
        if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]+$/D', $name)) { throw new RuntimeException('mysql_comparison_identifier_invalid'); }
        return $name;
    }
}
