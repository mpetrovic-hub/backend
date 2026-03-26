<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Dimoco_Callback_Operator_Lookup_Repository
{
    /**
     * Get the full table name
     */
    private function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_dimoco_operator_lookup_callbacks';
    }

    /**
     * Create the operator lookup callback table if it does not exist
     */
    public function create_table(): void
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            action VARCHAR(50) NOT NULL DEFAULT '',
            action_status INT NULL,
            action_status_text VARCHAR(50) NOT NULL DEFAULT '',
            action_code VARCHAR(100) NOT NULL DEFAULT '',
            detail TEXT NULL,
            detail_psp TEXT NULL,
            request_id VARCHAR(100) NOT NULL DEFAULT '',
            reference VARCHAR(100) NOT NULL DEFAULT '',
            transaction_id VARCHAR(150) NOT NULL DEFAULT '',
            order_id VARCHAR(100) NOT NULL DEFAULT '',
            msisdn VARCHAR(50) NOT NULL DEFAULT '',
            operator VARCHAR(100) NOT NULL DEFAULT '',
            service_key VARCHAR(100) NOT NULL DEFAULT '',
            service_label VARCHAR(255) NOT NULL DEFAULT '',
            raw_payload LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY request_id (request_id),
            KEY reference (reference),
            KEY transaction_id (transaction_id),
            KEY order_id (order_id),
            KEY msisdn (msisdn),
            KEY operator (operator),
            KEY service_key (service_key),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Save one parsed operator lookup callback row
     */
    public function insert(array $data): bool
    {
        global $wpdb;

        $table_name = $this->get_table_name();

        $result = $wpdb->insert(
            $table_name,
            [
                'created_at'         => current_time('mysql'),
                'action'             => $data['action'] ?? '',
                'action_status'      => isset($data['action_status']) ? (int) $data['action_status'] : null,
                'action_status_text' => $data['action_status_text'] ?? '',
                'action_code'        => $data['action_code'] ?? '',
                'detail'             => $data['detail'] ?? '',
                'detail_psp'         => $data['detail_psp'] ?? '',
                'request_id'         => $data['request_id'] ?? '',
                'reference'          => $data['reference'] ?? '',
                'transaction_id'     => $data['transaction_id'] ?? '',
                'order_id'           => $data['order_id'] ?? '',
                'msisdn'             => $data['msisdn'] ?? '',
                'operator'           => $data['operator'] ?? '',
                'service_key'        => $data['service_key'] ?? '',
                'service_label'      => $data['service_label'] ?? '',
                'raw_payload'        => isset($data['raw']) ? wp_json_encode($data['raw']) : '',
            ],
            [
                '%s', // created_at
                '%s', // action
                '%d', // action_status
                '%s', // action_status_text
                '%s', // action_code
                '%s', // detail
                '%s', // detail_psp
                '%s', // request_id
                '%s', // reference
                '%s', // transaction_id
                '%s', // order_id
                '%s', // msisdn
                '%s', // operator
                '%s', // service_key
                '%s', // service_label
                '%s', // raw_payload
            ]
        );

        return $result !== false;
    }

    /**
     * Get recent operator lookup callbacks by service key
     */
    public function get_recent_by_service(string $service_key, int $limit = 50): array
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $limit = max(1, $limit);

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$table_name}
                 WHERE service_key = %s
                 ORDER BY id DESC
                 LIMIT %d",
                $service_key,
                $limit
            ),
            ARRAY_A
        );
    }

    /**
     * Get recent operator lookup callbacks by request IDs
     */
    public function get_recent_by_request_ids(array $request_ids, int $limit = 100): array
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $limit = max(1, $limit);

        $request_ids = array_values(array_filter(array_map('strval', $request_ids)));

        if (empty($request_ids)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($request_ids), '%s'));
        $sql = "SELECT *
                FROM {$table_name}
                WHERE request_id IN ({$placeholders})
                ORDER BY id DESC
                LIMIT %d";

        $params = array_merge($request_ids, [$limit]);

        return $wpdb->get_results(
            $wpdb->prepare($sql, ...$params),
            ARRAY_A
        );
    }

    /**
     * Get recent operator lookup callbacks by MSISDNs
     */
    public function get_recent_by_msisdns(array $msisdns, int $limit = 100): array
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $limit = max(1, $limit);

        $msisdns = array_values(array_filter(array_map('strval', $msisdns)));

        if (empty($msisdns)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($msisdns), '%s'));
        $sql = "SELECT *
                FROM {$table_name}
                WHERE msisdn IN ({$placeholders})
                ORDER BY id DESC
                LIMIT %d";

        $params = array_merge($msisdns, [$limit]);

        return $wpdb->get_results(
            $wpdb->prepare($sql, ...$params),
            ARRAY_A
        );
    }

    /**
     * Get the most recent successful operator lookup callback for one MSISDN
     */
    public function get_latest_success_by_msisdn(string $msisdn): ?array
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $msisdn = trim($msisdn);

        if ($msisdn === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$table_name}
                 WHERE msisdn = %s
                   AND action = %s
                   AND action_status = %d
                   AND operator <> ''
                 ORDER BY id DESC
                 LIMIT 1",
                $msisdn,
                'operator-lookup',
                0
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }
}