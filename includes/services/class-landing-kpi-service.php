<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Kpi_Service
{
    private $config;
    private $summary_repository;

    public function __construct(Kiwi_Config $config, Kiwi_Landing_Kpi_Summary_Repository $summary_repository)
    {
        $this->config = $config;
        $this->summary_repository = $summary_repository;
    }

    public function increment_click(string $landing_key, array $context = []): bool
    {
        return $this->summary_repository->increment_counter($landing_key, 'clicks', $context);
    }

    public function increment_step(string $landing_key, string $step, array $context = []): bool
    {
        $step = strtolower(trim($step));

        if (!$this->is_supported_step($step)) {
            return false;
        }

        return $this->summary_repository->increment_counter($landing_key, $step, $context);
    }

    public function increment_conversion(string $landing_key, array $context = []): bool
    {
        return $this->summary_repository->increment_counter($landing_key, 'conv', $context);
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
        $summary_rows = $this->summary_repository->get_rows($selected_landing_keys);
        $summary_by_landing = [];

        foreach ($summary_rows as $summary_row) {
            if (!is_array($summary_row)) {
                continue;
            }

            $row_landing_key = trim((string) ($summary_row['landing_key'] ?? ''));

            if ($row_landing_key === '') {
                continue;
            }

            $summary_by_landing[$row_landing_key] = $summary_row;
        }

        $all_landing_keys = $this->merge_landing_keys(
            $selected_landing_keys,
            array_keys($summary_by_landing)
        );

        $rows = [];

        foreach ($all_landing_keys as $key) {
            $landing_meta = is_array($landing_pages[$key] ?? null) ? $landing_pages[$key] : [];
            $summary = is_array($summary_by_landing[$key] ?? null)
                ? $summary_by_landing[$key]
                : [];
            $clicks = (int) ($summary['clicks'] ?? 0);
            $cta1 = (int) ($summary['cta1'] ?? 0);
            $cta2 = (int) ($summary['cta2'] ?? 0);
            $cta3 = (int) ($summary['cta3'] ?? 0);
            $conv = (int) ($summary['conv'] ?? 0);
            $cta1_cr = $this->rate_percent($cta1, $clicks);
            $cta2_cr = $this->rate_percent($cta2, $clicks);
            $cta3_cr = $this->rate_percent($cta3, $clicks);
            $conv_cr = $this->rate_percent($conv, $clicks);

            $rows[] = [
                'landing_key' => $key,
                'title' => (string) ($landing_meta['title'] ?? ''),
                'service_key' => (string) ($landing_meta['service_key'] ?? ''),
                'provider' => (string) ($landing_meta['provider'] ?? ''),
                'flow' => (string) ($landing_meta['flow'] ?? ''),
                'clicks' => $clicks,
                'cta1' => $cta1,
                'cta1_cr' => $cta1_cr,
                'cta2' => $cta2,
                'cta2_cr' => $cta2_cr,
                'cta3' => $cta3,
                'cta3_cr' => $cta3_cr,
                'conv' => $conv,
                'conv_cr' => $conv_cr,
                'cta1_rate_pct' => $cta1_cr,
                'cta2_rate_pct' => $cta2_cr,
                'cta3_rate_pct' => $cta3_cr,
                'conv_rate_pct' => $conv_cr,
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return strcmp((string) ($left['landing_key'] ?? ''), (string) ($right['landing_key'] ?? ''));
        });

        return [
            'days' => $days,
            'since' => '',
            'window' => 'all_time_summary',
            'generated_at' => $this->current_time_mysql(),
            'rows' => $rows,
        ];
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

    private function is_supported_step(string $step): bool
    {
        return in_array($step, ['cta1', 'cta2', 'cta3'], true);
    }

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
