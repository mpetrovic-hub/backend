<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Kpi_Rest_Routes
{
    private $config;
    private $kpi_service;
    private $landing_engagement_repository;
    private $click_attribution_repository;

    public function __construct(
        Kiwi_Config $config,
        Kiwi_Landing_Kpi_Service $kpi_service,
        ?Kiwi_Premium_Sms_Landing_Engagement_Repository $landing_engagement_repository = null,
        ?Kiwi_Click_Attribution_Repository $click_attribution_repository = null
    ) {
        $this->config = $config;
        $this->kpi_service = $kpi_service;
        $this->landing_engagement_repository = $landing_engagement_repository;
        $this->click_attribution_repository = $click_attribution_repository;
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
        $event_type = strtolower(trim((string) ($params['event_type'] ?? '')));

        if (!$this->is_valid_landing_key($landing_key)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'invalid_landing_key',
            ], 400);
        }

        $has_valid_step = $this->is_valid_step($step);
        $has_valid_event_type = $this->is_valid_event_type($event_type);

        if (!$has_valid_step && !$has_valid_event_type) {
            $error = $step !== '' && $event_type === ''
                ? 'invalid_step'
                : 'invalid_event';

            return new WP_REST_Response([
                'success' => false,
                'error' => $error,
            ], 400);
        }

        $landing = $this->config->get_landing_page($landing_key) ?? [];
        $incremented = false;
        $engagement_recorded = false;

        if ($has_valid_step) {
            $incremented = $this->kpi_service->increment_step($landing_key, $step, [
                'service_key' => (string) ($landing['service_key'] ?? ''),
                'provider_key' => (string) ($landing['provider'] ?? ''),
                'flow_key' => (string) ($landing['flow'] ?? ''),
            ]);
        }

        if ($has_valid_event_type) {
            $engagement_recorded = $this->capture_landing_engagement_event(
                $landing_key,
                $event_type,
                $params,
                is_array($landing) ? $landing : []
            );
        }

        return new WP_REST_Response([
            'success' => $incremented || $engagement_recorded,
            'incremented' => $incremented,
            'engagement_recorded' => $engagement_recorded,
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

    private function is_valid_event_type(string $event_type): bool
    {
        return in_array($event_type, ['page_loaded', 'cta_click'], true);
    }

    private function sanitize_event_value(string $value): string
    {
        return substr(trim($value), 0, 191);
    }

    private function capture_landing_engagement_event(
        string $landing_key,
        string $event_type,
        array $params,
        array $landing
    ): bool {
        if (!$this->landing_engagement_repository instanceof Kiwi_Premium_Sms_Landing_Engagement_Repository) {
            return false;
        }

        $session_token = trim((string) ($params['session_token'] ?? ''));

        if ($session_token === '') {
            return false;
        }

        $record = $this->landing_engagement_repository->upsert_event([
            'landing_key' => $landing_key,
            'session_token' => $session_token,
            'service_key' => (string) ($landing['service_key'] ?? ''),
            'provider_key' => (string) ($landing['provider'] ?? ''),
            'flow_key' => (string) ($landing['flow'] ?? ''),
            'pid' => $this->resolve_pid_for_engagement($params, $landing, $session_token),
        ], $event_type);

        return !empty($record);
    }

    private function resolve_pid_for_engagement(array $params, array $landing, string $session_token): string
    {
        $pid = $this->sanitize_pid((string) ($params['pid'] ?? ''));

        if ($pid !== '') {
            return $pid;
        }

        if (!$this->click_attribution_repository instanceof Kiwi_Click_Attribution_Repository) {
            return '';
        }

        $service_key = trim((string) ($landing['service_key'] ?? ''));

        if ($service_key === '' || $session_token === '') {
            return '';
        }

        $attribution = $this->click_attribution_repository->find_unique_pending_by_service_reference(
            $service_key,
            $session_token
        );

        if (!is_array($attribution)) {
            return '';
        }

        return $this->sanitize_pid((string) ($attribution['pid'] ?? ''));
    }

    private function sanitize_pid(string $pid): string
    {
        $pid = trim($pid);

        if ($pid === '') {
            return '';
        }

        $pid = preg_replace('/[^A-Za-z0-9._~:-]/', '', $pid);
        $pid = is_string($pid) ? $pid : '';

        return substr($pid, 0, 191);
    }

}
