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

    public function __construct(
    Kiwi_Config $config,
    Kiwi_Dimoco_Callback_Verifier $dimoco_callback_verifier,
    Kiwi_Dimoco_Response_Parser $dimoco_response_parser,
    Kiwi_Dimoco_Callback_Refund_Repository $dimoco_callback_refund_repository
) {
    $this->config = $config;
    $this->dimoco_callback_verifier = $dimoco_callback_verifier;
    $this->dimoco_response_parser = $dimoco_response_parser;
    $this->dimoco_callback_refund_repository = $dimoco_callback_refund_repository;
}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /* public function register_routes(): void
    {
        register_rest_route('kiwi-backend/v1', '/dimoco-callback', [
            /*'methods'             => 'POST', */ /*
            'methods' => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_dimoco_callback'],
            'permission_callback' => '__return_true',
        ]);
    } */

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

    public function handle_dimoco_callback(WP_REST_Request $request): WP_REST_Response
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