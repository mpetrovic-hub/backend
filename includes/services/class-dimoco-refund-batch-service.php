<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Dimoco_Refund_Batch_Service
{
    private $client;
    private $parser;
    private $config;

    public function __construct(
        Kiwi_Dimoco_Client $client,
        Kiwi_Dimoco_Response_Parser $parser,
        Kiwi_Config $config
    ) {
        $this->client = $client;
        $this->parser = $parser;
        $this->config = $config;
    }

    /**
     * Process a batch of DIMOCO refund transaction IDs
     */
    public function process(string $service_key, string $msisdn, string $input): array
    {
        $service = $this->config->get_dimoco_service($service_key);

        if (!is_array($service)) {
            return [
                'success'      => false,
                'service_key'  => $service_key,
                'service_label'=> '',
                'msisdn'       => trim($msisdn),
                'total_input'  => 0,
                'unique_input' => 0,
                'processed'    => 0,
                'results'      => [],
                'messages'     => ['Unknown DIMOCO service key.'],
            ];
        }

        $lines = preg_split('/\r\n|\r|\n/', $input);
        $lines = is_array($lines) ? $lines : [];

        $raw_lines = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $raw_lines[] = $line;
        }

        $total_input = count($raw_lines);
        $unique_transactions = array_values(array_unique($raw_lines));

        $results = [];

        foreach ($unique_transactions as $transaction_id) {
            $raw_result = $this->client->refund($service_key, $transaction_id);
            $parsed_result = $this->parser->parse($raw_result);

            $parsed_result['input_transaction_id'] = $transaction_id;
            $parsed_result['service_key'] = $service_key;
            $parsed_result['service_label'] = $service['label'] ?? $service_key;
            $parsed_result['msisdn'] = trim($msisdn);

            $results[] = $parsed_result;
        }

        return [
            'success'       => true,
            'service_key'   => $service_key,
            'service_label' => $service['label'] ?? $service_key,
            'msisdn'        => trim($msisdn),
            'total_input'   => $total_input,
            'unique_input'  => count($unique_transactions),
            'processed'     => count($results),
            'results'       => $results,
            'messages'      => [],
        ];
    }
}