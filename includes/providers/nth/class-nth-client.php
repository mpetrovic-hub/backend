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

        $response = wp_remote_post($endpoint, [
            'timeout' => $this->config->get_nth_submit_timeout(),
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
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

        return [
            'success' => $status_code >= 200 && $status_code < 300,
            'status_code' => $status_code,
            'error' => '',
            'request' => $body,
            'body' => $response_body,
        ];
    }

    private function get_default_submit_message_body(): array
    {
        return [
            'operation' => 'submitMessage',
            'username' => '{username}',
            'password' => '{password}',
            'msisdn' => '{subscriber_reference}',
            'shortcode' => '{shortcode}',
            'message' => '{message_text}',
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
        if (array_key_exists('message_ref', $body) && !array_key_exists('messageRef', $body)) {
            $body['messageRef'] = (string) $body['message_ref'];
            unset($body['message_ref']);
        }

        if (array_key_exists('messageref', $body) && !array_key_exists('messageRef', $body)) {
            $body['messageRef'] = (string) $body['messageref'];
            unset($body['messageref']);
        }

        if (array_key_exists('reference', $body) && !array_key_exists('messageRef', $body)) {
            $body['messageRef'] = (string) $body['reference'];
            unset($body['reference']);
        }

        return $body;
    }

    private function validate_required_placeholder_values(array $placeholder_values): array
    {
        $required_fields = [
            'flow_reference',
            'message_text',
            'nwc',
            'password',
            'price',
            'shortcode',
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
}
