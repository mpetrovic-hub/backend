<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Shared_Sales_Recorder
{
    private $sales_repository;

    public function __construct(Kiwi_Sales_Repository $sales_repository)
    {
        $this->sales_repository = $sales_repository;
    }

    public function record_successful_one_off_sale(array $transaction, array $report_event): array
    {
        $sale_reference = trim((string) ($transaction['sale_reference'] ?? $transaction['flow_reference'] ?? ''));
        $transaction_id = $this->resolve_transaction_id($transaction);

        if ($sale_reference === '') {
            $sale_reference = 'sale_' . md5(wp_json_encode([$transaction, $report_event]));
        }

        return $this->sales_repository->upsert([
            'sale_reference' => $sale_reference,
            'transaction_id' => $transaction_id,
            'provider_key' => 'nth',
            'country' => (string) ($transaction['country'] ?? ''),
            'flow_key' => (string) ($transaction['flow_key'] ?? ''),
            'sale_type' => 'premium_sms_one_off',
            'status' => 'completed',
            'amount_minor' => isset($transaction['price']) ? (int) $transaction['price'] : 0,
            'currency' => (string) ($transaction['currency'] ?? 'EUR'),
            'subscriber_reference' => (string) ($transaction['subscriber_reference'] ?? ''),
            'operator_code' => (string) ($transaction['operator_code'] ?? ''),
            'operator_name' => (string) ($transaction['operator_name'] ?? ''),
            'shortcode' => (string) ($transaction['shortcode'] ?? ''),
            'keyword' => (string) ($transaction['keyword'] ?? ''),
            'external_sale_id' => (string) ($report_event['external_message_id'] ?? $transaction['external_message_id'] ?? ''),
            'external_transaction_id' => (string) ($transaction['flow_reference'] ?? ''),
            'completed_at' => (string) ($report_event['occurred_at'] ?? ''),
            'context_json' => [
                'transaction' => $transaction,
                'report_event' => $report_event,
            ],
        ]);
    }

    private function resolve_transaction_id(array $transaction): string
    {
        $transaction_id = trim((string) ($transaction['transaction_id'] ?? ''));

        if ($transaction_id !== '') {
            return $transaction_id;
        }

        $meta_json = $transaction['meta_json'] ?? null;
        $meta = $this->decode_meta_json($meta_json);

        if (is_array($meta)) {
            $meta_transaction_id = trim((string) ($meta['attribution_transaction_id'] ?? ''));

            if ($meta_transaction_id !== '') {
                return $meta_transaction_id;
            }
        }

        $flow_reference = trim((string) ($transaction['flow_reference'] ?? ''));

        if (
            $flow_reference !== ''
            && preg_match('/^(txn_[A-Za-z0-9]{12,120})(?:-[A-Za-z0-9]{1,64})?$/', $flow_reference, $matches)
        ) {
            return (string) ($matches[1] ?? '');
        }

        return '';
    }

    private function decode_meta_json($value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
