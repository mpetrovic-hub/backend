<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Kpi_Summary_Repository
{
    private function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_landing_kpi_summary';
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
            landing_key VARCHAR(100) NOT NULL DEFAULT '',
            service_key VARCHAR(100) NOT NULL DEFAULT '',
            provider_key VARCHAR(50) NOT NULL DEFAULT '',
            flow_key VARCHAR(50) NOT NULL DEFAULT '',
            clicks INT UNSIGNED NOT NULL DEFAULT 0,
            cta1 INT UNSIGNED NOT NULL DEFAULT 0,
            cta1_cr DECIMAL(7,2) NOT NULL DEFAULT 0,
            cta2 INT UNSIGNED NOT NULL DEFAULT 0,
            cta2_cr DECIMAL(7,2) NOT NULL DEFAULT 0,
            cta3 INT UNSIGNED NOT NULL DEFAULT 0,
            cta3_cr DECIMAL(7,2) NOT NULL DEFAULT 0,
            conv INT UNSIGNED NOT NULL DEFAULT 0,
            conv_cr DECIMAL(7,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY landing_key (landing_key),
            KEY service_key (service_key),
            KEY provider_key (provider_key),
            KEY flow_key (flow_key),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function increment_counter(string $landing_key, string $counter, array $context = []): bool
    {
        global $wpdb;

        $landing_key = trim($landing_key);
        $counter = strtolower(trim($counter));

        if ($landing_key === '' || !$this->is_allowed_counter($counter)) {
            return false;
        }

        $service_key = trim((string) ($context['service_key'] ?? ''));
        $provider_key = trim((string) ($context['provider_key'] ?? ''));
        $flow_key = trim((string) ($context['flow_key'] ?? ''));
        $now = $this->current_time_mysql();
        $table_name = $this->get_table_name();
        $clicks_expression = $counter === 'clicks' ? '(clicks + 1)' : 'clicks';
        $cta1_expression = $counter === 'cta1' ? '(cta1 + 1)' : 'cta1';
        $cta2_expression = $counter === 'cta2' ? '(cta2 + 1)' : 'cta2';
        $cta3_expression = $counter === 'cta3' ? '(cta3 + 1)' : 'cta3';
        $conv_expression = $counter === 'conv' ? '(conv + 1)' : 'conv';

        $upsert_result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table_name} (
                    created_at,
                    updated_at,
                    landing_key,
                    service_key,
                    provider_key,
                    flow_key,
                    clicks,
                    cta1,
                    cta1_cr,
                    cta2,
                    cta2_cr,
                    cta3,
                    cta3_cr,
                    conv,
                    conv_cr
                ) VALUES (
                    %s,
                    %s,
                    %s,
                    %s,
                    %s,
                    %s,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0
                )
                ON DUPLICATE KEY UPDATE
                    updated_at = VALUES(updated_at),
                    service_key = IF(service_key = '', VALUES(service_key), service_key),
                    provider_key = IF(provider_key = '', VALUES(provider_key), provider_key),
                    flow_key = IF(flow_key = '', VALUES(flow_key), flow_key)",
                $now,
                $now,
                $landing_key,
                $service_key,
                $provider_key,
                $flow_key
            )
        );

        if ($upsert_result === false) {
            return false;
        }

        $update_result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table_name}
                 SET updated_at = %s,
                     clicks = {$clicks_expression},
                     cta1 = {$cta1_expression},
                     cta2 = {$cta2_expression},
                     cta3 = {$cta3_expression},
                     conv = {$conv_expression},
                     cta1_cr = CASE WHEN {$clicks_expression} > 0 THEN ROUND(({$cta1_expression} / {$clicks_expression}) * 100, 2) ELSE 0 END,
                     cta2_cr = CASE WHEN {$clicks_expression} > 0 THEN ROUND(({$cta2_expression} / {$clicks_expression}) * 100, 2) ELSE 0 END,
                     cta3_cr = CASE WHEN {$clicks_expression} > 0 THEN ROUND(({$cta3_expression} / {$clicks_expression}) * 100, 2) ELSE 0 END,
                     conv_cr = CASE WHEN {$clicks_expression} > 0 THEN ROUND(({$conv_expression} / {$clicks_expression}) * 100, 2) ELSE 0 END
                 WHERE landing_key = %s",
                $now,
                $landing_key
            )
        );

        return $update_result !== false;
    }

    public function get_rows(array $landing_keys = []): array
    {
        global $wpdb;

        $normalized_keys = $this->normalize_landing_keys($landing_keys);
        $table_name = $this->get_table_name();

        if (!empty($normalized_keys)) {
            $placeholders = implode(', ', array_fill(0, count($normalized_keys), '%s'));
            $sql = "SELECT * FROM {$table_name} WHERE landing_key IN ({$placeholders}) ORDER BY landing_key ASC";

            $rows = $wpdb->get_results(
                $wpdb->prepare($sql, ...$normalized_keys),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                "SELECT * FROM {$table_name} ORDER BY landing_key ASC",
                ARRAY_A
            );
        }

        return is_array($rows) ? $rows : [];
    }

    private function is_allowed_counter(string $counter): bool
    {
        return in_array($counter, ['clicks', 'cta1', 'cta2', 'cta3', 'conv'], true);
    }

    private function normalize_landing_keys(array $landing_keys): array
    {
        $normalized = [];

        foreach ($landing_keys as $landing_key) {
            $landing_key = trim((string) $landing_key);

            if ($landing_key === '') {
                continue;
            }

            $normalized[] = $landing_key;
        }

        return array_values(array_unique($normalized));
    }

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}

