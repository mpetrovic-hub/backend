<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Dimoco_Client
{
    private $config;
    private $digest_builder;

    public function __construct(
        Kiwi_Config $config,
        Kiwi_Dimoco_Digest $digest_builder
    ) {
        $this->config = $config;
        $this->digest_builder = $digest_builder;
    }

    /**
     * Send refund request to DIMOCO
     */
    public function refund(string $service_key, string $transaction_id): array
    {
        $service = $this->config->get_dimoco_service($service_key);

        if (!is_array($service)) {
            return [
                'success' => false,
                'error'   => 'Unknown DIMOCO service key.',
            ];
        }

        $merchant = $service['merchant'] ?? '';
        $secret   = $service['secret'] ?? '';
        $order_id = $service['order_id'] ?? '';

        $base_url     = $this->config->get_dimoco_base_url();
        $callback_url = $this->config->get_dimoco_callback_url();

        if ($merchant === '' || $secret === '' || $order_id === '' || $base_url === '' || $callback_url === '') {
            return [
                'success' => false,
                'error'   => 'Incomplete DIMOCO configuration.',
            ];
        }

        $params = [
            'action'       => 'refund',
            'merchant'     => $merchant,
            'order'        => $order_id,
            'request_id'   => $this->generate_request_id(),
            'transaction'  => trim($transaction_id),
            'url_callback' => $callback_url,
        ];

        $params['digest'] = $this->digest_builder->create($params, $secret);

        //Error logging for debugging purposes - remove in production
        error_log('================ DIMOCO REQUEST START ================');
        error_log('URL: ' . $base_url);
        error_log('PAYLOAD: ' . wp_json_encode($params));
        error_log('DIMOCO DIGEST: ' . ($params['digest'] ?? 'MISSING'));
        error_log('================ DIMOCO REQUEST END ==================');
        error_log('DIMOCO DIGEST: ' . ($payload['digest'] ?? 'MISSING'));

        $response = wp_remote_post($base_url, [
            'timeout' => $this->config->get_http_timeout(),
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            ],
            'body' => $params,
        ]);

        //Error logging for debugging purposes - remove in production
        error_log('================ DIMOCO RESPONSE START ================');
        if (is_wp_error($response)) {
            error_log('WP ERROR: ' . $response->get_error_message());
        } else {
            error_log('STATUS CODE: ' . wp_remote_retrieve_response_code($response));
            error_log('BODY: ' . wp_remote_retrieve_body($response));
        }

error_log('================ DIMOCO RESPONSE END ==================');

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error'   => $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body        = wp_remote_retrieve_body($response);

        return [
            'success'     => ($status_code >= 200 && $status_code < 300),
            'status_code' => $status_code,
            'request'     => $params,
            'xml'         => $body,
        ];
    }

    /**
     * Generate unique DIMOCO request_id
     */
    private function generate_request_id(): string
    {
        return wp_generate_uuid4();
    }
}