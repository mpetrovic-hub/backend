<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Hlr_Service
{
    private $provider;
    private $normalizer;

    public function __construct(
        Kiwi_Lily_Hlr_Provider $provider,
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
                'provider'    => 'lily',
                'feature'     => 'hlr',
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