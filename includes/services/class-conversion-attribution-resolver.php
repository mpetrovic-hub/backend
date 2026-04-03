<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Conversion_Attribution_Resolver
{
    private $repository;
    private $dispatcher;

    public function __construct(
        Kiwi_Click_Attribution_Repository $repository,
        Kiwi_Affiliate_Postback_Dispatcher $dispatcher
    ) {
        $this->repository = $repository;
        $this->dispatcher = $dispatcher;
    }

    public function attach_provider_references(array $binding): ?array
    {
        $tracking_token = trim((string) ($binding['tracking_token'] ?? ''));
        $service_key = trim((string) ($binding['service_key'] ?? ''));
        $reference_hint = trim((string) ($binding['reference_hint'] ?? ''));

        if ($tracking_token === '' && ($service_key === '' || $reference_hint === '')) {
            return null;
        }

        $row = null;

        if ($tracking_token !== '') {
            $row = $this->repository->find_by_tracking_token($tracking_token);
        }

        if (!is_array($row) && $service_key !== '' && $reference_hint !== '') {
            $row = $this->repository->find_unique_pending_by_service_reference($service_key, $reference_hint);
        }

        if (!is_array($row)) {
            return null;
        }

        $this->repository->bind_references((int) $row['id'], [
            'provider_key' => (string) ($binding['provider_key'] ?? ($row['provider_key'] ?? '')),
            'service_key' => (string) ($binding['service_key'] ?? ($row['service_key'] ?? '')),
            'flow_key' => (string) ($binding['flow_key'] ?? ($row['flow_key'] ?? '')),
            'session_ref' => (string) ($binding['session_ref'] ?? ($row['session_ref'] ?? '')),
            'transaction_ref' => (string) ($binding['transaction_ref'] ?? ($row['transaction_ref'] ?? '')),
            'message_ref' => (string) ($binding['message_ref'] ?? ($row['message_ref'] ?? '')),
            'external_ref' => (string) ($binding['external_ref'] ?? ($row['external_ref'] ?? '')),
            'sale_reference' => (string) ($binding['sale_reference'] ?? ($row['sale_reference'] ?? '')),
        ]);

        return $this->repository->get_by_id((int) $row['id']);
    }

    public function handle_confirmed_conversion(array $conversion): array
    {
        if (empty($conversion['confirmed'])) {
            return [
                'matched' => false,
                'dispatched' => false,
                'reason' => 'not_confirmed',
            ];
        }

        $row = $this->repository->find_for_conversion([
            'provider_key' => (string) ($conversion['provider_key'] ?? ''),
            'service_key' => (string) ($conversion['service_key'] ?? ''),
            'sale_reference' => (string) ($conversion['sale_reference'] ?? ''),
            'transaction_ref' => (string) ($conversion['transaction_ref'] ?? ''),
            'message_ref' => (string) ($conversion['message_ref'] ?? ''),
            'external_ref' => (string) ($conversion['external_ref'] ?? ''),
            'session_ref' => (string) ($conversion['session_ref'] ?? ''),
        ]);

        if (!is_array($row)) {
            return [
                'matched' => false,
                'dispatched' => false,
                'reason' => 'attribution_not_found',
            ];
        }

        if (trim((string) ($row['postback_sent_at'] ?? '')) !== '') {
            return [
                'matched' => true,
                'dispatched' => false,
                'reason' => 'postback_already_sent',
                'attribution_id' => (int) $row['id'],
            ];
        }

        $this->repository->bind_references((int) $row['id'], [
            'provider_key' => (string) ($conversion['provider_key'] ?? ''),
            'service_key' => (string) ($conversion['service_key'] ?? ''),
            'flow_key' => (string) ($conversion['flow_key'] ?? ''),
            'session_ref' => (string) ($conversion['session_ref'] ?? ''),
            'transaction_ref' => (string) ($conversion['transaction_ref'] ?? ''),
            'message_ref' => (string) ($conversion['message_ref'] ?? ''),
            'external_ref' => (string) ($conversion['external_ref'] ?? ''),
            'sale_reference' => (string) ($conversion['sale_reference'] ?? ''),
        ]);
        $this->repository->mark_conversion_confirmed(
            (int) $row['id'],
            (string) ($conversion['occurred_at'] ?? '')
        );

        $updated_row = $this->repository->get_by_id((int) $row['id']) ?? $row;
        $dispatch_result = $this->dispatcher->dispatch($updated_row, $conversion);
        $this->repository->record_postback_attempt((int) $row['id'], $dispatch_result);

        return [
            'matched' => true,
            'dispatched' => !empty($dispatch_result['success']),
            'reason' => !empty($dispatch_result['success']) ? 'postback_sent' : 'postback_failed',
            'attribution_id' => (int) $row['id'],
            'postback' => $dispatch_result,
        ];
    }
}
