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
        $engagement_table = $wpdb->prefix . 'kiwi_premium_sms_landing_engagements';
        $handoff_table = $wpdb->prefix . 'kiwi_landing_handoff_events';
        $sales_table = $wpdb->prefix . 'kiwi_sales';

        if (!$this->is_identifier($source_table)
            || !$this->is_identifier($summary_table)
            || !$this->is_identifier($engagement_table)
            || !$this->is_identifier($handoff_table)
            || !$this->is_identifier($sales_table)
        ) {
            return $this->failed_result(
                'main_summary_identifier_invalid',
                'Retention coverage gate could not verify main summary coverage because a table identifier was invalid.'
            );
        }

        $query = $wpdb->prepare(
            "WITH landing_loads AS (
                SELECT
                    DATE(created_at) AS metric_date,
                    landing_key,
                    session_token,
                    MIN(created_at) AS first_landing_at,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(service_key, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS service_key,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(provider_key, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS provider_key,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(flow_key, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS flow_key,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(country, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS country,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(pid, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS pid,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(tksource, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS tksource,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(NULLIF(device_brand, ''), '(unknown)') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS device_brand,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(NULLIF(os, ''), '(unknown)') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS os,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(NULLIF(os_version, ''), '(unknown)') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS os_version,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(NULLIF(browser, ''), '(unknown)') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS browser,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(NULLIF(client_ip_version, ''), '(unknown)') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS client_ip_version,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(NULLIF(client_ip_prefix, ''), '(unknown)') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS client_ip_prefix
                FROM {$source_table}
                WHERE created_at < %s
                  AND landing_key <> ''
                  AND session_token <> ''
                GROUP BY DATE(created_at), landing_key, session_token
             ),
             handoff_origin_events AS (
                SELECT
                    h.landing_key,
                    h.session_token,
                    DATE(MAX(ls.created_at)) AS metric_date,
                    h.event_type,
                    h.elapsed_ms,
                    MAX(h.created_at) AS handoff_created_at
                FROM {$handoff_table} h
                INNER JOIN {$source_table} ls
                  ON ls.landing_key = h.landing_key
                 AND ls.session_token = h.session_token
                 AND ls.created_at < DATE_ADD(DATE(%s), INTERVAL 1 DAY)
                 AND ls.created_at <= h.created_at
                 AND ls.landing_key <> ''
                 AND ls.session_token <> ''
                WHERE h.created_at < DATE_ADD(DATE(%s), INTERVAL 1 DAY)
                 AND h.landing_key <> ''
                 AND h.session_token <> ''
                GROUP BY h.id, h.landing_key, h.session_token, h.event_type, h.elapsed_ms
                HAVING handoff_created_at < DATE_ADD(metric_date, INTERVAL 2 DAY)
             ),
             handoff_by_session AS (
                SELECT
                    metric_date,
                    landing_key,
                    session_token,
                    SUM(CASE WHEN event_type = 'sms_handoff_attempted' THEN 1 ELSE 0 END) AS handoff_attempts,
                    SUM(CASE WHEN event_type = 'sms_handoff_hidden' THEN 1 ELSE 0 END) AS handoff_successes,
                    SUM(CASE WHEN event_type = 'sms_handoff_no_hide' THEN 1 ELSE 0 END) AS handoff_fails,
                    MIN(CASE WHEN event_type = 'sms_handoff_hidden' THEN ROUND(elapsed_ms / 1000, 2) ELSE NULL END) AS min_hidden_seconds,
                    MAX(CASE WHEN event_type = 'sms_handoff_hidden' THEN ROUND(elapsed_ms / 1000, 2) ELSE NULL END) AS max_hidden_seconds
                FROM handoff_origin_events
                GROUP BY metric_date, landing_key, session_token
             ),
             session_facts AS (
                SELECT
                    l.metric_date,
                    COALESCE(NULLIF(l.landing_key, ''), '(unknown)') AS landing_key,
                    l.service_key,
                    l.provider_key,
                    l.flow_key,
                    l.country,
                    l.pid,
                    l.tksource,
                    l.device_brand,
                    l.os,
                    l.os_version,
                    l.browser,
                    l.client_ip_version,
                    l.client_ip_prefix,
                    1 AS sessions,
                    CASE WHEN e.page_loaded_at IS NOT NULL THEN 1 ELSE 0 END AS page_loaded_sessions,
                    CASE WHEN e.first_cta1_click_at IS NOT NULL THEN 1 ELSE 0 END AS cta1_sessions,
                    COALESCE(e.cta1_click_count, 0) AS cta1_click_events,
                    CASE WHEN e.first_cta2_click_at IS NOT NULL THEN 1 ELSE 0 END AS cta2_sessions,
                    COALESCE(e.cta2_click_count, 0) AS cta2_click_events,
                    CASE WHEN e.first_cta3_click_at IS NOT NULL THEN 1 ELSE 0 END AS cta3_sessions,
                    COALESCE(e.cta3_click_count, 0) AS cta3_click_events,
                    COALESCE(h.handoff_attempts, 0) AS handoff_attempts,
                    COALESCE(h.handoff_successes, 0) AS handoff_successes,
                    COALESCE(h.handoff_fails, 0) AS handoff_fails,
                    h.min_hidden_seconds,
                    h.max_hidden_seconds,
                    0 AS sales,
                    0 AS sales_amount_minor
                FROM landing_loads l
                LEFT JOIN {$engagement_table} e
                  ON e.landing_key = l.landing_key
                 AND e.session_token = l.session_token
                 AND e.created_at >= l.metric_date
                 AND e.created_at < DATE_ADD(l.metric_date, INTERVAL 1 DAY)
                 AND e.landing_key <> ''
                 AND e.session_token <> ''
                LEFT JOIN handoff_by_session h
                  ON h.landing_key = l.landing_key
                 AND h.session_token = l.session_token
                 AND h.metric_date = l.metric_date
             ),
             sales_facts AS (
                SELECT
                    s.attribution_metric_date AS metric_date,
                    COALESCE(NULLIF(s.landing_key, ''), '(unknown)') AS landing_key,
                    COALESCE(NULLIF(s.service_key, ''), '(unknown)') AS service_key,
                    COALESCE(NULLIF(s.provider_key, ''), '(unknown)') AS provider_key,
                    COALESCE(NULLIF(s.flow_key, ''), '(unknown)') AS flow_key,
                    COALESCE(NULLIF(s.country, ''), '(unknown)') AS country,
                    COALESCE(NULLIF(s.pid, ''), '(unknown)') AS pid,
                    COALESCE(NULLIF(s.tksource, ''), '(unknown)') AS tksource,
                    COALESCE(NULLIF(s.device_brand, ''), '(unknown)') AS device_brand,
                    COALESCE(NULLIF(s.os, ''), '(unknown)') AS os,
                    COALESCE(NULLIF(s.os_version, ''), '(unknown)') AS os_version,
                    COALESCE(NULLIF(s.browser, ''), '(unknown)') AS browser,
                    COALESCE(NULLIF(s.client_ip_version, ''), '(unknown)') AS client_ip_version,
                    COALESCE(NULLIF(s.client_ip_prefix, ''), '(unknown)') AS client_ip_prefix,
                    0 AS sessions,
                    0 AS page_loaded_sessions,
                    0 AS cta1_sessions,
                    0 AS cta1_click_events,
                    0 AS cta2_sessions,
                    0 AS cta2_click_events,
                    0 AS cta3_sessions,
                    0 AS cta3_click_events,
                    0 AS handoff_attempts,
                    0 AS handoff_successes,
                    0 AS handoff_fails,
                    NULL AS min_hidden_seconds,
                    NULL AS max_hidden_seconds,
                    COUNT(*) AS sales,
                    COALESCE(SUM(s.amount_minor), 0) AS sales_amount_minor
                FROM {$sales_table} s
                WHERE s.status = 'completed'
                  AND s.attribution_metric_date < DATE(%s)
                GROUP BY
                    s.attribution_metric_date,
                    COALESCE(NULLIF(s.landing_key, ''), '(unknown)'),
                    COALESCE(NULLIF(s.service_key, ''), '(unknown)'),
                    COALESCE(NULLIF(s.provider_key, ''), '(unknown)'),
                    COALESCE(NULLIF(s.flow_key, ''), '(unknown)'),
                    COALESCE(NULLIF(s.country, ''), '(unknown)'),
                    COALESCE(NULLIF(s.pid, ''), '(unknown)'),
                    COALESCE(NULLIF(s.tksource, ''), '(unknown)'),
                    COALESCE(NULLIF(s.device_brand, ''), '(unknown)'),
                    COALESCE(NULLIF(s.os, ''), '(unknown)'),
                    COALESCE(NULLIF(s.os_version, ''), '(unknown)'),
                    COALESCE(NULLIF(s.browser, ''), '(unknown)'),
                    COALESCE(NULLIF(s.client_ip_version, ''), '(unknown)'),
                    COALESCE(NULLIF(s.client_ip_prefix, ''), '(unknown)')
             ),
             raw AS (
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
                    SUM(sessions) AS sessions,
                    SUM(page_loaded_sessions) AS page_loaded_sessions,
                    SUM(cta1_sessions) AS cta1_sessions,
                    SUM(cta1_click_events) AS cta1_click_events,
                    SUM(cta2_sessions) AS cta2_sessions,
                    SUM(cta2_click_events) AS cta2_click_events,
                    SUM(cta3_sessions) AS cta3_sessions,
                    SUM(cta3_click_events) AS cta3_click_events,
                    SUM(handoff_attempts) AS handoff_attempts,
                    SUM(handoff_successes) AS handoff_successes,
                    SUM(handoff_fails) AS handoff_fails,
                    CASE
                        WHEN SUM(handoff_attempts) <= 0 THEN 0
                        ELSE ROUND(SUM(handoff_successes) / SUM(handoff_attempts) * 100, 2)
                    END AS handoff_rate_pct,
                    MIN(min_hidden_seconds) AS min_hidden_seconds,
                    MAX(max_hidden_seconds) AS max_hidden_seconds,
                    SUM(sales) AS sales,
                    SUM(sales_amount_minor) AS sales_amount_minor
                FROM (
                    SELECT * FROM session_facts
                    UNION ALL
                    SELECT * FROM sales_facts
                ) all_facts
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
             ),
             summary AS (
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
                    SUM(sessions) AS sessions,
                    SUM(page_loaded_sessions) AS page_loaded_sessions,
                    SUM(cta1_sessions) AS cta1_sessions,
                    SUM(cta1_click_events) AS cta1_click_events,
                    SUM(cta2_sessions) AS cta2_sessions,
                    SUM(cta2_click_events) AS cta2_click_events,
                    SUM(cta3_sessions) AS cta3_sessions,
                    SUM(cta3_click_events) AS cta3_click_events,
                    SUM(handoff_attempts) AS handoff_attempts,
                    SUM(handoff_successes) AS handoff_successes,
                    SUM(handoff_fails) AS handoff_fails,
                    MAX(handoff_rate_pct) AS handoff_rate_pct,
                    MIN(min_hidden_seconds) AS min_hidden_seconds,
                    MAX(max_hidden_seconds) AS max_hidden_seconds,
                    SUM(sales) AS sales,
                    SUM(sales_amount_minor) AS sales_amount_minor
                FROM {$summary_table}
                WHERE metric_date < DATE(%s)
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
             )
             SELECT raw.metric_date
             FROM raw
             LEFT JOIN summary ON summary.metric_date = raw.metric_date
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
                AND summary.page_loaded_sessions = raw.page_loaded_sessions
                AND summary.cta1_sessions = raw.cta1_sessions
                AND summary.cta1_click_events = raw.cta1_click_events
                AND summary.cta2_sessions = raw.cta2_sessions
                AND summary.cta2_click_events = raw.cta2_click_events
                AND summary.cta3_sessions = raw.cta3_sessions
                AND summary.cta3_click_events = raw.cta3_click_events
                AND summary.handoff_attempts = raw.handoff_attempts
                AND summary.handoff_successes = raw.handoff_successes
                AND summary.handoff_fails = raw.handoff_fails
                AND summary.handoff_rate_pct = raw.handoff_rate_pct
                AND summary.min_hidden_seconds <=> raw.min_hidden_seconds
                AND summary.max_hidden_seconds <=> raw.max_hidden_seconds
                AND summary.sales = raw.sales
                AND summary.sales_amount_minor = raw.sales_amount_minor
             WHERE summary.metric_date IS NULL
             GROUP BY raw.metric_date
             ORDER BY raw.metric_date ASC",
            $cutoff_value,
            $cutoff_value,
            $cutoff_value,
            $cutoff_value,
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
        $engagement_table = $wpdb->prefix . 'kiwi_premium_sms_landing_engagements';
        $handoff_table = $wpdb->prefix . 'kiwi_landing_handoff_events';
        $sales_table = $wpdb->prefix . 'kiwi_sales';

        if (!$this->is_identifier($source_table)
            || !$this->is_identifier($summary_table)
            || !$this->is_identifier($engagement_table)
            || !$this->is_identifier($handoff_table)
            || !$this->is_identifier($sales_table)
        ) {
            return $this->failed_result(
                'tkzone_summary_identifier_invalid',
                'Retention coverage gate could not verify TK zone summary coverage because a table identifier was invalid.'
            );
        }

        $placeholders = implode(', ', array_fill(0, count($pids), '%s'));
        $pid_set_hash = $this->config->get_landing_funnel_tkzone_summary_pid_set_hash();
        $params = array_merge([$cutoff_value], $pids, [$cutoff_value], $pids, [$pid_set_hash, $cutoff_value]);
        $query = $wpdb->prepare(
            "WITH landing_loads AS (
                SELECT
                    DATE(created_at) AS metric_date,
                    landing_key,
                    session_token,
                    MIN(created_at) AS first_landing_at,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(provider_key, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS provider_key,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(flow_key, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS flow_key,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(country, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS country,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(service_key, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS service_key,
                    COALESCE(NULLIF(landing_key, ''), '(unknown)') AS landing_key_normalized,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(tksource, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS tksource,
                    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(tkzone, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1), ''), '(unknown)') AS tkzone
                FROM {$source_table}
                WHERE created_at < %s
                  AND pid IN ({$placeholders})
                  AND landing_key <> ''
                  AND session_token <> ''
                GROUP BY DATE(created_at), landing_key, session_token
             ),
             handoff_by_session AS (
                SELECT
                    l.metric_date,
                    l.landing_key,
                    l.session_token,
                    SUM(CASE WHEN h.event_type = 'sms_handoff_attempted' THEN 1 ELSE 0 END) AS handoff_attempts,
                    SUM(CASE WHEN h.event_type = 'sms_handoff_hidden' THEN 1 ELSE 0 END) AS handoff_successes,
                    SUM(CASE WHEN h.event_type = 'sms_handoff_no_hide' THEN 1 ELSE 0 END) AS handoff_fails
                FROM landing_loads l
                INNER JOIN {$handoff_table} h
                  ON h.landing_key = l.landing_key
                 AND h.session_token = l.session_token
                 AND h.created_at >= l.metric_date
                 AND h.created_at < DATE_ADD(l.metric_date, INTERVAL 2 DAY)
                 AND h.landing_key <> ''
                 AND h.session_token <> ''
                GROUP BY l.metric_date, l.landing_key, l.session_token
             ),
             session_facts AS (
                SELECT
                    l.metric_date,
                    l.provider_key,
                    l.flow_key,
                    l.country,
                    l.service_key,
                    l.landing_key_normalized AS landing_key,
                    l.tksource,
                    l.tkzone,
                    1 AS sessions,
                    CASE WHEN e.page_loaded_at IS NOT NULL THEN 1 ELSE 0 END AS page_loaded_sessions,
                    CASE WHEN e.first_cta1_click_at IS NOT NULL THEN 1 ELSE 0 END AS cta1_sessions,
                    COALESCE(e.cta1_click_count, 0) AS cta1_click_events,
                    CASE WHEN e.first_cta2_click_at IS NOT NULL THEN 1 ELSE 0 END AS cta2_sessions,
                    COALESCE(e.cta2_click_count, 0) AS cta2_click_events,
                    CASE WHEN e.first_cta3_click_at IS NOT NULL THEN 1 ELSE 0 END AS cta3_sessions,
                    COALESCE(e.cta3_click_count, 0) AS cta3_click_events,
                    COALESCE(h.handoff_attempts, 0) AS handoff_attempts,
                    COALESCE(h.handoff_successes, 0) AS handoff_successes,
                    COALESCE(h.handoff_fails, 0) AS handoff_fails,
                    0 AS sales,
                    0 AS sales_amount_minor
                FROM landing_loads l
                LEFT JOIN {$engagement_table} e
                  ON e.landing_key = l.landing_key
                 AND e.session_token = l.session_token
                 AND e.created_at >= l.metric_date
                 AND e.created_at < DATE_ADD(l.metric_date, INTERVAL 1 DAY)
                 AND e.landing_key <> ''
                 AND e.session_token <> ''
                LEFT JOIN handoff_by_session h
                  ON h.landing_key = l.landing_key
                 AND h.session_token = l.session_token
                 AND h.metric_date = l.metric_date
             ),
             sales_facts AS (
                SELECT
                    s.attribution_metric_date AS metric_date,
                    COALESCE(NULLIF(s.provider_key, ''), '(unknown)') AS provider_key,
                    COALESCE(NULLIF(s.flow_key, ''), '(unknown)') AS flow_key,
                    COALESCE(NULLIF(s.country, ''), '(unknown)') AS country,
                    COALESCE(NULLIF(s.service_key, ''), '(unknown)') AS service_key,
                    COALESCE(NULLIF(s.landing_key, ''), '(unknown)') AS landing_key,
                    COALESCE(NULLIF(s.tksource, ''), '(unknown)') AS tksource,
                    COALESCE(NULLIF(s.tkzone, ''), '(unknown)') AS tkzone,
                    0 AS sessions,
                    0 AS page_loaded_sessions,
                    0 AS cta1_sessions,
                    0 AS cta1_click_events,
                    0 AS cta2_sessions,
                    0 AS cta2_click_events,
                    0 AS cta3_sessions,
                    0 AS cta3_click_events,
                    0 AS handoff_attempts,
                    0 AS handoff_successes,
                    0 AS handoff_fails,
                    COUNT(*) AS sales,
                    COALESCE(SUM(s.amount_minor), 0) AS sales_amount_minor
                FROM {$sales_table} s
                WHERE s.status = 'completed'
                  AND s.attribution_metric_date < DATE(%s)
                  AND s.pid IN ({$placeholders})
                GROUP BY
                    s.attribution_metric_date,
                    COALESCE(NULLIF(s.provider_key, ''), '(unknown)'),
                    COALESCE(NULLIF(s.flow_key, ''), '(unknown)'),
                    COALESCE(NULLIF(s.country, ''), '(unknown)'),
                    COALESCE(NULLIF(s.service_key, ''), '(unknown)'),
                    COALESCE(NULLIF(s.landing_key, ''), '(unknown)'),
                    COALESCE(NULLIF(s.tksource, ''), '(unknown)'),
                    COALESCE(NULLIF(s.tkzone, ''), '(unknown)')
             ),
             raw AS (
                SELECT
                    metric_date,
                    provider_key,
                    flow_key,
                    country,
                    service_key,
                    landing_key,
                    tksource,
                    tkzone,
                    SUM(sessions) AS sessions,
                    SUM(page_loaded_sessions) AS page_loaded_sessions,
                    SUM(cta1_sessions) AS cta1_sessions,
                    SUM(cta1_click_events) AS cta1_click_events,
                    SUM(cta2_sessions) AS cta2_sessions,
                    SUM(cta2_click_events) AS cta2_click_events,
                    SUM(cta3_sessions) AS cta3_sessions,
                    SUM(cta3_click_events) AS cta3_click_events,
                    SUM(handoff_attempts) AS handoff_attempts,
                    SUM(handoff_successes) AS handoff_successes,
                    SUM(handoff_fails) AS handoff_fails,
                    CASE
                        WHEN SUM(handoff_attempts) <= 0 THEN 0
                        ELSE ROUND(SUM(handoff_successes) / SUM(handoff_attempts) * 100, 2)
                    END AS handoff_rate_pct,
                    SUM(sales) AS sales,
                    SUM(sales_amount_minor) AS sales_amount_minor
                FROM (
                    SELECT * FROM session_facts
                    UNION ALL
                    SELECT * FROM sales_facts
                ) all_facts
                GROUP BY
                    metric_date,
                    provider_key,
                    flow_key,
                    country,
                    service_key,
                    landing_key,
                    tksource,
                    tkzone
             ),
             summary AS (
                    SELECT
                        metric_date,
                        provider_key,
                        flow_key,
                        country,
                        service_key,
                        landing_key,
                        tksource,
                        tkzone,
                        SUM(sessions) AS sessions,
                        SUM(page_loaded_sessions) AS page_loaded_sessions,
                        SUM(cta1_sessions) AS cta1_sessions,
                        SUM(cta1_click_events) AS cta1_click_events,
                        SUM(cta2_sessions) AS cta2_sessions,
                        SUM(cta2_click_events) AS cta2_click_events,
                        SUM(cta3_sessions) AS cta3_sessions,
                        SUM(cta3_click_events) AS cta3_click_events,
                        SUM(handoff_attempts) AS handoff_attempts,
                        SUM(handoff_successes) AS handoff_successes,
                        SUM(handoff_fails) AS handoff_fails,
                        MAX(handoff_rate_pct) AS handoff_rate_pct,
                        SUM(sales) AS sales,
                        SUM(sales_amount_minor) AS sales_amount_minor
                    FROM {$summary_table}
                    WHERE pid_set_hash = %s
                      AND metric_date < DATE(%s)
                    GROUP BY
                        metric_date,
                        provider_key,
                        flow_key,
                        country,
                        service_key,
                        landing_key,
                        tksource,
                        tkzone
             )
             SELECT raw.metric_date
             FROM raw
             LEFT JOIN summary ON summary.metric_date = raw.metric_date
                     AND summary.provider_key = raw.provider_key
                     AND summary.flow_key = raw.flow_key
                     AND summary.country = raw.country
                    AND summary.service_key = raw.service_key
                    AND summary.landing_key = raw.landing_key
                     AND summary.tksource = raw.tksource
                     AND summary.tkzone = raw.tkzone
                     AND summary.sessions = raw.sessions
                     AND summary.page_loaded_sessions = raw.page_loaded_sessions
                     AND summary.cta1_sessions = raw.cta1_sessions
                     AND summary.cta1_click_events = raw.cta1_click_events
                     AND summary.cta2_sessions = raw.cta2_sessions
                     AND summary.cta2_click_events = raw.cta2_click_events
                     AND summary.cta3_sessions = raw.cta3_sessions
                     AND summary.cta3_click_events = raw.cta3_click_events
                     AND summary.handoff_attempts = raw.handoff_attempts
                     AND summary.handoff_successes = raw.handoff_successes
                     AND summary.handoff_fails = raw.handoff_fails
                     AND summary.handoff_rate_pct = raw.handoff_rate_pct
                     AND summary.sales = raw.sales
                     AND summary.sales_amount_minor = raw.sales_amount_minor
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
