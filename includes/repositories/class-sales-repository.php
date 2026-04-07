<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Sales_Repository
{
    private function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_sales';
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
            sale_reference VARCHAR(100) NOT NULL DEFAULT '',
            transaction_id VARCHAR(120) NOT NULL DEFAULT '',
            provider_key VARCHAR(50) NOT NULL DEFAULT '',
            country VARCHAR(10) NOT NULL DEFAULT '',
            flow_key VARCHAR(50) NOT NULL DEFAULT '',
            sale_type VARCHAR(50) NOT NULL DEFAULT '',
            status VARCHAR(50) NOT NULL DEFAULT '',
            amount_minor INT NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT '',
            subscriber_reference VARCHAR(150) NOT NULL DEFAULT '',
            operator_code VARCHAR(100) NOT NULL DEFAULT '',
            operator_name VARCHAR(191) NOT NULL DEFAULT '',
            shortcode VARCHAR(50) NOT NULL DEFAULT '',
            keyword VARCHAR(100) NOT NULL DEFAULT '',
            external_sale_id VARCHAR(100) NOT NULL DEFAULT '',
            external_transaction_id VARCHAR(100) NOT NULL DEFAULT '',
            completed_at DATETIME NULL,
            context_json LONGTEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY sale_reference (sale_reference),
            KEY provider_key (provider_key),
            KEY country (country),
            KEY flow_key (flow_key),
            KEY transaction_id (transaction_id),
            KEY external_sale_id (external_sale_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function upsert(array $data): array
    {
        $existing = $this->find_by_sale_reference((string) ($data['sale_reference'] ?? ''));

        if (is_array($existing)) {
            $this->update((int) $existing['id'], $data);

            return $this->find_by_sale_reference((string) ($data['sale_reference'] ?? '')) ?? $existing;
        }

        $id = $this->insert($data);

        return $this->get_by_id($id) ?? [];
    }

    public function find_by_sale_reference(string $sale_reference): ?array
    {
        global $wpdb;

        $sale_reference = trim($sale_reference);

        if ($sale_reference === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->get_table_name()} WHERE sale_reference = %s LIMIT 1",
                $sale_reference
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function insert(array $data): int
    {
        global $wpdb;

        $now = $this->current_time_mysql();

        $wpdb->insert(
            $this->get_table_name(),
            [
                'created_at' => $now,
                'updated_at' => $now,
                'sale_reference' => $data['sale_reference'] ?? '',
                'transaction_id' => $data['transaction_id'] ?? '',
                'provider_key' => $data['provider_key'] ?? '',
                'country' => $data['country'] ?? '',
                'flow_key' => $data['flow_key'] ?? '',
                'sale_type' => $data['sale_type'] ?? '',
                'status' => $data['status'] ?? '',
                'amount_minor' => isset($data['amount_minor']) ? (int) $data['amount_minor'] : 0,
                'currency' => $data['currency'] ?? '',
                'subscriber_reference' => $data['subscriber_reference'] ?? '',
                'operator_code' => $data['operator_code'] ?? '',
                'operator_name' => $data['operator_name'] ?? '',
                'shortcode' => $data['shortcode'] ?? '',
                'keyword' => $data['keyword'] ?? '',
                'external_sale_id' => $data['external_sale_id'] ?? '',
                'external_transaction_id' => $data['external_transaction_id'] ?? '',
                'completed_at' => $data['completed_at'] ?? null,
                'context_json' => isset($data['context_json']) ? wp_json_encode($data['context_json']) : '',
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
                '%d',
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

        return (int) $wpdb->insert_id;
    }

    private function update(int $id, array $data): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            $this->get_table_name(),
            [
                'updated_at' => $this->current_time_mysql(),
                'transaction_id' => $data['transaction_id'] ?? '',
                'provider_key' => $data['provider_key'] ?? '',
                'country' => $data['country'] ?? '',
                'flow_key' => $data['flow_key'] ?? '',
                'sale_type' => $data['sale_type'] ?? '',
                'status' => $data['status'] ?? '',
                'amount_minor' => isset($data['amount_minor']) ? (int) $data['amount_minor'] : 0,
                'currency' => $data['currency'] ?? '',
                'subscriber_reference' => $data['subscriber_reference'] ?? '',
                'operator_code' => $data['operator_code'] ?? '',
                'operator_name' => $data['operator_name'] ?? '',
                'shortcode' => $data['shortcode'] ?? '',
                'keyword' => $data['keyword'] ?? '',
                'external_sale_id' => $data['external_sale_id'] ?? '',
                'external_transaction_id' => $data['external_transaction_id'] ?? '',
                'completed_at' => $data['completed_at'] ?? null,
                'context_json' => isset($data['context_json']) ? wp_json_encode($data['context_json']) : '',
            ],
            ['id' => $id],
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
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
            ],
            ['%d']
        );

        return $result !== false;
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

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
