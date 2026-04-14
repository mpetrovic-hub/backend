<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Kpi_Rest_Routes
{
    private $config;
    private $kpi_service;

    public function __construct(
        Kiwi_Config $config,
        Kiwi_Landing_Kpi_Service $kpi_service
    ) {
        $this->config = $config;
        $this->kpi_service = $kpi_service;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route('kiwi-backend/v1', '/landing-kpi/event', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_event'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('kiwi-backend/v1', '/landing-kpi/report', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_report'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_event(WP_REST_Request $request): WP_REST_Response
    {
        $params = $this->normalize_params($request);
        $landing_key = trim((string) ($params['landing_key'] ?? ''));
        $step = strtolower(trim((string) ($params['step'] ?? '')));

        if (!$this->is_valid_landing_key($landing_key)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'invalid_landing_key',
            ], 400);
        }

        if (!$this->is_valid_step($step)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'invalid_step',
            ], 400);
        }

        $landing = $this->config->get_landing_page($landing_key) ?? [];
        $incremented = $this->kpi_service->increment_step($landing_key, $step, [
            'service_key' => (string) ($landing['service_key'] ?? ''),
            'provider_key' => (string) ($landing['provider'] ?? ''),
            'flow_key' => (string) ($landing['flow'] ?? ''),
        ]);

        return new WP_REST_Response([
            'success' => $incremented,
            'incremented' => $incremented,
            'event_value' => $this->sanitize_event_value((string) ($params['event_value'] ?? '')),
        ], 200);
    }

    public function handle_report(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_params();
        $params = is_array($params) ? $params : [];
        $days = isset($params['days']) ? (int) $params['days'] : 30;
        $landing_key = trim((string) ($params['landing_key'] ?? ''));

        if ($landing_key !== '' && !$this->is_valid_landing_key($landing_key)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'invalid_landing_key',
            ], 400);
        }

        return new WP_REST_Response(
            $this->kpi_service->build_report($days, $landing_key),
            200
        );
    }

    private function normalize_params(WP_REST_Request $request): array
    {
        $json_params = method_exists($request, 'get_json_params')
            ? $request->get_json_params()
            : null;

        if (is_array($json_params) && !empty($json_params)) {
            return $json_params;
        }

        $params = $request->get_params();

        return is_array($params) ? $params : [];
    }

    private function is_valid_landing_key(string $landing_key): bool
    {
        if ($landing_key === '') {
            return false;
        }

        return is_array($this->config->get_landing_page($landing_key));
    }

    private function is_valid_step(string $step): bool
    {
        return in_array($step, ['cta1', 'cta2', 'cta3'], true);
    }

    private function sanitize_event_value(string $value): string
    {
        return substr(trim($value), 0, 191);
    }

}
