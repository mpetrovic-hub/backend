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
     * Returns null on lookup failure, an empty array when no run is actively
     * writing, or the oldest open run's frozen archive state.
     */
    public function find_open_archive_state(): ?array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT archive_db_path,
                    archived_rows,
                    deleted_rows,
                    archive_last_primary_key,
                    delete_last_primary_key
             FROM {$this->get_table_name()}
             WHERE status IN ('pending', 'running', 'partial')
               AND finished_at IS NULL
               AND archive_db_path IS NOT NULL
               AND archive_db_path <> ''
             ORDER BY started_at ASC, id ASC
             LIMIT 1",
            ARRAY_A
        );
        if (!is_array($rows) || trim((string) ($wpdb->last_error ?? '')) !== '') {
            return null;
        }
        if (empty($rows)) {
            return [];
        }

        return [
            'archive_db_path' => trim((string) ($rows[0]['archive_db_path'] ?? '')),
            'archived_rows' => max(0, (int) ($rows[0]['archived_rows'] ?? 0)),
            'deleted_rows' => max(0, (int) ($rows[0]['deleted_rows'] ?? 0)),
            'archive_last_primary_key' => max(0, (int) ($rows[0]['archive_last_primary_key'] ?? 0)),
            'delete_last_primary_key' => max(0, (int) ($rows[0]['delete_last_primary_key'] ?? 0)),
        ];
    }

    /**
     * Marks unfinished runs as failed when their audit heartbeat has stopped.
     *
     * Returns the rows that this call actually transitioned, or null when the
     * audit read/update could not be executed.
     */
    public function mark_stale_unfinished_runs(string $source_key, int $stale_after_minutes = 30): ?array
    {
        global $wpdb;

        $stale_after_minutes = max(1, $stale_after_minutes);
        $now = $this->current_time_mysql();
        $resumable_worker_phases = [
            'active_run_rescheduled',
            'archive_pending',
            'archive_running',
            'receipt_repair_running',
            'receipt_verified',
            'delete_running',
            'archive_partial',
            'snapshot_after_running',
            'finalizing',
            'lock_skipped',
        ];
        $resumable_placeholders = implode(', ', array_fill(0, count($resumable_worker_phases), '%s'));
        $candidates = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, run_id, source_key, worker_phase, updated_at
                 FROM {$this->get_table_name()}
                 WHERE source_key = %s
                   AND finished_at IS NULL
                   AND status IN ('skipped', 'running')
                   AND (
                       status = 'skipped'
                       OR worker_phase NOT IN ({$resumable_placeholders})
                   )
                   AND updated_at < DATE_SUB(%s, INTERVAL {$stale_after_minutes} MINUTE)",
                ...array_merge(
                    [$source_key],
                    $resumable_worker_phases,
                    [$now]
                )
            ),
            ARRAY_A
        );

        if (!is_array($candidates)) {
            return null;
        }

        $marked = [];
        foreach ($candidates as $candidate) {
            $id = (int) ($candidate['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

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
                     WHERE id = %d
                       AND source_key = %s
                       AND finished_at IS NULL
                       AND status IN ('skipped', 'running')
                       AND (
                           status = 'skipped'
                           OR worker_phase NOT IN ({$resumable_placeholders})
                       )
                       AND updated_at < DATE_SUB(%s, INTERVAL {$stale_after_minutes} MINUTE)",
                    ...array_merge(
                        [$now, $now, $id, $source_key],
                        $resumable_worker_phases,
                        [$now]
                    )
                )
            );

            if ($result === false) {
                return null;
            }

            if ((int) $result === 1) {
                $candidate['worker_phase'] = (string) ($candidate['worker_phase'] ?? '') !== ''
                    ? (string) $candidate['worker_phase']
                    : 'stale_unknown';
                $candidate['error_code'] = 'cron_timeout_suspected';
                $candidate['finished_at'] = $now;
                $marked[] = $candidate;
            }
        }

        return $marked;
    }

    /**
     * Atomically closes a run whose archive generation was quarantined and
     * creates (or returns) its deterministic successor for the remaining
     * source rows. No new persistence schema is needed for the transition.
     */
    public function create_quarantine_successor(
        int $run_db_id,
        string $new_archive_db_path,
        int $remaining_rows,
        array $transition_context
    ): ?array {
        if ($run_db_id <= 0 || $new_archive_db_path === '') {
            return null;
        }

        global $wpdb;

        $table_name = $this->get_table_name();
        $now = $this->current_time_mysql();
        $transaction_started = $wpdb->query('START TRANSACTION');
        if ($transaction_started === false) {
            return null;
        }

        try {
            $current = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT *
                     FROM {$table_name}
                     WHERE id = %d
                     FOR UPDATE",
                    $run_db_id
                ),
                ARRAY_A
            );
            if (!is_array($current)) {
                throw new RuntimeException('Quarantined retention run could not be locked.');
            }

            $old_run_id = (string) ($current['run_id'] ?? '');
            $old_archive = basename((string) ($current['archive_db_path'] ?? ''));
            $new_archive = basename($new_archive_db_path);
            if ($old_run_id === ''
                || $old_archive === ''
                || $new_archive === ''
                || $old_archive === $new_archive
                || (string) ($transition_context['old_run_id'] ?? '') !== $old_run_id
                || (string) ($transition_context['old_archive'] ?? '') !== $old_archive
                || (string) ($transition_context['new_archive'] ?? '') !== $new_archive
                || (int) ($transition_context['remaining_rows'] ?? -1) !== max(0, $remaining_rows)
                || ((string) ($current['status'] ?? '') === 'failed'
                    && (string) ($current['worker_phase'] ?? '') !== 'archive_quarantined')
            ) {
                throw new RuntimeException('Quarantined retention run transition no longer matches its frozen scope.');
            }
            $successor_run_id = 'retention_recovery_' . substr(
                hash('sha256', $old_run_id . ':' . $new_archive),
                0,
                40
            );
            $successor_batch_id = 'archive_recovery_' . substr(
                hash('sha256', (string) ($current['archive_batch_id'] ?? '') . ':' . $new_archive),
                0,
                40
            );
            $successor = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT *
                     FROM {$table_name}
                     WHERE run_id = %s
                     LIMIT 1",
                    $successor_run_id
                ),
                ARRAY_A
            );

            if (!is_array($successor)) {
                $context_json = function_exists('wp_json_encode')
                    ? wp_json_encode($transition_context)
                    : json_encode($transition_context);
                $row = $this->normalize_row([
                    'run_id' => $successor_run_id,
                    'source_key' => (string) ($current['source_key'] ?? ''),
                    'source_table' => (string) ($current['source_table'] ?? ''),
                    'status' => 'pending',
                    'triggered_by' => 'archive_recovery',
                    'enabled' => !empty($current['enabled']),
                    'dry_run' => !empty($current['dry_run']),
                    'started_at' => $now,
                    'finished_at' => null,
                    'retention_days_effective' => (int) ($current['retention_days_effective'] ?? 0),
                    'cutoff_column' => (string) ($current['cutoff_column'] ?? ''),
                    'cutoff_value' => (string) ($current['cutoff_value'] ?? ''),
                    'eligible_rows' => max(0, $remaining_rows),
                    'archived_rows' => 0,
                    'archive_inserted_rows' => 0,
                    'archive_duplicate_rows' => 0,
                    'deleted_rows' => 0,
                    'delete_batches' => 0,
                    'gate_status' => (string) ($current['gate_status'] ?? 'passed'),
                    'gate_results_json' => (string) ($current['gate_results_json'] ?? ''),
                    'worker_phase' => 'archive_pending',
                    'target_max_primary_key' => (int) ($current['target_max_primary_key'] ?? 0),
                    'archive_last_primary_key' => 0,
                    'delete_last_primary_key' => 0,
                    'worker_runs' => 0,
                    'archive_batch_id' => $successor_batch_id,
                    'archive_db_path' => $new_archive_db_path,
                    'archive_integrity_check' => 'quarantine_successor_pending',
                    'error_code' => 'archive_recovery_pending',
                    'error_message' => is_string($context_json) ? $context_json : '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $inserted = $wpdb->insert(
                    $table_name,
                    $row,
                    $this->formats_for(array_keys($row))
                );
                if ($inserted === false || (int) ($wpdb->insert_id ?? 0) <= 0) {
                    throw new RuntimeException('Retention quarantine successor could not be created.');
                }
                $successor_id = (int) $wpdb->insert_id;
                $successor = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $successor_id),
                    ARRAY_A
                );
            }
            $successor_context = is_array($successor)
                ? json_decode((string) ($successor['error_message'] ?? ''), true)
                : null;
            if (!is_array($successor)
                || (string) ($successor['run_id'] ?? '') !== $successor_run_id
                || (string) ($successor['source_key'] ?? '') !== (string) ($current['source_key'] ?? '')
                || (string) ($successor['source_table'] ?? '') !== (string) ($current['source_table'] ?? '')
                || (string) ($successor['cutoff_value'] ?? '') !== (string) ($current['cutoff_value'] ?? '')
                || (int) ($successor['target_max_primary_key'] ?? 0) !== (int) ($current['target_max_primary_key'] ?? 0)
                || (int) ($successor['eligible_rows'] ?? -1) !== max(0, $remaining_rows)
                || (string) ($successor['archive_batch_id'] ?? '') !== $successor_batch_id
                || (string) ($successor['archive_db_path'] ?? '') !== $new_archive_db_path
                || (string) ($successor['triggered_by'] ?? '') !== 'archive_recovery'
                || !is_array($successor_context)
                || (string) ($successor_context['old_run_id'] ?? '') !== $old_run_id
                || (string) ($successor_context['old_archive'] ?? '') !== $old_archive
                || (string) ($successor_context['new_archive'] ?? '') !== $new_archive
            ) {
                throw new RuntimeException('Existing retention quarantine successor does not match the frozen source scope.');
            }

            $closed = $wpdb->update(
                $table_name,
                $this->normalize_row([
                    'status' => 'failed',
                    'worker_phase' => 'archive_quarantined',
                    'archive_integrity_check' => 'corruption_confirmed',
                    'error_code' => 'archive_quarantined_successor_created',
                    'error_message' => 'Retention cleanup stopped using the quarantined archive generation and created a deterministic successor.',
                    'finished_at' => $now,
                    'worker_last_finished_at' => $now,
                    'updated_at' => $now,
                ]),
                ['id' => $run_db_id],
                null,
                ['%d']
            );
            if ($closed === false) {
                throw new RuntimeException('Quarantined retention run could not be closed.');
            }

            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('Retention quarantine transition could not be committed.');
            }

            return is_array($successor) ? $successor : null;
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');

            return null;
        }
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
