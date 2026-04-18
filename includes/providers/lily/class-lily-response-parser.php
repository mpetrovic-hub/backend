<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Lily_Response_Parser
{
    public function parse_hlr_response(array $response): array
    {
        $http_success = (bool) ($response['http_success'] ?? $response['success'] ?? false);
        $status_code = (int) ($response['status_code'] ?? 0);
        $data = $response['data'] ?? [];
        $error = trim((string) ($response['error'] ?? ''));

        if (!is_array($data)) {
            $data = [];
        }

        $api_status = trim((string) ($data['status'] ?? ''));
        $messages = $this->normalize_messages($data['messages'] ?? []);
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];

        $hlr_status = trim((string) ($payload['hlrStatus'] ?? ''));
        $operator = trim((string) ($payload['operator'] ?? ''));
        $msisdn = trim((string) ($payload['msisdn'] ?? ''));

        $business_success = $http_success
            && $api_status !== ''
            && strtoupper($api_status) === 'OK'
            && $this->is_successful_hlr_status($hlr_status);

        $failure_reason = '';
        if (!$http_success) {
            $failure_reason = 'Transport HTTP status is non-2xx.';
        } elseif (empty($data)) {
            $failure_reason = 'Missing or invalid JSON response body.';
        } elseif (strtoupper($api_status) !== 'OK') {
            $failure_reason = 'Lily API status is not OK.';
        } elseif (!$this->is_successful_hlr_status($hlr_status)) {
            $failure_reason = 'Lily HLR status is missing or not in allowed set (OK|SUCCESS).';
        }

        if ($error !== '' && !in_array($error, $messages, true)) {
            $messages[] = $error;
        }

        if (!$business_success && $failure_reason !== '' && !in_array($failure_reason, $messages, true)) {
            $messages[] = $failure_reason;
        }

        return [
            'success'     => $business_success,
            'http_success' => $http_success,
            'provider'    => 'lily',
            'feature'     => 'hlr',
            'status_code' => $status_code,
            'api_status'  => $api_status,
            'hlr_status'  => $hlr_status,
            'operator'    => $operator,
            'msisdn'      => $msisdn,
            'messages'    => $messages,
            'raw'         => $response,
        ];
    }

    private function is_successful_hlr_status(string $hlr_status): bool
    {
        $normalized = strtoupper(trim($hlr_status));

        return in_array($normalized, ['OK', 'SUCCESS'], true);
    }

    private function normalize_messages($messages): array
    {
        if (is_array($messages)) {
            $normalized = [];
            foreach ($messages as $message) {
                if (is_scalar($message) || (is_object($message) && method_exists($message, '__toString'))) {
                    $text = trim((string) $message);
                    if ($text !== '') {
                        $normalized[] = $text;
                    }
                }
            }

            return $normalized;
        }

        if (is_scalar($messages) || (is_object($messages) && method_exists($messages, '__toString'))) {
            $text = trim((string) $messages);
            return $text === '' ? [] : [$text];
        }

        return [];
    }
}
