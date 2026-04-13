<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Kpi_Service
{
    private $config;
    private $event_repository;

    public function __construct(Kiwi_Config $config, Kiwi_Landing_Kpi_Event_Repository $event_repository)
    {
        $this->config = $config;
        $this->event_repository = $event_repository;
    }

    public function build_report(int $days = 30, string $landing_key = ''): array
    {
        $days = max(1, min(365, $days));
        $landing_pages = $this->config->get_landing_pages();
        $landing_key = trim($landing_key);
        $configured_landing_keys = array_values(array_filter(array_map('strval', array_keys($landing_pages))));
        $selected_landing_keys = $landing_key !== ''
            ? [$landing_key]
            : $configured_landing_keys;
        $since_mysql = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

        $click_counts = $this->fetch_click_counts($selected_landing_keys, $since_mysql);
        $step_counts = $this->event_repository->get_step_counts_by_landing($since_mysql, $selected_landing_keys);
        $conversion_counts = $this->fetch_conversion_counts($selected_landing_keys, $since_mysql);
        $all_landing_keys = $this->merge_landing_keys(
            $selected_landing_keys,
            array_keys($click_counts),
            array_keys($step_counts),
            array_keys($conversion_counts)
        );

        $rows = [];

        foreach ($all_landing_keys as $key) {
            $landing_meta = is_array($landing_pages[$key] ?? null) ? $landing_pages[$key] : [];
            $clicks = (int) ($click_counts[$key] ?? 0);
            $cta1 = (int) (($step_counts[$key]['cta1'] ?? 0));
            $cta2 = (int) (($step_counts[$key]['cta2'] ?? 0));
            $cta3 = (int) (($step_counts[$key]['cta3'] ?? 0));
            $conv = (int) ($conversion_counts[$key] ?? 0);

            $rows[] = [
                'landing_key' => $key,
                'title' => (string) ($landing_meta['title'] ?? ''),
                'service_key' => (string) ($landing_meta['service_key'] ?? ''),
                'provider' => (string) ($landing_meta['provider'] ?? ''),
                'flow' => (string) ($landing_meta['flow'] ?? ''),
                'clicks' => $clicks,
                'cta1' => $cta1,
                'cta2' => $cta2,
                'cta3' => $cta3,
                'conv' => $conv,
                'cta1_rate_pct' => $this->rate_percent($cta1, $clicks),
                'cta2_rate_pct' => $this->rate_percent($cta2, $clicks),
                'cta3_rate_pct' => $this->rate_percent($cta3, $clicks),
                'conv_rate_pct' => $this->rate_percent($conv, $clicks),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return strcmp((string) ($left['landing_key'] ?? ''), (string) ($right['landing_key'] ?? ''));
        });

        return [
            'days' => $days,
            'since' => $since_mysql,
            'generated_at' => $this->current_time_mysql(),
            'rows' => $rows,
        ];
    }

    protected function fetch_click_counts(array $landing_keys, string $since_mysql): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kiwi_landing_page_sessions';
        $where = "created_at >= %s AND landing_key <> ''";
        $params = [trim($since_mysql)];
        $normalized_landing_keys = $this->normalize_landing_keys($landing_keys);

        if (!empty($normalized_landing_keys)) {
            $placeholders = implode(', ', array_fill(0, count($normalized_landing_keys), '%s'));
            $where .= " AND landing_key IN ({$placeholders})";
            $params = array_merge($params, $normalized_landing_keys);
        }

        $sql = "SELECT landing_key, COUNT(DISTINCT session_token) AS total
                FROM {$table_name}
                WHERE {$where}
                GROUP BY landing_key";
        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, ...$params),
            ARRAY_A
        );

        return $this->rows_to_count_map($rows, 'landing_key');
    }

    protected function fetch_conversion_counts(array $landing_keys, string $since_mysql): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kiwi_click_attributions';
        $where = "landing_page_key <> ''
            AND conversion_status IN ('confirmed', 'postback_sent', 'postback_failed')
            AND (
                (conversion_confirmed_at IS NOT NULL AND conversion_confirmed_at <> '' AND conversion_confirmed_at >= %s)
                OR ((conversion_confirmed_at IS NULL OR conversion_confirmed_at = '') AND updated_at >= %s)
            )";
        $params = [trim($since_mysql), trim($since_mysql)];
        $normalized_landing_keys = $this->normalize_landing_keys($landing_keys);

        if (!empty($normalized_landing_keys)) {
            $placeholders = implode(', ', array_fill(0, count($normalized_landing_keys), '%s'));
            $where .= " AND landing_page_key IN ({$placeholders})";
            $params = array_merge($params, $normalized_landing_keys);
        }

        $sql = "SELECT
                    landing_page_key AS landing_key,
                    COUNT(DISTINCT CASE WHEN transaction_id <> '' THEN transaction_id ELSE CONCAT('row_', id) END) AS total
                FROM {$table_name}
                WHERE {$where}
                GROUP BY landing_page_key";
        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, ...$params),
            ARRAY_A
        );

        return $this->rows_to_count_map($rows, 'landing_key');
    }

    private function rows_to_count_map($rows, string $key_field): array
    {
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $map = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = trim((string) ($row[$key_field] ?? ''));

            if ($key === '') {
                continue;
            }

            $map[$key] = (int) ($row['total'] ?? 0);
        }

        return $map;
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

    private function merge_landing_keys(array ...$sets): array
    {
        $merged = [];

        foreach ($sets as $set) {
            foreach ($set as $value) {
                $key = trim((string) $value);

                if ($key === '') {
                    continue;
                }

                $merged[] = $key;
            }
        }

        $merged = array_values(array_unique($merged));
        sort($merged, SORT_STRING);

        return $merged;
    }

    private function rate_percent(int $numerator, int $denominator): float
    {
        if ($denominator <= 0 || $numerator <= 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 2);
    }

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}

