<?php

define('ABSPATH', __DIR__ . '/../');

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

$GLOBALS['kiwi_test_hooks'] = [];
$GLOBALS['kiwi_test_styles'] = [];
$GLOBALS['kiwi_test_scripts'] = [];
$GLOBALS['kiwi_test_transients'] = [];
$GLOBALS['kiwi_test_options'] = [];
$GLOBALS['kiwi_test_shortcodes'] = [];
$GLOBALS['kiwi_test_rest_routes'] = [];
$GLOBALS['kiwi_test_http_responses'] = [];
$GLOBALS['kiwi_test_http_requests'] = [];

function add_action($hook, $callback): void
{
    $GLOBALS['kiwi_test_hooks'][$hook][] = $callback;
}

function add_shortcode($tag, $callback): void
{
    $GLOBALS['kiwi_test_shortcodes'][$tag] = $callback;
}

function register_rest_route($namespace, $route, $args): void
{
    $GLOBALS['kiwi_test_rest_routes'][] = [
        'namespace' => (string) $namespace,
        'route' => (string) $route,
        'args' => $args,
    ];
}

function wp_enqueue_style($handle, $src, $deps = [], $version = false): void
{
    $GLOBALS['kiwi_test_styles'][] = [
        'handle' => $handle,
        'src' => $src,
        'deps' => $deps,
        'version' => $version,
    ];
}

function wp_enqueue_script($handle, $src, $deps = [], $version = false, $in_footer = false): void
{
    $GLOBALS['kiwi_test_scripts'][] = [
        'handle' => $handle,
        'src' => $src,
        'deps' => $deps,
        'version' => $version,
        'in_footer' => $in_footer,
    ];
}

function get_transient($key)
{
    return $GLOBALS['kiwi_test_transients'][$key] ?? false;
}

function set_transient($key, $value, $expiration = 0): bool
{
    $GLOBALS['kiwi_test_transients'][$key] = $value;

    return true;
}

function get_option($option, $default = false)
{
    if (!array_key_exists((string) $option, $GLOBALS['kiwi_test_options'])) {
        return $default;
    }

    return $GLOBALS['kiwi_test_options'][(string) $option];
}

function update_option($option, $value, $autoload = null): bool
{
    $GLOBALS['kiwi_test_options'][(string) $option] = $value;

    return true;
}

function wp_unslash($value)
{
    return $value;
}

function sanitize_text_field($value)
{
    return trim((string) $value);
}

function wp_json_encode($value)
{
    return json_encode($value);
}

function current_time($type)
{
    if ($type === 'mysql') {
        return '2026-04-01 12:00:00';
    }

    return time();
}

function wp_generate_uuid4()
{
    static $counter = 0;
    $counter++;

    return sprintf('00000000-0000-4000-8000-%012d', $counter);
}

function wp_nonce_field($action, $name, $referer = true, $display = true)
{
    return '';
}

function wp_verify_nonce($nonce, $action)
{
    return (string) $nonce === (string) $action;
}

function selected($selected, $current, $display = true)
{
    return (string) $selected === (string) $current ? ' selected="selected"' : '';
}

function esc_attr($text)
{
    return (string) $text;
}

function esc_html($text)
{
    return (string) $text;
}

function esc_textarea($text)
{
    return (string) $text;
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private $code;
        private $message;

        public function __construct(string $code = '', string $message = '')
        {
            $this->code = $code;
            $this->message = $message;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }
    }
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

function wp_remote_post($url, array $args = [])
{
    $GLOBALS['kiwi_test_http_requests'][] = [
        'url' => (string) $url,
        'args' => $args,
    ];

    if (empty($GLOBALS['kiwi_test_http_responses'])) {
        return new WP_Error('kiwi_test_http_missing_response', 'No fake HTTP response configured.');
    }

    return array_shift($GLOBALS['kiwi_test_http_responses']);
}

function wp_remote_retrieve_response_code($response): int
{
    if (is_array($response) && isset($response['response']) && is_array($response['response'])) {
        return (int) ($response['response']['code'] ?? 0);
    }

    if (is_array($response) && isset($response['status_code'])) {
        return (int) $response['status_code'];
    }

    return 0;
}

function wp_remote_retrieve_body($response): string
{
    if (is_array($response) && isset($response['body'])) {
        return (string) $response['body'];
    }

    return '';
}

if (!class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        public const CREATABLE = 'CREATABLE';
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private $params;
        private $json_params;

        public function __construct(array $params = [], ?array $json_params = null)
        {
            $this->params = $params;
            $this->json_params = $json_params;
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }

        public function get_params(): array
        {
            return $this->params;
        }

        public function get_json_params(): ?array
        {
            if (is_array($this->json_params)) {
                return $this->json_params;
            }

            return is_array($this->params) ? $this->params : null;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public $data;
        public $status;
        public $headers = [];

        public function __construct($data = null, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        public function header(string $name, string $value): void
        {
            $this->headers[$name] = $value;
        }
    }
}

require_once __DIR__ . '/../includes/landing-pages/class-landing-page-registry.php';
require_once __DIR__ . '/../includes/core/class-config.php';
require_once __DIR__ . '/../includes/core/class-frontend-auth-gate.php';
require_once __DIR__ . '/../includes/core/class-plugin.php';
require_once __DIR__ . '/../includes/exporters/class-csv-exporter.php';
require_once __DIR__ . '/../includes/providers/dimoco/class-dimoco-client.php';
require_once __DIR__ . '/../includes/providers/lily/class-lily-client.php';
require_once __DIR__ . '/../includes/providers/lily/class-lily-response-parser.php';
require_once __DIR__ . '/../includes/services/class-msisdn-normalizer.php';
require_once __DIR__ . '/../includes/services/class-dimoco-blacklist-batch-service.php';
require_once __DIR__ . '/../includes/services/class-dimoco-refund-batch-service.php';
require_once __DIR__ . '/../includes/services/class-operator-lookup-service.php';
require_once __DIR__ . '/../includes/services/class-operator-lookup-batch-service.php';
require_once __DIR__ . '/../includes/providers/lily/class-lily-operator-lookup-provider.php';
require_once __DIR__ . '/../includes/providers/dimoco/class-dimoco-operator-lookup-provider.php';
require_once __DIR__ . '/../includes/providers/class-routed-operator-lookup-provider.php';
require_once __DIR__ . '/../includes/providers/dimoco/class-dimoco-response-parser.php';
require_once __DIR__ . '/../includes/providers/dimoco/class-dimoco-callback-verifier.php';
require_once __DIR__ . '/../includes/repositories/class-dimoco-callback-blacklist-repository.php';
require_once __DIR__ . '/../includes/repositories/class-dimoco-callback-operator-lookup-repository.php';
require_once __DIR__ . '/../includes/repositories/class-dimoco-callback-refund-repository.php';
require_once __DIR__ . '/../includes/repositories/class-landing-page-session-repository.php';
require_once __DIR__ . '/../includes/repositories/class-nth-event-repository.php';
require_once __DIR__ . '/../includes/repositories/class-nth-flow-transaction-repository.php';
require_once __DIR__ . '/../includes/repositories/class-click-attribution-repository.php';
require_once __DIR__ . '/../includes/repositories/class-sales-repository.php';
require_once __DIR__ . '/../includes/repositories/class-landing-kpi-summary-repository.php';
require_once __DIR__ . '/../includes/repositories/class-premium-sms-landing-engagement-repository.php';
require_once __DIR__ . '/../includes/repositories/class-premium-sms-fraud-signal-repository.php';
require_once __DIR__ . '/../includes/providers/nth/class-nth-premium-sms-normalizer.php';
require_once __DIR__ . '/../includes/providers/nth/class-nth-client.php';
require_once __DIR__ . '/../includes/shortcodes/class-dimoco-blacklister-shortcode.php';
require_once __DIR__ . '/../includes/shortcodes/class-dimoco-refunder-shortcode.php';
require_once __DIR__ . '/../includes/shortcodes/class-hlr-lookup-shortcode.php';
require_once __DIR__ . '/../includes/shortcodes/class-premium-sms-fraud-shortcode.php';
require_once __DIR__ . '/../includes/services/class-shared-sales-recorder.php';
require_once __DIR__ . '/../includes/services/class-affiliate-postback-dispatcher.php';
require_once __DIR__ . '/../includes/services/class-conversion-attribution-resolver.php';
require_once __DIR__ . '/../includes/services/class-tracking-capture-service.php';
require_once __DIR__ . '/../includes/services/class-premium-sms-mo-engagement-evaluator-service.php';
require_once __DIR__ . '/../includes/services/class-premium-sms-fraud-monitor-service.php';
require_once __DIR__ . '/../includes/services/class-landing-primary-cta-adapter-interface.php';
require_once __DIR__ . '/../includes/services/class-landing-primary-cta-resolver.php';
require_once __DIR__ . '/../includes/services/class-landing-kpi-service.php';
require_once __DIR__ . '/../includes/services/class-landing-page-gallery-service.php';
require_once __DIR__ . '/../includes/services/class-landing-page-variant-agent.php';
require_once __DIR__ . '/../includes/providers/nth/class-nth-primary-cta-adapter.php';
require_once __DIR__ . '/../includes/services/class-nth-fr-one-off-service.php';
require_once __DIR__ . '/../includes/http/class-landing-page-router.php';
require_once __DIR__ . '/../includes/http/class-nth-rest-routes.php';
require_once __DIR__ . '/../includes/http/class-landing-kpi-rest-routes.php';
require_once __DIR__ . '/../includes/http/class-dimoco-rest-routes.php';
require_once __DIR__ . '/../includes/shortcodes/class-landing-pages-gallery-shortcode.php';

function kiwi_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message
            . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

function kiwi_assert_true($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function kiwi_assert_contains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException(
            $message
            . "\nExpected to find: " . var_export($needle, true)
            . "\nIn: " . var_export($haystack, true)
        );
    }
}

function kiwi_create_temp_directory(string $prefix): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . '_' . uniqid('', true);

    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create temp directory: ' . $path);
    }

    return $path;
}

function kiwi_write_file(string $path, string $contents): void
{
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create directory: ' . $directory);
    }

    file_put_contents($path, $contents);
}

function kiwi_remove_directory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    $entries = is_array($entries) ? $entries : [];

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $entry_path = $path . DIRECTORY_SEPARATOR . $entry;

        if (is_dir($entry_path)) {
            kiwi_remove_directory($entry_path);
            continue;
        }

        unlink($entry_path);
    }

    rmdir($path);
}

function kiwi_write_landing_page_fixture(
    string $project_root,
    string $folder_name,
    array $metadata_overrides = [],
    bool $with_styles = true,
    bool $create_docs_file = true
): void {
    $landing_root = $project_root . DIRECTORY_SEPARATOR . 'landing-pages';
    $folder_path = $landing_root . DIRECTORY_SEPARATOR . $folder_name;

    kiwi_write_file(
        $folder_path . DIRECTORY_SEPARATOR . 'index.html',
        "<!doctype html>\n<html><head><link rel=\"stylesheet\" href=\"./styles.css\"></head><body>LP</body></html>\n"
    );

    if ($with_styles) {
        kiwi_write_file(
            $folder_path . DIRECTORY_SEPARATOR . 'styles.css',
            "body { font-family: Arial, sans-serif; }\n"
        );
    }

    $metadata = array_merge([
        'key' => $folder_name,
        'country' => 'FR',
        'flow' => 'nth-fr-one-off',
        'provider' => 'nth',
        'documentation' => '/integrations/nth/fr/one-off/README.md',
        'active' => true,
        'backend_path' => '/lp/fr/myjoyplay',
        'dedicated_path' => '/',
        'hostnames' => ['frlp1.joy-play.com'],
        'service_key' => 'nth_fr_one_off_jplay',
    ], $metadata_overrides);

    kiwi_write_file(
        $folder_path . DIRECTORY_SEPARATOR . 'integration.php',
        "<?php\n\nreturn " . var_export($metadata, true) . ";\n"
    );

    if ($create_docs_file) {
        $documentation = (string) ($metadata['documentation'] ?? '');

        if (strpos($documentation, '/integrations/') === 0) {
            $relative_doc_path = ltrim(substr($documentation, strlen('/integrations/')), '/');
            kiwi_write_file(
                $project_root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'integrations' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_doc_path),
                "# fixture\n"
            );
        }
    }
}

function kiwi_write_variant_agent_fixture(
    string $project_root,
    string $folder_name,
    string $landing_title
): void {
    kiwi_write_file(
        $project_root . DIRECTORY_SEPARATOR . 'README.md',
        "# Fixture README\n\nRepository conventions.\n"
    );
    kiwi_write_file(
        $project_root . DIRECTORY_SEPARATOR . 'agents.md',
        "# Fixture AGENTS\n\nAgent operating instructions.\n"
    );

    kiwi_write_landing_page_fixture($project_root, $folder_name, [
        'title' => $landing_title,
        'keyword' => 'JPLAY',
        'shortcode' => '84072',
        'price_label' => '4,50 EUR / SMS + prix d\'un SMS',
    ]);

    $folder_path = $project_root . DIRECTORY_SEPARATOR . 'landing-pages' . DIRECTORY_SEPARATOR . $folder_name;

    kiwi_write_file(
        $folder_path . DIRECTORY_SEPARATOR . 'index.html',
        "<!doctype html>\n"
        . "<html lang=\"fr\">\n"
        . "<head>\n"
        . "  <meta charset=\"utf-8\">\n"
        . "  <title>MyJoyplay FR</title>\n"
        . "  <link rel=\"stylesheet\" href=\"./styles.css\">\n"
        . "</head>\n"
        . "<body>\n"
        . "  <main class=\"lp-container\">\n"
        . "    <h1>Service Joyplay</h1>\n"
        . "    <p>Catalogue de jeux mobile.</p>\n"
        . "    <a class=\"cta\" href=\"{{KIWI_PRIMARY_CTA_HREF}}\">CONTINUER ET PAYER</a>\n"
        . "    <p class=\"price\">Activer en envoyant JPLAY au 84072<br>4,50 EUR / SMS + prix d'un SMS</p>\n"
        . "    <p class=\"disclaimer\">Service a frais uniques. Assistance: myjoyplay.fr@silverlines.info.</p>\n"
        . "  </main>\n"
        . "</body>\n"
        . "</html>\n"
    );
}

function kiwi_run_test(string $name, callable $test): void
{
    $test();
    echo "[PASS] {$name}\n";
}

class Kiwi_Test_Config extends Kiwi_Config
{
    private $hlr_batch_limit;
    private $hlr_request_delay_ms;
    private $hlr_retry_delay_seconds;
    private $operator_lookup_routes;
    private $dimoco_services;
    private $nth_services;
    private $landing_pages;
    private $nth_submit_timeout;
    private $premium_sms_fraud_threshold_1h;
    private $premium_sms_fraud_threshold_24h;
    private $premium_sms_fraud_mo_engagement_mode;
    private $premium_sms_fraud_mo_require_page_loaded;
    private $premium_sms_fraud_mo_require_cta_click;
    private $premium_sms_fraud_mo_min_seconds_after_load;

    public function __construct(
        int $hlr_batch_limit = 100,
        int $hlr_request_delay_ms = 0,
        int $hlr_retry_delay_seconds = 0,
        array $operator_lookup_routes = [],
        array $dimoco_services = [],
        array $nth_services = [],
        array $landing_pages = [],
        int $nth_submit_timeout = 180,
        int $premium_sms_fraud_threshold_1h = 3,
        int $premium_sms_fraud_threshold_24h = 6,
        string $premium_sms_fraud_mo_engagement_mode = 'observe',
        bool $premium_sms_fraud_mo_require_page_loaded = true,
        bool $premium_sms_fraud_mo_require_cta_click = true,
        int $premium_sms_fraud_mo_min_seconds_after_load = 1
    ) {
        $this->hlr_batch_limit = $hlr_batch_limit;
        $this->hlr_request_delay_ms = $hlr_request_delay_ms;
        $this->hlr_retry_delay_seconds = $hlr_retry_delay_seconds;
        $this->operator_lookup_routes = $operator_lookup_routes;
        $this->dimoco_services = $dimoco_services;
        $this->nth_services = $nth_services;
        $this->landing_pages = $landing_pages;
        $this->nth_submit_timeout = $nth_submit_timeout;
        $this->premium_sms_fraud_threshold_1h = $premium_sms_fraud_threshold_1h;
        $this->premium_sms_fraud_threshold_24h = $premium_sms_fraud_threshold_24h;
        $this->premium_sms_fraud_mo_engagement_mode = $premium_sms_fraud_mo_engagement_mode;
        $this->premium_sms_fraud_mo_require_page_loaded = $premium_sms_fraud_mo_require_page_loaded;
        $this->premium_sms_fraud_mo_require_cta_click = $premium_sms_fraud_mo_require_cta_click;
        $this->premium_sms_fraud_mo_min_seconds_after_load = $premium_sms_fraud_mo_min_seconds_after_load;
    }

    public function get_hlr_batch_limit(): int
    {
        return $this->hlr_batch_limit;
    }

    public function get_hlr_request_delay_ms(): int
    {
        return $this->hlr_request_delay_ms;
    }

    public function get_hlr_retry_delay_seconds(): int
    {
        return $this->hlr_retry_delay_seconds;
    }

    public function get_operator_lookup_routes(): array
    {
        return $this->operator_lookup_routes;
    }

    public function get_dimoco_service(string $key): ?array
    {
        return $this->dimoco_services[$key] ?? null;
    }

    public function get_dimoco_services(): array
    {
        return $this->dimoco_services;
    }

    public function get_dimoco_service_options(): array
    {
        $options = [];

        foreach ($this->dimoco_services as $key => $service) {
            $options[$key] = $service['label'] ?? $key;
        }

        return $options;
    }

    public function get_nth_services(): array
    {
        return $this->nth_services;
    }

    public function get_nth_service(string $key): ?array
    {
        return $this->nth_services[$key] ?? null;
    }

    public function get_landing_pages(): array
    {
        return $this->landing_pages;
    }

    public function get_landing_page(string $key): ?array
    {
        return $this->landing_pages[$key] ?? null;
    }

    public function get_nth_submit_timeout(): int
    {
        return $this->nth_submit_timeout;
    }

    public function get_premium_sms_fraud_threshold_1h(): int
    {
        return $this->premium_sms_fraud_threshold_1h;
    }

    public function get_premium_sms_fraud_threshold_24h(): int
    {
        return $this->premium_sms_fraud_threshold_24h;
    }

    public function get_premium_sms_fraud_mo_engagement_mode(): string
    {
        $mode = strtolower(trim((string) $this->premium_sms_fraud_mo_engagement_mode));

        return in_array($mode, ['observe', 'block'], true) ? $mode : 'observe';
    }

    public function get_premium_sms_fraud_mo_require_page_loaded(): bool
    {
        return (bool) $this->premium_sms_fraud_mo_require_page_loaded;
    }

    public function get_premium_sms_fraud_mo_require_cta_click(): bool
    {
        return (bool) $this->premium_sms_fraud_mo_require_cta_click;
    }

    public function get_premium_sms_fraud_mo_min_seconds_after_load(): int
    {
        return max(0, (int) $this->premium_sms_fraud_mo_min_seconds_after_load);
    }
}

class Kiwi_Test_Runtime_Config extends Kiwi_Config
{
    private $landing_pages_root;
    private $legacy_landing_pages;
    private $filesystem_enabled;
    private $legacy_fallback_enabled;
    private $debug;
    private $nth_services;

    public function __construct(
        string $landing_pages_root,
        array $legacy_landing_pages = [],
        bool $filesystem_enabled = true,
        bool $legacy_fallback_enabled = true,
        bool $debug = false,
        array $nth_services = []
    ) {
        $this->landing_pages_root = $landing_pages_root;
        $this->legacy_landing_pages = $legacy_landing_pages;
        $this->filesystem_enabled = $filesystem_enabled;
        $this->legacy_fallback_enabled = $legacy_fallback_enabled;
        $this->debug = $debug;
        $this->nth_services = $nth_services;
    }

    public function is_debug(): bool
    {
        return $this->debug;
    }

    public function get_nth_service(string $key): ?array
    {
        return $this->nth_services[$key] ?? null;
    }

    protected function get_landing_pages_root_path(): string
    {
        return $this->landing_pages_root;
    }

    protected function get_legacy_landing_pages(): array
    {
        return $this->legacy_landing_pages;
    }

    protected function is_landing_pages_filesystem_enabled(): bool
    {
        return $this->filesystem_enabled;
    }

    protected function is_landing_pages_legacy_fallback_enabled(): bool
    {
        return $this->legacy_fallback_enabled;
    }
}

class Kiwi_Test_Attribution_Config extends Kiwi_Test_Config
{
    private $postback_template;
    private $postback_secret;
    private $postback_signature_base;
    private $postback_signature_parameter;
    private $click_id_keys;
    private $ttl_seconds;

    public function __construct(
        string $postback_template = '',
        string $postback_secret = '',
        string $postback_signature_base = '{clickid}:{secret}',
        string $postback_signature_parameter = 'secure',
        array $click_id_keys = ['clickid', 'click_id'],
        int $ttl_seconds = 172800
    ) {
        parent::__construct();
        $this->postback_template = $postback_template;
        $this->postback_secret = $postback_secret;
        $this->postback_signature_base = $postback_signature_base;
        $this->postback_signature_parameter = $postback_signature_parameter;
        $this->click_id_keys = $click_id_keys;
        $this->ttl_seconds = $ttl_seconds;
    }

    public function get_affiliate_postback_url_template(): string
    {
        return $this->postback_template;
    }

    public function get_affiliate_postback_secret(): string
    {
        return $this->postback_secret;
    }

    public function get_affiliate_postback_signature_base(): string
    {
        return $this->postback_signature_base;
    }

    public function get_affiliate_postback_signature_parameter(): string
    {
        return $this->postback_signature_parameter;
    }

    public function get_affiliate_postback_signature_algorithm(): string
    {
        return 'sha256';
    }

    public function get_affiliate_postback_timeout_seconds(): int
    {
        return 5;
    }

    public function get_affiliate_postback_response_body_limit(): int
    {
        return 1000;
    }

    public function get_click_attribution_click_id_keys(): array
    {
        return $this->click_id_keys;
    }

    public function get_click_attribution_ttl_seconds(): int
    {
        return $this->ttl_seconds;
    }
}

class Kiwi_Test_Nth_Service extends Kiwi_Nth_Fr_One_Off_Service
{
    public $mo_calls = [];
    public $notification_calls = [];

    public function __construct()
    {
    }

    public function handle_inbound_mo(string $service_key, array $payload): array
    {
        $this->mo_calls[] = [
            'service_key' => $service_key,
            'payload' => $payload,
        ];

        return [
            'success' => true,
            'message' => 'mo',
        ];
    }

    public function handle_notification(string $service_key, array $payload): array
    {
        $this->notification_calls[] = [
            'service_key' => $service_key,
            'payload' => $payload,
        ];

        return [
            'success' => true,
            'message' => 'notification',
        ];
    }
}

class Kiwi_Test_Lookup_Provider
{
    public $calls = [];
    private $responses_by_msisdn;

    public function __construct(array $responses_by_msisdn)
    {
        $this->responses_by_msisdn = $responses_by_msisdn;
    }

    public function lookup(string $msisdn): array
    {
        $this->calls[] = $msisdn;

        if (!isset($this->responses_by_msisdn[$msisdn])) {
            return [];
        }

        $responses = &$this->responses_by_msisdn[$msisdn];
        $response = array_shift($responses);

        if (empty($responses)) {
            $responses[] = $response;
        }

        return $response;
    }
}

class Kiwi_Test_Lily_Provider extends Kiwi_Lily_Operator_Lookup_Provider
{
    public $calls = [];

    public function __construct()
    {
    }

    public function lookup(string $msisdn): array
    {
        $this->calls[] = $msisdn;

        return [
            'provider' => 'lily',
            'msisdn' => $msisdn,
        ];
    }
}

class Kiwi_Test_Dimoco_Provider extends Kiwi_Dimoco_Operator_Lookup_Provider
{
    public $calls = [];

    public function __construct()
    {
    }

    public function lookup(string $msisdn, string $service_key): array
    {
        $this->calls[] = [$msisdn, $service_key];

        return [
            'provider' => 'dimoco',
            'msisdn' => $msisdn,
            'service_key' => $service_key,
        ];
    }
}

class Kiwi_Test_Plugin extends Kiwi_Plugin
{
    public $exported_rows;
    public $hlr_async_export_rows = [];
    public $hlr_async_export_request_ids = [];
    public $hlr_async_export_rows_by_msisdn = [];
    public $hlr_async_export_msisdns = [];

    protected function export_hlr_rows(array $rows): void
    {
        $this->exported_rows = $rows;
    }

    protected function load_hlr_async_export_rows(array $request_ids): array
    {
        $this->hlr_async_export_request_ids[] = $request_ids;

        return $this->hlr_async_export_rows;
    }

    protected function load_hlr_async_export_rows_by_msisdns(array $msisdns): array
    {
        $this->hlr_async_export_msisdns[] = $msisdns;

        return $this->hlr_async_export_rows_by_msisdn;
    }
}

class Kiwi_Test_Plugin_Performance_Gates extends Kiwi_Plugin
{
    public $schema_migration_runs = 0;
    public $cleanup_limits = [];
    public $cleanup_limit = 777;

    protected function run_schema_migrations(): void
    {
        $this->schema_migration_runs++;
    }

    protected function get_click_attribution_cleanup_limit(): int
    {
        return $this->cleanup_limit;
    }

    protected function cleanup_click_attribution_records(int $limit): void
    {
        $this->cleanup_limits[] = $limit;
    }
}

class Kiwi_Test_Dimoco_Client extends Kiwi_Dimoco_Client
{
    public $add_blocklist_calls = [];
    private $add_blocklist_response;

    public function __construct(array $add_blocklist_response = [])
    {
        $this->add_blocklist_response = $add_blocklist_response;
    }

    public function add_blocklist(
        string $service_key,
        string $msisdn,
        string $operator,
        string $blocklist_scope = 'merchant'
    ): array {
        $this->add_blocklist_calls[] = [
            'service_key' => $service_key,
            'msisdn' => $msisdn,
            'operator' => $operator,
            'blocklist_scope' => $blocklist_scope,
        ];

        return $this->add_blocklist_response;
    }
}

class Kiwi_Test_Dimoco_Refund_Batch_Service extends Kiwi_Dimoco_Refund_Batch_Service
{
    public $calls = [];
    private $result;

    public function __construct(array $result)
    {
        $this->result = $result;
    }

    public function process(string $service_key, string $msisdn, string $input): array
    {
        $this->calls[] = [
            'service_key' => $service_key,
            'msisdn' => $msisdn,
            'input' => $input,
        ];

        return $this->result;
    }
}

class Kiwi_Test_Operator_Lookup_Batch_Service extends Kiwi_Operator_Lookup_Batch_Service
{
    public $calls = [];
    private $result;

    public function __construct(array $result)
    {
        $this->result = $result;
    }

    public function process(string $input): array
    {
        $this->calls[] = $input;

        return $this->result;
    }
}

class Kiwi_Test_Operator_Lookup_Repository extends Kiwi_Dimoco_Callback_Operator_Lookup_Repository
{
    public $calls = [];
    private $responses_by_request_id;

    public function __construct(array $responses_by_request_id)
    {
        $this->responses_by_request_id = $responses_by_request_id;
    }

    public function get_success_by_request_id(string $request_id): ?array
    {
        $this->calls[] = $request_id;

        if (!array_key_exists($request_id, $this->responses_by_request_id)) {
            return null;
        }

        $responses = &$this->responses_by_request_id[$request_id];

        if (!is_array($responses)) {
            return $responses;
        }

        $response = array_shift($responses);

        if (empty($responses)) {
            $responses[] = $response;
        }

        return $response;
    }
}

class Kiwi_Test_Blacklist_Callback_Repository extends Kiwi_Dimoco_Callback_Blacklist_Repository
{
    public $calls = [];
    private $response_sequence;

    public function __construct(array $response_sequence)
    {
        $this->response_sequence = $response_sequence;
    }

    public function get_recent_by_request_ids(array $request_ids, int $limit = 100): array
    {
        $this->calls[] = [
            'request_ids' => $request_ids,
            'limit' => $limit,
        ];

        if (empty($this->response_sequence)) {
            return [];
        }

        $response = array_shift($this->response_sequence);

        if (empty($this->response_sequence)) {
            $this->response_sequence[] = $response;
        }

        return $response;
    }
}

class Kiwi_Test_Refund_Callback_Repository extends Kiwi_Dimoco_Callback_Refund_Repository
{
    public $calls = [];
    private $response;
    private $response_sequence;

    public function __construct(array $response, array $response_sequence = [])
    {
        $this->response = $response;
        $this->response_sequence = $response_sequence;
    }

    private function next_response(): array
    {
        if (empty($this->response_sequence)) {
            return $this->response;
        }

        $response = array_shift($this->response_sequence);

        if (empty($this->response_sequence)) {
            $this->response_sequence[] = $response;
        }

        return $response;
    }

    public function get_recent_by_request_ids(array $request_ids, int $limit = 100): array
    {
        $this->calls[] = [
            'request_ids' => $request_ids,
            'limit' => $limit,
        ];

        return $this->next_response();
    }

    public function get_recent_by_transaction_ids(array $transaction_ids, int $limit = 100): array
    {
        $this->calls[] = [
            'transaction_ids' => $transaction_ids,
            'limit' => $limit,
        ];

        return $this->next_response();
    }
}

class Kiwi_Test_Insert_Refund_Callback_Repository extends Kiwi_Dimoco_Callback_Refund_Repository
{
    public $rows = [];

    public function __construct()
    {
    }

    public function insert(array $data): bool
    {
        $this->rows[] = $data;

        return true;
    }
}

class Kiwi_Test_Insert_Blacklist_Callback_Repository extends Kiwi_Dimoco_Callback_Blacklist_Repository
{
    public $rows = [];

    public function __construct()
    {
    }

    public function insert(array $data): bool
    {
        $this->rows[] = $data;

        return true;
    }
}

class Kiwi_Test_Insert_Operator_Lookup_Callback_Repository extends Kiwi_Dimoco_Callback_Operator_Lookup_Repository
{
    public $rows = [];

    public function __construct()
    {
    }

    public function insert(array $data): bool
    {
        $this->rows[] = $data;

        return true;
    }
}

class Kiwi_Test_Hlr_Callback_Repository extends Kiwi_Dimoco_Callback_Operator_Lookup_Repository
{
    public $calls = [];
    public $msisdn_calls = [];
    private $response;
    private $response_by_msisdn;

    public function __construct(array $response, array $response_by_msisdn = [])
    {
        $this->response = $response;
        $this->response_by_msisdn = $response_by_msisdn;
    }

    public function get_recent_by_request_ids(array $request_ids, int $limit = 100): array
    {
        $this->calls[] = [
            'request_ids' => $request_ids,
            'limit' => $limit,
        ];

        return $this->response;
    }

    public function get_recent_by_msisdns(array $msisdns, int $limit = 100): array
    {
        $this->msisdn_calls[] = [
            'msisdns' => $msisdns,
            'limit' => $limit,
        ];

        return $this->response_by_msisdn;
    }
}

class Kiwi_Test_Dimoco_Blacklist_Batch_Service extends Kiwi_Dimoco_Blacklist_Batch_Service
{
    private $lookup_timeout_seconds;
    private $lookup_poll_interval_microseconds;

    public function __construct(
        Kiwi_Operator_Lookup_Service $operator_lookup_service,
        Kiwi_Dimoco_Callback_Operator_Lookup_Repository $operator_lookup_repository,
        Kiwi_Dimoco_Client $client,
        Kiwi_Dimoco_Response_Parser $parser,
        Kiwi_Config $config,
        Kiwi_Msisdn_Normalizer $normalizer,
        int $lookup_timeout_seconds = 0,
        int $lookup_poll_interval_microseconds = 0
    ) {
        parent::__construct(
            $operator_lookup_service,
            $operator_lookup_repository,
            $client,
            $parser,
            $config,
            $normalizer
        );

        $this->lookup_timeout_seconds = $lookup_timeout_seconds;
        $this->lookup_poll_interval_microseconds = $lookup_poll_interval_microseconds;
    }

    protected function get_lookup_timeout_seconds(): int
    {
        return $this->lookup_timeout_seconds;
    }

    protected function get_lookup_poll_interval_microseconds(): int
    {
        return $this->lookup_poll_interval_microseconds;
    }
}

class Kiwi_Test_Noop_Blacklist_Batch_Service extends Kiwi_Dimoco_Blacklist_Batch_Service
{
    public function __construct()
    {
    }
}

class Kiwi_Test_Noop_Refund_Batch_Service extends Kiwi_Dimoco_Refund_Batch_Service
{
    public function __construct()
    {
    }
}

class Kiwi_Test_Noop_Operator_Lookup_Batch_Service extends Kiwi_Operator_Lookup_Batch_Service
{
    public function __construct()
    {
    }
}

class Kiwi_Test_Dimoco_Blacklister_Shortcode extends Kiwi_Dimoco_Blacklister_Shortcode
{
    private $async_timeout_seconds;
    private $async_poll_interval_microseconds;
    public $redirect_result_state_id;
    private $generated_result_state_id;

    public function __construct(
        Kiwi_Dimoco_Blacklist_Batch_Service $batch_service,
        Kiwi_Config $config,
        Kiwi_Dimoco_Callback_Blacklist_Repository $callback_blacklist_repository,
        int $async_timeout_seconds = 1,
        int $async_poll_interval_microseconds = 0,
        string $generated_result_state_id = 'kiwi_dimoco_blacklister_test_state',
        ?Kiwi_Frontend_Auth_Gate $frontend_auth_gate = null
    ) {
        parent::__construct($batch_service, $config, $callback_blacklist_repository, $frontend_auth_gate);

        $this->async_timeout_seconds = $async_timeout_seconds;
        $this->async_poll_interval_microseconds = $async_poll_interval_microseconds;
        $this->generated_result_state_id = $generated_result_state_id;
    }

    public function collect_async_results(array $request_ids): array
    {
        return $this->wait_for_async_blacklist_callbacks($request_ids);
    }

    public function store_result_state_for_test(array $state): string
    {
        return $this->store_result_state($state);
    }

    public function load_result_state_from_request_for_test(): ?array
    {
        return $this->load_result_state_from_request();
    }

    public function maybe_store_and_redirect_result_state_for_test(array $state): bool
    {
        return $this->maybe_store_and_redirect_result_state($state);
    }

    protected function get_async_timeout_seconds(): int
    {
        return $this->async_timeout_seconds;
    }

    protected function get_async_poll_interval_microseconds(): int
    {
        return $this->async_poll_interval_microseconds;
    }

    protected function can_redirect_after_submission(): bool
    {
        return true;
    }

    protected function generate_result_state_id(): string
    {
        return $this->generated_result_state_id;
    }

    protected function redirect_to_result_state(string $result_state_id): void
    {
        $this->redirect_result_state_id = $result_state_id;
    }
}

class Kiwi_Test_Dimoco_Refunder_Shortcode extends Kiwi_Dimoco_Refunder_Shortcode
{
    private $async_timeout_seconds;
    private $async_poll_interval_microseconds;
    public $redirect_result_state_id;
    private $generated_result_state_id;

    public function __construct(
        Kiwi_Dimoco_Refund_Batch_Service $batch_service,
        Kiwi_Config $config,
        Kiwi_Dimoco_Callback_Refund_Repository $callback_refund_repository,
        string $generated_result_state_id = 'kiwi_dimoco_refunder_test_state',
        int $async_timeout_seconds = 1,
        int $async_poll_interval_microseconds = 0,
        ?Kiwi_Frontend_Auth_Gate $frontend_auth_gate = null
    ) {
        parent::__construct($batch_service, $config, $callback_refund_repository, $frontend_auth_gate);

        $this->generated_result_state_id = $generated_result_state_id;
        $this->async_timeout_seconds = $async_timeout_seconds;
        $this->async_poll_interval_microseconds = $async_poll_interval_microseconds;
    }

    public function collect_async_results(array $request_ids): array
    {
        return $this->wait_for_async_refund_callbacks($request_ids);
    }

    public function store_result_state_for_test(array $state): string
    {
        return $this->store_result_state($state);
    }

    public function load_result_state_from_request_for_test(): ?array
    {
        return $this->load_result_state_from_request();
    }

    public function maybe_store_and_redirect_result_state_for_test(array $state): bool
    {
        return $this->maybe_store_and_redirect_result_state($state);
    }

    protected function can_redirect_after_submission(): bool
    {
        return true;
    }

    protected function get_async_timeout_seconds(): int
    {
        return $this->async_timeout_seconds;
    }

    protected function get_async_poll_interval_microseconds(): int
    {
        return $this->async_poll_interval_microseconds;
    }

    protected function generate_result_state_id(): string
    {
        return $this->generated_result_state_id;
    }

    protected function redirect_to_result_state(string $result_state_id): void
    {
        $this->redirect_result_state_id = $result_state_id;
    }
}

class Kiwi_Test_Hlr_Lookup_Shortcode extends Kiwi_Hlr_Lookup_Shortcode
{
    public $redirect_result_state_id;
    private $generated_result_state_id;
    private $generated_export_batch_id;

    public function __construct(
        Kiwi_Operator_Lookup_Batch_Service $batch_service,
        Kiwi_Dimoco_Callback_Operator_Lookup_Repository $callback_operator_lookup_repository,
        string $generated_result_state_id = 'kiwi_hlr_lookup_test_state',
        string $generated_export_batch_id = 'kiwi_hlr_export_test_batch',
        ?Kiwi_Frontend_Auth_Gate $frontend_auth_gate = null
    ) {
        parent::__construct($batch_service, $callback_operator_lookup_repository, $frontend_auth_gate);

        $this->generated_result_state_id = $generated_result_state_id;
        $this->generated_export_batch_id = $generated_export_batch_id;
    }

    public function store_result_state_for_test(array $state): string
    {
        return $this->store_result_state($state);
    }

    public function load_result_state_from_request_for_test(): ?array
    {
        return $this->load_result_state_from_request();
    }

    public function maybe_store_and_redirect_result_state_for_test(array $state): bool
    {
        return $this->maybe_store_and_redirect_result_state($state);
    }

    protected function can_redirect_after_submission(): bool
    {
        return true;
    }

    protected function generate_result_state_id(): string
    {
        return $this->generated_result_state_id;
    }

    protected function generate_export_batch_id(): string
    {
        return $this->generated_export_batch_id;
    }

    protected function redirect_to_result_state(string $result_state_id): void
    {
        $this->redirect_result_state_id = $result_state_id;
    }
}

class Kiwi_Test_Premium_Sms_Fraud_Signal_Repository extends Kiwi_Premium_Sms_Fraud_Signal_Repository
{
    public $rows = [];
    private $next_id = 1;

    public function create_table(): void
    {
    }

    public function insert_if_new(array $data): array
    {
        $source_event_key = trim((string) ($data['source_event_key'] ?? ''));
        $identity_type = trim((string) ($data['identity_type'] ?? ''));

        if ($source_event_key === '' || $identity_type === '') {
            return [
                'inserted' => false,
                'row' => null,
            ];
        }

        foreach ($this->rows as $row) {
            if (($row['source_event_key'] ?? '') !== $source_event_key) {
                continue;
            }

            if (($row['identity_type'] ?? '') !== $identity_type) {
                continue;
            }

            return [
                'inserted' => false,
                'row' => $row,
            ];
        }

        $id = $this->next_id++;
        $row = [
            'id' => $id,
            'created_at' => (string) ($data['created_at'] ?? '2026-04-01 12:00:00'),
            'provider_key' => (string) ($data['provider_key'] ?? ''),
            'service_key' => (string) ($data['service_key'] ?? ''),
            'flow_key' => (string) ($data['flow_key'] ?? ''),
            'pid' => (string) ($data['pid'] ?? ''),
            'click_id' => (string) ($data['click_id'] ?? ''),
            'country' => (string) ($data['country'] ?? ''),
            'source_event_key' => $source_event_key,
            'identity_type' => $identity_type,
            'identity_value' => (string) ($data['identity_value'] ?? ''),
            'occurred_at' => (string) ($data['occurred_at'] ?? '2026-04-01 12:00:00'),
            'count_1h' => (int) ($data['count_1h'] ?? 0),
            'count_24h' => (int) ($data['count_24h'] ?? 0),
            'count_total' => (int) ($data['count_total'] ?? 0),
            'is_soft_flag' => !empty($data['is_soft_flag']) ? 1 : 0,
            'soft_flag_reason' => (string) ($data['soft_flag_reason'] ?? ''),
            'meta_json' => isset($data['meta_json']) ? json_encode($data['meta_json']) : '',
        ];

        $this->rows[] = $row;

        return [
            'inserted' => true,
            'row' => $row,
        ];
    }

    public function build_counts_snapshot(
        string $service_key,
        string $identity_type,
        string $identity_value,
        string $occurred_at
    ): array {
        $service_key = trim($service_key);
        $identity_type = trim($identity_type);
        $identity_value = trim($identity_value);

        if ($service_key === '' || $identity_type === '' || $identity_value === '') {
            return [
                'count_1h' => 0,
                'count_24h' => 0,
                'count_total' => 0,
            ];
        }

        $now = strtotime($occurred_at);

        if ($now === false) {
            $now = strtotime('2026-04-01 12:00:00');
        }

        $count_total = 0;
        $count_24h = 0;
        $count_1h = 0;

        foreach ($this->rows as $row) {
            if (($row['service_key'] ?? '') !== $service_key) {
                continue;
            }

            if (($row['identity_type'] ?? '') !== $identity_type) {
                continue;
            }

            if (($row['identity_value'] ?? '') !== $identity_value) {
                continue;
            }

            $row_time = strtotime((string) ($row['occurred_at'] ?? ''));

            if ($row_time === false || $row_time > $now) {
                continue;
            }

            $count_total++;

            if ($row_time >= ($now - 24 * 3600)) {
                $count_24h++;
            }

            if ($row_time >= ($now - 3600)) {
                $count_1h++;
            }
        }

        return [
            'count_1h' => $count_1h + 1,
            'count_24h' => $count_24h + 1,
            'count_total' => $count_total + 1,
        ];
    }

    public function get_recent(array $filters = [], int $limit = 100): array
    {
        $rows = $this->rows;

        if (trim((string) ($filters['service_key'] ?? '')) !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
                return (string) ($row['service_key'] ?? '') === (string) $filters['service_key'];
            }));
        }

        if (trim((string) ($filters['provider_key'] ?? '')) !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
                return (string) ($row['provider_key'] ?? '') === (string) $filters['provider_key'];
            }));
        }

        if (trim((string) ($filters['flow_key'] ?? '')) !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
                return (string) ($row['flow_key'] ?? '') === (string) $filters['flow_key'];
            }));
        }

        if (trim((string) ($filters['pid'] ?? '')) !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
                return (string) ($row['pid'] ?? '') === (string) $filters['pid'];
            }));
        }

        if (trim((string) ($filters['click_id'] ?? '')) !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
                return (string) ($row['click_id'] ?? '') === (string) $filters['click_id'];
            }));
        }

        if (trim((string) ($filters['identity_type'] ?? '')) !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
                return (string) ($row['identity_type'] ?? '') === (string) $filters['identity_type'];
            }));
        }

        if (!empty($filters['flagged_only'])) {
            $rows = array_values(array_filter($rows, static function (array $row): bool {
                return !empty($row['is_soft_flag']);
            }));
        }

        usort($rows, static function (array $left, array $right): int {
            $left_ts = strtotime((string) ($left['occurred_at'] ?? '')) ?: 0;
            $right_ts = strtotime((string) ($right['occurred_at'] ?? '')) ?: 0;

            if ($left_ts === $right_ts) {
                return (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
            }

            return $right_ts <=> $left_ts;
        });

        return array_slice($rows, 0, max(1, min(500, $limit)));
    }
}

class Kiwi_Test_Premium_Sms_Fraud_Monitor_Service extends Kiwi_Premium_Sms_Fraud_Monitor_Service
{
    public $calls = [];
    private $result;

    public function __construct(array $result = [])
    {
        $this->result = $result !== []
            ? $result
            : [
                'signals' => [],
                'has_soft_flag' => false,
                'soft_flagged_identity_types' => [],
                'engagement' => [],
                'engagement_soft_flag_reasons' => [],
                'should_block' => false,
            ];
    }

    public function capture_inbound_mo(array $signal_context): array
    {
        $this->calls[] = $signal_context;

        return $this->result;
    }
}

class Kiwi_Test_Nth_Client extends Kiwi_Nth_Client
{
    public $calls = [];
    private $responses;

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function submit_message(string $service_key, array $transaction): array
    {
        $this->calls[] = [
            'service_key' => $service_key,
            'transaction' => $transaction,
        ];

        $response = array_shift($this->responses);

        if ($response === null) {
            return [
                'success' => false,
                'status_code' => 500,
                'error' => 'No fake response configured.',
                'request' => [],
                'body' => '',
            ];
        }

        return $response;
    }
}

class Kiwi_Test_Nth_Event_Repository extends Kiwi_Nth_Event_Repository
{
    public $rows = [];
    private $next_id = 1;

    public function insert_if_new(array $event): array
    {
        foreach ($this->rows as $row) {
            if (($row['dedupe_key'] ?? '') === ($event['dedupe_key'] ?? '')) {
                return [
                    'inserted' => false,
                    'row' => $row,
                ];
            }
        }

        $row = array_merge($event, ['id' => $this->next_id++]);
        $this->rows[] = $row;

        return [
            'inserted' => true,
            'row' => $row,
        ];
    }
}

class Kiwi_Test_Nth_Flow_Transaction_Repository extends Kiwi_Nth_Flow_Transaction_Repository
{
    public $rows = [];
    private $next_id = 1;

    public function create(array $data): int
    {
        $id = $this->next_id++;
        $this->rows[$id] = array_merge($data, ['id' => $id]);

        return $id;
    }

    public function update(int $id, array $data): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }

        $this->rows[$id] = array_merge($this->rows[$id], $data);

        return true;
    }

    public function get_by_id(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function find_active_by_subscriber_context(
        string $service_key,
        string $subscriber_reference,
        string $shortcode,
        string $keyword,
        int $hours
    ): ?array {
        $rows = array_reverse($this->rows, true);

        foreach ($rows as $row) {
            if (($row['service_key'] ?? '') !== $service_key) {
                continue;
            }

            if (($row['subscriber_reference'] ?? '') !== $subscriber_reference) {
                continue;
            }

            if (($row['shortcode'] ?? '') !== $shortcode) {
                continue;
            }

            if (($row['keyword'] ?? '') !== $keyword) {
                continue;
            }

            return $row;
        }

        return null;
    }

    public function find_recent_by_external_references(string $service_key, array $references): ?array
    {
        $references = array_values($references);
        $rows = array_reverse($this->rows, true);

        foreach ($rows as $row) {
            if (($row['service_key'] ?? '') !== $service_key) {
                continue;
            }

            if (in_array($row['external_message_id'] ?? '', $references, true)) {
                return $row;
            }

            if (in_array($row['external_request_id'] ?? '', $references, true)) {
                return $row;
            }
        }

        return null;
    }
}

class Kiwi_Test_Click_Attribution_Repository extends Kiwi_Click_Attribution_Repository
{
    public $rows = [];
    public $cleanup_calls = [];
    private $next_id = 1;
    private $next_transaction_sequence = 1;

    public function create_table(): void
    {
    }

    public function upsert_capture(array $data): array
    {
        $tracking_token = (string) ($data['tracking_token'] ?? '');
        foreach ($this->rows as $id => $row) {
            if (($row['tracking_token'] ?? '') !== $tracking_token) {
                continue;
            }

            $transaction_id = $this->resolve_transaction_id(
                (string) ($data['transaction_id'] ?? ''),
                (string) ($row['transaction_id'] ?? '')
            );
            $incoming_pid = trim((string) ($data['pid'] ?? ''));
            if ($incoming_pid === '' && array_key_exists('pid', $row)) {
                unset($data['pid']);
            }
            $this->rows[$id] = array_merge($row, $data, [
                'id' => $id,
                'transaction_id' => $transaction_id,
            ]);

            return $this->rows[$id];
        }

        $id = $this->next_id++;
        $transaction_id = $this->resolve_transaction_id((string) ($data['transaction_id'] ?? ''));
        $row = array_merge(
            [
                'id' => $id,
                'transaction_id' => $transaction_id,
                'conversion_status' => 'captured',
                'postback_sent_at' => '',
                'postback_attempts' => 0,
                'postback_response_code' => 0,
                'postback_response_body' => '',
                'postback_last_error' => '',
                'conversion_confirmed_at' => '',
            ],
            $data
        );
        $this->rows[$id] = $row;

        return $row;
    }

    public function find_by_tracking_token(string $tracking_token): ?array
    {
        foreach ($this->rows as $row) {
            if (($row['tracking_token'] ?? '') === $tracking_token) {
                return $row;
            }
        }

        return null;
    }

    public function find_by_transaction_id(string $transaction_id): ?array
    {
        foreach ($this->rows as $row) {
            if (($row['transaction_id'] ?? '') === $transaction_id) {
                return $row;
            }
        }

        return null;
    }

    public function get_by_id(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function find_unique_pending_by_service_reference(string $service_key, string $reference): ?array
    {
        $matches = [];

        foreach ($this->rows as $row) {
            if (($row['service_key'] ?? '') !== $service_key) {
                continue;
            }

            if (($row['transaction_ref'] ?? '') !== '') {
                continue;
            }

            if (($row['session_ref'] ?? '') !== $reference && ($row['external_ref'] ?? '') !== $reference) {
                continue;
            }

            $matches[] = $row;
        }

        if (count($matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    public function bind_references(int $id, array $references): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }

        $row = $this->rows[$id];
        $transaction_id = $this->resolve_transaction_id(
            (string) ($references['transaction_id'] ?? ''),
            (string) ($row['transaction_id'] ?? '')
        );
        $this->rows[$id] = array_merge($row, $references);
        $this->rows[$id]['transaction_id'] = $transaction_id;

        if (($this->rows[$id]['conversion_status'] ?? '') === 'captured') {
            $this->rows[$id]['conversion_status'] = 'bound';
        }

        return true;
    }

    public function find_for_conversion(array $references): ?array
    {
        $order = ['transaction_id', 'sale_reference', 'transaction_ref', 'message_ref', 'external_ref', 'session_ref'];

        foreach ($order as $field) {
            $value = (string) ($references[$field] ?? '');
            if ($value === '') {
                continue;
            }

            foreach (array_reverse($this->rows, true) as $row) {
                if ((string) ($row[$field] ?? '') === $value) {
                    return $row;
                }
            }
        }

        return null;
    }

    public function mark_conversion_confirmed(int $id, string $occurred_at): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }

        $this->rows[$id]['conversion_status'] = 'confirmed';
        $this->rows[$id]['conversion_confirmed_at'] = $occurred_at !== '' ? $occurred_at : '2026-04-01 12:00:00';

        return true;
    }

    public function record_postback_attempt(int $id, array $result): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }

        $this->rows[$id]['postback_attempts'] = (int) ($this->rows[$id]['postback_attempts'] ?? 0) + 1;
        $this->rows[$id]['postback_response_code'] = (int) ($result['response_code'] ?? 0);
        $this->rows[$id]['postback_response_body'] = (string) ($result['response_body'] ?? '');
        $this->rows[$id]['postback_last_error'] = (string) ($result['error'] ?? '');

        if (!empty($result['success']) && (string) ($this->rows[$id]['postback_sent_at'] ?? '') === '') {
            $this->rows[$id]['postback_sent_at'] = '2026-04-01 12:00:00';
            $this->rows[$id]['conversion_status'] = 'postback_sent';
        } elseif (empty($result['success'])) {
            $this->rows[$id]['conversion_status'] = 'postback_failed';
        }

        return true;
    }

    public function cleanup_expired(int $limit = 500): int
    {
        $this->cleanup_calls[] = $limit;

        $expired = [];
        $limit = max(1, $limit);

        foreach ($this->rows as $id => $row) {
            if (count($expired) >= $limit) {
                break;
            }

            if (($row['expires_at'] ?? '') <= '2026-04-01 12:00:00') {
                $expired[] = $id;
            }
        }

        foreach ($expired as $id) {
            unset($this->rows[$id]);
        }

        return count($expired);
    }

    private function resolve_transaction_id(string $incoming, string $fallback = ''): string
    {
        $incoming = trim($incoming);

        if ($incoming !== '') {
            return $incoming;
        }

        $fallback = trim($fallback);

        if ($fallback !== '') {
            return $fallback;
        }

        $next = str_pad((string) $this->next_transaction_sequence++, 6, '0', STR_PAD_LEFT);

        return 'txn_test_' . $next;
    }
}

class Kiwi_Test_Premium_Sms_Landing_Engagement_Repository extends Kiwi_Premium_Sms_Landing_Engagement_Repository
{
    public $rows = [];
    private $next_id = 1;

    public function create_table(): void
    {
    }

    public function upsert_event(array $context, string $event_type, string $occurred_at = ''): array
    {
        $landing_key = trim((string) ($context['landing_key'] ?? ''));
        $session_token = trim((string) ($context['session_token'] ?? ''));
        $event_type = strtolower(trim($event_type));
        $occurred_at = trim($occurred_at) !== '' ? trim($occurred_at) : '2026-04-01 12:00:00';

        if ($landing_key === '' || $session_token === '') {
            return [];
        }

        if (!in_array($event_type, ['page_loaded', 'cta_click'], true)) {
            return [];
        }

        $id = null;

        foreach ($this->rows as $row_id => $row) {
            if (($row['landing_key'] ?? '') !== $landing_key) {
                continue;
            }

            if (($row['session_token'] ?? '') !== $session_token) {
                continue;
            }

            $id = $row_id;
            break;
        }

        if ($id === null) {
            $id = $this->next_id++;
            $this->rows[$id] = [
                'id' => $id,
                'created_at' => '2026-04-01 12:00:00',
                'updated_at' => '2026-04-01 12:00:00',
                'provider_key' => (string) ($context['provider_key'] ?? ''),
                'service_key' => (string) ($context['service_key'] ?? ''),
                'flow_key' => (string) ($context['flow_key'] ?? ''),
                'pid' => (string) ($context['pid'] ?? ''),
                'click_id' => (string) ($context['click_id'] ?? ''),
                'landing_key' => $landing_key,
                'session_token' => $session_token,
                'page_loaded_at' => '',
                'first_cta_click_at' => '',
                'last_cta_click_at' => '',
                'cta_click_count' => 0,
                'last_event_at' => $occurred_at,
            ];
        }

        $row = $this->rows[$id];
        $row['updated_at'] = '2026-04-01 12:00:00';
        $row['provider_key'] = (string) ($row['provider_key'] !== '' ? $row['provider_key'] : (string) ($context['provider_key'] ?? ''));
        $row['service_key'] = (string) ($row['service_key'] !== '' ? $row['service_key'] : (string) ($context['service_key'] ?? ''));
        $row['flow_key'] = (string) ($row['flow_key'] !== '' ? $row['flow_key'] : (string) ($context['flow_key'] ?? ''));
        $row['pid'] = (string) ($row['pid'] !== '' ? $row['pid'] : (string) ($context['pid'] ?? ''));
        $row['click_id'] = (string) ($row['click_id'] !== '' ? $row['click_id'] : (string) ($context['click_id'] ?? ''));
        $row['last_event_at'] = $occurred_at;

        if ($event_type === 'page_loaded') {
            if ((string) ($row['page_loaded_at'] ?? '') === '') {
                $row['page_loaded_at'] = $occurred_at;
            }
        } elseif ($event_type === 'cta_click') {
            if ((string) ($row['first_cta_click_at'] ?? '') === '') {
                $row['first_cta_click_at'] = $occurred_at;
            }

            $row['last_cta_click_at'] = $occurred_at;
            $row['cta_click_count'] = max(0, (int) ($row['cta_click_count'] ?? 0)) + 1;
        }

        $this->rows[$id] = $row;

        return $row;
    }

    public function get_by_landing_session(string $landing_key, string $session_token): ?array
    {
        foreach ($this->rows as $row) {
            if (($row['landing_key'] ?? '') !== $landing_key) {
                continue;
            }

            if (($row['session_token'] ?? '') !== $session_token) {
                continue;
            }

            return $row;
        }

        return null;
    }

    public function get_recent(array $filters = [], int $limit = 100): array
    {
        $rows = array_values($this->rows);
        $service_key = trim((string) ($filters['service_key'] ?? ''));
        $provider_key = trim((string) ($filters['provider_key'] ?? ''));
        $pid = trim((string) ($filters['pid'] ?? ''));
        $click_id = trim((string) ($filters['click_id'] ?? ''));

        if ($service_key !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($service_key): bool {
                return (string) ($row['service_key'] ?? '') === $service_key;
            }));
        }

        if ($provider_key !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($provider_key): bool {
                return (string) ($row['provider_key'] ?? '') === $provider_key;
            }));
        }

        if ($pid !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($pid): bool {
                return (string) ($row['pid'] ?? '') === $pid;
            }));
        }

        if ($click_id !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($click_id): bool {
                return (string) ($row['click_id'] ?? '') === $click_id;
            }));
        }

        usort($rows, static function (array $left, array $right): int {
            $left_ts = strtotime((string) ($left['updated_at'] ?? '')) ?: 0;
            $right_ts = strtotime((string) ($right['updated_at'] ?? '')) ?: 0;

            if ($left_ts === $right_ts) {
                return (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
            }

            return $right_ts <=> $left_ts;
        });

        return array_slice($rows, 0, max(1, min(500, $limit)));
    }
}

class Kiwi_Test_Tracking_Capture_Service extends Kiwi_Tracking_Capture_Service
{
    public $cookies = [];

    protected function set_tracking_cookie(string $tracking_token): void
    {
        $this->cookies[] = $tracking_token;
    }
}

class Kiwi_Test_Landing_Kpi_Summary_Repository extends Kiwi_Landing_Kpi_Summary_Repository
{
    public $rows = [];

    public function create_table(): void
    {
    }

    public function increment_counter(string $landing_key, string $counter, array $context = []): bool
    {
        $landing_key = trim($landing_key);
        $counter = strtolower(trim($counter));

        if ($landing_key === '' || !in_array($counter, ['clicks', 'cta1', 'cta2', 'cta3', 'conv'], true)) {
            return false;
        }

        if (!isset($this->rows[$landing_key])) {
            $this->rows[$landing_key] = [
                'landing_key' => $landing_key,
                'service_key' => (string) ($context['service_key'] ?? ''),
                'provider_key' => (string) ($context['provider_key'] ?? ''),
                'flow_key' => (string) ($context['flow_key'] ?? ''),
                'clicks' => 0,
                'cta1' => 0,
                'cta1_cr' => 0.0,
                'cta2' => 0,
                'cta2_cr' => 0.0,
                'cta3' => 0,
                'cta3_cr' => 0.0,
                'conv' => 0,
                'conv_cr' => 0.0,
            ];
        }

        $this->rows[$landing_key][$counter] = (int) ($this->rows[$landing_key][$counter] ?? 0) + 1;
        $clicks = (int) ($this->rows[$landing_key]['clicks'] ?? 0);
        $this->rows[$landing_key]['cta1_cr'] = $clicks > 0
            ? round(((int) ($this->rows[$landing_key]['cta1'] ?? 0) / $clicks) * 100, 2)
            : 0.0;
        $this->rows[$landing_key]['cta2_cr'] = $clicks > 0
            ? round(((int) ($this->rows[$landing_key]['cta2'] ?? 0) / $clicks) * 100, 2)
            : 0.0;
        $this->rows[$landing_key]['cta3_cr'] = $clicks > 0
            ? round(((int) ($this->rows[$landing_key]['cta3'] ?? 0) / $clicks) * 100, 2)
            : 0.0;
        $this->rows[$landing_key]['conv_cr'] = $clicks > 0
            ? round(((int) ($this->rows[$landing_key]['conv'] ?? 0) / $clicks) * 100, 2)
            : 0.0;

        return true;
    }

    public function get_rows(array $landing_keys = []): array
    {
        if (empty($landing_keys)) {
            $rows = array_values($this->rows);
            usort($rows, static function (array $left, array $right): int {
                return strcmp((string) ($left['landing_key'] ?? ''), (string) ($right['landing_key'] ?? ''));
            });

            return $rows;
        }

        $allowed = array_flip(array_values(array_filter(array_map('strval', $landing_keys))));
        $rows = [];

        foreach ($this->rows as $landing_key => $row) {
            if (!isset($allowed[$landing_key])) {
                continue;
            }

            $rows[] = $row;
        }

        usort($rows, static function (array $left, array $right): int {
            return strcmp((string) ($left['landing_key'] ?? ''), (string) ($right['landing_key'] ?? ''));
        });

        return $rows;
    }
}

class Kiwi_Test_Affiliate_Postback_Dispatcher extends Kiwi_Affiliate_Postback_Dispatcher
{
    public $calls = [];
    public $responses = [];

    protected function send_request(string $url): array
    {
        $this->calls[] = $url;

        if (!empty($this->responses)) {
            return array_shift($this->responses);
        }

        return [
            'status_code' => 200,
            'body' => 'OK',
            'error' => '',
        ];
    }
}

class Kiwi_Test_Sales_Repository extends Kiwi_Sales_Repository
{
    public $upsert_calls = [];
    public $pid_updates = [];
    public $rows = [];
    private $next_id = 1;

    public function create_table(): void
    {
    }

    public function upsert(array $data): array
    {
        $this->upsert_calls[] = $data;
        $row = array_merge(['id' => $this->next_id++], $data);
        $this->rows[] = $row;

        return $row;
    }

    public function find_by_sale_reference(string $sale_reference): ?array
    {
        $sale_reference = trim($sale_reference);

        if ($sale_reference === '') {
            return null;
        }

        foreach ($this->rows as $row) {
            if ((string) ($row['sale_reference'] ?? '') === $sale_reference) {
                return $row;
            }
        }

        return null;
    }

    public function update_pid_by_sale_reference(string $sale_reference, string $pid): bool
    {
        $sale_reference = trim($sale_reference);
        $pid = trim($pid);

        if ($sale_reference === '' || $pid === '') {
            return false;
        }

        foreach ($this->rows as $index => $row) {
            if ((string) ($row['sale_reference'] ?? '') !== $sale_reference) {
                continue;
            }

            $this->rows[$index]['pid'] = $pid;
            $this->pid_updates[] = [
                'sale_reference' => $sale_reference,
                'pid' => $pid,
            ];

            return true;
        }

        return false;
    }
}

class Kiwi_Test_Failing_Sales_Repository extends Kiwi_Sales_Repository
{
    public $upsert_calls = [];
    public $rows = [];

    public function create_table(): void
    {
    }

    public function upsert(array $data): array
    {
        $this->upsert_calls[] = $data;

        return [];
    }

    public function find_by_sale_reference(string $sale_reference): ?array
    {
        return null;
    }

    public function update_pid_by_sale_reference(string $sale_reference, string $pid): bool
    {
        return false;
    }
}

class Kiwi_Test_Shared_Sales_Recorder extends Kiwi_Shared_Sales_Recorder
{
    public $calls = [];
    private $next_id = 1;

    public function __construct()
    {
    }

    public function record_successful_one_off_sale(array $transaction, array $report_event): array
    {
        $sale = [
            'id' => $this->next_id++,
            'sale_reference' => $transaction['sale_reference'] ?? $transaction['flow_reference'] ?? '',
            'provider_key' => 'nth',
            'status' => 'completed',
        ];

        $this->calls[] = [
            'transaction' => $transaction,
            'report_event' => $report_event,
            'sale' => $sale,
        ];

        return $sale;
    }
}

kiwi_run_test('Kiwi_Config exposes NTH service and landing page configuration', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'shortcode' => '84072',
            ],
        ],
        [
            'lp2-fr' => [
                'backend_path' => '/lp/fr/myjoyplay',
            ],
        ],
        240
    );

    kiwi_assert_same('FR', $config->get_nth_service('nth_fr_one_off_jplay')['country'], 'Expected NTH service config to be returned by key.');
    kiwi_assert_same('/lp/fr/myjoyplay', $config->get_landing_page('lp2-fr')['backend_path'], 'Expected landing page config to be returned by key.');
    kiwi_assert_same(240, $config->get_nth_submit_timeout(), 'Expected the configured NTH timeout to be returned.');
});

kiwi_run_test('Kiwi_Landing_Page_Registry discovers folder landing pages and parses metadata', function (): void {
    $project_root = kiwi_create_temp_directory('kiwi_lp_registry');

    try {
        kiwi_write_landing_page_fixture($project_root, 'lp2-fr');

        $registry = new Kiwi_Landing_Page_Registry(
            $project_root . DIRECTORY_SEPARATOR . 'landing-pages',
            $project_root
        );
        $landing_pages = $registry->get_registry();
        $errors = $registry->get_errors();

        kiwi_assert_same([], $errors, 'Expected valid landing page folders to load without validation errors.');
        kiwi_assert_true(isset($landing_pages['lp2-fr']), 'Expected lp2-fr to be discovered from the filesystem.');
        kiwi_assert_same('nth-fr-one-off', $landing_pages['lp2-fr']['flow'] ?? '', 'Expected landing page flow metadata to be parsed from integration.php.');
        kiwi_assert_same('/integrations/nth/fr/one-off/README.md', $landing_pages['lp2-fr']['documentation'] ?? '', 'Expected documentation path to normalize to /integrations/.');
        kiwi_assert_true(is_file((string) ($landing_pages['lp2-fr']['documentation_resolved_path'] ?? '')), 'Expected documentation link to resolve to an existing docs file.');
        kiwi_assert_same('filesystem', $landing_pages['lp2-fr']['render_mode'] ?? '', 'Expected discovered entries to be marked as filesystem-rendered.');
    } finally {
        kiwi_remove_directory($project_root);
    }
});

kiwi_run_test('Kiwi_Landing_Page_Registry enforces required files and reports actionable errors', function (): void {
    $project_root = kiwi_create_temp_directory('kiwi_lp_missing');

    try {
        kiwi_write_landing_page_fixture($project_root, 'lp2-fr', [], false);

        $registry = new Kiwi_Landing_Page_Registry(
            $project_root . DIRECTORY_SEPARATOR . 'landing-pages',
            $project_root
        );
        $errors = $registry->get_errors();

        kiwi_assert_true(!empty($errors), 'Expected invalid landing page folders to produce validation errors.');
        kiwi_assert_contains(
            'Landing page "lp2-fr" is missing styles.css.',
            implode("\n", array_map('strval', $errors)),
            'Expected validation errors to clearly name the missing required file.'
        );
    } finally {
        kiwi_remove_directory($project_root);
    }
});

kiwi_run_test('Kiwi_Config fails loudly in debug mode when filesystem landing pages are invalid', function (): void {
    $project_root = kiwi_create_temp_directory('kiwi_lp_debug');

    try {
        kiwi_write_landing_page_fixture($project_root, 'lp2-fr', [], false);

        $config = new Kiwi_Test_Runtime_Config(
            $project_root . DIRECTORY_SEPARATOR . 'landing-pages',
            [],
            true,
            false,
            true
        );

        $caught_message = '';

        try {
            $config->get_landing_pages();
        } catch (RuntimeException $exception) {
            $caught_message = $exception->getMessage();
        }

        kiwi_assert_contains(
            'Landing page "lp2-fr" is missing styles.css.',
            $caught_message,
            'Expected debug-mode loading to throw a clear validation error for invalid landing pages.'
        );
    } finally {
        kiwi_remove_directory($project_root);
    }
});

kiwi_run_test('Kiwi_Landing_Page_Registry validates documentation linkage safety and existence', function (): void {
    $project_root = kiwi_create_temp_directory('kiwi_lp_docs');

    try {
        kiwi_write_landing_page_fixture($project_root, 'lp2-fr', [
            'documentation' => '/integrations/../../secrets.md',
        ], true, false);
        kiwi_write_landing_page_fixture($project_root, 'lp3-fr', [
            'documentation' => '/integrations/nth/fr/one-off/missing.md',
        ], true, false);

        $registry = new Kiwi_Landing_Page_Registry(
            $project_root . DIRECTORY_SEPARATOR . 'landing-pages',
            $project_root
        );
        $errors = implode("\n", array_map('strval', $registry->get_errors()));

        kiwi_assert_contains(
            'relative traversal is not allowed',
            strtolower($errors),
            'Expected documentation path validation to block traversal attempts.'
        );
        kiwi_assert_contains(
            'documentation file was not found',
            strtolower($errors),
            'Expected documentation path validation to fail when linked docs are missing.'
        );
    } finally {
        kiwi_remove_directory($project_root);
    }
});

kiwi_run_test('Kiwi_Config keeps filesystem landing pages primary and legacy fallback for unmigrated keys only', function (): void {
    $project_root = kiwi_create_temp_directory('kiwi_lp_fallback');

    try {
        kiwi_write_landing_page_fixture($project_root, 'lp2-fr', [
            'flow' => 'filesystem-flow',
        ]);

        $config = new Kiwi_Test_Runtime_Config(
            $project_root . DIRECTORY_SEPARATOR . 'landing-pages',
            [
                'lp2-fr' => [
                    'flow' => 'legacy-flow',
                    'backend_path' => '/legacy/lp2-fr',
                ],
                'legacy-only-fr' => [
                    'flow' => 'legacy-only-flow',
                    'backend_path' => '/legacy/only',
                ],
            ],
            true,
            true,
            false
        );

        $landing_pages = $config->get_landing_pages();

        kiwi_assert_same(
            'filesystem-flow',
            $landing_pages['lp2-fr']['flow'] ?? '',
            'Expected filesystem landing pages to override same-key legacy entries.'
        );
        kiwi_assert_same(
            'legacy-only-flow',
            $landing_pages['legacy-only-fr']['flow'] ?? '',
            'Expected legacy fallback to remain available for unmigrated landing pages.'
        );
    } finally {
        kiwi_remove_directory($project_root);
    }
});

kiwi_run_test('Kiwi_Landing_Page_Gallery_Service normalizes metadata and dedicated-host URLs', function (): void {
    $project_root = kiwi_create_temp_directory('kiwi_lp_gallery_meta');
    $original_server = $_SERVER;

    try {
        $_SERVER['HTTP_HOST'] = 'backend.example.test';
        $_SERVER['HTTPS'] = 'on';

        kiwi_write_landing_page_fixture($project_root, 'lp2-fr', [
            'hostnames' => ['FRLP2.JOY-PLAY.COM', 'frlp2.joy-play.com'],
            'dedicated_path' => 'offer/fr',
            'backend_path' => '/lp/fr/myjoyplay',
            'service_key' => 'nth_fr_one_off_jplay',
            'asset_base_url' => 'https://assets.example.test/landing-pages/',
        ]);

        $config = new Kiwi_Test_Runtime_Config(
            $project_root . DIRECTORY_SEPARATOR . 'landing-pages',
            [],
            true,
            false,
            false
        );
        $service = new Kiwi_Landing_Page_Gallery_Service($config);
        $gallery_data = $service->build_gallery_data();
        $entry = $gallery_data['entries'][0] ?? [];

        kiwi_assert_same(1, $gallery_data['count'] ?? 0, 'Expected one filesystem landing page in the gallery.');
        kiwi_assert_same('lp2-fr', $entry['key'] ?? '', 'Expected the landing key to be normalized from integration metadata.');
        kiwi_assert_same('FR', $entry['country'] ?? '', 'Expected the country code to remain uppercase.');
        kiwi_assert_same('nth-fr-one-off', $entry['flow'] ?? '', 'Expected flow metadata to be carried into gallery output.');
        kiwi_assert_same('nth_fr_one_off_jplay', $entry['service_key'] ?? '', 'Expected service_key metadata to be carried into gallery output.');
        kiwi_assert_same('https://assets.example.test/landing-pages/', $entry['asset_base_url'] ?? '', 'Expected asset_base_url metadata to be carried into gallery output.');
        kiwi_assert_same('hybrid', $entry['routing_mode'] ?? '', 'Expected hostnames plus backend_path to be marked as hybrid routing.');
        kiwi_assert_same(
            'https://frlp2.joy-play.com/lp/fr/myjoyplay',
            $entry['primary_url']['url'] ?? '',
            'Expected hostname-based gallery URLs to align with outside backend-path routing.'
        );
        kiwi_assert_same(
            'https://frlp2.joy-play.com/lp/fr/myjoyplay',
            $entry['preview_url'] ?? '',
            'Expected preview URL to use the same outside hostname + backend_path route.'
        );
        kiwi_assert_true(
            is_file((string) ($entry['index_path'] ?? '')),
            'Expected gallery entries to expose the filesystem index_path for local preview rendering.'
        );
        kiwi_assert_true(
            is_file((string) ($entry['styles_path'] ?? '')),
            'Expected gallery entries to expose the filesystem styles_path for local preview rendering.'
        );
    } finally {
        $_SERVER = $original_server;
        kiwi_remove_directory($project_root);
    }
});

kiwi_run_test('Kiwi_Landing_Page_Gallery_Service derives inferred URL for backend-path-only pages', function (): void {
    $project_root = kiwi_create_temp_directory('kiwi_lp_gallery_path');
    $original_server = $_SERVER;

    try {
        $_SERVER['HTTP_HOST'] = 'backend.example.test';
        $_SERVER['HTTPS'] = 'on';

        kiwi_write_landing_page_fixture($project_root, 'lp2-fr', [
            'hostnames' => [],
            'backend_path' => 'lp/fr/path-only',
            'dedicated_path' => '/',
        ]);

        $config = new Kiwi_Test_Runtime_Config(
            $project_root . DIRECTORY_SEPARATOR . 'landing-pages',
            [],
            true,
            false,
            false
        );
        $service = new Kiwi_Landing_Page_Gallery_Service($config);
        $gallery_data = $service->build_gallery_data();
        $entry = $gallery_data['entries'][0] ?? [];
        $urls = $entry['public_urls'] ?? [];

        kiwi_assert_same('/lp/fr/path-only', $entry['backend_path'] ?? '', 'Expected backend_path normalization to force a leading slash.');
        kiwi_assert_same('/lp/fr/path-only', $urls[0]['url'] ?? '', 'Expected the first URL candidate to keep the path-only routing strategy.');
        kiwi_assert_same(
            'https://backend.example.test/lp/fr/path-only',
            $urls[1]['url'] ?? '',
            'Expected the gallery to derive an inferred absolute URL from the current host for backend_path-only pages.'
        );
        kiwi_assert_same(true, $urls[1]['inferred'] ?? false, 'Expected inferred absolute backend URLs to be explicitly marked.');
    } finally {
        $_SERVER = $original_server;
        kiwi_remove_directory($project_root);
    }
});

kiwi_run_test('Kiwi_Landing_Page_Gallery_Service keeps valid entries when discovery reports broken folders', function (): void {
    $project_root = kiwi_create_temp_directory('kiwi_lp_gallery_errors');

    try {
        kiwi_write_landing_page_fixture($project_root, 'lp2-fr');
        kiwi_write_landing_page_fixture($project_root, 'lp3-fr', [], false);

        $config = new Kiwi_Test_Runtime_Config(
            $project_root . DIRECTORY_SEPARATOR . 'landing-pages',
            [],
            true,
            false,
            false
        );
        $service = new Kiwi_Landing_Page_Gallery_Service($config);
        $gallery_data = $service->build_gallery_data();

        kiwi_assert_same(1, $gallery_data['count'] ?? 0, 'Expected invalid folders to be skipped while valid pages remain renderable.');
        kiwi_assert_true(!empty($gallery_data['errors']), 'Expected gallery data to surface filesystem discovery warnings.');
        kiwi_assert_contains(
            'Landing page "lp3-fr" is missing styles.css.',
            implode("\n", $gallery_data['errors']),
            'Expected gallery warnings to include missing-file diagnostics from discovery.'
        );
    } finally {
        kiwi_remove_directory($project_root);
    }
});

kiwi_run_test('Kiwi_Landing_Pages_Gallery_Shortcode renders preview cards and URL metadata', function (): void {
    $project_root = kiwi_create_temp_directory('kiwi_lp_gallery_shortcode');
    $original_server = $_SERVER;
    $GLOBALS['kiwi_test_shortcodes'] = [];

    try {
        $_SERVER['HTTP_HOST'] = 'backend.example.test';
        $_SERVER['HTTPS'] = 'on';

        kiwi_write_landing_page_fixture($project_root, 'lp2-fr', [
            'hostnames' => ['frlp2.joy-play.com'],
            'backend_path' => '/lp/fr/myjoyplay',
            'service_key' => 'nth_fr_one_off_jplay',
        ]);
        kiwi_write_file(
            $project_root . DIRECTORY_SEPARATOR . 'landing-pages' . DIRECTORY_SEPARATOR . 'lp2-fr' . DIRECTORY_SEPARATOR . 'index.html',
            "<!doctype html>\n<html><head><link rel=\"stylesheet\" href=\"./styles.css\"></head><body><img src=\"./FR-Joyplay_LandingPage_Overview_Collage.png\" alt=\"Joyplay\">LP</body></html>\n"
        );
        kiwi_write_file(
            $project_root . DIRECTORY_SEPARATOR . 'landing-pages' . DIRECTORY_SEPARATOR . 'lp2-fr' . DIRECTORY_SEPARATOR . 'styles.css',
            "body { font-family: Arial, sans-serif; }\n.hero { background-image: url('./preview-bg.png'); }\n"
        );

        $config = new Kiwi_Test_Runtime_Config(
            $project_root . DIRECTORY_SEPARATOR . 'landing-pages',
            [],
            true,
            false,
            false
        );
        $service = new Kiwi_Landing_Page_Gallery_Service($config);
        $shortcode = new Kiwi_Landing_Pages_Gallery_Shortcode($service);
        $shortcode->register();

        kiwi_assert_true(
            isset($GLOBALS['kiwi_test_shortcodes']['kiwi_landing_pages_gallery']),
            'Expected the landing pages gallery shortcode tag to be registered.'
        );

        $output = $shortcode->render();

        kiwi_assert_contains('kiwi-card-grid', $output, 'Expected the shortcode to render the responsive gallery grid.');
        kiwi_assert_contains('<iframe', $output, 'Expected the shortcode to render iframe-based landing-page previews.');
        kiwi_assert_contains('srcdoc="', $output, 'Expected shortcode previews to use local srcdoc rendering when filesystem HTML is available.');
        kiwi_assert_contains('lp2-fr', $output, 'Expected the shortcode to render the landing-page key.');
        kiwi_assert_contains('nth_fr_one_off_jplay', $output, 'Expected the shortcode to render service_key metadata.');
        kiwi_assert_contains(
            '<span class="kiwi-url-label">URL:</span> <a class="kiwi-preview-url" href="https://frlp2.joy-play.com/lp/fr/myjoyplay"',
            $output,
            'Expected primary URL display to prefer explicit outside hostname routes and use label "URL".'
        );
        kiwi_assert_contains(
            'class="kiwi-copy-button"',
            $output,
            'Expected the preview URL row to render a copy-to-clipboard button.'
        );
        kiwi_assert_contains(
            'data-copy-text="https://frlp2.joy-play.com/lp/fr/myjoyplay"',
            $output,
            'Expected the copy button to carry the resolved primary URL as copy payload.'
        );
        kiwi_assert_true(
            strpos($output, 'More URLs') === false,
            'Expected the URL block to show only one URL and not render expandable extra URLs.'
        );
        kiwi_assert_true(
            strpos($output, 'kiwi-lp-card__urls') === false,
            'Expected the separate bottom URL section to be removed and URL to be shown inside the preview area.'
        );
        kiwi_assert_contains('<!doctype html>', $output, 'Expected srcdoc previews to embed landing-page HTML content.');
        kiwi_assert_contains('body { font-family: Arial, sans-serif; }', $output, 'Expected local preview rendering to inline styles.css content in srcdoc.');
        kiwi_assert_contains(
            'src="https://backend.kiwimobile.de/wp-content/uploads/assets/FR-Joyplay_LandingPage_Overview_Collage.png"',
            $output,
            'Expected srcdoc previews to rewrite local assets through the default landing-page asset base URL.'
        );
        kiwi_assert_contains(
            "url('https://backend.kiwimobile.de/wp-content/uploads/assets/preview-bg.png')",
            $output,
            'Expected srcdoc previews to rewrite local CSS asset paths through the default landing-page asset base URL.'
        );
    } finally {
        $_SERVER = $original_server;
        kiwi_remove_directory($project_root);
    }
});

kiwi_run_test('Kiwi_Landing_Page_Variant_Agent fails loudly when README.md or agents.md is missing', function (): void {
    $project_root = kiwi_create_temp_directory('kiwi_lp_variant_missing_rules');

    try {
        kiwi_write_landing_page_fixture($project_root, 'lp2-fr', [
            'title' => 'LP Missing Rules',
        ]);
        kiwi_write_file(
            $project_root . DIRECTORY_SEPARATOR . 'README.md',
            "# Fixture README\n"
        );

        $agent = new Kiwi_Landing_Page_Variant_Agent($project_root);
        $caught_message = '';

        try {
            $agent->create_landing_page_variants_by_title('LP Missing Rules');
        } catch (RuntimeException $exception) {
            $caught_message = $exception->getMessage();
        }

        kiwi_assert_contains(
            'agents.md',
            strtolower($caught_message),
            'Expected variant generation to fail loudly when agents.md has not been read because it is missing.'
        );
    } finally {
        kiwi_remove_directory($project_root);
    }
});

kiwi_run_test('Kiwi_Landing_Page_Variant_Agent resolves page, extracts protected content, parses integration, and generates a minimal safe variant', function (): void {
    $project_root = kiwi_create_temp_directory('kiwi_lp_variant_extract');

    try {
        kiwi_write_variant_agent_fixture($project_root, 'lp2-fr', 'XYZ');
        kiwi_write_variant_agent_fixture($project_root, 'lp3-fr', 'Other Title');

        $agent = new Kiwi_Landing_Page_Variant_Agent($project_root);
        $result = $agent->create_landing_page_variants_by_title('XYZ', ['variant_count' => 1]);
        $variant = $result['variants'][0] ?? [];
        $variant_html = (string) ($variant['html'] ?? '');

        kiwi_assert_same(
            'read README.md',
            $result['workflow']['sequence'][0] ?? '',
            'Expected the variant workflow to begin by reading README.md.'
        );
        kiwi_assert_same(
            'read agents.md',
            $result['workflow']['sequence'][1] ?? '',
            'Expected the variant workflow to read agents.md immediately after README.md.'
        );
        kiwi_assert_same(
            'lp2-fr',
            $result['landing_page']['key'] ?? '',
            'Expected title lookup to resolve the landing-page folder mapped by integration.php title.'
        );
        kiwi_assert_contains(
            'Activer en envoyant JPLAY au 84072',
            (string) ($result['protected_content']['price_text'] ?? ''),
            'Expected .price text to be extracted from source HTML.'
        );
        kiwi_assert_contains(
            'Assistance: myjoyplay.fr@silverlines.info.',
            (string) ($result['protected_content']['disclaimer_text'] ?? ''),
            'Expected .disclaimer text to be extracted from source HTML.'
        );
        kiwi_assert_same(
            'nth-fr-one-off',
            $result['integration']['flow'] ?? '',
            'Expected integration.php metadata to be parsed and exposed.'
        );
        kiwi_assert_same(
            'nth',
            $result['integration']['provider'] ?? '',
            'Expected integration.php provider metadata to be preserved.'
        );
        kiwi_assert_same(
            'repository_fallback',
            $result['research']['mode'] ?? '',
            'Expected variant generation to work in degraded mode without external web research.'
        );
        kiwi_assert_true(
            count($result['variants'] ?? []) >= 1,
            'Expected at least one generated landing-page variant.'
        );
        kiwi_assert_same(
            'minimal',
            $variant['type'] ?? '',
            'Expected generated variants to support minimal variant output as a first-class type.'
        );
        kiwi_assert_contains(
            'data-kiwi-variant=',
            $variant_html,
            'Expected variant output HTML to contain the injected minimal variant style block.'
        );
        kiwi_assert_contains(
            '<p class="price">Activer en envoyant JPLAY au 84072<br>4,50 EUR / SMS + prix d\'un SMS</p>',
            $variant_html,
            'Expected generated variant HTML to preserve the protected .price block.'
        );
        kiwi_assert_contains(
            '<p class="disclaimer">Service a frais uniques. Assistance: myjoyplay.fr@silverlines.info.</p>',
            $variant_html,
            'Expected generated variant HTML to preserve the protected .disclaimer block.'
        );
        kiwi_assert_same(
            $result['integration']['flow'] ?? '',
            $variant['integration_guardrails']['flow'] ?? '',
            'Expected integration-derived flow constraints to be reused inside variant metadata guardrails.'
        );
        kiwi_assert_same(
            $result['integration']['provider'] ?? '',
            $variant['integration_guardrails']['provider'] ?? '',
            'Expected integration-derived provider constraints to be reused inside variant metadata guardrails.'
        );
    } finally {
        kiwi_remove_directory($project_root);
    }
});

kiwi_run_test('Kiwi_Landing_Page_Router resolves filesystem landing pages to the configured flow', function (): void {
    $project_root = kiwi_create_temp_directory('kiwi_lp_router');

    try {
        kiwi_write_landing_page_fixture($project_root, 'lp2-fr', [
            'flow' => 'nth-fr-one-off',
            'backend_path' => '/lp/fr/myjoyplay',
            'hostnames' => ['frlp1.joy-play.com'],
        ]);

        $config = new Kiwi_Test_Runtime_Config(
            $project_root . DIRECTORY_SEPARATOR . 'landing-pages',
            [],
            true,
            false,
            false,
            [
                'nth_fr_one_off_jplay' => [
                    'shortcode' => '84072',
                    'keyword' => 'JPLAY',
                    'landing_price_label' => '4,50 EUR / SMS',
                ],
            ]
        );

        $router = new Kiwi_Landing_Page_Router(
            $config,
            new Kiwi_Landing_Page_Session_Repository(),
            'https://example.test/plugin/'
        );

        $match = $router->resolve_request('backend.kiwimobile.de', '/lp/fr/myjoyplay');

        kiwi_assert_same('lp2-fr', $match['landing_key'] ?? '', 'Expected routing to resolve the discovered filesystem landing page key.');
        kiwi_assert_same('nth-fr-one-off', $match['landing_page']['flow'] ?? '', 'Expected routing to carry the flow linked in integration.php.');
    } finally {
        kiwi_remove_directory($project_root);
    }
});

kiwi_run_test('Kiwi_Landing_Page_Router resolves backend path and dedicated host routes', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [],
        [
            'lp2-fr' => [
                'backend_path' => '/lp/fr/myjoyplay',
                'dedicated_path' => '/',
                'hostnames' => ['frlp2.joy-play.com'],
                'service_key' => 'nth_fr_one_off_jplay',
            ],
        ]
    );
    $router = new Kiwi_Landing_Page_Router(
        $config,
        new Kiwi_Landing_Page_Session_Repository(),
        'https://example.test/plugin/'
    );

    $path_match = $router->resolve_request('backend.kiwimobile.de', '/lp/fr/myjoyplay');
    $public_host_path_match = $router->resolve_request('landing-public.example.test', '/lp/fr/myjoyplay');
    $host_match = $router->resolve_request('frlp2.joy-play.com', '/');
    $no_match = $router->resolve_request('backend.kiwimobile.de', '/other');

    kiwi_assert_same('lp2-fr', $path_match['landing_key'], 'Expected backend path matching to resolve the configured landing page.');
    kiwi_assert_same('lp2-fr', $public_host_path_match['landing_key'], 'Expected backend path matching to be host-agnostic for proxied public domains.');
    kiwi_assert_same('lp2-fr', $host_match['landing_key'], 'Expected dedicated host matching to resolve the configured landing page.');
    kiwi_assert_same(null, $no_match, 'Expected unrelated requests not to match a landing page.');
});

kiwi_run_test('Kiwi_Landing_Page_Router rewrites local filesystem asset paths to default landing-page asset URL', function (): void {
    $router = new Kiwi_Landing_Page_Router(
        new Kiwi_Test_Config(),
        new Kiwi_Landing_Page_Session_Repository(),
        'https://backend.kiwimobile.de/wp-content/plugins/kiwi-backend/'
    );

    $resolve_asset_base_method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'resolve_filesystem_asset_base_url');
    $replace_stylesheet_method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'replace_stylesheet_href');
    $replace_local_assets_method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'replace_local_asset_paths');
    $replace_local_css_assets_method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'replace_local_css_asset_paths');

    $html = '<!doctype html><html><head><link rel="stylesheet" href="./styles.css"></head><body><img class="hero" src="./hero-dragonfight.jpg"><script src="./core.js"></script><a href="./terms.pdf">Terms</a></body></html>';
    $landing_folder_asset_base_url = 'https://backend.kiwimobile.de/wp-content/plugins/kiwi-backend/landing-pages/lp4-fr/';
    $asset_base_url = $resolve_asset_base_method->invoke($router, [], 'lp4-fr');
    $html = $replace_stylesheet_method->invoke($router, $html, $landing_folder_asset_base_url . 'styles.css');
    $html = $replace_local_assets_method->invoke($router, $html, $asset_base_url);
    $css = $replace_local_css_assets_method->invoke($router, ".hero { background-image: url('./hero-bg.png'); }", $asset_base_url);

    kiwi_assert_contains(
        'href="https://backend.kiwimobile.de/wp-content/plugins/kiwi-backend/landing-pages/lp4-fr/styles.css"',
        $html,
        'Expected styles.css path to remain served from the plugin landing-page folder.'
    );
    kiwi_assert_contains(
        'src="https://backend.kiwimobile.de/wp-content/uploads/assets/hero-dragonfight.jpg"',
        $html,
        'Expected local img src paths to be rewritten to the default landing-page asset URL.'
    );
    kiwi_assert_contains(
        'src="https://backend.kiwimobile.de/wp-content/uploads/assets/core.js"',
        $html,
        'Expected local script src paths to be rewritten to the default landing-page asset URL.'
    );
    kiwi_assert_contains(
        'href="https://backend.kiwimobile.de/wp-content/uploads/assets/terms.pdf"',
        $html,
        'Expected local anchor href paths to be rewritten to the default landing-page asset URL.'
    );
    kiwi_assert_contains(
        "url('https://backend.kiwimobile.de/wp-content/uploads/assets/hero-bg.png')",
        $css,
        'Expected local CSS asset paths to be rewritten to the default landing-page asset URL.'
    );
});

kiwi_run_test('Kiwi_Landing_Page_Router rewrites local filesystem asset paths to configured asset base URL', function (): void {
    $router = new Kiwi_Landing_Page_Router(
        new Kiwi_Test_Config(),
        new Kiwi_Landing_Page_Session_Repository(),
        'https://backend.kiwimobile.de/wp-content/plugins/kiwi-backend/'
    );

    $resolve_asset_base_method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'resolve_filesystem_asset_base_url');
    $replace_stylesheet_method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'replace_stylesheet_href');
    $replace_local_assets_method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'replace_local_asset_paths');

    $html = '<!doctype html><html><head><link rel="stylesheet" href="./styles.css"></head><body><img class="hero" src="./FR-Joyplay_LandingPage_Overview_Collage.png"></body></html>';
    $landing_folder_asset_base_url = 'https://backend.kiwimobile.de/wp-content/plugins/kiwi-backend/landing-pages/lp2-fr/';
    $external_asset_base_url = $resolve_asset_base_method->invoke($router, [
        'asset_base_url' => 'https://assets.example.test/custom/',
    ], 'lp2-fr');

    $html = $replace_stylesheet_method->invoke($router, $html, $landing_folder_asset_base_url . 'styles.css');
    $html = $replace_local_assets_method->invoke($router, $html, $external_asset_base_url);

    kiwi_assert_contains(
        'href="https://backend.kiwimobile.de/wp-content/plugins/kiwi-backend/landing-pages/lp2-fr/styles.css"',
        $html,
        'Expected styles.css to remain served from the landing-page folder.'
    );
    kiwi_assert_contains(
        'src="https://assets.example.test/custom/FR-Joyplay_LandingPage_Overview_Collage.png"',
        $html,
        'Expected local img src paths to be rewritten to the configured external asset base URL.'
    );
});

kiwi_run_test('Kiwi_Tracking_Capture_Service captures clickid and persists server-side attribution state', function (): void {
    $_COOKIE = [];
    $repository = new Kiwi_Test_Click_Attribution_Repository();
    $config = new Kiwi_Test_Attribution_Config();
    $service = new Kiwi_Test_Tracking_Capture_Service($config, $repository);

    $record = $service->capture_from_request(
        [
            'key' => 'lp2-fr',
            'provider' => 'nth',
            'flow' => 'nth-fr-one-off',
            'service_key' => 'nth_fr_one_off_jplay',
        ],
        'landing-session-1',
        [
            'clickid' => 'abc:123',
            'pid' => ' partner<>_A:1 + ',
        ]
    );

    kiwi_assert_true(is_array($record), 'Expected click attribution capture to create a persisted record.');
    kiwi_assert_same('abc:123', $record['click_id'] ?? '', 'Expected captured clickid to be persisted in server-side storage.');
    kiwi_assert_same('partner_A:1', $record['pid'] ?? '', 'Expected capture to sanitize and persist pid as first-class attribution field.');
    kiwi_assert_true(trim((string) ($record['transaction_id'] ?? '')) !== '', 'Expected capture to assign a server-side transaction_id.');
    kiwi_assert_same(1, count($repository->rows), 'Expected one server-side attribution record after first capture.');
    kiwi_assert_true(!empty($service->cookies[0] ?? ''), 'Expected capture to set an opaque tracking-token cookie.');
    kiwi_assert_true(($service->cookies[0] ?? '') !== 'abc:123', 'Expected the cookie value to be opaque and not the raw clickid.');
    $initial_transaction_id = (string) ($record['transaction_id'] ?? '');

    $_COOKIE['kiwi_tracking_token'] = (string) ($service->cookies[0] ?? '');
    $service->capture_from_request(
        [
            'key' => 'lp2-fr',
            'provider' => 'nth',
            'flow' => 'nth-fr-one-off',
            'service_key' => 'nth_fr_one_off_jplay',
        ],
        'landing-session-1',
        [
            'clickid' => 'xyz:789',
        ]
    );

    kiwi_assert_same(1, count($repository->rows), 'Expected repeated capture in same browser context to reuse the same server-side attribution row.');
    $saved = array_values($repository->rows)[0];
    kiwi_assert_same('xyz:789', $saved['click_id'] ?? '', 'Expected repeated capture to refresh stored clickid value for the active tracking token.');
    kiwi_assert_same($initial_transaction_id, $saved['transaction_id'] ?? '', 'Expected repeated capture for one tracking token to keep the same transaction_id.');
    kiwi_assert_same('partner_A:1', $saved['pid'] ?? '', 'Expected repeated capture without pid input to keep the previously captured pid value.');
});

kiwi_run_test('Kiwi_Conversion_Attribution_Resolver matches confirmed conversions and dispatches one idempotent postback', function (): void {
    $repository = new Kiwi_Test_Click_Attribution_Repository();
    $config = new Kiwi_Test_Attribution_Config(
        'https://offers.example.test/postback?clickid={clickid}&secure={hash}',
        'super-secret'
    );
    $dispatcher = new Kiwi_Test_Affiliate_Postback_Dispatcher($config);
    $kpi_summary_repository = new Kiwi_Test_Landing_Kpi_Summary_Repository();
    $kpi_service = new Kiwi_Landing_Kpi_Service(
        new Kiwi_Test_Config(
            100,
            0,
            0,
            [],
            [],
            [],
            [
                'lp2-fr' => [
                    'service_key' => 'nth_fr_one_off_jplay',
                    'provider' => 'nth',
                    'flow' => 'one-off',
                ],
            ]
        ),
        $kpi_summary_repository
    );
    $resolver = new Kiwi_Conversion_Attribution_Resolver($repository, $dispatcher, $kpi_service);

    $capture = $repository->upsert_capture([
        'tracking_token' => 'TOK1234567890123',
        'click_id' => 'aff:click:001',
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'landing_page_key' => 'lp2-fr',
        'flow_key' => 'one-off',
        'session_ref' => 'session-1',
        'expires_at' => '2026-04-05 12:00:00',
    ]);

    $resolver->attach_provider_references([
        'tracking_token' => (string) ($capture['tracking_token'] ?? ''),
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'flow_key' => 'one-off',
        'transaction_ref' => 'flow-1',
        'message_ref' => 'msg-1',
        'sale_reference' => 'sale-1',
        'session_ref' => 'session-1',
    ]);

    $result = $resolver->handle_confirmed_conversion([
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'flow_key' => 'one-off',
        'confirmed' => true,
        'occurred_at' => '2026-04-01 12:00:00',
        'transaction_ref' => 'flow-1',
        'message_ref' => 'msg-1',
        'sale_reference' => 'sale-1',
    ]);

    kiwi_assert_true($result['matched'] ?? false, 'Expected confirmed conversion handling to match the persisted click attribution row.');
    kiwi_assert_true($result['dispatched'] ?? false, 'Expected confirmed conversion handling to dispatch an affiliate postback.');
    kiwi_assert_same(1, count($dispatcher->calls), 'Expected exactly one outbound postback call for the first confirmed conversion.');
    kiwi_assert_true(
        strpos($dispatcher->calls[0], 'clickid=aff%3Aclick%3A001') !== false,
        'Expected postback URL building to URL-encode clickid values.'
    );
    $expected_hash = hash('sha256', 'aff:click:001:super-secret');
    kiwi_assert_true(
        strpos($dispatcher->calls[0], 'secure=' . $expected_hash) !== false,
        'Expected configured postback secret/signature generation to be applied.'
    );

    $duplicate = $resolver->handle_confirmed_conversion([
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'confirmed' => true,
        'transaction_ref' => 'flow-1',
    ]);
    kiwi_assert_same('postback_already_sent', $duplicate['reason'] ?? '', 'Expected duplicate confirmed callbacks to skip duplicate postback dispatch.');
    kiwi_assert_same(1, count($dispatcher->calls), 'Expected duplicate callbacks not to emit a second postback.');
    kiwi_assert_same(1, (int) ($kpi_summary_repository->rows['lp2-fr']['conv'] ?? 0), 'Expected conversion KPI summary counter to increment exactly once across duplicate confirmed callbacks.');

    $not_confirmed = $resolver->handle_confirmed_conversion([
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'confirmed' => false,
        'transaction_ref' => 'flow-1',
    ]);
    kiwi_assert_same('not_confirmed', $not_confirmed['reason'] ?? '', 'Expected non-confirmed conversions to avoid outbound postbacks.');
    kiwi_assert_same(1, count($dispatcher->calls), 'Expected non-confirmed conversion handling not to trigger postback dispatch.');
});

kiwi_run_test('Kiwi_Conversion_Attribution_Resolver can resolve confirmed conversions by transaction_id', function (): void {
    $repository = new Kiwi_Test_Click_Attribution_Repository();
    $config = new Kiwi_Test_Attribution_Config(
        'https://offers.example.test/postback?clickid={clickid}&secure={hash}',
        'super-secret'
    );
    $dispatcher = new Kiwi_Test_Affiliate_Postback_Dispatcher($config);
    $resolver = new Kiwi_Conversion_Attribution_Resolver($repository, $dispatcher);

    $capture = $repository->upsert_capture([
        'tracking_token' => 'TOKTXNID12345678',
        'transaction_id' => 'txn_test_conversion_0001',
        'click_id' => 'aff:click:txn',
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'expires_at' => '2026-04-05 12:00:00',
    ]);

    $result = $resolver->handle_confirmed_conversion([
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'confirmed' => true,
        'transaction_id' => (string) ($capture['transaction_id'] ?? ''),
    ]);

    kiwi_assert_true($result['matched'] ?? false, 'Expected transaction_id-based resolution to match a persisted attribution row.');
    kiwi_assert_true($result['dispatched'] ?? false, 'Expected transaction_id-based resolution to dispatch one postback.');
    kiwi_assert_same(1, count($dispatcher->calls), 'Expected one outbound postback for transaction_id-based confirmed conversion handling.');
});

kiwi_run_test('Kiwi_Conversion_Attribution_Resolver appends sub7 from persisted sales operator_name', function (): void {
    $repository = new Kiwi_Test_Click_Attribution_Repository();
    $sales_repository = new Kiwi_Test_Sales_Repository();
    $sales_repository->upsert([
        'sale_reference' => 'sale-sub7-1',
        'operator_name' => '20820',
        'operator_code' => '20820',
        'provider_key' => 'nth',
        'status' => 'completed',
    ]);
    $config = new Kiwi_Test_Attribution_Config(
        'https://offers.example.test/postback?clickid={clickid}&goal=sale',
        ''
    );
    $dispatcher = new Kiwi_Test_Affiliate_Postback_Dispatcher($config);
    $resolver = new Kiwi_Conversion_Attribution_Resolver(
        $repository,
        $dispatcher,
        null,
        $sales_repository
    );

    $capture = $repository->upsert_capture([
        'tracking_token' => 'TOKSUB7ATTRIBUTION',
        'click_id' => 'aff:click:sub7',
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'expires_at' => '2026-04-05 12:00:00',
    ]);

    $resolver->attach_provider_references([
        'tracking_token' => (string) ($capture['tracking_token'] ?? ''),
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'transaction_ref' => 'flow-sub7-1',
        'sale_reference' => 'sale-sub7-1',
    ]);

    $result = $resolver->handle_confirmed_conversion([
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'confirmed' => true,
        'transaction_ref' => 'flow-sub7-1',
        'sale_reference' => 'sale-sub7-1',
    ]);

    kiwi_assert_true($result['dispatched'] ?? false, 'Expected confirmed conversion to dispatch postback in sub7 enrichment flow.');
    kiwi_assert_same(1, count($dispatcher->calls), 'Expected one outbound postback call for sub7 enrichment flow.');
    kiwi_assert_true(
        strpos((string) ($dispatcher->calls[0] ?? ''), 'sub7=20820') !== false,
        'Expected postback URL to include sub7 from wp_kiwi_sales.operator_name.'
    );
});

kiwi_run_test('Kiwi_Conversion_Attribution_Resolver persists pid from attribution query params into sales record', function (): void {
    $repository = new Kiwi_Test_Click_Attribution_Repository();
    $sales_repository = new Kiwi_Test_Sales_Repository();
    $sales_repository->upsert([
        'sale_reference' => 'sale-pid-1',
        'provider_key' => 'nth',
        'status' => 'completed',
        'pid' => '',
    ]);
    $config = new Kiwi_Test_Attribution_Config(
        'https://offers.example.test/postback?clickid={clickid}&goal=sale',
        ''
    );
    $dispatcher = new Kiwi_Test_Affiliate_Postback_Dispatcher($config);
    $resolver = new Kiwi_Conversion_Attribution_Resolver(
        $repository,
        $dispatcher,
        null,
        $sales_repository
    );

    $capture = $repository->upsert_capture([
        'tracking_token' => 'TOKPIDATTRIBUTION01',
        'click_id' => 'aff:click:pid1',
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'raw_context' => [
            'query_params' => [
                'pid' => ' partner<>_A:1 + ',
            ],
        ],
        'expires_at' => '2026-04-05 12:00:00',
    ]);

    $resolver->attach_provider_references([
        'tracking_token' => (string) ($capture['tracking_token'] ?? ''),
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'transaction_ref' => 'flow-pid-1',
        'sale_reference' => 'sale-pid-1',
    ]);

    $result = $resolver->handle_confirmed_conversion([
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'confirmed' => true,
        'transaction_ref' => 'flow-pid-1',
        'sale_reference' => 'sale-pid-1',
    ]);

    kiwi_assert_true($result['dispatched'] ?? false, 'Expected confirmed conversion to dispatch postback in pid persistence flow.');
    kiwi_assert_same(1, count($sales_repository->pid_updates), 'Expected one pid update write for confirmed conversion with pid context.');
    kiwi_assert_same(
        'partner_A:1',
        (string) ($sales_repository->find_by_sale_reference('sale-pid-1')['pid'] ?? ''),
        'Expected pid to be sanitized and persisted into wp_kiwi_sales.'
    );
});

kiwi_run_test('Kiwi_Conversion_Attribution_Resolver leaves sales pid unchanged when attribution has no pid', function (): void {
    $repository = new Kiwi_Test_Click_Attribution_Repository();
    $sales_repository = new Kiwi_Test_Sales_Repository();
    $sales_repository->upsert([
        'sale_reference' => 'sale-pid-2',
        'provider_key' => 'nth',
        'status' => 'completed',
        'pid' => 'existing_pid',
    ]);
    $config = new Kiwi_Test_Attribution_Config(
        'https://offers.example.test/postback?clickid={clickid}&goal=sale',
        ''
    );
    $dispatcher = new Kiwi_Test_Affiliate_Postback_Dispatcher($config);
    $resolver = new Kiwi_Conversion_Attribution_Resolver(
        $repository,
        $dispatcher,
        null,
        $sales_repository
    );

    $capture = $repository->upsert_capture([
        'tracking_token' => 'TOKPIDATTRIBUTION02',
        'click_id' => 'aff:click:pid2',
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'raw_context' => [
            'query_params' => [
                'gclid' => 'gclid-123',
            ],
        ],
        'expires_at' => '2026-04-05 12:00:00',
    ]);

    $resolver->attach_provider_references([
        'tracking_token' => (string) ($capture['tracking_token'] ?? ''),
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'transaction_ref' => 'flow-pid-2',
        'sale_reference' => 'sale-pid-2',
    ]);

    $result = $resolver->handle_confirmed_conversion([
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'confirmed' => true,
        'transaction_ref' => 'flow-pid-2',
        'sale_reference' => 'sale-pid-2',
    ]);

    kiwi_assert_true($result['dispatched'] ?? false, 'Expected confirmed conversion to dispatch postback in no-pid flow.');
    kiwi_assert_same(0, count($sales_repository->pid_updates), 'Expected no pid write when attribution query params do not include pid.');
    kiwi_assert_same(
        'existing_pid',
        (string) ($sales_repository->find_by_sale_reference('sale-pid-2')['pid'] ?? ''),
        'Expected existing pid value to remain unchanged when no pid is present in attribution context.'
    );
});

kiwi_run_test('Kiwi_Shared_Sales_Recorder writes transaction_id to sales records when provided', function (): void {
    $repository = new Kiwi_Test_Sales_Repository();
    $recorder = new Kiwi_Shared_Sales_Recorder($repository);

    $recorder->record_successful_one_off_sale([
        'sale_reference' => 'sale-explicit-1',
        'flow_reference' => 'txn_1234567890abcdef1234567890abcd-a1b2c3d4e5f6',
        'transaction_id' => 'txn_explicit_123456789012',
        'country' => 'FR',
        'flow_key' => 'one-off',
        'price' => 450,
        'currency' => 'EUR',
        'subscriber_reference' => 'enc-1',
        'operator_code' => '20801',
        'operator_name' => 'Orange',
        'shortcode' => '84072',
        'keyword' => 'JPLAY',
    ], [
        'external_message_id' => 'msg-explicit-1',
        'occurred_at' => '2026-04-01 12:00:00',
    ]);

    kiwi_assert_same(
        'txn_explicit_123456789012',
        $repository->upsert_calls[0]['transaction_id'] ?? '',
        'Expected shared sales recorder to persist explicit transaction_id into wp_kiwi_sales writes.'
    );
});

kiwi_run_test('Kiwi_Shared_Sales_Recorder derives transaction_id from flow_reference when explicit value is absent', function (): void {
    $repository = new Kiwi_Test_Sales_Repository();
    $recorder = new Kiwi_Shared_Sales_Recorder($repository);

    $recorder->record_successful_one_off_sale([
        'sale_reference' => 'sale-derived-1',
        'flow_reference' => 'txn_1234567890abcdef1234567890abce-a1b2c3d4e5f6',
        'country' => 'FR',
        'flow_key' => 'one-off',
        'price' => 450,
        'currency' => 'EUR',
        'subscriber_reference' => 'enc-2',
        'operator_code' => '20801',
        'operator_name' => 'Orange',
        'shortcode' => '84072',
        'keyword' => 'JPLAY',
    ], [
        'external_message_id' => 'msg-derived-1',
        'occurred_at' => '2026-04-01 12:00:00',
    ]);

    kiwi_assert_same(
        'txn_1234567890abcdef1234567890abce',
        $repository->upsert_calls[0]['transaction_id'] ?? '',
        'Expected shared sales recorder to derive transaction_id from provider flow_reference when needed.'
    );
});

kiwi_run_test('Kiwi_Affiliate_Postback_Dispatcher supports double-brace clickid placeholders', function (): void {
    $config = new Kiwi_Test_Attribution_Config(
        'https://offers-kiwimobile.affise.com/postback?clickid={{clickid}}&secure=7e09e7feb5d6f029ae4bb755955b6727&goal=sale',
        ''
    );
    $dispatcher = new Kiwi_Test_Affiliate_Postback_Dispatcher($config);

    $url = $dispatcher->build_postback_url('aff:double:brace');

    kiwi_assert_true(
        strpos($url, 'clickid=aff%3Adouble%3Abrace') !== false,
        'Expected dispatcher to replace double-brace clickid placeholders.'
    );
    kiwi_assert_true(
        strpos($url, 'goal=sale') !== false,
        'Expected non-placeholder query parameters to remain unchanged.'
    );
});

kiwi_run_test('Kiwi_Click_Attribution_Repository cleanup removes only expired rows up to the configured limit', function (): void {
    $repository = new Kiwi_Test_Click_Attribution_Repository();
    $repository->upsert_capture([
        'tracking_token' => 'TOK1234567890AAA',
        'click_id' => 'one',
        'expires_at' => '2026-03-01 00:00:00',
    ]);
    $repository->upsert_capture([
        'tracking_token' => 'TOK1234567890BBB',
        'click_id' => 'two',
        'expires_at' => '2026-03-02 00:00:00',
    ]);
    $repository->upsert_capture([
        'tracking_token' => 'TOK1234567890CCC',
        'click_id' => 'three',
        'expires_at' => '2026-04-10 00:00:00',
    ]);

    $deleted = $repository->cleanup_expired(1);

    kiwi_assert_same(1, $deleted, 'Expected cleanup to honor the delete batch limit.');
    kiwi_assert_same(2, count($repository->rows), 'Expected cleanup to remove only one expired row in this batch.');
});

kiwi_run_test('Kiwi_Nth_Rest_Routes registers a single generic NTH callback endpoint', function (): void {
    $GLOBALS['kiwi_test_rest_routes'] = [];

    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'shortcode' => '84072',
                'keyword' => 'JPLAY',
            ],
        ]
    );
    $routes = new Kiwi_Nth_Rest_Routes($config, new Kiwi_Test_Nth_Service());
    $routes->register_routes();

    kiwi_assert_same(1, count($GLOBALS['kiwi_test_rest_routes']), 'Expected a single NTH callback route to be registered.');
    kiwi_assert_same('/nth-callback', $GLOBALS['kiwi_test_rest_routes'][0]['route'], 'Expected NTH callbacks to use the generic /nth-callback route.');
});

kiwi_run_test('Kiwi_Nth_Rest_Routes dispatches generic callbacks by payload command and resolved service key', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'shortcode' => '84072',
                'keyword' => 'JPLAY',
            ],
        ]
    );
    $service = new Kiwi_Test_Nth_Service();
    $routes = new Kiwi_Nth_Rest_Routes($config, $service);

    $mo_response = $routes->handle_callback(new WP_REST_Request([
        'command' => 'deliverMessage',
        'businessNumber' => '84072',
        'content' => 'JPLAY txn_demo_12345678',
    ]));
    $notification_response = $routes->handle_callback(new WP_REST_Request([
        'service_key' => 'nth_fr_one_off_jplay',
        'command' => 'deliverReport',
        'messageStatus' => 'delivered',
    ]));
    $rejected_response = $routes->handle_callback(new WP_REST_Request([
        'command' => 'deliverMessage',
        'businessNumber' => '99999',
    ]));

    kiwi_assert_same(1, count($service->mo_calls), 'Expected deliverMessage callbacks to route to handle_inbound_mo.');
    kiwi_assert_same('nth_fr_one_off_jplay', $service->mo_calls[0]['service_key'] ?? '', 'Expected MO callbacks to resolve the configured NTH service key.');
    kiwi_assert_same(1, count($service->notification_calls), 'Expected deliverReport callbacks to route to handle_notification.');
    kiwi_assert_same('accepted', $mo_response->headers['X-Kiwi-Status'] ?? '', 'Expected successful callbacks to be acknowledged as accepted.');
    kiwi_assert_same('accepted', $notification_response->headers['X-Kiwi-Status'] ?? '', 'Expected notification callbacks to be acknowledged as accepted.');
    kiwi_assert_same('rejected', $rejected_response->headers['X-Kiwi-Status'] ?? '', 'Expected unresolved callbacks to be acknowledged as rejected.');
});

kiwi_run_test('Kiwi_Landing_Kpi_Rest_Routes registers event and report endpoints', function (): void {
    $GLOBALS['kiwi_test_rest_routes'] = [];
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [],
        [
            'lp2-fr' => [
                'service_key' => 'nth_fr_one_off_jplay',
                'provider' => 'nth',
                'flow' => 'nth-fr-one-off',
            ],
        ]
    );
    $summary_repository = new Kiwi_Test_Landing_Kpi_Summary_Repository();
    $service = new Kiwi_Landing_Kpi_Service($config, $summary_repository);
    $routes = new Kiwi_Landing_Kpi_Rest_Routes($config, $service);

    $routes->register_routes();

    kiwi_assert_same(2, count($GLOBALS['kiwi_test_rest_routes']), 'Expected landing KPI routes to register event and report endpoints.');
    kiwi_assert_same('/landing-kpi/event', $GLOBALS['kiwi_test_rest_routes'][0]['route'] ?? '', 'Expected event endpoint route to match contract.');
    kiwi_assert_same('/landing-kpi/report', $GLOBALS['kiwi_test_rest_routes'][1]['route'] ?? '', 'Expected report endpoint route to match contract.');
});

kiwi_run_test('Kiwi_Landing_Kpi_Rest_Routes increments configured CTA steps in summary storage', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [],
        [
            'lp2-fr' => [
                'service_key' => 'nth_fr_one_off_jplay',
                'provider' => 'nth',
                'flow' => 'nth-fr-one-off',
            ],
        ]
    );
    $summary_repository = new Kiwi_Test_Landing_Kpi_Summary_Repository();
    $service = new Kiwi_Landing_Kpi_Service($config, $summary_repository);
    $routes = new Kiwi_Landing_Kpi_Rest_Routes($config, $service);

    $first = $routes->handle_event(new WP_REST_Request([], [
        'landing_key' => 'lp2-fr',
        'step' => 'cta1',
        'event_value' => '.cta',
    ]));
    $duplicate = $routes->handle_event(new WP_REST_Request([], [
        'landing_key' => 'lp2-fr',
        'step' => 'cta1',
        'event_value' => '.cta',
    ]));
    $invalid = $routes->handle_event(new WP_REST_Request([], [
        'landing_key' => 'lp2-fr',
        'step' => 'invalid_step',
    ]));

    kiwi_assert_true(($first->data['success'] ?? false) === true, 'Expected valid KPI events to be accepted.');
    kiwi_assert_true(($first->data['incremented'] ?? false) === true, 'Expected first KPI step event to increment summary counters.');
    kiwi_assert_true(($duplicate->data['incremented'] ?? false) === true, 'Expected repeated KPI step events to increment aggregated counters.');
    kiwi_assert_same(2, (int) ($summary_repository->rows['lp2-fr']['cta1'] ?? 0), 'Expected CTA1 counter to increment twice for repeated valid events.');
    kiwi_assert_same(400, $invalid->status, 'Expected invalid KPI step values to be rejected.');
});

kiwi_run_test('Kiwi_Landing_Kpi_Rest_Routes records landing engagement events without changing KPI counters', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [],
        [
            'lp2-fr' => [
                'service_key' => 'nth_fr_one_off_jplay',
                'provider' => 'nth',
                'flow' => 'nth-fr-one-off',
            ],
        ]
    );
    $summary_repository = new Kiwi_Test_Landing_Kpi_Summary_Repository();
    $engagement_repository = new Kiwi_Test_Premium_Sms_Landing_Engagement_Repository();
    $service = new Kiwi_Landing_Kpi_Service($config, $summary_repository);
    $routes = new Kiwi_Landing_Kpi_Rest_Routes($config, $service, $engagement_repository);

    $page_loaded = $routes->handle_event(new WP_REST_Request([], [
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-kpi-1',
        'event_type' => 'page_loaded',
        'pid' => 'affpid_42',
        'clickid' => 'aff-click-42',
    ]));
    $cta_click_a = $routes->handle_event(new WP_REST_Request([], [
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-kpi-1',
        'event_type' => 'cta_click',
        'event_value' => 'cta1:.cta',
    ]));
    $cta_click_b = $routes->handle_event(new WP_REST_Request([], [
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-kpi-1',
        'event_type' => 'cta_click',
        'event_value' => 'cta1:.cta',
    ]));

    $row = $engagement_repository->get_by_landing_session('lp2-fr', 'sess-kpi-1');

    kiwi_assert_true(($page_loaded->data['engagement_recorded'] ?? false), 'Expected page_loaded engagement event to be stored.');
    kiwi_assert_true(($cta_click_a->data['engagement_recorded'] ?? false), 'Expected first cta_click engagement event to be stored.');
    kiwi_assert_true(($cta_click_b->data['engagement_recorded'] ?? false), 'Expected repeated cta_click engagement events to update click count.');
    kiwi_assert_true(is_array($row), 'Expected engagement storage row to be persisted by landing/session.');
    kiwi_assert_same('2026-04-01 12:00:00', (string) ($row['page_loaded_at'] ?? ''), 'Expected first page_loaded timestamp to be captured.');
    kiwi_assert_same(2, (int) ($row['cta_click_count'] ?? 0), 'Expected cta_click count to increment for repeated click events.');
    kiwi_assert_same('affpid_42', (string) ($row['pid'] ?? ''), 'Expected landing engagement storage to persist pid from KPI event payload.');
    kiwi_assert_same('aff-click-42', (string) ($row['click_id'] ?? ''), 'Expected landing engagement storage to persist clickid from KPI event payload.');
    kiwi_assert_same(0, (int) ($summary_repository->rows['lp2-fr']['cta1'] ?? 0), 'Expected engagement-only events not to mutate KPI CTA counters.');
});

kiwi_run_test('Kiwi_Landing_Kpi_Service builds per-landing summary rows with percentage rates', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [],
        [
            'lp2-fr' => [
                'title' => 'LP2',
                'service_key' => 'nth_fr_one_off_jplay',
                'provider' => 'nth',
                'flow' => 'nth-fr-one-off',
            ],
            'lp3-fr' => [
                'title' => 'LP3',
                'service_key' => 'nth_fr_one_off_jplay',
                'provider' => 'nth',
                'flow' => 'nth-fr-one-off',
            ],
        ]
    );
    $summary_repository = new Kiwi_Test_Landing_Kpi_Summary_Repository();
    $service = new Kiwi_Landing_Kpi_Service($config, $summary_repository);

    for ($i = 0; $i < 100; $i++) {
        $service->increment_click('lp2-fr');
    }
    for ($i = 0; $i < 40; $i++) {
        $service->increment_step('lp2-fr', 'cta1');
    }
    for ($i = 0; $i < 25; $i++) {
        $service->increment_step('lp2-fr', 'cta2');
    }
    for ($i = 0; $i < 10; $i++) {
        $service->increment_step('lp2-fr', 'cta3');
    }
    for ($i = 0; $i < 12; $i++) {
        $service->increment_conversion('lp2-fr');
    }
    for ($i = 0; $i < 20; $i++) {
        $service->increment_click('lp3-fr');
    }
    for ($i = 0; $i < 5; $i++) {
        $service->increment_step('lp3-fr', 'cta1');
    }
    for ($i = 0; $i < 2; $i++) {
        $service->increment_conversion('lp3-fr');
    }

    $report = $service->build_report(30);
    $rows = $report['rows'] ?? [];
    $lp2 = null;

    foreach ($rows as $row) {
        if (($row['landing_key'] ?? '') === 'lp2-fr') {
            $lp2 = $row;
            break;
        }
    }

    kiwi_assert_true(is_array($lp2), 'Expected KPI report to include lp2-fr row.');
    kiwi_assert_same(100, $lp2['clicks'] ?? 0, 'Expected click count to come from summary counter aggregate.');
    kiwi_assert_same(40, $lp2['cta1'] ?? 0, 'Expected cta1 count to come from summary counter aggregate.');
    kiwi_assert_same(25, $lp2['cta2'] ?? 0, 'Expected cta2 count to come from summary counter aggregate.');
    kiwi_assert_same(10, $lp2['cta3'] ?? 0, 'Expected cta3 count to come from summary counter aggregate.');
    kiwi_assert_same(12, $lp2['conv'] ?? 0, 'Expected conversion count to come from summary counter aggregate.');
    kiwi_assert_same(40.0, $lp2['cta1_rate_pct'] ?? 0.0, 'Expected cta1 rate to be calculated against clicks.');
    kiwi_assert_same(25.0, $lp2['cta2_rate_pct'] ?? 0.0, 'Expected cta2 rate to be calculated against clicks.');
    kiwi_assert_same(10.0, $lp2['cta3_rate_pct'] ?? 0.0, 'Expected cta3 rate to be calculated against clicks.');
    kiwi_assert_same(12.0, $lp2['conv_rate_pct'] ?? 0.0, 'Expected conversion rate to be calculated against clicks.');
});

kiwi_run_test('Kiwi_Landing_Page_Router accepts integration kpi_cta_steps using class assignment shorthand', function (): void {
    $router = new Kiwi_Landing_Page_Router(
        new Kiwi_Test_Config(),
        new Kiwi_Landing_Page_Session_Repository(),
        'https://example.test/plugin/'
    );
    $method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'resolve_kpi_step_selectors');
    $steps = $method->invoke($router, [
        'kpi_cta_steps' => [
            'cta1' => 'class="cta"',
            'cta2' => '.mobile_number_input',
        ],
    ]);

    kiwi_assert_same('.cta', $steps['cta1'] ?? '', 'Expected class=\"cta\" shorthand to normalize into .cta selector.');
    kiwi_assert_same('.mobile_number_input', $steps['cta2'] ?? '', 'Expected direct CSS selectors to remain unchanged.');
});

kiwi_run_test('Kiwi_Landing_Primary_Cta_Resolver builds NTH sms CTA with transaction_id suffix', function (): void {
    $resolver = new Kiwi_Landing_Primary_Cta_Resolver([
        new Kiwi_Nth_Primary_Cta_Adapter(),
    ]);

    $href = $resolver->resolve(
        [
            'provider' => 'nth',
            'flow' => 'nth-fr-one-off',
            'shortcode' => '84072',
            'keyword' => 'JPLAY*',
        ],
        [],
        [
            'transaction_id' => 'txn_demo_12345678',
        ]
    );

    kiwi_assert_same(
        'sms:84072?body=JPLAY%20txn_demo_12345678',
        $href,
        'Expected NTH CTA resolution to append transaction_id to the MO body.'
    );
});

kiwi_run_test('Generic landing page template renders shared assets and config-driven CTA content', function (): void {
    $landing_page = [
        'page_title' => 'Joyplay',
        'asset_base_url' => 'https://assets.example.test/joyplay',
        'background_image_path' => 'background.png',
        'hero_image_path' => 'hero.png',
        'cta_href' => 'sms:84072?body=JPLAY',
        'cta_label' => 'CONTINUER ET PAYER',
        'terms_url' => 'https://example.test/terms',
        'terms_label' => 'TERMES ET CONDITIONS',
        'short_description' => 'Short copy',
        'long_description' => 'Long copy',
        'keyword' => 'JPLAY',
        'shortcode' => '84072',
        'price_label' => '4,50 EUR / SMS + prix d\'un SMS',
        'disclaimer_html' => 'Disclaimer',
    ];
    $click_to_sms_uri = '#';

    ob_start();
    include __DIR__ . '/../templates/landing-pages/generic-offer.php';
    $output = (string) ob_get_clean();

    kiwi_assert_true(strpos($output, 'https://assets.example.test/joyplay/background.png') !== false, 'Expected the generic landing template to expand the shared background asset URL.');
    kiwi_assert_true(strpos($output, 'https://assets.example.test/joyplay/hero.png') !== false, 'Expected the generic landing template to expand the shared hero asset URL.');
    kiwi_assert_true(strpos($output, 'sms:84072?body=JPLAY') !== false, 'Expected the generic landing template to render the configured CTA href.');
    kiwi_assert_true(strpos($output, 'Activer en envoyant JPLAY au 84072') !== false, 'Expected the generic landing template to derive FR click-to-SMS price text from config.');
});

kiwi_run_test('Kiwi_Nth_Premium_Sms_Normalizer normalizes alias-heavy MO payloads', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'shortcode' => '84072',
                'keyword' => 'JPLAY',
            ],
        ]
    );
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);

    $normalized = $normalizer->normalize_callback('nth_fr_one_off_jplay', 'mo', [
        'Encrypted_MSISDN' => 'enc-123',
        'Business_Number' => '84072',
        'Message' => 'JPLAY+txn_alias_12345678',
        'NWC' => '20801',
        'Operator' => 'Orange',
    ]);

    kiwi_assert_same('enc-123', $normalized['subscriber_reference'], 'Expected encrypted MSISDN aliases to normalize into subscriber_reference.');
    kiwi_assert_same('84072', $normalized['shortcode'], 'Expected business number aliases to normalize into shortcode.');
    kiwi_assert_same('JPLAY', $normalized['keyword'], 'Expected MO keyword to be normalized to uppercase without wildcard suffix.');
    kiwi_assert_same('20801', $normalized['operator_code'], 'Expected NWC to remain the canonical operator code source.');
    kiwi_assert_same('Orange', $normalized['operator_name'], 'Expected explicit operator name to remain unchanged when provided.');
    kiwi_assert_same('received', $normalized['status'], 'Expected MO callbacks to normalize to received status.');

    $code_only = $normalizer->normalize_callback('nth_fr_one_off_jplay', 'mo', [
        'msisdn' => 'enc-123',
        'businessNumber' => '84072',
        'content' => 'JPLAY txn_alias_12345678',
        'operatorCode' => '20820',
        'command' => 'deliverMessage',
    ]);

    kiwi_assert_same('20820', $code_only['operator_code'], 'Expected operator_code to normalize from operatorCode.');
    kiwi_assert_same('20820', $code_only['operator_name'], 'Expected operator_name to default to operator_code when only operatorCode is provided.');
});

kiwi_run_test('Kiwi_Nth_Premium_Sms_Normalizer resolves operator_name from operator_code via service mapping', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'shortcode' => '84072',
                'keyword' => 'JPLAY',
                'operator_nwc_map' => [
                    '20820' => '20820',
                    'Orange' => '20801',
                    'Bouygues Telecom' => '20820',
                ],
            ],
        ]
    );
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);

    $normalized = $normalizer->normalize_callback('nth_fr_one_off_jplay', 'mo', [
        'msisdn' => 'enc-operator-map-1',
        'businessNumber' => '84072',
        'content' => 'JPLAY txn_operator_map_1234',
        'operatorCode' => '20820',
        'command' => 'deliverMessage',
    ]);

    kiwi_assert_same('20820', $normalized['operator_code'], 'Expected operator_code to remain the canonical normalized operator code.');
    kiwi_assert_same(
        'Bouygues Telecom',
        $normalized['operator_name'],
        'Expected operator_name to be resolved from service mapping when only operatorCode is provided.'
    );
});

kiwi_run_test('Kiwi_Nth_Premium_Sms_Normalizer maps messageRef and numeric messageStatus=2 as confirmed delivery', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'shortcode' => '84072',
                'keyword' => 'JPLAY',
            ],
        ]
    );
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);

    $normalized = $normalizer->normalize_callback('nth_fr_one_off_jplay', 'notification', [
        'command' => 'deliverReport',
        'businessNumber' => '84072',
        'messageStatus' => '2',
        'messageRef' => 'txn_51592fc6c87f44ec910f06a25859806a-42a4f301a24e',
        'sessionId' => '9292CHA1571000000000',
        'messageId' => 'msg-200',
    ]);

    kiwi_assert_same(
        'txn_51592fc6c87f44ec910f06a25859806a-42a4f301a24e',
        $normalized['external_request_id'] ?? '',
        'Expected deliverReport messageRef to normalize as external_request_id for transaction correlation.'
    );
    kiwi_assert_same('delivered', $normalized['status'] ?? '', 'Expected messageStatus=2 to normalize as delivered.');
    kiwi_assert_true(!empty($normalized['is_terminal']), 'Expected messageStatus=2 notification to be terminal.');
    kiwi_assert_true(!empty($normalized['is_success']), 'Expected messageStatus=2 notification to be successful.');
});

kiwi_run_test('Kiwi_Nth_Premium_Sms_Normalizer maps intermediate and failure numeric messageStatus values', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'shortcode' => '84072',
                'keyword' => 'JPLAY',
            ],
        ]
    );
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);

    $intermediate = $normalizer->normalize_callback('nth_fr_one_off_jplay', 'notification', [
        'command' => 'deliverReport',
        'businessNumber' => '84072',
        'messageStatus' => '1',
        'messageRef' => 'txn_intermediate_1',
    ]);
    kiwi_assert_same('intermediate', $intermediate['status'] ?? '', 'Expected messageStatus=1 to remain intermediate.');
    kiwi_assert_true(empty($intermediate['is_terminal']), 'Expected messageStatus=1 not to be terminal.');
    kiwi_assert_true(empty($intermediate['is_success']), 'Expected messageStatus=1 not to be successful.');

    $failed = $normalizer->normalize_callback('nth_fr_one_off_jplay', 'notification', [
        'command' => 'deliverReport',
        'businessNumber' => '84072',
        'messageStatus' => '-21',
        'messageRef' => 'txn_failed_1',
    ]);
    kiwi_assert_same('failed', $failed['status'] ?? '', 'Expected messageStatus=-21 to map to failed.');
    kiwi_assert_true(!empty($failed['is_terminal']), 'Expected messageStatus=-21 to be terminal.');
    kiwi_assert_true(empty($failed['is_success']), 'Expected messageStatus=-21 to be unsuccessful.');
});

kiwi_run_test('Kiwi_Nth_Client rejects submit requests with missing required routing data before transport', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'mt_submission_url' => 'http://mobilegate58.nth.ch:9099',
                'username' => 'user',
                'password' => 'pass',
                'shortcode' => '84072',
                'price' => 450,
            ],
        ]
    );
    $client = new Kiwi_Nth_Client($config);

    $response = $client->submit_message('nth_fr_one_off_jplay', [
        'flow_reference' => 'flow-1',
        'session_id' => 'session-client-validation-1',
        'subscriber_reference' => 'enc-123',
        'shortcode' => '84072',
        'message_text' => 'Merci pour votre achat.',
        'price' => 450,
        'nwc' => '',
    ]);

    kiwi_assert_true(!$response['success'], 'Expected missing required NTH routing data to fail before any HTTP request is made.');
    kiwi_assert_true(strpos($response['error'], 'nwc') !== false, 'Expected the client validation error to call out the missing NWC field.');
});

kiwi_run_test('Kiwi_Nth_Client rejects legacy submit template keys in strict mode', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'mt_submission_url' => 'http://mobilegate58.nth.ch:9099',
                'username' => 'user',
                'password' => 'pass',
                'shortcode' => '84072',
                'price' => 450,
                'submit_message_body' => [
                    'operation' => 'submitMessage',
                    'username' => '{username}',
                    'password' => '{password}',
                    'msisdn' => '{subscriber_reference}',
                    'shortcode' => '{shortcode}',
                    'message' => '{message_text}',
                    'price' => '{price}',
                    'nwc' => '{nwc}',
                    'encoding' => '{encoding}',
                    'reference' => '{flow_reference}',
                ],
            ],
        ]
    );
    $client = new Kiwi_Nth_Client($config);

    $response = $client->submit_message('nth_fr_one_off_jplay', [
        'flow_reference' => 'txn_1bdac2bce5f0459a-342be0d2db7e',
        'session_id' => 'session-client-validation-2',
        'subscriber_reference' => '1000000111043765',
        'shortcode' => '84072',
        'message_text' => 'Merci',
        'price' => 450,
        'nwc' => '20820',
    ]);

    kiwi_assert_true(!$response['success'], 'Expected strict submit template validation to reject legacy key aliases.');
    kiwi_assert_true(
        strpos((string) ($response['error'] ?? ''), 'Unsupported legacy NTH submitMessage template keys') !== false,
        'Expected strict validation error to explain that legacy keys are no longer accepted.'
    );
});

kiwi_run_test('Kiwi_Nth_Client requires sessionId in strict submit template contract', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'mt_submission_url' => 'http://mobilegate58.nth.ch:9099',
                'username' => 'user',
                'password' => 'pass',
                'shortcode' => '84072',
                'price' => 450,
                'submit_message_body' => [
                    'command' => 'submitMessage',
                    'username' => '{username}',
                    'password' => '{password}',
                    'msisdn' => '{subscriber_reference}',
                    'businessNumber' => '{shortcode}',
                    'content' => '{message_text}',
                    'price' => '{price}',
                    'nwc' => '{nwc}',
                    'encoding' => '{encoding}',
                    'messageRef' => '{flow_reference}',
                ],
            ],
        ]
    );
    $client = new Kiwi_Nth_Client($config);

    $response = $client->submit_message('nth_fr_one_off_jplay', [
        'flow_reference' => 'txn_1bdac2bce5f0459a-342be0d2db7e',
        'session_id' => 'session-contract-1',
        'subscriber_reference' => '1000000111043765',
        'shortcode' => '84072',
        'message_text' => 'Merci',
        'price' => 450,
        'nwc' => '20820',
    ]);

    kiwi_assert_true(!$response['success'], 'Expected strict submit template validation to require sessionId key.');
    kiwi_assert_true(
        strpos((string) ($response['error'] ?? ''), 'sessionId') !== false,
        'Expected strict validation error to call out missing sessionId key in submit_message_body template.'
    );
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Signal_Repository keeps idempotent source-event rows and per-service count scope', function (): void {
    $repository = new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository();

    $first_insert = $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_a',
        'flow_key' => 'flow-a',
        'source_event_key' => 'event-1',
        'identity_type' => 'subscriber',
        'identity_value' => 'enc-1',
        'occurred_at' => '2026-04-01 10:00:00',
        'count_1h' => 1,
        'count_24h' => 1,
        'count_total' => 1,
        'is_soft_flag' => false,
    ]);
    $second_insert = $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_a',
        'flow_key' => 'flow-a',
        'source_event_key' => 'event-2',
        'identity_type' => 'subscriber',
        'identity_value' => 'enc-1',
        'occurred_at' => '2026-04-01 10:20:00',
        'count_1h' => 2,
        'count_24h' => 2,
        'count_total' => 2,
        'is_soft_flag' => false,
    ]);
    $duplicate_insert = $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_a',
        'flow_key' => 'flow-a',
        'source_event_key' => 'event-2',
        'identity_type' => 'subscriber',
        'identity_value' => 'enc-1',
        'occurred_at' => '2026-04-01 10:20:00',
    ]);
    $other_service_insert = $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_b',
        'flow_key' => 'flow-b',
        'source_event_key' => 'event-3',
        'identity_type' => 'subscriber',
        'identity_value' => 'enc-1',
        'occurred_at' => '2026-04-01 10:25:00',
        'count_1h' => 1,
        'count_24h' => 1,
        'count_total' => 1,
        'is_soft_flag' => false,
    ]);

    $snapshot = $repository->build_counts_snapshot(
        'svc_a',
        'subscriber',
        'enc-1',
        '2026-04-01 10:30:00'
    );

    kiwi_assert_true(!empty($first_insert['inserted']), 'Expected first source-event row to be inserted.');
    kiwi_assert_true(!empty($second_insert['inserted']), 'Expected second unique source-event row to be inserted.');
    kiwi_assert_true(empty($duplicate_insert['inserted']), 'Expected duplicate source_event_key + identity_type insert to be ignored.');
    kiwi_assert_true(!empty($other_service_insert['inserted']), 'Expected same identity in another service_key to be insertable.');
    kiwi_assert_same(3, count($repository->rows), 'Expected repository to persist only unique source-event rows.');
    kiwi_assert_same(3, (int) ($snapshot['count_1h'] ?? 0), 'Expected 1h snapshot count to stay scoped to service_key.');
    kiwi_assert_same(3, (int) ($snapshot['count_24h'] ?? 0), 'Expected 24h snapshot count to stay scoped to service_key.');
    kiwi_assert_same(3, (int) ($snapshot['count_total'] ?? 0), 'Expected total snapshot count to stay scoped to service_key.');
});

kiwi_run_test('Kiwi_Premium_Sms_Landing_Engagement_Repository upserts by landing/session and preserves first timestamps', function (): void {
    $repository = new Kiwi_Test_Premium_Sms_Landing_Engagement_Repository();

    $first_load = $repository->upsert_event([
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-1',
        'service_key' => 'nth_fr_one_off_jplay',
        'provider_key' => 'nth',
        'flow_key' => 'nth-fr-one-off',
    ], 'page_loaded', '2026-04-01 12:00:01');
    $second_load = $repository->upsert_event([
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-1',
    ], 'page_loaded', '2026-04-01 12:00:05');
    $first_click = $repository->upsert_event([
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-1',
    ], 'cta_click', '2026-04-01 12:00:07');
    $second_click = $repository->upsert_event([
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-1',
    ], 'cta_click', '2026-04-01 12:00:09');

    kiwi_assert_same(1, count($repository->rows), 'Expected one engagement row per landing/session pair.');
    kiwi_assert_same('2026-04-01 12:00:01', (string) ($first_load['page_loaded_at'] ?? ''), 'Expected first page_loaded timestamp to be persisted.');
    kiwi_assert_same('2026-04-01 12:00:01', (string) ($second_load['page_loaded_at'] ?? ''), 'Expected repeated page_loaded events not to overwrite initial page_loaded_at.');
    kiwi_assert_same('2026-04-01 12:00:07', (string) ($first_click['first_cta_click_at'] ?? ''), 'Expected first cta click timestamp to be recorded once.');
    kiwi_assert_same('2026-04-01 12:00:09', (string) ($second_click['last_cta_click_at'] ?? ''), 'Expected last cta click timestamp to advance with later clicks.');
    kiwi_assert_same(2, (int) ($second_click['cta_click_count'] ?? 0), 'Expected cta click count to increment on each click event.');
});

kiwi_run_test('Kiwi_Premium_Sms_Mo_Engagement_Evaluator_Service flags unknown links and fast MO deltas', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [],
        [],
        180,
        3,
        6,
        'observe',
        true,
        true,
        1
    );
    $click_repository = new Kiwi_Test_Click_Attribution_Repository();
    $engagement_repository = new Kiwi_Test_Premium_Sms_Landing_Engagement_Repository();
    $evaluator = new Kiwi_Premium_Sms_Mo_Engagement_Evaluator_Service(
        $config,
        $click_repository,
        $engagement_repository
    );

    $unknown = $evaluator->evaluate_inbound_mo([
        'service_key' => 'nth_fr_one_off_jplay',
        'occurred_at' => '2026-04-01 12:00:00',
        'transaction_id' => 'txn_unknown_11111111',
    ]);

    $capture = $click_repository->upsert_capture([
        'tracking_token' => 'TOK1234567890FRA',
        'service_key' => 'nth_fr_one_off_jplay',
        'landing_page_key' => 'lp2-fr',
        'session_ref' => 'sess-fast-1',
        'transaction_id' => 'txn_fast_12345678',
        'click_id' => 'aff-fast-1',
        'expires_at' => '2026-04-03 12:00:00',
    ]);
    $engagement_repository->upsert_event([
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-fast-1',
        'service_key' => 'nth_fr_one_off_jplay',
    ], 'page_loaded', '2026-04-01 12:00:00');
    $engagement_repository->upsert_event([
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-fast-1',
        'service_key' => 'nth_fr_one_off_jplay',
    ], 'cta_click', '2026-04-01 12:00:00');

    $fast = $evaluator->evaluate_inbound_mo([
        'service_key' => 'nth_fr_one_off_jplay',
        'occurred_at' => '2026-04-01 12:00:00',
        'transaction_id' => (string) ($capture['transaction_id'] ?? ''),
    ]);

    kiwi_assert_true(in_array('unknown_link', $unknown['reasons'] ?? [], true), 'Expected missing attribution/engagement linkage to be flagged as unknown_link.');
    kiwi_assert_true(($unknown['has_soft_flag'] ?? false) === true, 'Expected unknown link evaluation to be soft-flagged.');
    kiwi_assert_true(in_array('mo_too_fast_after_load<1s', $fast['reasons'] ?? [], true), 'Expected sub-1s MO delta to be flagged as suspicious.');
    kiwi_assert_true(($fast['linked'] ?? false) === true, 'Expected evaluator to treat matched attribution + engagement rows as linked.');
    kiwi_assert_same('aff-fast-1', (string) ($fast['click_id'] ?? ''), 'Expected evaluator to expose resolved click_id from linked attribution.');
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Monitor_Service merges engagement reasons and enables block mode when configured', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [],
        [],
        180,
        3,
        6,
        'block',
        true,
        true,
        1
    );
    $fraud_repository = new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository();
    $click_repository = new Kiwi_Test_Click_Attribution_Repository();
    $engagement_repository = new Kiwi_Test_Premium_Sms_Landing_Engagement_Repository();
    $evaluator = new Kiwi_Premium_Sms_Mo_Engagement_Evaluator_Service(
        $config,
        $click_repository,
        $engagement_repository
    );
    $service = new Kiwi_Premium_Sms_Fraud_Monitor_Service($config, $fraud_repository, $evaluator);

    $capture = $click_repository->upsert_capture([
        'tracking_token' => 'TOK1234567890FRB',
        'service_key' => 'svc_block',
        'landing_page_key' => 'lp2-fr',
        'session_ref' => 'sess-block-1',
        'transaction_id' => 'txn_block_12345678',
        'click_id' => 'aff-click-block-1',
        'pid' => 'affiliate_p42',
        'expires_at' => '2026-04-03 12:00:00',
    ]);
    $engagement_repository->upsert_event([
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-block-1',
        'service_key' => 'svc_block',
        'provider_key' => 'nth',
        'flow_key' => 'nth-fr-one-off',
    ], 'page_loaded', '2026-04-01 12:00:00');
    $engagement_repository->upsert_event([
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-block-1',
        'service_key' => 'svc_block',
    ], 'cta_click', '2026-04-01 12:00:00');

    $result = $service->capture_inbound_mo([
        'provider_key' => 'nth',
        'service_key' => 'svc_block',
        'flow_key' => 'nth-fr-one-off',
        'country' => 'FR',
        'source_event_key' => 'event-block-1',
        'occurred_at' => '2026-04-01 12:00:00',
        'subscriber_reference' => 'enc-block-1',
        'session_ref' => 'sess-block-1',
        'transaction_id' => (string) ($capture['transaction_id'] ?? ''),
    ]);

    $first_row = $fraud_repository->rows[0] ?? [];

    kiwi_assert_true(($result['has_soft_flag'] ?? false) === true, 'Expected engagement-based suspicious delta to set soft-flag.');
    kiwi_assert_true(($result['should_block'] ?? false) === true, 'Expected block mode to request blocking when engagement reasons are present.');
    kiwi_assert_same('affiliate_p42', (string) ($first_row['pid'] ?? ''), 'Expected fraud signal rows to snapshot pid from linked attribution context.');
    kiwi_assert_same('aff-click-block-1', (string) ($first_row['click_id'] ?? ''), 'Expected fraud signal rows to snapshot click_id from linked attribution context.');
    kiwi_assert_contains('mo_too_fast_after_load<1s', (string) ($first_row['soft_flag_reason'] ?? ''), 'Expected persisted soft_flag_reason to include engagement rule trigger.');
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Monitor_Service records both identities and soft-flags when either threshold is exceeded', function (): void {
    $repository = new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository();
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [],
        [],
        180,
        3,
        6
    );
    $service = new Kiwi_Premium_Sms_Fraud_Monitor_Service($config, $repository);

    $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_a',
        'flow_key' => 'flow-a',
        'source_event_key' => 'seed-1',
        'identity_type' => 'session',
        'identity_value' => 'session-abc',
        'occurred_at' => '2026-04-01 11:00:00',
        'count_1h' => 1,
        'count_24h' => 1,
        'count_total' => 1,
    ]);
    $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_a',
        'flow_key' => 'flow-a',
        'source_event_key' => 'seed-2',
        'identity_type' => 'session',
        'identity_value' => 'session-abc',
        'occurred_at' => '2026-04-01 11:10:00',
        'count_1h' => 2,
        'count_24h' => 2,
        'count_total' => 2,
    ]);

    $result = $service->capture_inbound_mo([
        'provider_key' => 'nth',
        'service_key' => 'svc_a',
        'flow_key' => 'flow-a',
        'country' => 'FR',
        'source_event_key' => 'event-capture-1',
        'occurred_at' => '2026-04-01 11:20:00',
        'pid' => 'pid-direct-a',
        'click_id' => 'click-direct-a',
        'subscriber_reference' => 'enc-123',
        'session_ref' => 'session-abc',
    ]);

    kiwi_assert_same(2, count($result['signals'] ?? []), 'Expected fraud monitor to persist one row per available identity type.');
    kiwi_assert_true(!empty($result['has_soft_flag']), 'Expected either-key threshold logic to mark the capture result as soft-flagged.');
    kiwi_assert_same(['session'], $result['soft_flagged_identity_types'] ?? [], 'Expected session identity to carry the soft flag in this scenario.');
    kiwi_assert_same(4, count($repository->rows), 'Expected seed rows plus both new identity rows to be persisted.');
    kiwi_assert_same('pid-direct-a', (string) ($repository->rows[2]['pid'] ?? ''), 'Expected fraud signal rows to include pid from inbound signal context.');
    kiwi_assert_same('click-direct-a', (string) ($repository->rows[2]['click_id'] ?? ''), 'Expected fraud signal rows to include click_id from inbound signal context.');
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Monitor_Service skips empty identities and records available identity safely', function (): void {
    $repository = new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository();
    $service = new Kiwi_Premium_Sms_Fraud_Monitor_Service(new Kiwi_Test_Config(), $repository);

    $result = $service->capture_inbound_mo([
        'provider_key' => 'nth',
        'service_key' => 'svc_a',
        'flow_key' => 'flow-a',
        'country' => 'FR',
        'source_event_key' => 'event-capture-2',
        'occurred_at' => '2026-04-01 12:00:00',
        'subscriber_reference' => '',
        'session_ref' => 'session-only-1',
    ]);

    kiwi_assert_same(1, count($result['signals'] ?? []), 'Expected only the non-empty identity to be recorded.');
    kiwi_assert_same('session', $result['signals'][0]['identity_type'] ?? '', 'Expected session identity row to be stored when subscriber is empty.');
    kiwi_assert_same(1, count($repository->rows), 'Expected repository to store exactly one signal row when one identity is available.');
});

kiwi_run_test('Kiwi_Nth_Fr_One_Off_Service avoids duplicate MT submission for duplicate MO callbacks', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'shortcode' => '84072',
                'keyword' => 'JPLAY',
                'price' => 450,
                'currency' => 'EUR',
                'operator_nwc_map' => [
                    '20801' => '20801',
                ],
                'landing_price_label' => '4,50 EUR par SMS + prix d\'un SMS',
            ],
        ]
    );
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);
    $client = new Kiwi_Test_Nth_Client([
        [
            'success' => true,
            'status_code' => 200,
            'body' => '<response><message_id>msg-1</message_id><status>submitted</status></response>',
            'request' => [],
            'error' => '',
        ],
    ]);
    $event_repository = new Kiwi_Test_Nth_Event_Repository();
    $transaction_repository = new Kiwi_Test_Nth_Flow_Transaction_Repository();
    $sales_recorder = new Kiwi_Test_Shared_Sales_Recorder();
    $service = new Kiwi_Nth_Fr_One_Off_Service(
        $config,
        $normalizer,
        $client,
        $event_repository,
        $transaction_repository,
        $sales_recorder
    );

    $payload = [
        'Encrypted_MSISDN' => 'enc-123',
        'Business_Number' => '84072',
        'Message' => 'JPLAY',
        'NWC' => '20801',
        'Operator' => 'Orange',
        'session_id' => 'session-dup-1',
    ];

    $first = $service->handle_inbound_mo('nth_fr_one_off_jplay', $payload);
    $second = $service->handle_inbound_mo('nth_fr_one_off_jplay', $payload);

    kiwi_assert_true($first['success'], 'Expected the first MO callback to trigger one MT submission.');
    kiwi_assert_same(1, count($client->calls), 'Expected duplicate MO callbacks to avoid a second MT submission.');
    kiwi_assert_same(
        'session-dup-1',
        (string) ($client->calls[0]['transaction']['session_id'] ?? ''),
        'Expected FR one-off MT submit payload to propagate MO session_id as sessionId input.'
    );
    kiwi_assert_same(
        'MyJoyplay kiwi mobile GmbH 4,5€ + prix SMS(ce n\'est pas un abonnement) https://mcontentfr.joy-play.com Problème? plainte.84072@allopass.com',
        (string) ($client->calls[0]['transaction']['message_text'] ?? ''),
        'Expected FR one-off default MT content wording to match the configured compliance text.'
    );
    kiwi_assert_same('Duplicate MO callback ignored.', $second['message'], 'Expected the second identical MO callback to be treated as a duplicate event.');
});

kiwi_run_test('Kiwi_Nth_Fr_One_Off_Service captures fraud signal once for deduped MO callbacks without changing MT behavior', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'shortcode' => '84072',
                'keyword' => 'JPLAY',
                'price' => 450,
                'currency' => 'EUR',
                'operator_nwc_map' => [
                    '20801' => '20801',
                ],
            ],
        ]
    );
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);
    $client = new Kiwi_Test_Nth_Client([
        [
            'success' => true,
            'status_code' => 200,
            'body' => '<response><message_id>msg-fraud-1</message_id><status>submitted</status></response>',
            'request' => [],
            'error' => '',
        ],
    ]);
    $event_repository = new Kiwi_Test_Nth_Event_Repository();
    $transaction_repository = new Kiwi_Test_Nth_Flow_Transaction_Repository();
    $sales_recorder = new Kiwi_Test_Shared_Sales_Recorder();
    $fraud_monitor = new Kiwi_Test_Premium_Sms_Fraud_Monitor_Service();
    $service = new Kiwi_Nth_Fr_One_Off_Service(
        $config,
        $normalizer,
        $client,
        $event_repository,
        $transaction_repository,
        $sales_recorder,
        null,
        $fraud_monitor
    );

    $payload = [
        'Encrypted_MSISDN' => 'enc-fraud-123',
        'Business_Number' => '84072',
        'Message' => 'JPLAY',
        'NWC' => '20801',
        'Operator' => 'Orange',
        'session_id' => 'session-fraud-1',
    ];

    $service->handle_inbound_mo('nth_fr_one_off_jplay', $payload);
    $service->handle_inbound_mo('nth_fr_one_off_jplay', $payload);

    kiwi_assert_same(1, count($fraud_monitor->calls), 'Expected fraud capture to run only for the first inserted MO event.');
    kiwi_assert_same('enc-fraud-123', (string) ($fraud_monitor->calls[0]['subscriber_reference'] ?? ''), 'Expected fraud capture context to include subscriber identity.');
    kiwi_assert_same('session-fraud-1', (string) ($fraud_monitor->calls[0]['session_ref'] ?? ''), 'Expected fraud capture context to include session identity.');
    kiwi_assert_same(1, count($client->calls), 'Expected MT submission behavior to remain unchanged by fraud capture wiring.');
});

kiwi_run_test('Kiwi_Nth_Fr_One_Off_Service blocks MT submission when fraud monitor requests engagement blocking', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'shortcode' => '84072',
                'keyword' => 'JPLAY',
                'price' => 450,
                'currency' => 'EUR',
                'operator_nwc_map' => [
                    '20801' => '20801',
                ],
            ],
        ],
        [],
        180,
        3,
        6,
        'block',
        true,
        true,
        1
    );
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);
    $client = new Kiwi_Test_Nth_Client([
        [
            'success' => true,
            'status_code' => 200,
            'body' => '<response><message_id>msg-should-not-send</message_id><status>submitted</status></response>',
            'request' => [],
            'error' => '',
        ],
    ]);
    $event_repository = new Kiwi_Test_Nth_Event_Repository();
    $transaction_repository = new Kiwi_Test_Nth_Flow_Transaction_Repository();
    $sales_recorder = new Kiwi_Test_Shared_Sales_Recorder();
    $fraud_monitor = new Kiwi_Test_Premium_Sms_Fraud_Monitor_Service([
        'signals' => [],
        'has_soft_flag' => true,
        'soft_flagged_identity_types' => ['session'],
        'engagement' => [],
        'engagement_soft_flag_reasons' => ['unknown_link'],
        'should_block' => true,
    ]);
    $service = new Kiwi_Nth_Fr_One_Off_Service(
        $config,
        $normalizer,
        $client,
        $event_repository,
        $transaction_repository,
        $sales_recorder,
        null,
        $fraud_monitor
    );

    $result = $service->handle_inbound_mo('nth_fr_one_off_jplay', [
        'Encrypted_MSISDN' => 'enc-fraud-block-1',
        'Business_Number' => '84072',
        'Message' => 'JPLAY',
        'NWC' => '20801',
        'Operator' => 'Orange',
        'session_id' => 'session-fraud-block-1',
    ]);

    kiwi_assert_true(!$result['success'], 'Expected fraud blocking mode to stop MO processing before MT submission.');
    kiwi_assert_same('fraud_engagement_blocked', (string) ($result['message'] ?? ''), 'Expected fraud-based block status to be surfaced to caller.');
    kiwi_assert_same(0, count($client->calls), 'Expected fraud block path to skip downstream MT submission call.');
    kiwi_assert_same('fraud_engagement_blocked', (string) ($result['transaction']['current_status'] ?? ''), 'Expected blocked transaction to persist fraud_engagement_blocked status.');
});

kiwi_run_test('Kiwi_Nth_Fr_One_Off_Service blocks MT submission when FR routing data is missing', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'shortcode' => '84072',
                'keyword' => 'JPLAY',
                'price' => 450,
                'currency' => 'EUR',
                'operator_nwc_map' => [],
                'landing_price_label' => '4,50 EUR par SMS + prix d\'un SMS',
            ],
        ]
    );
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);
    $client = new Kiwi_Test_Nth_Client([]);
    $event_repository = new Kiwi_Test_Nth_Event_Repository();
    $transaction_repository = new Kiwi_Test_Nth_Flow_Transaction_Repository();
    $sales_recorder = new Kiwi_Test_Shared_Sales_Recorder();
    $service = new Kiwi_Nth_Fr_One_Off_Service(
        $config,
        $normalizer,
        $client,
        $event_repository,
        $transaction_repository,
        $sales_recorder
    );

    $result = $service->handle_inbound_mo('nth_fr_one_off_jplay', [
        'Encrypted_MSISDN' => 'enc-456',
        'Business_Number' => '84072',
        'Message' => 'JPLAY',
        'Operator' => 'Unknown',
        'session_id' => 'session-routing-missing',
    ]);

    kiwi_assert_true(!$result['success'], 'Expected FR MO processing to stop when no NWC can be resolved.');
    kiwi_assert_same('routing_data_missing', $result['message'], 'Expected the blocked MO result to expose the missing-routing status.');
    kiwi_assert_same(0, count($client->calls), 'Expected missing routing data to prevent any MT submission attempt.');
    kiwi_assert_same(2, count($event_repository->rows), 'Expected both the inbound MO and the internal blocked event to be persisted.');
    kiwi_assert_same('mt_submission_blocked', $result['submit_event']['event_type'], 'Expected the internal blocked event to be classified separately from provider callbacks.');
    kiwi_assert_same('routing_data_missing', $result['transaction']['current_status'], 'Expected the flow transaction to keep the blocked-routing status.');
    kiwi_assert_same(1, $result['transaction']['is_terminal'], 'Expected the blocked-routing transaction to be marked terminal.');
});

kiwi_run_test('Kiwi_Nth_Fr_One_Off_Service blocks MT submission when MO session_id is missing', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'shortcode' => '84072',
                'keyword' => 'JPLAY',
                'price' => 450,
                'currency' => 'EUR',
                'operator_nwc_map' => [
                    '20801' => '20801',
                ],
            ],
        ]
    );
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);
    $client = new Kiwi_Test_Nth_Client([]);
    $event_repository = new Kiwi_Test_Nth_Event_Repository();
    $transaction_repository = new Kiwi_Test_Nth_Flow_Transaction_Repository();
    $sales_recorder = new Kiwi_Test_Shared_Sales_Recorder();
    $service = new Kiwi_Nth_Fr_One_Off_Service(
        $config,
        $normalizer,
        $client,
        $event_repository,
        $transaction_repository,
        $sales_recorder
    );

    $result = $service->handle_inbound_mo('nth_fr_one_off_jplay', [
        'Encrypted_MSISDN' => 'enc-session-missing',
        'Business_Number' => '84072',
        'Message' => 'JPLAY',
        'NWC' => '20801',
        'Operator' => 'Orange',
    ]);

    kiwi_assert_true(!$result['success'], 'Expected FR MO processing to stop when session_id is missing.');
    kiwi_assert_same('session_id_missing', $result['message'], 'Expected missing MO session_id to expose session_id_missing status.');
    kiwi_assert_same(0, count($client->calls), 'Expected missing session_id to prevent MT submission attempt.');
    kiwi_assert_same('session_id_missing', $result['transaction']['current_status'], 'Expected transaction status to record missing session_id.');
});

kiwi_run_test('Kiwi_Nth_Fr_One_Off_Service correlates MO content transaction_id to attribution and confirmed postback flow', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'shortcode' => '84072',
                'keyword' => 'JPLAY*',
                'price' => 450,
                'currency' => 'EUR',
                'operator_nwc_map' => [
                    '20801' => '20801',
                ],
            ],
        ]
    );
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);
    $client = new Kiwi_Test_Nth_Client([
        [
            'success' => true,
            'status_code' => 200,
            'body' => '<response><message_id>msg-mo-bind</message_id><status>submitted</status></response>',
            'request' => [],
            'error' => '',
        ],
    ]);
    $event_repository = new Kiwi_Test_Nth_Event_Repository();
    $transaction_repository = new Kiwi_Test_Nth_Flow_Transaction_Repository();
    $sales_recorder = new Kiwi_Test_Shared_Sales_Recorder();
    $click_attribution_repository = new Kiwi_Test_Click_Attribution_Repository();
    $click_attribution_repository->upsert_capture([
        'tracking_token' => 'TOKMOCTRANSID001',
        'transaction_id' => 'txn_mocorr_12345678',
        'click_id' => 'aff:mo:txid:1',
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'expires_at' => '2026-04-05 12:00:00',
    ]);
    $postback_dispatcher = new Kiwi_Test_Affiliate_Postback_Dispatcher(
        new Kiwi_Test_Attribution_Config(
            'https://offers.example.test/postback?clickid={clickid}&secure={hash}',
            'secret-mo-corr'
        )
    );
    $conversion_resolver = new Kiwi_Conversion_Attribution_Resolver(
        $click_attribution_repository,
        $postback_dispatcher
    );
    $service = new Kiwi_Nth_Fr_One_Off_Service(
        $config,
        $normalizer,
        $client,
        $event_repository,
        $transaction_repository,
        $sales_recorder,
        $conversion_resolver
    );

    $service->handle_inbound_mo('nth_fr_one_off_jplay', [
        'Encrypted_MSISDN' => 'enc-mo-bind-1',
        'Business_Number' => '84072',
        'Message' => 'JPLAY+txn_mocorr_12345678',
        'keyword' => 'JPLAY',
        'NWC' => '20801',
        'Operator' => 'Orange',
        'session_id' => 'session-mo-bind-1',
    ]);

    kiwi_assert_same(1, count($client->calls), 'Expected MO with suffixed transaction_id to continue MT submission flow.');
    kiwi_assert_true(
        strpos((string) ($client->calls[0]['transaction']['flow_reference'] ?? ''), 'txn_mocorr_12345678-') === 0,
        'Expected provider flow_reference to be rooted in MO-supplied transaction_id.'
    );
    kiwi_assert_same(
        'session-mo-bind-1',
        (string) ($client->calls[0]['transaction']['session_id'] ?? ''),
        'Expected MO session_id to be propagated without overriding flow_reference/messageRef transaction correlation.'
    );
    $stored_meta = $transaction_repository->rows[1]['meta_json'] ?? null;
    $stored_meta = is_array($stored_meta) ? $stored_meta : [];
    kiwi_assert_same(
        'txn_mocorr_12345678',
        (string) ($stored_meta['attribution_transaction_id'] ?? ''),
        'Expected attribution_transaction_id to persist in transaction meta after submit updates.'
    );

    $notification = $service->handle_notification('nth_fr_one_off_jplay', [
        'message_id' => 'msg-mo-bind',
        'status' => 'delivered',
        'encrypted_msisdn' => 'enc-mo-bind-1',
        'shortcode' => '84072',
        'keyword' => 'JPLAY',
    ]);

    kiwi_assert_true($notification['success'], 'Expected delivery report to be accepted as confirmed success.');
    kiwi_assert_same(1, count($postback_dispatcher->calls), 'Expected confirmed notification to trigger one affiliate postback.');
    kiwi_assert_true(
        strpos($postback_dispatcher->calls[0], 'clickid=aff%3Amo%3Atxid%3A1') !== false,
        'Expected MO-correlated attribution to resolve original clickid for postback dispatch.'
    );
});

kiwi_run_test('Kiwi_Nth_Fr_One_Off_Service prefers MO sessionId over request_id for MT session binding', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'shortcode' => '84072',
                'keyword' => 'JPLAY',
                'price' => 450,
                'currency' => 'EUR',
                'operator_nwc_map' => [
                    '20801' => '20801',
                ],
            ],
        ]
    );
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);
    $client = new Kiwi_Test_Nth_Client([
        [
            'success' => true,
            'status_code' => 200,
            'body' => '<response><message_id>msg-session-priority</message_id><status>submitted</status></response>',
            'request' => [],
            'error' => '',
        ],
    ]);
    $event_repository = new Kiwi_Test_Nth_Event_Repository();
    $transaction_repository = new Kiwi_Test_Nth_Flow_Transaction_Repository();
    $sales_recorder = new Kiwi_Test_Shared_Sales_Recorder();
    $service = new Kiwi_Nth_Fr_One_Off_Service(
        $config,
        $normalizer,
        $client,
        $event_repository,
        $transaction_repository,
        $sales_recorder
    );

    $result = $service->handle_inbound_mo('nth_fr_one_off_jplay', [
        'Encrypted_MSISDN' => 'enc-session-priority-1',
        'Business_Number' => '84072',
        'Message' => 'JPLAY txn_priority_12345678',
        'NWC' => '20801',
        'Operator' => 'Orange',
        'request_id' => 'request-should-not-be-used',
        'sessionId' => 'session-priority-1',
    ]);

    kiwi_assert_true($result['success'], 'Expected MT submission flow to continue when MO sessionId is present.');
    kiwi_assert_same(1, count($client->calls), 'Expected one MT submit call for session-priority MO callback.');
    kiwi_assert_same(
        'session-priority-1',
        (string) ($client->calls[0]['transaction']['session_id'] ?? ''),
        'Expected submit transaction session_id to use MO sessionId instead of generic request_id.'
    );
    kiwi_assert_true(
        strpos((string) ($client->calls[0]['transaction']['flow_reference'] ?? ''), 'txn_priority_12345678-') === 0,
        'Expected flow_reference/messageRef txn correlation to remain txn-rooted.'
    );
});

kiwi_run_test('Kiwi_Nth_Fr_One_Off_Service uses txn-rooted flow references when MO transaction_id is missing', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'shortcode' => '84072',
                'keyword' => 'JPLAY',
                'price' => 450,
                'currency' => 'EUR',
                'operator_nwc_map' => [
                    '20801' => '20801',
                ],
            ],
        ]
    );
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);
    $client = new Kiwi_Test_Nth_Client([
        [
            'success' => true,
            'status_code' => 200,
            'body' => '<response><message_id>msg-fallback-1</message_id><status>submitted</status></response>',
            'request' => [],
            'error' => '',
        ],
    ]);
    $event_repository = new Kiwi_Test_Nth_Event_Repository();
    $transaction_repository = new Kiwi_Test_Nth_Flow_Transaction_Repository();
    $sales_recorder = new Kiwi_Test_Shared_Sales_Recorder();
    $service = new Kiwi_Nth_Fr_One_Off_Service(
        $config,
        $normalizer,
        $client,
        $event_repository,
        $transaction_repository,
        $sales_recorder
    );

    $result = $service->handle_inbound_mo('nth_fr_one_off_jplay', [
        'Encrypted_MSISDN' => 'enc-fallback-1',
        'Business_Number' => '84072',
        'Message' => 'JPLAY',
        'NWC' => '20801',
        'Operator' => 'Orange',
        'session_id' => 'session-fallback-1',
    ]);

    kiwi_assert_true($result['success'], 'Expected fallback MO path to continue through MT submit.');
    kiwi_assert_same(1, count($client->calls), 'Expected one MT submit attempt in fallback flow-reference mode.');
    $flow_reference = (string) ($client->calls[0]['transaction']['flow_reference'] ?? '');
    kiwi_assert_true(
        strpos($flow_reference, 'txn_') === 0,
        'Expected fallback flow_reference to use txn_ prefix to stay compatible with shared transaction_id correlation.'
    );
});

kiwi_run_test('Kiwi_Nth_Fr_One_Off_Service records a shared sale only on successful terminal notification', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'shortcode' => '84072',
                'keyword' => 'JPLAY',
                'price' => 450,
                'currency' => 'EUR',
                'operator_nwc_map' => [
                    '20801' => '20801',
                ],
            ],
        ]
    );
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);
    $client = new Kiwi_Test_Nth_Client([
        [
            'success' => true,
            'status_code' => 200,
            'body' => '<response><message_id>msg-1</message_id><status>submitted</status></response>',
            'request' => [],
            'error' => '',
        ],
    ]);
    $event_repository = new Kiwi_Test_Nth_Event_Repository();
    $transaction_repository = new Kiwi_Test_Nth_Flow_Transaction_Repository();
    $sales_recorder = new Kiwi_Test_Shared_Sales_Recorder();
    $click_attribution_repository = new Kiwi_Test_Click_Attribution_Repository();
    $capture = $click_attribution_repository->upsert_capture([
        'tracking_token' => 'TOK1234567890DDD',
        'click_id' => 'aff:flow:msg1',
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'session_ref' => 'session-42',
        'external_ref' => 'session-42',
        'expires_at' => '2026-04-05 12:00:00',
    ]);
    $postback_dispatcher = new Kiwi_Test_Affiliate_Postback_Dispatcher(
        new Kiwi_Test_Attribution_Config(
            'https://offers.example.test/postback?clickid={clickid}&secure={hash}',
            'secret-1'
        )
    );
    $conversion_resolver = new Kiwi_Conversion_Attribution_Resolver(
        $click_attribution_repository,
        $postback_dispatcher
    );
    $service = new Kiwi_Nth_Fr_One_Off_Service(
        $config,
        $normalizer,
        $client,
        $event_repository,
        $transaction_repository,
        $sales_recorder,
        $conversion_resolver
    );

    $service->handle_inbound_mo('nth_fr_one_off_jplay', [
        'Encrypted_MSISDN' => 'enc-123',
        'Business_Number' => '84072',
        'Message' => 'JPLAY',
        'NWC' => '20801',
        'Operator' => 'Orange',
        'session_id' => 'session-42',
    ]);
    $submitted_flow_reference = (string) ($client->calls[0]['transaction']['flow_reference'] ?? '');
    $expected_transaction_id = (string) ($capture['transaction_id'] ?? '');
    kiwi_assert_true(
        $expected_transaction_id !== '' && strpos($submitted_flow_reference, $expected_transaction_id . '-') === 0,
        'Expected NTH outbound flow reference to carry the shared attribution transaction_id prefix when attribution is resolved.'
    );

    $notification = $service->handle_notification('nth_fr_one_off_jplay', [
        'message_id' => 'msg-1',
        'status' => 'delivered',
        'encrypted_msisdn' => 'enc-123',
        'shortcode' => '84072',
        'keyword' => 'JPLAY',
    ]);

    kiwi_assert_true($notification['success'], 'Expected a delivered notification to be treated as successful.');
    kiwi_assert_same(1, count($sales_recorder->calls), 'Expected exactly one shared sale to be recorded on successful terminal delivery.');
    kiwi_assert_same(1, $notification['transaction']['sale_id'], 'Expected the flow transaction to reference the recorded shared sale.');
    kiwi_assert_same(1, count($postback_dispatcher->calls), 'Expected a confirmed conversion to trigger one outbound postback dispatch.');
    kiwi_assert_true(($notification['attribution']['dispatched'] ?? false), 'Expected successful notifications to produce a dispatched attribution postback result.');

    $duplicate = $service->handle_notification('nth_fr_one_off_jplay', [
        'message_id' => 'msg-1',
        'status' => 'delivered',
        'encrypted_msisdn' => 'enc-123',
        'shortcode' => '84072',
        'keyword' => 'JPLAY',
    ]);
    kiwi_assert_same('Duplicate notification callback ignored.', $duplicate['message'] ?? '', 'Expected duplicate notifications to keep being deduplicated.');
    kiwi_assert_same(1, count($postback_dispatcher->calls), 'Expected duplicate callbacks not to emit duplicate postbacks.');
});

kiwi_run_test('Kiwi_Nth_Fr_One_Off_Service can expose clear-sale event to sales drift when sales persistence fails', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'shortcode' => '84072',
                'keyword' => 'JPLAY',
                'price' => 450,
                'currency' => 'EUR',
                'operator_nwc_map' => [
                    '20801' => '20801',
                ],
            ],
        ]
    );
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);
    $client = new Kiwi_Test_Nth_Client([
        [
            'success' => true,
            'status_code' => 200,
            'body' => '<response><message_id>msg-sales-drift-1</message_id><status>submitted</status></response>',
            'request' => [],
            'error' => '',
        ],
    ]);
    $event_repository = new Kiwi_Test_Nth_Event_Repository();
    $transaction_repository = new Kiwi_Test_Nth_Flow_Transaction_Repository();
    $sales_repository = new Kiwi_Test_Failing_Sales_Repository();
    $sales_recorder = new Kiwi_Shared_Sales_Recorder($sales_repository);
    $service = new Kiwi_Nth_Fr_One_Off_Service(
        $config,
        $normalizer,
        $client,
        $event_repository,
        $transaction_repository,
        $sales_recorder
    );

    $service->handle_inbound_mo('nth_fr_one_off_jplay', [
        'Encrypted_MSISDN' => 'enc-sales-drift-1',
        'Business_Number' => '84072',
        'Message' => 'JPLAY',
        'NWC' => '20801',
        'Operator' => 'Orange',
        'session_id' => 'session-sales-drift-1',
    ]);

    $notification = $service->handle_notification('nth_fr_one_off_jplay', [
        'message_id' => 'msg-sales-drift-1',
        'status' => 'delivered',
        'encrypted_msisdn' => 'enc-sales-drift-1',
        'shortcode' => '84072',
        'keyword' => 'JPLAY',
    ]);

    $terminal_success_notifications = array_values(array_filter(
        $event_repository->rows,
        static function (array $row): bool {
            return (($row['event_type'] ?? '') === 'notification_callback')
                && !empty($row['is_terminal'])
                && !empty($row['is_success']);
        }
    ));

    kiwi_assert_true($notification['success'], 'Expected delivered NTH notification to remain accepted.');
    kiwi_assert_same(1, count($terminal_success_notifications), 'Expected one terminal successful notification event to be persisted.');
    kiwi_assert_same(1, count($sales_repository->upsert_calls), 'Expected one attempted wp_kiwi_sales upsert for the clear-sale signal.');
    kiwi_assert_same(0, count($sales_repository->rows), 'Expected no wp_kiwi_sales row persisted when repository upsert fails.');
    kiwi_assert_same(0, (int) ($notification['transaction']['sale_id'] ?? 0), 'Expected flow transaction to keep sale_id=0 when sales persistence fails.');
});

kiwi_run_test('Kiwi_Nth_Fr_One_Off_Service retries attribution postback on duplicate notification when first postback failed', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
                'shortcode' => '84072',
                'keyword' => 'JPLAY',
                'price' => 450,
                'currency' => 'EUR',
                'operator_nwc_map' => [
                    '20801' => '20801',
                ],
            ],
        ]
    );
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);
    $client = new Kiwi_Test_Nth_Client([
        [
            'success' => true,
            'status_code' => 200,
            'body' => '<response><message_id>msg-2</message_id><status>submitted</status></response>',
            'request' => [],
            'error' => '',
        ],
    ]);
    $event_repository = new Kiwi_Test_Nth_Event_Repository();
    $transaction_repository = new Kiwi_Test_Nth_Flow_Transaction_Repository();
    $sales_recorder = new Kiwi_Test_Shared_Sales_Recorder();
    $click_attribution_repository = new Kiwi_Test_Click_Attribution_Repository();
    $click_attribution_repository->upsert_capture([
        'tracking_token' => 'TOK1234567890EEE',
        'click_id' => 'aff:flow:msg2',
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'session_ref' => 'session-99',
        'external_ref' => 'session-99',
        'expires_at' => '2026-04-05 12:00:00',
    ]);
    $postback_dispatcher = new Kiwi_Test_Affiliate_Postback_Dispatcher(
        new Kiwi_Test_Attribution_Config(
            'https://offers.example.test/postback?clickid={clickid}&secure={hash}',
            'secret-2'
        )
    );
    $postback_dispatcher->responses = [
        [
            'status_code' => 500,
            'body' => 'ERR',
            'error' => '',
        ],
        [
            'status_code' => 200,
            'body' => 'OK',
            'error' => '',
        ],
    ];
    $conversion_resolver = new Kiwi_Conversion_Attribution_Resolver(
        $click_attribution_repository,
        $postback_dispatcher
    );
    $service = new Kiwi_Nth_Fr_One_Off_Service(
        $config,
        $normalizer,
        $client,
        $event_repository,
        $transaction_repository,
        $sales_recorder,
        $conversion_resolver
    );

    $service->handle_inbound_mo('nth_fr_one_off_jplay', [
        'Encrypted_MSISDN' => 'enc-999',
        'Business_Number' => '84072',
        'Message' => 'JPLAY',
        'NWC' => '20801',
        'Operator' => 'Orange',
        'session_id' => 'session-99',
    ]);

    $first = $service->handle_notification('nth_fr_one_off_jplay', [
        'message_id' => 'msg-2',
        'status' => 'delivered',
        'encrypted_msisdn' => 'enc-999',
        'shortcode' => '84072',
        'keyword' => 'JPLAY',
    ]);
    kiwi_assert_true($first['success'], 'Expected successful delivery notifications to remain accepted even when affiliate postback fails.');
    kiwi_assert_true(!($first['attribution']['dispatched'] ?? true), 'Expected first attribution postback attempt to be marked failed.');
    kiwi_assert_same(1, count($postback_dispatcher->calls), 'Expected first notification to trigger one postback attempt.');

    $duplicate = $service->handle_notification('nth_fr_one_off_jplay', [
        'message_id' => 'msg-2',
        'status' => 'delivered',
        'encrypted_msisdn' => 'enc-999',
        'shortcode' => '84072',
        'keyword' => 'JPLAY',
    ]);
    kiwi_assert_same('Duplicate notification callback ignored.', $duplicate['message'] ?? '', 'Expected duplicate callback event dedupe to remain unchanged.');
    kiwi_assert_true(($duplicate['attribution']['dispatched'] ?? false), 'Expected duplicate callback path to retry attribution when postback_sent_at is still empty.');
    kiwi_assert_same(2, count($postback_dispatcher->calls), 'Expected exactly one retry attempt from duplicate callback path.');

    $duplicate_after_success = $service->handle_notification('nth_fr_one_off_jplay', [
        'message_id' => 'msg-2',
        'status' => 'delivered',
        'encrypted_msisdn' => 'enc-999',
        'shortcode' => '84072',
        'keyword' => 'JPLAY',
    ]);
    kiwi_assert_same('postback_already_sent', $duplicate_after_success['attribution']['reason'] ?? '', 'Expected duplicate callback path to stop dispatching after postback_sent_at is populated.');
    kiwi_assert_same(2, count($postback_dispatcher->calls), 'Expected no additional postback dispatch after successful retry.');
});

kiwi_run_test('Kiwi_Plugin registers the existing hook surface and asset handles', function (): void {
    $GLOBALS['kiwi_test_hooks'] = [];
    $GLOBALS['kiwi_test_styles'] = [];
    $GLOBALS['kiwi_test_scripts'] = [];

    $plugin = new Kiwi_Plugin(dirname(__DIR__), 'https://example.test/plugin/');
    $plugin->register();

    kiwi_assert_same(1, count($GLOBALS['kiwi_test_hooks']['wp_enqueue_scripts'] ?? []), 'Expected one frontend asset hook.');

    $init_callbacks = $GLOBALS['kiwi_test_hooks']['init'] ?? [];
    kiwi_assert_same(
        [
            'handle_frontend_auth',
            'register_shortcodes',
            'register_rest_routes',
            'ensure_operator_lookup_callback_table',
            'ensure_refund_callback_table',
            'ensure_blacklist_callback_table',
            'ensure_nth_operational_tables',
            'ensure_click_attribution_table',
            'ensure_sales_table',
            'cleanup_expired_click_attributions',
            'maybe_export_hlr_results',
            'maybe_run_dimoco_test',
            'maybe_run_refund_batch_test',
        ],
        array_map(static function ($callback) {
            return is_array($callback) ? $callback[1] : null;
        }, $init_callbacks),
        'Expected init callbacks to stay registered in the current order.'
    );

    kiwi_assert_same(1, count($GLOBALS['kiwi_test_hooks']['template_redirect'] ?? []), 'Expected one landing-page routing hook.');
    kiwi_assert_same('maybe_render_landing_page', $GLOBALS['kiwi_test_hooks']['template_redirect'][0][1] ?? null, 'Expected the landing-page router hook to remain explicit.');

    $enqueue_callback = $GLOBALS['kiwi_test_hooks']['wp_enqueue_scripts'][0];
    $enqueue_callback();

    kiwi_assert_same(
        [
            'kiwi-backend-components',
            'kiwi-backend-forms',
            'kiwi-backend-tables',
            'kiwi-backend-frontend',
        ],
        array_column($GLOBALS['kiwi_test_styles'], 'handle'),
        'Expected stylesheet handles to include only shared reusable CSS bundles.'
    );
    kiwi_assert_same(
        [
            'kiwi-backend-core',
            'kiwi-backend-frontend',
        ],
        array_column($GLOBALS['kiwi_test_scripts'], 'handle'),
        'Expected the existing script handles to remain unchanged.'
    );
    kiwi_assert_same(
        ['kiwi-backend-components'],
        $GLOBALS['kiwi_test_styles'][1]['deps'],
        'Expected forms.css dependencies to remain unchanged.'
    );
    kiwi_assert_same(
        ['kiwi-backend-core'],
        $GLOBALS['kiwi_test_scripts'][1]['deps'],
        'Expected frontend.js dependencies to remain unchanged.'
    );
    kiwi_assert_true(
        is_int($GLOBALS['kiwi_test_styles'][0]['version']) && $GLOBALS['kiwi_test_styles'][0]['version'] > 0,
        'Expected stylesheet versioning to keep using filemtime().'
    );
});

kiwi_run_test('Kiwi_Plugin runs schema migrations once and persists schema version on first ensure call', function (): void {
    $GLOBALS['kiwi_test_options'] = [];

    $reflection = new ReflectionClass(Kiwi_Plugin::class);
    $schema_option = (string) $reflection->getConstant('DB_SCHEMA_VERSION_OPTION');
    $schema_version = (string) $reflection->getConstant('DB_SCHEMA_VERSION');

    $plugin = new Kiwi_Test_Plugin_Performance_Gates(dirname(__DIR__), 'https://example.test/plugin/');
    $plugin->ensure_operator_lookup_callback_table();
    $plugin->ensure_refund_callback_table();
    $plugin->ensure_click_attribution_table();

    kiwi_assert_same(1, $plugin->schema_migration_runs, 'Expected schema migrations to run once per runtime when schema is outdated.');
    kiwi_assert_same(
        $schema_version,
        $GLOBALS['kiwi_test_options'][$schema_option] ?? '',
        'Expected schema migrations to persist the installed schema version.'
    );
});

kiwi_run_test('Kiwi_Plugin skips schema migrations when installed version already matches', function (): void {
    $reflection = new ReflectionClass(Kiwi_Plugin::class);
    $schema_option = (string) $reflection->getConstant('DB_SCHEMA_VERSION_OPTION');
    $schema_version = (string) $reflection->getConstant('DB_SCHEMA_VERSION');

    $GLOBALS['kiwi_test_options'] = [
        $schema_option => $schema_version,
    ];

    $plugin = new Kiwi_Test_Plugin_Performance_Gates(dirname(__DIR__), 'https://example.test/plugin/');
    $plugin->ensure_sales_table();
    $plugin->ensure_nth_operational_tables();

    kiwi_assert_same(0, $plugin->schema_migration_runs, 'Expected schema migrations to be skipped when the stored schema version matches.');
});

kiwi_run_test('Kiwi_Plugin throttles click attribution cleanup with transient lock', function (): void {
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_options'] = [];

    $reflection = new ReflectionClass(Kiwi_Plugin::class);
    $cleanup_lock_key = (string) $reflection->getConstant('CLICK_ATTR_CLEANUP_LOCK_KEY');

    $plugin = new Kiwi_Test_Plugin_Performance_Gates(dirname(__DIR__), 'https://example.test/plugin/');
    $plugin->cleanup_limit = 321;

    $plugin->cleanup_expired_click_attributions();
    $plugin->cleanup_expired_click_attributions();

    kiwi_assert_same([321], $plugin->cleanup_limits, 'Expected cleanup execution to be throttled while lock is active.');
    kiwi_assert_same('1', $GLOBALS['kiwi_test_transients'][$cleanup_lock_key] ?? '', 'Expected cleanup throttle lock to be stored after first cleanup run.');
});

kiwi_run_test('Kiwi_Plugin exports HLR rows from the transient identified by batch_id', function (): void {
    $GLOBALS['kiwi_test_transients'] = [
        'kiwi_hlr_abc123' => [
            [
                'msisdn' => '306912345678',
                'provider' => 'lily',
            ],
        ],
    ];
    $_GET = [
        'kiwi_hlr_export' => '1',
        'batch_id' => 'kiwi_hlr_abc123',
    ];

    $plugin = new Kiwi_Test_Plugin(dirname(__DIR__), 'https://example.test/plugin/');
    $plugin->maybe_export_hlr_results();

    kiwi_assert_same(
        $GLOBALS['kiwi_test_transients']['kiwi_hlr_abc123'],
        $plugin->exported_rows,
        'Expected the export handler to stream the rows stored under the submitted batch_id.'
    );

    $_GET = [];
});

kiwi_run_test('Kiwi_Plugin exports stored asynchronous HLR callback rows when request_ids are available', function (): void {
    $GLOBALS['kiwi_test_transients'] = [
        'kiwi_hlr_async_export' => [
            'sync_rows' => [
                [
                    'msisdn' => '436641234567',
                    'provider' => 'dimoco',
                    'feature' => 'operator_lookup',
                    'request_id' => 'lookup-req-1',
                    'success' => false,
                    'status_code' => 200,
                    'api_status' => '',
                    'hlr_status' => '',
                    'operator' => '',
                    'messages' => ['Pending callback'],
                ],
            ],
            'request_ids' => ['lookup-req-1'],
        ],
    ];
    $_GET = [
        'kiwi_hlr_export' => '1',
        'batch_id' => 'kiwi_hlr_async_export',
    ];

    $plugin = new Kiwi_Test_Plugin(dirname(__DIR__), 'https://example.test/plugin/');
    $plugin->hlr_async_export_rows = [
        [
            'request_id' => 'lookup-req-1',
            'action' => 'operator-lookup',
            'action_status' => 0,
            'action_status_text' => 'success',
            'action_code' => '200',
            'detail' => 'Operator resolved',
            'detail_psp' => 'Callback persisted',
            'msisdn' => '436641234567',
            'operator' => 'A1',
        ],
    ];
    $plugin->maybe_export_hlr_results();

    kiwi_assert_same(
        [['lookup-req-1']],
        $plugin->hlr_async_export_request_ids,
        'Expected async HLR export to query callback rows using the stored request_ids.'
    );
    kiwi_assert_same(
        [
            [
                'msisdn' => '436641234567',
                'provider' => 'dimoco',
                'feature' => 'operator-lookup',
                'success' => true,
                'status_code' => '200',
                'api_status' => 'success',
                'hlr_status' => '',
                'operator' => 'A1',
                'messages' => ['Operator resolved', 'Callback persisted'],
            ],
        ],
        $plugin->exported_rows,
        'Expected async HLR export rows to take precedence over the original sync rows.'
    );

    $_GET = [];
});

kiwi_run_test('Kiwi_Plugin exports asynchronous HLR callback rows for every sync row in a multi-MSISDN batch', function (): void {
    $GLOBALS['kiwi_test_transients'] = [
        'kiwi_hlr_async_export_multi' => [
            'sync_rows' => [
                [
                    'msisdn' => '436641234567',
                    'provider' => 'dimoco',
                    'feature' => 'operator_lookup',
                    'request_id' => 'lookup-req-1',
                    'messages' => ['Pending callback'],
                ],
                [
                    'msisdn' => '436761234567',
                    'provider' => 'dimoco',
                    'feature' => 'operator_lookup',
                    'request_id' => 'lookup-req-2',
                    'messages' => ['Pending callback'],
                ],
            ],
            'request_ids' => ['lookup-req-1', 'lookup-req-2'],
        ],
    ];
    $_GET = [
        'kiwi_hlr_export' => '1',
        'batch_id' => 'kiwi_hlr_async_export_multi',
    ];

    $plugin = new Kiwi_Test_Plugin(dirname(__DIR__), 'https://example.test/plugin/');
    $plugin->hlr_async_export_rows = [
        [
            'request_id' => 'lookup-req-1',
            'action' => 'operator-lookup',
            'action_status' => 0,
            'action_status_text' => 'success',
            'action_code' => '200',
            'detail' => 'Operator resolved 1',
            'msisdn' => '436641234567',
            'operator' => 'A1',
        ],
    ];
    $plugin->hlr_async_export_rows_by_msisdn = [
        [
            'request_id' => 'lookup-req-1',
            'action' => 'operator-lookup',
            'action_status' => 0,
            'action_status_text' => 'success',
            'action_code' => '200',
            'detail' => 'Operator resolved 1',
            'msisdn' => '436641234567',
            'operator' => 'A1',
        ],
        [
            'request_id' => 'lookup-req-2',
            'action' => 'operator-lookup',
            'action_status' => 0,
            'action_status_text' => 'success',
            'action_code' => '200',
            'detail' => 'Operator resolved 2',
            'msisdn' => '436761234567',
            'operator' => 'Magenta',
        ],
    ];
    $plugin->maybe_export_hlr_results();

    kiwi_assert_same(
        [['lookup-req-1', 'lookup-req-2']],
        $plugin->hlr_async_export_request_ids,
        'Expected multi-MSISDN HLR export to query callback rows using all stored request_ids.'
    );
    kiwi_assert_same(
        [['436641234567', '436761234567']],
        $plugin->hlr_async_export_msisdns,
        'Expected multi-MSISDN HLR export to fall back to the submitted MSISDNs when request-id results are incomplete.'
    );
    kiwi_assert_same(
        [
            [
                'msisdn' => '436641234567',
                'provider' => 'dimoco',
                'feature' => 'operator-lookup',
                'success' => true,
                'status_code' => '200',
                'api_status' => 'success',
                'hlr_status' => '',
                'operator' => 'A1',
                'messages' => ['Operator resolved 1'],
            ],
            [
                'msisdn' => '436761234567',
                'provider' => 'dimoco',
                'feature' => 'operator-lookup',
                'success' => true,
                'status_code' => '200',
                'api_status' => 'success',
                'hlr_status' => '',
                'operator' => 'Magenta',
                'messages' => ['Operator resolved 2'],
            ],
        ],
        $plugin->exported_rows,
        'Expected multi-MSISDN async HLR export to resolve one callback row per submitted DIMOCO sync row.'
    );

    $_GET = [];
});

kiwi_run_test('Kiwi_Msisdn_Normalizer preserves the current normalization rules', function (): void {
    $normalizer = new Kiwi_Msisdn_Normalizer();

    kiwi_assert_same('306912345678', $normalizer->normalize('+30 691 234 5678'), 'Expected + and whitespace to be removed.');
    kiwi_assert_same('306912345678', $normalizer->normalize('006912345678'), 'Expected leading 00 to be normalized.');
    kiwi_assert_same('306912345678', $normalizer->normalize('6912345678'), 'Expected GR mobile shorthand to gain the 30 prefix.');
    kiwi_assert_same('', $normalizer->normalize(" \n\t "), 'Expected empty input to stay empty.');
});

kiwi_run_test('Kiwi_Routed_Operator_Lookup_Provider preserves prefix routing', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [
            '30' => ['provider' => 'lily'],
            '43' => ['provider' => 'dimoco', 'service_key' => 'at_service_getstronger'],
        ]
    );
    $lily_provider = new Kiwi_Test_Lily_Provider();
    $dimoco_provider = new Kiwi_Test_Dimoco_Provider();
    $provider = new Kiwi_Routed_Operator_Lookup_Provider($config, $lily_provider, $dimoco_provider);

    $lily_result = $provider->lookup('306912345678');
    $dimoco_result = $provider->lookup('436641234567');

    kiwi_assert_same('lily', $lily_result['provider'], 'Expected prefix 30 to stay routed to Lily.');
    kiwi_assert_same([['436641234567', 'at_service_getstronger']], $dimoco_provider->calls, 'Expected prefix 43 to stay routed to DIMOCO with the configured service key.');
    kiwi_assert_same('dimoco', $dimoco_result['provider'], 'Expected prefix 43 to keep returning the DIMOCO result.');
});

kiwi_run_test('Kiwi_Operator_Lookup_Batch_Service keeps deduping and retrying throttled lookups', function (): void {
    $provider = new Kiwi_Test_Lookup_Provider(
        [
            '306912345678' => [
                [
                    'success' => false,
                    'hlr_status' => 'REQUEST THROTTLED',
                    'messages' => ['Initial throttle'],
                    'msisdn' => '306912345678',
                ],
                [
                    'success' => true,
                    'hlr_status' => 'DELIVERED',
                    'messages' => ['Recovered'],
                    'msisdn' => '306912345678',
                ],
            ],
            '436641234567' => [
                [
                    'success' => true,
                    'hlr_status' => 'DELIVERED',
                    'messages' => [],
                    'msisdn' => '436641234567',
                ],
            ],
        ]
    );
    $normalizer = new Kiwi_Msisdn_Normalizer();
    $service = new Kiwi_Operator_Lookup_Service($provider, $normalizer);
    $config = new Kiwi_Test_Config(10, 0, 0);
    $batch_service = new Kiwi_Operator_Lookup_Batch_Service($service, $config, $normalizer);

    $result = $batch_service->process("+30 6912345678\n306912345678\n436641234567");

    kiwi_assert_same(3, $result['total_input'], 'Expected total input counting to stay unchanged.');
    kiwi_assert_same(2, $result['unique_input'], 'Expected normalized duplicate MSISDNs to stay deduplicated.');
    kiwi_assert_same(2, $result['processed'], 'Expected two unique MSISDNs to be processed.');
    kiwi_assert_same(
        ['306912345678', '306912345678', '436641234567'],
        $provider->calls,
        'Expected throttled MSISDNs to be retried once before moving on.'
    );
    kiwi_assert_same(
        ['Recovered', 'Retried after throttling.'],
        $result['results'][0]['messages'],
        'Expected the retry marker to stay appended after throttling.'
    );
});

kiwi_run_test('Kiwi_Lily_HLR marks 200+OK+SUCCESS as transport and business success', function (): void {
    $GLOBALS['kiwi_test_http_requests'] = [];
    $GLOBALS['kiwi_test_http_responses'] = [
        [
            'response' => ['code' => 200],
            'body' => wp_json_encode([
                'status' => 'OK',
                'messages' => [],
                'payload' => [
                    'hlrStatus' => 'SUCCESS',
                    'operator' => 'WIND',
                    'msisdn' => '306906854036',
                ],
            ]),
        ],
    ];

    $client = new Kiwi_Lily_Client(new Kiwi_Test_Config());
    $parser = new Kiwi_Lily_Response_Parser();
    $parsed = $parser->parse_hlr_response($client->hlr_lookup('306906854036'));

    kiwi_assert_true($parsed['http_success'], 'Expected HTTP 200 to be transport-successful for Lily.');
    kiwi_assert_true($parsed['success'], 'Expected status=OK and hlrStatus=SUCCESS to be business-successful for Lily.');
    kiwi_assert_same(200, $parsed['status_code'], 'Expected status code to be preserved on successful Lily parsing.');
});

kiwi_run_test('Kiwi_Lily_HLR marks 202+OK+OK as success under 2xx transport contract', function (): void {
    $GLOBALS['kiwi_test_http_requests'] = [];
    $GLOBALS['kiwi_test_http_responses'] = [
        [
            'response' => ['code' => 202],
            'body' => wp_json_encode([
                'status' => 'OK',
                'messages' => ['Accepted for processing'],
                'payload' => [
                    'hlrStatus' => 'OK',
                    'operator' => 'VODAFONE',
                    'msisdn' => '306906854037',
                ],
            ]),
        ],
    ];

    $client = new Kiwi_Lily_Client(new Kiwi_Test_Config());
    $parser = new Kiwi_Lily_Response_Parser();
    $parsed = $parser->parse_hlr_response($client->hlr_lookup('306906854037'));

    kiwi_assert_true($parsed['http_success'], 'Expected HTTP 202 to be transport-successful under the Lily 2xx contract.');
    kiwi_assert_true($parsed['success'], 'Expected status=OK and hlrStatus=OK to remain business-successful for Lily.');
    kiwi_assert_same(202, $parsed['status_code'], 'Expected non-200 2xx status code to be preserved for Lily.');
});

kiwi_run_test('Kiwi_Lily_HLR keeps 2xx transport success but fails business success for empty or invalid body', function (): void {
    $GLOBALS['kiwi_test_http_requests'] = [];
    $GLOBALS['kiwi_test_http_responses'] = [
        [
            'response' => ['code' => 204],
            'body' => '',
        ],
    ];

    $client = new Kiwi_Lily_Client(new Kiwi_Test_Config());
    $parser = new Kiwi_Lily_Response_Parser();
    $parsed = $parser->parse_hlr_response($client->hlr_lookup('306906854038'));

    kiwi_assert_true($parsed['http_success'], 'Expected HTTP 204 to stay transport-successful under the Lily 2xx contract.');
    kiwi_assert_true(!$parsed['success'], 'Expected empty JSON body to fail Lily business-success evaluation.');
    kiwi_assert_same(204, $parsed['status_code'], 'Expected status code preservation for 2xx responses with invalid body.');
    kiwi_assert_true(
        in_array('Missing or invalid JSON response body.', $parsed['messages'], true),
        'Expected parser reason to explain why business success failed for empty/invalid JSON.'
    );
    kiwi_assert_same('', $parsed['raw']['raw_body'] ?? null, 'Expected raw body context to be preserved even when empty.');
});

kiwi_run_test('Kiwi_Lily_HLR keeps non-2xx details in failure output', function (): void {
    $body = wp_json_encode([
        'status' => 'ERROR',
        'messages' => ['Rate limit exceeded'],
        'payload' => [
            'hlrStatus' => 'REQUEST THROTTLED',
            'operator' => '',
            'msisdn' => '306906854039',
        ],
    ]);

    $GLOBALS['kiwi_test_http_requests'] = [];
    $GLOBALS['kiwi_test_http_responses'] = [
        [
            'response' => ['code' => 429],
            'body' => $body,
        ],
    ];

    $client = new Kiwi_Lily_Client(new Kiwi_Test_Config());
    $parser = new Kiwi_Lily_Response_Parser();
    $parsed = $parser->parse_hlr_response($client->hlr_lookup('306906854039'));

    kiwi_assert_true(!$parsed['http_success'], 'Expected non-2xx responses to fail Lily transport success.');
    kiwi_assert_true(!$parsed['success'], 'Expected non-2xx responses to fail Lily business success.');
    kiwi_assert_same(429, $parsed['status_code'], 'Expected non-2xx status code to be preserved for Lily.');
    kiwi_assert_true(
        in_array('Rate limit exceeded', $parsed['messages'], true),
        'Expected provider messages from non-2xx bodies to be preserved.'
    );
    kiwi_assert_true(
        in_array('Transport HTTP status is non-2xx.', $parsed['messages'], true),
        'Expected parser reason to explain non-2xx transport failure.'
    );
    kiwi_assert_same($body, $parsed['raw']['raw_body'] ?? '', 'Expected non-2xx raw body context to be preserved.');
});

kiwi_run_test('Kiwi_Dimoco_Response_Parser preserves XML field extraction and status mapping', function (): void {
    $parser = new Kiwi_Dimoco_Response_Parser();
    $response = $parser->parse(
        [
            'success' => true,
            'status_code' => 200,
            'request' => ['action' => 'refund'],
            'xml' => <<<XML
<response>
    <action>refund</action>
    <action_result>
        <status>0</status>
        <code>200</code>
        <detail>Accepted</detail>
        <detail_psp>Queued</detail_psp>
    </action_result>
    <request_id>req-123</request_id>
    <reference>ref-456</reference>
    <payment_parameters>
        <order>order-789</order>
    </payment_parameters>
    <transactions>
        <transaction>
            <id>tx-111</id>
        </transaction>
    </transactions>
    <customer>
        <msisdn>436641234567</msisdn>
        <operator>A1</operator>
    </customer>
</response>
XML,
        ]
    );

    kiwi_assert_same(true, $response['success'], 'Expected DIMOCO status 0 to remain successful.');
    kiwi_assert_same('success', $response['action_status_text'], 'Expected status 0 to keep mapping to success.');
    kiwi_assert_same('refund', $response['feature'], 'Expected action names to keep driving the feature field.');
    kiwi_assert_same(['Accepted', 'Queued'], $response['messages'], 'Expected detail fields to keep feeding the messages array.');
    kiwi_assert_same('tx-111', $response['transaction_id'], 'Expected transaction IDs to keep being extracted from XML.');
});

kiwi_run_test('Kiwi_Dimoco_Callback_Verifier preserves HMAC digest validation', function (): void {
    $verifier = new Kiwi_Dimoco_Callback_Verifier();
    $xml = '<response><status>ok</status></response>';
    $secret = 'shared-secret';
    $digest = hash_hmac('sha256', $xml, $secret);

    kiwi_assert_true($verifier->verify($xml, $digest, $secret), 'Expected valid digests to verify successfully.');
    kiwi_assert_true(!$verifier->verify($xml, 'invalid', $secret), 'Expected invalid digests to keep failing validation.');
});

kiwi_run_test('Kiwi_Dimoco_Rest_Routes resolves missing-order callbacks by unique digest match and stores refund callbacks', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [
            'svc_match' => [
                'label' => 'Service Match',
                'secret' => 'secret-match',
                'order_id' => '115511',
            ],
            'svc_other' => [
                'label' => 'Service Other',
                'secret' => 'secret-other',
                'order_id' => '115510',
            ],
        ]
    );

    $refund_repository = new Kiwi_Test_Insert_Refund_Callback_Repository();
    $blacklist_repository = new Kiwi_Test_Insert_Blacklist_Callback_Repository();
    $operator_lookup_repository = new Kiwi_Test_Insert_Operator_Lookup_Callback_Repository();

    $routes = new Kiwi_Dimoco_Rest_Routes(
        $config,
        new Kiwi_Dimoco_Callback_Verifier(),
        new Kiwi_Dimoco_Response_Parser(),
        $refund_repository,
        $blacklist_repository,
        $operator_lookup_repository
    );

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<result version="2" sync="false" original_version="2">
    <action>refund</action>
    <action_result>
        <code>301</code>
        <detail>ERROR_ACTION_STATUS</detail>
        <status>1</status>
    </action_result>
    <customer/>
    <request_id>2eb2f1d1-fbb1-4c4e-ae36-9e8a9af1e761</request_id>
    <reference>R-p-a4711581-327c-418b-b26a-0c1f5c7e0fe3</reference>
</result>
XML;

    $digest = hash_hmac('sha256', $xml, 'secret-match');
    $response = $routes->handle_dimoco_callback(new WP_REST_Request([
        'data' => $xml,
        'digest' => $digest,
    ]));

    kiwi_assert_same(200, $response->status, 'Expected callbacks with missing order to be accepted when digest matches exactly one configured service.');
    kiwi_assert_same(1, count($refund_repository->rows), 'Expected refund callbacks to be persisted after digest-based service resolution.');
    kiwi_assert_same('svc_match', $refund_repository->rows[0]['service_key'] ?? '', 'Expected digest fallback to resolve and persist the matching service key.');
    kiwi_assert_same('Service Match', $refund_repository->rows[0]['service_label'] ?? '', 'Expected digest fallback to persist the matching service label.');
    kiwi_assert_same(0, count($blacklist_repository->rows), 'Expected refund callbacks not to be routed into blacklist storage.');
    kiwi_assert_same(0, count($operator_lookup_repository->rows), 'Expected refund callbacks not to be routed into operator-lookup storage.');
});

kiwi_run_test('Kiwi_Dimoco_Rest_Routes accepts missing-order callbacks when digest matches multiple shared-secret services', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [
            'svc_one' => [
                'label' => 'Service One',
                'secret' => 'shared-secret',
                'order_id' => '115511',
            ],
            'svc_two' => [
                'label' => 'Service Two',
                'secret' => 'shared-secret',
                'order_id' => '115510',
            ],
        ]
    );

    $refund_repository = new Kiwi_Test_Insert_Refund_Callback_Repository();
    $routes = new Kiwi_Dimoco_Rest_Routes(
        $config,
        new Kiwi_Dimoco_Callback_Verifier(),
        new Kiwi_Dimoco_Response_Parser(),
        $refund_repository,
        new Kiwi_Test_Insert_Blacklist_Callback_Repository(),
        new Kiwi_Test_Insert_Operator_Lookup_Callback_Repository()
    );

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<result version="2" sync="false" original_version="2">
    <action>refund</action>
    <action_result>
        <code>301</code>
        <detail>ERROR_ACTION_STATUS</detail>
        <status>1</status>
    </action_result>
    <customer/>
    <request_id>2eb2f1d1-fbb1-4c4e-ae36-9e8a9af1e761</request_id>
    <reference>R-p-a4711581-327c-418b-b26a-0c1f5c7e0fe3</reference>
</result>
XML;

    $digest = hash_hmac('sha256', $xml, 'shared-secret');
    $response = $routes->handle_dimoco_callback(new WP_REST_Request([
        'data' => $xml,
        'digest' => $digest,
    ]));

    kiwi_assert_same(200, $response->status, 'Expected ambiguous digest-only callback resolution to be accepted when all matched services share one secret.');
    kiwi_assert_same(1, count($refund_repository->rows), 'Expected ambiguous shared-secret callbacks to be persisted.');
    kiwi_assert_same('', $refund_repository->rows[0]['service_key'] ?? '', 'Expected shared-secret fallback callbacks to keep service attribution unresolved.');
    kiwi_assert_same('', $refund_repository->rows[0]['service_label'] ?? '', 'Expected shared-secret fallback callbacks to keep service label unresolved.');
    kiwi_assert_same(
        'digest_fallback_ambiguous_shared_secret_accepted',
        $refund_repository->rows[0]['raw']['callback_resolution']['strategy'] ?? '',
        'Expected callback raw payload metadata to record the shared-secret fallback strategy.'
    );
});

kiwi_run_test('Kiwi_Dimoco_Blacklist_Batch_Service keeps the no-request-id failure branch', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [
            'svc' => [
                'label' => 'Service Label',
            ],
        ]
    );
    $provider = new Kiwi_Test_Lookup_Provider(
        [
            '436641234567' => [
                [
                    'success' => false,
                    'status_code' => 503,
                    'reference' => 'lookup-ref',
                    'messages' => ['Lookup missing request id'],
                ],
            ],
        ]
    );
    $normalizer = new Kiwi_Msisdn_Normalizer();
    $service = new Kiwi_Operator_Lookup_Service($provider, $normalizer);
    $repository = new Kiwi_Test_Operator_Lookup_Repository([]);
    $client = new Kiwi_Test_Dimoco_Client();
    $batch_service = new Kiwi_Test_Dimoco_Blacklist_Batch_Service(
        $service,
        $repository,
        $client,
        new Kiwi_Dimoco_Response_Parser(),
        $config,
        $normalizer
    );

    $result = $batch_service->process('svc', 'merchant', '436641234567');

    kiwi_assert_same('operator_lookup_failed', $result['results'][0]['action_status_text'], 'Expected missing request IDs to keep producing operator_lookup_failed.');
    kiwi_assert_same([], $client->add_blocklist_calls, 'Expected add-blocklist not to run when operator lookup cannot be started.');
});

kiwi_run_test('Kiwi_Dimoco_Blacklist_Batch_Service continues on request_id even when lookup success is false', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [
            'svc' => [
                'label' => 'Service Label',
            ],
        ]
    );
    $provider = new Kiwi_Test_Lookup_Provider(
        [
            '436641234567' => [
                [
                    'success' => false,
                    'request_id' => 'lookup-req-authoritative',
                    'status_code' => 503,
                    'reference' => 'lookup-ref',
                    'messages' => ['Lookup queued despite sync failure'],
                ],
            ],
        ]
    );
    $normalizer = new Kiwi_Msisdn_Normalizer();
    $service = new Kiwi_Operator_Lookup_Service($provider, $normalizer);
    $repository = new Kiwi_Test_Operator_Lookup_Repository(
        [
            'lookup-req-authoritative' => [
                [
                    'operator' => 'A1',
                ],
            ],
        ]
    );
    $client = new Kiwi_Test_Dimoco_Client(
        [
            'success' => true,
            'status_code' => 200,
            'request' => [
                'action' => 'add-blocklist',
                'request_id' => 'blacklist-req-authoritative',
                'order' => 'order-1',
                'msisdn' => '436641234567',
                'operator' => 'A1',
            ],
            'xml' => <<<XML
<response>
    <action>add-blocklist</action>
    <action_result>
        <status>0</status>
        <code>200</code>
        <detail>Accepted</detail>
    </action_result>
    <request_id>blacklist-req-authoritative</request_id>
    <reference>blacklist-ref-authoritative</reference>
    <payment_parameters>
        <order>order-1</order>
    </payment_parameters>
    <customer>
        <msisdn>436641234567</msisdn>
        <operator>A1</operator>
    </customer>
</response>
XML,
        ]
    );
    $batch_service = new Kiwi_Test_Dimoco_Blacklist_Batch_Service(
        $service,
        $repository,
        $client,
        new Kiwi_Dimoco_Response_Parser(),
        $config,
        $normalizer
    );

    $result = $batch_service->process('svc', 'merchant', '436641234567');

    kiwi_assert_same(
        [
            [
                'service_key' => 'svc',
                'msisdn' => '436641234567',
                'operator' => 'A1',
                'blocklist_scope' => 'merchant',
            ],
        ],
        $client->add_blocklist_calls,
        'Expected add-blocklist to run when request_id exists, even if synchronous lookup success is false.'
    );
    kiwi_assert_same('success', $result['results'][0]['action_status_text'], 'Expected request_id-based callback resolution to produce successful add-blocklist flow.');
});

kiwi_run_test('Kiwi_Dimoco_Blacklist_Batch_Service requires request_id even when lookup success is true', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [
            'svc' => [
                'label' => 'Service Label',
            ],
        ]
    );
    $provider = new Kiwi_Test_Lookup_Provider(
        [
            '436641234567' => [
                [
                    'success' => true,
                    'status_code' => 200,
                    'reference' => 'lookup-ref',
                    'messages' => ['Lookup response without request id'],
                ],
            ],
        ]
    );
    $normalizer = new Kiwi_Msisdn_Normalizer();
    $service = new Kiwi_Operator_Lookup_Service($provider, $normalizer);
    $repository = new Kiwi_Test_Operator_Lookup_Repository([]);
    $client = new Kiwi_Test_Dimoco_Client();
    $batch_service = new Kiwi_Test_Dimoco_Blacklist_Batch_Service(
        $service,
        $repository,
        $client,
        new Kiwi_Dimoco_Response_Parser(),
        $config,
        $normalizer
    );

    $result = $batch_service->process('svc', 'merchant', '436641234567');

    kiwi_assert_same('operator_lookup_failed', $result['results'][0]['action_status_text'], 'Expected missing request_id to remain a hard failure gate.');
    kiwi_assert_same([], $client->add_blocklist_calls, 'Expected add-blocklist not to run when request_id is missing, even if lookup success is true.');
});

kiwi_run_test('Kiwi_Dimoco_Blacklist_Batch_Service keeps the callback-success branch', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [
            'svc' => [
                'label' => 'Service Label',
            ],
        ]
    );
    $provider = new Kiwi_Test_Lookup_Provider(
        [
            '436641234567' => [
                [
                    'success' => true,
                    'request_id' => 'lookup-req-1',
                    'status_code' => 200,
                    'reference' => 'lookup-ref',
                    'messages' => [],
                ],
            ],
        ]
    );
    $normalizer = new Kiwi_Msisdn_Normalizer();
    $service = new Kiwi_Operator_Lookup_Service($provider, $normalizer);
    $repository = new Kiwi_Test_Operator_Lookup_Repository(
        [
            'lookup-req-1' => [
                [
                    'operator' => 'A1',
                ],
            ],
        ]
    );
    $client = new Kiwi_Test_Dimoco_Client(
        [
            'success' => true,
            'status_code' => 200,
            'request' => [
                'action' => 'add-blocklist',
                'request_id' => 'blacklist-req-1',
                'order' => 'order-1',
                'msisdn' => '436641234567',
                'operator' => 'A1',
            ],
            'xml' => <<<XML
<response>
    <action>add-blocklist</action>
    <action_result>
        <status>0</status>
        <code>200</code>
        <detail>Accepted</detail>
    </action_result>
    <request_id>blacklist-req-1</request_id>
    <reference>blacklist-ref</reference>
    <payment_parameters>
        <order>order-1</order>
    </payment_parameters>
    <customer>
        <msisdn>436641234567</msisdn>
        <operator>A1</operator>
    </customer>
</response>
XML,
        ]
    );
    $batch_service = new Kiwi_Test_Dimoco_Blacklist_Batch_Service(
        $service,
        $repository,
        $client,
        new Kiwi_Dimoco_Response_Parser(),
        $config,
        $normalizer
    );

    $result = $batch_service->process('svc', 'merchant', '436641234567');

    kiwi_assert_same(
        [
            [
                'service_key' => 'svc',
                'msisdn' => '436641234567',
                'operator' => 'A1',
                'blocklist_scope' => 'merchant',
            ],
        ],
        $client->add_blocklist_calls,
        'Expected add-blocklist to receive the operator resolved from the callback repository.'
    );
    kiwi_assert_same('success', $result['results'][0]['action_status_text'], 'Expected successful callback resolution to keep producing a parsed success result.');
    kiwi_assert_same('Service Label', $result['results'][0]['service_label'], 'Expected service metadata to stay attached to the parsed result.');
});

kiwi_run_test('Kiwi_Dimoco_Blacklist_Batch_Service keeps the callback-timeout branch', function (): void {
    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [
            'svc' => [
                'label' => 'Service Label',
            ],
        ]
    );
    $provider = new Kiwi_Test_Lookup_Provider(
        [
            '436641234567' => [
                [
                    'success' => true,
                    'request_id' => 'lookup-req-2',
                    'status_code' => 200,
                    'reference' => 'lookup-ref',
                    'messages' => ['Lookup queued'],
                ],
            ],
        ]
    );
    $normalizer = new Kiwi_Msisdn_Normalizer();
    $service = new Kiwi_Operator_Lookup_Service($provider, $normalizer);
    $repository = new Kiwi_Test_Operator_Lookup_Repository(
        [
            'lookup-req-2' => [null],
        ]
    );
    $client = new Kiwi_Test_Dimoco_Client();
    $batch_service = new Kiwi_Test_Dimoco_Blacklist_Batch_Service(
        $service,
        $repository,
        $client,
        new Kiwi_Dimoco_Response_Parser(),
        $config,
        $normalizer
    );

    $result = $batch_service->process('svc', 'merchant', '436641234567');

    kiwi_assert_same('operator_lookup_timeout', $result['results'][0]['action_status_text'], 'Expected missing callback rows to keep producing operator_lookup_timeout.');
    kiwi_assert_same([], $client->add_blocklist_calls, 'Expected add-blocklist not to run when the operator lookup callback times out.');
});

kiwi_run_test('Kiwi_Dimoco_Blacklister_Shortcode keeps polling until all async request IDs are present', function (): void {
    $repository = new Kiwi_Test_Blacklist_Callback_Repository(
        [
            [
                ['request_id' => 'req-1'],
            ],
            [
                ['request_id' => 'req-1'],
                ['request_id' => 'req-2'],
            ],
        ]
    );
    $shortcode = new Kiwi_Test_Dimoco_Blacklister_Shortcode(
        new Kiwi_Test_Noop_Blacklist_Batch_Service(),
        new Kiwi_Test_Config(),
        $repository,
        1,
        0
    );

    $async_results = $shortcode->collect_async_results(['req-1', 'req-2']);

    kiwi_assert_same(2, count($repository->calls), 'Expected async callback polling to continue until all request IDs have been observed.');
    kiwi_assert_same(
        [
            ['request_id' => 'req-1'],
            ['request_id' => 'req-2'],
        ],
        $async_results,
        'Expected the final async callback result set to be returned once all request IDs are present.'
    );
});

kiwi_run_test('Kiwi_Dimoco_Refunder_Shortcode keeps polling until all async request IDs are present', function (): void {
    $repository = new Kiwi_Test_Refund_Callback_Repository(
        [],
        [
            [
                ['request_id' => 'req-1'],
            ],
            [
                ['request_id' => 'req-1'],
                ['request_id' => 'req-2'],
            ],
        ]
    );
    $shortcode = new Kiwi_Test_Dimoco_Refunder_Shortcode(
        new Kiwi_Test_Noop_Refund_Batch_Service(),
        new Kiwi_Test_Config(),
        $repository,
        'kiwi_dimoco_refunder_test_state',
        1,
        0
    );

    $async_results = $shortcode->collect_async_results(['req-1', 'req-2']);

    kiwi_assert_same(2, count($repository->calls), 'Expected async callback polling to continue until all request IDs have been observed.');
    kiwi_assert_same(
        [
            ['request_id' => 'req-1'],
            ['request_id' => 'req-2'],
        ],
        $async_results,
        'Expected the final async callback result set to be returned once all request IDs are present.'
    );
});

kiwi_run_test('Kiwi_Dimoco_Blacklister_Shortcode stores and reloads result state for PRG', function (): void {
    $GLOBALS['kiwi_test_transients'] = [];
    $_GET = [];

    $shortcode = new Kiwi_Test_Dimoco_Blacklister_Shortcode(
        new Kiwi_Test_Noop_Blacklist_Batch_Service(),
        new Kiwi_Test_Config(),
        new Kiwi_Test_Blacklist_Callback_Repository([]),
        1,
        0,
        'kiwi_dimoco_blacklister_saved_state'
    );

    $state = [
        'service_key' => 'svc',
        'blocklist_scope' => 'merchant',
        'msisdns_input' => '436641234567',
        'batch_result' => ['processed' => 1],
        'async_results' => [['request_id' => 'req-1']],
    ];

    $stored_id = $shortcode->store_result_state_for_test($state);

    kiwi_assert_same('kiwi_dimoco_blacklister_saved_state', $stored_id, 'Expected the result-state token to be generated deterministically in the test.');
    kiwi_assert_same($state, $GLOBALS['kiwi_test_transients'][$stored_id], 'Expected the result state to be persisted in the transient store.');

    $_GET = [
        'kiwi_dimoco_blacklister_result' => $stored_id,
    ];

    kiwi_assert_same(
        $state,
        $shortcode->load_result_state_from_request_for_test(),
        'Expected the shortcode to restore the stored state from the GET token.'
    );

    $_GET = [];
});

kiwi_run_test('Kiwi_Dimoco_Blacklister_Shortcode redirects after storing result state', function (): void {
    $GLOBALS['kiwi_test_transients'] = [];

    $shortcode = new Kiwi_Test_Dimoco_Blacklister_Shortcode(
        new Kiwi_Test_Noop_Blacklist_Batch_Service(),
        new Kiwi_Test_Config(),
        new Kiwi_Test_Blacklist_Callback_Repository([]),
        1,
        0,
        'kiwi_dimoco_blacklister_redirect_state'
    );

    $did_redirect = $shortcode->maybe_store_and_redirect_result_state_for_test([
        'batch_result' => ['processed' => 1],
        'async_results' => [],
    ]);

    kiwi_assert_true($did_redirect, 'Expected a completed submission to trigger result-state storage and redirect.');
    kiwi_assert_same('kiwi_dimoco_blacklister_redirect_state', $shortcode->redirect_result_state_id, 'Expected redirect to target the generated result-state token.');
    kiwi_assert_same(
        ['batch_result' => ['processed' => 1], 'async_results' => []],
        $GLOBALS['kiwi_test_transients']['kiwi_dimoco_blacklister_redirect_state'],
        'Expected the redirect target state to be saved before redirecting.'
    );
});

kiwi_run_test('Kiwi_Dimoco_Refunder_Shortcode stores and reloads result state for PRG', function (): void {
    $GLOBALS['kiwi_test_transients'] = [];
    $_GET = [];

    $shortcode = new Kiwi_Test_Dimoco_Refunder_Shortcode(
        new Kiwi_Test_Noop_Refund_Batch_Service(),
        new Kiwi_Test_Config(),
        new Kiwi_Test_Refund_Callback_Repository([]),
        'kiwi_dimoco_refunder_saved_state'
    );

    $state = [
        'service_key' => 'svc',
        'msisdn' => '436641234567',
        'transactions_input' => 'tx-1',
        'batch_result' => ['processed' => 1],
        'async_results' => [['transaction_id' => 'tx-1']],
    ];

    $stored_id = $shortcode->store_result_state_for_test($state);

    kiwi_assert_same('kiwi_dimoco_refunder_saved_state', $stored_id, 'Expected the refund result-state token to be generated deterministically in the test.');
    kiwi_assert_same($state, $GLOBALS['kiwi_test_transients'][$stored_id], 'Expected the refund result state to be persisted in the transient store.');

    $_GET = [
        'kiwi_dimoco_refunder_result' => $stored_id,
    ];

    kiwi_assert_same(
        $state,
        $shortcode->load_result_state_from_request_for_test(),
        'Expected the refund shortcode to restore the stored state from the GET token.'
    );

    $_GET = [];
});

kiwi_run_test('Kiwi_Dimoco_Refunder_Shortcode redirects after refund submission and reload does not reprocess', function (): void {
    $GLOBALS['kiwi_test_transients'] = [];
    $_POST = [
        'kiwi_dimoco_refunder_action' => 'refund',
        'kiwi_dimoco_refunder_nonce' => 'kiwi_dimoco_refunder_action',
        'kiwi_dimoco_service' => 'svc',
        'kiwi_dimoco_msisdn' => '436641234567',
        'kiwi_dimoco_transactions' => "tx-1\ntx-1",
    ];
    $_GET = [];

    $batch_service = new Kiwi_Test_Dimoco_Refund_Batch_Service([
        'success' => true,
        'service_key' => 'svc',
        'service_label' => 'Service Label',
        'msisdn' => '436641234567',
        'total_input' => 2,
        'unique_input' => 1,
        'processed' => 1,
        'messages' => [],
        'results' => [
            [
                'request_id' => 'req-1',
                'transaction_id' => 'tx-1',
                'input_transaction_id' => 'tx-1',
                'service_label' => 'Service Label',
                'msisdn' => '436641234567',
                'reference' => 'ref-1',
                'status_code' => 200,
                'action_status_text' => 'pending',
                'detail' => 'Accepted',
            ],
        ],
    ]);
    $callback_repository = new Kiwi_Test_Refund_Callback_Repository([
        [
            'request_id' => 'req-1',
            'transaction_id' => 'tx-1',
            'service_label' => 'Service Label',
            'action_status_text' => 'success',
            'detail' => 'Completed',
        ],
    ]);
    $shortcode = new Kiwi_Test_Dimoco_Refunder_Shortcode(
        $batch_service,
        new Kiwi_Test_Config(
            100,
            0,
            0,
            [],
            [
                'svc' => [
                    'label' => 'Service Label',
                ],
            ]
        ),
        $callback_repository,
        'kiwi_dimoco_refunder_redirect_state'
    );

    $render_result = $shortcode->render();

    kiwi_assert_same('', $render_result, 'Expected a successful refund submission to stop rendering after storing state for redirect.');
    kiwi_assert_same(1, count($batch_service->calls), 'Expected the refund batch service to run once during the POST request.');
    kiwi_assert_same(1, count($callback_repository->calls), 'Expected refund callback rows to be looked up once during the POST request.');
    kiwi_assert_same('kiwi_dimoco_refunder_redirect_state', $shortcode->redirect_result_state_id, 'Expected redirect to target the generated refund result-state token.');

    $_POST = [];
    $_GET = [
        'kiwi_dimoco_refunder_result' => 'kiwi_dimoco_refunder_redirect_state',
    ];

    $reload_output = $shortcode->render();

    kiwi_assert_same(1, count($batch_service->calls), 'Expected reloading the GET result page not to rerun the refund batch service.');
    kiwi_assert_same(1, count($callback_repository->calls), 'Expected reloading the GET result page not to query refund callbacks again.');
    kiwi_assert_true(strpos($reload_output, 'tx-1') !== false, 'Expected the restored GET result page to keep rendering the saved refund data.');

    $_GET = [];
});

kiwi_run_test('Kiwi_Hlr_Lookup_Shortcode stores and reloads result state for PRG', function (): void {
    $GLOBALS['kiwi_test_transients'] = [];
    $_GET = [];

    $shortcode = new Kiwi_Test_Hlr_Lookup_Shortcode(
        new Kiwi_Test_Noop_Operator_Lookup_Batch_Service(),
        new Kiwi_Test_Hlr_Callback_Repository([]),
        'kiwi_hlr_lookup_saved_state',
        'kiwi_hlr_export_saved_batch'
    );

    $state = [
        'submitted_input' => "306912345678\n436641234567",
        'batch_id' => 'kiwi_hlr_export_saved_batch',
        'batch_result' => ['processed' => 2],
    ];

    $stored_id = $shortcode->store_result_state_for_test($state);

    kiwi_assert_same('kiwi_hlr_lookup_saved_state', $stored_id, 'Expected the HLR result-state token to be generated deterministically in the test.');
    kiwi_assert_same($state, $GLOBALS['kiwi_test_transients'][$stored_id], 'Expected the HLR result state to be persisted in the transient store.');

    $_GET = [
        'kiwi_hlr_lookup_result' => $stored_id,
    ];

    kiwi_assert_same(
        $state,
        $shortcode->load_result_state_from_request_for_test(),
        'Expected the HLR shortcode to restore the stored state from the GET token.'
    );

    $_GET = [];
});

kiwi_run_test('Kiwi_Hlr_Lookup_Shortcode redirects after lookup submission and reload does not reprocess', function (): void {
    $GLOBALS['kiwi_test_transients'] = [];
    $_POST = [
        'kiwi_hlr_form_action' => 'lookup',
        'kiwi_hlr_lookup_nonce' => 'kiwi_hlr_lookup_action',
        'kiwi_hlr_input' => "306912345678\n306912345678",
    ];
    $_GET = [];

    $batch_service = new Kiwi_Test_Operator_Lookup_Batch_Service([
        'total_input' => 2,
        'unique_input' => 1,
        'processed' => 1,
        'batch_limit' => 100,
        'has_more' => false,
        'results' => [
            [
                'msisdn' => '306912345678',
                'provider' => 'lily',
                'feature' => 'hlr',
                'success' => true,
                'status_code' => 200,
                'api_status' => 'OK',
                'hlr_status' => 'DELIVERED',
                'operator' => 'Cosmote',
                'messages' => ['Delivered'],
            ],
        ],
    ]);
    $callback_repository = new Kiwi_Test_Hlr_Callback_Repository([]);
    $shortcode = new Kiwi_Test_Hlr_Lookup_Shortcode(
        $batch_service,
        $callback_repository,
        'kiwi_hlr_lookup_redirect_state',
        'kiwi_hlr_export_redirect_batch'
    );

    $render_result = $shortcode->render();

    kiwi_assert_same('', $render_result, 'Expected a successful HLR submission to stop rendering after storing state for redirect.');
    kiwi_assert_same(1, count($batch_service->calls), 'Expected the HLR batch service to run once during the POST request.');
    kiwi_assert_same('kiwi_hlr_lookup_redirect_state', $shortcode->redirect_result_state_id, 'Expected redirect to target the generated HLR result-state token.');
    kiwi_assert_same(
        [
            'sync_rows' => [
                [
                    'msisdn' => '306912345678',
                    'provider' => 'lily',
                    'feature' => 'hlr',
                    'success' => true,
                    'status_code' => 200,
                    'api_status' => 'OK',
                    'hlr_status' => 'DELIVERED',
                    'operator' => 'Cosmote',
                    'messages' => ['Delivered'],
                ],
            ],
            'request_ids' => [],
        ],
        $GLOBALS['kiwi_test_transients']['kiwi_hlr_export_redirect_batch'],
        'Expected HLR export state to keep the sync rows and request_ids under the generated batch_id before redirecting.'
    );

    $_POST = [];
    $_GET = [
        'kiwi_hlr_lookup_result' => 'kiwi_hlr_lookup_redirect_state',
    ];

    $reload_output = $shortcode->render();

    kiwi_assert_same(1, count($batch_service->calls), 'Expected reloading the GET result page not to rerun the HLR batch service.');
    kiwi_assert_true(strpos($reload_output, 'Export CSV') !== false, 'Expected the restored GET result page to keep rendering the export button.');
    kiwi_assert_true(strpos($reload_output, 'kiwi_hlr_export_redirect_batch') !== false, 'Expected the restored GET result page to keep the saved export batch_id.');

    $_GET = [];
});

kiwi_run_test('Kiwi_Hlr_Lookup_Shortcode renders stored asynchronous callback rows for DIMOCO lookups', function (): void {
    $GLOBALS['kiwi_test_transients'] = [];
    $_GET = [];

    $callback_repository = new Kiwi_Test_Hlr_Callback_Repository([
        [
            'created_at' => '2026-03-31 10:00:00',
            'request_id' => 'lookup-req-1',
            'action' => 'operator-lookup',
            'action_status' => 0,
            'action_status_text' => 'success',
            'action_code' => '200',
            'detail' => 'Operator resolved',
            'detail_psp' => 'Callback persisted',
            'msisdn' => '436641234567',
            'operator' => 'A1',
            'service_label' => 'Austria Service',
        ],
    ]);
    $shortcode = new Kiwi_Test_Hlr_Lookup_Shortcode(
        new Kiwi_Test_Noop_Operator_Lookup_Batch_Service(),
        $callback_repository,
        'kiwi_hlr_lookup_async_state',
        'kiwi_hlr_export_async_batch'
    );

    $state = [
        'submitted_input' => '436641234567',
        'batch_id' => 'kiwi_hlr_export_async_batch',
        'batch_result' => [
            'total_input' => 1,
            'unique_input' => 1,
            'processed' => 1,
            'results' => [
                [
                    'msisdn' => '436641234567',
                    'provider' => 'dimoco',
                    'feature' => 'operator_lookup',
                    'success' => false,
                    'status_code' => 200,
                    'api_status' => '',
                    'hlr_status' => '',
                    'operator' => '',
                    'request_id' => 'lookup-req-1',
                    'messages' => ['Pending callback'],
                ],
            ],
        ],
    ];

    $GLOBALS['kiwi_test_transients']['kiwi_hlr_lookup_async_state'] = $state;
    $_GET = [
        'kiwi_hlr_lookup_result' => 'kiwi_hlr_lookup_async_state',
    ];

    $output = $shortcode->render();

    kiwi_assert_same(
        [
            [
                'request_ids' => ['lookup-req-1'],
                'limit' => 100,
            ],
        ],
        $callback_repository->calls,
        'Expected restored HLR result pages to query stored DIMOCO callback rows by request_id.'
    );
    kiwi_assert_true(strpos($output, 'Asynchronous callback responses') !== false, 'Expected the HLR shortcode to render an async callback section when callback rows exist.');
    kiwi_assert_true(strpos($output, 'Austria Service') !== false, 'Expected the HLR shortcode to render the stored callback service label.');
    kiwi_assert_true(strpos($output, 'A1') !== false, 'Expected the HLR shortcode to render the operator from the asynchronous callback.');
    kiwi_assert_true(strpos($output, 'Operator resolved | Callback persisted') !== false, 'Expected the HLR shortcode to render the callback detail messages.');

    $_GET = [];
});

kiwi_run_test('Kiwi_Hlr_Lookup_Shortcode renders all asynchronous callback rows for multi-MSISDN DIMOCO lookups', function (): void {
    $GLOBALS['kiwi_test_transients'] = [];
    $_GET = [];

    $callback_repository = new Kiwi_Test_Hlr_Callback_Repository(
        [
            [
                'created_at' => '2026-03-31 10:00:00',
                'request_id' => 'lookup-req-1',
                'action' => 'operator-lookup',
                'action_status' => 0,
                'action_status_text' => 'success',
                'action_code' => '200',
                'detail' => 'Operator resolved 1',
                'msisdn' => '436641234567',
                'operator' => 'A1',
                'service_label' => 'Austria Service',
            ],
        ],
        [
            [
                'created_at' => '2026-03-31 10:00:00',
                'request_id' => 'lookup-req-1',
                'action' => 'operator-lookup',
                'action_status' => 0,
                'action_status_text' => 'success',
                'action_code' => '200',
                'detail' => 'Operator resolved 1',
                'msisdn' => '436641234567',
                'operator' => 'A1',
                'service_label' => 'Austria Service',
            ],
            [
                'created_at' => '2026-03-31 10:00:05',
                'request_id' => 'lookup-req-2',
                'action' => 'operator-lookup',
                'action_status' => 0,
                'action_status_text' => 'success',
                'action_code' => '200',
                'detail' => 'Operator resolved 2',
                'msisdn' => '436761234567',
                'operator' => 'Magenta',
                'service_label' => 'Austria Service',
            ],
        ]
    );
    $shortcode = new Kiwi_Test_Hlr_Lookup_Shortcode(
        new Kiwi_Test_Noop_Operator_Lookup_Batch_Service(),
        $callback_repository,
        'kiwi_hlr_lookup_async_multi_state',
        'kiwi_hlr_export_async_multi_batch'
    );

    $state = [
        'submitted_input' => "436641234567\n436761234567",
        'batch_id' => 'kiwi_hlr_export_async_multi_batch',
        'batch_result' => [
            'total_input' => 2,
            'unique_input' => 2,
            'processed' => 2,
            'results' => [
                [
                    'msisdn' => '436641234567',
                    'provider' => 'dimoco',
                    'feature' => 'operator_lookup',
                    'success' => false,
                    'status_code' => 200,
                    'api_status' => '',
                    'hlr_status' => '',
                    'operator' => '',
                    'request_id' => 'lookup-req-1',
                    'messages' => ['Pending callback'],
                ],
                [
                    'msisdn' => '436761234567',
                    'provider' => 'dimoco',
                    'feature' => 'operator_lookup',
                    'success' => false,
                    'status_code' => 200,
                    'api_status' => '',
                    'hlr_status' => '',
                    'operator' => '',
                    'request_id' => 'lookup-req-2',
                    'messages' => ['Pending callback'],
                ],
            ],
        ],
    ];

    $GLOBALS['kiwi_test_transients']['kiwi_hlr_lookup_async_multi_state'] = $state;
    $_GET = [
        'kiwi_hlr_lookup_result' => 'kiwi_hlr_lookup_async_multi_state',
    ];

    $output = $shortcode->render();

    kiwi_assert_same(
        [
            [
                'request_ids' => ['lookup-req-1', 'lookup-req-2'],
                'limit' => 100,
            ],
        ],
        $callback_repository->calls,
        'Expected multi-MSISDN HLR result pages to query callback rows using all request_ids from the sync batch.'
    );
    kiwi_assert_same(
        [
            [
                'msisdns' => ['436641234567', '436761234567'],
                'limit' => 100,
            ],
        ],
        $callback_repository->msisdn_calls,
        'Expected multi-MSISDN HLR result pages to fall back to msisdn-based callback lookup when request-id results are incomplete.'
    );
    kiwi_assert_true(substr_count($output, '<td>dimoco</td>') >= 2, 'Expected the HLR async table to render one DIMOCO async row per submitted MSISDN.');
    kiwi_assert_true(strpos($output, 'Operator resolved 1') !== false, 'Expected the first asynchronous HLR callback row to be rendered.');
    kiwi_assert_true(strpos($output, 'Operator resolved 2') !== false, 'Expected the second asynchronous HLR callback row to be rendered.');
    kiwi_assert_true(strpos($output, 'Magenta') !== false, 'Expected the second operator from the async callback table to be rendered.');

    $_GET = [];
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Shortcode requires frontend auth when gate is configured', function (): void {
    $_GET = [];
    $_POST = [];
    unset($_COOKIE['kiwi_frontend_auth']);

    $gate = new Kiwi_Frontend_Auth_Gate([
        'username' => 'admin',
        'password_hash' => password_hash('kiwi-fraud-secret-1', PASSWORD_DEFAULT),
    ]);
    $shortcode = new Kiwi_Premium_Sms_Fraud_Shortcode(
        new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository(),
        null,
        $gate
    );

    $output = $shortcode->render();

    kiwi_assert_contains('Kiwi Tools Login', $output, 'Expected fraud shortcode to enforce the shared frontend auth gate.');
    kiwi_assert_contains('Please sign in to access the Premium SMS fraud monitor tool.', $output, 'Expected fraud shortcode login message context to be rendered.');
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Shortcode shows configured service keys even when no fraud rows exist yet', function (): void {
    $_POST = [];
    $_GET = [];

    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [],
        [
            'nth_fr_one_off_jplay' => [
                'country' => 'FR',
                'flow' => 'one-off',
            ],
        ]
    );
    $shortcode = new Kiwi_Premium_Sms_Fraud_Shortcode(
        new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository(),
        $config,
        new Kiwi_Frontend_Auth_Gate()
    );

    $output = $shortcode->render();

    kiwi_assert_contains('value="nth_fr_one_off_jplay"', $output, 'Expected Service Key dropdown to include configured NTH services even before first fraud signal row.');
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Shortcode discovers service keys from non-NTH service maps', function (): void {
    $_POST = [];
    $_GET = [];

    $config = new Kiwi_Test_Config(
        100,
        0,
        0,
        [],
        [
            'at_service_getstronger' => [
                'label' => 'Get Stronger',
            ],
        ],
        []
    );
    $shortcode = new Kiwi_Premium_Sms_Fraud_Shortcode(
        new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository(),
        $config,
        new Kiwi_Frontend_Auth_Gate()
    );

    $output = $shortcode->render();

    kiwi_assert_contains('value="at_service_getstronger"', $output, 'Expected generic service-key discovery to include non-NTH configured service maps.');
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Shortcode renders engagement soft-flag columns and reasons', function (): void {
    $_POST = [];
    $_GET = [
        'kiwi_fraud_flagged_only' => '1',
        'kiwi_fraud_limit' => '50',
    ];

    $fraud_repository = new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository();
    $engagement_repository = new Kiwi_Test_Premium_Sms_Landing_Engagement_Repository();

    $engagement_repository->upsert_event([
        'landing_key' => 'lp5-fr',
        'session_token' => 'sess-fast-flagged',
        'service_key' => 'nth_fr_one_off_jplay',
        'provider_key' => 'nth',
        'flow_key' => 'nth-fr-one-off',
        'pid' => 'pid-fast',
        'click_id' => 'click-fast',
    ], 'page_loaded', '2026-04-01 12:00:00');
    $engagement_repository->upsert_event([
        'landing_key' => 'lp5-fr',
        'session_token' => 'sess-fast-flagged',
        'service_key' => 'nth_fr_one_off_jplay',
        'provider_key' => 'nth',
        'flow_key' => 'nth-fr-one-off',
    ], 'cta_click', '2026-04-01 12:00:00');

    $engagement_repository->upsert_event([
        'landing_key' => 'lp5-fr',
        'session_token' => 'sess-normal-unflagged',
        'service_key' => 'nth_fr_one_off_jplay',
        'provider_key' => 'nth',
        'flow_key' => 'nth-fr-one-off',
    ], 'page_loaded', '2026-04-01 12:00:00');
    $engagement_repository->upsert_event([
        'landing_key' => 'lp5-fr',
        'session_token' => 'sess-normal-unflagged',
        'service_key' => 'nth_fr_one_off_jplay',
        'provider_key' => 'nth',
        'flow_key' => 'nth-fr-one-off',
    ], 'cta_click', '2026-04-01 12:00:03');

    $shortcode = new Kiwi_Premium_Sms_Fraud_Shortcode(
        $fraud_repository,
        new Kiwi_Test_Config(),
        new Kiwi_Frontend_Auth_Gate(),
        $engagement_repository
    );

    $output = $shortcode->render();

    kiwi_assert_contains('Landing Engagement Signals', $output, 'Expected engagement section title to render.');
    kiwi_assert_contains('<th>PID</th>', $output, 'Expected engagement table to include PID column.');
    kiwi_assert_contains('<th>Click ID</th>', $output, 'Expected engagement table to include Click ID column.');
    kiwi_assert_contains('<th>Delta (Load->First CTA)</th>', $output, 'Expected engagement table to include load-to-first-CTA delta column.');
    kiwi_assert_contains('<th>Soft Flag</th>', $output, 'Expected engagement table to include soft-flag column.');
    kiwi_assert_contains('<th>Reason</th>', $output, 'Expected engagement table to include reason column.');
    kiwi_assert_contains('pid-fast', $output, 'Expected engagement rows to render persisted pid values.');
    kiwi_assert_contains('click-fast', $output, 'Expected engagement rows to render persisted click_id values.');
    kiwi_assert_contains('0s', $output, 'Expected engagement table to render computed delta in seconds when both timestamps are present.');
    kiwi_assert_contains('fast_click', $output, 'Expected engagement soft-flag reason fast_click to be rendered.');
    kiwi_assert_contains('sess-fast-flagged', $output, 'Expected flagged engagement row to be present.');
    kiwi_assert_true(strpos($output, 'sess-normal-unflagged') === false, 'Expected flagged_only filter to hide non-flagged engagement rows.');

    $_GET = [];
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Shortcode renders filtered flagged rows from fraud signal storage', function (): void {
    $_POST = [];
    $_GET = [
        'kiwi_fraud_service_key' => 'svc_a',
        'kiwi_fraud_provider_key' => 'nth',
        'kiwi_fraud_pid' => 'pid-a',
        'kiwi_fraud_flow_key' => 'flow-a',
        'kiwi_fraud_identity_type' => 'session',
        'kiwi_fraud_flagged_only' => '1',
        'kiwi_fraud_limit' => '50',
    ];
    unset($_COOKIE['kiwi_frontend_auth']);

    $repository = new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository();
    $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_a',
        'flow_key' => 'flow-a',
        'pid' => 'pid-a',
        'click_id' => 'click-a',
        'source_event_key' => 'row-1',
        'identity_type' => 'session',
        'identity_value' => 'session-flagged-1',
        'occurred_at' => '2026-04-01 12:00:00',
        'count_1h' => 3,
        'count_24h' => 4,
        'count_total' => 4,
        'is_soft_flag' => true,
        'soft_flag_reason' => 'count_1h>=3',
    ]);
    $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_a',
        'flow_key' => 'flow-a',
        'pid' => 'pid-a',
        'click_id' => 'click-a',
        'source_event_key' => 'row-2',
        'identity_type' => 'session',
        'identity_value' => 'session-not-flagged',
        'occurred_at' => '2026-04-01 12:01:00',
        'count_1h' => 1,
        'count_24h' => 1,
        'count_total' => 1,
        'is_soft_flag' => false,
        'soft_flag_reason' => '',
    ]);
    $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_b',
        'flow_key' => 'flow-b',
        'pid' => 'pid-b',
        'click_id' => 'click-b',
        'source_event_key' => 'row-3',
        'identity_type' => 'session',
        'identity_value' => 'session-other-service',
        'occurred_at' => '2026-04-01 12:02:00',
        'count_1h' => 3,
        'count_24h' => 3,
        'count_total' => 3,
        'is_soft_flag' => true,
        'soft_flag_reason' => 'count_1h>=3',
    ]);
    $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_a',
        'flow_key' => 'flow-a',
        'pid' => 'pid-other',
        'click_id' => 'click-other',
        'source_event_key' => 'row-4',
        'identity_type' => 'session',
        'identity_value' => 'session-other-pid',
        'occurred_at' => '2026-04-01 12:03:00',
        'count_1h' => 3,
        'count_24h' => 3,
        'count_total' => 3,
        'is_soft_flag' => true,
        'soft_flag_reason' => 'count_1h>=3',
    ]);

    $shortcode = new Kiwi_Premium_Sms_Fraud_Shortcode($repository, null, new Kiwi_Frontend_Auth_Gate());
    $output = $shortcode->render();

    kiwi_assert_contains('Premium SMS Fraud Monitor', $output, 'Expected fraud shortcode title to render.');
    kiwi_assert_contains('<th>PID</th>', $output, 'Expected shortcode tables to render a PID column.');
    kiwi_assert_contains('<th>Click ID</th>', $output, 'Expected shortcode tables to render a Click ID column.');
    kiwi_assert_contains('click-a', $output, 'Expected filtered fraud rows to render click_id values.');
    kiwi_assert_contains('session-flagged-1', $output, 'Expected filtered flagged row to be rendered.');
    kiwi_assert_true(strpos($output, 'session-not-flagged') === false, 'Expected flagged_only filter to remove non-flagged rows.');
    kiwi_assert_true(strpos($output, 'session-other-service') === false, 'Expected service_key filter to remove rows from other services.');
    kiwi_assert_true(strpos($output, 'session-other-pid') === false, 'Expected pid filter to remove flagged rows from other pid values.');

    $_GET = [];
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Shortcode applies flow_key filter to fraud signal rows', function (): void {
    $_POST = [];
    $_GET = [
        'kiwi_fraud_service_key' => 'svc_a',
        'kiwi_fraud_provider_key' => 'nth',
        'kiwi_fraud_pid' => 'pid-a',
        'kiwi_fraud_flow_key' => 'flow-a',
        'kiwi_fraud_identity_type' => 'session',
        'kiwi_fraud_flagged_only' => '1',
        'kiwi_fraud_limit' => '50',
    ];

    $repository = new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository();
    $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_a',
        'flow_key' => 'flow-a',
        'pid' => 'pid-a',
        'click_id' => 'click-a',
        'source_event_key' => 'row-flow-1',
        'identity_type' => 'session',
        'identity_value' => 'session-flow-a',
        'occurred_at' => '2026-04-01 12:00:00',
        'count_1h' => 3,
        'count_24h' => 3,
        'count_total' => 3,
        'is_soft_flag' => true,
        'soft_flag_reason' => 'count_1h>=3',
    ]);
    $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_a',
        'flow_key' => 'flow-b',
        'pid' => 'pid-a',
        'click_id' => 'click-b',
        'source_event_key' => 'row-flow-2',
        'identity_type' => 'session',
        'identity_value' => 'session-flow-b',
        'occurred_at' => '2026-04-01 12:01:00',
        'count_1h' => 3,
        'count_24h' => 3,
        'count_total' => 3,
        'is_soft_flag' => true,
        'soft_flag_reason' => 'count_1h>=3',
    ]);

    $shortcode = new Kiwi_Premium_Sms_Fraud_Shortcode($repository, null, new Kiwi_Frontend_Auth_Gate());
    $output = $shortcode->render();

    kiwi_assert_contains('session-flow-a', $output, 'Expected matching flow_key row to remain visible.');
    kiwi_assert_true(strpos($output, 'session-flow-b') === false, 'Expected flow_key filter to remove rows from other flows.');

    $_GET = [];
});

kiwi_run_test('Kiwi frontend auth denies unauthenticated access to tool shortcodes', function (): void {
    $_GET = [];
    $_POST = [];
    unset($_COOKIE['kiwi_frontend_auth']);

    $gate = new Kiwi_Frontend_Auth_Gate([
        'username' => 'admin',
        'password_hash' => password_hash('kiwi-secret-1', PASSWORD_DEFAULT),
    ]);
    $shortcode = new Kiwi_Hlr_Lookup_Shortcode(
        new Kiwi_Test_Noop_Operator_Lookup_Batch_Service(),
        new Kiwi_Test_Hlr_Callback_Repository([]),
        $gate
    );

    $output = $shortcode->render();

    kiwi_assert_contains('Kiwi Tools Login', $output, 'Expected unauthenticated users to receive the auth login form.');
    kiwi_assert_contains('Please sign in to access the HLR lookup tool.', $output, 'Expected login prompt context to be rendered for the protected HLR tool.');
});

kiwi_run_test('Kiwi frontend auth allows valid credential login for protected tool shortcodes', function (): void {
    $_GET = [];
    $_POST = [];
    unset($_COOKIE['kiwi_frontend_auth']);

    $gate = new Kiwi_Frontend_Auth_Gate([
        'username' => 'admin',
        'password_hash' => password_hash('kiwi-secret-2', PASSWORD_DEFAULT),
    ]);
    $logged_in = $gate->login('admin', 'kiwi-secret-2');
    $shortcode = new Kiwi_Hlr_Lookup_Shortcode(
        new Kiwi_Test_Noop_Operator_Lookup_Batch_Service(),
        new Kiwi_Test_Hlr_Callback_Repository([]),
        $gate
    );

    $output = $shortcode->render();

    kiwi_assert_true($logged_in, 'Expected valid auth credentials to produce an authenticated session.');
    kiwi_assert_contains('HLR Lookup', $output, 'Expected authenticated users to receive the original HLR tool output.');
});

kiwi_run_test('Kiwi frontend auth logout revokes previously granted access', function (): void {
    $_GET = [];
    $_POST = [];
    unset($_COOKIE['kiwi_frontend_auth']);

    $gate = new Kiwi_Frontend_Auth_Gate([
        'username' => 'admin',
        'password_hash' => password_hash('kiwi-secret-3', PASSWORD_DEFAULT),
    ]);

    kiwi_assert_true($gate->login('admin', 'kiwi-secret-3'), 'Expected valid credentials to authenticate before logout.');
    $gate->logout();

    $shortcode = new Kiwi_Hlr_Lookup_Shortcode(
        new Kiwi_Test_Noop_Operator_Lookup_Batch_Service(),
        new Kiwi_Test_Hlr_Callback_Repository([]),
        $gate
    );
    $output = $shortcode->render();

    kiwi_assert_contains('Kiwi Tools Login', $output, 'Expected logout to remove access and show the auth login form again.');
});

kiwi_run_test('Kiwi frontend auth keeps existing HLR submit flow behavior after successful login', function (): void {
    $_GET = [];
    $_POST = [];
    $GLOBALS['kiwi_test_transients'] = [];
    unset($_COOKIE['kiwi_frontend_auth']);

    $gate = new Kiwi_Frontend_Auth_Gate([
        'username' => 'admin',
        'password_hash' => password_hash('kiwi-secret-4', PASSWORD_DEFAULT),
    ]);
    kiwi_assert_true($gate->login('admin', 'kiwi-secret-4'), 'Expected authenticated session setup to succeed before validating flow behavior.');

    $batch_service = new Kiwi_Test_Operator_Lookup_Batch_Service([
        'total_input' => 1,
        'unique_input' => 1,
        'processed' => 1,
        'results' => [
            [
                'msisdn' => '436641234567',
                'provider' => 'dimoco',
                'feature' => 'operator_lookup',
                'success' => true,
                'status_code' => 200,
                'api_status' => 'ok',
                'hlr_status' => '',
                'operator' => 'A1',
                'request_id' => 'req-1',
                'messages' => ['done'],
            ],
        ],
    ]);
    $shortcode = new Kiwi_Test_Hlr_Lookup_Shortcode(
        $batch_service,
        new Kiwi_Test_Hlr_Callback_Repository([]),
        'kiwi_hlr_lookup_auth_state',
        'kiwi_hlr_export_auth_batch',
        $gate
    );

    $_POST = [
        'kiwi_hlr_form_action' => 'lookup',
        'kiwi_hlr_lookup_nonce' => 'kiwi_hlr_lookup_action',
        'kiwi_hlr_input' => '436641234567',
    ];

    $render_result = $shortcode->render();

    kiwi_assert_same('', $render_result, 'Expected authenticated submissions to continue redirect-based result-state flow.');
    kiwi_assert_same(1, count($batch_service->calls), 'Expected authenticated submit flow to still execute the batch service exactly once.');
    kiwi_assert_same('kiwi_hlr_lookup_auth_state', $shortcode->redirect_result_state_id, 'Expected authenticated submit flow to keep redirecting to stored result-state tokens.');

    $_POST = [];
    $_GET = [];
});
