<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Nth_Premium_Sms_Normalizer
{
    private $config;

    public function __construct(Kiwi_Config $config)
    {
        $this->config = $config;
    }

    public function normalize_callback(string $service_key, string $callback_type, array $payload): array
    {
        $service = $this->config->get_nth_service($service_key) ?? [];

        $message_text = $this->first_non_empty($payload, [
            'message',
            'message_text',
            'text',
            'body',
            'sms',
            'content',
            'keyword',
        ]);

        $subscriber_reference = $this->first_non_empty($payload, [
            'encrypted_msisdn',
            'encryptedmsisdn',
            'encryptedMsisdn',
            'msisdn',
            'subscriber',
            'subscriber_id',
            'address',
            'from',
            'sender',
        ]);

        $shortcode = $this->first_non_empty($payload, [
            'shortcode',
            'business_number',
            'businessnumber',
            'destination',
            'to',
            'receiver',
            'service_number',
        ]);

        if ($shortcode === '') {
            $shortcode = trim((string) ($service['shortcode'] ?? ''));
        }

        $operator_code = $this->first_non_empty($payload, [
            'nwc',
            'operator_code',
            'operatorcode',
            'network_code',
            'networkcode',
        ]);

        $operator_name = $this->first_non_empty($payload, [
            'operator',
            'operator_name',
            'operatorname',
            'network',
            'carrier',
        ]);

        $keyword = $this->normalize_keyword(
            $this->first_non_empty($payload, [
                'keyword',
                'service_keyword',
                'servicekeyword',
            ])
        );

        if ($keyword === '') {
            $keyword = $this->normalize_keyword(
                $this->extract_keyword_from_message($message_text)
            );
        }

        if ($keyword === '') {
            $keyword = $this->normalize_keyword((string) ($service['keyword'] ?? ''));
        }

        $external_event_type = strtolower($this->first_non_empty($payload, [
            'command',
            'operation',
            'action',
            'event',
            'event_type',
            'eventtype',
            'callback_type',
            'callbacktype',
        ]));

        if ($external_event_type === '') {
            $external_event_type = $callback_type === 'mo'
                ? 'delivermessage'
                : 'notification';
        }

        $external_request_id = $this->first_non_empty($payload, [
            'request_id',
            'requestid',
            'reference',
            'message_ref',
            'messageref',
            'ref',
            'session_id',
            'sessionid',
        ]);

        $external_message_id = $this->first_non_empty($payload, [
            'message_id',
            'messageid',
            'mt_message_id',
            'mtmessageid',
            'transaction_id',
            'transactionid',
            'sms_id',
            'smsid',
        ]);

        $external_report_id = $this->first_non_empty($payload, [
            'report_id',
            'reportid',
            'notification_id',
            'notificationid',
            'delivery_id',
            'deliveryid',
        ]);

        $raw_status = $this->first_non_empty($payload, [
            'message_status',
            'messagestatus',
            'status',
            'delivery_status',
            'deliverystatus',
            'report_status',
            'reportstatus',
            'result',
            'state',
            'code',
        ]);

        $normalized_status = $this->normalize_status($callback_type, $raw_status);

        $event_timestamp = $this->normalize_timestamp(
            $this->first_non_empty($payload, [
                'timestamp',
                'created_at',
                'createdat',
                'datetime',
                'date',
                'time',
                'sent_at',
                'sentat',
                'delivered_at',
                'deliveredat',
            ])
        );

        $normalized = [
            'provider' => 'nth',
            'service_key' => $service_key,
            'country' => (string) ($service['country'] ?? ''),
            'flow_key' => (string) ($service['flow'] ?? ''),
            'direction' => 'inbound',
            'event_type' => $callback_type === 'mo' ? 'mo_callback' : 'notification_callback',
            'external_event_type' => $external_event_type,
            'external_request_id' => $external_request_id,
            'external_message_id' => $external_message_id,
            'external_report_id' => $external_report_id,
            'subscriber_reference' => $subscriber_reference,
            'shortcode' => $shortcode,
            'keyword' => $keyword,
            'message_text' => $message_text,
            'operator_code' => $operator_code,
            'operator_name' => $operator_name,
            'status' => $normalized_status['status'],
            'is_terminal' => $normalized_status['is_terminal'],
            'is_success' => $normalized_status['is_success'],
            'occurred_at' => $event_timestamp,
            'raw_payload' => $payload,
        ];

        $normalized['dedupe_key'] = $this->build_dedupe_key($normalized);

        return $normalized;
    }

    public function normalize_submit_response(string $service_key, array $transaction, array $response): array
    {
        $service = $this->config->get_nth_service($service_key) ?? [];
        $xml = trim((string) ($response['body'] ?? $response['xml'] ?? ''));
        $http_status = (int) ($response['status_code'] ?? 0);
        $xml_object = null;

        if ($xml !== '') {
            libxml_use_internal_errors(true);
            $xml_object = simplexml_load_string($xml);
            if ($xml_object === false) {
                libxml_clear_errors();
                $xml_object = null;
            }
        }

        $external_request_id = trim((string) ($transaction['flow_reference'] ?? ''));
        $external_message_id = $xml_object instanceof SimpleXMLElement
            ? $this->first_xml_value($xml_object, [
                'message_id',
                'messageId',
                'mt_message_id',
                'id',
                'reference',
                'transaction_id',
            ])
            : '';

        if ($external_message_id === '') {
            $external_message_id = trim((string) ($transaction['external_message_id'] ?? ''));
        }

        $raw_status = $xml_object instanceof SimpleXMLElement
            ? $this->first_xml_value($xml_object, [
                'status',
                'state',
                'result',
                'code',
                'delivery_status',
            ])
            : '';

        if ($raw_status === '' && $http_status >= 200 && $http_status < 300) {
            $raw_status = 'submitted';
        } elseif ($raw_status === '') {
            $raw_status = 'failed';
        }

        $normalized_status = $this->normalize_submit_status($raw_status, $http_status);

        $normalized = [
            'provider' => 'nth',
            'service_key' => $service_key,
            'country' => (string) ($service['country'] ?? ''),
            'flow_key' => (string) ($service['flow'] ?? ''),
            'direction' => 'outbound',
            'event_type' => 'mt_submit_response',
            'external_event_type' => 'submitmessage',
            'external_request_id' => $external_request_id,
            'external_message_id' => $external_message_id,
            'external_report_id' => '',
            'subscriber_reference' => trim((string) ($transaction['subscriber_reference'] ?? '')),
            'shortcode' => trim((string) ($transaction['shortcode'] ?? ($service['shortcode'] ?? ''))),
            'keyword' => trim((string) ($transaction['keyword'] ?? ($service['keyword'] ?? ''))),
            'message_text' => trim((string) ($transaction['message_text'] ?? '')),
            'operator_code' => trim((string) ($transaction['operator_code'] ?? '')),
            'operator_name' => trim((string) ($transaction['operator_name'] ?? '')),
            'status' => $normalized_status['status'],
            'is_terminal' => $normalized_status['is_terminal'],
            'is_success' => $normalized_status['is_success'],
            'occurred_at' => $this->current_time_mysql(),
            'raw_payload' => $response,
        ];

        $normalized['dedupe_key'] = sha1(
            implode('|', [
                'nth',
                $service_key,
                'mt_submit_response',
                $external_request_id,
                $external_message_id,
                (string) $http_status,
                $normalized['status'],
            ])
        );

        return $normalized;
    }

    private function normalize_status(string $callback_type, string $raw_status): array
    {
        if ($callback_type === 'mo') {
            return [
                'status' => 'received',
                'is_terminal' => false,
                'is_success' => true,
            ];
        }

        $value = strtolower(trim($raw_status));

        if ($value === '') {
            return [
                'status' => 'unknown',
                'is_terminal' => false,
                'is_success' => false,
            ];
        }

        if (preg_match('/^-?\d+$/', $value)) {
            $numeric_status = $this->normalize_numeric_notification_status((int) $value);

            if (is_array($numeric_status)) {
                return $numeric_status;
            }
        }

        $success_values = ['0', '2', 'success', 'ok', 'delivered', 'delivery_success', 'deliverysuccess', 'paid', 'billed', 'charged'];
        $failure_values = ['1', 'failed', 'failure', 'error', 'delivery_failed', 'deliveryfailed', 'undelivered', 'rejected'];
        $pending_values = ['intermediate', 'pending', 'submitted', 'accepted', 'queued', 'processing', 'in_progress'];

        if (in_array($value, $success_values, true) || strpos($value, 'deliver') !== false && strpos($value, 'fail') === false) {
            return [
                'status' => 'delivered',
                'is_terminal' => true,
                'is_success' => true,
            ];
        }

        if (in_array($value, $failure_values, true) || strpos($value, 'fail') !== false) {
            return [
                'status' => 'failed',
                'is_terminal' => true,
                'is_success' => false,
            ];
        }

        if (in_array($value, $pending_values, true)) {
            return [
                'status' => 'intermediate',
                'is_terminal' => false,
                'is_success' => false,
            ];
        }

        return [
            'status' => $value,
            'is_terminal' => false,
            'is_success' => false,
        ];
    }

    private function normalize_numeric_notification_status(int $code): ?array
    {
        if (in_array($code, [1, 3, 23], true)) {
            return [
                'status' => 'intermediate',
                'is_terminal' => false,
                'is_success' => false,
            ];
        }

        if ($code === 2) {
            return [
                'status' => 'delivered',
                'is_terminal' => true,
                'is_success' => true,
            ];
        }

        if ($code === 14) {
            return [
                'status' => 'presumably_delivered',
                'is_terminal' => true,
                'is_success' => true,
            ];
        }

        if ($code === 24) {
            return [
                'status' => 'charged_not_delivered',
                'is_terminal' => true,
                'is_success' => true,
            ];
        }

        if ($code === 25) {
            return [
                'status' => 'delivered_payment_risk',
                'is_terminal' => true,
                'is_success' => true,
            ];
        }

        if ($code === 26) {
            return [
                'status' => 'provider_generated',
                'is_terminal' => true,
                'is_success' => false,
            ];
        }

        if (in_array($code, [-3, -4, -5, -6, -9, -11, -12, -14, -20, -21, -28, -29, -31, -33, -34, -35, -36, -37, -38], true)) {
            return [
                'status' => 'failed',
                'is_terminal' => true,
                'is_success' => false,
            ];
        }

        return null;
    }

    private function normalize_submit_status(string $raw_status, int $http_status): array
    {
        $value = strtolower(trim($raw_status));

        if ($value === 'failed' || $http_status < 200 || $http_status >= 300) {
            return [
                'status' => 'mt_submit_failed',
                'is_terminal' => true,
                'is_success' => false,
            ];
        }

        return [
            'status' => 'mt_submitted',
            'is_terminal' => false,
            'is_success' => true,
        ];
    }

    private function build_dedupe_key(array $normalized): string
    {
        $signature = [
            'event_type' => $normalized['event_type'] ?? '',
            'external_event_type' => $normalized['external_event_type'] ?? '',
            'external_request_id' => $normalized['external_request_id'] ?? '',
            'external_message_id' => $normalized['external_message_id'] ?? '',
            'external_report_id' => $normalized['external_report_id'] ?? '',
            'subscriber_reference' => $normalized['subscriber_reference'] ?? '',
            'shortcode' => $normalized['shortcode'] ?? '',
            'keyword' => $normalized['keyword'] ?? '',
            'message_text' => $normalized['message_text'] ?? '',
            'operator_code' => $normalized['operator_code'] ?? '',
            'operator_name' => $normalized['operator_name'] ?? '',
            'status' => $normalized['status'] ?? '',
        ];

        return sha1(($normalized['service_key'] ?? '') . '|' . wp_json_encode($signature));
    }

    private function first_non_empty(array $payload, array $aliases): string
    {
        $normalized_payload = [];

        foreach ($payload as $key => $value) {
            $normalized_payload[strtolower((string) $key)] = $value;
        }

        foreach ($aliases as $alias) {
            $normalized_alias = strtolower($alias);

            if (!array_key_exists($normalized_alias, $normalized_payload)) {
                continue;
            }

            $value = trim((string) $normalized_payload[$normalized_alias]);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function first_xml_value(SimpleXMLElement $xml_object, array $aliases): string
    {
        foreach ($aliases as $alias) {
            $matches = $xml_object->xpath('//*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "' . strtolower($alias) . '"]');

            if (!is_array($matches) || empty($matches)) {
                continue;
            }

            foreach ($matches as $match) {
                $value = trim((string) $match);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function extract_keyword_from_message(string $message_text): string
    {
        $message_text = trim($message_text);

        if ($message_text === '') {
            return '';
        }

        $parts = preg_split('/[\s+]+/', $message_text);

        if (!is_array($parts) || empty($parts)) {
            return '';
        }

        return (string) $parts[0];
    }

    private function normalize_keyword(string $keyword): string
    {
        $keyword = strtoupper(trim($keyword));
        $keyword = rtrim($keyword, '*');

        return preg_replace('/[^A-Z0-9]/', '', $keyword) ?? '';
    }

    private function normalize_timestamp(string $timestamp): string
    {
        $timestamp = trim($timestamp);

        if ($timestamp === '') {
            return $this->current_time_mysql();
        }

        $unix_timestamp = strtotime($timestamp);

        if ($unix_timestamp === false) {
            return $this->current_time_mysql();
        }

        return gmdate('Y-m-d H:i:s', $unix_timestamp);
    }

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
