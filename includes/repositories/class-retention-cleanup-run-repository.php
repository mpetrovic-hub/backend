<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Retention_Cleanup_Run_Repository
{
    public function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_retention_cleanup_runs';
    }

    public function create_table(): void
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id VARCHAR(100) NOT NULL DEFAULT '',
            source_key VARCHAR(100) NOT NULL DEFAULT '',
            source_table VARCHAR(191) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'skipped',
            triggered_by VARCHAR(30) NOT NULL DEFAULT 'cron',
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            dry_run TINYINT(1) NOT NULL DEFAULT 1,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            retention_days_effective INT UNSIGNED NOT NULL DEFAULT 0,
            cutoff_column VARCHAR(64) NOT NULL DEFAULT '',
            cutoff_value DATETIME NULL,
            eligible_rows BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            archived_rows BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            archive_inserted_rows BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            archive_duplicate_rows BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            deleted_rows BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            delete_batches INT UNSIGNED NOT NULL DEFAULT 0,
            gate_status VARCHAR(20) NOT NULL DEFAULT 'skipped',
            gate_results_json LONGTEXT NULL,
            worker_phase VARCHAR(30) NOT NULL DEFAULT '',
            target_max_primary_key BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            archive_last_primary_key BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            delete_last_primary_key BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            worker_runs INT UNSIGNED NOT NULL DEFAULT 0,
            worker_last_started_at DATETIME NULL,
            worker_last_finished_at DATETIME NULL,
            archive_batch_id VARCHAR(100) NOT NULL DEFAULT '',
            archive_db_path TEXT NULL,
            archive_integrity_check VARCHAR(100) NOT NULL DEFAULT '',
            error_code VARCHAR(100) NOT NULL DEFAULT '',
            error_message TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY run_id (run_id),
            KEY source_key_started (source_key, started_at),
            KEY status_started (status, started_at),
            KEY source_status_started (source_key, status, started_at),
            KEY archive_batch_id (archive_batch_id)
        ) {$charset_collate};";

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta($sql);
    }

    public function create_run(array $data): int
    {
        global $wpdb;

        $now = $this->current_time_mysql();
        $row = $this->normalize_row(array_merge([
            'status' => 'skipped',
            'triggered_by' => 'cron',
            'enabled' => 0,
            'dry_run' => 1,
            'started_at' => $now,
            'finished_at' => null,
            'eligible_rows' => 0,
            'archived_rows' => 0,
            'archive_inserted_rows' => 0,
            'archive_duplicate_rows' => 0,
            'deleted_rows' => 0,
            'delete_batches' => 0,
            'gate_status' => 'skipped',
            'created_at' => $now,
            'updated_at' => $now,
        ], $data));

        $result = $wpdb->insert(
            $this->get_table_name(),
            $row,
            $this->formats_for(array_keys($row))
        );

        if ($result === false) {
            return 0;
        }

        return (int) ($wpdb->insert_id ?? 0);
    }

    public function update_run(int $id, array $data): bool
    {
        if ($id <= 0) {
            return false;
        }

        global $wpdb;

        $row = $this->normalize_row(array_merge($data, [
            'updated_at' => $this->current_time_mysql(),
        ]));

        if (empty($row)) {
            return true;
        }

        $result = $wpdb->update(
            $this->get_table_name(),
            $row,
            ['id' => $id],
            $this->formats_for(array_keys($row)),
            ['%d']
        );

        return $result !== false;
    }

    public function find_open_run_for_source(string $source_key): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$this->get_table_name()}
                 WHERE source_key = %s
                   AND status IN ('pending', 'running', 'partial')
                 ORDER BY started_at ASC, id ASC
                 LIMIT 1",
                $source_key
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Marks unfinished runs as failed when their audit heartbeat has stopped.
     *
     * Returns null when the audit update itself could not be executed.
     */
    public function mark_stale_unfinished_runs(string $source_key, int $stale_after_minutes = 30): ?int
    {
        global $wpdb;

        $stale_after_minutes = max(1, $stale_after_minutes);
        $now = $this->current_time_mysql();

        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->get_table_name()}
                 SET status = 'failed',
                     worker_phase = CASE
                         WHEN worker_phase IS NULL OR worker_phase = '' THEN 'stale_unknown'
                         ELSE worker_phase
                     END,
                     error_code = 'cron_timeout_suspected',
                     error_message = 'Retention cleanup run was marked failed because its heartbeat became stale.',
                     finished_at = %s,
                     updated_at = %s
                 WHERE source_key = %s
                   AND finished_at IS NULL
                   AND status IN ('skipped', 'running', 'partial')
                   AND updated_at < DATE_SUB(%s, INTERVAL {$stale_after_minutes} MINUTE)",
                $now,
                $now,
                $source_key,
                $now
            )
        );

        return $result === false ? null : (int) $result;
    }

    private function normalize_row(array $data): array
    {
        $allowed = [
            'run_id' => 'string',
            'source_key' => 'string',
            'source_table' => 'string',
            'status' => 'string',
            'triggered_by' => 'string',
            'enabled' => 'bool',
            'dry_run' => 'bool',
            'started_at' => 'datetime',
            'finished_at' => 'datetime_nullable',
            'retention_days_effective' => 'int',
            'cutoff_column' => 'string',
            'cutoff_value' => 'datetime_nullable',
            'eligible_rows' => 'int',
            'archived_rows' => 'int',
            'archive_inserted_rows' => 'int',
            'archive_duplicate_rows' => 'int',
            'deleted_rows' => 'int',
            'delete_batches' => 'int',
            'gate_status' => 'string',
            'gate_results_json' => 'json_text',
            'worker_phase' => 'string',
            'target_max_primary_key' => 'int',
            'archive_last_primary_key' => 'int',
            'delete_last_primary_key' => 'int',
            'worker_runs' => 'int',
            'worker_last_started_at' => 'datetime_nullable',
            'worker_last_finished_at' => 'datetime_nullable',
            'archive_batch_id' => 'string',
            'archive_db_path' => 'text',
            'archive_integrity_check' => 'string',
            'error_code' => 'string',
            'error_message' => 'text',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
        $row = [];

        foreach ($allowed as $key => $type) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if ($type === 'bool') {
                $row[$key] = !empty($value) ? 1 : 0;
                continue;
            }

            if ($type === 'int') {
                $row[$key] = max(0, (int) $value);
                continue;
            }

            if ($type === 'json_text' && is_array($value)) {
                $row[$key] = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);
                continue;
            }

            if ($type === 'datetime_nullable' && ($value === null || $value === '')) {
                $row[$key] = null;
                continue;
            }

            $row[$key] = is_string($value) || is_numeric($value) ? (string) $value : '';
        }

        return $row;
    }

    private function formats_for(array $keys): array
    {
        $int_fields = [
            'enabled',
            'dry_run',
            'retention_days_effective',
            'eligible_rows',
            'archived_rows',
            'archive_inserted_rows',
            'archive_duplicate_rows',
            'deleted_rows',
            'delete_batches',
            'target_max_primary_key',
            'archive_last_primary_key',
            'delete_last_primary_key',
            'worker_runs',
        ];

        return array_map(static function (string $key) use ($int_fields): string {
            return in_array($key, $int_fields, true) ? '%d' : '%s';
        }, $keys);
    }

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
