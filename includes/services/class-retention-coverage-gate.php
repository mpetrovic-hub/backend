<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Retention_Coverage_Gate
{
    private const EXACT_METRICS = [
        'sessions',
        'page_loaded_sessions',
        'handoff_attempts',
        'handoff_successes',
        'handoff_fails',
    ];

    private const NULLABLE_EXACT_METRICS = [
        'min_hidden_seconds',
        'max_hidden_seconds',
    ];

    private const CTA_METRICS = [
        'cta1_sessions',
        'cta1_click_events',
        'cta2_sessions',
        'cta2_click_events',
        'cta3_sessions',
        'cta3_click_events',
    ];

    private const WARNING_ONLY_METRICS = [
        'sales',
        'sales_amount_minor',
    ];

    private $config;

    public function __construct(?Kiwi_Config $config = null)
    {
        $this->config = $config instanceof Kiwi_Config ? $config : new Kiwi_Config();
    }

    public function check_landing_page_sessions(array $source, string $cutoff_value): array
    {
        $requested_cutoff_value = $this->normalize_cutoff_value($cutoff_value);
        $accepted_dates = array_fill_keys($this->normalize_date_list((array) ($source['accepted_missing_metric_dates'] ?? [])), true);
        $candidate_result = $this->find_candidate_metric_dates($source, $requested_cutoff_value);

        if (empty($candidate_result['ok'])) {
            return $this->build_failed_gate_result(
                $requested_cutoff_value,
                (string) ($candidate_result['error_code'] ?? 'candidate_dates_query_failed'),
                (string) ($candidate_result['error_message'] ?? 'Retention coverage gate could not enumerate candidate metric dates.')
            );
        }

        $candidate_dates = (array) ($candidate_result['metric_dates'] ?? []);
        $last_candidate_date = empty($candidate_dates) ? '' : (string) end($candidate_dates);
        $main_details = [];
        $tkzone_details = [];
        $blocking_errors = [];
        $verified_dates = [];
        $blocked_dates = [];
        $warning_dates = [];

        foreach ($candidate_dates as $metric_date) {
            $metric_date = (string) $metric_date;

            if (isset($accepted_dates[$metric_date])) {
                $accepted_detail = [
                    'metric_date' => $metric_date,
                    'status' => 'accepted',
                    'ok' => true,
                    'blockers' => [],
                    'warnings' => [],
                    'accepted_missing_date' => true,
                ];
                $main_details[] = array_merge($accepted_detail, ['summary' => 'main']);
                $tkzone_details[] = array_merge($accepted_detail, ['summary' => 'tkzone']);
                $verified_dates[] = $metric_date;
                continue;
            }

            $main_result = $this->check_main_summary_date($source, $metric_date, $requested_cutoff_value);
            $tkzone_result = $this->check_tkzone_summary_date($source, $metric_date, $requested_cutoff_value);

            if (empty($main_result['ok'])) {
                $blocking_errors[] = (string) ($main_result['error_code'] ?? 'main_summary_coverage_failed');
            }

            if (empty($tkzone_result['ok'])) {
                $blocking_errors[] = (string) ($tkzone_result['error_code'] ?? 'tkzone_summary_coverage_failed');
            }

            $main_details[] = $main_result;
            $tkzone_details[] = $tkzone_result;

            $date_blockers = array_merge(
                (array) ($main_result['blockers'] ?? []),
                (array) ($tkzone_result['blockers'] ?? [])
            );
            $date_warnings = array_merge(
                (array) ($main_result['warnings'] ?? []),
                (array) ($tkzone_result['warnings'] ?? [])
            );

            if (!empty($date_blockers) || empty($main_result['ok']) || empty($tkzone_result['ok'])) {
                $blocked_dates[] = $metric_date;
                continue;
            }

            if (!empty($date_warnings)) {
                $warning_dates[] = $metric_date;
            }

            $verified_dates[] = $metric_date;
        }

        $blocked_dates = array_values(array_unique($blocked_dates));
        $warning_dates = array_values(array_unique($warning_dates));
        $blocking_errors = array_values(array_unique($blocking_errors));
        $last_verified_before_blocker = $this->last_verified_date_before_first_blocker($candidate_dates, $verified_dates, $blocked_dates);
        $has_errors = !empty($blocking_errors);

        if ($has_errors || empty($blocked_dates)) {
            $status = $has_errors ? 'failed' : 'passed';
        } else {
            $status = $last_verified_before_blocker !== '' ? 'partial' : 'failed';
        }

        $effective_cutoff_value = '';

        if ($status === 'passed') {
            $effective_cutoff_value = $requested_cutoff_value;
        } elseif ($status === 'partial') {
            $effective_cutoff_value = $this->start_of_next_day($last_verified_before_blocker);
        }

        return [
            'status' => $status,
            'requested_cutoff_value' => $requested_cutoff_value,
            'effective_cutoff_value' => $effective_cutoff_value,
            'verified_until_date' => $status === 'passed' ? $last_candidate_date : $last_verified_before_blocker,
            'candidate_dates' => $candidate_dates,
            'blocked_dates' => $blocked_dates,
            'warning_dates' => $warning_dates,
            'blocking_errors' => $blocking_errors,
            'main_summary' => $this->build_summary_result('main', $main_details),
            'tkzone_summary' => $this->build_summary_result('tkzone', $tkzone_details),
        ];
    }

    protected function find_candidate_metric_dates(array $source, string $cutoff_value): array
    {
        global $wpdb;

        $source_table = (string) ($source['source_table'] ?? '');
        $cutoff_column = (string) ($source['cutoff_column'] ?? 'created_at');

        if (!$this->is_identifier($source_table) || !$this->is_identifier($cutoff_column)) {
            return $this->failed_result(
                'candidate_dates_identifier_invalid',
                'Retention coverage gate could not enumerate candidate dates because a table identifier was invalid.'
            );
        }

        $query = $wpdb->prepare(
            "/* kiwi_retention_candidate_dates */
             SELECT DATE({$cutoff_column}) AS metric_date
             FROM {$source_table}
             WHERE {$cutoff_column} < %s
               AND landing_key <> ''
               AND session_token <> ''
             GROUP BY DATE({$cutoff_column})
             ORDER BY metric_date ASC",
            $cutoff_value
        );

        if ($query === false) {
            return $this->failed_result(
                'candidate_dates_query_prepare_failed',
                'Retention coverage gate could not prepare the candidate date query.'
            );
        }

        $rows = $wpdb->get_results($query, ARRAY_A);

        if (!is_array($rows)) {
            return $this->failed_result(
                'candidate_dates_query_failed',
                $this->db_error_message('Retention coverage gate could not enumerate candidate metric dates.')
            );
        }

        return [
            'ok' => true,
            'metric_dates' => $this->pluck_metric_dates($rows),
        ];
    }

    protected function check_main_summary_date(
        array $source,
        string $metric_date,
        string $requested_cutoff_value
    ): array {
        global $wpdb;

        $source_table = (string) ($source['source_table'] ?? '');
        $summary_table = $wpdb->prefix . 'kiwi_landing_funnel_daily_summary';
        $engagement_table = $wpdb->prefix . 'kiwi_premium_sms_landing_engagements';
        $handoff_table = $wpdb->prefix . 'kiwi_landing_handoff_events';
        $sales_table = $wpdb->prefix . 'kiwi_sales';

        if (!$this->identifiers_are_valid([$source_table, $summary_table, $engagement_table, $handoff_table, $sales_table])) {
            return $this->date_error_result(
                'main',
                $metric_date,
                'main_summary_identifier_invalid',
                'Retention coverage gate could not verify main summary coverage because a table identifier was invalid.'
            );
        }

        $window = $this->build_metric_window($metric_date, $requested_cutoff_value);
        $query = $this->prepare_main_light_totals_query(
            $source_table,
            $summary_table,
            $engagement_table,
            $handoff_table,
            $sales_table,
            $metric_date,
            $window
        );

        if ($query === false) {
            return $this->date_error_result(
                'main',
                $metric_date,
                'main_summary_query_prepare_failed',
                'Retention coverage gate could not prepare the main summary coverage query.'
            );
        }

        $rows = $wpdb->get_results($query, ARRAY_A);

        if (!is_array($rows)) {
            return $this->date_error_result(
                'main',
                $metric_date,
                'main_summary_query_failed',
                $this->db_error_message('Retention coverage gate could not verify main summary coverage because the query failed.')
            );
        }

        $result = $this->classify_totals_row('main', $metric_date, $rows[0] ?? [], true);

        if (!empty($result['blockers'])) {
            return $result;
        }

        $deep_result = $this->check_main_summary_deep_compare(
            $source_table,
            $summary_table,
            $engagement_table,
            $handoff_table,
            $metric_date,
            $window
        );
        $result = $this->merge_deep_compare_result($result, $deep_result);

        return $result;
    }

    protected function check_tkzone_summary_date(
        array $source,
        string $metric_date,
        string $requested_cutoff_value
    ): array {
        global $wpdb;

        $pids = $this->config->get_landing_funnel_tkzone_summary_pids();

        if (empty($pids)) {
            return [
                'summary' => 'tkzone',
                'metric_date' => $metric_date,
                'status' => 'skipped',
                'ok' => true,
                'blockers' => [],
                'warnings' => [],
                'reason' => 'tkzone_summary_pid_allowlist_empty',
            ];
        }

        $source_table = (string) ($source['source_table'] ?? '');
        $summary_table = $wpdb->prefix . 'kiwi_landing_funnel_daily_tkzone_summary';
        $engagement_table = $wpdb->prefix . 'kiwi_premium_sms_landing_engagements';
        $handoff_table = $wpdb->prefix . 'kiwi_landing_handoff_events';
        $sales_table = $wpdb->prefix . 'kiwi_sales';

        if (!$this->identifiers_are_valid([$source_table, $summary_table, $engagement_table, $handoff_table, $sales_table])) {
            return $this->date_error_result(
                'tkzone',
                $metric_date,
                'tkzone_summary_identifier_invalid',
                'Retention coverage gate could not verify TK zone summary coverage because a table identifier was invalid.'
            );
        }

        $window = $this->build_metric_window($metric_date, $requested_cutoff_value);
        $query = $this->prepare_tkzone_light_totals_query(
            $source_table,
            $summary_table,
            $engagement_table,
            $handoff_table,
            $sales_table,
            $metric_date,
            $window,
            $pids
        );

        if ($query === false) {
            return $this->date_error_result(
                'tkzone',
                $metric_date,
                'tkzone_summary_query_prepare_failed',
                'Retention coverage gate could not prepare the TK zone summary coverage query.'
            );
        }

        $rows = $wpdb->get_results($query, ARRAY_A);

        if (!is_array($rows)) {
            return $this->date_error_result(
                'tkzone',
                $metric_date,
                'tkzone_summary_query_failed',
                $this->db_error_message('Retention coverage gate could not verify TK zone summary coverage because the query failed.')
            );
        }

        $result = $this->classify_totals_row('tkzone', $metric_date, $rows[0] ?? [], false);

        if (!empty($result['blockers'])) {
            return $result;
        }

        $deep_result = $this->check_tkzone_summary_deep_compare(
            $source_table,
            $summary_table,
            $engagement_table,
            $handoff_table,
            $metric_date,
            $window,
            $pids
        );
        $result = $this->merge_deep_compare_result($result, $deep_result);

        return $result;
    }

    private function prepare_main_light_totals_query(
        string $source_table,
        string $summary_table,
        string $engagement_table,
        string $handoff_table,
        string $sales_table,
        string $metric_date,
        array $window
    ) {
        global $wpdb;

        return $wpdb->prepare(
            "/* kiwi_retention_main_light_totals */
             WITH landing_loads AS (
                SELECT
                    landing_key,
                    session_token,
                    MIN(created_at) AS first_landing_at
                FROM {$source_table}
                WHERE created_at >= %s
                  AND created_at < %s
                  AND landing_key <> ''
                  AND session_token <> ''
                GROUP BY landing_key, session_token
             ),
             session_facts AS (
                SELECT
                    1 AS sessions,
                    CASE WHEN e.page_loaded_at IS NOT NULL THEN 1 ELSE 0 END AS page_loaded_sessions,
                    CASE WHEN e.first_cta1_click_at IS NOT NULL THEN 1 ELSE 0 END AS cta1_sessions,
                    COALESCE(e.cta1_click_count, 0) AS cta1_click_events,
                    CASE WHEN e.first_cta2_click_at IS NOT NULL THEN 1 ELSE 0 END AS cta2_sessions,
                    COALESCE(e.cta2_click_count, 0) AS cta2_click_events,
                    CASE WHEN e.first_cta3_click_at IS NOT NULL THEN 1 ELSE 0 END AS cta3_sessions,
                    COALESCE(e.cta3_click_count, 0) AS cta3_click_events,
                    SUM(CASE WHEN h.event_type = 'sms_handoff_attempted' THEN 1 ELSE 0 END) AS handoff_attempts,
                    SUM(CASE WHEN h.event_type = 'sms_handoff_hidden' THEN 1 ELSE 0 END) AS handoff_successes,
                    SUM(CASE WHEN h.event_type = 'sms_handoff_no_hide' THEN 1 ELSE 0 END) AS handoff_fails,
                    MIN(CASE WHEN h.event_type = 'sms_handoff_hidden' THEN ROUND(h.elapsed_ms / 1000, 2) ELSE NULL END) AS min_hidden_seconds,
                    MAX(CASE WHEN h.event_type = 'sms_handoff_hidden' THEN ROUND(h.elapsed_ms / 1000, 2) ELSE NULL END) AS max_hidden_seconds,
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
                LEFT JOIN {$handoff_table} h
                  ON h.landing_key = l.landing_key
                 AND h.session_token = l.session_token
                 AND h.created_at >= %s
                 AND h.created_at >= l.first_landing_at
                 AND h.created_at < %s
                 AND h.landing_key <> ''
                 AND h.session_token <> ''
                 AND NOT EXISTS (
                    SELECT 1
                    FROM {$source_table} later_ls
                    WHERE later_ls.landing_key = l.landing_key
                      AND later_ls.session_token = l.session_token
                      AND later_ls.created_at > l.first_landing_at
                      AND later_ls.created_at <= h.created_at
                      AND DATE(later_ls.created_at) > DATE(l.first_landing_at)
                      AND later_ls.landing_key <> ''
                      AND later_ls.session_token <> ''
                    LIMIT 1
                 )
                GROUP BY
                    l.landing_key,
                    l.session_token,
                    e.page_loaded_at,
                    e.first_cta1_click_at,
                    e.cta1_click_count,
                    e.first_cta2_click_at,
                    e.cta2_click_count,
                    e.first_cta3_click_at,
                    e.cta3_click_count
             ),
             sales_facts AS (
                SELECT
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
                  AND s.attribution_metric_date = %s
             ),
             raw AS (
                SELECT
                    COALESCE(SUM(sessions), 0) AS sessions,
                    COALESCE(SUM(page_loaded_sessions), 0) AS page_loaded_sessions,
                    COALESCE(SUM(cta1_sessions), 0) AS cta1_sessions,
                    COALESCE(SUM(cta1_click_events), 0) AS cta1_click_events,
                    COALESCE(SUM(cta2_sessions), 0) AS cta2_sessions,
                    COALESCE(SUM(cta2_click_events), 0) AS cta2_click_events,
                    COALESCE(SUM(cta3_sessions), 0) AS cta3_sessions,
                    COALESCE(SUM(cta3_click_events), 0) AS cta3_click_events,
                    COALESCE(SUM(handoff_attempts), 0) AS handoff_attempts,
                    COALESCE(SUM(handoff_successes), 0) AS handoff_successes,
                    COALESCE(SUM(handoff_fails), 0) AS handoff_fails,
                    MIN(min_hidden_seconds) AS min_hidden_seconds,
                    MAX(max_hidden_seconds) AS max_hidden_seconds,
                    COALESCE(SUM(sales), 0) AS sales,
                    COALESCE(SUM(sales_amount_minor), 0) AS sales_amount_minor
                FROM (
                    SELECT * FROM session_facts
                    UNION ALL
                    SELECT * FROM sales_facts
                ) all_facts
             ),
             summary AS (
                SELECT
                    COALESCE(SUM(sessions), 0) AS sessions,
                    COALESCE(SUM(page_loaded_sessions), 0) AS page_loaded_sessions,
                    COALESCE(SUM(cta1_sessions), 0) AS cta1_sessions,
                    COALESCE(SUM(cta1_click_events), 0) AS cta1_click_events,
                    COALESCE(SUM(cta2_sessions), 0) AS cta2_sessions,
                    COALESCE(SUM(cta2_click_events), 0) AS cta2_click_events,
                    COALESCE(SUM(cta3_sessions), 0) AS cta3_sessions,
                    COALESCE(SUM(cta3_click_events), 0) AS cta3_click_events,
                    COALESCE(SUM(handoff_attempts), 0) AS handoff_attempts,
                    COALESCE(SUM(handoff_successes), 0) AS handoff_successes,
                    COALESCE(SUM(handoff_fails), 0) AS handoff_fails,
                    MIN(min_hidden_seconds) AS min_hidden_seconds,
                    MAX(max_hidden_seconds) AS max_hidden_seconds,
                    COALESCE(SUM(sales), 0) AS sales,
                    COALESCE(SUM(sales_amount_minor), 0) AS sales_amount_minor
                FROM {$summary_table}
                WHERE metric_date = %s
             )
             SELECT
                raw.sessions AS raw_sessions,
                summary.sessions AS summary_sessions,
                raw.page_loaded_sessions AS raw_page_loaded_sessions,
                summary.page_loaded_sessions AS summary_page_loaded_sessions,
                raw.cta1_sessions AS raw_cta1_sessions,
                summary.cta1_sessions AS summary_cta1_sessions,
                raw.cta1_click_events AS raw_cta1_click_events,
                summary.cta1_click_events AS summary_cta1_click_events,
                raw.cta2_sessions AS raw_cta2_sessions,
                summary.cta2_sessions AS summary_cta2_sessions,
                raw.cta2_click_events AS raw_cta2_click_events,
                summary.cta2_click_events AS summary_cta2_click_events,
                raw.cta3_sessions AS raw_cta3_sessions,
                summary.cta3_sessions AS summary_cta3_sessions,
                raw.cta3_click_events AS raw_cta3_click_events,
                summary.cta3_click_events AS summary_cta3_click_events,
                raw.handoff_attempts AS raw_handoff_attempts,
                summary.handoff_attempts AS summary_handoff_attempts,
                raw.handoff_successes AS raw_handoff_successes,
                summary.handoff_successes AS summary_handoff_successes,
                raw.handoff_fails AS raw_handoff_fails,
                summary.handoff_fails AS summary_handoff_fails,
                raw.min_hidden_seconds AS raw_min_hidden_seconds,
                summary.min_hidden_seconds AS summary_min_hidden_seconds,
                raw.max_hidden_seconds AS raw_max_hidden_seconds,
                summary.max_hidden_seconds AS summary_max_hidden_seconds,
                raw.sales AS raw_sales,
                summary.sales AS summary_sales,
                raw.sales_amount_minor AS raw_sales_amount_minor,
                summary.sales_amount_minor AS summary_sales_amount_minor
             FROM raw
             CROSS JOIN summary",
            $window['from'],
            $window['to'],
            $window['from'],
            $window['to'],
            $window['from'],
            $window['handoff_to'],
            $metric_date,
            $metric_date
        );
    }

    private function prepare_tkzone_light_totals_query(
        string $source_table,
        string $summary_table,
        string $engagement_table,
        string $handoff_table,
        string $sales_table,
        string $metric_date,
        array $window,
        array $pids
    ) {
        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count($pids), '%s'));
        $pid_set_hash = $this->config->get_landing_funnel_tkzone_summary_pid_set_hash();
        $params = array_merge(
            [$window['from'], $window['to']],
            $pids,
            [$window['from'], $window['handoff_to'], $window['from'], $window['to'], $metric_date],
            $pids,
            [$pid_set_hash, $metric_date]
        );

        return $wpdb->prepare(
            "/* kiwi_retention_tkzone_light_totals */
             WITH landing_loads AS (
                SELECT
                    landing_key,
                    session_token,
                    MIN(created_at) AS first_landing_at
                FROM {$source_table}
                WHERE created_at >= %s
                  AND created_at < %s
                  AND pid IN ({$placeholders})
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
                  AND s.attribution_metric_date = %s
                  AND s.pid IN ({$placeholders})
             ),
             raw AS (
                SELECT
                    COALESCE(SUM(sessions), 0) AS sessions,
                    COALESCE(SUM(page_loaded_sessions), 0) AS page_loaded_sessions,
                    COALESCE(SUM(cta1_sessions), 0) AS cta1_sessions,
                    COALESCE(SUM(cta1_click_events), 0) AS cta1_click_events,
                    COALESCE(SUM(cta2_sessions), 0) AS cta2_sessions,
                    COALESCE(SUM(cta2_click_events), 0) AS cta2_click_events,
                    COALESCE(SUM(cta3_sessions), 0) AS cta3_sessions,
                    COALESCE(SUM(cta3_click_events), 0) AS cta3_click_events,
                    COALESCE(SUM(handoff_attempts), 0) AS handoff_attempts,
                    COALESCE(SUM(handoff_successes), 0) AS handoff_successes,
                    COALESCE(SUM(handoff_fails), 0) AS handoff_fails,
                    COALESCE(SUM(sales), 0) AS sales,
                    COALESCE(SUM(sales_amount_minor), 0) AS sales_amount_minor
                FROM (
                    SELECT * FROM session_facts
                    UNION ALL
                    SELECT * FROM sales_facts
                ) all_facts
             ),
             summary AS (
                SELECT
                    COALESCE(SUM(sessions), 0) AS sessions,
                    COALESCE(SUM(page_loaded_sessions), 0) AS page_loaded_sessions,
                    COALESCE(SUM(cta1_sessions), 0) AS cta1_sessions,
                    COALESCE(SUM(cta1_click_events), 0) AS cta1_click_events,
                    COALESCE(SUM(cta2_sessions), 0) AS cta2_sessions,
                    COALESCE(SUM(cta2_click_events), 0) AS cta2_click_events,
                    COALESCE(SUM(cta3_sessions), 0) AS cta3_sessions,
                    COALESCE(SUM(cta3_click_events), 0) AS cta3_click_events,
                    COALESCE(SUM(handoff_attempts), 0) AS handoff_attempts,
                    COALESCE(SUM(handoff_successes), 0) AS handoff_successes,
                    COALESCE(SUM(handoff_fails), 0) AS handoff_fails,
                    COALESCE(SUM(sales), 0) AS sales,
                    COALESCE(SUM(sales_amount_minor), 0) AS sales_amount_minor
                FROM {$summary_table}
                WHERE pid_set_hash = %s
                  AND metric_date = %s
             )
             SELECT
                raw.sessions AS raw_sessions,
                summary.sessions AS summary_sessions,
                raw.page_loaded_sessions AS raw_page_loaded_sessions,
                summary.page_loaded_sessions AS summary_page_loaded_sessions,
                raw.cta1_sessions AS raw_cta1_sessions,
                summary.cta1_sessions AS summary_cta1_sessions,
                raw.cta1_click_events AS raw_cta1_click_events,
                summary.cta1_click_events AS summary_cta1_click_events,
                raw.cta2_sessions AS raw_cta2_sessions,
                summary.cta2_sessions AS summary_cta2_sessions,
                raw.cta2_click_events AS raw_cta2_click_events,
                summary.cta2_click_events AS summary_cta2_click_events,
                raw.cta3_sessions AS raw_cta3_sessions,
                summary.cta3_sessions AS summary_cta3_sessions,
                raw.cta3_click_events AS raw_cta3_click_events,
                summary.cta3_click_events AS summary_cta3_click_events,
                raw.handoff_attempts AS raw_handoff_attempts,
                summary.handoff_attempts AS summary_handoff_attempts,
                raw.handoff_successes AS raw_handoff_successes,
                summary.handoff_successes AS summary_handoff_successes,
                raw.handoff_fails AS raw_handoff_fails,
                summary.handoff_fails AS summary_handoff_fails,
                raw.sales AS raw_sales,
                summary.sales AS summary_sales,
                raw.sales_amount_minor AS raw_sales_amount_minor,
                summary.sales_amount_minor AS summary_sales_amount_minor
             FROM raw
             CROSS JOIN summary",
            ...$params
        );
    }

    private function check_main_summary_deep_compare(
        string $source_table,
        string $summary_table,
        string $engagement_table,
        string $handoff_table,
        string $metric_date,
        array $window
    ): array {
        global $wpdb;

        $query = $wpdb->prepare(
            "/* kiwi_retention_main_deep_compare */
             WITH landing_loads AS (
                SELECT
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
                WHERE created_at >= %s
                  AND created_at < %s
                  AND landing_key <> ''
                  AND session_token <> ''
                GROUP BY landing_key, session_token
             ),
             session_facts AS (
                SELECT
                    %s AS metric_date,
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
                    SUM(CASE WHEN h.event_type = 'sms_handoff_attempted' THEN 1 ELSE 0 END) AS handoff_attempts,
                    SUM(CASE WHEN h.event_type = 'sms_handoff_hidden' THEN 1 ELSE 0 END) AS handoff_successes,
                    SUM(CASE WHEN h.event_type = 'sms_handoff_no_hide' THEN 1 ELSE 0 END) AS handoff_fails,
                    MIN(CASE WHEN h.event_type = 'sms_handoff_hidden' THEN ROUND(h.elapsed_ms / 1000, 2) ELSE NULL END) AS min_hidden_seconds,
                    MAX(CASE WHEN h.event_type = 'sms_handoff_hidden' THEN ROUND(h.elapsed_ms / 1000, 2) ELSE NULL END) AS max_hidden_seconds
                FROM landing_loads l
                LEFT JOIN {$engagement_table} e
                  ON e.landing_key = l.landing_key
                 AND e.session_token = l.session_token
                 AND e.created_at >= %s
                 AND e.created_at < %s
                 AND e.landing_key <> ''
                 AND e.session_token <> ''
                LEFT JOIN {$handoff_table} h
                  ON h.landing_key = l.landing_key
                 AND h.session_token = l.session_token
                 AND h.created_at >= %s
                 AND h.created_at >= l.first_landing_at
                 AND h.created_at < %s
                 AND h.landing_key <> ''
                 AND h.session_token <> ''
                 AND NOT EXISTS (
                    SELECT 1
                    FROM {$source_table} later_ls
                    WHERE later_ls.landing_key = l.landing_key
                      AND later_ls.session_token = l.session_token
                      AND later_ls.created_at > l.first_landing_at
                      AND later_ls.created_at <= h.created_at
                      AND DATE(later_ls.created_at) > DATE(l.first_landing_at)
                      AND later_ls.landing_key <> ''
                      AND later_ls.session_token <> ''
                    LIMIT 1
                 )
                GROUP BY
                    l.landing_key,
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
                    l.session_token,
                    e.page_loaded_at,
                    e.first_cta1_click_at,
                    e.cta1_click_count,
                    e.first_cta2_click_at,
                    e.cta2_click_count,
                    e.first_cta3_click_at,
                    e.cta3_click_count
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
                    MIN(min_hidden_seconds) AS min_hidden_seconds,
                    MAX(max_hidden_seconds) AS max_hidden_seconds
                FROM session_facts
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
                    MIN(min_hidden_seconds) AS min_hidden_seconds,
                    MAX(max_hidden_seconds) AS max_hidden_seconds
                FROM {$summary_table}
                WHERE metric_date = %s
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
                AND ABS(summary.cta1_sessions - raw.cta1_sessions) <= GREATEST(5, CEIL(GREATEST(ABS(raw.cta1_sessions), ABS(summary.cta1_sessions)) * 0.001))
                AND ABS(summary.cta1_click_events - raw.cta1_click_events) <= GREATEST(5, CEIL(GREATEST(ABS(raw.cta1_click_events), ABS(summary.cta1_click_events)) * 0.001))
                AND ABS(summary.cta2_sessions - raw.cta2_sessions) <= GREATEST(5, CEIL(GREATEST(ABS(raw.cta2_sessions), ABS(summary.cta2_sessions)) * 0.001))
                AND ABS(summary.cta2_click_events - raw.cta2_click_events) <= GREATEST(5, CEIL(GREATEST(ABS(raw.cta2_click_events), ABS(summary.cta2_click_events)) * 0.001))
                AND ABS(summary.cta3_sessions - raw.cta3_sessions) <= GREATEST(5, CEIL(GREATEST(ABS(raw.cta3_sessions), ABS(summary.cta3_sessions)) * 0.001))
                AND ABS(summary.cta3_click_events - raw.cta3_click_events) <= GREATEST(5, CEIL(GREATEST(ABS(raw.cta3_click_events), ABS(summary.cta3_click_events)) * 0.001))
                AND summary.handoff_attempts = raw.handoff_attempts
                AND summary.handoff_successes = raw.handoff_successes
                AND summary.handoff_fails = raw.handoff_fails
                AND summary.min_hidden_seconds <=> raw.min_hidden_seconds
                AND summary.max_hidden_seconds <=> raw.max_hidden_seconds
             WHERE summary.metric_date IS NULL
             LIMIT 1",
            $window['from'],
            $window['to'],
            $metric_date,
            $window['from'],
            $window['to'],
            $window['from'],
            $window['handoff_to'],
            $metric_date
        );

        return $this->run_deep_compare_query($query, 'main');
    }

    private function check_tkzone_summary_deep_compare(
        string $source_table,
        string $summary_table,
        string $engagement_table,
        string $handoff_table,
        string $metric_date,
        array $window,
        array $pids
    ): array {
        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count($pids), '%s'));
        $pid_set_hash = $this->config->get_landing_funnel_tkzone_summary_pid_set_hash();
        $params = array_merge(
            [$window['from'], $window['to']],
            $pids,
            [$window['from'], $window['handoff_to'], $metric_date, $window['from'], $window['to'], $pid_set_hash, $metric_date]
        );

        $query = $wpdb->prepare(
            "/* kiwi_retention_tkzone_deep_compare */
             WITH landing_loads AS (
                SELECT
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
                WHERE created_at >= %s
                  AND created_at < %s
                  AND pid IN ({$placeholders})
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
             raw AS (
                SELECT
                    %s AS metric_date,
                    l.provider_key,
                    l.flow_key,
                    l.country,
                    l.service_key,
                    l.landing_key_normalized AS landing_key,
                    l.tksource,
                    l.tkzone,
                    COUNT(*) AS sessions,
                    SUM(CASE WHEN e.page_loaded_at IS NOT NULL THEN 1 ELSE 0 END) AS page_loaded_sessions,
                    SUM(CASE WHEN e.first_cta1_click_at IS NOT NULL THEN 1 ELSE 0 END) AS cta1_sessions,
                    COALESCE(SUM(e.cta1_click_count), 0) AS cta1_click_events,
                    SUM(CASE WHEN e.first_cta2_click_at IS NOT NULL THEN 1 ELSE 0 END) AS cta2_sessions,
                    COALESCE(SUM(e.cta2_click_count), 0) AS cta2_click_events,
                    SUM(CASE WHEN e.first_cta3_click_at IS NOT NULL THEN 1 ELSE 0 END) AS cta3_sessions,
                    COALESCE(SUM(e.cta3_click_count), 0) AS cta3_click_events,
                    SUM(COALESCE(h.handoff_attempts, 0)) AS handoff_attempts,
                    SUM(COALESCE(h.handoff_successes, 0)) AS handoff_successes,
                    SUM(COALESCE(h.handoff_fails, 0)) AS handoff_fails
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
                GROUP BY
                    l.provider_key,
                    l.flow_key,
                    l.country,
                    l.service_key,
                    l.landing_key_normalized,
                    l.tksource,
                    l.tkzone
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
                    SUM(handoff_fails) AS handoff_fails
                FROM {$summary_table}
                WHERE pid_set_hash = %s
                  AND metric_date = %s
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
                AND ABS(summary.cta1_sessions - raw.cta1_sessions) <= GREATEST(5, CEIL(GREATEST(ABS(raw.cta1_sessions), ABS(summary.cta1_sessions)) * 0.001))
                AND ABS(summary.cta1_click_events - raw.cta1_click_events) <= GREATEST(5, CEIL(GREATEST(ABS(raw.cta1_click_events), ABS(summary.cta1_click_events)) * 0.001))
                AND ABS(summary.cta2_sessions - raw.cta2_sessions) <= GREATEST(5, CEIL(GREATEST(ABS(raw.cta2_sessions), ABS(summary.cta2_sessions)) * 0.001))
                AND ABS(summary.cta2_click_events - raw.cta2_click_events) <= GREATEST(5, CEIL(GREATEST(ABS(raw.cta2_click_events), ABS(summary.cta2_click_events)) * 0.001))
                AND ABS(summary.cta3_sessions - raw.cta3_sessions) <= GREATEST(5, CEIL(GREATEST(ABS(raw.cta3_sessions), ABS(summary.cta3_sessions)) * 0.001))
                AND ABS(summary.cta3_click_events - raw.cta3_click_events) <= GREATEST(5, CEIL(GREATEST(ABS(raw.cta3_click_events), ABS(summary.cta3_click_events)) * 0.001))
                AND summary.handoff_attempts = raw.handoff_attempts
                AND summary.handoff_successes = raw.handoff_successes
                AND summary.handoff_fails = raw.handoff_fails
             WHERE summary.metric_date IS NULL
             LIMIT 1",
            ...$params
        );

        return $this->run_deep_compare_query($query, 'tkzone');
    }

    private function run_deep_compare_query($query, string $summary): array
    {
        global $wpdb;

        if ($query === false) {
            return [
                'ok' => false,
                'error_code' => $summary . '_summary_deep_compare_prepare_failed',
                'error_message' => 'Retention coverage gate could not prepare the ' . $summary . ' deep compare query.',
            ];
        }

        $rows = $wpdb->get_results($query, ARRAY_A);

        if (!is_array($rows)) {
            return [
                'ok' => false,
                'error_code' => $summary . '_summary_deep_compare_failed',
                'error_message' => $this->db_error_message('Retention coverage gate could not run the ' . $summary . ' deep compare query.'),
            ];
        }

        return [
            'ok' => true,
            'matched' => empty($rows),
        ];
    }

    private function merge_deep_compare_result(array $result, array $deep_result): array
    {
        $result['deep_compare'] = $deep_result;

        if (empty($deep_result['ok'])) {
            $result['ok'] = false;
            $result['status'] = 'blocked';
            $result['error_code'] = (string) ($deep_result['error_code'] ?? 'deep_compare_failed');
            $result['error_message'] = (string) ($deep_result['error_message'] ?? 'Retention coverage deep compare failed.');
            $result['blockers'][] = [
                'metric' => 'deep_compare',
                'type' => 'query_error',
                'error_code' => $result['error_code'],
            ];

            return $result;
        }

        if (empty($deep_result['matched'])) {
            $result['ok'] = true;
            $result['status'] = 'blocked';
            $result['blockers'][] = [
                'metric' => 'deep_compare',
                'type' => 'hard_dimension_mismatch',
            ];
        }

        return $result;
    }

    private function classify_totals_row(string $summary, string $metric_date, array $row, bool $include_hidden_metrics): array
    {
        $result = [
            'summary' => $summary,
            'metric_date' => $metric_date,
            'status' => 'passed',
            'ok' => true,
            'blockers' => [],
            'warnings' => [],
            'metrics' => [],
        ];

        foreach (self::EXACT_METRICS as $metric) {
            $raw = $this->numeric_value($row['raw_' . $metric] ?? 0);
            $summary_value = $this->numeric_value($row['summary_' . $metric] ?? 0);
            $result['metrics'][$metric] = $this->metric_result($raw, $summary_value);

            if (!$this->numbers_match($raw, $summary_value)) {
                $result['blockers'][] = $this->metric_blocker($metric, $raw, $summary_value, 'hard_metric_diff');
            }
        }

        if ($include_hidden_metrics) {
            foreach (self::NULLABLE_EXACT_METRICS as $metric) {
                $raw = $row['raw_' . $metric] ?? null;
                $summary_value = $row['summary_' . $metric] ?? null;
                $result['metrics'][$metric] = $this->metric_result($raw, $summary_value);

                if (!$this->nullable_numbers_match($raw, $summary_value)) {
                    $result['blockers'][] = $this->metric_blocker($metric, $raw, $summary_value, 'hard_metric_diff');
                }
            }
        }

        foreach (self::CTA_METRICS as $metric) {
            $raw = $this->numeric_value($row['raw_' . $metric] ?? 0);
            $summary_value = $this->numeric_value($row['summary_' . $metric] ?? 0);
            $result['metrics'][$metric] = $this->metric_result($raw, $summary_value);

            if ($this->numbers_match($raw, $summary_value)) {
                continue;
            }

            $diff = abs($raw - $summary_value);
            $tolerance = max(5, (int) ceil(max(abs($raw), abs($summary_value)) * 0.001));

            if ($diff <= $tolerance) {
                $result['warnings'][] = $this->metric_warning($metric, $raw, $summary_value, 'cta_diff_within_tolerance', $tolerance);
                continue;
            }

            $result['blockers'][] = $this->metric_blocker($metric, $raw, $summary_value, 'cta_diff_above_tolerance', $tolerance);
        }

        foreach (self::WARNING_ONLY_METRICS as $metric) {
            $raw = $this->numeric_value($row['raw_' . $metric] ?? 0);
            $summary_value = $this->numeric_value($row['summary_' . $metric] ?? 0);
            $result['metrics'][$metric] = $this->metric_result($raw, $summary_value);

            if (!$this->numbers_match($raw, $summary_value)) {
                $result['warnings'][] = $this->metric_warning($metric, $raw, $summary_value, 'sales_metric_diff_warning_only');
            }
        }

        if (!empty($result['blockers'])) {
            $result['status'] = 'blocked';
        } elseif (!empty($result['warnings'])) {
            $result['status'] = 'warning';
        }

        return $result;
    }

    private function build_summary_result(string $summary, array $details): array
    {
        $blocked_dates = [];
        $warning_dates = [];
        $accepted_missing_dates = [];
        $blocking_errors = [];

        foreach ($details as $detail) {
            $metric_date = (string) ($detail['metric_date'] ?? '');

            if ($metric_date === '') {
                continue;
            }

            if (!empty($detail['accepted_missing_date'])) {
                $accepted_missing_dates[] = $metric_date;
            }

            if (!empty($detail['blockers']) || empty($detail['ok'])) {
                $blocked_dates[] = $metric_date;
            }

            if (!empty($detail['warnings'])) {
                $warning_dates[] = $metric_date;
            }

            if (empty($detail['ok']) && !empty($detail['error_code'])) {
                $blocking_errors[] = (string) $detail['error_code'];
            }
        }

        $blocked_dates = array_values(array_unique($blocked_dates));
        $warning_dates = array_values(array_unique($warning_dates));
        $accepted_missing_dates = array_values(array_unique($accepted_missing_dates));
        $blocking_errors = array_values(array_unique($blocking_errors));

        return [
            'status' => empty($blocked_dates) && empty($blocking_errors) ? 'passed' : 'failed',
            'missing_dates' => $blocked_dates,
            'accepted_missing_dates' => $accepted_missing_dates,
            'blocking_missing_dates' => $blocked_dates,
            'warning_dates' => $warning_dates,
            'blocking_errors' => $blocking_errors,
            'details' => $details,
        ];
    }

    private function build_failed_gate_result(string $requested_cutoff_value, string $error_code, string $error_message): array
    {
        $summary = [
            'status' => 'failed',
            'missing_dates' => [],
            'accepted_missing_dates' => [],
            'blocking_missing_dates' => [],
            'warning_dates' => [],
            'blocking_errors' => [$error_code],
            'error_code' => $error_code,
            'error_message' => $error_message,
            'details' => [],
        ];

        return [
            'status' => 'failed',
            'requested_cutoff_value' => $requested_cutoff_value,
            'effective_cutoff_value' => '',
            'verified_until_date' => '',
            'candidate_dates' => [],
            'blocked_dates' => [],
            'warning_dates' => [],
            'blocking_errors' => [$error_code],
            'main_summary' => $summary,
            'tkzone_summary' => $summary,
        ];
    }

    private function date_error_result(string $summary, string $metric_date, string $error_code, string $error_message): array
    {
        return [
            'summary' => $summary,
            'metric_date' => $metric_date,
            'status' => 'blocked',
            'ok' => false,
            'blockers' => [
                [
                    'metric' => 'query',
                    'type' => 'query_error',
                    'error_code' => $error_code,
                ],
            ],
            'warnings' => [],
            'error_code' => $error_code,
            'error_message' => $error_message,
        ];
    }

    private function failed_result(string $error_code, string $error_message): array
    {
        return [
            'ok' => false,
            'error_code' => $error_code,
            'error_message' => $error_message,
        ];
    }

    private function metric_result($raw, $summary): array
    {
        return [
            'raw' => $raw,
            'summary' => $summary,
            'diff' => is_numeric($raw) && is_numeric($summary) ? $raw - $summary : null,
        ];
    }

    private function metric_blocker(string $metric, $raw, $summary, string $type, ?int $tolerance = null): array
    {
        $result = [
            'metric' => $metric,
            'type' => $type,
            'raw' => $raw,
            'summary' => $summary,
        ];

        if ($tolerance !== null) {
            $result['tolerance'] = $tolerance;
        }

        return $result;
    }

    private function metric_warning(string $metric, $raw, $summary, string $type, ?int $tolerance = null): array
    {
        return $this->metric_blocker($metric, $raw, $summary, $type, $tolerance);
    }

    private function last_verified_date_before_first_blocker(array $candidate_dates, array $verified_dates, array $blocked_dates): string
    {
        $verified_lookup = array_fill_keys($verified_dates, true);
        $blocked_lookup = array_fill_keys($blocked_dates, true);
        $last_verified = '';

        foreach ($candidate_dates as $metric_date) {
            $metric_date = (string) $metric_date;

            if (isset($blocked_lookup[$metric_date])) {
                break;
            }

            if (isset($verified_lookup[$metric_date])) {
                $last_verified = $metric_date;
            }
        }

        return $last_verified;
    }

    private function build_metric_window(string $metric_date, string $requested_cutoff_value): array
    {
        $from = $metric_date . ' 00:00:00';
        $to = $this->start_of_next_day($metric_date);
        $handoff_to = $this->start_of_next_day($this->date_part($to));
        $requested_handoff_to = $this->start_of_next_day($this->date_part($requested_cutoff_value));

        if (strcmp($handoff_to, $requested_handoff_to) > 0) {
            $handoff_to = $requested_handoff_to;
        }

        return [
            'from' => $from,
            'to' => $to,
            'handoff_to' => $handoff_to,
        ];
    }

    private function normalize_cutoff_value(string $cutoff_value): string
    {
        $cutoff_value = trim($cutoff_value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoff_value) === 1) {
            return $cutoff_value . ' 00:00:00';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cutoff_value) === 1) {
            return $cutoff_value;
        }

        return gmdate('Y-m-d 00:00:00');
    }

    private function normalize_date_list(array $dates): array
    {
        $normalized = [];

        foreach ($dates as $date) {
            $date = (string) $date;

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $normalized[] = $date;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function start_of_next_day(string $date): string
    {
        $date = $this->date_part($date);
        $timestamp = strtotime($date . ' +1 day');

        return ($timestamp === false ? $date : gmdate('Y-m-d', $timestamp)) . ' 00:00:00';
    }

    private function date_part(string $value): string
    {
        return substr($value, 0, 10);
    }

    private function numeric_value($value)
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return is_numeric($value) ? $value + 0 : 0;
    }

    private function numbers_match($left, $right): bool
    {
        return abs($this->numeric_value($left) - $this->numeric_value($right)) < 0.0001;
    }

    private function nullable_numbers_match($left, $right): bool
    {
        $left_empty = $left === null || $left === '';
        $right_empty = $right === null || $right === '';

        if ($left_empty || $right_empty) {
            return $left_empty && $right_empty;
        }

        return $this->numbers_match($left, $right);
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

    private function identifiers_are_valid(array $values): bool
    {
        foreach ($values as $value) {
            if (!$this->is_identifier((string) $value)) {
                return false;
            }
        }

        return true;
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
