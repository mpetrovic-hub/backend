<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Operational_Event_Repository
{
    public function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_operational_events';
    }

    public function create_table(): void
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            occurred_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            area VARCHAR(64) NOT NULL,
            severity VARCHAR(20) NOT NULL,
            event_type VARCHAR(100) NOT NULL,
            lifecycle_action VARCHAR(20) NOT NULL,
            idempotency_key VARCHAR(191) NULL,
            correlation_key VARCHAR(191) NOT NULL,
            reference_type VARCHAR(64) NOT NULL DEFAULT '',
            reference_id VARCHAR(191) NOT NULL DEFAULT '',
            message VARCHAR(500) NOT NULL,
            raw_error_text TEXT NULL,
            context_json LONGTEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY correlation_id (correlation_key, id),
            KEY occurred_id (occurred_at, id),
            KEY created_id (created_at, id),
            KEY area_severity_occurred_id (area, severity, occurred_at, id),
            KEY event_type_occurred_id (event_type, occurred_at, id),
            KEY reference_id (reference_type, reference_id, id)
        ) {$charset_collate};";

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta($sql);
    }

    public function insert_event(array $event): int
    {
        global $wpdb;

        $row = [];
        foreach ([
            'occurred_at',
            'created_at',
            'area',
            'severity',
            'event_type',
            'lifecycle_action',
            'idempotency_key',
            'correlation_key',
            'reference_type',
            'reference_id',
            'message',
            'raw_error_text',
            'context_json',
        ] as $field) {
            if (array_key_exists($field, $event)) {
                $row[$field] = $event[$field];
            }
        }

        $result = $wpdb->insert(
            $this->get_table_name(),
            $row,
            array_fill(0, count($row), '%s')
        );

        if ($result !== false) {
            return (int) ($wpdb->insert_id ?? 0);
        }

        $idempotency_key = (string) ($row['idempotency_key'] ?? '');
        if ($idempotency_key === '') {
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->get_table_name()} WHERE idempotency_key = %s LIMIT 1",
                $idempotency_key
            )
        );
    }

    public function find_latest_by_correlation_key(string $correlation_key): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->get_table_name()}
                 WHERE correlation_key = %s
                 ORDER BY occurred_at DESC, id DESC
                 LIMIT 1",
                $correlation_key
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function get_recent(array $filters = [], int $limit = 100): array
    {
        global $wpdb;

        $clauses = [];
        $args = [];
        $filter_columns = [
            'area' => 'area',
            'severity' => 'severity',
            'event_type' => 'event_type',
            'lifecycle_action' => 'lifecycle_action',
            'correlation_key' => 'correlation_key',
            'reference_type' => 'reference_type',
            'reference_id' => 'reference_id',
        ];

        foreach ($filter_columns as $filter => $column) {
            $value = trim((string) ($filters[$filter] ?? ''));
            if ($value === '') {
                continue;
            }
            $clauses[] = "{$column} = %s";
            $args[] = $value;
        }

        $where = empty($clauses) ? '' : ' WHERE ' . implode(' AND ', $clauses);
        $args[] = min(500, max(1, $limit));
        $sql = "SELECT * FROM {$this->get_table_name()}{$where}
                ORDER BY occurred_at DESC, id DESC
                LIMIT %d";

        return (array) $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
    }

    public function get_open_incidents(array $filters = [], int $limit = 100): array
    {
        global $wpdb;

        $clauses = ["latest.lifecycle_action IN ('raised', 'repeated')"];
        $args = [];

        foreach (['area', 'severity', 'event_type', 'reference_type', 'reference_id'] as $column) {
            $value = trim((string) ($filters[$column] ?? ''));
            if ($value === '') {
                continue;
            }
            $clauses[] = "latest.{$column} = %s";
            $args[] = $value;
        }

        $args[] = min(500, max(1, $limit));
        $clauses[] = "NOT EXISTS (
            SELECT 1
            FROM {$this->get_table_name()} newer
            WHERE newer.correlation_key = latest.correlation_key
              AND (
                  newer.occurred_at > latest.occurred_at
                  OR (newer.occurred_at = latest.occurred_at AND newer.id > latest.id)
              )
        )";
        $sql = "SELECT latest.*
                FROM {$this->get_table_name()} latest
                WHERE " . implode(' AND ', $clauses) . "
                ORDER BY latest.occurred_at DESC, latest.id DESC
                LIMIT %d";

        return (array) $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
    }

    public function delete_created_before(string $cutoff, int $limit): int
    {
        global $wpdb;

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->get_table_name()}
                 WHERE created_at < %s
                 ORDER BY created_at ASC, id ASC
                 LIMIT %d",
                $cutoff,
                max(1, $limit)
            )
        );

        if ($deleted === false) {
            throw new RuntimeException('Operational event cleanup query failed.');
        }

        return (int) $deleted;
    }
}
