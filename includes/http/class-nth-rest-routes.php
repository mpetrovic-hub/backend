<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Nth_Rest_Routes
{
    private $service;

    public function __construct(Kiwi_Nth_Fr_One_Off_Service $service)
    {
        $this->service = $service;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route('kiwi-backend/v1', '/nth/services/(?P<service_key>[a-zA-Z0-9_-]+)/mo', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_mo_callback'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('kiwi-backend/v1', '/nth/services/(?P<service_key>[a-zA-Z0-9_-]+)/notification', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_notification_callback'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_mo_callback(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->service->handle_inbound_mo(
            (string) $request->get_param('service_key'),
            $this->normalize_request_params($request)
        );

        return $this->acknowledge($result);
    }

    public function handle_notification_callback(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->service->handle_notification(
            (string) $request->get_param('service_key'),
            $this->normalize_request_params($request)
        );

        return $this->acknowledge($result);
    }

    private function normalize_request_params(WP_REST_Request $request): array
    {
        $params = $request->get_params();
        unset($params['service_key']);

        return is_array($params) ? $params : [];
    }

    private function acknowledge(array $result): WP_REST_Response
    {
        $response = new WP_REST_Response('OK', 200);
        $response->header('Content-Type', 'text/plain; charset=utf-8');
        $response->header('X-Kiwi-Status', !empty($result['success']) ? 'accepted' : 'rejected');

        return $response;
    }
}
