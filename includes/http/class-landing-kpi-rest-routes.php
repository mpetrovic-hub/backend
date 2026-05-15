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
    private $handoff_event_repository;
    private $sms_body_variant_repository;

    public function __construct(
        Kiwi_Config $config,
        Kiwi_Landing_Kpi_Service $kpi_service,
        ?Kiwi_Premium_Sms_Landing_Engagement_Repository $landing_engagement_repository = null,
        ?Kiwi_Click_Attribution_Repository $click_attribution_repository = null,
        ?Kiwi_Landing_Handoff_Event_Repository $handoff_event_repository = null,
        ?Kiwi_Sms_Body_Variant_Repository $sms_body_variant_repository = null
    ) {
        $this->config = $config;
        $this->kpi_service = $kpi_service;
        $this->landing_engagement_repository = $landing_engagement_repository;
        $this->click_attribution_repository = $click_attribution_repository;
        $this->handoff_event_repository = $handoff_event_repository;
        $this->sms_body_variant_repository = $sms_body_variant_repository;
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
        $handoff_recorded = false;
        $sms_body_variant_recorded = false;

        if ($has_valid_step) {
            $incremented = $this->kpi_service->increment_step($landing_key, $step, [
                'service_key' => (string) ($landing['service_key'] ?? ''),
                'provider_key' => (string) ($landing['provider'] ?? ''),
                'flow_key' => (string) ($landing['flow'] ?? ''),
            ]);

            if ($step === 'cta1') {
                $sms_body_variant_recorded = $this->mark_sms_body_variant_event(
                    $landing_key,
                    'cta1',
                    $params
                );
            }
        }

        if ($this->is_landing_engagement_event_type($event_type)) {
            $engagement_recorded = $this->capture_landing_engagement_event(
                $landing_key,
                $event_type,
                $params,
                is_array($landing) ? $landing : []
            );
        }

        if ($this->is_handoff_event_type($event_type)) {
            $handoff_recorded = $this->capture_handoff_event(
                $landing_key,
                $event_type,
                $params,
                is_array($landing) ? $landing : []
            );
            $sms_body_variant_recorded = $this->mark_sms_body_variant_event(
                $landing_key,
                $event_type,
                $params
            ) || $sms_body_variant_recorded;
        }

        return new WP_REST_Response([
            'success' => $incremented || $engagement_recorded || $handoff_recorded || $sms_body_variant_recorded,
            'incremented' => $incremented,
            'engagement_recorded' => $engagement_recorded,
            'handoff_recorded' => $handoff_recorded,
            'sms_body_variant_recorded' => $sms_body_variant_recorded,
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
        return $this->is_landing_engagement_event_type($event_type)
            || $this->is_handoff_event_type($event_type);
    }

    private function is_landing_engagement_event_type(string $event_type): bool
    {
        return in_array($event_type, ['page_loaded', 'cta_click'], true);
    }

    private function is_handoff_event_type(string $event_type): bool
    {
        return in_array($event_type, [
            'sms_handoff_attempted',
            'sms_handoff_hidden',
            'sms_handoff_returned',
            'sms_handoff_no_hide',
        ], true);
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
            'click_id' => $this->resolve_click_id_for_engagement($params, $landing, $session_token),
            'tksource' => $this->resolve_source_value_for_engagement('tksource', $params, $landing, $session_token),
            'tkzone' => $this->resolve_source_value_for_engagement('tkzone', $params, $landing, $session_token),
        ], $event_type);

        return !empty($record);
    }

    private function capture_handoff_event(
        string $landing_key,
        string $event_type,
        array $params,
        array $landing
    ): bool {
        if (!$this->handoff_event_repository instanceof Kiwi_Landing_Handoff_Event_Repository) {
            return false;
        }

        $session_token = trim((string) ($params['session_token'] ?? ''));

        if ($session_token === '') {
            return false;
        }

        $ua_client_hints_enabled = $this->config->is_landing_handoff_ua_client_hints_enabled();
        $ua_client_hints = $ua_client_hints_enabled
            ? $this->sanitize_ua_client_hints_context($params)
            : [];

        $result = $this->handoff_event_repository->insert_if_new([
            'landing_key' => $landing_key,
            'session_token' => $session_token,
            'service_key' => (string) ($landing['service_key'] ?? ''),
            'provider_key' => (string) ($landing['provider'] ?? ''),
            'flow_key' => (string) ($landing['flow'] ?? ''),
            'pid' => $this->resolve_pid_for_engagement($params, $landing, $session_token),
            'click_id' => $this->resolve_click_id_for_engagement($params, $landing, $session_token),
            'tksource' => $this->resolve_source_value_for_engagement('tksource', $params, $landing, $session_token),
            'tkzone' => $this->resolve_source_value_for_engagement('tkzone', $params, $landing, $session_token),
            'handoff_id' => (string) ($params['handoff_id'] ?? ''),
            'event_type' => $event_type,
            'href_scheme' => (string) ($params['href_scheme'] ?? ''),
            'sms_recipient' => (string) ($params['sms_recipient'] ?? ''),
            'sms_body_present' => !empty($params['sms_body_present']),
            'sms_body_has_transaction' => !empty($params['sms_body_has_transaction']),
            'elapsed_ms' => max(0, (int) ($params['elapsed_ms'] ?? 0)),
            'visibility_state' => (string) ($params['visibility_state'] ?? ''),
            'ua_ch_supported' => $ua_client_hints_enabled && !empty($params['ua_ch_supported']),
            'ua_ch_mobile' => $ua_client_hints_enabled && !empty($params['ua_ch_mobile']),
            'ua_ch_platform' => $ua_client_hints_enabled ? (string) ($params['ua_ch_platform'] ?? '') : '',
            'ua_ch_platform_version' => $ua_client_hints_enabled ? (string) ($params['ua_ch_platform_version'] ?? '') : '',
            'ua_ch_model' => $ua_client_hints_enabled ? (string) ($params['ua_ch_model'] ?? '') : '',
            'ua_ch_brands' => $ua_client_hints_enabled ? (string) ($params['ua_ch_brands'] ?? '') : '',
            'ua_ch_full_version_list' => $ua_client_hints_enabled ? (string) ($params['ua_ch_full_version_list'] ?? '') : '',
            'user_agent' => $this->server_value('HTTP_USER_AGENT'),
            'raw_context' => [
                'event_value' => $this->sanitize_event_value((string) ($params['event_value'] ?? '')),
                'ua_client_hints' => $ua_client_hints,
            ],
        ]);

        return is_array($result['row'] ?? null);
    }

    private function sanitize_ua_client_hints_context(array $params): array
    {
        $context = [];

        foreach ([
            'ua_ch_supported',
            'ua_ch_mobile',
            'ua_ch_platform',
            'ua_ch_platform_version',
            'ua_ch_model',
            'ua_ch_brands',
            'ua_ch_full_version_list',
        ] as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }

            $value = $params[$key];

            if (is_bool($value) || is_int($value) || is_float($value)) {
                $context[$key] = $value;
                continue;
            }

            if (is_array($value) || is_object($value)) {
                continue;
            }

            $context[$key] = substr(trim((string) $value), 0, 1000);
        }

        return $context;
    }

    private function mark_sms_body_variant_event(string $landing_key, string $event_key, array $params): bool
    {
        if (!$this->sms_body_variant_repository instanceof Kiwi_Sms_Body_Variant_Repository) {
            return false;
        }

        $session_token = trim((string) ($params['session_token'] ?? ''));

        if ($session_token === '') {
            return false;
        }

        return $this->sms_body_variant_repository->mark_event_by_landing_session(
            $landing_key,
            $session_token,
            $event_key
        );
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

    private function resolve_click_id_for_engagement(array $params, array $landing, string $session_token): string
    {
        $click_id = $this->resolve_click_id_from_params($params);

        if ($click_id !== '') {
            return $click_id;
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

        return $this->sanitize_click_id((string) ($attribution['click_id'] ?? ''));
    }

    private function resolve_click_id_from_params(array $params): string
    {
        $keys = array_merge(['click_id'], $this->config->get_click_attribution_click_id_keys());
        $keys = array_values(array_unique(array_map('strval', $keys)));

        foreach ($keys as $key) {
            if (!array_key_exists($key, $params) || is_array($params[$key])) {
                continue;
            }

            $candidate = $this->sanitize_click_id((string) $params[$key]);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function sanitize_click_id(string $click_id): string
    {
        $click_id = trim($click_id);

        if ($click_id === '') {
            return '';
        }

        $click_id = preg_replace('/[^A-Za-z0-9._~:-]/', '', $click_id);
        $click_id = is_string($click_id) ? $click_id : '';

        return substr($click_id, 0, 191);
    }

    private function resolve_source_value_for_engagement(
        string $field,
        array $params,
        array $landing,
        string $session_token
    ): string {
        $field = strtolower($field);
        if (!in_array($field, ['tksource', 'tkzone'], true)) {
            return '';
        }

        $source_value = $this->sanitize_source_value((string) ($params[$field] ?? ''));

        if ($source_value !== '') {
            return $source_value;
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

        return $this->sanitize_source_value((string) ($attribution[$field] ?? ''));
    }

    private function sanitize_source_value(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9._~:-]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, 191);
    }

    private function server_value(string $key): string
    {
        return isset($_SERVER[$key]) ? trim((string) $_SERVER[$key]) : '';
    }

}
