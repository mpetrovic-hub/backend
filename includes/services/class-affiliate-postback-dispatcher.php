<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Affiliate_Postback_Dispatcher
{
    private $config;

    public function __construct(Kiwi_Config $config)
    {
        $this->config = $config;
    }

    public function dispatch(array $attribution, array $conversion = []): array
    {
        $click_id = trim((string) ($attribution['click_id'] ?? ''));

        if ($click_id === '') {
            return [
                'success' => false,
                'response_code' => 0,
                'response_body' => '',
                'error' => 'Missing click_id for postback dispatch.',
                'url' => '',
            ];
        }

        $url = $this->build_postback_url($click_id, $attribution, $conversion);

        if ($url === '') {
            return [
                'success' => false,
                'response_code' => 0,
                'response_body' => '',
                'error' => 'Affiliate postback URL template is not configured.',
                'url' => '',
            ];
        }

        $transport = $this->send_request($url);
        $response_code = (int) ($transport['status_code'] ?? 0);
        $response_body = (string) ($transport['body'] ?? '');
        $response_body = substr($response_body, 0, max(100, $this->config->get_affiliate_postback_response_body_limit()));
        $error = (string) ($transport['error'] ?? '');
        $success = $response_code >= 200 && $response_code < 300 && $error === '';

        return [
            'success' => $success,
            'response_code' => $response_code,
            'response_body' => $response_body,
            'error' => $error,
            'url' => $url,
        ];
    }

    public function build_postback_url(string $click_id, array $attribution = [], array $conversion = []): string
    {
        $template = trim($this->config->get_affiliate_postback_url_template());

        if ($template === '') {
            return '';
        }

        $sale_reference = trim((string) ($conversion['sale_reference'] ?? ($attribution['sale_reference'] ?? '')));
        $service_key = trim((string) ($conversion['service_key'] ?? ($attribution['service_key'] ?? '')));
        $provider_key = trim((string) ($conversion['provider_key'] ?? ($attribution['provider_key'] ?? '')));
        $operator_name = trim((string) ($conversion['operator_name'] ?? ($attribution['operator_name'] ?? '')));
        $signature = $this->build_signature($click_id, $sale_reference, $service_key, $provider_key);
        $url = strtr($template, [
            '{clickid}' => rawurlencode($click_id),
            '{click_id}' => rawurlencode($click_id),
            '{{clickid}}' => rawurlencode($click_id),
            '{{click_id}}' => rawurlencode($click_id),
            '{sale_reference}' => rawurlencode($sale_reference),
            '{{sale_reference}}' => rawurlencode($sale_reference),
            '{service_key}' => rawurlencode($service_key),
            '{{service_key}}' => rawurlencode($service_key),
            '{provider_key}' => rawurlencode($provider_key),
            '{{provider_key}}' => rawurlencode($provider_key),
            '{operator_name}' => rawurlencode($operator_name),
            '{{operator_name}}' => rawurlencode($operator_name),
            '{sub7}' => rawurlencode($operator_name),
            '{{sub7}}' => rawurlencode($operator_name),
            '{secure}' => rawurlencode($signature),
            '{hash}' => rawurlencode($signature),
            '{{secure}}' => rawurlencode($signature),
            '{{hash}}' => rawurlencode($signature),
        ]);

        if ($signature !== '' && strpos($template, '{secure}') === false && strpos($template, '{hash}') === false) {
            $parameter = trim($this->config->get_affiliate_postback_signature_parameter());

            if ($parameter !== '') {
                $url .= (strpos($url, '?') === false ? '?' : '&')
                    . rawurlencode($parameter)
                    . '='
                    . rawurlencode($signature);
            }
        }

        if ($operator_name !== '' && !$this->url_has_query_parameter($url, 'sub7')) {
            $url .= (strpos($url, '?') === false ? '?' : '&')
                . 'sub7='
                . rawurlencode($operator_name);
        }

        return $url;
    }

    private function url_has_query_parameter(string $url, string $parameter): bool
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (!is_string($query) || $query === '') {
            return false;
        }

        parse_str($query, $params);

        return is_array($params) && array_key_exists($parameter, $params);
    }

    protected function send_request(string $url): array
    {
        $response = wp_remote_get($url, [
            'timeout' => $this->config->get_affiliate_postback_timeout_seconds(),
            'redirection' => 3,
        ]);

        if (is_wp_error($response)) {
            return [
                'status_code' => 0,
                'body' => '',
                'error' => (string) $response->get_error_message(),
            ];
        }

        return [
            'status_code' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
            'error' => '',
        ];
    }

    protected function build_signature(
        string $click_id,
        string $sale_reference,
        string $service_key,
        string $provider_key
    ): string {
        $secret = trim($this->config->get_affiliate_postback_secret());

        if ($secret === '') {
            return '';
        }

        $algorithm = strtolower(trim($this->config->get_affiliate_postback_signature_algorithm()));
        if ($algorithm === '' || !in_array($algorithm, hash_algos(), true)) {
            $algorithm = 'sha256';
        }

        $base_template = $this->config->get_affiliate_postback_signature_base();
        $base = strtr($base_template, [
            '{clickid}' => $click_id,
            '{click_id}' => $click_id,
            '{{clickid}}' => $click_id,
            '{{click_id}}' => $click_id,
            '{sale_reference}' => $sale_reference,
            '{{sale_reference}}' => $sale_reference,
            '{service_key}' => $service_key,
            '{{service_key}}' => $service_key,
            '{provider_key}' => $provider_key,
            '{{provider_key}}' => $provider_key,
            '{secret}' => $secret,
            '{{secret}}' => $secret,
        ]);

        return hash($algorithm, $base);
    }
}
