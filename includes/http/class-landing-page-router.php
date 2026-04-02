<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Page_Router
{
    private $config;
    private $landing_page_session_repository;
    private $plugin_base_url;

    public function __construct(
        Kiwi_Config $config,
        Kiwi_Landing_Page_Session_Repository $landing_page_session_repository,
        string $plugin_base_url
    ) {
        $this->config = $config;
        $this->landing_page_session_repository = $landing_page_session_repository;
        $this->plugin_base_url = rtrim($plugin_base_url, '/\\') . '/';
    }

    public function maybe_render_current_request(): bool
    {
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';

        $match = $this->resolve_request($host, $request_uri);

        if (!is_array($match)) {
            return false;
        }

        $service_key = trim((string) ($match['landing_page']['service_key'] ?? ''));
        $service = $service_key !== ''
            ? $this->config->get_nth_service($service_key)
            : null;
        $landing_page = $this->build_render_landing_page(
            $match['landing_page'],
            is_array($service) ? $service : []
        );
        $click_to_sms_uri = (string) ($landing_page['cta_href'] ?? '#');

        $session_token = $this->resolve_session_token();

        $this->landing_page_session_repository->insert([
            'landing_key' => $match['landing_key'],
            'service_key' => $match['landing_page']['service_key'] ?? '',
            'request_host' => $host,
            'request_path' => $match['request_path'],
            'session_token' => $session_token,
            'click_to_sms_uri' => $click_to_sms_uri,
            'referer' => isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
            'remote_ip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
            'query_params' => $_GET,
            'raw_context' => [
                'host' => $host,
                'request_uri' => $request_uri,
                'landing_page' => $landing_page,
            ],
        ]);

        $template = $this->resolve_template_path((string) ($landing_page['template'] ?? ''));

        if (function_exists('status_header')) {
            status_header(200);
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        $landing_key = $match['landing_key'];
        $asset_base_url = $this->plugin_base_url;

        if ($this->is_filesystem_landing_page($landing_page)) {
            return $this->render_filesystem_landing_page($landing_page, $landing_key);
        }

        if ($template === null) {
            return false;
        }

        include $template;
        exit;
    }

    public function resolve_request(string $host, string $request_uri): ?array
    {
        $request_path = $this->normalize_request_path($request_uri);

        foreach ($this->config->get_landing_pages() as $landing_key => $landing_page) {
            if (!is_array($landing_page)) {
                continue;
            }

            if (array_key_exists('active', $landing_page) && $landing_page['active'] === false) {
                continue;
            }

            $backend_path = $this->normalize_request_path((string) ($landing_page['backend_path'] ?? ''));
            $dedicated_path = $this->normalize_request_path((string) ($landing_page['dedicated_path'] ?? '/'));
            $hostnames = array_map('strtolower', array_map('strval', $landing_page['hostnames'] ?? []));
            $normalized_host = strtolower(trim($host));

            if ($backend_path !== '' && $request_path === $backend_path) {
                return [
                    'landing_key' => (string) $landing_key,
                    'landing_page' => $landing_page,
                    'request_path' => $request_path,
                ];
            }

            if (!empty($hostnames) && in_array($normalized_host, $hostnames, true)) {
                if ($request_path === '/' || $request_path === $dedicated_path) {
                    return [
                        'landing_key' => (string) $landing_key,
                        'landing_page' => $landing_page,
                        'request_path' => $request_path,
                    ];
                }
            }
        }

        return null;
    }

    private function resolve_template_path(string $template): ?string
    {
        $template = trim($template);

        if ($template === '') {
            return null;
        }

        $path = dirname(__DIR__, 2) . '/templates/landing-pages/' . $template . '.php';

        return file_exists($path) ? $path : null;
    }

    private function is_filesystem_landing_page(array $landing_page): bool
    {
        if ((string) ($landing_page['render_mode'] ?? '') !== 'filesystem') {
            return false;
        }

        $index_path = (string) ($landing_page['index_path'] ?? '');

        return $index_path !== '' && is_file($index_path);
    }

    private function render_filesystem_landing_page(array $landing_page, string $landing_key): bool
    {
        $index_path = (string) ($landing_page['index_path'] ?? '');
        $styles_path = (string) ($landing_page['styles_path'] ?? '');

        if (!is_file($index_path) || !is_readable($index_path)) {
            return false;
        }

        $html = file_get_contents($index_path);

        if (!is_string($html) || $html === '') {
            return false;
        }

        $css_url = $this->plugin_base_url . 'landing-pages/' . rawurlencode($landing_key) . '/styles.css';
        $html = $this->replace_stylesheet_href($html, $css_url);

        if (is_file($styles_path) && is_readable($styles_path)) {
            $css_content = file_get_contents($styles_path);

            if (is_string($css_content) && trim($css_content) !== '') {
                $html = $this->inject_inline_styles($html, $css_content);
            }
        }

        echo $html;
        exit;
    }

    private function replace_stylesheet_href(string $html, string $css_url): string
    {
        $escaped_css_url = htmlspecialchars($css_url, ENT_QUOTES, 'UTF-8');
        $pattern = '/(<link\b[^>]*href=["\'])(\.\/)?styles\.css(["\'][^>]*>)/i';
        $count = 0;
        $html = preg_replace($pattern, '$1' . $escaped_css_url . '$3', $html, 1, $count);
        $html = is_string($html) ? $html : '';

        if ($count > 0) {
            return $html;
        }

        $stylesheet_link = '<link rel="stylesheet" href="' . $escaped_css_url . '">';

        if (stripos($html, '</head>') !== false) {
            return preg_replace('/<\/head>/i', $stylesheet_link . "\n</head>", $html, 1) ?? $html;
        }

        return $stylesheet_link . "\n" . $html;
    }

    private function inject_inline_styles(string $html, string $css_content): string
    {
        $style_block = "<style>\n" . $css_content . "\n</style>";

        if (stripos($html, '</head>') !== false) {
            return preg_replace('/<\/head>/i', $style_block . "\n</head>", $html, 1) ?? $html;
        }

        return $style_block . "\n" . $html;
    }

    private function build_render_landing_page(array $landing_page, array $service): array
    {
        $shortcode = trim((string) ($landing_page['shortcode'] ?? ($service['shortcode'] ?? '')));
        $keyword = trim((string) ($landing_page['keyword'] ?? ($service['keyword'] ?? '')));
        $price_label = trim((string) ($landing_page['price_label'] ?? ($service['landing_price_label'] ?? '')));
        $cta_href = trim((string) ($landing_page['cta_href'] ?? ''));

        if ($cta_href === '') {
            $cta_href = $this->build_click_to_sms_uri($shortcode, $keyword);
        }

        if (!array_key_exists('price_info', $landing_page) || trim(strip_tags((string) $landing_page['price_info'])) === '') {
            $landing_page['price_info'] = $this->build_default_price_info(
                (string) ($landing_page['keyword_display'] ?? $keyword),
                (string) ($landing_page['shortcode_display'] ?? $shortcode),
                $price_label
            );
        }

        $landing_page['shortcode'] = $shortcode;
        $landing_page['keyword'] = $keyword;
        $landing_page['price_label'] = $price_label;
        $landing_page['cta_href'] = $cta_href;

        return $landing_page;
    }

    private function build_click_to_sms_uri(string $shortcode, string $keyword): string
    {
        $shortcode = trim($shortcode);
        $keyword = trim($keyword);

        if ($shortcode === '' || $keyword === '') {
            return '#';
        }

        return 'sms:' . $shortcode . '?body=' . rawurlencode($keyword);
    }

    private function build_default_price_info(string $keyword, string $shortcode, string $price_label): string
    {
        $parts = [];
        $keyword = trim($keyword);
        $shortcode = trim($shortcode);
        $price_label = trim($price_label);

        if ($keyword !== '' && $shortcode !== '') {
            $parts[] = 'Activer en envoyant ' . $keyword . ' au ' . $shortcode;
        }

        if ($price_label !== '') {
            $parts[] = $price_label;
        }

        return implode(' <br> ', $parts);
    }

    private function normalize_request_path(string $request_uri): string
    {
        $path = parse_url($request_uri, PHP_URL_PATH);
        $path = is_string($path) ? trim($path) : '';

        if ($path === '') {
            return '/';
        }

        $path = '/' . trim($path, '/');

        return $path === '//' ? '/' : $path;
    }

    private function resolve_session_token(): string
    {
        if (!empty($_COOKIE['kiwi_landing_session'])) {
            return (string) $_COOKIE['kiwi_landing_session'];
        }

        $token = function_exists('wp_generate_uuid4')
            ? wp_generate_uuid4()
            : md5(uniqid('', true));

        if (!headers_sent()) {
            $day_in_seconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;

            setcookie('kiwi_landing_session', $token, [
                'expires' => time() + $day_in_seconds,
                'path' => '/',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }

        return $token;
    }
}
