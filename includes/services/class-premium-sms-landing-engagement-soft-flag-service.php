<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Premium_Sms_Landing_Engagement_Soft_Flag_Service
{
    public const RULE_KEY = 'landing_engagement_v1';

    private $config;

    public function __construct(?Kiwi_Config $config = null)
    {
        $this->config = $config instanceof Kiwi_Config
            ? $config
            : new Kiwi_Config();
    }

    public function evaluate(array $row): array
    {
        $reasons = [];
        $page_loaded_at = trim((string) ($row['page_loaded_at'] ?? ''));
        $first_cta_click_at = trim((string) ($row['first_cta_click_at'] ?? ''));
        $last_cta_click_at = trim((string) ($row['last_cta_click_at'] ?? ''));
        $cta_click_count = max(0, (int) ($row['cta_click_count'] ?? 0));
        $has_click_signal = $cta_click_count > 0 || $first_cta_click_at !== '' || $last_cta_click_at !== '';

        if ($has_click_signal && $page_loaded_at === '') {
            $reasons[] = 'missing_load';
        }

        if ($page_loaded_at !== '' && $first_cta_click_at !== '') {
            $delta_seconds = $this->seconds_delta($page_loaded_at, $first_cta_click_at);
            $min_seconds = max(0, (int) $this->config->get_premium_sms_fraud_mo_min_seconds_after_load());

            if ($delta_seconds !== null && $delta_seconds < 0) {
                $reasons[] = 'click_before_load';
            } elseif ($delta_seconds !== null && $delta_seconds < $min_seconds) {
                $reasons[] = 'fast_click';
            }
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'is_soft_flag' => !empty($reasons),
            'soft_flag_reason' => implode(' OR ', $reasons),
            'soft_flag_rule_key' => self::RULE_KEY,
        ];
    }

    private function seconds_delta(string $from, string $to): ?int
    {
        $from_ts = strtotime($from);
        $to_ts = strtotime($to);

        if ($from_ts === false || $to_ts === false) {
            return null;
        }

        return (int) ($to_ts - $from_ts);
    }
}
