<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Device_Model_Brand_Map_Repository
{
    public function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_device_model_brand_map';
    }

    public function create_table(): void
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            model_key VARCHAR(191) NOT NULL DEFAULT '',
            brand VARCHAR(100) NOT NULL DEFAULT '',
            source VARCHAR(100) NOT NULL DEFAULT '',
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY model_key (model_key),
            KEY brand (brand)
        ) {$charset_collate};";

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        dbDelta($sql);
    }

    public function find_brand_for_model(string $model): string
    {
        global $wpdb;

        $model_key = $this->normalize_model_key($model);

        if ($model_key === '') {
            return '';
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT brand FROM {$this->get_table_name()} WHERE model_key = %s LIMIT 1",
                $model_key
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return '';
        }

        return $this->sanitize_brand((string) ($row['brand'] ?? ''));
    }

    public function normalize_model_key(string $model): string
    {
        $model = strtoupper(trim($model));

        if ($model === '') {
            return '';
        }

        $model = preg_replace('/[^A-Z0-9._~:-]+/', ' ', $model);
        $model = is_string($model) ? trim((string) preg_replace('/\s+/', ' ', $model)) : '';

        return substr($model, 0, 191);
    }

    private function sanitize_brand(string $brand): string
    {
        $brand = trim($brand);

        if ($brand === '') {
            return '';
        }

        $brand = preg_replace('/[^\P{C}\r\n\t]/u', '', $brand);
        $brand = is_string($brand) ? $brand : '';
        $brand = preg_replace('/\s+/', ' ', $brand);
        $brand = is_string($brand) ? trim($brand) : '';

        return substr($brand, 0, 100);
    }
}
