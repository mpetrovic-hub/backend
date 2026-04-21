<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Premium_Sms_Mo_Engagement_Evaluator_Service
{
    private $config;
    private $click_attribution_repository;
    private $landing_engagement_repository;

    public function __construct(
        Kiwi_Config $config,
        Kiwi_Click_Attribution_Repository $click_attribution_repository,
        Kiwi_Premium_Sms_Landing_Engagement_Repository $landing_engagement_repository
    ) {
        $this->config = $config;
        $this->click_attribution_repository = $click_attribution_repository;
        $this->landing_engagement_repository = $landing_engagement_repository;
    }

    public function evaluate_inbound_mo(array $context): array
    {
        $service_key = trim((string) ($context['service_key'] ?? ''));
        $occurred_at = $this->normalize_mysql_datetime((string) ($context['occurred_at'] ?? ''));
        $transaction_id = trim((string) ($context['transaction_id'] ?? ''));
        $reference_hint = trim((string) ($context['reference_hint'] ?? ''));
        $session_ref = trim((string) ($context['session_ref'] ?? ''));
        $require_page_loaded = $this->config->get_premium_sms_fraud_mo_require_page_loaded();
        $require_cta_click = $this->config->get_premium_sms_fraud_mo_require_cta_click();
        $min_seconds_after_load = max(0, $this->config->get_premium_sms_fraud_mo_min_seconds_after_load());

        $attribution = $this->resolve_attribution_row(
            $service_key,
            $transaction_id,
            $reference_hint,
            $session_ref
        );
        $engagement = $this->resolve_engagement_row($attribution);
        $reasons = [];

        if (!is_array($attribution) || !is_array($engagement)) {
            $reasons[] = 'unknown_link';
        }

        $page_loaded_at = is_array($engagement)
            ? trim((string) ($engagement['page_loaded_at'] ?? ''))
            : '';
        $first_cta_click_at = is_array($engagement)
            ? trim((string) ($engagement['first_cta_click_at'] ?? ''))
            : '';
        $cta_click_count = is_array($engagement)
            ? max(0, (int) ($engagement['cta_click_count'] ?? 0))
            : 0;

        $delta_seconds_after_load = null;

        if (is_array($engagement)) {
            if ($require_page_loaded && $page_loaded_at === '') {
                $reasons[] = 'missing_page_loaded';
            }

            if ($require_cta_click && ($first_cta_click_at === '' || $cta_click_count <= 0)) {
                $reasons[] = 'missing_cta_click';
            }

            if ($page_loaded_at !== '') {
                $delta_seconds_after_load = $this->resolve_seconds_delta($page_loaded_at, $occurred_at);

                if ($delta_seconds_after_load !== null && $delta_seconds_after_load < $min_seconds_after_load) {
                    $reasons[] = 'mo_too_fast_after_load<' . $min_seconds_after_load . 's';
                }
            }
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'linked' => is_array($attribution) && is_array($engagement),
            'has_soft_flag' => !empty($reasons),
            'reasons' => $reasons,
            'attribution' => is_array($attribution) ? $attribution : [],
            'engagement' => is_array($engagement) ? $engagement : [],
            'metrics' => [
                'occurred_at' => $occurred_at,
                'page_loaded_at' => $page_loaded_at,
                'first_cta_click_at' => $first_cta_click_at,
                'cta_click_count' => $cta_click_count,
                'delta_seconds_after_load' => $delta_seconds_after_load,
                'min_seconds_after_load' => $min_seconds_after_load,
                'require_page_loaded' => $require_page_loaded,
                'require_cta_click' => $require_cta_click,
            ],
        ];
    }

    protected function resolve_attribution_row(
        string $service_key,
        string $transaction_id,
        string $reference_hint,
        string $session_ref
    ): ?array {
        $row = null;

        if ($transaction_id !== '') {
            $row = $this->click_attribution_repository->find_by_transaction_id($transaction_id);
        }

        if (!is_array($row) && $service_key !== '' && $reference_hint !== '') {
            $row = $this->click_attribution_repository->find_unique_pending_by_service_reference(
                $service_key,
                $reference_hint
            );
        }

        if (!is_array($row) && $service_key !== '' && $session_ref !== '') {
            $row = $this->click_attribution_repository->find_unique_pending_by_service_reference(
                $service_key,
                $session_ref
            );
        }

        return is_array($row) ? $row : null;
    }

    protected function resolve_engagement_row(?array $attribution): ?array
    {
        if (!is_array($attribution)) {
            return null;
        }

        $landing_key = trim((string) ($attribution['landing_page_key'] ?? ''));
        $session_token = trim((string) ($attribution['session_ref'] ?? ''));

        if ($landing_key === '' || $session_token === '') {
            return null;
        }

        return $this->landing_engagement_repository->get_by_landing_session(
            $landing_key,
            $session_token
        );
    }

    private function resolve_seconds_delta(string $from, string $to): ?int
    {
        $from_ts = strtotime($from);
        $to_ts = strtotime($to);

        if ($from_ts === false || $to_ts === false) {
            return null;
        }

        return (int) ($to_ts - $from_ts);
    }

    private function normalize_mysql_datetime(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return $this->current_time_mysql();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return $this->current_time_mysql();
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
