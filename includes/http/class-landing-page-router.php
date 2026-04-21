<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Page_Router
{
    private const PRIMARY_CTA_PLACEHOLDER = '{{KIWI_PRIMARY_CTA_HREF}}';

    private $config;
    private $landing_page_session_repository;
    private $plugin_base_url;
    private $tracking_capture_service;
    private $primary_cta_resolver;
    private $landing_kpi_service;

    public function __construct(
        Kiwi_Config $config,
        Kiwi_Landing_Page_Session_Repository $landing_page_session_repository,
        string $plugin_base_url,
        ?Kiwi_Tracking_Capture_Service $tracking_capture_service = null,
        ?Kiwi_Landing_Primary_Cta_Resolver $primary_cta_resolver = null,
        ?Kiwi_Landing_Kpi_Service $landing_kpi_service = null
    ) {
        $this->config = $config;
        $this->landing_page_session_repository = $landing_page_session_repository;
        $this->plugin_base_url = rtrim($plugin_base_url, '/\\') . '/';
        $this->tracking_capture_service = $tracking_capture_service;
        $this->primary_cta_resolver = $primary_cta_resolver;
        $this->landing_kpi_service = $landing_kpi_service;
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
        $service = is_array($service) ? $service : [];

        $session_token = $this->resolve_session_token();
        $attribution = null;

        if ($this->tracking_capture_service instanceof Kiwi_Tracking_Capture_Service) {
            $attribution = $this->tracking_capture_service->capture_from_request(
                $match['landing_page'],
                $session_token,
                is_array($_GET) ? $_GET : []
            );
        }

        $primary_cta_href = $this->resolve_primary_cta_href(
            $match['landing_page'],
            $service,
            is_array($attribution) ? $attribution : null
        );
        $landing_page = $this->build_render_landing_page(
            $match['landing_page'],
            $service,
            $primary_cta_href
        );
        $click_to_sms_uri = (string) ($landing_page['cta_href'] ?? '#');

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
            $this->maybe_record_kpi_click($landing_key, $landing_page);

            return $this->render_filesystem_landing_page($landing_page, $landing_key, $session_token);
        }

        if ($template === null) {
            return false;
        }

        $this->maybe_record_kpi_click($landing_key, $landing_page);

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

    private function render_filesystem_landing_page(array $landing_page, string $landing_key, string $session_token): bool
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

        $html = $this->replace_primary_cta_placeholder(
            $html,
            (string) ($landing_page['cta_href'] ?? '#')
        );

        $asset_base_url = $this->plugin_base_url . 'landing-pages/' . rawurlencode($landing_key) . '/';
        $css_url = $asset_base_url . 'styles.css';
        $html = $this->replace_stylesheet_href($html, $css_url);
        $html = $this->replace_local_asset_paths($html, $asset_base_url);

        if (is_file($styles_path) && is_readable($styles_path)) {
            $css_content = file_get_contents($styles_path);

            if (is_string($css_content) && trim($css_content) !== '') {
                $html = $this->inject_inline_styles($html, $css_content);
            }
        }

        $html = $this->inject_kpi_tracker_script(
            $html,
            $landing_page,
            $landing_key,
            $session_token
        );

        echo $html;
        exit;
    }

    private function inject_kpi_tracker_script(
        string $html,
        array $landing_page,
        string $landing_key,
        string $session_token
    ): string {
        $session_token = trim($session_token);

        if ($session_token === '') {
            return $html;
        }

        $tracker_payload = [
            'endpoint' => $this->resolve_kpi_event_endpoint(),
            'landingKey' => $landing_key,
            'sessionToken' => $session_token,
            'steps' => $this->resolve_kpi_step_selectors($landing_page),
        ];
        $tracker_json = $this->json_for_script($tracker_payload);

        if ($tracker_json === '') {
            return $html;
        }

        $script = "<script>(function(){"
            . "var cfg={$tracker_json};"
            . "if(!cfg||!cfg.endpoint||!cfg.landingKey||!cfg.sessionToken){return;}"
            . "var storagePrefix='kiwi_kpi_sent_'+cfg.landingKey+'_'+cfg.sessionToken+'_';"
            . "function resolvePid(){try{if(typeof window==='undefined'||!window.location||typeof window.location.search!=='string'){return '';}if(typeof URLSearchParams!=='undefined'){var params=new URLSearchParams(window.location.search);var value=params.get('pid')||'';return value||'';}var match=window.location.search.match(/[?&]pid=([^&]+)/i);if(!match||!match[1]){return '';}return decodeURIComponent(match[1].replace(/\\+/g,'%20'));}catch(e){return '';}}"
            . "var pid=resolvePid();"
            . "function wasSent(key){try{return window.sessionStorage.getItem(storagePrefix+key)==='1';}catch(e){return false;}}"
            . "function markSent(key){try{window.sessionStorage.setItem(storagePrefix+key,'1');}catch(e){}}"
            . "function dispatch(payload){var normalized=payload&&typeof payload==='object'?payload:{};if(pid){normalized.pid=pid;}var body=JSON.stringify(normalized);var delivered=false;"
            . "try{if(typeof navigator!=='undefined'&&navigator.sendBeacon){delivered=navigator.sendBeacon(cfg.endpoint,new Blob([body],{type:'application/json'}));}}catch(e){}"
            . "if(!delivered&&typeof fetch==='function'){fetch(cfg.endpoint,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',keepalive:true,body:body}).catch(function(){});}}"
            . "function sendStep(step,eventValue){if(!step||wasSent('step_'+step)){return;}"
            . "dispatch({landing_key:cfg.landingKey,session_token:cfg.sessionToken,step:step,event_value:eventValue||''});"
            . "markSent('step_'+step);}"
            . "function sendEngagement(eventType,eventValue,onceKey){if(!eventType){return;}"
            . "if(onceKey&&wasSent('eng_'+onceKey)){return;}"
            . "dispatch({landing_key:cfg.landingKey,session_token:cfg.sessionToken,event_type:eventType,event_value:eventValue||''});"
            . "if(onceKey){markSent('eng_'+onceKey);}}"
            . "function bind(step,selector){if(!selector){return;}var nodes=[];"
            . "try{nodes=document.querySelectorAll(selector);}catch(e){return;}"
            . "if(!nodes||!nodes.length){return;}"
            . "for(var i=0;i<nodes.length;i++){nodes[i].addEventListener('click',function(){sendStep(step,selector);sendEngagement('cta_click',step+':'+selector,'');},{passive:true});}}"
            . "for(var step in cfg.steps){if(Object.prototype.hasOwnProperty.call(cfg.steps,step)){bind(step,cfg.steps[step]);}}"
            . "function trackPageLoaded(){sendEngagement('page_loaded','','page_loaded');}"
            . "if(typeof document!=='undefined'&&document.readyState==='complete'){trackPageLoaded();}"
            . "else if(typeof window!=='undefined'&&window.addEventListener){window.addEventListener('load',trackPageLoaded,{once:true});}"
            . "})();</script>";

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $script . "\n</body>", $html, 1) ?? $html;
        }

        return $html . "\n" . $script;
    }

    private function resolve_kpi_event_endpoint(): string
    {
        if (function_exists('rest_url')) {
            return (string) rest_url('kiwi-backend/v1/landing-kpi/event');
        }

        return '/wp-json/kiwi-backend/v1/landing-kpi/event';
    }

    private function resolve_kpi_step_selectors(array $landing_page): array
    {
        $steps = [];
        $configured_steps = $landing_page['kpi_cta_steps'] ?? null;

        if (is_array($configured_steps)) {
            foreach ($configured_steps as $step => $selector) {
                $step_key = strtolower(trim((string) $step));

                if (preg_match('/^cta[1-9][0-9]*$/', $step_key) !== 1) {
                    continue;
                }

                $normalized_selector = $this->normalize_kpi_selector((string) $selector);

                if ($normalized_selector === '') {
                    continue;
                }

                $steps[$step_key] = $normalized_selector;
            }
        }

        if (empty($steps)) {
            $steps['cta1'] = '.cta';
        }

        return $steps;
    }

    private function normalize_kpi_selector(string $selector): string
    {
        $selector = trim($selector);

        if ($selector === '') {
            return '';
        }

        if (preg_match('/^class\s*=\s*["\']([^"\']+)["\']$/i', $selector, $matches) === 1) {
            $classes = preg_split('/\s+/', trim((string) ($matches[1] ?? '')));
            $classes = array_values(array_filter(array_map(static function ($value): string {
                return trim((string) $value);
            }, is_array($classes) ? $classes : [])));

            if (empty($classes)) {
                return '';
            }

            return '.' . implode('.', $classes);
        }

        if (preg_match('/^[A-Za-z0-9_-]+$/', $selector) === 1) {
            return '.' . $selector;
        }

        if (preg_match('/^[A-Za-z0-9_-]+(?:\s+[A-Za-z0-9_-]+)+$/', $selector) === 1) {
            $classes = preg_split('/\s+/', $selector);
            $classes = array_values(array_filter(array_map(static function ($value): string {
                return trim((string) $value);
            }, is_array($classes) ? $classes : [])));

            if (empty($classes)) {
                return '';
            }

            return '.' . implode('.', $classes);
        }

        return $selector;
    }

    private function json_for_script(array $payload): string
    {
        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($payload)
            : json_encode($payload);

        if (!is_string($encoded) || trim($encoded) === '') {
            return '';
        }

        return str_replace('</', '<\/', $encoded);
    }

    private function maybe_record_kpi_click(string $landing_key, array $landing_page): void
    {
        if (!$this->landing_kpi_service instanceof Kiwi_Landing_Kpi_Service) {
            return;
        }

        $this->landing_kpi_service->increment_click($landing_key, [
            'service_key' => (string) ($landing_page['service_key'] ?? ''),
            'provider_key' => (string) ($landing_page['provider'] ?? ''),
            'flow_key' => (string) ($landing_page['flow'] ?? ''),
        ]);
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

    private function replace_local_asset_paths(string $html, string $asset_base_url): string
    {
        $asset_base_url = rtrim($asset_base_url, '/\\') . '/';

        $pattern = '/(<(?:img|source|video|audio|script|a|link)\b[^>]*\b(?:src|href)=["\'])\.\/([^"\']+)(["\'][^>]*>)/i';
        $rewritten_html = preg_replace_callback(
            $pattern,
            function (array $matches) use ($asset_base_url): string {
                $relative_path = trim((string) ($matches[2] ?? ''));

                if ($relative_path === '' || strtolower($relative_path) === 'styles.css') {
                    return (string) ($matches[0] ?? '');
                }

                $normalized_path = str_replace('\\', '/', $relative_path);
                $normalized_path = ltrim($normalized_path, '/');

                $segments = array_values(array_filter(explode('/', $normalized_path), static function (string $segment): bool {
                    return $segment !== '';
                }));
                $encoded_segments = array_map('rawurlencode', $segments);
                $encoded_path = implode('/', $encoded_segments);

                return (string) ($matches[1] ?? '')
                    . htmlspecialchars($asset_base_url . $encoded_path, ENT_QUOTES, 'UTF-8')
                    . (string) ($matches[3] ?? '');
            },
            $html
        );

        return is_string($rewritten_html) ? $rewritten_html : $html;
    }

    private function inject_inline_styles(string $html, string $css_content): string
    {
        $style_block = "<style>\n" . $css_content . "\n</style>";

        if (stripos($html, '</head>') !== false) {
            return preg_replace('/<\/head>/i', $style_block . "\n</head>", $html, 1) ?? $html;
        }

        return $style_block . "\n" . $html;
    }

    private function replace_primary_cta_placeholder(string $html, string $cta_href): string
    {
        if (strpos($html, self::PRIMARY_CTA_PLACEHOLDER) === false) {
            return $html;
        }

        return str_replace(
            self::PRIMARY_CTA_PLACEHOLDER,
            htmlspecialchars($cta_href, ENT_QUOTES, 'UTF-8'),
            $html
        );
    }

    private function build_render_landing_page(array $landing_page, array $service, string $primary_cta_href = ''): array
    {
        $shortcode = trim((string) ($landing_page['shortcode'] ?? ($service['shortcode'] ?? '')));
        $keyword = trim((string) ($landing_page['keyword'] ?? ($service['keyword'] ?? '')));
        $price_label = trim((string) ($landing_page['price_label'] ?? ($service['landing_price_label'] ?? '')));
        $cta_href = trim($primary_cta_href);

        if ($cta_href === '') {
            $cta_href = trim((string) ($landing_page['cta_href'] ?? ''));
        }

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

    private function resolve_primary_cta_href(array $landing_page, array $service, ?array $attribution): string
    {
        if ($this->primary_cta_resolver instanceof Kiwi_Landing_Primary_Cta_Resolver) {
            return $this->primary_cta_resolver->resolve($landing_page, $service, $attribution);
        }

        return trim((string) ($landing_page['cta_href'] ?? ''));
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
