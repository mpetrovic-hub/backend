<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Nth_Event_Repository
{
    private function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_nth_events';
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
            country VARCHAR(10) NOT NULL DEFAULT '',
            flow_key VARCHAR(50) NOT NULL DEFAULT '',
            event_type VARCHAR(50) NOT NULL DEFAULT '',
            direction VARCHAR(20) NOT NULL DEFAULT '',
            dedupe_key VARCHAR(191) NOT NULL DEFAULT '',
            external_event_type VARCHAR(100) NOT NULL DEFAULT '',
            external_request_id VARCHAR(100) NOT NULL DEFAULT '',
            external_message_id VARCHAR(100) NOT NULL DEFAULT '',
            external_report_id VARCHAR(100) NOT NULL DEFAULT '',
            subscriber_reference VARCHAR(150) NOT NULL DEFAULT '',
            shortcode VARCHAR(50) NOT NULL DEFAULT '',
            keyword VARCHAR(100) NOT NULL DEFAULT '',
            operator_code VARCHAR(100) NOT NULL DEFAULT '',
            operator_name VARCHAR(191) NOT NULL DEFAULT '',
            status VARCHAR(100) NOT NULL DEFAULT '',
            is_terminal TINYINT(1) NOT NULL DEFAULT 0,
            is_success TINYINT(1) NOT NULL DEFAULT 0,
            occurred_at DATETIME NOT NULL,
            raw_payload LONGTEXT NULL,
            normalized_payload LONGTEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY dedupe_key (dedupe_key),
            KEY service_key (service_key),
            KEY external_message_id (external_message_id),
            KEY external_request_id (external_request_id),
            KEY subscriber_reference (subscriber_reference),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function insert_if_new(array $event): array
    {
        $existing = $this->get_by_dedupe_key((string) ($event['dedupe_key'] ?? ''));

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
                'provider_key' => $event['provider'] ?? 'nth',
                'service_key' => $event['service_key'] ?? '',
                'country' => $event['country'] ?? '',
                'flow_key' => $event['flow_key'] ?? '',
                'event_type' => $event['event_type'] ?? '',
                'direction' => $event['direction'] ?? '',
                'dedupe_key' => $event['dedupe_key'] ?? '',
                'external_event_type' => $event['external_event_type'] ?? '',
                'external_request_id' => $event['external_request_id'] ?? '',
                'external_message_id' => $event['external_message_id'] ?? '',
                'external_report_id' => $event['external_report_id'] ?? '',
                'subscriber_reference' => $event['subscriber_reference'] ?? '',
                'shortcode' => $event['shortcode'] ?? '',
                'keyword' => $event['keyword'] ?? '',
                'operator_code' => $event['operator_code'] ?? '',
                'operator_name' => $event['operator_name'] ?? '',
                'status' => $event['status'] ?? '',
                'is_terminal' => !empty($event['is_terminal']) ? 1 : 0,
                'is_success' => !empty($event['is_success']) ? 1 : 0,
                'occurred_at' => $event['occurred_at'] ?? $this->current_time_mysql(),
                'raw_payload' => isset($event['raw_payload']) ? wp_json_encode($event['raw_payload']) : '',
                'normalized_payload' => wp_json_encode($event),
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
                '%s',
                '%s',
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
            ]
        );

        if ($result === false) {
            $existing_after_failure = $this->get_by_dedupe_key((string) ($event['dedupe_key'] ?? ''));

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

    public function get_by_dedupe_key(string $dedupe_key): ?array
    {
        global $wpdb;

        $dedupe_key = trim($dedupe_key);

        if ($dedupe_key === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->get_table_name()} WHERE dedupe_key = %s LIMIT 1",
                $dedupe_key
            ),
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
