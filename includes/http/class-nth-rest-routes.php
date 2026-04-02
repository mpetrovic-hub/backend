<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Nth_Rest_Routes
{
    private $config;
    private $service;

    public function __construct(Kiwi_Config $config, Kiwi_Nth_Fr_One_Off_Service $service)
    {
        $this->config = $config;
        $this->service = $service;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route('kiwi-backend/v1', '/nth-callback', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_callback'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_callback(WP_REST_Request $request): WP_REST_Response
    {
        $params = $this->normalize_request_params($request);
        $service_key = $this->resolve_service_key($params);

        if ($service_key === '') {
            return $this->acknowledge([
                'success' => false,
                'message' => 'Unable to resolve NTH service key.',
            ], '');
        }

        $callback_type = $this->resolve_callback_type($params);
        $result = $callback_type === 'notification'
            ? $this->service->handle_notification($service_key, $params)
            : $this->service->handle_inbound_mo($service_key, $params);

        return $this->acknowledge($result, $service_key);
    }

    private function normalize_request_params(WP_REST_Request $request): array
    {
        $params = $request->get_params();

        return is_array($params) ? $params : [];
    }

    private function resolve_service_key(array $params): string
    {
        $explicit_service_key = trim((string) ($params['service_key'] ?? ''));

        if ($explicit_service_key !== '' && is_array($this->config->get_nth_service($explicit_service_key))) {
            return $explicit_service_key;
        }

        $shortcode = trim($this->first_non_empty($params, [
            'shortcode',
            'business_number',
            'businessnumber',
            'businessNumber',
            'destination',
            'to',
            'receiver',
            'service_number',
        ]));

        if ($shortcode === '') {
            return '';
        }

        $keyword = $this->normalize_keyword($this->first_non_empty($params, [
            'keyword',
            'service_keyword',
            'servicekeyword',
            'serviceKeyword',
            'message',
            'content',
        ]));
        $candidates = [];

        foreach ($this->config->get_nth_services() as $service_key => $service) {
            if (!is_array($service)) {
                continue;
            }

            $service_shortcode = trim((string) ($service['shortcode'] ?? ''));

            if ($service_shortcode !== $shortcode) {
                continue;
            }

            $service_keyword = $this->normalize_keyword((string) ($service['keyword'] ?? ''));

            if ($keyword !== '' && $service_keyword !== '' && $keyword !== $service_keyword) {
                continue;
            }

            $candidates[] = [
                'service_key' => (string) $service_key,
                'score' => ($keyword !== '' && $service_keyword !== '') ? 2 : 1,
            ];
        }

        if (empty($candidates)) {
            return '';
        }

        usort($candidates, static function (array $left, array $right): int {
            if ($left['score'] === $right['score']) {
                return strcmp((string) $left['service_key'], (string) $right['service_key']);
            }

            return (int) $right['score'] <=> (int) $left['score'];
        });

        if (count($candidates) > 1 && $candidates[0]['score'] === $candidates[1]['score']) {
            return '';
        }

        return (string) $candidates[0]['service_key'];
    }

    private function resolve_callback_type(array $params): string
    {
        $command = strtolower(trim($this->first_non_empty($params, [
            'command',
            'operation',
            'action',
            'event_type',
            'eventtype',
        ])));

        if (in_array($command, ['deliverreport', 'notification', 'report'], true)) {
            return 'notification';
        }

        if (in_array($command, ['delivermessage', 'mo'], true)) {
            return 'mo';
        }

        $status_marker = trim($this->first_non_empty($params, [
            'message_status',
            'messageStatus',
            'status',
            'delivery_status',
            'report_status',
        ]));

        return $status_marker === '' ? 'mo' : 'notification';
    }

    private function first_non_empty(array $params, array $aliases): string
    {
        $normalized = [];

        foreach ($params as $key => $value) {
            $normalized[strtolower((string) $key)] = trim((string) $value);
        }

        foreach ($aliases as $alias) {
            $alias = strtolower($alias);

            if (!array_key_exists($alias, $normalized)) {
                continue;
            }

            if ($normalized[$alias] !== '') {
                return $normalized[$alias];
            }
        }

        return '';
    }

    private function normalize_keyword(string $keyword): string
    {
        $keyword = strtoupper(trim($keyword));
        $keyword = rtrim($keyword, '*');

        return preg_replace('/[^A-Z0-9]/', '', $keyword) ?? '';
    }

    private function acknowledge(array $result, string $service_key): WP_REST_Response
    {
        $response = new WP_REST_Response('OK', 200);
        $response->header('Content-Type', 'text/plain; charset=utf-8');
        $response->header('X-Kiwi-Status', !empty($result['success']) ? 'accepted' : 'rejected');
        $response->header('X-Kiwi-Service-Key', $service_key);

        return $response;
    }
}
