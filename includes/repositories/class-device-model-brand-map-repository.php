<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Device_Model_Brand_Map_Repository
{
    private $brand_cache = [];

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

        if (array_key_exists($model_key, $this->brand_cache)) {
            return $this->brand_cache[$model_key];
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT brand FROM {$this->get_table_name()} WHERE model_key = %s LIMIT 1",
                $model_key
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            $this->brand_cache[$model_key] = '';

            return '';
        }

        $brand = $this->sanitize_brand((string) ($row['brand'] ?? ''));
        $brand = $brand === '(unknown)' ? '' : $brand;
        $this->brand_cache[$model_key] = $brand;

        return $brand;
    }

    public function seed_default_mappings(): int
    {
        $seeded = 0;

        foreach ($this->get_default_model_brand_mappings() as $mapping) {
            if ($this->upsert_seed_mapping(
                (string) ($mapping['model_key'] ?? ''),
                (string) ($mapping['brand'] ?? ''),
                (string) ($mapping['notes'] ?? '')
            )) {
                $seeded++;
            }
        }

        return $seeded;
    }

    public function insert_observed_unknown_model_key(
        string $model_key,
        string $observed_date,
        int $distinct_sessions,
        int $threshold
    ): bool {
        global $wpdb;

        $model_key = $this->normalize_model_key($model_key);
        $observed_date = $this->normalize_date($observed_date);
        $distinct_sessions = max(0, $distinct_sessions);
        $threshold = max(1, $threshold);

        if ($model_key === '' || $observed_date === '' || $distinct_sessions < $threshold) {
            return false;
        }

        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$this->get_table_name()}
                    (model_key, brand, source, notes, created_at, updated_at)
                 VALUES
                    (%s, %s, %s, %s, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                $model_key,
                '(unknown)',
                'observed',
                'Auto-harvested from ' . $observed_date . ' with ' . $distinct_sessions . ' distinct sessions (threshold ' . $threshold . ').'
            )
        );

        return (int) $result > 0;
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

    private function upsert_seed_mapping(string $model_key, string $brand, string $notes): bool
    {
        global $wpdb;

        $model_key = $this->normalize_model_key($model_key);
        $brand = $this->sanitize_brand($brand);
        $notes = trim($notes);

        if ($model_key === '' || $brand === '' || $brand === '(unknown)') {
            return false;
        }

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT brand, source FROM {$this->get_table_name()} WHERE model_key = %s LIMIT 1",
                $model_key
            ),
            ARRAY_A
        );

        if (!is_array($existing)) {
            $result = $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$this->get_table_name()}
                        (model_key, brand, source, notes, created_at, updated_at)
                     VALUES
                        (%s, %s, %s, %s, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                    $model_key,
                    $brand,
                    'seed:deep-research-ua-device-agents-2',
                    $notes
                )
            );

            unset($this->brand_cache[$model_key]);

            return (int) $result > 0;
        }

        $existing_brand = $this->sanitize_brand((string) ($existing['brand'] ?? ''));
        $existing_source = trim((string) ($existing['source'] ?? ''));

        if ($existing_brand !== '' && $existing_brand !== '(unknown)' && $existing_source !== 'observed') {
            return false;
        }

        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->get_table_name()}
                 SET brand = %s,
                     source = %s,
                     notes = %s,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE model_key = %s",
                $brand,
                'seed:deep-research-ua-device-agents-2',
                $notes,
                $model_key
            )
        );

        unset($this->brand_cache[$model_key]);

        return $result !== false;
    }

    public function get_default_model_brand_mappings(): array
    {
        return [
            ['model_key' => 'M2102K1G', 'brand' => 'Xiaomi', 'notes' => 'Xiaomi numeric exact map from DeepResearch.'],
            ['model_key' => '21091116UG', 'brand' => 'Redmi', 'notes' => 'Xiaomi numeric exact map from DeepResearch.'],
            ['model_key' => '2201116SG', 'brand' => 'Redmi', 'notes' => 'Xiaomi numeric exact map from DeepResearch.'],
            ['model_key' => '2201116PG', 'brand' => 'POCO', 'notes' => 'Xiaomi numeric exact map from DeepResearch.'],
            ['model_key' => 'VOG-L29', 'brand' => 'Huawei', 'notes' => 'Huawei/Honor exact split from DeepResearch.'],
            ['model_key' => 'ANA-NX9', 'brand' => 'Huawei', 'notes' => 'Huawei/Honor exact split from DeepResearch.'],
            ['model_key' => 'ELS-NX9', 'brand' => 'Huawei', 'notes' => 'Huawei/Honor exact split from DeepResearch.'],
            ['model_key' => 'NTH-NX9', 'brand' => 'Honor', 'notes' => 'Huawei/Honor exact split from DeepResearch.'],
            ['model_key' => 'REA-NX9', 'brand' => 'Honor', 'notes' => 'Huawei/Honor exact split from DeepResearch.'],
            ['model_key' => 'ANY-NX1', 'brand' => 'Honor', 'notes' => 'Huawei/Honor exact split from DeepResearch.'],
            ['model_key' => 'RMX3491', 'brand' => 'realme', 'notes' => 'Exact model from DeepResearch sample.'],
            ['model_key' => 'LE2113', 'brand' => 'OnePlus', 'notes' => 'Exact model from DeepResearch sample.'],
            ['model_key' => 'I2202', 'brand' => 'iQOO', 'notes' => 'Exact model from DeepResearch sample.'],
            ['model_key' => 'XT2453-2', 'brand' => 'Motorola', 'notes' => 'Exact model from DeepResearch sample.'],
            ['model_key' => 'XQ-EC54', 'brand' => 'Sony', 'notes' => 'Exact model from DeepResearch sample.'],
            ['model_key' => 'NX709J', 'brand' => 'Nubia', 'notes' => 'Exact model from DeepResearch sample.'],
            ['model_key' => 'TB328FU', 'brand' => 'Lenovo', 'notes' => 'Exact model from DeepResearch sample.'],
            ['model_key' => 'ASUS_I005DA', 'brand' => 'Asus', 'notes' => 'Exact model from DeepResearch sample.'],
        ];
    }

    private function normalize_date(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
            return '';
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : '';
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
