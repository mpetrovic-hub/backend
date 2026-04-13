<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Kpi_Rest_Routes
{
    private $config;
    private $event_repository;
    private $kpi_service;

    public function __construct(
        Kiwi_Config $config,
        Kiwi_Landing_Kpi_Event_Repository $event_repository,
        Kiwi_Landing_Kpi_Service $kpi_service
    ) {
        $this->config = $config;
        $this->event_repository = $event_repository;
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
        $session_token = trim((string) ($params['session_token'] ?? ''));

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

        if (!$this->is_valid_session_token($session_token)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'invalid_session_token',
            ], 400);
        }

        $landing = $this->config->get_landing_page($landing_key) ?? [];
        $event_result = $this->event_repository->insert_step_event([
            'landing_key' => $landing_key,
            'service_key' => (string) ($landing['service_key'] ?? ''),
            'session_token' => $session_token,
            'event_step' => $step,
            'event_value' => $this->sanitize_event_value((string) ($params['event_value'] ?? '')),
            'request_host' => $this->server_value('HTTP_HOST'),
            'request_path' => $this->server_value('REQUEST_URI'),
            'remote_ip' => $this->server_value('REMOTE_ADDR'),
            'user_agent' => $this->sanitize_event_value($this->server_value('HTTP_USER_AGENT')),
            'raw_context' => [
                'source' => 'landing_page',
                'payload' => [
                    'landing_key' => $landing_key,
                    'step' => $step,
                ],
            ],
        ]);

        return new WP_REST_Response([
            'success' => true,
            'inserted' => !empty($event_result['inserted']),
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
        if ($step === '') {
            return false;
        }

        return preg_match('/^cta[1-9][0-9]*$/', $step) === 1;
    }

    private function is_valid_session_token(string $session_token): bool
    {
        if ($session_token === '') {
            return false;
        }

        return preg_match('/^[A-Za-z0-9_-]{8,120}$/', $session_token) === 1;
    }

    private function sanitize_event_value(string $value): string
    {
        return substr(trim($value), 0, 191);
    }

    private function server_value(string $key): string
    {
        if (!isset($_SERVER[$key])) {
            return '';
        }

        return trim((string) $_SERVER[$key]);
    }
}

