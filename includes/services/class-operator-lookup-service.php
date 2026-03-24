<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Operator_Lookup_Service
{
    private $provider;
    private $normalizer;

    public function __construct(
        $provider,
        Kiwi_Msisdn_Normalizer $normalizer
    ) {
        $this->provider = $provider;
        $this->normalizer = $normalizer;
    }

    public function lookup(string $msisdn): array
    {
        $normalized_msisdn = $this->normalizer->normalize($msisdn);

        if ($normalized_msisdn === '') {
            return [
                'success'     => false,
                'provider'    => '',
                'feature'     => 'operator_lookup',
                'status_code' => 0,
                'api_status'  => '',
                'hlr_status'  => '',
                'operator'    => '',
                'msisdn'      => '',
                'messages'    => ['Empty MSISDN provided.'],
                'raw'         => [],
            ];
        }

        return $this->provider->lookup($normalized_msisdn);
    }
}