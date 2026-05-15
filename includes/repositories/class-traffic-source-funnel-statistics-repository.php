<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Traffic_Source_Funnel_Statistics_Repository
{
    public const DEFAULT_FROM = '2026-05-12 20:00:00';

    private $last_error = '';

    public function create_table(): void
    {
        $this->create_view();
    }

    public function create_view(): bool
    {
        global $wpdb;

        $this->last_error = '';
        $view_name = $this->get_view_name();

        $wpdb->query("DROP VIEW IF EXISTS {$view_name}");

        if ($this->has_database_error()) {
            return false;
        }

        $wpdb->query($this->build_create_view_sql());

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

    public function get_last_error(): string
    {
        return $this->last_error;
    }

    public function get_view_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_v_load_to_cta_by_tksource_tkzone';
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

    private function build_create_view_sql(): string
    {
        global $wpdb;

        $view_name = $this->get_view_name();
        $engagement_table = $wpdb->prefix . 'kiwi_premium_sms_landing_engagements';
        $click_attribution_table = $wpdb->prefix . 'kiwi_click_attributions';
        $sales_table = $wpdb->prefix . 'kiwi_sales';
        $default_from = self::DEFAULT_FROM;

        return "CREATE VIEW {$view_name} AS
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
                s.created_at AS metric_at,
                COALESCE(NULLIF(ca.service_key, ''), '(empty)') AS service_key,
                COALESCE(NULLIF(ca.tksource, ''), '(empty)') AS tksource,
                COALESCE(NULLIF(ca.tkzone, ''), '(empty)') AS tkzone,
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
            INNER JOIN (
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
              AND s.created_at >= '{$default_from}'";
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

    private function normalize_datetime(string $value, string $fallback): string
    {
        $value = trim($value);

        if ($value === '') {
            return $fallback;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return $fallback;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
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
