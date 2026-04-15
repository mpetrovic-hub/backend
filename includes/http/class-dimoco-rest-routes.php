<?php

if (!defined('ABSPATH')) {
    exit;
}

// Initialize REST routes for callbacks from Aggregators like DIMOCO and Lily

class Kiwi_Dimoco_Rest_Routes
{
    private $config;
    private $dimoco_callback_verifier;
    private $dimoco_response_parser;
    private $dimoco_callback_refund_repository;
    private $dimoco_callback_blacklist_repository;
    private $dimoco_callback_operator_lookup_repository;

    public function __construct(
    Kiwi_Config $config,
    Kiwi_Dimoco_Callback_Verifier $dimoco_callback_verifier,
    Kiwi_Dimoco_Response_Parser $dimoco_response_parser,
    Kiwi_Dimoco_Callback_Refund_Repository $dimoco_callback_refund_repository,
    Kiwi_Dimoco_Callback_Blacklist_Repository $dimoco_callback_blacklist_repository,
    Kiwi_Dimoco_Callback_Operator_Lookup_Repository $dimoco_callback_operator_lookup_repository
    ) {
    $this->config = $config;
    $this->dimoco_callback_verifier = $dimoco_callback_verifier;
    $this->dimoco_response_parser = $dimoco_response_parser;
    $this->dimoco_callback_refund_repository = $dimoco_callback_refund_repository;
    $this->dimoco_callback_blacklist_repository = $dimoco_callback_blacklist_repository;
    $this->dimoco_callback_operator_lookup_repository = $dimoco_callback_operator_lookup_repository;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }    

    public function register_routes(): void
    {
        $this->register_dimoco_routes();
    }

    private function register_dimoco_routes(): void
    {
        register_rest_route('kiwi-backend/v1', '/dimoco-callback', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_dimoco_callback'],
            'permission_callback' => '__return_true',
        ]);
    }

    /* handle dimoco callback with error logging */
    public function handle_dimoco_callback(WP_REST_Request $request): WP_REST_Response
    {
    error_log('KIWI DIMOCO CALLBACK HIT');

    error_log('KIWI DIMOCO CALLBACK STEP 1: before reading params');

    $xml = (string) $request->get_param('data');
    $received_digest = (string) $request->get_param('digest');

    error_log('KIWI DIMOCO CALLBACK STEP 2: params read');
    error_log('KIWI DIMOCO CALLBACK XML LENGTH: ' . strlen($xml));
    error_log('KIWI DIMOCO CALLBACK DIGEST LENGTH: ' . strlen($received_digest));
    error_log('KIWI DIMOCO CALLBACK DIGEST RAW: ' . $received_digest);

    if ($xml === '' || $received_digest === '') {
        error_log('KIWI DIMOCO CALLBACK: missing data or digest');

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing callback parameters.',
        ], 400);
    }
    error_log('KIWI DIMOCO CALLBACK XML RAW: ' . $xml);

    error_log('KIWI DIMOCO CALLBACK STEP 3: before resolve_dimoco_service_by_order_from_xml');

    $service_resolution = $this->resolve_and_verify_dimoco_service_for_callback($xml, $received_digest);
    $service = $service_resolution['service'] ?? null;

    error_log('KIWI DIMOCO CALLBACK STEP 4: service resolution strategy=' . ($service_resolution['strategy'] ?? 'unknown'));

    if ($service === null) {
        error_log('KIWI DIMOCO CALLBACK: service resolution failed: ' . ($service_resolution['message'] ?? 'Unknown DIMOCO order.'));

        return new WP_REST_Response([
            'success' => false,
            'message' => (string) ($service_resolution['message'] ?? 'Unknown DIMOCO order.'),
        ], (int) ($service_resolution['status'] ?? 400));
    }

    error_log('KIWI DIMOCO CALLBACK STEP 5: service_key=' . (string) ($service['service_key'] ?? ''));
    error_log('KIWI DIMOCO CALLBACK STEP 6: callback digest already verified during service resolution');
    error_log('KIWI DIMOCO CALLBACK STEP 7: before parser');

    $parsed_result = $this->dimoco_response_parser->parse([
        'success'     => true,
        'status_code' => 200,
        'request'     => [],
        'xml'         => $xml,
    ]);

    error_log('KIWI DIMOCO CALLBACK STEP 8: parser done');
    error_log('KIWI DIMOCO CALLBACK PARSED: ' . wp_json_encode($parsed_result));

    $parsed_result['service_key'] = $service['service_key'] ?? '';
    $parsed_result['service_label'] = $service['label'] ?? '';



    $action = (string) ($parsed_result['action'] ?? '');

    error_log('KIWI DIMOCO CALLBACK STEP 9: before insert, action=' . $action);

    $inserted = false;

    if ($action === 'refund') {
        error_log('KIWI DIMOCO CALLBACK: routing to refund repository');
        $inserted = $this->dimoco_callback_refund_repository->insert($parsed_result);
    } elseif ($action === 'add-blocklist') {
        error_log('KIWI DIMOCO CALLBACK: routing to blacklist repository');
        $inserted = $this->dimoco_callback_blacklist_repository->insert($parsed_result);
    } elseif ($action === 'operator-lookup') {
        error_log('KIWI DIMOCO CALLBACK: routing to operator lookup repository');
        $inserted = $this->dimoco_callback_operator_lookup_repository->insert($parsed_result);
    } else {
        error_log('KIWI DIMOCO CALLBACK: unsupported action "' . $action . '"');
    }

    error_log('KIWI DIMOCO CALLBACK STEP 10: insert => ' . ($inserted ? 'OK' : 'FAILED'));

    /* if ($action === 'operator-lookup') {
    error_log('KIWI DIMOCO CALLBACK STEP 10: insert skipped');
    } else {
        error_log('KIWI DIMOCO CALLBACK STEP 10: insert => ' . ($inserted ? 'OK' : 'FAILED'));
    } */

    $this->maybe_log_dimoco_callback($parsed_result);

    error_log('KIWI DIMOCO CALLBACK STEP 11: returning 200 OK');

    $response = new WP_REST_Response('OK', 200);
    $response->header('Content-Type', 'text/plain; charset=utf-8');

    return $response;
}

    private function resolve_and_verify_dimoco_service_for_callback(string $xml, string $received_digest): array
    {
        $service = $this->resolve_dimoco_service_by_order_from_xml($xml);

        if (is_array($service)) {
            $secret = (string) ($service['secret'] ?? '');

            if ($secret === '') {
                return [
                    'service' => null,
                    'status' => 400,
                    'message' => 'Incomplete DIMOCO service configuration.',
                    'strategy' => 'order_missing_secret',
                ];
            }

            $is_valid = $this->dimoco_callback_verifier->verify($xml, $received_digest, $secret);

            if (!$is_valid) {
                return [
                    'service' => null,
                    'status' => 403,
                    'message' => 'Invalid callback digest.',
                    'strategy' => 'order_digest_invalid',
                ];
            }

            return [
                'service' => $service,
                'status' => 200,
                'message' => '',
                'strategy' => 'order',
            ];
        }

        $matched_services = $this->resolve_dimoco_services_by_digest($xml, $received_digest);
        $matched_count = count($matched_services);

        if ($matched_count === 1) {
            return [
                'service' => $matched_services[0],
                'status' => 200,
                'message' => '',
                'strategy' => 'digest_fallback_unique_match',
            ];
        }

        if ($matched_count > 1) {
            return [
                'service' => null,
                'status' => 400,
                'message' => 'Ambiguous DIMOCO callback service.',
                'strategy' => 'digest_fallback_ambiguous_match',
            ];
        }

        return [
            'service' => null,
            'status' => 403,
            'message' => 'Invalid callback digest.',
            'strategy' => 'digest_fallback_no_match',
        ];
    }

    private function resolve_dimoco_services_by_digest(string $xml, string $received_digest): array
    {
        $matched_services = [];

        foreach ($this->config->get_dimoco_services() as $service_key => $service) {
            $secret = trim((string) ($service['secret'] ?? ''));

            if ($secret === '') {
                continue;
            }

            if ($this->dimoco_callback_verifier->verify($xml, $received_digest, $secret)) {
                $service['service_key'] = (string) $service_key;
                $matched_services[] = $service;
            }
        }

        return $matched_services;
    }

    private function resolve_dimoco_service_by_order_from_xml(string $xml): ?array
    {
        libxml_use_internal_errors(true);
        $xml_object = simplexml_load_string($xml);

        if ($xml_object === false) {
            libxml_clear_errors();
            return null;
        }

        $order_id = trim((string) ($xml_object->payment_parameters->order ?? ''));

        if ($order_id === '') {
            return null;
        }

        foreach ($this->config->get_dimoco_services() as $service_key => $service) {
            if (($service['order_id'] ?? '') === $order_id) {
                $service['service_key'] = $service_key;
                return $service;
            }
        }

        return null;
    }

    private function maybe_log_dimoco_callback(array $parsed_result): void
    {
        if (!$this->config->is_dimoco_debug()) {
            return;
        }

        error_log('KIWI DIMOCO async CALLBACK: ' . wp_json_encode($parsed_result));
    }
}
