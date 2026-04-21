<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Premium_Sms_Fraud_Monitor_Service
{
    private $config;
    private $repository;

    public function __construct(
        Kiwi_Config $config,
        Kiwi_Premium_Sms_Fraud_Signal_Repository $repository
    ) {
        $this->config = $config;
        $this->repository = $repository;
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

            $is_soft_flag = $count_1h >= $threshold_1h || $count_24h >= $threshold_24h;
            $soft_flag_reason = $this->build_soft_flag_reason(
                $is_soft_flag,
                $count_1h,
                $count_24h,
                $threshold_1h,
                $threshold_24h
            );

            $record = $this->repository->insert_if_new([
                'provider_key' => $provider_key,
                'service_key' => $service_key,
                'flow_key' => $flow_key,
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
        ];
    }

    private function build_soft_flag_reason(
        bool $is_soft_flag,
        int $count_1h,
        int $count_24h,
        int $threshold_1h,
        int $threshold_24h
    ): string {
        if (!$is_soft_flag) {
            return '';
        }

        $reasons = [];

        if ($count_1h >= $threshold_1h) {
            $reasons[] = 'count_1h>=' . $threshold_1h;
        }

        if ($count_24h >= $threshold_24h) {
            $reasons[] = 'count_24h>=' . $threshold_24h;
        }

        return implode(' OR ', $reasons);
    }
}
