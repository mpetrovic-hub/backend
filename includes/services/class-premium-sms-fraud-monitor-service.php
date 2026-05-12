<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Premium_Sms_Fraud_Monitor_Service
{
    private $config;
    private $repository;
    private $engagement_evaluator;

    public function __construct(
        Kiwi_Config $config,
        Kiwi_Premium_Sms_Fraud_Signal_Repository $repository,
        ?Kiwi_Premium_Sms_Mo_Engagement_Evaluator_Service $engagement_evaluator = null
    ) {
        $this->config = $config;
        $this->repository = $repository;
        $this->engagement_evaluator = $engagement_evaluator;
    }

    public function capture_inbound_mo(array $signal_context): array
    {
        $service_key = trim((string) ($signal_context['service_key'] ?? ''));
        $source_event_key = trim((string) ($signal_context['source_event_key'] ?? ''));

        if ($service_key === '' || $source_event_key === '') {
            return [
                'signals' => [],
                'has_soft_flag' => false,
                'soft_flagged_identity_types' => [],
            ];
        }

        $provider_key = trim((string) ($signal_context['provider_key'] ?? ''));
        $flow_key = trim((string) ($signal_context['flow_key'] ?? ''));
        $country = trim((string) ($signal_context['country'] ?? ''));
        $occurred_at = trim((string) ($signal_context['occurred_at'] ?? ''));
        $threshold_1h = max(1, $this->config->get_premium_sms_fraud_threshold_1h());
        $threshold_24h = max(1, $this->config->get_premium_sms_fraud_threshold_24h());
        $engagement_evaluation = $this->evaluate_engagement($signal_context);
        $has_engagement_soft_flag = !empty($engagement_evaluation['has_soft_flag']);
        $engagement_reasons = $has_engagement_soft_flag
            ? array_values(array_unique(array_filter(array_map('strval', $engagement_evaluation['reasons'] ?? []))))
            : [];
        $engagement_mode = $this->config->get_premium_sms_fraud_mo_engagement_mode();
        $pid = $this->resolve_pid((string) ($signal_context['pid'] ?? ''), $engagement_evaluation);
        $click_id = $this->resolve_click_id((string) ($signal_context['click_id'] ?? ($signal_context['clickid'] ?? '')), $engagement_evaluation);
        $tksource = $this->resolve_source_value('tksource', (string) ($signal_context['tksource'] ?? ''), $engagement_evaluation);
        $tkzone = $this->resolve_source_value('tkzone', (string) ($signal_context['tkzone'] ?? ''), $engagement_evaluation);

        $identity_candidates = [
            'subscriber' => trim((string) ($signal_context['subscriber_reference'] ?? '')),
            'session' => trim((string) ($signal_context['session_ref'] ?? '')),
        ];

        $signals = [];
        $flagged_identity_types = [];

        foreach ($identity_candidates as $identity_type => $identity_value) {
            if ($identity_value === '') {
                continue;
            }

            $counts = $this->repository->build_counts_snapshot(
                $service_key,
                $identity_type,
                $identity_value,
                $occurred_at
            );
            $count_1h = (int) ($counts['count_1h'] ?? 0);
            $count_24h = (int) ($counts['count_24h'] ?? 0);
            $count_total = (int) ($counts['count_total'] ?? 0);

            $count_reasons = $this->build_count_threshold_reasons(
                $count_1h,
                $count_24h,
                $threshold_1h,
                $threshold_24h
            );
            $is_soft_flag = !empty($count_reasons) || $has_engagement_soft_flag;
            $soft_flag_reason = $this->build_soft_flag_reason(array_merge($count_reasons, $engagement_reasons));

            $record = $this->repository->insert_if_new([
                'provider_key' => $provider_key,
                'service_key' => $service_key,
                'flow_key' => $flow_key,
                'pid' => $pid,
                'click_id' => $click_id,
                'tksource' => $tksource,
                'tkzone' => $tkzone,
                'country' => $country,
                'source_event_key' => $source_event_key,
                'identity_type' => $identity_type,
                'identity_value' => $identity_value,
                'occurred_at' => $occurred_at,
                'count_1h' => $count_1h,
                'count_24h' => $count_24h,
                'count_total' => $count_total,
                'is_soft_flag' => $is_soft_flag,
                'soft_flag_reason' => $soft_flag_reason,
                'meta_json' => [
                    'threshold_1h' => $threshold_1h,
                    'threshold_24h' => $threshold_24h,
                    'engagement_mode' => $engagement_mode,
                    'engagement' => $engagement_evaluation,
                ],
            ]);

            $row = is_array($record['row'] ?? null) ? $record['row'] : [];

            if (!empty($row['is_soft_flag'])) {
                $flagged_identity_types[] = $identity_type;
            }

            $signals[] = [
                'identity_type' => $identity_type,
                'identity_value' => $identity_value,
                'inserted' => !empty($record['inserted']),
                'row' => $row,
            ];
        }

        $flagged_identity_types = array_values(array_unique($flagged_identity_types));

        return [
            'signals' => $signals,
            'has_soft_flag' => !empty($flagged_identity_types),
            'soft_flagged_identity_types' => $flagged_identity_types,
            'engagement' => $engagement_evaluation,
            'engagement_soft_flag_reasons' => $engagement_reasons,
            'should_block' => $engagement_mode === 'block' && $has_engagement_soft_flag,
            'pid' => $pid,
            'click_id' => $click_id,
            'tksource' => $tksource,
            'tkzone' => $tkzone,
        ];
    }

    private function build_count_threshold_reasons(
        int $count_1h,
        int $count_24h,
        int $threshold_1h,
        int $threshold_24h
    ): array {
        $reasons = [];

        if ($count_1h >= $threshold_1h) {
            $reasons[] = 'count_1h>=' . $threshold_1h;
        }

        if ($count_24h >= $threshold_24h) {
            $reasons[] = 'count_24h>=' . $threshold_24h;
        }

        return $reasons;
    }

    private function build_soft_flag_reason(array $reasons): string
    {
        $reasons = array_values(array_unique(array_filter(array_map('strval', $reasons))));

        return implode(' OR ', $reasons);
    }

    private function evaluate_engagement(array $signal_context): array
    {
        if (!$this->engagement_evaluator instanceof Kiwi_Premium_Sms_Mo_Engagement_Evaluator_Service) {
            return [
                'linked' => false,
                'has_soft_flag' => false,
                'reasons' => [],
                'link_reasons' => [],
                'pid' => '',
                'click_id' => '',
                'tksource' => '',
                'tkzone' => '',
                'attribution' => [],
                'engagement' => [],
                'metrics' => [],
            ];
        }

        return $this->engagement_evaluator->evaluate_inbound_mo([
            'service_key' => (string) ($signal_context['service_key'] ?? ''),
            'occurred_at' => (string) ($signal_context['occurred_at'] ?? ''),
            'transaction_id' => (string) ($signal_context['transaction_id'] ?? ''),
            'reference_hint' => (string) ($signal_context['reference_hint'] ?? ''),
            'session_ref' => (string) ($signal_context['session_ref'] ?? ''),
        ]);
    }

    private function resolve_pid(string $signal_pid, array $engagement_evaluation): string
    {
        $signal_pid = $this->sanitize_pid($signal_pid);

        if ($signal_pid !== '') {
            return $signal_pid;
        }

        return $this->sanitize_pid((string) ($engagement_evaluation['pid'] ?? ''));
    }

    private function sanitize_pid(string $pid): string
    {
        $pid = trim($pid);

        if ($pid === '') {
            return '';
        }

        $pid = preg_replace('/[^A-Za-z0-9._~:-]/', '', $pid);
        $pid = is_string($pid) ? $pid : '';

        return substr($pid, 0, 191);
    }

    private function resolve_click_id(string $signal_click_id, array $engagement_evaluation): string
    {
        $signal_click_id = $this->sanitize_click_id($signal_click_id);

        if ($signal_click_id !== '') {
            return $signal_click_id;
        }

        return $this->sanitize_click_id((string) ($engagement_evaluation['click_id'] ?? ''));
    }

    private function sanitize_click_id(string $click_id): string
    {
        $click_id = trim($click_id);

        if ($click_id === '') {
            return '';
        }

        $click_id = preg_replace('/[^A-Za-z0-9._~:-]/', '', $click_id);
        $click_id = is_string($click_id) ? $click_id : '';

        return substr($click_id, 0, 191);
    }

    private function resolve_source_value(string $field, string $signal_value, array $engagement_evaluation): string
    {
        $field = strtolower($field);
        if (!in_array($field, ['tksource', 'tkzone'], true)) {
            return '';
        }

        $signal_value = $this->sanitize_source_value($signal_value);

        if ($signal_value !== '') {
            return $signal_value;
        }

        return $this->sanitize_source_value((string) ($engagement_evaluation[$field] ?? ''));
    }

    private function sanitize_source_value(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9._~:-]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, 191);
    }
}
