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

        if ($sale_reference === '') {
            $sale_reference = 'sale_' . md5(wp_json_encode([$transaction, $report_event]));
        }

        return $this->sales_repository->upsert([
            'sale_reference' => $sale_reference,
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
}
