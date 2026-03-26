<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Routed_Operator_Lookup_Provider
{
    private $config;
    private $lily_provider;
    private $dimoco_provider;

    public function __construct(
        Kiwi_Config $config,
        Kiwi_Lily_Operator_Lookup_Provider $lily_provider,
        Kiwi_Dimoco_Operator_Lookup_Provider $dimoco_provider
    ) {
        $this->config = $config;
        $this->lily_provider = $lily_provider;
        $this->dimoco_provider = $dimoco_provider;
    }

    public function lookup(string $msisdn): array
    {
        $msisdn = trim($msisdn);

        if ($msisdn === '') {
            return $this->build_error_result('', 'Empty MSISDN provided.');
        }

        $routes = $this->config->get_operator_lookup_routes();

        foreach ($routes as $prefix => $route) {
            $prefix = trim((string) $prefix);

            if ($prefix === '') {
                continue;
            }

            if (strpos($msisdn, $prefix) !== 0) {
                continue;
            }

            $provider = strtolower(trim((string) ($route['provider'] ?? '')));

            if ($provider === 'lily') {
                return $this->lily_provider->lookup($msisdn);
            }

            if ($provider === 'dimoco') {
                $service_key = trim((string) ($route['service_key'] ?? ''));

                if ($service_key === '') {
                    return $this->build_error_result(
                        $msisdn,
                        'Missing DIMOCO service_key for prefix ' . $prefix . '.'
                    );
                }

                return $this->dimoco_provider->lookup($msisdn, $service_key);
            }

            return $this->build_error_result(
                $msisdn,
                'Unsupported operator lookup provider "' . $provider . '" for prefix ' . $prefix . '.'
            );
        }

        return $this->build_error_result(
            $msisdn,
            'Unsupported MSISDN prefix. No operator lookup route matched.'
        );
    }

    private function build_error_result(string $msisdn, string $message): array
    {
        return [
            'success'     => false,
            'provider'    => '',
            'feature'     => 'operator_lookup',
            'status_code' => 0,
            'api_status'  => '',
            'hlr_status'  => '',
            'operator'    => '',
            'msisdn'      => $msisdn,
            'messages'    => [$message],
            'raw'         => [],
        ];
    }
}