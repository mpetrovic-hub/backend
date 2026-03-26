<?php

/*
if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Dimoco_Operator_Lookup_Provider
{
    private $client;
    private $parser;
    private $service_key;

    public function __construct(
        Kiwi_Dimoco_Client $client,
        Kiwi_Dimoco_Response_Parser $parser,
        string $service_key
    ) {
        $this->client = $client;
        $this->parser = $parser;
        $this->service_key = $service_key;
    }

    public function lookup(string $msisdn): array
    {
        $raw_result = $this->client->operator_lookup($this->service_key, $msisdn);
        $parsed = $this->parser->parse($raw_result);

        $parsed['provider'] = 'dimoco';
        $parsed['feature'] = 'operator_lookup';

        if (!isset($parsed['msisdn']) || $parsed['msisdn'] === '') {
            $parsed['msisdn'] = $msisdn;
        }

        return $parsed;
    }
}
*/


if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Dimoco_Operator_Lookup_Provider
{
    private $client;
    private $parser;

    public function __construct(
        Kiwi_Dimoco_Client $client,
        Kiwi_Dimoco_Response_Parser $parser
    ) {
        $this->client = $client;
        $this->parser = $parser;
    }

    public function lookup(string $msisdn, string $service_key): array
    {
        $raw_result = $this->client->operator_lookup($service_key, $msisdn);
        $parsed = $this->parser->parse($raw_result);

        $parsed['provider'] = 'dimoco';
        $parsed['feature'] = 'operator_lookup';

        if (!isset($parsed['msisdn']) || $parsed['msisdn'] === '') {
            $parsed['msisdn'] = $msisdn;
        }

        if (!isset($parsed['operator'])) {
            $parsed['operator'] = '';
        }

        return $parsed;
    }
}