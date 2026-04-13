<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Kpi_Event_Repository
{
    private function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_landing_kpi_events';
    }

    public function create_table(): void
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            landing_key VARCHAR(100) NOT NULL DEFAULT '',
            service_key VARCHAR(100) NOT NULL DEFAULT '',
            session_token VARCHAR(100) NOT NULL DEFAULT '',
            event_step VARCHAR(32) NOT NULL DEFAULT '',
            event_value VARCHAR(191) NOT NULL DEFAULT '',
            request_host VARCHAR(191) NOT NULL DEFAULT '',
            request_path VARCHAR(191) NOT NULL DEFAULT '',
            remote_ip VARCHAR(100) NOT NULL DEFAULT '',
            user_agent TEXT NULL,
            raw_context LONGTEXT NULL,
            dedupe_key CHAR(40) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            UNIQUE KEY dedupe_key (dedupe_key),
            KEY landing_key (landing_key),
            KEY service_key (service_key),
            KEY session_token (session_token),
            KEY event_step (event_step),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function insert_step_event(array $data): array
    {
        global $wpdb;

        $landing_key = trim((string) ($data['landing_key'] ?? ''));
        $event_step = strtolower(trim((string) ($data['event_step'] ?? '')));
        $session_token = trim((string) ($data['session_token'] ?? ''));

        if ($landing_key === '' || $event_step === '' || $session_token === '') {
            return [
                'inserted' => false,
                'row' => null,
            ];
        }

        $dedupe_key = trim((string) ($data['dedupe_key'] ?? ''));
        if ($dedupe_key === '') {
            $dedupe_key = $this->build_dedupe_key($landing_key, $session_token, $event_step);
        }

        $insert_result = $wpdb->insert(
            $this->get_table_name(),
            [
                'created_at' => $this->current_time_mysql(),
                'landing_key' => $landing_key,
                'service_key' => (string) ($data['service_key'] ?? ''),
                'session_token' => $session_token,
                'event_step' => $event_step,
                'event_value' => (string) ($data['event_value'] ?? ''),
                'request_host' => (string) ($data['request_host'] ?? ''),
                'request_path' => (string) ($data['request_path'] ?? ''),
                'remote_ip' => (string) ($data['remote_ip'] ?? ''),
                'user_agent' => (string) ($data['user_agent'] ?? ''),
                'raw_context' => isset($data['raw_context']) ? wp_json_encode($data['raw_context']) : '',
                'dedupe_key' => $dedupe_key,
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
                '%s',
            ]
        );

        if ($insert_result === false) {
            $existing = $this->find_by_dedupe_key($dedupe_key);

            if (is_array($existing)) {
                return [
                    'inserted' => false,
                    'row' => $existing,
                ];
            }

            return [
                'inserted' => false,
                'row' => null,
            ];
        }

        $row = $this->get_by_id((int) $wpdb->insert_id);

        return [
            'inserted' => true,
            'row' => is_array($row) ? $row : null,
        ];
    }

    public function get_step_counts_by_landing(string $since_mysql, array $landing_keys = []): array
    {
        global $wpdb;

        $where = 'created_at >= %s';
        $params = [trim($since_mysql)];
        $normalized_landing_keys = array_values(array_filter(array_map('strval', $landing_keys), static function (string $value): bool {
            return trim($value) !== '';
        }));

        if (!empty($normalized_landing_keys)) {
            $placeholders = implode(', ', array_fill(0, count($normalized_landing_keys), '%s'));
            $where .= " AND landing_key IN ({$placeholders})";
            $params = array_merge($params, $normalized_landing_keys);
        }

        $sql = "SELECT landing_key, event_step, COUNT(*) AS total
                FROM {$this->get_table_name()}
                WHERE {$where}
                GROUP BY landing_key, event_step";

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, ...$params),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $counts = [];

        foreach ($rows as $row) {
            $landing_key = trim((string) ($row['landing_key'] ?? ''));
            $event_step = strtolower(trim((string) ($row['event_step'] ?? '')));

            if ($landing_key === '' || $event_step === '') {
                continue;
            }

            if (!isset($counts[$landing_key])) {
                $counts[$landing_key] = [];
            }

            $counts[$landing_key][$event_step] = (int) ($row['total'] ?? 0);
        }

        return $counts;
    }

    private function find_by_dedupe_key(string $dedupe_key): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->get_table_name()} WHERE dedupe_key = %s LIMIT 1",
                $dedupe_key
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
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

    private function build_dedupe_key(string $landing_key, string $session_token, string $event_step): string
    {
        return sha1($landing_key . '|' . $session_token . '|' . $event_step);
    }

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}

