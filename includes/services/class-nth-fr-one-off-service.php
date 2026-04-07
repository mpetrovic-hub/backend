<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Nth_Fr_One_Off_Service
{
    private $config;
    private $normalizer;
    private $client;
    private $event_repository;
    private $flow_transaction_repository;
    private $sales_recorder;
    private $conversion_attribution_resolver;

    public function __construct(
        Kiwi_Config $config,
        Kiwi_Nth_Premium_Sms_Normalizer $normalizer,
        Kiwi_Nth_Client $client,
        Kiwi_Nth_Event_Repository $event_repository,
        Kiwi_Nth_Flow_Transaction_Repository $flow_transaction_repository,
        Kiwi_Shared_Sales_Recorder $sales_recorder,
        ?Kiwi_Conversion_Attribution_Resolver $conversion_attribution_resolver = null
    ) {
        $this->config = $config;
        $this->normalizer = $normalizer;
        $this->client = $client;
        $this->event_repository = $event_repository;
        $this->flow_transaction_repository = $flow_transaction_repository;
        $this->sales_recorder = $sales_recorder;
        $this->conversion_attribution_resolver = $conversion_attribution_resolver;
    }

    public function handle_inbound_mo(string $service_key, array $payload): array
    {
        $service = $this->config->get_nth_service($service_key);

        if (!is_array($service)) {
            return [
                'success' => false,
                'message' => 'Unknown NTH service key.',
            ];
        }

        $normalized_event = $this->normalizer->normalize_callback($service_key, 'mo', $payload);
        $event_record = $this->event_repository->insert_if_new($normalized_event);

        if (!$event_record['inserted']) {
            return [
                'success' => true,
                'message' => 'Duplicate MO callback ignored.',
                'event' => $event_record['row'],
            ];
        }

        $subscriber_reference = trim((string) ($normalized_event['subscriber_reference'] ?? ''));
        $shortcode = trim((string) ($normalized_event['shortcode'] ?? ''));
        $keyword = trim((string) ($normalized_event['keyword'] ?? ''));

        if ($subscriber_reference === '' || $shortcode === '' || $keyword === '') {
            return [
                'success' => false,
                'message' => 'Missing mandatory FR one-off MO fields after normalization.',
                'event' => $event_record['row'],
            ];
        }

        $existing_transaction = $this->flow_transaction_repository->find_active_by_subscriber_context(
            $service_key,
            $subscriber_reference,
            $shortcode,
            $keyword,
            (int) ($service['session_validity_hours'] ?? 24)
        );

        if (is_array($existing_transaction) && trim((string) ($existing_transaction['external_message_id'] ?? '')) !== '') {
            $this->flow_transaction_repository->update((int) $existing_transaction['id'], [
                'last_event_id' => (int) ($event_record['row']['id'] ?? 0),
                'current_status' => 'duplicate_mo_ignored',
            ]);

            return [
                'success' => true,
                'message' => 'Active one-off transaction already exists for this subscriber context.',
                'event' => $event_record['row'],
                'transaction' => $existing_transaction,
            ];
        }

        $reference_hint = trim((string) ($normalized_event['external_request_id'] ?? ''));
        $attribution_transaction_id = $this->resolve_attribution_transaction_id($service_key, $reference_hint);
        $flow_reference = $this->build_provider_flow_reference($attribution_transaction_id);
        $sale_reference = $flow_reference;
        $message_text = $this->build_mt_message_text($service, $normalized_event);
        $nwc = $this->resolve_nwc($service, $normalized_event);

        $transaction_data = [
            'service_key' => $service_key,
            'country' => (string) ($service['country'] ?? ''),
            'flow_key' => (string) ($service['flow'] ?? ''),
            'flow_reference' => $flow_reference,
            'sale_reference' => $sale_reference,
            'landing_key' => (string) ($service['landing_page_key'] ?? ''),
            'landing_session_token' => '',
            'subscriber_reference' => $subscriber_reference,
            'shortcode' => $shortcode,
            'keyword' => $keyword,
            'operator_code' => (string) ($normalized_event['operator_code'] ?? ''),
            'operator_name' => (string) ($normalized_event['operator_name'] ?? ''),
            'nwc' => $nwc,
            'message_text' => $message_text,
            'mo_event_id' => (int) ($event_record['row']['id'] ?? 0),
            'last_event_id' => (int) ($event_record['row']['id'] ?? 0),
            'mt_submit_event_id' => 0,
            'last_report_event_id' => 0,
            'external_request_id' => $flow_reference,
            'external_message_id' => '',
            'current_status' => 'mo_received',
            'is_terminal' => 0,
            'sale_id' => 0,
            'price' => isset($service['price']) ? (int) $service['price'] : 0,
            'currency' => (string) ($service['currency'] ?? 'EUR'),
            'meta_json' => [
                'initial_event' => $normalized_event,
                'attribution_transaction_id' => $attribution_transaction_id,
            ],
        ];

        $transaction_id = $this->flow_transaction_repository->create($transaction_data);
        $transaction = $this->flow_transaction_repository->get_by_id($transaction_id);

        if (!is_array($transaction)) {
            $transaction = array_merge($transaction_data, ['id' => $transaction_id]);
        }

        $submit_transaction = [
            'flow_reference' => $flow_reference,
            'subscriber_reference' => $subscriber_reference,
            'shortcode' => $shortcode,
            'keyword' => $keyword,
            'operator_code' => (string) ($normalized_event['operator_code'] ?? ''),
            'operator_name' => (string) ($normalized_event['operator_name'] ?? ''),
            'message_text' => $message_text,
            'nwc' => $nwc,
            'price' => $transaction_data['price'],
        ];

        $blocked_reason = $this->resolve_blocked_reason($submit_transaction);

        if ($blocked_reason !== null) {
            $blocked_event = $this->build_blocked_event(
                $service_key,
                $transaction_data,
                $normalized_event,
                $blocked_reason
            );
            $blocked_event_record = $this->event_repository->insert_if_new($blocked_event);

            $this->flow_transaction_repository->update($transaction_id, [
                'last_event_id' => (int) ($blocked_event_record['row']['id'] ?? 0),
                'current_status' => (string) $blocked_event['status'],
                'is_terminal' => 1,
                'meta_json' => [
                    'initial_event' => $normalized_event,
                    'blocked_event' => $blocked_event,
                ],
            ]);

            return [
                'success' => false,
                'message' => (string) $blocked_event['status'],
                'event' => $event_record['row'],
                'submit_event' => $blocked_event_record['row'],
                'transaction' => $this->flow_transaction_repository->get_by_id($transaction_id),
            ];
        }

        $submit_response = $this->client->submit_message($service_key, $submit_transaction);

        $submit_event = $this->normalizer->normalize_submit_response($service_key, $transaction, $submit_response);
        $submit_event_record = $this->event_repository->insert_if_new($submit_event);

        $this->flow_transaction_repository->update($transaction_id, [
            'last_event_id' => (int) ($submit_event_record['row']['id'] ?? 0),
            'mt_submit_event_id' => (int) ($submit_event_record['row']['id'] ?? 0),
            'external_message_id' => (string) ($submit_event['external_message_id'] ?? ''),
            'current_status' => (string) ($submit_event['status'] ?? 'mt_submit_failed'),
            'is_terminal' => !empty($submit_event['is_terminal']) ? 1 : 0,
            'meta_json' => [
                'initial_event' => $normalized_event,
                'submit_event' => $submit_event,
            ],
        ]);

        $transaction_after_submit = $this->flow_transaction_repository->get_by_id($transaction_id);
        $transaction_after_submit = is_array($transaction_after_submit) ? $transaction_after_submit : $transaction;
        $this->maybe_attach_click_attribution(
            $service_key,
            $transaction_after_submit,
            $normalized_event,
            $submit_event
        );

        return [
            'success' => (bool) ($submit_event['is_success'] ?? false),
            'message' => (string) ($submit_event['status'] ?? ''),
            'event' => $event_record['row'],
            'submit_event' => $submit_event_record['row'],
            'transaction' => $transaction_after_submit,
        ];
    }

    public function handle_notification(string $service_key, array $payload): array
    {
        $normalized_event = $this->normalizer->normalize_callback($service_key, 'notification', $payload);
        $event_record = $this->event_repository->insert_if_new($normalized_event);

        if (!$event_record['inserted']) {
            $transaction = $this->resolve_transaction_for_notification($service_key, $normalized_event);
            $attribution_result = is_array($transaction)
                ? $this->maybe_handle_conversion_attribution($service_key, $transaction, $normalized_event)
                : null;

            return [
                'success' => true,
                'message' => 'Duplicate notification callback ignored.',
                'event' => $event_record['row'],
                'transaction' => $transaction,
                'attribution' => $attribution_result,
            ];
        }

        $transaction = $this->resolve_transaction_for_notification($service_key, $normalized_event);

        if (!is_array($transaction)) {
            return [
                'success' => false,
                'message' => 'No matching FR one-off transaction found for NTH notification.',
                'event' => $event_record['row'],
            ];
        }

        $update_data = [
            'last_event_id' => (int) ($event_record['row']['id'] ?? 0),
            'last_report_event_id' => (int) ($event_record['row']['id'] ?? 0),
            'current_status' => (string) ($normalized_event['status'] ?? 'unknown'),
            'is_terminal' => !empty($normalized_event['is_terminal']) ? 1 : 0,
        ];

        if (trim((string) ($normalized_event['external_message_id'] ?? '')) !== '') {
            $update_data['external_message_id'] = (string) $normalized_event['external_message_id'];
        }

        $sale = null;

        if (!empty($normalized_event['is_terminal']) && !empty($normalized_event['is_success'])) {
            $sale = $this->sales_recorder->record_successful_one_off_sale($transaction, $normalized_event);
            $update_data['sale_id'] = (int) ($sale['id'] ?? 0);
        }

        $this->flow_transaction_repository->update((int) $transaction['id'], $update_data);
        $attribution_result = $this->maybe_handle_conversion_attribution(
            $service_key,
            $transaction,
            $normalized_event
        );

        return [
            'success' => !empty($normalized_event['is_success']),
            'message' => (string) ($normalized_event['status'] ?? ''),
            'event' => $event_record['row'],
            'transaction' => $this->flow_transaction_repository->get_by_id((int) $transaction['id']),
            'sale' => $sale,
            'attribution' => $attribution_result,
        ];
    }

    private function maybe_attach_click_attribution(
        string $service_key,
        array $transaction,
        array $normalized_event,
        array $submit_event
    ): void {
        if (!$this->conversion_attribution_resolver instanceof Kiwi_Conversion_Attribution_Resolver) {
            return;
        }

        $tracking_token = trim((string) ($transaction['landing_session_token'] ?? ''));
        $reference_hint = trim((string) ($normalized_event['external_request_id'] ?? ''));
        $transaction_id = $this->resolve_attribution_transaction_id($service_key, $reference_hint);

        if ($transaction_id === '') {
            $transaction_id = $this->extract_transaction_id_from_flow_reference((string) ($transaction['flow_reference'] ?? ''));
        }

        if ($tracking_token === '' && $reference_hint === '' && $transaction_id === '') {
            return;
        }

        $this->conversion_attribution_resolver->attach_provider_references([
            'provider_key' => 'nth',
            'service_key' => $service_key,
            'flow_key' => (string) ($transaction['flow_key'] ?? ''),
            'tracking_token' => $tracking_token,
            'reference_hint' => $reference_hint,
            'session_ref' => $reference_hint,
            'external_ref' => $reference_hint,
            'transaction_id' => $transaction_id,
            'transaction_ref' => (string) ($transaction['flow_reference'] ?? ''),
            'message_ref' => (string) ($submit_event['external_message_id'] ?? ''),
            'sale_reference' => (string) ($transaction['sale_reference'] ?? ''),
        ]);
    }

    private function maybe_handle_conversion_attribution(
        string $service_key,
        array $transaction,
        array $normalized_event
    ): ?array {
        if (!$this->conversion_attribution_resolver instanceof Kiwi_Conversion_Attribution_Resolver) {
            return null;
        }

        $flow_reference = (string) ($transaction['flow_reference'] ?? '');

        return $this->conversion_attribution_resolver->handle_confirmed_conversion([
            'provider_key' => 'nth',
            'service_key' => $service_key,
            'flow_key' => (string) ($transaction['flow_key'] ?? ''),
            'confirmed' => !empty($normalized_event['is_terminal']) && !empty($normalized_event['is_success']),
            'occurred_at' => (string) ($normalized_event['occurred_at'] ?? ''),
            'transaction_id' => $this->extract_transaction_id_from_flow_reference($flow_reference),
            'transaction_ref' => $flow_reference,
            'message_ref' => (string) ($normalized_event['external_message_id'] ?? ($transaction['external_message_id'] ?? '')),
            'external_ref' => (string) ($normalized_event['external_request_id'] ?? ''),
            'session_ref' => (string) ($normalized_event['external_request_id'] ?? ''),
            'sale_reference' => (string) ($transaction['sale_reference'] ?? ''),
        ]);
    }

    private function resolve_transaction_for_notification(string $service_key, array $normalized_event): ?array
    {
        $refs = array_filter([
            (string) ($normalized_event['external_message_id'] ?? ''),
            (string) ($normalized_event['external_request_id'] ?? ''),
            (string) ($normalized_event['external_report_id'] ?? ''),
        ]);

        if (!empty($refs)) {
            $transaction = $this->flow_transaction_repository->find_recent_by_external_references(
                $service_key,
                array_values($refs)
            );

            if (is_array($transaction)) {
                return $transaction;
            }
        }

        $subscriber_reference = trim((string) ($normalized_event['subscriber_reference'] ?? ''));
        $shortcode = trim((string) ($normalized_event['shortcode'] ?? ''));
        $keyword = trim((string) ($normalized_event['keyword'] ?? ''));

        if ($subscriber_reference === '' || $shortcode === '' || $keyword === '') {
            return null;
        }

        return $this->flow_transaction_repository->find_active_by_subscriber_context(
            $service_key,
            $subscriber_reference,
            $shortcode,
            $keyword,
            24
        );
    }

    private function resolve_nwc(array $service, array $normalized_event): string
    {
        $map = isset($service['operator_nwc_map']) && is_array($service['operator_nwc_map'])
            ? $service['operator_nwc_map']
            : [];

        $operator_code = trim((string) ($normalized_event['operator_code'] ?? ''));
        $operator_name = trim((string) ($normalized_event['operator_name'] ?? ''));

        if ($operator_code !== '' && isset($map[$operator_code])) {
            return (string) $map[$operator_code];
        }

        if ($operator_name !== '' && isset($map[$operator_name])) {
            return (string) $map[$operator_name];
        }

        if ($operator_code !== '') {
            return $operator_code;
        }

        if ($operator_name !== '' && isset($map[$this->normalize_operator_name($operator_name)])) {
            return (string) $map[$this->normalize_operator_name($operator_name)];
        }

        return trim((string) ($service['default_nwc'] ?? ''));
    }

    private function resolve_blocked_reason(array $submit_transaction): ?array
    {
        if (trim((string) ($submit_transaction['nwc'] ?? '')) === '') {
            return [
                'status' => 'routing_data_missing',
                'detail' => 'No NWC could be resolved for the FR one-off MO.',
            ];
        }

        if (trim((string) ($submit_transaction['message_text'] ?? '')) === '') {
            return [
                'status' => 'mt_message_missing',
                'detail' => 'No MT message text could be generated for the FR one-off MO.',
            ];
        }

        if ((int) ($submit_transaction['price'] ?? 0) <= 0) {
            return [
                'status' => 'price_missing',
                'detail' => 'No valid FR one-off price is configured for MT submission.',
            ];
        }

        return null;
    }

    private function build_blocked_event(
        string $service_key,
        array $transaction_data,
        array $normalized_event,
        array $blocked_reason
    ): array {
        $status = (string) ($blocked_reason['status'] ?? 'blocked');
        $detail = (string) ($blocked_reason['detail'] ?? '');

        return [
            'provider' => 'nth',
            'service_key' => $service_key,
            'country' => (string) ($transaction_data['country'] ?? ''),
            'flow_key' => (string) ($transaction_data['flow_key'] ?? ''),
            'direction' => 'internal',
            'event_type' => 'mt_submission_blocked',
            'external_event_type' => 'internal_block',
            'external_request_id' => (string) ($transaction_data['flow_reference'] ?? ''),
            'external_message_id' => '',
            'external_report_id' => '',
            'subscriber_reference' => (string) ($transaction_data['subscriber_reference'] ?? ''),
            'shortcode' => (string) ($transaction_data['shortcode'] ?? ''),
            'keyword' => (string) ($transaction_data['keyword'] ?? ''),
            'message_text' => (string) ($transaction_data['message_text'] ?? ''),
            'operator_code' => (string) ($transaction_data['operator_code'] ?? ''),
            'operator_name' => (string) ($transaction_data['operator_name'] ?? ''),
            'status' => $status,
            'is_terminal' => true,
            'is_success' => false,
            'occurred_at' => $this->current_time_mysql(),
            'raw_payload' => [
                'reason' => $blocked_reason,
                'normalized_event' => $normalized_event,
                'transaction_data' => $transaction_data,
            ],
            'dedupe_key' => sha1(
                implode('|', [
                    'nth',
                    $service_key,
                    'mt_submission_blocked',
                    (string) ($transaction_data['flow_reference'] ?? ''),
                    $status,
                ])
            ),
        ];
    }

    private function build_mt_message_text(array $service, array $normalized_event): string
    {
        $template = trim((string) ($service['mt_message_template'] ?? ''));

        if ($template === '') {
            $price_label = (string) ($service['landing_price_label'] ?? '4,50 EUR par SMS + prix d\'un SMS');

            return 'Merci pour votre achat. '
                . $price_label
                . '. Ceci n\'est pas un abonnement.';
        }

        $replacements = [
            '{keyword}' => (string) ($normalized_event['keyword'] ?? ($service['keyword'] ?? '')),
            '{shortcode}' => (string) ($normalized_event['shortcode'] ?? ($service['shortcode'] ?? '')),
            '{price}' => (string) ($service['price'] ?? ''),
            '{price_label}' => (string) ($service['landing_price_label'] ?? ''),
        ];

        return strtr($template, $replacements);
    }

    private function normalize_operator_name(string $operator_name): string
    {
        return preg_replace('/\s+/', ' ', trim($operator_name)) ?? '';
    }

    private function generate_reference(string $prefix): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return $prefix . '_' . wp_generate_uuid4();
        }

        return $prefix . '_' . md5(uniqid('', true));
    }

    private function resolve_attribution_transaction_id(string $service_key, string $reference_hint): string
    {
        if (!$this->conversion_attribution_resolver instanceof Kiwi_Conversion_Attribution_Resolver) {
            return '';
        }

        return $this->conversion_attribution_resolver->resolve_pending_transaction_id(
            $service_key,
            $reference_hint
        );
    }

    private function build_provider_flow_reference(string $transaction_id): string
    {
        $transaction_id = trim($transaction_id);

        if ($transaction_id === '') {
            return $this->generate_reference('nth');
        }

        $suffix = substr(md5(uniqid('', true)), 0, 12);

        return substr($transaction_id . '-' . $suffix, 0, 100);
    }

    private function extract_transaction_id_from_flow_reference(string $flow_reference): string
    {
        $flow_reference = trim($flow_reference);

        if ($flow_reference === '') {
            return '';
        }

        if (preg_match('/^(txn_[A-Za-z0-9]{12,120})(?:-[A-Za-z0-9]{1,64})?$/', $flow_reference, $matches)) {
            return (string) ($matches[1] ?? '');
        }

        return '';
    }

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
