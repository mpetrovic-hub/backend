<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Page_Gallery_Service
{
    private $config;

    public function __construct(Kiwi_Config $config)
    {
        $this->config = $config;
    }

    public function build_gallery_data(): array
    {
        $landing_pages = $this->config->get_landing_pages();
        $discovery_errors = $this->config->get_landing_page_registry_errors();
        $entries = [];

        foreach ($landing_pages as $fallback_key => $landing_page) {
            if (!is_array($landing_page)) {
                continue;
            }

            if (!$this->is_filesystem_landing_page($landing_page)) {
                continue;
            }

            $normalized_entry = $this->normalize_landing_page((string) $fallback_key, $landing_page);

            if (!is_array($normalized_entry)) {
                continue;
            }

            $entries[] = $normalized_entry;
        }

        usort($entries, static function (array $left, array $right): int {
            return strcmp((string) ($left['key'] ?? ''), (string) ($right['key'] ?? ''));
        });

        $normalized_errors = [];

        foreach ($discovery_errors as $error) {
            $message = trim((string) $error);

            if ($message === '') {
                continue;
            }

            $normalized_errors[] = $message;
        }

        return [
            'count' => count($entries),
            'entries' => $entries,
            'errors' => array_values(array_unique($normalized_errors)),
        ];
    }

    private function normalize_landing_page(string $fallback_key, array $landing_page): ?array
    {
        $key = trim((string) ($landing_page['key'] ?? $fallback_key));

        if ($key === '') {
            return null;
        }

        $hostnames = $this->normalize_hostnames($landing_page['hostnames'] ?? []);
        $backend_path = $this->normalize_path((string) ($landing_page['backend_path'] ?? ''), false);
        $dedicated_path = $this->normalize_path((string) ($landing_page['dedicated_path'] ?? '/'), true);
        $url_bundle = $this->derive_public_url_bundle($hostnames, $dedicated_path, $backend_path);

        return [
            'key' => $key,
            'title' => trim((string) ($landing_page['title'] ?? $key)),
            'country' => strtoupper(trim((string) ($landing_page['country'] ?? ''))),
            'flow' => trim((string) ($landing_page['flow'] ?? '')),
            'service_key' => trim((string) ($landing_page['service_key'] ?? '')),
            'provider' => trim((string) ($landing_page['provider'] ?? '')),
            'backend_path' => $backend_path,
            'dedicated_path' => $dedicated_path,
            'hostnames' => $hostnames,
            'routing_mode' => $this->resolve_routing_mode($hostnames, $backend_path),
            'public_urls' => $url_bundle['urls'],
            'primary_url' => $url_bundle['primary'],
            'preview_url' => $url_bundle['preview_url'],
            'index_path' => trim((string) ($landing_page['index_path'] ?? '')),
            'styles_path' => trim((string) ($landing_page['styles_path'] ?? '')),
            'asset_base_url' => trim((string) ($landing_page['asset_base_url'] ?? '')),
            'documentation' => trim((string) ($landing_page['documentation'] ?? '')),
            'active' => !array_key_exists('active', $landing_page) || (bool) $landing_page['active'],
        ];
    }

    private function is_filesystem_landing_page(array $landing_page): bool
    {
        if ((string) ($landing_page['render_mode'] ?? '') === 'filesystem') {
            return true;
        }

        if (trim((string) ($landing_page['folder_path'] ?? '')) !== '') {
            return true;
        }

        if (trim((string) ($landing_page['index_path'] ?? '')) !== '') {
            return true;
        }

        return false;
    }

    private function normalize_hostnames($hostnames): array
    {
        if (!is_array($hostnames)) {
            return [];
        }

        $normalized = [];

        foreach ($hostnames as $hostname) {
            $hostname = trim(strtolower((string) $hostname));

            if ($hostname === '') {
                continue;
            }

            if (strpos($hostname, '://') !== false) {
                $parsed_host = parse_url($hostname, PHP_URL_HOST);
                $hostname = is_string($parsed_host) ? trim(strtolower($parsed_host)) : '';
            } else {
                $hostname = preg_replace('#/.*$#', '', $hostname);
            }

            if (!is_string($hostname)) {
                continue;
            }

            $hostname = trim($hostname);

            if ($hostname === '') {
                continue;
            }

            $normalized[] = $hostname;
        }

        return array_values(array_unique($normalized));
    }

    private function normalize_path(string $path, bool $default_root): string
    {
        $path = trim($path);

        if ($path === '') {
            return $default_root ? '/' : '';
        }

        $parsed_path = parse_url($path, PHP_URL_PATH);

        if (is_string($parsed_path) && $parsed_path !== '') {
            $path = $parsed_path;
        }

        $path = '/' . ltrim($path, '/');

        if ($path === '//') {
            return '/';
        }

        return $path;
    }

    private function derive_public_url_bundle(array $hostnames, string $dedicated_path, string $backend_path): array
    {
        $urls = [];

        foreach ($hostnames as $hostname) {
            $public_path = $backend_path !== '' ? $backend_path : $dedicated_path;
            $public_label = $backend_path !== '' ? 'Public hostname route' : 'Dedicated hostname';

            $urls[] = [
                'url' => 'https://' . $hostname . $public_path,
                'label' => $public_label,
                'absolute' => true,
                'inferred' => false,
                'path_only' => false,
            ];
        }

        if ($backend_path !== '') {
            $urls[] = [
                'url' => $backend_path,
                'label' => 'Backend path strategy',
                'absolute' => false,
                'inferred' => false,
                'path_only' => true,
            ];

            $current_host_base_url = $this->resolve_current_host_base_url();

            if ($current_host_base_url !== '') {
                $inferred_url = $current_host_base_url . $backend_path;
                $has_explicit_match = false;

                foreach ($urls as $url_item) {
                    if ((string) ($url_item['url'] ?? '') === $inferred_url) {
                        $has_explicit_match = true;
                        break;
                    }
                }

                if (!$has_explicit_match) {
                    $urls[] = [
                        'url' => $inferred_url,
                        'label' => 'Inferred current-site URL',
                        'absolute' => true,
                        'inferred' => true,
                        'path_only' => false,
                    ];
                }
            }
        }

        $primary_url = $this->resolve_primary_url($urls);

        return [
            'urls' => $urls,
            'primary' => $primary_url,
            'preview_url' => $this->resolve_preview_url($primary_url, $urls),
        ];
    }

    private function resolve_primary_url(array $urls): ?array
    {
        foreach ($urls as $url_item) {
            if (($url_item['absolute'] ?? false) && !($url_item['inferred'] ?? false)) {
                return $url_item;
            }
        }

        foreach ($urls as $url_item) {
            if (($url_item['absolute'] ?? false)) {
                return $url_item;
            }
        }

        foreach ($urls as $url_item) {
            if (($url_item['path_only'] ?? false)) {
                return $url_item;
            }
        }

        return null;
    }

    private function resolve_preview_url(?array $primary_url, array $urls): string
    {
        if (is_array($primary_url) && ($primary_url['absolute'] ?? false)) {
            return (string) ($primary_url['url'] ?? '');
        }

        foreach ($urls as $url_item) {
            if (($url_item['absolute'] ?? false)) {
                return (string) ($url_item['url'] ?? '');
            }
        }

        return '';
    }

    private function resolve_current_host_base_url(): string
    {
        if (function_exists('home_url')) {
            $home_url = home_url('/');

            if (is_string($home_url) && trim($home_url) !== '') {
                return rtrim($home_url, '/');
            }
        }

        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $host = trim($host);

        if ($host === '') {
            return '';
        }

        $host = preg_replace('/[^a-zA-Z0-9\\.\\-:\\[\\]]/', '', $host);
        $host = is_string($host) ? trim($host) : '';

        if ($host === '') {
            return '';
        }

        $is_https = false;

        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            $is_https = true;
        }

        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            $is_https = true;
        }

        return ($is_https ? 'https' : 'http') . '://' . $host;
    }

    private function resolve_routing_mode(array $hostnames, string $backend_path): string
    {
        if (!empty($hostnames) && $backend_path !== '') {
            return 'hybrid';
        }

        if (!empty($hostnames)) {
            return 'dedicated';
        }

        if ($backend_path !== '') {
            return 'path';
        }

        return 'unknown';
    }
}
