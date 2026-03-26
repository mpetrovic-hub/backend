<?php

if (!defined('ABSPATH')) {
    exit;
}

// Initialize REST routes for callbacks from Aggregators like DIMOCO and Lily

class Kiwi_Rest_Routes
{
    private $config;
    private $dimoco_callback_verifier;
    private $dimoco_response_parser;
    private $dimoco_callback_refund_repository;
    private $dimoco_callback_blacklist_repository;

    public function __construct(
    Kiwi_Config $config,
    Kiwi_Dimoco_Callback_Verifier $dimoco_callback_verifier,
    Kiwi_Dimoco_Response_Parser $dimoco_response_parser,
    Kiwi_Dimoco_Callback_Refund_Repository $dimoco_callback_refund_repository,
    Kiwi_Dimoco_Callback_Blacklist_Repository $dimoco_callback_blacklist_repository
) {
    $this->config = $config;
    $this->dimoco_callback_verifier = $dimoco_callback_verifier;
    $this->dimoco_response_parser = $dimoco_response_parser;
    $this->dimoco_callback_refund_repository = $dimoco_callback_refund_repository;
    $this->dimoco_callback_blacklist_repository = $dimoco_callback_blacklist_repository;
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

    /*public function handle_dimoco_callback(WP_REST_Request $request): WP_REST_Response
    {
        error_log('KIWI DIMOCO CALLBACK HIT');

        $xml = (string) $request->get_param('data');
        $received_digest = (string) $request->get_param('digest');

        if ($xml === '' || $received_digest === '') {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Missing callback parameters.',
            ], 400);
        }

        $service = $this->resolve_dimoco_service_by_order_from_xml($xml);

        if ($service === null) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Unknown DIMOCO order.',
            ], 400);
        }

        $secret = $service['secret'] ?? '';

        if (!$this->dimoco_callback_verifier->verify($xml, $received_digest, $secret)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid callback digest.',
            ], 403);
        }

        $parsed_result = $this->dimoco_response_parser->parse([
            'success'     => true,
            'status_code' => 200,
            'request'     => [],
            'xml'         => $xml,
        ]);

        $parsed_result['service_key'] = $service['service_key'] ?? '';
        $parsed_result['service_label'] = $service['label'] ?? '';

        $this->dimoco_callback_refund_repository->insert($parsed_result);

        $this->maybe_log_dimoco_callback($parsed_result);      

        return new WP_REST_Response('OK', 200);
    } */

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

    if ($xml === '' || $received_digest === '') {
        error_log('KIWI DIMOCO CALLBACK: missing data or digest');

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing callback parameters.',
        ], 400);
    }
    error_log('KIWI DIMOCO CALLBACK XML RAW: ' . $xml);

    error_log('KIWI DIMOCO CALLBACK STEP 3: before resolve_dimoco_service_by_order_from_xml');

    $service = $this->resolve_dimoco_service_by_order_from_xml($xml);

    error_log('KIWI DIMOCO CALLBACK STEP 4: service resolved');

    if ($service === null) {
        error_log('KIWI DIMOCO CALLBACK: unknown order');

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Unknown DIMOCO order.',
        ], 400);
    }

    $secret = $service['secret'] ?? '';
    error_log('KIWI DIMOCO CALLBACK STEP 5: secret loaded');

    $is_valid = $this->dimoco_callback_verifier->verify($xml, $received_digest, $secret);
    error_log('KIWI DIMOCO CALLBACK STEP 6: digest checked => ' . ($is_valid ? 'valid' : 'invalid'));

    if (!$is_valid) {
        error_log('KIWI DIMOCO CALLBACK: invalid digest');

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid callback digest.',
        ], 403);
    }

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

    /* error_log('KIWI DIMOCO CALLBACK STEP 9: before insert');

    $inserted = $this->dimoco_callback_refund_repository->insert($parsed_result); */

    $action = (string) ($parsed_result['action'] ?? '');

    error_log('KIWI DIMOCO CALLBACK STEP 9: before insert, action=' . $action);

    $inserted = false;

    if ($action === 'refund') {
        error_log('KIWI DIMOCO CALLBACK: routing to refund repository');
        $inserted = $this->dimoco_callback_refund_repository->insert($parsed_result);
    } elseif ($action === 'add-blocklist') {
        error_log('KIWI DIMOCO CALLBACK: routing to blacklist repository');
        $inserted = $this->dimoco_callback_blacklist_repository->insert($parsed_result);
    } else {
        error_log('KIWI DIMOCO CALLBACK: unsupported action "' . $action . '"');
    }

    error_log('KIWI DIMOCO CALLBACK STEP 10: insert => ' . ($inserted ? 'OK' : 'FAILED'));

    $this->maybe_log_dimoco_callback($parsed_result);

    error_log('KIWI DIMOCO CALLBACK STEP 11: returning 200 OK');

    $response = new WP_REST_Response('OK', 200);
    $response->header('Content-Type', 'text/plain; charset=utf-8');

    return $response;
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