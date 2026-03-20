<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Lily_Hlr_Provider
{
    private $client;
    private $parser;

    public function __construct(
        Kiwi_Lily_Client $client,
        Kiwi_Lily_Response_Parser $parser
    ) {
        $this->client = $client;
        $this->parser = $parser;
    }

    public function lookup(string $msisdn): array
    {
        // 1. Request an Lily senden
        $raw_response = $this->client->hlr_lookup($msisdn);

        // 2. Antwort parsen
        $parsed_response = $this->parser->parse_hlr_response($raw_response);

        return $parsed_response;
    }
}