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
        $main_missing = $this->find_missing_main_summary_dates($source, $cutoff_value);
        $tkzone_missing = $this->find_missing_tkzone_summary_dates($source, $cutoff_value);
        $accepted_main = $this->filter_accepted_dates($main_missing, $accepted_dates);
        $accepted_tkzone = $this->filter_accepted_dates($tkzone_missing, $accepted_dates);
        $blocked_main = $this->filter_blocking_dates($main_missing, $accepted_dates);
        $blocked_tkzone = $this->filter_blocking_dates($tkzone_missing, $accepted_dates);
        $passed = empty($blocked_main) && empty($blocked_tkzone);

        return [
            'status' => $passed ? 'passed' : 'failed',
            'main_summary' => [
                'missing_dates' => $main_missing,
                'accepted_missing_dates' => $accepted_main,
                'blocking_missing_dates' => $blocked_main,
            ],
            'tkzone_summary' => [
                'missing_dates' => $tkzone_missing,
                'accepted_missing_dates' => $accepted_tkzone,
                'blocking_missing_dates' => $blocked_tkzone,
            ],
        ];
    }

    protected function find_missing_main_summary_dates(array $source, string $cutoff_value): array
    {
        global $wpdb;

        $source_table = (string) ($source['source_table'] ?? '');
        $summary_table = $wpdb->prefix . 'kiwi_landing_funnel_daily_summary';

        if (!$this->is_identifier($source_table) || !$this->is_identifier($summary_table)) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT raw.metric_date
                 FROM (
                    SELECT DISTINCT DATE(created_at) AS metric_date
                    FROM {$source_table}
                    WHERE created_at < %s
                 ) raw
                 LEFT JOIN (
                    SELECT DISTINCT metric_date
                    FROM {$summary_table}
                 ) summary ON summary.metric_date = raw.metric_date
                 WHERE summary.metric_date IS NULL
                 ORDER BY raw.metric_date ASC",
                $cutoff_value
            ),
            ARRAY_A
        );

        return $this->pluck_metric_dates($rows);
    }

    protected function find_missing_tkzone_summary_dates(array $source, string $cutoff_value): array
    {
        global $wpdb;

        $pids = $this->config->get_landing_funnel_tkzone_summary_pids();

        if (empty($pids)) {
            return [];
        }

        $source_table = (string) ($source['source_table'] ?? '');
        $summary_table = $wpdb->prefix . 'kiwi_landing_funnel_daily_tkzone_summary';

        if (!$this->is_identifier($source_table) || !$this->is_identifier($summary_table)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($pids), '%s'));
        $params = array_merge([$cutoff_value], $pids);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT raw.metric_date
                 FROM (
                    SELECT DISTINCT DATE(created_at) AS metric_date
                    FROM {$source_table}
                    WHERE created_at < %s
                      AND pid IN ({$placeholders})
                 ) raw
                 LEFT JOIN (
                    SELECT DISTINCT metric_date
                    FROM {$summary_table}
                 ) summary ON summary.metric_date = raw.metric_date
                 WHERE summary.metric_date IS NULL
                 ORDER BY raw.metric_date ASC",
                ...$params
            ),
            ARRAY_A
        );

        return $this->pluck_metric_dates($rows);
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

    private function pluck_metric_dates($rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $dates = [];

        foreach ($rows as $row) {
            $date = is_array($row) ? (string) ($row['metric_date'] ?? '') : '';

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $dates[] = $date;
            }
        }

        return array_values(array_unique($dates));
    }

    private function is_identifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1;
    }
}
