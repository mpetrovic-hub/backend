<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Aggregation_Service
{
    private $repository;
    private $config;
    private $last_error = '';

    public function __construct(
        ?Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository $repository = null,
        ?Kiwi_Config $config = null
    ) {
        $this->repository = $repository instanceof Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository
            ? $repository
            : new Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository();
        $this->config = $config instanceof Kiwi_Config ? $config : new Kiwi_Config();
    }

    public function refresh_range(string $from_date, string $to_date): array
    {
        $this->last_error = '';
        $from_date = $this->normalize_date($from_date);
        $to_date = $this->normalize_date($to_date);

        if ($from_date === '' || $to_date === '' || strcmp($from_date, $to_date) > 0) {
            $this->last_error = 'Invalid landing funnel daily tkzone summary date range.';

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
            $this->last_error = 'Invalid landing funnel daily tkzone summary metric date.';

            return $this->build_daily_result(false, '', 0, 0);
        }

        $from_datetime = $metric_date . ' 00:00:00';
        $to_exclusive_datetime = $this->next_date($metric_date) . ' 00:00:00';
        $handoff_to_exclusive_datetime = $this->next_date($this->next_date($metric_date)) . ' 00:00:00';

        $this->run_statement('START TRANSACTION');
        $deleted = $this->repository->delete_metric_date($metric_date);

        if ($deleted < 0) {
            $this->last_error = $this->format_step_error($metric_date, 'delete', $this->repository->get_last_error());
            $this->run_statement('ROLLBACK');

            return $this->build_daily_result(false, $metric_date, 0, 0);
        }

        $tkzone_summary_pids = $this->config->get_landing_funnel_tkzone_summary_pids();

        if (empty($tkzone_summary_pids)) {
            $this->run_statement('COMMIT');

            return $this->build_daily_result(true, $metric_date, $deleted, 0);
        }

        $inserted = $this->repository->insert_aggregate_rows(
            $this->build_refresh_insert_sql($tkzone_summary_pids),
            $this->build_refresh_insert_params(
                $metric_date,
                $from_datetime,
                $to_exclusive_datetime,
                $handoff_to_exclusive_datetime,
                $tkzone_summary_pids
            )
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

    public function build_refresh_insert_sql(?array $tkzone_summary_pids = null): string
    {
        global $wpdb;

        $tkzone_summary_pids = $tkzone_summary_pids === null
            ? $this->config->get_landing_funnel_tkzone_summary_pids()
            : $tkzone_summary_pids;
        $summary_table = $this->repository->get_table_name();
        $landing_session_table = $wpdb->prefix . 'kiwi_landing_page_sessions';
        $engagement_table = $wpdb->prefix . 'kiwi_premium_sms_landing_engagements';
        $handoff_table = $wpdb->prefix . 'kiwi_landing_handoff_events';
        $sales_table = $wpdb->prefix . 'kiwi_sales';
        $pid_placeholders = $this->build_placeholder_list(count($tkzone_summary_pids));
        $landing_pid_filter_sql = $pid_placeholders !== ''
            ? "AND pid IN ({$pid_placeholders})"
            : 'AND 1 = 0';
        $sales_pid_filter_sql = $pid_placeholders !== ''
            ? "AND s.pid IN ({$pid_placeholders})"
            : 'AND 1 = 0';

        $dimension_hash_expression = "SHA2(CONCAT_WS('|',
                    a.provider_key,
                    a.flow_key,
                    a.country,
                    a.service_key,
                    a.landing_key,
                    a.tksource,
                    a.tkzone
                ), 256)";

        return "INSERT INTO {$summary_table} (
                metric_date,
                provider_key,
                flow_key,
                country,
                service_key,
                landing_key,
                tksource,
                tkzone,
                dimension_hash,
                pid_set_hash,
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
                    SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(provider_key, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1) AS provider_key,
                    SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(flow_key, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1) AS flow_key,
                    SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(country, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1) AS country,
                    SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(service_key, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1) AS service_key,
                    SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(tksource, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1) AS tksource,
                    SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(tkzone, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1) AS tkzone
                FROM {$landing_session_table}
                WHERE created_at >= %s
                  AND created_at < %s
                  {$landing_pid_filter_sql}
                  AND landing_key <> ''
                  AND session_token <> ''
                GROUP BY landing_key, session_token
            ),
            handoff_by_session AS (
                SELECT
                    landing_key,
                    session_token,
                    SUM(CASE WHEN event_type = 'sms_handoff_attempted' THEN 1 ELSE 0 END) AS handoff_attempts,
                    SUM(CASE WHEN event_type = 'sms_handoff_hidden' THEN 1 ELSE 0 END) AS handoff_successes,
                    SUM(CASE WHEN event_type = 'sms_handoff_no_hide' THEN 1 ELSE 0 END) AS handoff_fails
                FROM {$handoff_table}
                WHERE created_at >= %s
                  AND created_at < %s
                  AND landing_key <> ''
                  AND session_token <> ''
                GROUP BY landing_key, session_token
            ),
            session_facts AS (
                SELECT
                    DATE(l.first_landing_at) AS metric_date,
                    COALESCE(NULLIF(l.provider_key, ''), '(unknown)') AS provider_key,
                    COALESCE(NULLIF(l.flow_key, ''), '(unknown)') AS flow_key,
                    COALESCE(NULLIF(l.country, ''), '(unknown)') AS country,
                    COALESCE(NULLIF(l.service_key, ''), '(unknown)') AS service_key,
                    COALESCE(NULLIF(l.landing_key, ''), '(unknown)') AS landing_key,
                    COALESCE(NULLIF(l.tksource, ''), '(unknown)') AS tksource,
                    COALESCE(NULLIF(l.tkzone, ''), '(unknown)') AS tkzone,
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
                 AND e.created_at >= %s
                 AND e.created_at < %s
                 AND e.landing_key <> ''
                 AND e.session_token <> ''
                LEFT JOIN handoff_by_session h
                  ON h.landing_key = l.landing_key
                 AND h.session_token = l.session_token
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
                  AND s.attribution_metric_date BETWEEN %s AND %s
                  {$sales_pid_filter_sql}
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
            all_facts AS (
                SELECT * FROM session_facts
                UNION ALL
                SELECT * FROM sales_facts
            ),
            aggregated AS (
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
                    SUM(sales) AS sales,
                    SUM(sales_amount_minor) AS sales_amount_minor
                FROM all_facts
                GROUP BY metric_date, provider_key, flow_key, country, service_key, landing_key, tksource, tkzone
            )
            SELECT
                a.metric_date,
                a.provider_key,
                a.flow_key,
                a.country,
                a.service_key,
                a.landing_key,
                a.tksource,
                a.tkzone,
                {$dimension_hash_expression} AS dimension_hash,
                %s AS pid_set_hash,
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
                CASE
                    WHEN a.handoff_attempts <= 0 THEN 0
                    ELSE ROUND(a.handoff_successes / a.handoff_attempts * 100, 2)
                END AS handoff_rate_pct,
                a.sales,
                a.sales_amount_minor,
                CURRENT_TIMESTAMP AS created_at,
                CURRENT_TIMESTAMP AS updated_at
            FROM aggregated a
            WHERE a.metric_date = %s
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

    private function build_refresh_insert_params(
        string $metric_date,
        string $from_datetime,
        string $to_exclusive_datetime,
        string $handoff_to_exclusive_datetime,
        array $tkzone_summary_pids
    ): array {
        return array_merge(
            [
                $from_datetime,
                $to_exclusive_datetime,
            ],
            $tkzone_summary_pids,
            [
                $from_datetime,
                $handoff_to_exclusive_datetime,
                $from_datetime,
                $to_exclusive_datetime,
                $metric_date,
                $metric_date,
            ],
            $tkzone_summary_pids,
            [
                $this->build_pid_set_hash($tkzone_summary_pids),
                $metric_date,
            ]
        );
    }

    private function build_pid_set_hash(array $pids): string
    {
        $pids = array_values(array_unique(array_map(static function ($pid): string {
            return (string) $pid;
        }, $pids)));
        sort($pids, SORT_STRING);

        return hash('sha256', implode('|', $pids));
    }

    private function build_placeholder_list(int $count): string
    {
        if ($count <= 0) {
            return '';
        }

        return implode(', ', array_fill(0, $count, '%s'));
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

    private function run_statement(string $sql): void
    {
        global $wpdb;

        $wpdb->query($sql);
    }
}
