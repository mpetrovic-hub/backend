<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Tracking_Capture_Service
{
    private $config;
    private $repository;

    public function __construct(Kiwi_Config $config, Kiwi_Click_Attribution_Repository $repository)
    {
        $this->config = $config;
        $this->repository = $repository;
    }

    public function capture_from_request(array $landing_page, string $session_token, array $query_params): ?array
    {
        $click_id = $this->resolve_click_id($query_params);
        $pid = $this->resolve_pid($query_params);

        if ($click_id === '') {
            return null;
        }

        $tracking_token = $this->resolve_tracking_token();
        $expires_at = gmdate(
            'Y-m-d H:i:s',
            time() + max(60, $this->config->get_click_attribution_ttl_seconds())
        );

        $service_key = trim((string) ($landing_page['service_key'] ?? ''));
        $record = $this->repository->upsert_capture([
            'tracking_token' => $tracking_token,
            'click_id' => $click_id,
            'provider_key' => trim((string) ($landing_page['provider'] ?? '')),
            'landing_page_key' => trim((string) ($landing_page['key'] ?? '')),
            'flow_key' => trim((string) ($landing_page['flow'] ?? '')),
            'service_key' => $service_key,
            'pid' => $pid,
            'session_ref' => $this->resolve_optional_reference(
                $query_params,
                ['session_ref', 'sessionid', 'session_id', 'sid'],
                $session_token
            ),
            'external_ref' => $this->resolve_optional_reference(
                $query_params,
                ['external_ref', 'ext_ref', 'subid', 'sub_id', 'aff_sub', 'aff_sub2', 'aff_sub3'],
                ''
            ),
            'expires_at' => $expires_at,
            'raw_context' => [
                'query_params' => $query_params,
                'landing_page_key' => trim((string) ($landing_page['key'] ?? '')),
                'service_key' => $service_key,
            ],
        ]);

        $this->set_tracking_cookie($tracking_token);

        return !empty($record) ? $record : null;
    }

    protected function set_tracking_cookie(string $tracking_token): void
    {
        if ($tracking_token === '' || headers_sent()) {
            return;
        }

        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(
            $this->config->get_click_attribution_cookie_name(),
            $tracking_token,
            [
                'expires' => time() + max(60, $this->config->get_click_attribution_ttl_seconds()),
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    private function resolve_click_id(array $query_params): string
    {
        foreach ($this->config->get_click_attribution_click_id_keys() as $key) {
            if (!array_key_exists($key, $query_params)) {
                continue;
            }

            $raw_value = is_array($query_params[$key])
                ? ''
                : (string) $query_params[$key];
            $value = $this->sanitize_click_id($raw_value);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolve_tracking_token(): string
    {
        $cookie_name = $this->config->get_click_attribution_cookie_name();

        if (!empty($_COOKIE[$cookie_name])) {
            $existing = $this->sanitize_tracking_token((string) $_COOKIE[$cookie_name]);

            if ($existing !== '') {
                return $existing;
            }
        }

        return $this->generate_tracking_token();
    }

    private function generate_tracking_token(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return str_replace('-', '', wp_generate_uuid4());
        }

        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(16));
        }

        return md5(uniqid('', true));
    }

    private function resolve_optional_reference(array $query_params, array $keys, string $fallback): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $query_params) || is_array($query_params[$key])) {
                continue;
            }

            $value = trim((string) $query_params[$key]);

            if ($value !== '') {
                return substr($value, 0, 150);
            }
        }

        return substr(trim($fallback), 0, 150);
    }

    private function sanitize_click_id(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9._~:-]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, 191);
    }

    private function sanitize_tracking_token(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (!preg_match('/^[A-Za-z0-9]{16,100}$/', $value)) {
            return '';
        }

        return $value;
    }

    private function resolve_pid(array $query_params): string
    {
        foreach ($query_params as $key => $value) {
            if (strtolower((string) $key) !== 'pid' || is_array($value)) {
                continue;
            }

            $pid = trim((string) $value);

            if ($pid === '') {
                continue;
            }

            $pid = preg_replace('/[^A-Za-z0-9._~:-]/', '', $pid);
            $pid = is_string($pid) ? $pid : '';

            if ($pid === '') {
                continue;
            }

            return substr($pid, 0, 191);
        }

        return '';
    }
}
