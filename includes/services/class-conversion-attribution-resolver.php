<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Conversion_Attribution_Resolver
{
    private $repository;
    private $dispatcher;
    private $landing_kpi_service;
    private $sales_repository;

    public function __construct(
        Kiwi_Click_Attribution_Repository $repository,
        Kiwi_Affiliate_Postback_Dispatcher $dispatcher,
        ?Kiwi_Landing_Kpi_Service $landing_kpi_service = null,
        ?Kiwi_Sales_Repository $sales_repository = null
    ) {
        $this->repository = $repository;
        $this->dispatcher = $dispatcher;
        $this->landing_kpi_service = $landing_kpi_service;
        $this->sales_repository = $sales_repository;
    }

    public function attach_provider_references(array $binding): ?array
    {
        $tracking_token = trim((string) ($binding['tracking_token'] ?? ''));
        $transaction_id = trim((string) ($binding['transaction_id'] ?? ''));
        $provider_key = trim((string) ($binding['provider_key'] ?? ''));
        $service_key = trim((string) ($binding['service_key'] ?? ''));
        $reference_hint = trim((string) ($binding['reference_hint'] ?? ''));

        if ($tracking_token === '' && $transaction_id === '' && ($service_key === '' || $reference_hint === '')) {
            return null;
        }

        $row = null;

        if ($tracking_token !== '') {
            $row = $this->repository->find_by_tracking_token($tracking_token);
        }

        if (!is_array($row) && $transaction_id !== '') {
            $row = $this->repository->find_by_transaction_id($transaction_id);
        }

        if (!is_array($row) && $service_key !== '' && $reference_hint !== '') {
            $row = $this->repository->find_unique_pending_by_service_reference($service_key, $reference_hint);
        }

        if (!is_array($row)) {
            return null;
        }

        if ($service_key !== '' && trim((string) ($row['service_key'] ?? '')) !== '' && trim((string) $row['service_key']) !== $service_key) {
            return null;
        }

        if ($provider_key !== '' && trim((string) ($row['provider_key'] ?? '')) !== '' && trim((string) $row['provider_key']) !== $provider_key) {
            return null;
        }

        $this->repository->bind_references((int) $row['id'], [
            'provider_key' => (string) ($binding['provider_key'] ?? ($row['provider_key'] ?? '')),
            'service_key' => (string) ($binding['service_key'] ?? ($row['service_key'] ?? '')),
            'flow_key' => (string) ($binding['flow_key'] ?? ($row['flow_key'] ?? '')),
            'session_ref' => (string) ($binding['session_ref'] ?? ($row['session_ref'] ?? '')),
            'transaction_id' => (string) ($binding['transaction_id'] ?? ($row['transaction_id'] ?? '')),
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
            'transaction_id' => (string) ($conversion['transaction_id'] ?? ''),
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
            'transaction_id' => (string) ($conversion['transaction_id'] ?? ($row['transaction_id'] ?? '')),
            'transaction_ref' => (string) ($conversion['transaction_ref'] ?? ''),
            'message_ref' => (string) ($conversion['message_ref'] ?? ''),
            'external_ref' => (string) ($conversion['external_ref'] ?? ''),
            'sale_reference' => (string) ($conversion['sale_reference'] ?? ''),
        ]);
        $is_first_confirmed = trim((string) ($row['conversion_confirmed_at'] ?? '')) === '';
        $this->repository->mark_conversion_confirmed(
            (int) $row['id'],
            (string) ($conversion['occurred_at'] ?? '')
        );
        $this->maybe_record_kpi_conversion($row, $conversion, $is_first_confirmed);

        $updated_row = $this->repository->get_by_id((int) $row['id']) ?? $row;
        $this->maybe_persist_sale_pid_from_attribution($updated_row, $conversion);
        $conversion = $this->enrich_conversion_with_sales_data($conversion, $updated_row);
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

    private function enrich_conversion_with_sales_data(array $conversion, array $attribution_row): array
    {
        if (!$this->sales_repository instanceof Kiwi_Sales_Repository) {
            return $conversion;
        }

        $sale_reference = trim((string) ($conversion['sale_reference'] ?? ($attribution_row['sale_reference'] ?? '')));

        if ($sale_reference === '') {
            return $conversion;
        }

        $sale = $this->sales_repository->find_by_sale_reference($sale_reference);

        if (!is_array($sale)) {
            return $conversion;
        }

        if (trim((string) ($conversion['operator_name'] ?? '')) === '') {
            $conversion['operator_name'] = trim((string) ($sale['operator_name'] ?? ''));
        }

        if (trim((string) ($conversion['operator_code'] ?? '')) === '') {
            $conversion['operator_code'] = trim((string) ($sale['operator_code'] ?? ''));
        }

        return $conversion;
    }

    private function maybe_persist_sale_pid_from_attribution(array $attribution_row, array $conversion): void
    {
        if (!$this->sales_repository instanceof Kiwi_Sales_Repository) {
            return;
        }

        $sale_reference = trim((string) ($conversion['sale_reference'] ?? ($attribution_row['sale_reference'] ?? '')));

        if ($sale_reference === '') {
            return;
        }

        $raw_context = $attribution_row['raw_context'] ?? null;
        $query_params = $this->resolve_query_params_from_raw_context($raw_context);
        $pid = $this->resolve_pid_from_query_params($query_params);

        if ($pid === '') {
            return;
        }

        $this->sales_repository->update_pid_by_sale_reference($sale_reference, $pid);
    }

    private function resolve_query_params_from_raw_context($raw_context): array
    {
        if (is_array($raw_context)) {
            $query_params = $raw_context['query_params'] ?? [];

            return is_array($query_params) ? $query_params : [];
        }

        if (!is_string($raw_context) || trim($raw_context) === '') {
            return [];
        }

        $decoded = json_decode($raw_context, true);

        if (!is_array($decoded)) {
            return [];
        }

        $query_params = $decoded['query_params'] ?? [];

        return is_array($query_params) ? $query_params : [];
    }

    private function resolve_pid_from_query_params(array $query_params): string
    {
        foreach ($query_params as $key => $value) {
            if (strtolower((string) $key) !== 'pid' || is_array($value)) {
                continue;
            }

            $candidate = trim((string) $value);

            if ($candidate === '') {
                continue;
            }

            $candidate = preg_replace('/[^A-Za-z0-9._~:-]/', '', $candidate);
            $candidate = is_string($candidate) ? $candidate : '';

            if ($candidate === '') {
                continue;
            }

            return substr($candidate, 0, 191);
        }

        return '';
    }

    private function maybe_record_kpi_conversion(array $attribution_row, array $conversion, bool $is_first_confirmed): void
    {
        if (!$is_first_confirmed) {
            return;
        }

        if (!$this->landing_kpi_service instanceof Kiwi_Landing_Kpi_Service) {
            return;
        }

        $landing_key = trim((string) ($attribution_row['landing_page_key'] ?? ''));

        if ($landing_key === '') {
            return;
        }

        $this->landing_kpi_service->increment_conversion($landing_key, [
            'service_key' => (string) ($conversion['service_key'] ?? ($attribution_row['service_key'] ?? '')),
            'provider_key' => (string) ($conversion['provider_key'] ?? ($attribution_row['provider_key'] ?? '')),
            'flow_key' => (string) ($conversion['flow_key'] ?? ($attribution_row['flow_key'] ?? '')),
        ]);
    }

    public function resolve_pending_transaction_id(string $service_key, string $reference_hint): string
    {
        $service_key = trim($service_key);
        $reference_hint = trim($reference_hint);

        if ($service_key === '' || $reference_hint === '') {
            return '';
        }

        $row = $this->repository->find_unique_pending_by_service_reference($service_key, $reference_hint);

        if (!is_array($row)) {
            return '';
        }

        return trim((string) ($row['transaction_id'] ?? ''));
    }
}
