<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Lily_Response_Parser
{
    public function parse_hlr_response(array $response): array
    {
        $success     = $response['success'] ?? false;
        $status_code = $response['status_code'] ?? 0;
        $data        = $response['data'] ?? [];

        $api_status  = $data['status'] ?? '';
        $messages    = $data['messages'] ?? [];
        $payload     = $data['payload'] ?? [];

        return [
            'success'     => (bool) $success,
            'provider'    => 'lily',
            'feature'     => 'hlr',
            'status_code' => (int) $status_code,
            'api_status'  => $api_status,
            'hlr_status'  => $payload['hlrStatus'] ?? '',
            'operator'    => $payload['operator'] ?? '',
            'msisdn'      => $payload['msisdn'] ?? '',
            'messages'    => is_array($messages) ? $messages : [],
            'raw'         => $response,
        ];
    }
}