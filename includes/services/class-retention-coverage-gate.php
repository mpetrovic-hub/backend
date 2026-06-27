<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Retention_Coverage_Gate
{
    private $config;

    public function __construct(?Kiwi_Config $config = null)
    {
        $this->config = $config instanceof Kiwi_Config ? $config : new Kiwi_Config();
    }

    public function check_landing_page_sessions(array $source, string $cutoff_value): array
    {
        $accepted_dates = array_fill_keys((array) ($source['accepted_missing_metric_dates'] ?? []), true);
        $main_result = $this->find_missing_main_summary_dates($source, $cutoff_value);
        $tkzone_result = $this->find_missing_tkzone_summary_dates($source, $cutoff_value);
        $main_missing = (array) ($main_result['missing_dates'] ?? []);
        $tkzone_missing = (array) ($tkzone_result['missing_dates'] ?? []);
        $accepted_main = $this->filter_accepted_dates($main_missing, $accepted_dates);
        $accepted_tkzone = $this->filter_accepted_dates($tkzone_missing, $accepted_dates);
        $blocked_main = $this->filter_blocking_dates($main_missing, $accepted_dates);
        $blocked_tkzone = $this->filter_blocking_dates($tkzone_missing, $accepted_dates);
        $blocking_errors = [];

        if (empty($main_result['ok'])) {
            $blocking_errors[] = (string) ($main_result['error_code'] ?? 'main_summary_coverage_failed');
        }

        if (empty($tkzone_result['ok'])) {
            $blocking_errors[] = (string) ($tkzone_result['error_code'] ?? 'tkzone_summary_coverage_failed');
        }

        $passed = empty($blocked_main) && empty($blocked_tkzone) && empty($blocking_errors);

        return [
            'status' => $passed ? 'passed' : 'failed',
            'blocking_errors' => $blocking_errors,
            'main_summary' => $this->build_summary_result($main_result, $main_missing, $accepted_main, $blocked_main),
            'tkzone_summary' => $this->build_summary_result($tkzone_result, $tkzone_missing, $accepted_tkzone, $blocked_tkzone),
        ];
    }

    protected function find_missing_main_summary_dates(array $source, string $cutoff_value): array
    {
        global $wpdb;

        $source_table = (string) ($source['source_table'] ?? '');
        $summary_table = $wpdb->prefix . 'kiwi_landing_funnel_daily_summary';

        if (!$this->is_identifier($source_table) || !$this->is_identifier($summary_table)) {
            return $this->failed_result(
                'main_summary_identifier_invalid',
                'Retention coverage gate could not verify main summary coverage because a table identifier was invalid.'
            );
        }

        $query = $wpdb->prepare(
            "SELECT raw.metric_date
             FROM (
                SELECT
                    landed.metric_date,
                    landed.landing_key,
                    landed.service_key,
                    landed.provider_key,
                    landed.flow_key,
                    landed.country,
                    landed.pid,
                    landed.tksource,
                    landed.device_brand,
                    landed.os,
                    landed.os_version,
                    landed.browser,
                    landed.client_ip_version,
                    landed.client_ip_prefix,
                    COUNT(*) AS sessions
                FROM (
                    SELECT
                        DATE(created_at) AS metric_date,
                        COALESCE(NULLIF(landing_key, ''), '(unknown)') AS landing_key,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(service_key, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS service_key,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(provider_key, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS provider_key,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(flow_key, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS flow_key,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(country, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS country,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(pid, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS pid,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(tksource, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS tksource,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(device_brand, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS device_brand,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(os, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS os,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(os_version, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS os_version,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(browser, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS browser,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(client_ip_version, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS client_ip_version,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(client_ip_prefix, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS client_ip_prefix
                    FROM {$source_table}
                    WHERE created_at < %s
                      AND landing_key <> ''
                      AND session_token <> ''
                    GROUP BY DATE(created_at), landing_key, session_token
                ) landed
                GROUP BY
                    landed.metric_date,
                    landed.landing_key,
                    landed.service_key,
                    landed.provider_key,
                    landed.flow_key,
                    landed.country,
                    landed.pid,
                    landed.tksource,
                    landed.device_brand,
                    landed.os,
                    landed.os_version,
                    landed.browser,
                    landed.client_ip_version,
                    landed.client_ip_prefix
             ) raw
             LEFT JOIN (
                SELECT
                    metric_date,
                    landing_key,
                    service_key,
                    provider_key,
                    flow_key,
                    country,
                    pid,
                    tksource,
                    device_brand,
                    os,
                    os_version,
                    browser,
                    client_ip_version,
                    client_ip_prefix,
                    SUM(sessions) AS sessions
                FROM {$summary_table}
                GROUP BY
                    metric_date,
                    landing_key,
                    service_key,
                    provider_key,
                    flow_key,
                    country,
                    pid,
                    tksource,
                    device_brand,
                    os,
                    os_version,
                    browser,
                    client_ip_version,
                    client_ip_prefix
             ) summary ON summary.metric_date = raw.metric_date
                AND summary.landing_key = raw.landing_key
                AND summary.service_key = raw.service_key
                AND summary.provider_key = raw.provider_key
                AND summary.flow_key = raw.flow_key
                AND summary.country = raw.country
                AND summary.pid = raw.pid
                AND summary.tksource = raw.tksource
                AND summary.device_brand = raw.device_brand
                AND summary.os = raw.os
                AND summary.os_version = raw.os_version
                AND summary.browser = raw.browser
                AND summary.client_ip_version = raw.client_ip_version
                AND summary.client_ip_prefix = raw.client_ip_prefix
                AND summary.sessions = raw.sessions
             WHERE summary.metric_date IS NULL
             GROUP BY raw.metric_date
             ORDER BY raw.metric_date ASC",
            $cutoff_value
        );

        if ($query === false) {
            return $this->failed_result(
                'main_summary_query_prepare_failed',
                'Retention coverage gate could not prepare the main summary coverage query.'
            );
        }

        $rows = $wpdb->get_results($query, ARRAY_A);

        return $this->metric_dates_result(
            $rows,
            'main_summary_query_failed',
            'Retention coverage gate could not verify main summary coverage because the query failed.'
        );
    }

    protected function find_missing_tkzone_summary_dates(array $source, string $cutoff_value): array
    {
        global $wpdb;

        $pids = $this->config->get_landing_funnel_tkzone_summary_pids();

        if (empty($pids)) {
            return $this->successful_result([]);
        }

        $source_table = (string) ($source['source_table'] ?? '');
        $summary_table = $wpdb->prefix . 'kiwi_landing_funnel_daily_tkzone_summary';

        if (!$this->is_identifier($source_table) || !$this->is_identifier($summary_table)) {
            return $this->failed_result(
                'tkzone_summary_identifier_invalid',
                'Retention coverage gate could not verify TK zone summary coverage because a table identifier was invalid.'
            );
        }

        $placeholders = implode(', ', array_fill(0, count($pids), '%s'));
        $pid_set_hash = $this->config->get_landing_funnel_tkzone_summary_pid_set_hash();
        $params = array_merge([$cutoff_value], $pids, [$pid_set_hash]);
        $query = $wpdb->prepare(
            "SELECT raw.metric_date
             FROM (
                SELECT
                    landed.metric_date,
                    landed.provider_key,
                    landed.flow_key,
                    landed.country,
                    landed.service_key,
                    landed.landing_key,
                    landed.tksource,
                    landed.tkzone,
                    COUNT(*) AS sessions
                FROM (
                    SELECT
                        DATE(created_at) AS metric_date,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(provider_key, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS provider_key,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(flow_key, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS flow_key,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(country, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS country,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(service_key, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS service_key,
                        COALESCE(NULLIF(landing_key, ''), '(unknown)') AS landing_key,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(tksource, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS tksource,
                        COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(tkzone, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS tkzone
                    FROM {$source_table}
                    WHERE created_at < %s
                      AND pid IN ({$placeholders})
                      AND landing_key <> ''
                      AND session_token <> ''
                    GROUP BY DATE(created_at), landing_key, session_token
                ) landed
                GROUP BY
                    landed.metric_date,
                    landed.provider_key,
                    landed.flow_key,
                    landed.country,
                    landed.service_key,
                    landed.landing_key,
                    landed.tksource,
                    landed.tkzone
                 ) raw
                 LEFT JOIN (
                    SELECT
                        metric_date,
                        provider_key,
                        flow_key,
                        country,
                        service_key,
                        landing_key,
                        tksource,
                        tkzone,
                        SUM(sessions) AS sessions
                    FROM {$summary_table}
                    WHERE pid_set_hash = %s
                    GROUP BY
                        metric_date,
                        provider_key,
                        flow_key,
                        country,
                        service_key,
                        landing_key,
                        tksource,
                        tkzone
                 ) summary ON summary.metric_date = raw.metric_date
                    AND summary.provider_key = raw.provider_key
                    AND summary.flow_key = raw.flow_key
                    AND summary.country = raw.country
                    AND summary.service_key = raw.service_key
                    AND summary.landing_key = raw.landing_key
                    AND summary.tksource = raw.tksource
                    AND summary.tkzone = raw.tkzone
                    AND summary.sessions = raw.sessions
                 WHERE summary.metric_date IS NULL
                 GROUP BY raw.metric_date
                 ORDER BY raw.metric_date ASC",
            ...$params
        );

        if ($query === false) {
            return $this->failed_result(
                'tkzone_summary_query_prepare_failed',
                'Retention coverage gate could not prepare the TK zone summary coverage query.'
            );
        }

        $rows = $wpdb->get_results($query, ARRAY_A);

        return $this->metric_dates_result(
            $rows,
            'tkzone_summary_query_failed',
            'Retention coverage gate could not verify TK zone summary coverage because the query failed.'
        );
    }

    private function filter_accepted_dates(array $dates, array $accepted_dates): array
    {
        return array_values(array_filter($dates, static function (string $date) use ($accepted_dates): bool {
            return isset($accepted_dates[$date]);
        }));
    }

    private function filter_blocking_dates(array $dates, array $accepted_dates): array
    {
        return array_values(array_filter($dates, static function (string $date) use ($accepted_dates): bool {
            return !isset($accepted_dates[$date]);
        }));
    }

    private function build_summary_result(
        array $result,
        array $missing_dates,
        array $accepted_missing_dates,
        array $blocking_missing_dates
    ): array {
        $summary = [
            'status' => !empty($result['ok']) && empty($blocking_missing_dates) ? 'passed' : 'failed',
            'missing_dates' => $missing_dates,
            'accepted_missing_dates' => $accepted_missing_dates,
            'blocking_missing_dates' => $blocking_missing_dates,
        ];

        if (empty($result['ok'])) {
            $summary['error_code'] = (string) ($result['error_code'] ?? 'coverage_query_failed');
            $summary['error_message'] = (string) ($result['error_message'] ?? 'Retention coverage gate failed.');
        }

        return $summary;
    }

    private function metric_dates_result($rows, string $error_code, string $error_message): array
    {
        if (!is_array($rows)) {
            return $this->failed_result($error_code, $this->db_error_message($error_message));
        }

        return $this->successful_result($this->pluck_metric_dates($rows));
    }

    private function pluck_metric_dates(array $rows): array
    {
        $dates = [];

        foreach ($rows as $row) {
            $date = is_array($row) ? (string) ($row['metric_date'] ?? '') : '';

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $dates[] = $date;
            }
        }

        return array_values(array_unique($dates));
    }

    private function successful_result(array $missing_dates): array
    {
        return [
            'ok' => true,
            'missing_dates' => $missing_dates,
        ];
    }

    private function failed_result(string $error_code, string $error_message): array
    {
        return [
            'ok' => false,
            'missing_dates' => [],
            'error_code' => $error_code,
            'error_message' => $error_message,
        ];
    }

    private function db_error_message(string $fallback): string
    {
        global $wpdb;

        $last_error = isset($wpdb->last_error) ? trim((string) $wpdb->last_error) : '';

        return $last_error !== '' ? $fallback . ' Database error: ' . $last_error : $fallback;
    }

    private function is_identifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1;
    }
}
