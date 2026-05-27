<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service
{
    private $repository;
    private $last_error = '';

    public function __construct(?Kiwi_Landing_Funnel_Daily_Summary_Repository $repository = null)
    {
        $this->repository = $repository instanceof Kiwi_Landing_Funnel_Daily_Summary_Repository
            ? $repository
            : new Kiwi_Landing_Funnel_Daily_Summary_Repository();
    }

    public function refresh_range(string $from_date, string $to_date): array
    {
        $this->last_error = '';
        $from_date = $this->normalize_date($from_date);
        $to_date = $this->normalize_date($to_date);

        if ($from_date === '' || $to_date === '' || strcmp($from_date, $to_date) > 0) {
            $this->last_error = 'Invalid landing funnel daily summary date range.';

            return [
                'success' => false,
                'from_date' => $from_date,
                'to_date' => $to_date,
                'deleted' => 0,
                'inserted' => 0,
                'error' => $this->last_error,
            ];
        }

        $deleted = 0;
        $inserted = 0;
        $daily_results = [];

        foreach ($this->build_metric_dates($from_date, $to_date) as $metric_date) {
            $daily_result = $this->refresh_metric_date($metric_date);
            $daily_results[] = $daily_result;
            $deleted += (int) $daily_result['deleted'];
            $inserted += (int) $daily_result['inserted'];

            if (!($daily_result['success'] ?? false)) {
                return $this->build_result(false, $from_date, $to_date, $deleted, $inserted, $daily_results);
            }
        }

        return $this->build_result(true, $from_date, $to_date, $deleted, $inserted, $daily_results);
    }

    private function refresh_metric_date(string $metric_date): array
    {
        $this->last_error = '';
        $metric_date = $this->normalize_date($metric_date);

        if ($metric_date === '') {
            $this->last_error = 'Invalid landing funnel daily summary metric date.';

            return $this->build_daily_result(false, '', 0, 0);
        }

        $from_datetime = $metric_date . ' 00:00:00';
        $to_exclusive_datetime = $this->next_date($metric_date) . ' 00:00:00';
        $handoff_from_datetime = $this->previous_date($metric_date) . ' 00:00:00';
        $handoff_to_exclusive_datetime = $this->next_date($this->next_date($metric_date)) . ' 00:00:00';

        $this->run_statement('START TRANSACTION');
        $deleted = $this->repository->delete_metric_date($metric_date);

        if ($deleted < 0) {
            $this->last_error = $this->format_step_error($metric_date, 'delete', $this->repository->get_last_error());
            $this->run_statement('ROLLBACK');

            return $this->build_daily_result(false, $metric_date, 0, 0);
        }

        $inserted = $this->repository->insert_aggregate_rows(
            $this->build_refresh_insert_sql(),
            [
                $from_datetime,
                $to_exclusive_datetime,
                $from_datetime,
                $to_exclusive_datetime,
                $handoff_from_datetime,
                $handoff_to_exclusive_datetime,
                $from_datetime,
                $to_exclusive_datetime,
                $from_datetime,
                $handoff_to_exclusive_datetime,
                $metric_date,
                $metric_date,
                $from_datetime,
                $to_exclusive_datetime,
            ]
        );

        if ($inserted < 0) {
            $this->last_error = $this->format_step_error($metric_date, 'insert aggregate rows', $this->repository->get_last_error());
            $this->run_statement('ROLLBACK');

            return $this->build_daily_result(false, $metric_date, $deleted, 0);
        }

        $this->run_statement('COMMIT');

        return $this->build_daily_result(true, $metric_date, $deleted, $inserted);
    }

    public function get_last_error(): string
    {
        return $this->last_error;
    }

    public function build_refresh_insert_sql(): string
    {
        global $wpdb;

        $summary_table = $this->repository->get_table_name();
        $landing_session_table = $wpdb->prefix . 'kiwi_landing_page_sessions';
        $engagement_table = $wpdb->prefix . 'kiwi_premium_sms_landing_engagements';
        $handoff_table = $wpdb->prefix . 'kiwi_landing_handoff_events';
        $sales_table = $wpdb->prefix . 'kiwi_sales';

        $dimension_hash_expression = "SHA2(CONCAT_WS('|',
                    a.landing_key,
                    a.service_key,
                    a.provider_key,
                    a.flow_key,
                    a.country,
                    a.pid,
                    a.tksource,
                    a.tkzone,
                    a.device_brand,
                    a.android_version,
                    a.browser
                ), 256)";

        return "INSERT INTO {$summary_table} (
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
                dimension_hash,
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
                median_hidden_seconds,
                min_hidden_seconds,
                max_hidden_seconds,
                sales,
                sales_amount_minor,
                created_at,
                updated_at
            )
            WITH landing_loads AS (
                SELECT
                    landing_key,
                    session_token,
                    MIN(created_at) AS first_landing_at,
                    MAX(service_key) AS service_key,
                    MAX(user_agent) AS user_agent
                FROM {$landing_session_table}
                WHERE created_at >= %s
                  AND created_at < %s
                  AND landing_key <> ''
                  AND session_token <> ''
                GROUP BY landing_key, session_token
            ),
            engagement_sessions AS (
                SELECT
                    landing_key,
                    session_token,
                    MIN(created_at) AS first_engagement_at,
                    MAX(service_key) AS service_key,
                    MAX(provider_key) AS provider_key,
                    MAX(flow_key) AS flow_key,
                    MAX(pid) AS pid,
                    MAX(tksource) AS tksource,
                    MAX(tkzone) AS tkzone,
                    MAX(CASE WHEN page_loaded_at IS NOT NULL THEN 1 ELSE 0 END) AS page_loaded_sessions,
                    MAX(CASE WHEN first_cta1_click_at IS NOT NULL THEN 1 ELSE 0 END) AS cta1_sessions,
                    SUM(cta1_click_count) AS cta1_click_events,
                    MAX(CASE WHEN first_cta2_click_at IS NOT NULL THEN 1 ELSE 0 END) AS cta2_sessions,
                    SUM(cta2_click_count) AS cta2_click_events,
                    MAX(CASE WHEN first_cta3_click_at IS NOT NULL THEN 1 ELSE 0 END) AS cta3_sessions,
                    SUM(cta3_click_count) AS cta3_click_events,
                    MAX(ua_ch_platform) AS ua_ch_platform,
                    MAX(ua_ch_platform_version) AS ua_ch_platform_version,
                    MAX(ua_ch_model) AS ua_ch_model,
                    MAX(ua_ch_brands) AS ua_ch_brands,
                    MAX(ua_ch_full_version_list) AS ua_ch_full_version_list,
                    MAX(user_agent) AS user_agent
                FROM {$engagement_table}
                WHERE created_at >= %s
                  AND created_at < %s
                  AND landing_key <> ''
                  AND session_token <> ''
                GROUP BY landing_key, session_token
            ),
            handoff_by_session AS (
                SELECT
                    landing_key,
                    session_token,
                    MIN(created_at) AS first_handoff_at,
                    MAX(service_key) AS service_key,
                    MAX(provider_key) AS provider_key,
                    MAX(flow_key) AS flow_key,
                    MAX(pid) AS pid,
                    MAX(tksource) AS tksource,
                    MAX(tkzone) AS tkzone,
                    COUNT(DISTINCT CASE WHEN event_type = 'sms_handoff_attempted' THEN handoff_id ELSE NULL END) AS handoff_attempts,
                    COUNT(DISTINCT CASE WHEN event_type = 'sms_handoff_hidden' THEN handoff_id ELSE NULL END) AS handoff_successes,
                    COUNT(DISTINCT CASE WHEN event_type = 'sms_handoff_no_hide' THEN handoff_id ELSE NULL END) AS handoff_fails,
                    MIN(CASE WHEN event_type = 'sms_handoff_hidden' THEN ROUND(elapsed_ms / 1000, 2) ELSE NULL END) AS min_hidden_seconds,
                    MAX(CASE WHEN event_type = 'sms_handoff_hidden' THEN ROUND(elapsed_ms / 1000, 2) ELSE NULL END) AS max_hidden_seconds,
                    MAX(ua_ch_platform) AS ua_ch_platform,
                    MAX(ua_ch_platform_version) AS ua_ch_platform_version,
                    MAX(ua_ch_model) AS ua_ch_model,
                    MAX(ua_ch_brands) AS ua_ch_brands,
                    MAX(ua_ch_full_version_list) AS ua_ch_full_version_list,
                    MAX(user_agent) AS user_agent
                FROM {$handoff_table}
                WHERE created_at >= %s
                  AND created_at < %s
                  AND landing_key <> ''
                  AND session_token <> ''
                GROUP BY landing_key, session_token
                HAVING first_handoff_at >= %s
                   AND first_handoff_at < %s
            ),
            session_keys AS (
                SELECT
                    landing_key,
                    session_token,
                    MIN(metric_at) AS metric_at,
                    MAX(has_session_fact) AS has_session_fact
                FROM (
                    SELECT landing_key, session_token, first_landing_at AS metric_at, 1 AS has_session_fact
                    FROM landing_loads
                    UNION ALL
                    SELECT landing_key, session_token, first_engagement_at AS metric_at, 1 AS has_session_fact
                    FROM engagement_sessions
                    UNION ALL
                    SELECT landing_key, session_token, first_handoff_at AS metric_at, 0 AS has_session_fact
                    FROM handoff_by_session
                ) source_sessions
                GROUP BY landing_key, session_token
            ),
            session_dimensions AS (
                SELECT
                    DATE(sk.metric_at) AS metric_date,
                    COALESCE(NULLIF(sk.landing_key, ''), '(unknown)') AS landing_key,
                    sk.landing_key AS raw_landing_key,
                    sk.session_token,
                    COALESCE(NULLIF(e.service_key, ''), NULLIF(h.service_key, ''), NULLIF(l.service_key, ''), '(unknown)') AS service_key,
                    COALESCE(NULLIF(e.provider_key, ''), NULLIF(h.provider_key, ''), '(unknown)') AS provider_key,
                    COALESCE(NULLIF(e.flow_key, ''), NULLIF(h.flow_key, ''), '(unknown)') AS flow_key,
                    '(unknown)' AS country,
                    COALESCE(NULLIF(e.pid, ''), NULLIF(h.pid, ''), '(unknown)') AS pid,
                    COALESCE(NULLIF(e.tksource, ''), NULLIF(h.tksource, ''), '(unknown)') AS tksource,
                    COALESCE(NULLIF(e.tkzone, ''), NULLIF(h.tkzone, ''), '(unknown)') AS tkzone,
                    COALESCE(NULLIF(e.ua_ch_platform, ''), NULLIF(h.ua_ch_platform, ''), '') AS ua_ch_platform,
                    COALESCE(NULLIF(e.ua_ch_platform_version, ''), NULLIF(h.ua_ch_platform_version, ''), '') AS ua_ch_platform_version,
                    COALESCE(NULLIF(e.ua_ch_model, ''), NULLIF(h.ua_ch_model, ''), '') AS ua_ch_model,
                    COALESCE(NULLIF(e.ua_ch_brands, ''), NULLIF(h.ua_ch_brands, ''), '') AS ua_ch_brands,
                    COALESCE(NULLIF(e.ua_ch_full_version_list, ''), NULLIF(h.ua_ch_full_version_list, ''), '') AS ua_ch_full_version_list,
                    COALESCE(NULLIF(e.user_agent, ''), NULLIF(h.user_agent, ''), NULLIF(l.user_agent, ''), '') AS raw_user_agent,
                    CASE WHEN sk.has_session_fact > 0 THEN 1 ELSE 0 END AS sessions,
                    COALESCE(e.page_loaded_sessions, 0) AS page_loaded_sessions,
                    COALESCE(e.cta1_sessions, 0) AS cta1_sessions,
                    COALESCE(e.cta1_click_events, 0) AS cta1_click_events,
                    COALESCE(e.cta2_sessions, 0) AS cta2_sessions,
                    COALESCE(e.cta2_click_events, 0) AS cta2_click_events,
                    COALESCE(e.cta3_sessions, 0) AS cta3_sessions,
                    COALESCE(e.cta3_click_events, 0) AS cta3_click_events,
                    COALESCE(h.handoff_attempts, 0) AS handoff_attempts,
                    COALESCE(h.handoff_successes, 0) AS handoff_successes,
                    COALESCE(h.handoff_fails, 0) AS handoff_fails,
                    h.min_hidden_seconds,
                    h.max_hidden_seconds
                FROM session_keys sk
                LEFT JOIN landing_loads l
                  ON l.landing_key = sk.landing_key
                 AND l.session_token = sk.session_token
                LEFT JOIN engagement_sessions e
                  ON e.landing_key = sk.landing_key
                 AND e.session_token = sk.session_token
                LEFT JOIN handoff_by_session h
                  ON h.landing_key = sk.landing_key
                 AND h.session_token = sk.session_token
            ),
            session_facts AS (
                SELECT
                    metric_date,
                    landing_key,
                    service_key,
                    provider_key,
                    flow_key,
                    country,
                    pid,
                    tksource,
                    tkzone,
                    CASE
                        WHEN ua_ch_model LIKE 'SM-%%' OR raw_user_agent LIKE '%%Samsung%%' THEN 'Samsung'
                        WHEN raw_user_agent LIKE '%%Huawei%%' THEN 'Huawei'
                        WHEN raw_user_agent LIKE '%%Xiaomi%%' THEN 'Xiaomi'
                        WHEN raw_user_agent LIKE '%%Pixel%%' THEN 'Google'
                        WHEN ua_ch_model <> '' THEN SUBSTRING_INDEX(ua_ch_model, ' ', 1)
                        ELSE '(unknown)'
                    END AS device_brand,
                    CASE
                        WHEN ua_ch_platform = 'Android' AND ua_ch_platform_version <> '' THEN ua_ch_platform_version
                        WHEN raw_user_agent LIKE '%%Android %%' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(raw_user_agent, 'Android ', -1), ';', 1)
                        ELSE '(unknown)'
                    END AS android_version,
                    CASE
                        WHEN ua_ch_full_version_list LIKE '%%Microsoft Edge%%' OR ua_ch_brands LIKE '%%Microsoft Edge%%' OR raw_user_agent LIKE '%%Edg/%%' THEN 'Edge'
                        WHEN ua_ch_full_version_list LIKE '%%Google Chrome%%' OR ua_ch_brands LIKE '%%Google Chrome%%' OR raw_user_agent LIKE '%%Chrome/%%' THEN 'Chrome'
                        WHEN raw_user_agent LIKE '%%Firefox/%%' THEN 'Firefox'
                        WHEN raw_user_agent LIKE '%%Safari/%%' THEN 'Safari'
                        ELSE '(unknown)'
                    END AS browser,
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
                    min_hidden_seconds,
                    max_hidden_seconds,
                    0 AS sales,
                    0 AS sales_amount_minor
                FROM session_dimensions
            ),
            hidden_events AS (
                SELECT
                    sd.metric_date,
                    sd.landing_key,
                    sd.service_key,
                    sd.provider_key,
                    sd.flow_key,
                    sd.country,
                    sd.pid,
                    sd.tksource,
                    sd.tkzone,
                    CASE
                        WHEN sd.ua_ch_model LIKE 'SM-%%' OR sd.raw_user_agent LIKE '%%Samsung%%' THEN 'Samsung'
                        WHEN sd.raw_user_agent LIKE '%%Huawei%%' THEN 'Huawei'
                        WHEN sd.raw_user_agent LIKE '%%Xiaomi%%' THEN 'Xiaomi'
                        WHEN sd.raw_user_agent LIKE '%%Pixel%%' THEN 'Google'
                        WHEN sd.ua_ch_model <> '' THEN SUBSTRING_INDEX(sd.ua_ch_model, ' ', 1)
                        ELSE '(unknown)'
                    END AS device_brand,
                    CASE
                        WHEN sd.ua_ch_platform = 'Android' AND sd.ua_ch_platform_version <> '' THEN sd.ua_ch_platform_version
                        WHEN sd.raw_user_agent LIKE '%%Android %%' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(sd.raw_user_agent, 'Android ', -1), ';', 1)
                        ELSE '(unknown)'
                    END AS android_version,
                    CASE
                        WHEN sd.ua_ch_full_version_list LIKE '%%Microsoft Edge%%' OR sd.ua_ch_brands LIKE '%%Microsoft Edge%%' OR sd.raw_user_agent LIKE '%%Edg/%%' THEN 'Edge'
                        WHEN sd.ua_ch_full_version_list LIKE '%%Google Chrome%%' OR sd.ua_ch_brands LIKE '%%Google Chrome%%' OR sd.raw_user_agent LIKE '%%Chrome/%%' THEN 'Chrome'
                        WHEN sd.raw_user_agent LIKE '%%Firefox/%%' THEN 'Firefox'
                        WHEN sd.raw_user_agent LIKE '%%Safari/%%' THEN 'Safari'
                        ELSE '(unknown)'
                    END AS browser,
                    ROUND(h.elapsed_ms / 1000, 2) AS hidden_seconds
                FROM session_dimensions sd
                INNER JOIN handoff_by_session hs
                  ON hs.landing_key = sd.raw_landing_key
                 AND hs.session_token = sd.session_token
                INNER JOIN {$handoff_table} h
                  ON h.landing_key = sd.raw_landing_key
                 AND h.session_token = sd.session_token
                WHERE h.event_type = 'sms_handoff_hidden'
                  AND h.created_at >= %s
                  AND h.created_at < %s
            ),
            hidden_ranked AS (
                SELECT
                    hidden_events.*,
                    ROW_NUMBER() OVER (
                        PARTITION BY metric_date, landing_key, service_key, provider_key, flow_key, country, pid, tksource, tkzone, device_brand, android_version, browser
                        ORDER BY hidden_seconds
                    ) AS rn,
                    COUNT(*) OVER (
                        PARTITION BY metric_date, landing_key, service_key, provider_key, flow_key, country, pid, tksource, tkzone, device_brand, android_version, browser
                    ) AS cnt
                FROM hidden_events
            ),
            hidden_medians AS (
                SELECT
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
                    ROUND(AVG(
                        CASE
                            WHEN rn IN (FLOOR((cnt + 1) / 2), FLOOR((cnt + 2) / 2))
                            THEN hidden_seconds
                            ELSE NULL
                        END
                    ), 2) AS median_hidden_seconds
                FROM hidden_ranked
                GROUP BY metric_date, landing_key, service_key, provider_key, flow_key, country, pid, tksource, tkzone, device_brand, android_version, browser
            ),
            sales_facts AS (
                SELECT
                    COALESCE(s.attribution_metric_date, DATE(s.completed_at)) AS metric_date,
                    COALESCE(NULLIF(s.landing_key, ''), '(unknown)') AS landing_key,
                    COALESCE(NULLIF(s.service_key, ''), '(unknown)') AS service_key,
                    COALESCE(NULLIF(s.provider_key, ''), '(unknown)') AS provider_key,
                    COALESCE(NULLIF(s.flow_key, ''), '(unknown)') AS flow_key,
                    COALESCE(NULLIF(s.country, ''), '(unknown)') AS country,
                    COALESCE(NULLIF(s.pid, ''), '(unknown)') AS pid,
                    COALESCE(NULLIF(s.tksource, ''), '(unknown)') AS tksource,
                    COALESCE(NULLIF(s.tkzone, ''), '(unknown)') AS tkzone,
                    COALESCE(NULLIF(s.device_brand, ''), '(unknown)') AS device_brand,
                    COALESCE(NULLIF(s.android_version, ''), '(unknown)') AS android_version,
                    COALESCE(NULLIF(s.browser, ''), '(unknown)') AS browser,
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
                    COUNT(DISTINCT s.id) AS sales,
                    COALESCE(SUM(s.amount_minor), 0) AS sales_amount_minor
                FROM {$sales_table} s
                WHERE s.status = 'completed'
                  AND (
                      s.attribution_metric_date BETWEEN %s AND %s
                      OR (
                          s.attribution_metric_date IS NULL
                          AND s.completed_at >= %s
                          AND s.completed_at < %s
                      )
                  )
                GROUP BY
                    COALESCE(s.attribution_metric_date, DATE(s.completed_at)),
                    COALESCE(NULLIF(s.landing_key, ''), '(unknown)'),
                    COALESCE(NULLIF(s.service_key, ''), '(unknown)'),
                    COALESCE(NULLIF(s.provider_key, ''), '(unknown)'),
                    COALESCE(NULLIF(s.flow_key, ''), '(unknown)'),
                    COALESCE(NULLIF(s.country, ''), '(unknown)'),
                    COALESCE(NULLIF(s.pid, ''), '(unknown)'),
                    COALESCE(NULLIF(s.tksource, ''), '(unknown)'),
                    COALESCE(NULLIF(s.tkzone, ''), '(unknown)'),
                    COALESCE(NULLIF(s.device_brand, ''), '(unknown)'),
                    COALESCE(NULLIF(s.android_version, ''), '(unknown)'),
                    COALESCE(NULLIF(s.browser, ''), '(unknown)')
            ),
            all_facts AS (
                SELECT * FROM session_facts
                UNION ALL
                SELECT * FROM sales_facts
            ),
            aggregated AS (
                SELECT
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
                    MIN(min_hidden_seconds) AS min_hidden_seconds,
                    MAX(max_hidden_seconds) AS max_hidden_seconds,
                    SUM(sales) AS sales,
                    SUM(sales_amount_minor) AS sales_amount_minor
                FROM all_facts
                GROUP BY metric_date, landing_key, service_key, provider_key, flow_key, country, pid, tksource, tkzone, device_brand, android_version, browser
            )
            SELECT
                a.metric_date,
                a.landing_key,
                a.service_key,
                a.provider_key,
                a.flow_key,
                a.country,
                a.pid,
                a.tksource,
                a.tkzone,
                a.device_brand,
                a.android_version,
                a.browser,
                {$dimension_hash_expression} AS dimension_hash,
                a.sessions,
                a.page_loaded_sessions,
                a.cta1_sessions,
                a.cta1_click_events,
                a.cta2_sessions,
                a.cta2_click_events,
                a.cta3_sessions,
                a.cta3_click_events,
                a.handoff_attempts,
                a.handoff_successes,
                a.handoff_fails,
                COALESCE(ROUND(a.handoff_successes / NULLIF(a.handoff_attempts, 0) * 100, 2), 0) AS handoff_rate_pct,
                hm.median_hidden_seconds,
                a.min_hidden_seconds,
                a.max_hidden_seconds,
                a.sales,
                a.sales_amount_minor,
                CURRENT_TIMESTAMP AS created_at,
                CURRENT_TIMESTAMP AS updated_at
            FROM aggregated a
            LEFT JOIN hidden_medians hm
              ON hm.metric_date = a.metric_date
             AND hm.landing_key = a.landing_key
             AND hm.service_key = a.service_key
             AND hm.provider_key = a.provider_key
             AND hm.flow_key = a.flow_key
             AND hm.country = a.country
             AND hm.pid = a.pid
             AND hm.tksource = a.tksource
             AND hm.tkzone = a.tkzone
             AND hm.device_brand = a.device_brand
             AND hm.android_version = a.android_version
             AND hm.browser = a.browser
            WHERE a.metric_date IS NOT NULL
              AND (
                  a.sessions > 0
                  OR a.page_loaded_sessions > 0
                  OR a.cta1_click_events > 0
                  OR a.cta2_click_events > 0
                  OR a.cta3_click_events > 0
                  OR a.handoff_attempts > 0
                  OR a.handoff_successes > 0
                  OR a.handoff_fails > 0
                  OR a.sales > 0
              )";
    }

    private function build_result(
        bool $success,
        string $from_date,
        string $to_date,
        int $deleted,
        int $inserted,
        array $daily_results = []
    ): array {
        return [
            'success' => $success,
            'from_date' => $from_date,
            'to_date' => $to_date,
            'deleted' => $deleted,
            'inserted' => $inserted,
            'error' => $success ? '' : $this->last_error,
            'daily_results' => $daily_results,
        ];
    }

    private function build_daily_result(
        bool $success,
        string $metric_date,
        int $deleted,
        int $inserted
    ): array {
        return [
            'success' => $success,
            'metric_date' => $metric_date,
            'deleted' => $deleted,
            'inserted' => $inserted,
            'error' => $success ? '' : $this->last_error,
        ];
    }

    private function build_metric_dates(string $from_date, string $to_date): array
    {
        $dates = [];
        $current = $from_date;

        while ($current !== '' && strcmp($current, $to_date) <= 0) {
            $dates[] = $current;
            $next = $this->next_date($current);

            if ($next === $current) {
                break;
            }

            $current = $next;
        }

        return $dates;
    }

    private function format_step_error(string $metric_date, string $step, string $error): string
    {
        $error = trim($error);

        if ($error === '') {
            $error = 'failed without database error detail.';
        }

        return $metric_date . ' ' . $step . ': ' . $error;
    }

    private function normalize_date(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $matches) === 1) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : '';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? '' : gmdate('Y-m-d', $timestamp);
    }

    private function next_date(string $date): string
    {
        $timestamp = strtotime($date . ' +1 day');

        return $timestamp === false ? $date : gmdate('Y-m-d', $timestamp);
    }

    private function previous_date(string $date): string
    {
        $timestamp = strtotime($date . ' -1 day');

        return $timestamp === false ? $date : gmdate('Y-m-d', $timestamp);
    }

    private function run_statement(string $sql): void
    {
        global $wpdb;

        $wpdb->query($sql);
    }
}
