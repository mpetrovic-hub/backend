<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Lily_Client
{
    private $config;

    public function __construct(Kiwi_Config $config)
    {
        $this->config = $config;
    }

    /**
     * Send HLR lookup request
     */
    public function hlr_lookup(string $msisdn): array
    {
        $base_url = $this->config->get_lily_base_url();
        $username = $this->config->get_lily_username();
        $password = $this->config->get_lily_password();

        $endpoint = $base_url . "/hlr/v2/{$username}/{$password}";

        $body = [
            'MSISDN' => $msisdn
        ];

        $response = wp_remote_post($endpoint, [                     //HTTP-request
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'timeout' => $this->config->get_http_timeout(),
            'body' => json_encode($body)
        ]);

        if (is_wp_error($response)) {
            return [
                'http_success' => false,
                'success' => false,
                'status_code' => 0,
                'data' => [],
                'raw_body' => '',
                'error' => $response->get_error_message()
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);
        $http_success = ($status_code >= 200 && $status_code < 300);

        $data = json_decode($raw_body, true);
        if (!is_array($data)) {
            $data = [];
        }

        return [
            'http_success' => $http_success,
            'success' => $http_success,
            'status_code' => $status_code,
            'data' => $data,
            'raw_body' => $raw_body,
            'error' => '',
        ];
    }
}
