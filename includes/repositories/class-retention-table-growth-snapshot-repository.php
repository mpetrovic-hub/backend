<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Retention_Table_Growth_Snapshot_Repository
{
    public function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_retention_table_growth_snapshots';
    }

    public function create_table(): void
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            snapshot_at DATETIME NOT NULL,
            snapshot_date DATE NOT NULL,
            snapshot_phase VARCHAR(30) NOT NULL DEFAULT '',
            source_key VARCHAR(100) NOT NULL DEFAULT '',
            source_table VARCHAR(191) NOT NULL DEFAULT '',
            retention_days_effective INT UNSIGNED NOT NULL DEFAULT 0,
            cutoff_column VARCHAR(64) NOT NULL DEFAULT '',
            cutoff_value DATETIME NULL,
            row_count_total BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            data_size_bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            index_size_bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            total_size_bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            min_cutoff_value DATETIME NULL,
            max_cutoff_value DATETIME NULL,
            eligible_rows_at_cutoff BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            archived_rows_last_run BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            deleted_rows_last_run BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            cleanup_run_id VARCHAR(100) NOT NULL DEFAULT '',
            archive_batch_id VARCHAR(100) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY snapshot_source_date (source_key, snapshot_date),
            KEY cleanup_run_id (cleanup_run_id),
            KEY archive_batch_id (archive_batch_id)
        ) {$charset_collate};";

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta($sql);
    }

    public function capture_snapshot(
        array $source,
        string $snapshot_phase,
        int $retention_days,
        string $cutoff_value,
        int $eligible_rows,
        string $cleanup_run_id,
        string $archive_batch_id = '',
        int $archived_rows = 0,
        int $deleted_rows = 0
    ): int {
        global $wpdb;

        $source_table = (string) ($source['source_table'] ?? '');
        $cutoff_column = (string) ($source['cutoff_column'] ?? '');
        $sizes = $this->get_table_sizes($source_table);
        $min_max = $this->get_cutoff_min_max($source_table, $cutoff_column);
        $snapshot_at = $this->current_time_mysql();
        $snapshot_date = substr($snapshot_at, 0, 10);
        $row = [
            'snapshot_at' => $snapshot_at,
            'snapshot_date' => $snapshot_date,
            'snapshot_phase' => $snapshot_phase,
            'source_key' => (string) ($source['source_key'] ?? ''),
            'source_table' => $source_table,
            'retention_days_effective' => $retention_days,
            'cutoff_column' => $cutoff_column,
            'cutoff_value' => $cutoff_value,
            'row_count_total' => $this->count_total_rows($source_table),
            'data_size_bytes' => (int) ($sizes['data_size_bytes'] ?? 0),
            'index_size_bytes' => (int) ($sizes['index_size_bytes'] ?? 0),
            'total_size_bytes' => (int) ($sizes['total_size_bytes'] ?? 0),
            'min_cutoff_value' => (string) ($min_max['min_cutoff_value'] ?? ''),
            'max_cutoff_value' => (string) ($min_max['max_cutoff_value'] ?? ''),
            'eligible_rows_at_cutoff' => $eligible_rows,
            'archived_rows_last_run' => $archived_rows,
            'deleted_rows_last_run' => $deleted_rows,
            'cleanup_run_id' => $cleanup_run_id,
            'archive_batch_id' => $archive_batch_id,
            'created_at' => $snapshot_at,
        ];

        $result = $wpdb->insert(
            $this->get_table_name(),
            $row,
            [
                '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d',
                '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s',
            ]
        );

        if ($result === false) {
            return 0;
        }

        return (int) ($wpdb->insert_id ?? 0);
    }

    protected function count_total_rows(string $source_table): int
    {
        global $wpdb;

        if (!$this->is_identifier($source_table)) {
            return 0;
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$source_table}");
    }

    protected function get_cutoff_min_max(string $source_table, string $cutoff_column): array
    {
        global $wpdb;

        if (!$this->is_identifier($source_table) || !$this->is_identifier($cutoff_column)) {
            return [];
        }

        $row = $wpdb->get_row(
            "SELECT MIN({$cutoff_column}) AS min_cutoff_value, MAX({$cutoff_column}) AS max_cutoff_value FROM {$source_table}",
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    protected function get_table_sizes(string $source_table): array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COALESCE(data_length, 0) AS data_size_bytes,
                    COALESCE(index_length, 0) AS index_size_bytes,
                    COALESCE(data_length + index_length, 0) AS total_size_bytes
                 FROM information_schema.TABLES
                 WHERE table_schema = DATABASE()
                   AND table_name = %s
                 LIMIT 1",
                $source_table
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    private function is_identifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1;
    }

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
