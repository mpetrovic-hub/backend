<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Dimoco_Blacklist_Batch_Service
{
    private $operator_lookup_service;
    private $operator_lookup_repository;
    private $client;
    private $parser;
    private $config;
    private $normalizer;

    public function __construct(
        Kiwi_Operator_Lookup_Service $operator_lookup_service,
        Kiwi_Dimoco_Callback_Operator_Lookup_Repository $operator_lookup_repository,
        Kiwi_Dimoco_Client $client,
        Kiwi_Dimoco_Response_Parser $parser,
        Kiwi_Config $config,
        Kiwi_Msisdn_Normalizer $normalizer
    ) {
        $this->operator_lookup_service = $operator_lookup_service;
        $this->operator_lookup_repository  = $operator_lookup_repository;
        $this->client                  = $client;
        $this->parser                  = $parser;
        $this->config                  = $config;
        $this->normalizer              = $normalizer;
    }

    /**
     * Process a batch of MSISDNs for DIMOCO add-blocklist
     */
    public function process(string $service_key, string $blocklist_scope, string $input): array
    {
        $service = $this->config->get_dimoco_service($service_key);

        if (!is_array($service)) {
            return [
                'success'         => false,
                'service_key'     => $service_key,
                'service_label'   => '',
                'blocklist_scope' => trim($blocklist_scope),
                'total_input'     => 0,
                'unique_input'    => 0,
                'processed'       => 0,
                'results'         => [],
                'messages'        => ['Unknown DIMOCO service key.'],
            ];
        }

        $blocklist_scope = trim($blocklist_scope);
        if ($blocklist_scope !== 'merchant' && $blocklist_scope !== 'order') {
            return [
                'success'         => false,
                'service_key'     => $service_key,
                'service_label'   => $service['label'] ?? $service_key,
                'blocklist_scope' => $blocklist_scope,
                'total_input'     => 0,
                'unique_input'    => 0,
                'processed'       => 0,
                'results'         => [],
                'messages'        => ['Invalid blocklist scope.'],
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
        $results = [];

        $lookup_timeout_seconds = 30;
        $lookup_poll_interval_microseconds = 1000000; // 1 second

        foreach ($unique_msisdns as $msisdn) {
            $lookup_result = $this->operator_lookup_service->lookup($msisdn);

            $lookup_request_id = (string) ($lookup_result['request_id'] ?? '');
            $lookup_messages   = [];

            error_log('KIWI BLACKLIST BATCH: starting operator lookup for ' . $msisdn);
            error_log('KIWI BLACKLIST BATCH: lookup result = ' . wp_json_encode($lookup_result));
            error_log('KIWI BLACKLIST BATCH: lookup request_id = ' . $lookup_request_id);

            if (!empty($lookup_result['messages']) && is_array($lookup_result['messages'])) {
                $lookup_messages = $lookup_result['messages'];
            }

            // if (!(bool) ($lookup_result['success'] ?? false) || $lookup_request_id === '') {
            //    $detail = 'Operator lookup could not be started.';

            if ($lookup_request_id === '') {
                // ohne request_id können wir nicht auf Callback warten
                $detail = 'Operator lookup could not be started.';


                if (!empty($lookup_messages)) {
                    $detail .= ' ' . implode(' | ', array_filter($lookup_messages));
                }

                $results[] = [
                    'success'            => false,
                    'provider'           => 'dimoco',
                    'feature'            => 'add-blocklist',
                    'http_success'       => (bool) (($lookup_result['http_success'] ?? false) || ($lookup_result['success'] ?? false)),
                    'status_code'        => (int) ($lookup_result['status_code'] ?? 0),
                    'action'             => 'add-blocklist',
                    'action_status'      => null,
                    'action_status_text' => 'operator_lookup_failed',
                    'action_code'        => '',
                    'detail'             => trim($detail),
                    'detail_psp'         => '',
                    'request_id'         => $lookup_request_id,
                    'reference'          => (string) ($lookup_result['reference'] ?? ''),
                    'transaction_id'     => '',
                    'order_id'           => '',
                    'msisdn'             => $msisdn,
                    'operator'           => '',
                    'service_key'        => $service_key,
                    'service_label'      => $service['label'] ?? $service_key,
                    'blocklist_scope'    => $blocklist_scope,
                    'messages'           => $lookup_messages,
                    'raw'                => [
                        'lookup_result' => $lookup_result,
                    ],
                ];

                continue;
            }

            $operator = '';
            $lookup_callback_row = null;            

            $timeout_seconds = $lookup_timeout_seconds;
            $poll_interval_microseconds = $lookup_poll_interval_microseconds;
            $started_at = time();

            do {
                $lookup_callback_row = $this->operator_lookup_repository->get_success_by_request_id($lookup_request_id);

                if (is_array($lookup_callback_row)) {
                    $operator = trim((string) ($lookup_callback_row['operator'] ?? ''));

                    if ($operator !== '') {
                        break;
                    }
                }

                error_log('KIWI BLACKLIST BATCH: polling callback for request_id ' . $lookup_request_id);

                usleep($poll_interval_microseconds);
            } while ((time() - $started_at) < $timeout_seconds);

            if ($operator === '') {
                $results[] = [
                    'success'            => false,
                    'provider'           => 'dimoco',
                    'feature'            => 'add-blocklist',
                    'http_success'       => (bool) (($lookup_result['http_success'] ?? false) || ($lookup_result['success'] ?? false)),
                    'status_code'        => (int) ($lookup_result['status_code'] ?? 0),
                    'action'             => 'add-blocklist',
                    'action_status'      => null,
                    'action_status_text' => 'operator_lookup_timeout',
                    'action_code'        => '',
                    'detail'             => 'Operator lookup callback not received within timeout.',
                    'detail_psp'         => '',
                    'request_id'         => $lookup_request_id,
                    'reference'          => (string) ($lookup_result['reference'] ?? ''),
                    'transaction_id'     => '',
                    'order_id'           => '',
                    'msisdn'             => $msisdn,
                    'operator'           => '',
                    'service_key'        => $service_key,
                    'service_label'      => $service['label'] ?? $service_key,
                    'blocklist_scope'    => $blocklist_scope,
                    'messages'           => $lookup_messages,
                    'raw'                => [
                        'lookup_result'         => $lookup_result,
                        'lookup_callback_row'   => $lookup_callback_row,
                        'lookup_timeout_seconds'=> $timeout_seconds,
                    ],
                ];
                error_log('KIWI BLACKLIST BATCH: timeout reached for request_id ' . $lookup_request_id);
                continue;
            }

            error_log('KIWI BLACKLIST BATCH: operator found via callback = ' . $operator);
            error_log('KIWI BLACKLIST BATCH: sending add-blocklist for ' . $msisdn);

            $raw_result = $this->client->add_blocklist(
                $service_key,
                $msisdn,
                $operator,
                $blocklist_scope
            );

            $parsed_result = $this->parser->parse($raw_result);

            $parsed_result['service_key']     = $service_key;
            $parsed_result['service_label']   = $service['label'] ?? $service_key;
            $parsed_result['blocklist_scope'] = $blocklist_scope;
            $parsed_result['msisdn']          = $parsed_result['msisdn'] !== ''
                ? $parsed_result['msisdn']
                : $msisdn;
            $parsed_result['operator']        = $parsed_result['operator'] !== ''
                ? $parsed_result['operator']
                : $operator;

            $results[] = $parsed_result;                        
        }

        return [
            'success'         => true,
            'service_key'     => $service_key,
            'service_label'   => $service['label'] ?? $service_key,
            'blocklist_scope' => $blocklist_scope,
            'total_input'     => $total_input,
            'unique_input'    => count($unique_msisdns),
            'processed'       => count($results),
            'results'         => $results,
            'messages'        => [],
        ];
    }
}