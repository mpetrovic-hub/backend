<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Funnel_Daily_Summary_Repository implements Kiwi_Statistics_Read_Repository_Interface
{
    public const DEFAULT_FROM = '2026-05-12';

    private const FILTER_FIELDS = [
        'service_key' => 'service_keys',
        'landing_key' => 'landing_keys',
        'tksource' => 'tksources',
        'tkzone' => 'tkzones',
        'device_brand' => 'device_brands',
        'android_version' => 'android_versions',
        'browser' => 'browsers',
    ];

    private $last_error = '';

    public function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_landing_funnel_daily_summary';
    }

    public function create_table(): void
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            metric_date DATE NOT NULL,
            landing_key VARCHAR(100) NOT NULL DEFAULT '(unknown)',
            service_key VARCHAR(100) NOT NULL DEFAULT '(unknown)',
            provider_key VARCHAR(50) NOT NULL DEFAULT '(unknown)',
            flow_key VARCHAR(50) NOT NULL DEFAULT '(unknown)',
            country VARCHAR(10) NOT NULL DEFAULT '(unknown)',
            pid VARCHAR(191) NOT NULL DEFAULT '(unknown)',
            tksource VARCHAR(191) NOT NULL DEFAULT '(unknown)',
            tkzone VARCHAR(191) NOT NULL DEFAULT '(unknown)',
            device_brand VARCHAR(100) NOT NULL DEFAULT '(unknown)',
            android_version VARCHAR(50) NOT NULL DEFAULT '(unknown)',
            browser VARCHAR(100) NOT NULL DEFAULT '(unknown)',
            dimension_hash CHAR(64) NOT NULL,
            sessions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            page_loaded_sessions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            cta1_sessions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            cta1_click_events BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            cta2_sessions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            cta2_click_events BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            cta3_sessions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            cta3_click_events BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            handoff_attempts BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            handoff_successes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            handoff_fails BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            handoff_rate_pct DECIMAL(7,2) NOT NULL DEFAULT 0,
            median_hidden_seconds DECIMAL(12,2) NULL,
            min_hidden_seconds DECIMAL(12,2) NULL,
            max_hidden_seconds DECIMAL(12,2) NULL,
            sales BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            sales_amount_minor BIGINT(20) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY metric_date_dimension_hash (metric_date, dimension_hash),
            KEY metric_date (metric_date),
            KEY landing_key (landing_key),
            KEY service_key (service_key),
            KEY provider_key (provider_key),
            KEY flow_key (flow_key),
            KEY country (country),
            KEY pid (pid),
            KEY tksource (tksource),
            KEY tkzone (tkzone),
            KEY device_brand (device_brand),
            KEY android_version (android_version),
            KEY browser (browser),
            KEY dimension_hash (dimension_hash)
        ) {$charset_collate};";

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        dbDelta($sql);
    }

    public function delete_metric_date_range(string $from_date, string $to_date): int
    {
        $table_name = $this->get_table_name();

        return $this->run_prepared_query(
            "DELETE FROM {$table_name} WHERE metric_date BETWEEN %s AND %s",
            [$from_date, $to_date],
            'delete daily summary metric date range'
        );
    }

    public function insert_aggregate_rows(string $sql, array $params): int
    {
        return $this->run_prepared_query($sql, $params, 'insert daily summary aggregate rows');
    }

    public function get_rows(array $filters = [], int $limit = 100): array
    {
        global $wpdb;

        $this->last_error = '';
        $limit = max(1, min(500, $limit));
        $normalized_filters = $this->normalize_filters($filters);
        $where_sql = ['metric_date >= %s'];
        $params = [(string) $normalized_filters['from']];

        if ((string) ($normalized_filters['to'] ?? '') !== '') {
            $where_sql[] = 'metric_date <= %s';
            $params[] = (string) $normalized_filters['to'];
        }

        foreach (array_keys(self::FILTER_FIELDS) as $field) {
            $value = (string) ($normalized_filters[$field] ?? '');

            if ($value === '') {
                continue;
            }

            $where_sql[] = "{$field} = %s";
            $params[] = $value;
        }

        $params[] = $limit;
        $table_name = $this->get_table_name();
        $where_clause = implode(' AND ', $where_sql);

        $sql = "SELECT
                    metric_date,
                    landing_key,
                    service_key,
                    provider_key,
                    flow_key,
                    country,
                    pid,
                    tksource,
                    tkzone,
                    device_brand,
                    android_version,
                    browser,
                    sessions,
                    page_loaded_sessions,
                    cta1_sessions,
                    cta1_click_events,
                    cta2_sessions,
                    cta2_click_events,
                    cta3_sessions,
                    cta3_click_events,
                    handoff_attempts,
                    handoff_successes,
                    handoff_fails,
                    handoff_rate_pct,
                    min_hidden_seconds,
                    median_hidden_seconds,
                    max_hidden_seconds,
                    sales,
                    sales_amount_minor
                FROM {$table_name}
                WHERE {$where_clause}
                ORDER BY metric_date DESC, sessions DESC, sales DESC, landing_key ASC, service_key ASC, tksource ASC, tkzone ASC
                LIMIT %d";

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, ...$params),
            ARRAY_A
        );

        if ($this->has_database_error()) {
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    public function get_filter_options(array $filters = []): array
    {
        $options = [];

        foreach (self::FILTER_FIELDS as $field => $option_key) {
            $options[$option_key] = $this->get_distinct_filter_values($field, $filters);
        }

        return $options;
    }

    public function get_last_error(): string
    {
        return $this->last_error;
    }

    public function get_source_name(): string
    {
        return $this->get_table_name();
    }

    public function get_default_from(): string
    {
        return self::DEFAULT_FROM;
    }

    public function normalize_filters(array $filters): array
    {
        $normalized = [
            'from' => $this->normalize_date((string) ($filters['from'] ?? self::DEFAULT_FROM), self::DEFAULT_FROM),
            'to' => $this->normalize_optional_date((string) ($filters['to'] ?? '')),
            'limit' => max(1, min(500, (int) ($filters['limit'] ?? 100))),
        ];

        foreach (array_keys(self::FILTER_FIELDS) as $field) {
            $normalized[$field] = $this->normalize_filter_value((string) ($filters[$field] ?? ''));
        }

        return $normalized;
    }

    private function run_prepared_query(string $sql, array $params, string $context): int
    {
        global $wpdb;

        $this->last_error = '';
        $statement = empty($params) ? $sql : $wpdb->prepare($sql, ...$params);

        if (!is_string($statement) && !is_array($statement)) {
            if (!$this->has_database_error()) {
                $this->last_error = 'Landing funnel daily summary ' . $context . ' prepare failed without database error detail.';
            }

            return -1;
        }

        $result = $wpdb->query($statement);

        if ($result === false) {
            if (!$this->has_database_error()) {
                $this->last_error = 'Landing funnel daily summary ' . $context . ' query failed without database error detail.';
            }

            return -1;
        }

        if ($this->has_database_error()) {
            return -1;
        }

        return (int) $result;
    }

    private function has_database_error(): bool
    {
        global $wpdb;

        $error = trim((string) ($wpdb->last_error ?? ''));

        if ($error === '') {
            return false;
        }

        $this->last_error = $error;

        return true;
    }

    private function get_distinct_filter_values(string $field, array $filters): array
    {
        global $wpdb;

        if (!array_key_exists($field, self::FILTER_FIELDS)) {
            return [];
        }

        $this->last_error = '';
        $normalized_filters = $this->normalize_filters($filters);
        $where_sql = ['metric_date >= %s'];
        $params = [(string) $normalized_filters['from']];

        if ((string) ($normalized_filters['to'] ?? '') !== '') {
            $where_sql[] = 'metric_date <= %s';
            $params[] = (string) $normalized_filters['to'];
        }

        $table_name = $this->get_table_name();
        $where_clause = implode(' AND ', $where_sql);
        $sql = "SELECT DISTINCT {$field}
                FROM {$table_name}
                WHERE {$where_clause}
                  AND {$field} <> ''
                ORDER BY {$field} ASC";

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, ...$params),
            ARRAY_A
        );

        if ($this->has_database_error() || !is_array($rows)) {
            return [];
        }

        $values = [];

        foreach ($rows as $row) {
            $value = $this->normalize_filter_value((string) ($row[$field] ?? ''));

            if ($value !== '') {
                $values[] = $value;
            }
        }

        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    private function normalize_date(string $value, string $fallback): string
    {
        $value = trim($value);

        if ($value === '') {
            return $fallback;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T].*)?$/', $value, $matches) !== 1) {
            return $fallback;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];

        return checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : $fallback;
    }

    private function normalize_optional_date(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return $this->normalize_date($value, '');
    }

    private function normalize_filter_value(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (in_array($value, ['(empty)', '(unknown)'], true)) {
            return $value;
        }

        $value = preg_replace('/[^A-Za-z0-9._~:-]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, 191);
    }
}
