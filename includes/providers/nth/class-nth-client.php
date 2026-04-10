<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Nth_Client
{
    private $config;

    public function __construct(Kiwi_Config $config)
    {
        $this->config = $config;
    }

    public function submit_message(string $service_key, array $transaction): array
    {
        $service = $this->config->get_nth_service($service_key);

        if (!is_array($service)) {
            return [
                'success' => false,
                'status_code' => 0,
                'error' => 'Unknown NTH service key.',
                'request' => [],
                'body' => '',
            ];
        }

        $endpoint = rtrim((string) ($service['mt_submission_url'] ?? ''), '/');
        $username = trim((string) ($service['username'] ?? ''));
        $password = trim((string) ($service['password'] ?? ''));

        if ($endpoint === '' || $username === '' || $password === '') {
            return [
                'success' => false,
                'status_code' => 0,
                'error' => 'Incomplete NTH configuration.',
                'request' => [],
                'body' => '',
            ];
        }

        $body_template = $service['submit_message_body'] ?? $this->get_default_submit_message_body();

        if (!is_array($body_template) || empty($body_template)) {
            return [
                'success' => false,
                'status_code' => 0,
                'error' => 'Missing NTH submitMessage body template.',
                'request' => [],
                'body' => '',
            ];
        }

        $placeholder_values = [
            'country' => (string) ($service['country'] ?? ''),
            'encoding' => (string) ($service['encoding'] ?? 'UTF-8'),
            'flow_reference' => trim((string) ($transaction['flow_reference'] ?? '')),
            'keyword' => trim((string) ($transaction['keyword'] ?? ($service['keyword'] ?? ''))),
            'message_text' => trim((string) ($transaction['message_text'] ?? '')),
            'nwc' => trim((string) ($transaction['nwc'] ?? '')),
            'password' => $password,
            'price' => (string) ($transaction['price'] ?? ($service['price'] ?? '')),
            'service_key' => $service_key,
            'shortcode' => trim((string) ($transaction['shortcode'] ?? ($service['shortcode'] ?? ''))),
            'subscriber_reference' => trim((string) ($transaction['subscriber_reference'] ?? '')),
            'username' => $username,
        ];

        $missing_fields = $this->validate_required_placeholder_values($placeholder_values);

        if (!empty($missing_fields)) {
            return [
                'success' => false,
                'status_code' => 0,
                'error' => 'Missing required NTH submitMessage values: ' . implode(', ', $missing_fields),
                'request' => [],
                'body' => '',
            ];
        }

        $body = $this->resolve_template($body_template, $placeholder_values);
        $body = $this->normalize_submit_message_body($body);

        if (empty($body)) {
            return [
                'success' => false,
                'status_code' => 0,
                'error' => 'NTH submitMessage body resolved to an empty payload.',
                'request' => [],
                'body' => '',
            ];
        }

        $template_contract_error = $this->validate_submit_message_body_contract($body);

        if ($template_contract_error !== '') {
            $this->log_submit('validation_error', [
                'service_key' => $service_key,
                'endpoint' => $endpoint,
                'flow_reference' => (string) ($placeholder_values['flow_reference'] ?? ''),
                'error' => $template_contract_error,
                'request_keys' => array_values(array_map('strval', array_keys($body))),
                'request_body' => $this->config->is_nth_callback_payload_logging_enabled()
                    ? $body
                    : [],
            ]);

            return [
                'success' => false,
                'status_code' => 0,
                'error' => $template_contract_error,
                'request' => $body,
                'body' => '',
            ];
        }

        $this->log_submit('outgoing', [
            'service_key' => $service_key,
            'endpoint' => $endpoint,
            'timeout_seconds' => $this->config->get_nth_submit_timeout(),
            'flow_reference' => (string) ($placeholder_values['flow_reference'] ?? ''),
            'request_keys' => array_values(array_map('strval', array_keys($body))),
            'request_body' => $this->config->is_nth_callback_payload_logging_enabled()
                ? $body
                : [],
        ]);

        $response = wp_remote_post($endpoint, [
            'timeout' => $this->config->get_nth_submit_timeout(),
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            $this->log_submit('response_error', [
                'service_key' => $service_key,
                'endpoint' => $endpoint,
                'flow_reference' => (string) ($placeholder_values['flow_reference'] ?? ''),
                'status_code' => 0,
                'success' => false,
                'error' => $response->get_error_message(),
            ]);

            return [
                'success' => false,
                'status_code' => 0,
                'error' => $response->get_error_message(),
                'request' => $body,
                'body' => '',
            ];
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $response_body = (string) wp_remote_retrieve_body($response);
        $success = $status_code >= 200 && $status_code < 300;

        $this->log_submit('response', [
            'service_key' => $service_key,
            'endpoint' => $endpoint,
            'flow_reference' => (string) ($placeholder_values['flow_reference'] ?? ''),
            'status_code' => $status_code,
            'success' => $success,
            'response_body' => $this->config->is_nth_callback_payload_logging_enabled()
                ? $response_body
                : '',
        ]);

        return [
            'success' => $success,
            'status_code' => $status_code,
            'error' => '',
            'request' => $body,
            'body' => $response_body,
        ];
    }

    private function get_default_submit_message_body(): array
    {
        return [
            'command' => 'submitMessage',
            'username' => '{username}',
            'password' => '{password}',
            'msisdn' => '{subscriber_reference}',
            'businessNumber' => '{shortcode}',
            'content' => '{message_text}',
            'price' => '{price}',
            'nwc' => '{nwc}',
            'encoding' => '{encoding}',
            'messageRef' => '{flow_reference}',
        ];
    }

    private function resolve_template(array $template, array $values): array
    {
        $resolved = [];

        foreach ($template as $key => $value) {
            $resolved_key = trim((string) $key);

            if ($resolved_key === '' || is_array($value)) {
                continue;
            }

            $resolved[$resolved_key] = preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', static function (array $matches) use ($values): string {
                $placeholder = $matches[1] ?? '';
                return isset($values[$placeholder]) ? (string) $values[$placeholder] : '';
            }, (string) $value);
        }

        return $resolved;
    }

    private function normalize_submit_message_body(array $body): array
    {
        return $body;
    }

    private function validate_submit_message_body_contract(array $body): string
    {
        $legacy_keys = [
            'operation',
            'message',
            'shortcode',
            'reference',
            'message_ref',
            'messageref',
        ];
        $present_legacy_keys = [];

        foreach ($legacy_keys as $legacy_key) {
            if (array_key_exists($legacy_key, $body)) {
                $present_legacy_keys[] = $legacy_key;
            }
        }

        if (!empty($present_legacy_keys)) {
            return 'Unsupported legacy NTH submitMessage template keys: '
                . implode(', ', $present_legacy_keys)
                . '. Use command/content/businessNumber/messageRef.';
        }

        $required_keys = ['command', 'content', 'businessNumber', 'messageRef'];
        $missing_keys = [];

        foreach ($required_keys as $required_key) {
            if (!array_key_exists($required_key, $body) || trim((string) $body[$required_key]) === '') {
                $missing_keys[] = $required_key;
            }
        }

        if (!empty($missing_keys)) {
            return 'NTH submitMessage body missing required keys: ' . implode(', ', $missing_keys) . '.';
        }

        $command = strtolower(trim((string) ($body['command'] ?? '')));

        if ($command !== 'submitmessage') {
            return 'NTH submitMessage body has invalid command value. Expected submitMessage.';
        }

        return '';
    }

    private function validate_required_placeholder_values(array $placeholder_values): array
    {
        $required_fields = [
            'flow_reference',
            'message_text',
            'nwc',
            'password',
            'price',
            'subscriber_reference',
            'username',
        ];
        $missing_fields = [];

        foreach ($required_fields as $field) {
            if (trim((string) ($placeholder_values[$field] ?? '')) !== '') {
                continue;
            }

            $missing_fields[] = $field;
        }

        return $missing_fields;
    }

    private function log_submit(string $stage, array $context = []): void
    {
        if (!$this->config->is_nth_callback_logging_enabled()) {
            return;
        }

        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($this->sanitize_for_log($context))
            : json_encode($this->sanitize_for_log($context));
        $encoded = is_string($encoded) ? $encoded : '{}';

        error_log('[kiwi-nth-submit] ' . $stage . ' ' . $encoded);
    }

    private function sanitize_for_log($value)
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $child) {
                $normalized_key = strtolower((string) $key);

                if (preg_match('/(password|secret|token|digest|signature|auth)/', $normalized_key)) {
                    $sanitized[$key] = '[redacted]';
                    continue;
                }

                if (preg_match('/(msisdn|subscriber|phone|mobile)/', $normalized_key)) {
                    $sanitized[$key] = $this->mask_identifier((string) $child);
                    continue;
                }

                $sanitized[$key] = $this->sanitize_for_log($child);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            return '[object]';
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return '';
        }

        return strlen($text) > 500
            ? substr($text, 0, 500) . '...[truncated]'
            : $text;
    }

    private function mask_identifier(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $length = strlen($value);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 2) . str_repeat('*', $length - 4) . substr($value, -2);
    }
}
