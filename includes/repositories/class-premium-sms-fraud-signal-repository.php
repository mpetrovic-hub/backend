<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Premium_Sms_Fraud_Signal_Repository
{
    private function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_premium_sms_fraud_signals';
    }

    public function create_table(): void
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            provider_key VARCHAR(50) NOT NULL DEFAULT '',
            service_key VARCHAR(100) NOT NULL DEFAULT '',
            flow_key VARCHAR(50) NOT NULL DEFAULT '',
            pid VARCHAR(191) NOT NULL DEFAULT '',
            click_id VARCHAR(191) NOT NULL DEFAULT '',
            country VARCHAR(10) NOT NULL DEFAULT '',
            source_event_key VARCHAR(191) NOT NULL DEFAULT '',
            identity_type VARCHAR(20) NOT NULL DEFAULT '',
            identity_value VARCHAR(191) NOT NULL DEFAULT '',
            occurred_at DATETIME NOT NULL,
            count_1h INT UNSIGNED NOT NULL DEFAULT 0,
            count_24h INT UNSIGNED NOT NULL DEFAULT 0,
            count_total INT UNSIGNED NOT NULL DEFAULT 0,
            is_soft_flag TINYINT(1) NOT NULL DEFAULT 0,
            soft_flag_reason VARCHAR(191) NOT NULL DEFAULT '',
            meta_json LONGTEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY source_event_identity (source_event_key, identity_type),
            KEY service_key (service_key),
            KEY provider_key (provider_key),
            KEY flow_key (flow_key),
            KEY pid (pid),
            KEY click_id (click_id),
            KEY identity_lookup (service_key, identity_type, identity_value),
            KEY occurred_at (occurred_at),
            KEY is_soft_flag (is_soft_flag)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function insert_if_new(array $data): array
    {
        $source_event_key = trim((string) ($data['source_event_key'] ?? ''));
        $identity_type = trim((string) ($data['identity_type'] ?? ''));

        if ($source_event_key === '' || $identity_type === '') {
            return [
                'inserted' => false,
                'row' => null,
            ];
        }

        $existing = $this->get_by_source_event_identity($source_event_key, $identity_type);

        if (is_array($existing)) {
            return [
                'inserted' => false,
                'row' => $existing,
            ];
        }

        global $wpdb;

        $result = $wpdb->insert(
            $this->get_table_name(),
            [
                'created_at' => $this->current_time_mysql(),
                'provider_key' => (string) ($data['provider_key'] ?? ''),
                'service_key' => (string) ($data['service_key'] ?? ''),
                'flow_key' => (string) ($data['flow_key'] ?? ''),
                'pid' => $this->sanitize_pid((string) ($data['pid'] ?? '')),
                'click_id' => $this->sanitize_click_id((string) ($data['click_id'] ?? '')),
                'country' => (string) ($data['country'] ?? ''),
                'source_event_key' => $source_event_key,
                'identity_type' => $identity_type,
                'identity_value' => (string) ($data['identity_value'] ?? ''),
                'occurred_at' => $this->normalize_mysql_datetime((string) ($data['occurred_at'] ?? '')),
                'count_1h' => max(0, (int) ($data['count_1h'] ?? 0)),
                'count_24h' => max(0, (int) ($data['count_24h'] ?? 0)),
                'count_total' => max(0, (int) ($data['count_total'] ?? 0)),
                'is_soft_flag' => !empty($data['is_soft_flag']) ? 1 : 0,
                'soft_flag_reason' => substr(trim((string) ($data['soft_flag_reason'] ?? '')), 0, 191),
                'meta_json' => isset($data['meta_json']) ? wp_json_encode($data['meta_json']) : '',
            ],
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%d',
                '%d',
                '%d',
                '%s',
                '%s',
            ]
        );

        if ($result === false) {
            $existing_after_failure = $this->get_by_source_event_identity($source_event_key, $identity_type);

            return [
                'inserted' => false,
                'row' => $existing_after_failure,
            ];
        }

        return [
            'inserted' => true,
            'row' => $this->get_by_id((int) $wpdb->insert_id),
        ];
    }

    public function build_counts_snapshot(
        string $service_key,
        string $identity_type,
        string $identity_value,
        string $occurred_at
    ): array {
        $service_key = trim($service_key);
        $identity_type = trim($identity_type);
        $identity_value = trim($identity_value);
        $occurred_at = $this->normalize_mysql_datetime($occurred_at);

        if ($service_key === '' || $identity_type === '' || $identity_value === '') {
            return [
                'count_1h' => 0,
                'count_24h' => 0,
                'count_total' => 0,
            ];
        }

        $count_1h = $this->count_identity_events(
            $service_key,
            $identity_type,
            $identity_value,
            $occurred_at,
            1
        );
        $count_24h = $this->count_identity_events(
            $service_key,
            $identity_type,
            $identity_value,
            $occurred_at,
            24
        );
        $count_total = $this->count_identity_total(
            $service_key,
            $identity_type,
            $identity_value,
            $occurred_at
        );

        return [
            'count_1h' => $count_1h + 1,
            'count_24h' => $count_24h + 1,
            'count_total' => $count_total + 1,
        ];
    }

    public function get_recent(array $filters = [], int $limit = 100): array
    {
        global $wpdb;

        $limit = max(1, min(500, $limit));
        $where_sql = ['1 = 1'];
        $params = [];

        $service_key = trim((string) ($filters['service_key'] ?? ''));
        if ($service_key !== '') {
            $where_sql[] = 'service_key = %s';
            $params[] = $service_key;
        }

        $provider_key = trim((string) ($filters['provider_key'] ?? ''));
        if ($provider_key !== '') {
            $where_sql[] = 'provider_key = %s';
            $params[] = $provider_key;
        }

        $flow_key = trim((string) ($filters['flow_key'] ?? ''));
        if ($flow_key !== '') {
            $where_sql[] = 'flow_key = %s';
            $params[] = $flow_key;
        }

        $pid = $this->sanitize_pid((string) ($filters['pid'] ?? ''));
        if ($pid !== '') {
            $where_sql[] = 'pid = %s';
            $params[] = $pid;
        }

        $click_id = $this->sanitize_click_id((string) ($filters['click_id'] ?? ''));
        if ($click_id !== '') {
            $where_sql[] = 'click_id = %s';
            $params[] = $click_id;
        }

        $identity_type = trim((string) ($filters['identity_type'] ?? ''));
        if ($identity_type !== '') {
            $where_sql[] = 'identity_type = %s';
            $params[] = $identity_type;
        }

        if (!empty($filters['flagged_only'])) {
            $where_sql[] = 'is_soft_flag = 1';
        }

        $params[] = $limit;

        $sql = "SELECT *
                FROM {$this->get_table_name()}
                WHERE " . implode(' AND ', $where_sql) . '
                ORDER BY occurred_at DESC, id DESC
                LIMIT %d';

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, ...$params),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    protected function get_by_source_event_identity(string $source_event_key, string $identity_type): ?array
    {
        global $wpdb;

        $source_event_key = trim($source_event_key);
        $identity_type = trim($identity_type);

        if ($source_event_key === '' || $identity_type === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$this->get_table_name()}
                 WHERE source_event_key = %s
                   AND identity_type = %s
                 LIMIT 1",
                $source_event_key,
                $identity_type
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    protected function count_identity_events(
        string $service_key,
        string $identity_type,
        string $identity_value,
        string $occurred_at,
        int $hours
    ): int {
        global $wpdb;

        $hours = max(1, $hours);

        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$this->get_table_name()}
                 WHERE service_key = %s
                   AND identity_type = %s
                   AND identity_value = %s
                   AND occurred_at <= %s
                   AND occurred_at >= DATE_SUB(%s, INTERVAL %d HOUR)",
                $service_key,
                $identity_type,
                $identity_value,
                $occurred_at,
                $occurred_at,
                $hours
            )
        );

        return max(0, (int) $value);
    }

    protected function count_identity_total(
        string $service_key,
        string $identity_type,
        string $identity_value,
        string $occurred_at
    ): int {
        global $wpdb;

        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$this->get_table_name()}
                 WHERE service_key = %s
                   AND identity_type = %s
                   AND identity_value = %s
                   AND occurred_at <= %s",
                $service_key,
                $identity_type,
                $identity_value,
                $occurred_at
            )
        );

        return max(0, (int) $value);
    }

    private function get_by_id(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->get_table_name()} WHERE id = %d LIMIT 1",
                $id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    protected function normalize_mysql_datetime(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return $this->current_time_mysql();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return $this->current_time_mysql();
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    protected function sanitize_pid(string $pid): string
    {
        $pid = trim($pid);

        if ($pid === '') {
            return '';
        }

        $pid = preg_replace('/[^A-Za-z0-9._~:-]/', '', $pid);
        $pid = is_string($pid) ? $pid : '';

        return substr($pid, 0, 191);
    }

    protected function sanitize_click_id(string $click_id): string
    {
        $click_id = trim($click_id);

        if ($click_id === '') {
            return '';
        }

        $click_id = preg_replace('/[^A-Za-z0-9._~:-]/', '', $click_id);
        $click_id = is_string($click_id) ? $click_id : '';

        return substr($click_id, 0, 191);
    }

    protected function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
