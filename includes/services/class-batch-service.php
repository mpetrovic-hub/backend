<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Batch_Service
{
    private $hlr_service;
    private $config;
    private $normalizer;

    public function __construct(
        Kiwi_Hlr_Service $hlr_service,
        Kiwi_Config $config,
        Kiwi_Msisdn_Normalizer $normalizer
    ) {
        $this->hlr_service = $hlr_service;
        $this->config = $config;
        $this->normalizer = $normalizer;
    }

    public function process(string $input): array
    {
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

        $normalized_map = [];

        foreach ($raw_lines as $line) {
            $normalized = $this->normalizer->normalize($line);

            if ($normalized === '') {
                continue;
            }

            if (!isset($normalized_map[$normalized])) {
                $normalized_map[$normalized] = $line;
            }
        }

        $unique_msisdns = array_keys($normalized_map);

        $batch_limit = $this->config->get_hlr_batch_limit();
        $msisdns_to_process = array_slice($unique_msisdns, 0, $batch_limit);

        $results = [];
        $request_delay_ms = max(0, $this->config->get_hlr_request_delay_ms());
        $retry_delay_seconds = max(0, $this->config->get_hlr_retry_delay_seconds());

        foreach ($msisdns_to_process as $index => $msisdn) {
            $result = $this->hlr_service->lookup($msisdn);

            if (($result['hlr_status'] ?? '') === 'REQUEST THROTTLED') {
                if ($retry_delay_seconds > 0) {
                    sleep($retry_delay_seconds);
                }

                $result = $this->hlr_service->lookup($msisdn);

                $existing_messages = [];
                if (!empty($result['messages']) && is_array($result['messages'])) {
                    $existing_messages = $result['messages'];
                }

                $existing_messages[] = 'Retried after throttling.';

                $result['messages'] = $existing_messages;
            }

            $results[] = $result;

            $is_last = ($index === count($msisdns_to_process) - 1);

            if (!$is_last && $request_delay_ms > 0) {
                usleep($request_delay_ms * 1000);
            }
        }

        return [
            'total_input'  => $total_input,
            'unique_input' => count($unique_msisdns),
            'processed'    => count($results),
            'batch_limit'  => $batch_limit,
            'has_more'     => count($unique_msisdns) > $batch_limit,
            'results'      => $results,
        ];
    }
}