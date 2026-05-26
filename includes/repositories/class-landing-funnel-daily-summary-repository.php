<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Funnel_Daily_Summary_Repository
{
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
            [$from_date, $to_date]
        );
    }

    public function insert_aggregate_rows(string $sql, array $params): int
    {
        return $this->run_prepared_query($sql, $params);
    }

    public function get_last_error(): string
    {
        return $this->last_error;
    }

    private function run_prepared_query(string $sql, array $params): int
    {
        global $wpdb;

        $this->last_error = '';
        $statement = empty($params) ? $sql : $wpdb->prepare($sql, ...$params);
        $result = $wpdb->query($statement);

        if ($result === false || $this->has_database_error()) {
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
}
