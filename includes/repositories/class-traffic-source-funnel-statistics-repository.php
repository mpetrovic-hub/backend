<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Traffic_Source_Funnel_Statistics_Repository implements Kiwi_Statistics_Read_Repository_Interface
{
    public const DEFAULT_FROM = '2026-05-12 20:00:00';

    private $last_error = '';

    public function create_table(): void
    {
        if ($this->create_view()) {
            return;
        }

        $error_message = sprintf(
            "Failed to create traffic-source funnel statistics view '%s': %s",
            $this->get_view_name(),
            $this->get_last_error() !== '' ? $this->get_last_error() : 'unknown database error'
        );

        error_log($error_message);
    }

    public function create_view(): bool
    {
        global $wpdb;

        $this->last_error = '';
        $wpdb->query($this->build_create_view_sql());

        if ($this->has_database_error()) {
            return false;
        }

        $wpdb->query($this->build_create_one_for_all_view_sql());

        return !$this->has_database_error();
    }

    public function get_rows(array $filters = [], int $limit = 100): array
    {
        global $wpdb;

        $this->last_error = '';
        $limit = max(1, min(500, $limit));
        $where_sql = ['metric_at >= %s'];
        $params = [$this->normalize_datetime((string) ($filters['from'] ?? self::DEFAULT_FROM), self::DEFAULT_FROM)];

        $to = $this->normalize_optional_datetime((string) ($filters['to'] ?? ''));
        if ($to !== '') {
            $where_sql[] = 'metric_at <= %s';
            $params[] = $to;
        }

        $service_key = $this->normalize_filter_value((string) ($filters['service_key'] ?? ''));
        if ($service_key !== '') {
            $where_sql[] = 'service_key = %s';
            $params[] = $service_key;
        }

        $tksource = $this->normalize_filter_value((string) ($filters['tksource'] ?? ''));
        if ($tksource !== '') {
            $where_sql[] = 'tksource = %s';
            $params[] = $tksource;
        }

        $params[] = $limit;
        $where_clause = implode(' AND ', $where_sql);
        $view_name = $this->get_view_name();

        $sql = "WITH filtered AS (
                    SELECT *
                    FROM {$view_name}
                    WHERE {$where_clause}
                ),
                summary AS (
                    SELECT
                        service_key,
                        tksource,
                        tkzone,
                        SUM(session_count) AS sessions,
                        SUM(loaded_session) AS loaded_sessions,
                        SUM(cta_session) AS cta_sessions,
                        SUM(cta_click_events) AS cta_click_events,
                        ROUND(SUM(cta_session) / NULLIF(SUM(loaded_session), 0) * 100, 2) AS cta_session_cr,
                        ROUND(AVG(seconds_load_to_cta), 2) AS avg_seconds_load_to_cta,
                        MIN(seconds_load_to_cta) AS min_seconds_load_to_cta,
                        MAX(seconds_load_to_cta) AS max_seconds_load_to_cta,
                        COUNT(DISTINCT sale_id) AS successful_sales,
                        COALESCE(SUM(successful_sale_amount_minor), 0) AS successful_sales_amount_minor,
                        GROUP_CONCAT(DISTINCT sale_id ORDER BY sale_completed_at SEPARATOR ', ') AS successful_sale_ids,
                        GROUP_CONCAT(DISTINCT successful_transaction_id ORDER BY sale_completed_at SEPARATOR ', ') AS successful_transaction_ids
                    FROM filtered
                    GROUP BY service_key, tksource, tkzone
                ),
                ranked AS (
                    SELECT
                        service_key,
                        tksource,
                        tkzone,
                        seconds_load_to_cta,
                        ROW_NUMBER() OVER (
                            PARTITION BY service_key, tksource, tkzone
                            ORDER BY seconds_load_to_cta
                        ) AS rn,
                        COUNT(*) OVER (
                            PARTITION BY service_key, tksource, tkzone
                        ) AS cnt
                    FROM filtered
                    WHERE seconds_load_to_cta IS NOT NULL
                ),
                median AS (
                    SELECT
                        service_key,
                        tksource,
                        tkzone,
                        ROUND(AVG(
                            CASE
                                WHEN rn IN (FLOOR((cnt + 1) / 2), FLOOR((cnt + 2) / 2))
                                THEN seconds_load_to_cta
                                ELSE NULL
                            END
                        ), 2) AS median_seconds_load_to_cta
                    FROM ranked
                    GROUP BY service_key, tksource, tkzone
                )
                SELECT
                    s.service_key,
                    s.tksource,
                    s.tkzone,
                    COALESCE(s.sessions, 0) AS sessions,
                    COALESCE(s.loaded_sessions, 0) AS loaded_sessions,
                    COALESCE(s.cta_sessions, 0) AS cta_sessions,
                    COALESCE(s.cta_click_events, 0) AS cta_click_events,
                    s.cta_session_cr,
                    s.avg_seconds_load_to_cta,
                    m.median_seconds_load_to_cta,
                    s.min_seconds_load_to_cta,
                    s.max_seconds_load_to_cta,
                    COALESCE(s.successful_sales, 0) AS successful_sales,
                    COALESCE(s.successful_sales_amount_minor, 0) AS successful_sales_amount_minor,
                    ROUND(COALESCE(s.successful_sales, 0) / NULLIF(s.sessions, 0) * 100, 2) AS sales_per_session_cr,
                    ROUND(COALESCE(s.successful_sales, 0) / NULLIF(s.cta_sessions, 0) * 100, 2) AS sales_per_cta_session_cr,
                    COALESCE(s.successful_sale_ids, '') AS successful_sale_ids,
                    COALESCE(s.successful_transaction_ids, '') AS successful_transaction_ids
                FROM summary s
                LEFT JOIN median m
                  ON m.service_key = s.service_key
                 AND m.tksource = s.tksource
                 AND m.tkzone = s.tkzone
                ORDER BY sessions DESC, cta_sessions DESC, successful_sales DESC, service_key ASC, tksource ASC, tkzone ASC
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
        return [
            'service_keys' => $this->get_distinct_filter_values('service_key', $filters),
            'tksources' => $this->get_distinct_filter_values('tksource', $filters),
        ];
    }

    public function get_last_error(): string
    {
        return $this->last_error;
    }

    public function get_view_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_v_load_to_cta_by_tksource_tkzone';
    }

    public function get_source_name(): string
    {
        return $this->get_view_name();
    }

    public function get_one_for_all_view_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_v_one_for_all';
    }

    public function get_default_from(): string
    {
        return self::DEFAULT_FROM;
    }

    public function normalize_filters(array $filters): array
    {
        return [
            'from' => $this->normalize_datetime((string) ($filters['from'] ?? self::DEFAULT_FROM), self::DEFAULT_FROM),
            'to' => $this->normalize_optional_datetime((string) ($filters['to'] ?? '')),
            'service_key' => $this->normalize_filter_value((string) ($filters['service_key'] ?? '')),
            'tksource' => $this->normalize_filter_value((string) ($filters['tksource'] ?? '')),
            'limit' => max(1, min(500, (int) ($filters['limit'] ?? 100))),
        ];
    }

    private function get_distinct_filter_values(string $field, array $filters): array
    {
        global $wpdb;

        if (!in_array($field, ['service_key', 'tksource'], true)) {
            return [];
        }

        $this->last_error = '';
        $where_sql = ['metric_at >= %s'];
        $params = [$this->normalize_datetime((string) ($filters['from'] ?? self::DEFAULT_FROM), self::DEFAULT_FROM)];

        $to = $this->normalize_optional_datetime((string) ($filters['to'] ?? ''));
        if ($to !== '') {
            $where_sql[] = 'metric_at <= %s';
            $params[] = $to;
        }

        $view_name = $this->get_view_name();
        $where_clause = implode(' AND ', $where_sql);
        $sql = "SELECT DISTINCT {$field}
                FROM {$view_name}
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

    private function build_create_view_sql(): string
    {
        global $wpdb;

        $view_name = $this->get_view_name();
        $engagement_table = $wpdb->prefix . 'kiwi_premium_sms_landing_engagements';
        $click_attribution_table = $wpdb->prefix . 'kiwi_click_attributions';
        $sales_table = $wpdb->prefix . 'kiwi_sales';
        $default_from = self::DEFAULT_FROM;

        return "CREATE OR REPLACE VIEW {$view_name} AS
            SELECT
                e.created_at AS metric_at,
                COALESCE(NULLIF(e.service_key, ''), '(empty)') AS service_key,
                COALESCE(NULLIF(e.tksource, ''), '(empty)') AS tksource,
                COALESCE(NULLIF(e.tkzone, ''), '(empty)') AS tkzone,
                1 AS session_count,
                CASE WHEN e.page_loaded_at IS NOT NULL THEN 1 ELSE 0 END AS loaded_session,
                CASE WHEN e.first_cta_click_at IS NOT NULL THEN 1 ELSE 0 END AS cta_session,
                e.cta_click_count AS cta_click_events,
                CASE
                    WHEN e.page_loaded_at IS NOT NULL
                     AND e.first_cta_click_at IS NOT NULL
                    THEN TIMESTAMPDIFF(SECOND, e.page_loaded_at, e.first_cta_click_at)
                    ELSE NULL
                END AS seconds_load_to_cta,
                NULL AS sale_id,
                0 AS successful_sale_amount_minor,
                NULL AS successful_transaction_id,
                NULL AS sale_completed_at
            FROM {$engagement_table} e
            WHERE e.created_at >= '{$default_from}'
            UNION ALL
            SELECT
                s.completed_at AS metric_at,
                COALESCE(NULLIF(s.service_key, ''), NULLIF(ca.service_key, ''), '(empty)') AS service_key,
                COALESCE(NULLIF(s.tksource, ''), NULLIF(ca.tksource, ''), '(empty)') AS tksource,
                COALESCE(NULLIF(s.tkzone, ''), NULLIF(ca.tkzone, ''), '(empty)') AS tkzone,
                0 AS session_count,
                0 AS loaded_session,
                0 AS cta_session,
                0 AS cta_click_events,
                NULL AS seconds_load_to_cta,
                s.id AS sale_id,
                s.amount_minor AS successful_sale_amount_minor,
                s.transaction_id AS successful_transaction_id,
                s.completed_at AS sale_completed_at
            FROM {$sales_table} s
            LEFT JOIN (
                SELECT *
                FROM (
                    SELECT
                        ca.*,
                        ROW_NUMBER() OVER (
                            PARTITION BY ca.transaction_id
                            ORDER BY ca.conversion_confirmed_at DESC, ca.updated_at DESC, ca.id DESC
                        ) AS kiwi_attribution_rank
                    FROM {$click_attribution_table} ca
                    WHERE ca.transaction_id <> ''
                ) ranked_ca
                WHERE ranked_ca.kiwi_attribution_rank = 1
            ) ca
              ON ca.transaction_id = s.transaction_id
            WHERE s.status = 'completed'
              AND s.completed_at >= '{$default_from}'";
    }

    private function build_create_one_for_all_view_sql(): string
    {
        global $wpdb;

        $view_name = $this->get_one_for_all_view_name();
        $landing_session_table = $wpdb->prefix . 'kiwi_landing_page_sessions';
        $engagement_table = $wpdb->prefix . 'kiwi_premium_sms_landing_engagements';
        $handoff_table = $wpdb->prefix . 'kiwi_landing_handoff_events';
        $click_attribution_table = $wpdb->prefix . 'kiwi_click_attributions';
        $sales_table = $wpdb->prefix . 'kiwi_sales';
        $default_from = self::DEFAULT_FROM;
        $device_brand_expression = $this->build_device_brand_case_expression('ua_ch_model', 'raw_user_agent');

        return "CREATE OR REPLACE VIEW {$view_name} AS
            WITH landing_loads AS (
                SELECT
                    landing_key,
                    session_token,
                    MIN(created_at) AS metric_at,
                    MAX(service_key) AS service_key,
                    MAX(user_agent) AS user_agent,
                    COUNT(*) AS landing_page_aufrufe
                FROM {$landing_session_table}
                WHERE created_at >= '{$default_from}'
                GROUP BY landing_key, session_token
            ),
            engagement_sessions AS (
                SELECT
                    landing_key,
                    session_token,
                    created_at,
                    service_key,
                    tksource,
                    tkzone,
                    page_loaded_at,
                    first_cta_click_at,
                    cta_click_count,
                    ua_ch_platform,
                    ua_ch_platform_version,
                    ua_ch_model,
                    ua_ch_brands,
                    ua_ch_full_version_list,
                    user_agent
                FROM {$engagement_table}
                WHERE created_at >= '{$default_from}'
            ),
            session_keys AS (
                SELECT landing_key, session_token FROM landing_loads
                UNION
                SELECT landing_key, session_token FROM engagement_sessions
            ),
            handoff_by_session AS (
                SELECT
                    landing_key,
                    session_token,
                    COUNT(DISTINCT CASE WHEN event_type = 'sms_handoff_attempted' THEN handoff_id ELSE NULL END) AS handoff_attempts,
                    COUNT(DISTINCT CASE WHEN event_type = 'sms_handoff_hidden' THEN handoff_id ELSE NULL END) AS handoff_successes,
                    COUNT(DISTINCT CASE WHEN event_type = 'sms_handoff_no_hide' THEN handoff_id ELSE NULL END) AS handoff_fails,
                    MIN(CASE WHEN event_type = 'sms_handoff_hidden' THEN elapsed_ms / 1000 ELSE NULL END) AS min_hidden_seconds,
                    MAX(CASE WHEN event_type = 'sms_handoff_hidden' THEN elapsed_ms / 1000 ELSE NULL END) AS max_hidden_seconds
                FROM {$handoff_table}
                WHERE created_at >= '{$default_from}'
                GROUP BY landing_key, session_token
            ),
            ranked_attribution AS (
                SELECT *
                FROM (
                    SELECT
                        ca.*,
                        ROW_NUMBER() OVER (
                            PARTITION BY ca.transaction_id
                            ORDER BY ca.conversion_confirmed_at DESC, ca.updated_at DESC, ca.id DESC
                        ) AS kiwi_attribution_rank
                    FROM {$click_attribution_table} ca
                    WHERE ca.transaction_id <> ''
                ) ranked_ca
                WHERE ranked_ca.kiwi_attribution_rank = 1
            ),
            sales_by_session AS (
                SELECT
                    COALESCE(NULLIF(s.landing_key, ''), NULLIF(ca.landing_page_key, '')) AS landing_key,
                    COALESCE(NULLIF(s.session_ref, ''), NULLIF(ca.session_ref, '')) AS session_token,
                    COUNT(DISTINCT s.id) AS sales
                FROM {$sales_table} s
                LEFT JOIN ranked_attribution ca
                  ON ca.transaction_id = s.transaction_id
                WHERE s.status = 'completed'
                  AND s.completed_at >= '{$default_from}'
                  AND COALESCE(NULLIF(s.landing_key, ''), NULLIF(ca.landing_page_key, '')) IS NOT NULL
                  AND COALESCE(NULLIF(s.session_ref, ''), NULLIF(ca.session_ref, '')) IS NOT NULL
                GROUP BY
                    COALESCE(NULLIF(s.landing_key, ''), NULLIF(ca.landing_page_key, '')),
                    COALESCE(NULLIF(s.session_ref, ''), NULLIF(ca.session_ref, ''))
            ),
            session_rows AS (
                SELECT
                    sk.landing_key AS raw_landing_key,
                    sk.session_token,
                    COALESCE(NULLIF(e.service_key, ''), NULLIF(ll.service_key, ''), '(empty)') AS service_key,
                    COALESCE(NULLIF(e.tksource, ''), '(empty)') AS tksource,
                    COALESCE(NULLIF(e.tkzone, ''), '(empty)') AS tkzone,
                    COALESCE(e.ua_ch_platform, '') AS ua_ch_platform,
                    COALESCE(e.ua_ch_platform_version, '') AS ua_ch_platform_version,
                    COALESCE(e.ua_ch_model, '') AS ua_ch_model,
                    COALESCE(e.ua_ch_brands, '') AS ua_ch_brands,
                    COALESCE(e.ua_ch_full_version_list, '') AS ua_ch_full_version_list,
                    COALESCE(NULLIF(e.user_agent, ''), NULLIF(ll.user_agent, ''), '') AS raw_user_agent,
                    COALESCE(ll.landing_page_aufrufe, 0) AS landing_page_aufrufe,
                    CASE WHEN e.page_loaded_at IS NOT NULL THEN 1 ELSE 0 END AS page_loaded_sessions,
                    CASE WHEN e.first_cta_click_at IS NOT NULL THEN 1 ELSE 0 END AS cta_sessions,
                    COALESCE(e.cta_click_count, 0) AS cta_click_events,
                    COALESCE(h.handoff_attempts, 0) AS handoff_attempts,
                    COALESCE(h.handoff_successes, 0) AS handoff_successes,
                    COALESCE(h.handoff_fails, 0) AS handoff_fails,
                    h.min_hidden_seconds,
                    h.max_hidden_seconds,
                    COALESCE(s.sales, 0) AS sales
                FROM session_keys sk
                LEFT JOIN landing_loads ll
                  ON ll.landing_key = sk.landing_key
                 AND ll.session_token = sk.session_token
                LEFT JOIN engagement_sessions e
                  ON e.landing_key = sk.landing_key
                 AND e.session_token = sk.session_token
                LEFT JOIN handoff_by_session h
                  ON h.landing_key = sk.landing_key
                 AND h.session_token = sk.session_token
                LEFT JOIN sales_by_session s
                  ON s.landing_key = sk.landing_key
                 AND s.session_token = sk.session_token
            ),
            dimensioned AS (
                SELECT
                    COALESCE(NULLIF(raw_landing_key, ''), '(empty)') AS landing_key,
                    raw_landing_key,
                    session_token,
                    service_key,
                    tksource,
                    tkzone,
                    {$device_brand_expression} AS device_brand,
                    CASE
                        WHEN ua_ch_platform = 'Android' AND ua_ch_platform_version <> '' THEN ua_ch_platform_version
                        WHEN raw_user_agent LIKE '%Android %' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(raw_user_agent, 'Android ', -1), ';', 1)
                        ELSE '(unknown)'
                    END AS android_version,
                    CASE
                        WHEN ua_ch_full_version_list LIKE '%Microsoft Edge%' OR ua_ch_brands LIKE '%Microsoft Edge%' OR raw_user_agent LIKE '%Edg/%' THEN 'Edge'
                        WHEN ua_ch_full_version_list LIKE '%Google Chrome%' OR ua_ch_brands LIKE '%Google Chrome%' OR raw_user_agent LIKE '%Chrome/%' THEN 'Chrome'
                        WHEN raw_user_agent LIKE '%Firefox/%' THEN 'Firefox'
                        WHEN raw_user_agent LIKE '%Safari/%' THEN 'Safari'
                        ELSE '(unknown)'
                    END AS browser,
                    landing_page_aufrufe,
                    page_loaded_sessions,
                    cta_sessions,
                    cta_click_events,
                    handoff_attempts,
                    handoff_successes,
                    handoff_fails,
                    min_hidden_seconds,
                    max_hidden_seconds,
                    sales
                FROM session_rows
            ),
            hidden_rows AS (
                SELECT
                    d.landing_key,
                    d.service_key,
                    d.tksource,
                    d.tkzone,
                    d.device_brand,
                    d.android_version,
                    d.browser,
                    h.elapsed_ms / 1000 AS hidden_seconds
                FROM dimensioned d
                INNER JOIN {$handoff_table} h
                  ON h.landing_key = d.raw_landing_key
                 AND h.session_token = d.session_token
                WHERE h.event_type = 'sms_handoff_hidden'
                  AND h.created_at >= '{$default_from}'
            ),
            hidden_ranked AS (
                SELECT
                    hidden_rows.*,
                    ROW_NUMBER() OVER (
                        PARTITION BY landing_key, service_key, tksource, tkzone, device_brand, android_version, browser
                        ORDER BY hidden_seconds
                    ) AS rn,
                    COUNT(*) OVER (
                        PARTITION BY landing_key, service_key, tksource, tkzone, device_brand, android_version, browser
                    ) AS cnt
                FROM hidden_rows
            ),
            hidden_medians AS (
                SELECT
                    landing_key,
                    service_key,
                    tksource,
                    tkzone,
                    device_brand,
                    android_version,
                    browser,
                    ROUND(AVG(
                        CASE
                            WHEN rn IN (FLOOR((cnt + 1) / 2), FLOOR((cnt + 2) / 2))
                            THEN hidden_seconds
                            ELSE NULL
                        END
                    ), 2) AS median_hidden_seconds
                FROM hidden_ranked
                GROUP BY landing_key, service_key, tksource, tkzone, device_brand, android_version, browser
            )
            SELECT
                d.landing_key,
                d.service_key,
                d.tksource,
                d.tkzone,
                d.device_brand,
                d.android_version,
                d.browser,
                COUNT(*) AS sessions,
                COALESCE(SUM(d.landing_page_aufrufe), 0) AS landing_page_aufrufe,
                COALESCE(SUM(d.page_loaded_sessions), 0) AS page_loaded_sessions,
                COALESCE(SUM(d.cta_sessions), 0) AS cta_sessions,
                COALESCE(SUM(d.cta_click_events), 0) AS cta_click_events,
                COALESCE(SUM(d.handoff_attempts), 0) AS handoff_attempts,
                COALESCE(SUM(d.handoff_successes), 0) AS handoff_successes,
                COALESCE(SUM(d.handoff_fails), 0) AS handoff_fails,
                ROUND(COALESCE(SUM(d.handoff_successes), 0) / NULLIF(SUM(d.handoff_attempts), 0) * 100, 2) AS handoff_rate_pct,
                hm.median_hidden_seconds,
                MIN(d.min_hidden_seconds) AS min_hidden_seconds,
                MAX(d.max_hidden_seconds) AS max_hidden_seconds,
                COALESCE(SUM(d.sales), 0) AS sales
            FROM dimensioned d
            LEFT JOIN hidden_medians hm
              ON hm.landing_key = d.landing_key
             AND hm.service_key = d.service_key
             AND hm.tksource = d.tksource
             AND hm.tkzone = d.tkzone
             AND hm.device_brand = d.device_brand
             AND hm.android_version = d.android_version
             AND hm.browser = d.browser
            GROUP BY
                d.landing_key,
                d.service_key,
                d.tksource,
                d.tkzone,
                d.device_brand,
                d.android_version,
                d.browser,
                hm.median_hidden_seconds";
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

    private function build_device_brand_case_expression(string $model_column, string $user_agent_column): string
    {
        return "CASE
                        WHEN {$model_column} LIKE 'SM-%' OR {$user_agent_column} LIKE '%Samsung%' THEN 'Samsung'
                        WHEN {$model_column} LIKE 'Huawei%' OR {$user_agent_column} LIKE '%Huawei%' THEN 'Huawei'
                        WHEN {$model_column} LIKE 'Xiaomi%' OR {$model_column} LIKE 'Redmi%' OR {$model_column} LIKE 'POCO%' OR {$user_agent_column} LIKE '%Xiaomi%' OR {$user_agent_column} LIKE '%Redmi%' OR {$user_agent_column} LIKE '%POCO%' THEN 'Xiaomi'
                        WHEN {$model_column} LIKE 'Pixel%' OR {$user_agent_column} LIKE '%Pixel%' THEN 'Google'
                        ELSE '(unknown)'
                    END";
    }

    private function normalize_datetime(string $value, string $fallback): string
    {
        $value = trim($value);

        if ($value === '') {
            return $fallback;
        }

        $wall_clock_datetime = $this->normalize_wall_clock_datetime($value);

        if ($wall_clock_datetime !== null) {
            return $wall_clock_datetime;
        }

        if ($this->is_wall_clock_datetime_shape($value)) {
            return $fallback;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return $fallback;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function normalize_wall_clock_datetime(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) === 1) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            return checkdate($month, $day, $year)
                ? sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day)
                : null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $matches) !== 1) {
            return null;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        $hour = (int) $matches[4];
        $minute = (int) $matches[5];
        $second = isset($matches[6]) ? (int) $matches[6] : 0;

        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
    }

    private function is_wall_clock_datetime_shape(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value) === 1;
    }

    private function normalize_optional_datetime(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return $this->normalize_datetime($value, '');
    }

    private function normalize_filter_value(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if ($value === '(empty)') {
            return $value;
        }

        $value = preg_replace('/[^A-Za-z0-9._~:-]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, 191);
    }
}
