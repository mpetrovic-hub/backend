<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Nth_Flow_Transaction_Repository
{
    private function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_nth_flow_transactions';
    }

    public function create_table(): void
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            service_key VARCHAR(100) NOT NULL DEFAULT '',
            country VARCHAR(10) NOT NULL DEFAULT '',
            flow_key VARCHAR(50) NOT NULL DEFAULT '',
            flow_reference VARCHAR(100) NOT NULL DEFAULT '',
            sale_reference VARCHAR(100) NOT NULL DEFAULT '',
            landing_key VARCHAR(100) NOT NULL DEFAULT '',
            landing_session_token VARCHAR(100) NOT NULL DEFAULT '',
            subscriber_reference VARCHAR(150) NOT NULL DEFAULT '',
            shortcode VARCHAR(50) NOT NULL DEFAULT '',
            keyword VARCHAR(100) NOT NULL DEFAULT '',
            operator_code VARCHAR(100) NOT NULL DEFAULT '',
            operator_name VARCHAR(191) NOT NULL DEFAULT '',
            nwc VARCHAR(100) NOT NULL DEFAULT '',
            message_text TEXT NULL,
            mo_event_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            last_event_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            mt_submit_event_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            last_report_event_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            external_request_id VARCHAR(100) NOT NULL DEFAULT '',
            external_message_id VARCHAR(100) NOT NULL DEFAULT '',
            current_status VARCHAR(100) NOT NULL DEFAULT '',
            is_terminal TINYINT(1) NOT NULL DEFAULT 0,
            sale_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            price INT NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT '',
            meta_json LONGTEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY flow_reference (flow_reference),
            KEY service_key (service_key),
            KEY sale_reference (sale_reference),
            KEY subscriber_reference (subscriber_reference),
            KEY external_message_id (external_message_id),
            KEY external_request_id (external_request_id),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function create(array $data): int
    {
        global $wpdb;

        $now = $this->current_time_mysql();

        $wpdb->insert(
            $this->get_table_name(),
            [
                'created_at' => $now,
                'updated_at' => $now,
                'service_key' => $data['service_key'] ?? '',
                'country' => $data['country'] ?? '',
                'flow_key' => $data['flow_key'] ?? '',
                'flow_reference' => $data['flow_reference'] ?? '',
                'sale_reference' => $data['sale_reference'] ?? '',
                'landing_key' => $data['landing_key'] ?? '',
                'landing_session_token' => $data['landing_session_token'] ?? '',
                'subscriber_reference' => $data['subscriber_reference'] ?? '',
                'shortcode' => $data['shortcode'] ?? '',
                'keyword' => $data['keyword'] ?? '',
                'operator_code' => $data['operator_code'] ?? '',
                'operator_name' => $data['operator_name'] ?? '',
                'nwc' => $data['nwc'] ?? '',
                'message_text' => $data['message_text'] ?? '',
                'mo_event_id' => isset($data['mo_event_id']) ? (int) $data['mo_event_id'] : 0,
                'last_event_id' => isset($data['last_event_id']) ? (int) $data['last_event_id'] : 0,
                'mt_submit_event_id' => isset($data['mt_submit_event_id']) ? (int) $data['mt_submit_event_id'] : 0,
                'last_report_event_id' => isset($data['last_report_event_id']) ? (int) $data['last_report_event_id'] : 0,
                'external_request_id' => $data['external_request_id'] ?? '',
                'external_message_id' => $data['external_message_id'] ?? '',
                'current_status' => $data['current_status'] ?? '',
                'is_terminal' => !empty($data['is_terminal']) ? 1 : 0,
                'sale_id' => isset($data['sale_id']) ? (int) $data['sale_id'] : 0,
                'price' => isset($data['price']) ? (int) $data['price'] : 0,
                'currency' => $data['currency'] ?? 'EUR',
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
                '%s',
                '%d',
                '%d',
                '%d',
                '%s',
                '%s',
            ]
        );

        return (int) $wpdb->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        global $wpdb;

        $fields = ['updated_at' => $this->current_time_mysql()];
        $formats = ['%s'];

        $allowed_fields = [
            'landing_session_token' => '%s',
            'operator_code' => '%s',
            'operator_name' => '%s',
            'nwc' => '%s',
            'message_text' => '%s',
            'last_event_id' => '%d',
            'mt_submit_event_id' => '%d',
            'last_report_event_id' => '%d',
            'external_request_id' => '%s',
            'external_message_id' => '%s',
            'current_status' => '%s',
            'is_terminal' => '%d',
            'sale_id' => '%d',
            'meta_json' => '%s',
        ];

        foreach ($allowed_fields as $field => $format) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            if ($field === 'meta_json') {
                $value = wp_json_encode($value);
            }

            $fields[$field] = $value;
            $formats[] = $format;
        }

        if (count($fields) === 1) {
            return true;
        }

        $result = $wpdb->update(
            $this->get_table_name(),
            $fields,
            ['id' => $id],
            $formats,
            ['%d']
        );

        return $result !== false;
    }

    public function get_by_id(int $id): ?array
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

    public function find_active_by_subscriber_context(
        string $service_key,
        string $subscriber_reference,
        string $shortcode,
        string $keyword,
        int $hours
    ): ?array {
        global $wpdb;

        $hours = max(1, $hours);

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$this->get_table_name()}
                 WHERE service_key = %s
                   AND subscriber_reference = %s
                   AND shortcode = %s
                   AND keyword = %s
                   AND updated_at >= DATE_SUB(%s, INTERVAL %d HOUR)
                 ORDER BY id DESC
                 LIMIT 1",
                $service_key,
                $subscriber_reference,
                $shortcode,
                $keyword,
                $this->current_time_mysql(),
                $hours
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function find_recent_by_external_references(string $service_key, array $references): ?array
    {
        global $wpdb;

        $references = array_values(array_filter(array_map('strval', $references)));

        if (empty($references)) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($references), '%s'));
        $params = array_merge([$service_key], $references, $references);

        $sql = "SELECT *
                FROM {$this->get_table_name()}
                WHERE service_key = %s
                  AND (
                    external_message_id IN ({$placeholders})
                    OR external_request_id IN ({$placeholders})
                  )
                ORDER BY id DESC
                LIMIT 1";

        $row = $wpdb->get_row(
            $wpdb->prepare($sql, ...$params),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
