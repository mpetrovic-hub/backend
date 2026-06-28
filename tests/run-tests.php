<?php

define('ABSPATH', __DIR__ . '/../');

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
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
$GLOBALS['kiwi_test_dbdelta_queries'] = [];
$GLOBALS['kiwi_test_cron_events'] = [];
$GLOBALS['kiwi_test_next_scheduled'] = [];
$GLOBALS['kiwi_test_deleted_transients'] = [];

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

function delete_transient($key): bool
{
    $GLOBALS['kiwi_test_deleted_transients'][] = (string) $key;
    unset($GLOBALS['kiwi_test_transients'][$key]);

    return true;
}

function wp_next_scheduled($hook)
{
    return $GLOBALS['kiwi_test_next_scheduled'][(string) $hook] ?? false;
}

function wp_schedule_event($timestamp, $recurrence, $hook): bool
{
    $event = [
        'timestamp' => (int) $timestamp,
        'recurrence' => (string) $recurrence,
        'hook' => (string) $hook,
    ];
    $GLOBALS['kiwi_test_cron_events'][] = $event;
    $GLOBALS['kiwi_test_next_scheduled'][(string) $hook] = (int) $timestamp;

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

function rest_url($path = ''): string
{
    return 'https://backend.example.test/wp-json/' . ltrim((string) $path, '/');
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
require_once __DIR__ . '/../includes/repositories/class-device-model-brand-map-repository.php';
require_once __DIR__ . '/../includes/services/class-device-context-normalizer.php';
require_once __DIR__ . '/../includes/services/class-device-model-brand-harvest-service.php';
require_once __DIR__ . '/../includes/services/class-client-ip-resolver.php';
require_once __DIR__ . '/../includes/repositories/class-landing-page-session-repository.php';
require_once __DIR__ . '/../includes/repositories/class-nth-event-repository.php';
require_once __DIR__ . '/../includes/repositories/class-nth-flow-transaction-repository.php';
require_once __DIR__ . '/../includes/repositories/class-click-attribution-repository.php';
require_once __DIR__ . '/../includes/repositories/class-sales-repository.php';
require_once __DIR__ . '/../includes/repositories/class-landing-kpi-summary-repository.php';
require_once __DIR__ . '/../includes/repositories/class-landing-handoff-event-repository.php';
require_once __DIR__ . '/../includes/repositories/class-sms-body-variant-repository.php';
require_once __DIR__ . '/../includes/repositories/class-premium-sms-landing-engagement-repository.php';
require_once __DIR__ . '/../includes/repositories/class-premium-sms-fraud-signal-repository.php';
require_once __DIR__ . '/../includes/repositories/class-retention-cleanup-run-repository.php';
require_once __DIR__ . '/../includes/repositories/class-retention-table-growth-snapshot-repository.php';
require_once __DIR__ . '/../includes/repositories/interface-statistics-read-repository.php';
require_once __DIR__ . '/../includes/repositories/class-landing-funnel-daily-summary-repository.php';
require_once __DIR__ . '/../includes/repositories/class-landing-funnel-daily-tkzone-summary-repository.php';
require_once __DIR__ . '/../includes/repositories/class-traffic-source-funnel-statistics-repository.php';
require_once __DIR__ . '/../includes/providers/nth/class-nth-premium-sms-normalizer.php';
require_once __DIR__ . '/../includes/providers/nth/class-nth-client.php';
require_once __DIR__ . '/../includes/shortcodes/class-dimoco-blacklister-shortcode.php';
require_once __DIR__ . '/../includes/shortcodes/class-dimoco-refunder-shortcode.php';
require_once __DIR__ . '/../includes/shortcodes/class-hlr-lookup-shortcode.php';
require_once __DIR__ . '/../includes/shortcodes/class-premium-sms-fraud-shortcode.php';
require_once __DIR__ . '/../includes/shortcodes/class-statistics-shortcode.php';
require_once __DIR__ . '/../includes/services/class-shared-sales-recorder.php';
require_once __DIR__ . '/../includes/services/class-sales-attribution-snapshot-builder.php';
require_once __DIR__ . '/../includes/services/class-affiliate-postback-dispatcher.php';
require_once __DIR__ . '/../includes/services/class-conversion-attribution-resolver.php';
require_once __DIR__ . '/../includes/services/class-tracking-capture-service.php';
require_once __DIR__ . '/../includes/services/class-premium-sms-mo-engagement-evaluator-service.php';
require_once __DIR__ . '/../includes/services/class-premium-sms-completed-sale-cooldown-service.php';
require_once __DIR__ . '/../includes/services/class-premium-sms-fraud-monitor-service.php';
require_once __DIR__ . '/../includes/services/class-landing-primary-cta-adapter-interface.php';
require_once __DIR__ . '/../includes/services/class-landing-primary-cta-resolver.php';
require_once __DIR__ . '/../includes/services/class-landing-kpi-service.php';
require_once __DIR__ . '/../includes/services/class-landing-page-gallery-service.php';
require_once __DIR__ . '/../includes/services/class-landing-page-variant-agent.php';
require_once __DIR__ . '/../includes/services/class-sms-body-variant-service.php';
require_once __DIR__ . '/../includes/services/class-landing-funnel-daily-summary-aggregation-service.php';
require_once __DIR__ . '/../includes/services/class-landing-funnel-daily-tkzone-summary-aggregation-service.php';
require_once __DIR__ . '/../includes/services/class-retention-source-registry.php';
require_once __DIR__ . '/../includes/services/class-retention-coverage-gate.php';
require_once __DIR__ . '/../includes/services/class-retention-sqlite-archive-service.php';
require_once __DIR__ . '/../includes/services/class-retention-cleanup-service.php';
require_once __DIR__ . '/../includes/services/class-premium-sms-landing-engagement-soft-flag-service.php';
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

function dbDelta($sql): void
{
    $GLOBALS['kiwi_test_dbdelta_queries'][] = (string) $sql;
}

class Kiwi_Test_Landing_Ua_Config extends Kiwi_Test_Config
{
    private $landing_ua_tracking_mode;

    public function __construct(string $landing_ua_tracking_mode, array $landing_pages = [])
    {
        parent::__construct(100, 0, 0, [], [], [], $landing_pages);
        $this->landing_ua_tracking_mode = strtolower(trim($landing_ua_tracking_mode));
    }

    public function get_landing_ua_tracking_mode(): string
    {
        return in_array($this->landing_ua_tracking_mode, ['disabled', 'onclick', 'onload'], true)
            ? $this->landing_ua_tracking_mode
            : 'onload';
    }
}

class Kiwi_Test_Trusted_Proxy_Config extends Kiwi_Test_Config
{
    private $trusted_proxy_cidrs;

    public function __construct(array $trusted_proxy_cidrs, array $landing_pages = [])
    {
        parent::__construct(100, 0, 0, [], [], [], $landing_pages);
        $this->trusted_proxy_cidrs = $trusted_proxy_cidrs;
    }

    public function get_trusted_proxy_cidrs(): array
    {
        return $this->trusted_proxy_cidrs;
    }
}

class Kiwi_Test_Trusted_Proxy_Debug_Config extends Kiwi_Test_Trusted_Proxy_Config
{
    private $client_ip_resolution_debug_enabled;

    public function __construct(array $trusted_proxy_cidrs, bool $client_ip_resolution_debug_enabled, array $landing_pages = [])
    {
        parent::__construct($trusted_proxy_cidrs, $landing_pages);
        $this->client_ip_resolution_debug_enabled = $client_ip_resolution_debug_enabled;
    }

    public function is_client_ip_resolution_debug_enabled(): bool
    {
        return $this->client_ip_resolution_debug_enabled;
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
        bool $legacy_fallback_enabled = false,
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

class Kiwi_Test_Ua_Client_Hints_Disabled_Config extends Kiwi_Test_Config
{
    public function get_landing_ua_tracking_mode(): string
    {
        return 'disabled';
    }

    public function is_landing_handoff_ua_client_hints_enabled(): bool
    {
        return false;
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
    public $exported_statistics_rows;
    public $hlr_async_export_rows = [];
    public $hlr_async_export_request_ids = [];
    public $hlr_async_export_rows_by_msisdn = [];
    public $hlr_async_export_msisdns = [];

    protected function export_hlr_rows(array $rows): void
    {
        $this->exported_rows = $rows;
    }

    protected function export_statistics_rows(array $rows): void
    {
        $this->exported_statistics_rows = $rows;
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

class Kiwi_Test_Plugin_Device_Dimension_Migration extends Kiwi_Plugin
{
    public function run_device_dimension_migration_for_test(): void
    {
        $this->migrate_legacy_android_version_columns();
    }

    public function run_slim_daily_summary_migration_for_test(): void
    {
        $this->migrate_slim_landing_funnel_daily_summary_columns();
    }
}

class Kiwi_Test_Landing_Funnel_Daily_Summary_Refresh_Service extends Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service
{
    public $calls = [];
    private $result;
    private $last_error;

    public function __construct(array $result, string $last_error = '')
    {
        $this->result = $result;
        $this->last_error = $last_error;
    }

    public function refresh_range(string $from_date, string $to_date): array
    {
        $this->calls[] = [$from_date, $to_date];

        return $this->result;
    }

    public function get_last_error(): string
    {
        return $this->last_error;
    }
}

class Kiwi_Test_Landing_Funnel_Daily_Tkzone_Summary_Refresh_Service extends Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Aggregation_Service
{
    public $calls = [];
    private $result;
    private $last_error;

    public function __construct(array $result, string $last_error = '')
    {
        $this->result = $result;
        $this->last_error = $last_error;
    }

    public function refresh_range(string $from_date, string $to_date): array
    {
        $this->calls[] = [$from_date, $to_date];

        return $this->result;
    }

    public function get_last_error(): string
    {
        return $this->last_error;
    }
}

class Kiwi_Test_Plugin_Landing_Funnel_Daily_Summary_Refresh extends Kiwi_Plugin
{
    public $current_business_date = '2026-05-26';
    public $current_time_mysql = '2026-05-26 12:00:00';
    public $refresh_days = 7;
    public $logs = [];
    private $refresh_service;
    private $tkzone_refresh_service;

    public function __construct(
        Kiwi_Test_Landing_Funnel_Daily_Summary_Refresh_Service $refresh_service,
        ?Kiwi_Test_Landing_Funnel_Daily_Tkzone_Summary_Refresh_Service $tkzone_refresh_service = null
    )
    {
        parent::__construct(dirname(__DIR__), 'https://example.test/plugin/');
        $this->refresh_service = $refresh_service;
        $this->tkzone_refresh_service = $tkzone_refresh_service instanceof Kiwi_Test_Landing_Funnel_Daily_Tkzone_Summary_Refresh_Service
            ? $tkzone_refresh_service
            : new Kiwi_Test_Landing_Funnel_Daily_Tkzone_Summary_Refresh_Service([
                'success' => true,
                'deleted' => 0,
                'inserted' => 0,
                'error' => '',
            ]);
    }

    protected function build_landing_funnel_daily_summary_refresh_service(): Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service
    {
        return $this->refresh_service;
    }

    protected function build_landing_funnel_daily_tkzone_summary_refresh_service(): Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Aggregation_Service
    {
        return $this->tkzone_refresh_service;
    }

    protected function get_current_business_date(): string
    {
        return $this->current_business_date;
    }

    protected function get_current_time_mysql(): string
    {
        return $this->current_time_mysql;
    }

    protected function get_landing_funnel_daily_summary_refresh_days(): int
    {
        return max(0, (int) $this->refresh_days);
    }

    protected function log_landing_funnel_daily_summary_refresh(string $message): void
    {
        $this->logs[] = $message;
    }
}

class Kiwi_Test_Retention_Cleanup_Run_Repository extends Kiwi_Retention_Cleanup_Run_Repository
{
    public $rows = [];
    public $updates = [];
    public $create_run_result = null;
    public $update_run_result = true;
    private $next_id = 1;

    public function create_table(): void
    {
    }

    public function create_run(array $data): int
    {
        if ($this->create_run_result !== null) {
            return (int) $this->create_run_result;
        }

        $id = $this->next_id++;
        $this->rows[$id] = array_merge(['id' => $id], $data);

        return $id;
    }

    public function update_run(int $id, array $data): bool
    {
        $this->updates[] = ['id' => $id, 'data' => $data];

        if (!$this->update_run_result) {
            return false;
        }

        $this->rows[$id] = array_merge($this->rows[$id] ?? ['id' => $id], $data);

        return true;
    }
}

class Kiwi_Test_Retention_Table_Growth_Snapshot_Repository extends Kiwi_Retention_Table_Growth_Snapshot_Repository
{
    public $snapshots = [];

    public function create_table(): void
    {
    }

    public function capture_snapshot(
        array $source,
        string $snapshot_phase,
        int $retention_days,
        string $cutoff_value,
        int $eligible_rows,
        string $cleanup_run_id,
        string $archive_batch_id = '',
        int $archived_rows = 0,
        int $deleted_rows = 0
    ): int {
        $this->snapshots[] = [
            'source' => $source,
            'snapshot_phase' => $snapshot_phase,
            'retention_days' => $retention_days,
            'cutoff_value' => $cutoff_value,
            'eligible_rows' => $eligible_rows,
            'cleanup_run_id' => $cleanup_run_id,
            'archive_batch_id' => $archive_batch_id,
            'archived_rows' => $archived_rows,
            'deleted_rows' => $deleted_rows,
        ];

        return count($this->snapshots);
    }
}

class Kiwi_Test_Retention_Sqlite_Archive_Service extends Kiwi_Retention_Sqlite_Archive_Service
{
    public $calls = [];
    public $events = null;
    public $result = [
        'success' => true,
        'archive_db_path' => '/tmp/kiwi_retention_archive_2026.sqlite',
        'archived_rows' => 0,
        'archive_inserted_rows' => 0,
        'archive_duplicate_rows' => 0,
        'archive_integrity_check' => 'ok',
    ];
    public $archived_primary_keys = [];

    public function __construct()
    {
    }

    public function archive_eligible_rows(
        array $source,
        string $cutoff_value,
        string $archive_batch_id,
        int $batch_limit
    ): array {
        if (is_array($this->events)) {
            $this->events[] = 'archive';
        }

        $this->calls[] = [
            'source' => $source,
            'cutoff_value' => $cutoff_value,
            'archive_batch_id' => $archive_batch_id,
            'batch_limit' => $batch_limit,
        ];

        return array_merge($this->result, [
            'archive_batch_id' => $archive_batch_id,
        ]);
    }

    public function fetch_archived_primary_key_batch(
        array $source,
        string $archive_db_path,
        string $archive_batch_id,
        int $last_primary_key,
        int $batch_limit
    ): array {
        return array_slice(array_values(array_filter($this->archived_primary_keys, static function (int $id) use ($last_primary_key): bool {
            return $id > $last_primary_key;
        })), 0, max(1, $batch_limit));
    }
}

class Kiwi_Test_Retention_Sqlite_Archive_Failure_Service extends Kiwi_Retention_Sqlite_Archive_Service
{
    public function __construct()
    {
    }

    public function apply_archive_failure_for_test(array $result, Throwable $error): array
    {
        return $this->apply_archive_failure($result, $error);
    }
}

class Kiwi_Test_Retention_Coverage_Gate extends Kiwi_Retention_Coverage_Gate
{
    public $calls = [];
    public $result;

    public function __construct(array $result)
    {
        $this->result = $result;
    }

    public function check_landing_page_sessions(array $source, string $cutoff_value): array
    {
        $this->calls[] = [
            'source' => $source,
            'cutoff_value' => $cutoff_value,
        ];

        return $this->result;
    }
}

class Kiwi_Test_Retention_Cleanup_Service extends Kiwi_Retention_Cleanup_Service
{
    public $eligible_rows = 0;
    public $delete_result = ['deleted_rows' => 0, 'delete_batches' => 0];
    public $deleted_primary_keys = [];
    public $deleted_primary_key_batches = [];
    public $events = [];

    protected function count_eligible_rows(array $source, string $cutoff_value): int
    {
        $this->events[] = 'count';

        return $this->eligible_rows;
    }

    protected function delete_source_primary_keys(array $source, array $primary_keys): int
    {
        $this->events[] = 'delete';
        $this->deleted_primary_key_batches[] = $primary_keys;
        $this->deleted_primary_keys = array_merge($this->deleted_primary_keys, $primary_keys);

        return (int) ($this->delete_result['deleted_rows'] ?? count($primary_keys));
    }
}

class Kiwi_Test_Plugin_Retention_Cleanup extends Kiwi_Plugin
{
    public $logs = [];
    private $retention_cleanup_service;

    public function __construct(Kiwi_Retention_Cleanup_Service $retention_cleanup_service)
    {
        parent::__construct(dirname(__DIR__), 'https://example.test/plugin/');
        $this->retention_cleanup_service = $retention_cleanup_service;
    }

    protected function build_retention_cleanup_service(): Kiwi_Retention_Cleanup_Service
    {
        return $this->retention_cleanup_service;
    }

    protected function log_retention_cleanup(string $message): void
    {
        $this->logs[] = $message;
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
            'tksource' => (string) ($data['tksource'] ?? ''),
            'tkzone' => (string) ($data['tkzone'] ?? ''),
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
            'billing_outcome' => (string) ($data['billing_outcome'] ?? 'mo_received'),
            'billing_outcome_at' => (string) ($data['billing_outcome_at'] ?? ($data['occurred_at'] ?? '2026-04-01 12:00:00')),
            'billing_transaction_id' => (int) ($data['billing_transaction_id'] ?? 0),
            'sale_id' => (int) ($data['sale_id'] ?? 0),
            'sale_completed_at' => (string) ($data['sale_completed_at'] ?? ''),
            'aggregator_status_code' => (string) ($data['aggregator_status_code'] ?? ''),
            'aggregator_status_text' => (string) ($data['aggregator_status_text'] ?? ''),
            'meta_json' => isset($data['meta_json']) ? json_encode($data['meta_json']) : '',
        ];

        $this->rows[] = $row;

        return [
            'inserted' => true,
            'row' => $row,
        ];
    }

    public function update_billing_outcome_by_source_event_identity(
        string $source_event_key,
        string $identity_type,
        array $data
    ): bool {
        $source_event_key = trim($source_event_key);
        $identity_type = trim($identity_type);

        if ($source_event_key === '' || $identity_type === '') {
            return false;
        }

        foreach ($this->rows as $index => $row) {
            if (($row['source_event_key'] ?? '') !== $source_event_key) {
                continue;
            }

            if (($row['identity_type'] ?? '') !== $identity_type) {
                continue;
            }

            foreach ([
                'billing_outcome',
                'billing_outcome_at',
                'billing_transaction_id',
                'sale_id',
                'sale_completed_at',
                'aggregator_status_code',
                'aggregator_status_text',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $this->rows[$index][$field] = $data[$field];
                }
            }

            return true;
        }

        return false;
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

        if (trim((string) ($filters['tksource'] ?? '')) !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
                return (string) ($row['tksource'] ?? '') === (string) $filters['tksource'];
            }));
        }

        if (trim((string) ($filters['tkzone'] ?? '')) !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
                return (string) ($row['tkzone'] ?? '') === (string) $filters['tkzone'];
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
    public $outcome_updates = [];
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

    public function update_subscriber_billing_outcome(string $source_event_key, array $outcome): bool
    {
        $this->outcome_updates[] = [
            'source_event_key' => $source_event_key,
            'outcome' => $outcome,
        ];

        return true;
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

    public function get_by_id(int $id): ?array
    {
        foreach ($this->rows as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }

        return null;
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

    public function find_pending_by_subscriber_context(
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

            if (!empty($row['is_terminal'])) {
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
            foreach (['tksource', 'tkzone'] as $source_field) {
                $incoming_value = trim((string) ($data[$source_field] ?? ''));
                if ($incoming_value === '' && array_key_exists($source_field, $row)) {
                    unset($data[$source_field]);
                }
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
        $updates = array_filter(
            $references,
            static function ($value): bool {
                return trim((string) $value) !== '';
            }
        );

        foreach (['provider_key', 'flow_key', 'service_key', 'session_ref', 'transaction_ref', 'message_ref', 'external_ref', 'sale_reference'] as $field) {
            if (trim((string) ($row[$field] ?? '')) !== '' && array_key_exists($field, $updates)) {
                unset($updates[$field]);
            }
        }

        $this->rows[$id] = array_merge($row, $updates);
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
                'tksource' => (string) ($context['tksource'] ?? ''),
                'tkzone' => (string) ($context['tkzone'] ?? ''),
                'landing_key' => $landing_key,
                'session_token' => $session_token,
                'page_loaded_at' => '',
                'first_cta_click_at' => '',
                'last_cta_click_at' => '',
                'cta_click_count' => 0,
                'first_cta1_click_at' => '',
                'last_cta1_click_at' => '',
                'cta1_click_count' => 0,
                'first_cta2_click_at' => '',
                'last_cta2_click_at' => '',
                'cta2_click_count' => 0,
                'first_cta3_click_at' => '',
                'last_cta3_click_at' => '',
                'cta3_click_count' => 0,
                'ua_ch_supported' => 0,
                'ua_ch_mobile' => 0,
                'ua_ch_platform' => '',
                'ua_ch_platform_version' => '',
                'ua_ch_model' => '',
                'ua_ch_brands' => '',
                'ua_ch_full_version_list' => '',
                'user_agent' => '',
                'last_event_at' => $occurred_at,
                'is_soft_flag' => 0,
                'soft_flag_reason' => '',
                'soft_flag_rule_key' => '',
                'soft_flag_evaluated_at' => '',
            ];
        }

        $row = $this->rows[$id];
        $row['updated_at'] = '2026-04-01 12:00:00';
        $row['provider_key'] = (string) ($row['provider_key'] !== '' ? $row['provider_key'] : (string) ($context['provider_key'] ?? ''));
        $row['service_key'] = (string) ($row['service_key'] !== '' ? $row['service_key'] : (string) ($context['service_key'] ?? ''));
        $row['flow_key'] = (string) ($row['flow_key'] !== '' ? $row['flow_key'] : (string) ($context['flow_key'] ?? ''));
        $row['pid'] = (string) ($row['pid'] !== '' ? $row['pid'] : (string) ($context['pid'] ?? ''));
        $row['click_id'] = (string) ($row['click_id'] !== '' ? $row['click_id'] : (string) ($context['click_id'] ?? ''));
        $row['tksource'] = (string) ($row['tksource'] !== '' ? $row['tksource'] : (string) ($context['tksource'] ?? ''));
        $row['tkzone'] = (string) ($row['tkzone'] !== '' ? $row['tkzone'] : (string) ($context['tkzone'] ?? ''));
        $row['last_event_at'] = $occurred_at;

        foreach (['ua_ch_supported', 'ua_ch_mobile'] as $field) {
            if (!empty($context[$field]) && (int) ($row[$field] ?? 0) === 0) {
                $row[$field] = 1;
            }
        }

        foreach ([
            'ua_ch_platform',
            'ua_ch_platform_version',
            'ua_ch_model',
            'ua_ch_brands',
            'ua_ch_full_version_list',
            'user_agent',
        ] as $field) {
            if ((string) ($row[$field] ?? '') === '' && trim((string) ($context[$field] ?? '')) !== '') {
                $row[$field] = trim((string) $context[$field]);
            }
        }

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

            $cta_step = strtolower(trim((string) ($context['cta_step'] ?? '')));
            if (in_array($cta_step, ['cta1', 'cta2', 'cta3'], true)) {
                $first_field = 'first_' . $cta_step . '_click_at';
                $last_field = 'last_' . $cta_step . '_click_at';
                $count_field = $cta_step . '_click_count';

                if ((string) ($row[$first_field] ?? '') === '') {
                    $row[$first_field] = $occurred_at;
                }

                $row[$last_field] = $occurred_at;
                $row[$count_field] = max(0, (int) ($row[$count_field] ?? 0)) + 1;
            }
        }

        $soft_flag = (new Kiwi_Premium_Sms_Landing_Engagement_Soft_Flag_Service(new Kiwi_Test_Config()))->evaluate($row);
        $row['is_soft_flag'] = !empty($soft_flag['is_soft_flag']) ? 1 : 0;
        $row['soft_flag_reason'] = (string) ($soft_flag['soft_flag_reason'] ?? '');
        $row['soft_flag_rule_key'] = (string) ($soft_flag['soft_flag_rule_key'] ?? '');
        $row['soft_flag_evaluated_at'] = '2026-04-01 12:00:00';

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
        $flow_key = trim((string) ($filters['flow_key'] ?? ''));
        $pid = trim((string) ($filters['pid'] ?? ''));
        $click_id = trim((string) ($filters['click_id'] ?? ''));
        $tksource = trim((string) ($filters['tksource'] ?? ''));
        $tkzone = trim((string) ($filters['tkzone'] ?? ''));

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

        if ($flow_key !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($flow_key): bool {
                return (string) ($row['flow_key'] ?? '') === $flow_key;
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

        if ($tksource !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($tksource): bool {
                return (string) ($row['tksource'] ?? '') === $tksource;
            }));
        }

        if ($tkzone !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($tkzone): bool {
                return (string) ($row['tkzone'] ?? '') === $tkzone;
            }));
        }

        if (!empty($filters['flagged_only'])) {
            $rows = array_values(array_filter($rows, static function (array $row): bool {
                return !empty($row['is_soft_flag']);
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

class Kiwi_Test_Landing_Handoff_Event_Repository extends Kiwi_Landing_Handoff_Event_Repository
{
    public $rows = [];
    private $next_id = 1;

    public function create_table(): void
    {
    }

    public function insert_if_new(array $event): array
    {
        $landing_key = trim((string) ($event['landing_key'] ?? ''));
        $session_token = trim((string) ($event['session_token'] ?? ''));
        $handoff_id = preg_replace('/[^A-Za-z0-9._:-]/', '', trim((string) ($event['handoff_id'] ?? '')));
        $handoff_id = is_string($handoff_id) ? substr($handoff_id, 0, 100) : '';
        $event_type = strtolower(trim((string) ($event['event_type'] ?? '')));

        if ($landing_key === '' || $session_token === '' || $handoff_id === '' || !in_array($event_type, [
            'sms_handoff_attempted',
            'sms_handoff_hidden',
            'sms_handoff_returned',
            'sms_handoff_no_hide',
        ], true)) {
            return [
                'inserted' => false,
                'row' => null,
            ];
        }

        foreach ($this->rows as $row) {
            if (($row['landing_key'] ?? '') === $landing_key
                && ($row['session_token'] ?? '') === $session_token
                && ($row['handoff_id'] ?? '') === $handoff_id
                && ($row['event_type'] ?? '') === $event_type
            ) {
                return [
                    'inserted' => false,
                    'row' => $row,
                ];
            }
        }

        $id = $this->next_id++;
        $row = [
            'id' => $id,
            'created_at' => '2026-04-01 12:00:00',
            'landing_key' => $landing_key,
            'service_key' => (string) ($event['service_key'] ?? ''),
            'provider_key' => (string) ($event['provider_key'] ?? ''),
            'flow_key' => (string) ($event['flow_key'] ?? ''),
            'pid' => (string) ($event['pid'] ?? ''),
            'click_id' => (string) ($event['click_id'] ?? ''),
            'tksource' => (string) ($event['tksource'] ?? ''),
            'tkzone' => (string) ($event['tkzone'] ?? ''),
            'session_token' => $session_token,
            'handoff_id' => $handoff_id,
            'event_type' => $event_type,
            'href_scheme' => (string) ($event['href_scheme'] ?? ''),
            'sms_recipient' => (string) ($event['sms_recipient'] ?? ''),
            'sms_body_present' => !empty($event['sms_body_present']) ? 1 : 0,
            'sms_body_has_transaction' => !empty($event['sms_body_has_transaction']) ? 1 : 0,
            'elapsed_ms' => max(0, (int) ($event['elapsed_ms'] ?? 0)),
            'visibility_state' => (string) ($event['visibility_state'] ?? ''),
            'ua_ch_supported' => !empty($event['ua_ch_supported']) ? 1 : 0,
            'ua_ch_mobile' => !empty($event['ua_ch_mobile']) ? 1 : 0,
            'ua_ch_platform' => (string) ($event['ua_ch_platform'] ?? ''),
            'ua_ch_platform_version' => (string) ($event['ua_ch_platform_version'] ?? ''),
            'ua_ch_model' => (string) ($event['ua_ch_model'] ?? ''),
            'ua_ch_brands' => (string) ($event['ua_ch_brands'] ?? ''),
            'ua_ch_full_version_list' => (string) ($event['ua_ch_full_version_list'] ?? ''),
            'user_agent' => (string) ($event['user_agent'] ?? ''),
            'raw_context' => $event['raw_context'] ?? [],
        ];
        $this->rows[$id] = $row;

        return [
            'inserted' => true,
            'row' => $row,
        ];
    }

    public function get_recent(array $filters = [], int $limit = 100): array
    {
        $rows = array_values($this->rows);

        foreach (['landing_key', 'service_key', 'provider_key', 'flow_key', 'event_type', 'handoff_id', 'pid', 'click_id', 'tksource', 'tkzone'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));

            if ($value === '') {
                continue;
            }

            $rows = array_values(array_filter($rows, static function (array $row) use ($field, $value): bool {
                return (string) ($row[$field] ?? '') === $value;
            }));
        }

        return array_slice($rows, 0, max(1, min(500, $limit)));
    }
}

class Kiwi_Test_Traffic_Source_Funnel_Statistics_Repository extends Kiwi_Traffic_Source_Funnel_Statistics_Repository
{
    public $rows = [];
    public $calls = [];
    public $filter_options = [
        'service_keys' => [],
        'tksources' => [],
    ];
    public $error = '';
    public $view_name = 'wp_kiwi_v_load_to_cta_by_tksource_tkzone';

    public function get_rows(array $filters = [], int $limit = 100): array
    {
        $this->calls[] = [
            'filters' => $filters,
            'limit' => $limit,
        ];

        return $this->rows;
    }

    public function get_filter_options(array $filters = []): array
    {
        return $this->filter_options;
    }

    public function get_last_error(): string
    {
        return $this->error;
    }

    public function get_view_name(): string
    {
        return $this->view_name;
    }
}

class Kiwi_Test_Landing_Funnel_Daily_Summary_Repository extends Kiwi_Landing_Funnel_Daily_Summary_Repository
{
    public $rows = [];
    public $calls = [];
    public $filter_options = [
        'service_keys' => [],
        'landing_keys' => [],
        'tksources' => [],
        'device_brands' => [],
        'os_values' => [],
        'os_versions' => [],
        'browsers' => [],
    ];
    public $error = '';
    public $source_name = 'wp_kiwi_landing_funnel_daily_summary';

    public function get_rows(array $filters = [], int $limit = 100): array
    {
        $this->calls[] = [
            'filters' => $filters,
            'limit' => $limit,
        ];

        return $this->rows;
    }

    public function get_filter_options(array $filters = []): array
    {
        return $this->filter_options;
    }

    public function get_last_error(): string
    {
        return $this->error;
    }

    public function get_source_name(): string
    {
        return $this->source_name;
    }
}

class Kiwi_Test_Sms_Body_Variant_Repository extends Kiwi_Sms_Body_Variant_Repository
{
    public $assignments = [];
    public $summary = [];
    public $create_table_called = 0;
    private $next_id = 1;

    public function create_table(): void
    {
        $this->create_table_called++;
    }

    public function insert_if_new(array $assignment): array
    {
        $transaction_id = trim((string) ($assignment['transaction_id'] ?? ''));
        $visible_token = trim((string) ($assignment['visible_token'] ?? ''));
        $variant_key = trim((string) ($assignment['variant_key'] ?? ''));

        if ($transaction_id === '' || $visible_token === '' || $variant_key === '') {
            return [
                'inserted' => false,
                'row' => null,
            ];
        }

        $existing = $this->find_by_transaction_id($transaction_id);

        if (is_array($existing)) {
            return [
                'inserted' => false,
                'row' => $existing,
            ];
        }

        if (is_array($this->find_by_visible_token($visible_token))) {
            return [
                'inserted' => false,
                'row' => null,
            ];
        }

        $id = $this->next_id++;
        $row = [
            'id' => $id,
            'created_at' => '2026-04-01 12:00:00',
            'updated_at' => '2026-04-01 12:00:00',
            'landing_key' => (string) ($assignment['landing_key'] ?? ''),
            'service_key' => (string) ($assignment['service_key'] ?? ''),
            'provider_key' => (string) ($assignment['provider_key'] ?? ''),
            'flow_key' => (string) ($assignment['flow_key'] ?? ''),
            'country' => (string) ($assignment['country'] ?? ''),
            'keyword' => (string) ($assignment['keyword'] ?? ''),
            'shortcode' => (string) ($assignment['shortcode'] ?? ''),
            'pid' => (string) ($assignment['pid'] ?? ''),
            'click_id' => (string) ($assignment['click_id'] ?? ''),
            'session_token' => (string) ($assignment['session_token'] ?? ''),
            'transaction_id' => $transaction_id,
            'visible_token' => $visible_token,
            'variant_key' => $variant_key,
            'seed' => (string) ($assignment['seed'] ?? ''),
            'sms_body' => (string) ($assignment['sms_body'] ?? ''),
            'cta1_recorded_at' => '',
            'handoff_attempted_at' => '',
            'handoff_hidden_at' => '',
            'handoff_no_hide_at' => '',
            'handoff_returned_at' => '',
            'conv_recorded_at' => '',
        ];
        $this->assignments[$id] = $row;
        $this->increment_summary($row, 'assignments');

        return [
            'inserted' => true,
            'row' => $row,
        ];
    }

    public function find_by_transaction_id(string $transaction_id): ?array
    {
        foreach ($this->assignments as $row) {
            if ((string) ($row['transaction_id'] ?? '') === $transaction_id) {
                return $row;
            }
        }

        return null;
    }

    public function find_by_visible_token(string $visible_token): ?array
    {
        foreach ($this->assignments as $row) {
            if ((string) ($row['visible_token'] ?? '') === $visible_token) {
                return $row;
            }
        }

        return null;
    }

    public function find_latest_by_landing_session(string $landing_key, string $session_token): ?array
    {
        $matches = [];

        foreach ($this->assignments as $row) {
            if ((string) ($row['landing_key'] ?? '') === $landing_key
                && (string) ($row['session_token'] ?? '') === $session_token
            ) {
                $matches[] = $row;
            }
        }

        if (empty($matches)) {
            return null;
        }

        usort($matches, static function (array $left, array $right): int {
            return (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
        });

        return $matches[0];
    }

    public function mark_event_by_landing_session(string $landing_key, string $session_token, string $event_key): bool
    {
        $assignment = $this->find_latest_by_landing_session($landing_key, $session_token);

        if (!is_array($assignment)) {
            return false;
        }

        return $this->mark_event_by_transaction_id((string) ($assignment['transaction_id'] ?? ''), $event_key);
    }

    public function mark_event_by_transaction_id(string $transaction_id, string $event_key): bool
    {
        $field_map = [
            'cta1' => ['field' => 'cta1_recorded_at', 'counter' => 'cta1'],
            'sms_handoff_attempted' => ['field' => 'handoff_attempted_at', 'counter' => 'handoff_attempted'],
            'sms_handoff_hidden' => ['field' => 'handoff_hidden_at', 'counter' => 'handoff_hidden'],
            'sms_handoff_no_hide' => ['field' => 'handoff_no_hide_at', 'counter' => 'handoff_no_hide'],
            'sms_handoff_returned' => ['field' => 'handoff_returned_at', 'counter' => 'handoff_returned'],
            'conv' => ['field' => 'conv_recorded_at', 'counter' => 'conv'],
        ];

        if (!isset($field_map[$event_key])) {
            return false;
        }

        foreach ($this->assignments as $id => $row) {
            if ((string) ($row['transaction_id'] ?? '') !== $transaction_id) {
                continue;
            }

            $field = $field_map[$event_key]['field'];

            if ((string) ($row[$field] ?? '') !== '') {
                return false;
            }

            $this->assignments[$id][$field] = '2026-04-01 12:00:00';
            $this->increment_summary($this->assignments[$id], $field_map[$event_key]['counter']);

            return true;
        }

        return false;
    }

    public function get_summary_rows(array $filters = []): array
    {
        return array_values($this->summary);
    }

    private function increment_summary(array $assignment, string $counter): void
    {
        $key = implode('|', [
            (string) ($assignment['landing_key'] ?? ''),
            (string) ($assignment['service_key'] ?? ''),
            (string) ($assignment['variant_key'] ?? ''),
            (string) ($assignment['seed'] ?? ''),
        ]);

        if (!isset($this->summary[$key])) {
            $this->summary[$key] = [
                'landing_key' => (string) ($assignment['landing_key'] ?? ''),
                'service_key' => (string) ($assignment['service_key'] ?? ''),
                'provider_key' => (string) ($assignment['provider_key'] ?? ''),
                'flow_key' => (string) ($assignment['flow_key'] ?? ''),
                'variant_key' => (string) ($assignment['variant_key'] ?? ''),
                'seed' => (string) ($assignment['seed'] ?? ''),
                'assignments' => 0,
                'cta1' => 0,
                'handoff_attempted' => 0,
                'handoff_hidden' => 0,
                'handoff_no_hide' => 0,
                'handoff_returned' => 0,
                'conv' => 0,
            ];
        }

        if (array_key_exists($counter, $this->summary[$key])) {
            $this->summary[$key][$counter]++;
        }
    }
}

class Kiwi_Test_Landing_Page_Session_Repository extends Kiwi_Landing_Page_Session_Repository
{
    public $rows = [];
    private $next_id = 1;

    public function create_table(): void
    {
    }

    public function insert(array $data): bool
    {
        $id = $this->next_id++;
        $this->rows[$id] = array_merge(
            [
                'id' => $id,
                'created_at' => '2026-04-01 12:00:00',
                'landing_key' => '',
                'service_key' => '',
                'provider_key' => '',
                'flow_key' => '',
                'country' => '',
                'pid' => '',
                'tksource' => '',
                'tkzone' => '',
                'browser_language' => '(unknown)',
                'device_brand' => '(unknown)',
                'os' => '(unknown)',
                'os_version' => '(unknown)',
                'browser' => '(unknown)',
                'request_host' => '',
                'request_path' => '',
                'session_token' => '',
                'click_to_sms_uri' => '',
                'referer' => '',
                'user_agent' => '',
                'remote_ip' => '',
                'client_ip_version' => '(unknown)',
                'client_ip_prefix' => '(unknown)',
                'query_params' => [],
                'raw_context' => [],
            ],
            $data,
            ['id' => $id]
        );

        return true;
    }

    public function find_by_landing_session(string $landing_key, string $session_token): ?array
    {
        foreach (array_reverse($this->rows, true) as $row) {
            if ((string) ($row['landing_key'] ?? '') !== $landing_key) {
                continue;
            }

            if ((string) ($row['session_token'] ?? '') !== $session_token) {
                continue;
            }

            return $row;
        }

        return null;
    }

    public function find_by_session_token(string $session_token, string $service_key = ''): ?array
    {
        foreach (array_reverse($this->rows, true) as $row) {
            if ((string) ($row['session_token'] ?? '') !== $session_token) {
                continue;
            }

            if ($service_key !== '' && (string) ($row['service_key'] ?? '') !== $service_key) {
                continue;
            }

            return $row;
        }

        return null;
    }

    public function enrich_device_context_by_landing_session(
        string $landing_key,
        string $session_token,
        array $device_context,
        ?Kiwi_Device_Context_Normalizer $normalizer = null
    ): bool {
        $normalizer = $normalizer instanceof Kiwi_Device_Context_Normalizer
            ? $normalizer
            : new Kiwi_Device_Context_Normalizer();
        $updated = false;

        foreach ($this->rows as $id => $row) {
            if ((string) ($row['landing_key'] ?? '') !== $landing_key) {
                continue;
            }

            if ((string) ($row['session_token'] ?? '') !== $session_token) {
                continue;
            }

            $merged = $normalizer->merge($row, $device_context);

            foreach (['device_brand', 'os', 'os_version', 'browser'] as $field) {
                if ((string) ($this->rows[$id][$field] ?? '') === (string) ($merged[$field] ?? '')) {
                    continue;
                }

                $this->rows[$id][$field] = (string) ($merged[$field] ?? '(unknown)');
                $updated = true;
            }
        }

        return $updated;
    }
}

class Kiwi_Test_Device_Model_Brand_Map_Repository extends Kiwi_Device_Model_Brand_Map_Repository
{
    public $brands_by_model_key = [];

    public function find_brand_for_model(string $model): string
    {
        $model_key = $this->normalize_model_key($model);

        return (string) ($this->brands_by_model_key[$model_key] ?? '');
    }
}

class Kiwi_Test_Wpdb_Sms_Body_Variant
{
    public $prefix = 'wp_';
    public $tables = [];

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        return [
            'query' => (string) $query,
            'args' => $args,
        ];
    }

    public function insert(string $table, array $data, array $formats = [])
    {
        if (!isset($this->tables[$table])) {
            $this->tables[$table] = [];
        }

        $id = count($this->tables[$table]) + 1;
        $this->tables[$table][$id] = array_merge(['id' => $id], $data);

        return 1;
    }

    public function get_row($statement, $output = null)
    {
        $query = is_array($statement) ? (string) ($statement['query'] ?? '') : (string) $statement;
        $args = is_array($statement) ? (array) ($statement['args'] ?? []) : [];
        $assignment_table = $this->prefix . 'kiwi_sms_body_variant_assignments';
        $rows = $this->tables[$assignment_table] ?? [];

        if (strpos($query, 'transaction_id = %s') !== false) {
            $transaction_id = (string) ($args[0] ?? '');

            foreach ($rows as $row) {
                if ((string) ($row['transaction_id'] ?? '') === $transaction_id) {
                    return $row;
                }
            }
        }

        if (strpos($query, 'visible_token = %s') !== false) {
            $visible_token = (string) ($args[0] ?? '');

            foreach ($rows as $row) {
                if ((string) ($row['visible_token'] ?? '') === $visible_token) {
                    return $row;
                }
            }
        }

        if (strpos($query, 'landing_key = %s') !== false && strpos($query, 'session_token = %s') !== false) {
            $landing_key = (string) ($args[0] ?? '');
            $session_token = (string) ($args[1] ?? '');
            $matches = [];

            foreach ($rows as $row) {
                if ((string) ($row['landing_key'] ?? '') === $landing_key
                    && (string) ($row['session_token'] ?? '') === $session_token
                ) {
                    $matches[] = $row;
                }
            }

            usort($matches, static function (array $left, array $right): int {
                return (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
            });

            return $matches[0] ?? null;
        }

        return null;
    }

    public function get_results($statement, $output = null): array
    {
        $summary_table = $this->prefix . 'kiwi_sms_body_variant_summary';

        return array_values($this->tables[$summary_table] ?? []);
    }

    public function query($statement)
    {
        $query = is_array($statement) ? (string) ($statement['query'] ?? '') : (string) $statement;
        $args = is_array($statement) ? (array) ($statement['args'] ?? []) : [];
        $assignment_table = $this->prefix . 'kiwi_sms_body_variant_assignments';
        $summary_table = $this->prefix . 'kiwi_sms_body_variant_summary';

        if (stripos($query, "UPDATE {$assignment_table}") !== false
            && preg_match('/SET\s+updated_at\s*=\s*%s,\s*([a-z0-9_]+)\s*=\s*%s/i', $query, $matches) === 1
        ) {
            $field = (string) ($matches[1] ?? '');
            $transaction_id = (string) ($args[2] ?? '');

            foreach ($this->tables[$assignment_table] ?? [] as $id => $row) {
                if ((string) ($row['transaction_id'] ?? '') !== $transaction_id) {
                    continue;
                }

                if ((string) ($row[$field] ?? '') !== '') {
                    return 0;
                }

                $this->tables[$assignment_table][$id]['updated_at'] = (string) ($args[0] ?? '');
                $this->tables[$assignment_table][$id][$field] = (string) ($args[1] ?? '');

                return 1;
            }

            return 0;
        }

        if (stripos($query, "INSERT INTO {$summary_table}") !== false) {
            $row_id = $this->find_summary_row_id(
                (string) ($args[2] ?? ''),
                (string) ($args[3] ?? ''),
                (string) ($args[6] ?? ''),
                (string) ($args[7] ?? '')
            );

            if ($row_id === null) {
                $row_id = count($this->tables[$summary_table] ?? []) + 1;
                $this->tables[$summary_table][$row_id] = [
                    'id' => $row_id,
                    'created_at' => (string) ($args[0] ?? ''),
                    'updated_at' => (string) ($args[1] ?? ''),
                    'landing_key' => (string) ($args[2] ?? ''),
                    'service_key' => (string) ($args[3] ?? ''),
                    'provider_key' => (string) ($args[4] ?? ''),
                    'flow_key' => (string) ($args[5] ?? ''),
                    'variant_key' => (string) ($args[6] ?? ''),
                    'seed' => (string) ($args[7] ?? ''),
                    'assignments' => 0,
                    'cta1' => 0,
                    'handoff_attempted' => 0,
                    'handoff_hidden' => 0,
                    'handoff_no_hide' => 0,
                    'handoff_returned' => 0,
                    'conv' => 0,
                    'cta1_cr' => 0.0,
                    'handoff_hidden_cr' => 0.0,
                    'conv_cr' => 0.0,
                    'conv_per_cta1_cr' => 0.0,
                    'conv_per_hidden_cr' => 0.0,
                ];
            } else {
                $this->tables[$summary_table][$row_id]['updated_at'] = (string) ($args[1] ?? '');
            }

            return 1;
        }

        if (preg_match('/SET\s+updated_at\s*=\s*%s,\s*([a-z0-9_]+)\s*=\s*\1\s*\+\s*1/i', $query, $matches) === 1) {
            $counter = (string) ($matches[1] ?? '');
            $row_id = $this->find_summary_row_id(
                (string) ($args[1] ?? ''),
                (string) ($args[2] ?? ''),
                (string) ($args[3] ?? ''),
                (string) ($args[4] ?? '')
            );

            if ($row_id === null || !array_key_exists($counter, $this->tables[$summary_table][$row_id])) {
                return 0;
            }

            $this->tables[$summary_table][$row_id]['updated_at'] = (string) ($args[0] ?? '');
            $this->tables[$summary_table][$row_id][$counter] = (int) $this->tables[$summary_table][$row_id][$counter] + 1;

            return 1;
        }

        if (strpos($query, 'cta1_cr = CASE WHEN assignments > 0') !== false) {
            $row_id = $this->find_summary_row_id(
                (string) ($args[1] ?? ''),
                (string) ($args[2] ?? ''),
                (string) ($args[3] ?? ''),
                (string) ($args[4] ?? '')
            );

            if ($row_id === null) {
                return 0;
            }

            $this->tables[$summary_table][$row_id]['updated_at'] = (string) ($args[0] ?? '');
            $this->recalculate_summary_rates($summary_table, $row_id);

            return 1;
        }

        return false;
    }

    private function find_summary_row_id(string $landing_key, string $service_key, string $variant_key, string $seed): ?int
    {
        $summary_table = $this->prefix . 'kiwi_sms_body_variant_summary';

        foreach ($this->tables[$summary_table] ?? [] as $id => $row) {
            if ((string) ($row['landing_key'] ?? '') === $landing_key
                && (string) ($row['service_key'] ?? '') === $service_key
                && (string) ($row['variant_key'] ?? '') === $variant_key
                && (string) ($row['seed'] ?? '') === $seed
            ) {
                return (int) $id;
            }
        }

        return null;
    }

    private function recalculate_summary_rates(string $summary_table, int $row_id): void
    {
        $row = $this->tables[$summary_table][$row_id] ?? [];
        $assignments = (int) ($row['assignments'] ?? 0);
        $cta1 = (int) ($row['cta1'] ?? 0);
        $handoff_attempted = (int) ($row['handoff_attempted'] ?? 0);
        $handoff_hidden = (int) ($row['handoff_hidden'] ?? 0);
        $conv = (int) ($row['conv'] ?? 0);

        $this->tables[$summary_table][$row_id]['cta1_cr'] = $this->rate($cta1, $assignments);
        $this->tables[$summary_table][$row_id]['handoff_hidden_cr'] = $this->rate($handoff_hidden, $handoff_attempted);
        $this->tables[$summary_table][$row_id]['conv_cr'] = $this->rate($conv, $assignments);
        $this->tables[$summary_table][$row_id]['conv_per_cta1_cr'] = $this->rate($conv, $cta1);
        $this->tables[$summary_table][$row_id]['conv_per_hidden_cr'] = $this->rate($conv, $handoff_hidden);
    }

    private function rate(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 2);
    }
}

class Kiwi_Test_Wpdb_Landing_Handoff_Event
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $tables = [];
    public $queries = [];
    public $insert_calls = 0;

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        return [
            'query' => (string) $query,
            'args' => $args,
        ];
    }

    public function insert(string $table, array $data, array $formats = [])
    {
        $this->insert_calls++;

        return false;
    }

    public function query($statement)
    {
        $query = is_array($statement) ? (string) ($statement['query'] ?? '') : (string) $statement;
        $args = is_array($statement) ? (array) ($statement['args'] ?? []) : [];
        $this->queries[] = $query;

        $table = $this->prefix . 'kiwi_landing_handoff_events';

        if (stripos($query, "INSERT INTO `{$table}`") === false) {
            return 0;
        }

        $columns = [
            'created_at',
            'landing_key',
            'service_key',
            'provider_key',
            'flow_key',
            'pid',
            'click_id',
            'tksource',
            'tkzone',
            'session_token',
            'handoff_id',
            'event_type',
            'href_scheme',
            'sms_recipient',
            'sms_body_present',
            'sms_body_has_transaction',
            'elapsed_ms',
            'visibility_state',
            'ua_ch_supported',
            'ua_ch_mobile',
            'ua_ch_platform',
            'ua_ch_platform_version',
            'ua_ch_model',
            'ua_ch_brands',
            'ua_ch_full_version_list',
            'user_agent',
            'raw_context',
        ];
        $row = [];

        foreach ($columns as $index => $column) {
            $row[$column] = $args[$index] ?? null;
        }

        if (!isset($this->tables[$table])) {
            $this->tables[$table] = [];
        }

        foreach ($this->tables[$table] as $id => $existing) {
            if ((string) ($existing['landing_key'] ?? '') === (string) ($row['landing_key'] ?? '')
                && (string) ($existing['session_token'] ?? '') === (string) ($row['session_token'] ?? '')
                && (string) ($existing['handoff_id'] ?? '') === (string) ($row['handoff_id'] ?? '')
                && (string) ($existing['event_type'] ?? '') === (string) ($row['event_type'] ?? '')
            ) {
                $this->insert_id = (int) $id;

                return 0;
            }
        }

        $id = count($this->tables[$table]) + 1;
        $row['id'] = $id;
        $this->tables[$table][$id] = $row;
        $this->insert_id = $id;

        return 1;
    }

    public function get_row($statement, $output = null)
    {
        $query = is_array($statement) ? (string) ($statement['query'] ?? '') : (string) $statement;
        $args = is_array($statement) ? (array) ($statement['args'] ?? []) : [];
        $table = $this->prefix . 'kiwi_landing_handoff_events';
        $rows = $this->tables[$table] ?? [];

        if (strpos($query, 'WHERE id = %d') !== false) {
            return $rows[(int) ($args[0] ?? 0)] ?? null;
        }

        if (strpos($query, 'landing_key = %s') !== false
            && strpos($query, 'session_token = %s') !== false
            && strpos($query, 'handoff_id = %s') !== false
            && strpos($query, 'event_type = %s') !== false
        ) {
            foreach ($rows as $row) {
                if ((string) ($row['landing_key'] ?? '') === (string) ($args[0] ?? '')
                    && (string) ($row['session_token'] ?? '') === (string) ($args[1] ?? '')
                    && (string) ($row['handoff_id'] ?? '') === (string) ($args[2] ?? '')
                    && (string) ($row['event_type'] ?? '') === (string) ($args[3] ?? '')
                ) {
                    return $row;
                }
            }
        }

        return null;
    }
}

class Kiwi_Test_Wpdb_Traffic_Source_Statistics
{
    public $prefix = 'wp_';
    public $last_error = '';
    public $queries = [];
    public $prepared_statements = [];
    public $result_rows = [];
    public $result_rows_queue = [];

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $statement = [
            'query' => (string) $query,
            'args' => $args,
        ];
        $this->prepared_statements[] = $statement;

        return $statement;
    }

    public function query($statement)
    {
        $query = is_array($statement) ? (string) ($statement['query'] ?? '') : (string) $statement;
        $this->queries[] = $query;

        return 1;
    }

    public function get_results($statement, $output = null): array
    {
        $this->prepared_statements[] = is_array($statement)
            ? $statement
            : [
                'query' => (string) $statement,
                'args' => [],
            ];

        if (!empty($this->result_rows_queue)) {
            return array_shift($this->result_rows_queue);
        }

        return $this->result_rows;
    }
}

class Kiwi_Test_Wpdb_Landing_Funnel_Daily_Summary
{
    public $prefix = 'wp_';
    public $last_error = '';
    public $queries = [];
    public $prepared_statements = [];
    public $result_rows = [];
    public $result_rows_queue = [];
    public $prepare_failure_prefix = '';
    public $query_failure_prefix = '';

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $statement = [
            'query' => (string) $query,
            'args' => $args,
        ];
        $this->prepared_statements[] = $statement;

        if ($this->prepare_failure_prefix !== '' && stripos((string) $query, $this->prepare_failure_prefix) === 0) {
            return false;
        }

        return $statement;
    }

    public function query($statement)
    {
        $query = is_array($statement) ? (string) ($statement['query'] ?? '') : (string) $statement;
        $args = is_array($statement) ? (array) ($statement['args'] ?? []) : [];
        $this->queries[] = [
            'query' => $query,
            'args' => $args,
        ];

        if ($this->query_failure_prefix !== '' && stripos($query, $this->query_failure_prefix) === 0) {
            return false;
        }

        if (stripos($query, 'DELETE FROM') === 0) {
            return 4;
        }

        if (stripos($query, 'INSERT INTO') === 0) {
            return 6;
        }

        return 0;
    }

    public function get_results($statement, $output = null): array
    {
        $this->prepared_statements[] = is_array($statement)
            ? $statement
            : [
                'query' => (string) $statement,
                'args' => [],
            ];

        if (!empty($this->result_rows_queue)) {
            return array_shift($this->result_rows_queue);
        }

        return $this->result_rows;
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
    public $snapshot_updates = [];
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

    public function find_recent_completed_one_off_sale_by_subscriber_context(
        string $service_key,
        string $subscriber_reference,
        string $shortcode,
        string $keyword,
        int $days
    ): ?array {
        $days = max(0, $days);

        if ($days === 0) {
            return null;
        }

        $matches = [];

        foreach ($this->rows as $row) {
            if ((string) ($row['status'] ?? '') !== 'completed') {
                continue;
            }

            if ((string) ($row['sale_type'] ?? '') !== 'premium_sms_one_off') {
                continue;
            }

            if ((string) ($row['service_key'] ?? '') !== $service_key) {
                continue;
            }

            if ((string) ($row['subscriber_reference'] ?? '') !== $subscriber_reference) {
                continue;
            }

            if ((string) ($row['shortcode'] ?? '') !== $shortcode) {
                continue;
            }

            if ((string) ($row['keyword'] ?? '') !== $keyword) {
                continue;
            }

            $completed_at = strtotime((string) ($row['completed_at'] ?? ''));

            if ($completed_at === false || $completed_at < strtotime('2026-04-01 12:00:00') - $days * 86400) {
                continue;
            }

            $matches[] = $row;
        }

        usort($matches, static function (array $left, array $right): int {
            return strcmp((string) ($right['completed_at'] ?? ''), (string) ($left['completed_at'] ?? ''));
        });

        return $matches[0] ?? null;
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

    public function update_attribution_snapshot_by_sale_reference(string $sale_reference, array $snapshot): bool
    {
        $sale_reference = trim($sale_reference);

        if ($sale_reference === '') {
            return false;
        }

        foreach ($this->rows as $index => $row) {
            if ((string) ($row['sale_reference'] ?? '') !== $sale_reference) {
                continue;
            }

            $context = $row['context_json'] ?? [];

            if (!is_array($context)) {
                $context = [];
            }

            if (isset($snapshot['attribution_snapshot']) && is_array($snapshot['attribution_snapshot'])) {
                $context['attribution_snapshot'] = $snapshot['attribution_snapshot'];
            }

            $this->rows[$index] = array_merge($row, $snapshot);
            $this->rows[$index]['context_json'] = $context;
            $this->snapshot_updates[] = [
                'sale_reference' => $sale_reference,
                'snapshot' => $snapshot,
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

    public function update_attribution_snapshot_by_sale_reference(string $sale_reference, array $snapshot): bool
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
    kiwi_assert_true($config->is_landing_handoff_ua_client_hints_enabled(), 'Expected landing handoff UA Client Hints telemetry to default to enabled.');
    kiwi_assert_true($config->is_client_ip_resolution_debug_enabled(), 'Expected client IP resolution debug diagnostics to be temporarily enabled by default.');
});

kiwi_run_test('Kiwi_Client_Ip_Resolver ignores forwarded headers without a trusted proxy', function (): void {
    $resolver = new Kiwi_Client_Ip_Resolver();
    $result = $resolver->resolve([
        'REMOTE_ADDR' => '198.51.100.77',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.44',
    ], []);

    kiwi_assert_same('198.51.100.77', $result['client_ip'] ?? '', 'Expected untrusted forwarded headers to be ignored.');
    kiwi_assert_same('ipv4', $result['client_ip_version'] ?? '', 'Expected direct IPv4 peer to be normalized.');
    kiwi_assert_same('198.51.100.0/24', $result['client_ip_prefix'] ?? '', 'Expected direct IPv4 peer to be bucketed as /24.');
    kiwi_assert_same('remote_addr', $result['source'] ?? '', 'Expected source to remain REMOTE_ADDR when no trusted proxy is configured.');
});

kiwi_run_test('Kiwi_Client_Ip_Resolver uses trusted proxy chains defensively', function (): void {
    $resolver = new Kiwi_Client_Ip_Resolver();
    $result = $resolver->resolve([
        'REMOTE_ADDR' => '10.0.0.8',
        'HTTP_X_FORWARDED_FOR' => '192.0.2.9, 203.0.113.44, 10.0.0.7',
    ], ['10.0.0.0/24']);
    $missing_forwarded = $resolver->resolve([
        'REMOTE_ADDR' => '10.0.0.8',
    ], ['10.0.0.0/24']);
    $trusted_only_forwarded = $resolver->resolve([
        'REMOTE_ADDR' => '10.0.0.8',
        'HTTP_X_FORWARDED_FOR' => '10.0.0.7',
    ], ['10.0.0.0/24']);

    kiwi_assert_same('203.0.113.44', $result['client_ip'] ?? '', 'Expected the right-most non-trusted forwarded IP to win.');
    kiwi_assert_same('203.0.113.0/24', $result['client_ip_prefix'] ?? '', 'Expected trusted XFF client to be bucketed as IPv4 /24.');
    kiwi_assert_same('x_forwarded_for', $result['source'] ?? '', 'Expected X-Forwarded-For to be recorded as the resolution source.');
    kiwi_assert_true((bool) ($result['peer_trusted'] ?? false), 'Expected direct peer to be marked trusted.');
    kiwi_assert_same('', $missing_forwarded['client_ip'] ?? '', 'Expected trusted proxy peers without forwarded clients not to be bucketed as clients.');
    kiwi_assert_same('(unknown)', $missing_forwarded['client_ip_version'] ?? '', 'Expected trusted proxy peers without forwarded clients to use unknown IP version.');
    kiwi_assert_same('(unknown)', $missing_forwarded['client_ip_prefix'] ?? '', 'Expected trusted proxy peers without forwarded clients to use unknown IP prefix.');
    kiwi_assert_same('trusted_proxy_missing_forwarded_client', $missing_forwarded['source'] ?? '', 'Expected trusted proxy missing forwarded source marker.');
    kiwi_assert_true((bool) ($missing_forwarded['peer_trusted'] ?? false), 'Expected missing forwarded snapshot to keep trusted peer marker.');
    kiwi_assert_same('', $trusted_only_forwarded['client_ip'] ?? '', 'Expected trusted-only forwarded chains not to bucket the proxy as client.');
});

kiwi_run_test('Kiwi_Client_Ip_Resolver trusts exact IPv6 proxy rules and exposes safe diagnostics', function (): void {
    $resolver = new Kiwi_Client_Ip_Resolver();
    $result = $resolver->resolve([
        'REMOTE_ADDR' => '2a02:4780:79:a1e9::1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.44, 2a02:4780:79:a1e9::1',
    ], ['2a02:4780:79:a1e9::1']);
    $diagnostic = $resolver->build_debug_context([
        'REMOTE_ADDR' => '2a02:4780:79:a1e9::1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.44, 2a02:4780:79:a1e9::1',
        'HTTP_FORWARDED' => 'for=198.51.100.12;proto=https',
        'HTTP_X_REAL_IP' => '192.0.2.77',
        'HTTP_CF_CONNECTING_IP' => '198.51.100.25',
        'HTTP_TRUE_CLIENT_IP' => '198.51.100.26',
    ], ['2a02:4780:79:a1e9::1']);
    $encoded_diagnostic = json_encode($diagnostic);
    $encoded_diagnostic = is_string($encoded_diagnostic) ? $encoded_diagnostic : '';

    kiwi_assert_same('203.0.113.44', $result['client_ip'] ?? '', 'Expected exact Hostinger-style IPv6 proxy trust to allow X-Forwarded-For client resolution.');
    kiwi_assert_same('203.0.113.0/24', $result['client_ip_prefix'] ?? '', 'Expected exact IPv6 proxy trust to keep coarse IPv4 client buckets.');
    kiwi_assert_true((bool) ($result['peer_trusted'] ?? false), 'Expected exact IPv6 proxy rule to mark the direct peer trusted.');
    kiwi_assert_same(true, $diagnostic['trusted_proxy_configured'] ?? null, 'Expected debug context to report configured trusted proxies.');
    kiwi_assert_same(['x_forwarded_for', 'forwarded', 'x_real_ip'], $diagnostic['forwarded_headers_present'] ?? [], 'Expected debug context to expose only supported header names.');
    kiwi_assert_same(['cf_connecting_ip', 'true_client_ip'], $diagnostic['other_client_ip_headers_present'] ?? [], 'Expected debug context to expose unsupported client-IP header names only.');
    kiwi_assert_same(4, $diagnostic['forwarded_candidate_count'] ?? null, 'Expected debug context to count valid forwarded IP candidates without storing them.');
    kiwi_assert_same('resolved_from_forwarded_header', $diagnostic['resolution_reason'] ?? '', 'Expected debug context to explain forwarded-header resolution.');
    kiwi_assert_true(strpos($encoded_diagnostic, '203.0.113.44') === false, 'Expected debug context not to store raw forwarded client IPs.');
    kiwi_assert_true(strpos($encoded_diagnostic, '2a02:4780:79:a1e9::1') === false, 'Expected debug context not to store raw proxy IPs.');
    kiwi_assert_true(strpos($encoded_diagnostic, '198.51.100.12') === false, 'Expected debug context not to store raw RFC Forwarded values.');
    kiwi_assert_true(strpos($encoded_diagnostic, '198.51.100.25') === false, 'Expected debug context not to store raw unsupported client-IP header values.');
});

kiwi_run_test('Kiwi_Client_Ip_Resolver accepts RFC Forwarded header only from trusted peers', function (): void {
    $resolver = new Kiwi_Client_Ip_Resolver();
    $trusted = $resolver->resolve([
        'REMOTE_ADDR' => '2001:db8:ffff::10',
        'HTTP_FORWARDED' => 'for="[2001:db8:85a3::8a2e:370:7334]";proto=https',
    ], ['2001:db8:ffff::/48']);
    $untrusted = $resolver->resolve([
        'REMOTE_ADDR' => '2001:db8:ffff::10',
        'HTTP_FORWARDED' => 'for=203.0.113.44;proto=https',
    ], []);

    kiwi_assert_same('2001:db8:85a3::8a2e:370:7334', $trusted['client_ip'] ?? '', 'Expected trusted Forwarded header to resolve IPv6 client.');
    kiwi_assert_same('forwarded', $trusted['source'] ?? '', 'Expected Forwarded header source to be recorded.');
    kiwi_assert_same('2001:db8:ffff::10', $untrusted['client_ip'] ?? '', 'Expected untrusted Forwarded headers to be ignored.');
});

kiwi_run_test('Kiwi_Client_Ip_Resolver normalizes IPv6 and unknown IP buckets', function (): void {
    $resolver = new Kiwi_Client_Ip_Resolver();
    $ipv6 = $resolver->resolve([
        'REMOTE_ADDR' => '2001:db8:85a3::8a2e:370:7334',
    ], []);
    $unknown = $resolver->resolve([
        'REMOTE_ADDR' => '',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.44',
    ], ['10.0.0.0/24']);

    kiwi_assert_same('ipv6', $ipv6['client_ip_version'] ?? '', 'Expected IPv6 version marker.');
    kiwi_assert_same('2001:db8:85a3::/48', $ipv6['client_ip_prefix'] ?? '', 'Expected IPv6 /48 prefix.');
    kiwi_assert_same('', $unknown['client_ip'] ?? '', 'Expected missing direct peer to avoid trusting forwarded headers.');
    kiwi_assert_same('(unknown)', $unknown['client_ip_version'] ?? '', 'Expected missing IP version to use the unknown bucket.');
    kiwi_assert_same('(unknown)', $unknown['client_ip_prefix'] ?? '', 'Expected missing IP prefix to use the unknown bucket.');
});

kiwi_run_test('Kiwi_Landing_Page_Registry discovers folder landing pages and parses metadata', function (): void {
    $project_root = kiwi_create_temp_directory('kiwi_lp_registry');

    try {
        kiwi_write_landing_page_fixture($project_root, 'lp2-fr');
        kiwi_write_landing_page_fixture($project_root, 'lp4-fr-img-preload-test', [
            'backend_path' => '/lp/fr/myjoyplay4-img-preload-test',
            'hostnames' => [],
        ]);

        $registry = new Kiwi_Landing_Page_Registry(
            $project_root . DIRECTORY_SEPARATOR . 'landing-pages',
            $project_root
        );
        $landing_pages = $registry->get_registry();
        $errors = $registry->get_errors();

        kiwi_assert_same([], $errors, 'Expected valid landing page folders to load without validation errors.');
        kiwi_assert_true(isset($landing_pages['lp2-fr']), 'Expected lp2-fr to be discovered from the filesystem.');
        kiwi_assert_true(isset($landing_pages['lp4-fr-img-preload-test']), 'Expected suffix test variants to be discovered from the filesystem.');
        kiwi_assert_same('nth-fr-one-off', $landing_pages['lp2-fr']['flow'] ?? '', 'Expected landing page flow metadata to be parsed from integration.php.');
        kiwi_assert_same('/lp/fr/myjoyplay4-img-preload-test', $landing_pages['lp4-fr-img-preload-test']['backend_path'] ?? '', 'Expected suffix test variant backend path metadata to be parsed.');
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

kiwi_run_test('Kiwi_Config keeps filesystem landing pages primary and disables legacy fallback by default', function (): void {
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
            true
        );

        $landing_pages = $config->get_landing_pages();

        kiwi_assert_same(
            'filesystem-flow',
            $landing_pages['lp2-fr']['flow'] ?? '',
            'Expected filesystem landing pages to override same-key legacy entries.'
        );
        kiwi_assert_same(
            false,
            isset($landing_pages['legacy-only-fr']),
            'Expected legacy-only landing pages to stay disabled unless the rollback fallback is explicit.'
        );
    } finally {
        kiwi_remove_directory($project_root);
    }
});

kiwi_run_test('Kiwi_Config allows explicit legacy fallback as a rollback switch', function (): void {
    $project_root = kiwi_create_temp_directory('kiwi_lp_fallback_on');

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
            true
        );

        $landing_pages = $config->get_landing_pages();

        kiwi_assert_same(
            'filesystem-flow',
            $landing_pages['lp2-fr']['flow'] ?? '',
            'Expected filesystem landing pages to remain primary when rollback fallback is explicit.'
        );
        kiwi_assert_same(
            'legacy-only-flow',
            $landing_pages['legacy-only-fr']['flow'] ?? '',
            'Expected explicit rollback fallback to restore unmigrated legacy landing pages.'
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
            "<!doctype html>\n<html><head><link rel=\"preload\" as=\"image\" href=\"./FR-Joyplay_LandingPage_Overview_Collage_420.png\" imagesrcset=\"./FR-Joyplay_LandingPage_Overview_Collage_360.png 360w, ./FR-Joyplay_LandingPage_Overview_Collage_420.png 420w\"><link rel=\"stylesheet\" href=\"./styles.css\"></head><body><img src=\"./FR-Joyplay_LandingPage_Overview_Collage.png\" srcset=\"./FR-Joyplay_LandingPage_Overview_Collage_360.png 360w, ./FR-Joyplay_LandingPage_Overview_Collage_420.png 420w\" alt=\"Joyplay\">LP</body></html>\n"
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
            'imagesrcset="https://backend.kiwimobile.de/wp-content/uploads/assets/FR-Joyplay_LandingPage_Overview_Collage_360.png 360w, https://backend.kiwimobile.de/wp-content/uploads/assets/FR-Joyplay_LandingPage_Overview_Collage_420.png 420w"',
            $output,
            'Expected srcdoc previews to rewrite local preload imagesrcset candidates through the default landing-page asset base URL.'
        );
        kiwi_assert_contains(
            'srcset="https://backend.kiwimobile.de/wp-content/uploads/assets/FR-Joyplay_LandingPage_Overview_Collage_360.png 360w, https://backend.kiwimobile.de/wp-content/uploads/assets/FR-Joyplay_LandingPage_Overview_Collage_420.png 420w"',
            $output,
            'Expected srcdoc previews to rewrite local img srcset candidates through the default landing-page asset base URL.'
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

kiwi_run_test('Kiwi_Landing_Page_Router builds canonical session dimensions from landing request context', function (): void {
    $router = new Kiwi_Landing_Page_Router(
        new Kiwi_Test_Config(),
        new Kiwi_Test_Landing_Page_Session_Repository(),
        'https://example.test/plugin/'
    );
    $method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'build_session_dimension_context');

    $dimensions = $method->invoke($router, [
        'provider' => 'nth',
        'flow' => 'nth-fr-one-off',
        'service_key' => 'nth_fr_one_off_jplay',
    ], [
        'country' => 'fr',
    ], [
        'PID' => 'partner 123!',
        'tksource' => 'src-1._~:a',
        'tkzone' => 'zone/42',
    ], 'fr-FR,fr;q=0.9,en;q=0.7');

    kiwi_assert_same('nth', $dimensions['provider_key'] ?? '', 'Expected provider_key to come from landing metadata.');
    kiwi_assert_same('nth-fr-one-off', $dimensions['flow_key'] ?? '', 'Expected flow_key to come from landing metadata.');
    kiwi_assert_same('FR', $dimensions['country'] ?? '', 'Expected country to fall back to service context and normalize to uppercase.');
    kiwi_assert_same('partner123', $dimensions['pid'] ?? '', 'Expected pid to be sanitized directly from query params without requiring click_id.');
    kiwi_assert_same('src-1._~:a', $dimensions['tksource'] ?? '', 'Expected tksource to preserve conservative source characters.');
    kiwi_assert_same('zone42', $dimensions['tkzone'] ?? '', 'Expected tkzone to strip unsupported characters.');
    kiwi_assert_same('fr', $dimensions['browser_language'] ?? '', 'Expected browser_language to store the primary language from Accept-Language.');

    $unknown_dimensions = $method->invoke($router, [
        'provider' => 'nth',
        'flow' => 'nth-fr-one-off',
        'country' => 'de',
    ], [
        'country' => 'FR',
    ], [
        'pid' => ['bad'],
        'tksource' => '',
    ], '*, 123;q=0.8');

    kiwi_assert_same('DE', $unknown_dimensions['country'] ?? '', 'Expected landing metadata country to win over service country.');
    kiwi_assert_same('', $unknown_dimensions['pid'] ?? '', 'Expected array query values to be ignored for source dimensions.');
    kiwi_assert_same('', $unknown_dimensions['tksource'] ?? '', 'Expected empty source values to stay empty at capture time.');
    kiwi_assert_same('(unknown)', $unknown_dimensions['browser_language'] ?? '', 'Expected invalid Accept-Language values to use the unknown bucket.');
});

kiwi_run_test('Kiwi_Landing_Page_Router resolves client IP context through trusted proxy config', function (): void {
    $router = new Kiwi_Landing_Page_Router(
        new Kiwi_Test_Trusted_Proxy_Config(['10.0.0.0/24']),
        new Kiwi_Test_Landing_Page_Session_Repository(),
        'https://example.test/plugin/'
    );
    $method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'resolve_client_ip_context');

    $trusted = $method->invoke($router, [
        'REMOTE_ADDR' => '10.0.0.8',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.44',
    ]);
    $spoofed = $method->invoke($router, [
        'REMOTE_ADDR' => '198.51.100.77',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.44',
    ]);

    kiwi_assert_same('203.0.113.44', $trusted['client_ip'] ?? '', 'Expected trusted proxy config to allow X-Forwarded-For client resolution.');
    kiwi_assert_same('203.0.113.0/24', $trusted['client_ip_prefix'] ?? '', 'Expected router IP context to include the stored landing-session prefix.');
    kiwi_assert_same('198.51.100.77', $spoofed['client_ip'] ?? '', 'Expected untrusted direct peers to ignore spoofed X-Forwarded-For headers.');
});

kiwi_run_test('Kiwi_Landing_Page_Router stores client IP debug diagnostics only behind flag', function (): void {
    $server = [
        'REMOTE_ADDR' => '2a02:4780:79:a1e9::1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.44',
    ];
    $debug_off_router = new Kiwi_Landing_Page_Router(
        new Kiwi_Test_Trusted_Proxy_Debug_Config(['2a02:4780:79:a1e9::1'], false),
        new Kiwi_Test_Landing_Page_Session_Repository(),
        'https://example.test/plugin/'
    );
    $debug_on_router = new Kiwi_Landing_Page_Router(
        new Kiwi_Test_Trusted_Proxy_Debug_Config(['2a02:4780:79:a1e9::1'], true),
        new Kiwi_Test_Landing_Page_Session_Repository(),
        'https://example.test/plugin/'
    );
    $resolve_method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'resolve_client_ip_context');
    $raw_context_method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'build_client_ip_resolution_context');
    $client_ip_context = $resolve_method->invoke($debug_off_router, $server);
    $debug_off = $raw_context_method->invoke($debug_off_router, $server, $client_ip_context);
    $debug_on = $raw_context_method->invoke($debug_on_router, $server, $client_ip_context);
    $encoded_debug_on = json_encode($debug_on);
    $encoded_debug_on = is_string($encoded_debug_on) ? $encoded_debug_on : '';

    kiwi_assert_same([
        'source' => 'x_forwarded_for',
        'peer_trusted' => true,
    ], $debug_off, 'Expected default raw context to keep client IP resolution diagnostics compact.');
    kiwi_assert_same(true, $debug_on['trusted_proxy_configured'] ?? null, 'Expected debug raw context to show trusted proxy config presence.');
    kiwi_assert_same(['x_forwarded_for'], $debug_on['forwarded_headers_present'] ?? [], 'Expected debug raw context to show forwarded header names only.');
    kiwi_assert_same(1, $debug_on['forwarded_candidate_count'] ?? null, 'Expected debug raw context to count forwarded candidates.');
    kiwi_assert_same('resolved_from_forwarded_header', $debug_on['resolution_reason'] ?? '', 'Expected debug raw context to explain the resolution path.');
    kiwi_assert_true(strpos($encoded_debug_on, '203.0.113.44') === false, 'Expected debug raw context not to include raw forwarded client IPs.');
    kiwi_assert_true(strpos($encoded_debug_on, '2a02:4780:79:a1e9::1') === false, 'Expected debug raw context not to include raw proxy IPs.');
});

kiwi_run_test('Kiwi_Landing_Page_Router resolves active filesystem landing variants without legacy fallback', function (): void {
    $config = new Kiwi_Test_Runtime_Config(
        __DIR__ . '/../landing-pages',
        [],
        true,
        false,
        false
    );
    $router = new Kiwi_Landing_Page_Router(
        $config,
        new Kiwi_Landing_Page_Session_Repository(),
        'https://example.test/plugin/'
    );

    $expected_routes = [
        'lp4-fr' => '/lp/fr/myjoyplay4',
        'lp5-fr' => '/lp/fr/myjoyplay5',
        'lp5-fr-v2' => '/lp/fr/myjoyplay5v2',
        'lp6-fr' => '/lp/fr/myjoyplay6',
        'lp6-fr-v2' => '/lp/fr/myjoyplay6v2',
    ];

    foreach ($expected_routes as $landing_key => $backend_path) {
        $match = $router->resolve_request('landing-public.example.test', $backend_path);

        kiwi_assert_same(
            $landing_key,
            $match['landing_key'] ?? '',
            'Expected active filesystem landing route ' . $backend_path . ' to resolve without legacy fallback.'
        );
        kiwi_assert_same(
            'filesystem',
            $match['landing_page']['render_mode'] ?? '',
            'Expected active landing route ' . $backend_path . ' to use filesystem rendering.'
        );
    }

    $dedicated_host_match = $router->resolve_request('frlp2.joy-play.com', '/');

    kiwi_assert_same('lp5-fr', $dedicated_host_match['landing_key'] ?? '', 'Expected lp5-fr dedicated host routing to resolve without legacy fallback.');
});

kiwi_run_test('Kiwi_Landing_Page_Router inlines readable filesystem styles and removes stylesheet link', function (): void {
    $project_root = kiwi_create_temp_directory('kiwi_lp_router_inline_styles');

    try {
        $styles_path = $project_root . DIRECTORY_SEPARATOR . 'styles.css';
        kiwi_write_file(
            $styles_path,
            "body { font-family: Arial, sans-serif; }\n.hero { background-image: url('./hero-bg.png'); }\n"
        );

        $router = new Kiwi_Landing_Page_Router(
            new Kiwi_Test_Config(),
            new Kiwi_Landing_Page_Session_Repository(),
            'https://backend.kiwimobile.de/wp-content/plugins/kiwi-backend/'
        );
        $apply_stylesheet_method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'apply_filesystem_stylesheet');

        $html = '<!doctype html><html><head><link rel="stylesheet" href="./styles.css"></head><body>LP</body></html>';
        $output = $apply_stylesheet_method->invoke(
            $router,
            $html,
            $styles_path,
            'https://backend.kiwimobile.de/wp-content/uploads/assets/',
            'https://backend.kiwimobile.de/wp-content/plugins/kiwi-backend/landing-pages/lp4-fr/styles.css'
        );

        kiwi_assert_contains('<style>', $output, 'Expected readable filesystem CSS to be inlined into the landing page.');
        kiwi_assert_contains('body { font-family: Arial, sans-serif; }', $output, 'Expected inlined CSS to preserve stylesheet content.');
        kiwi_assert_contains(
            "url('https://backend.kiwimobile.de/wp-content/uploads/assets/hero-bg.png')",
            $output,
            'Expected inlined CSS asset paths to be rewritten through the landing-page asset base URL.'
        );
        kiwi_assert_true(
            strpos($output, '<link rel="stylesheet"') === false,
            'Expected the external stylesheet link to be removed when CSS is inlined.'
        );
        kiwi_assert_true(
            strpos($output, 'lp4-fr/styles.css') === false,
            'Expected inlined output not to request the landing-page styles.css file.'
        );
    } finally {
        kiwi_remove_directory($project_root);
    }
});

kiwi_run_test('Kiwi_Landing_Page_Router falls back to external stylesheet link when inline styles are unavailable', function (): void {
    $router = new Kiwi_Landing_Page_Router(
        new Kiwi_Test_Config(),
        new Kiwi_Landing_Page_Session_Repository(),
        'https://backend.kiwimobile.de/wp-content/plugins/kiwi-backend/'
    );
    $apply_stylesheet_method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'apply_filesystem_stylesheet');
    $missing_styles_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kiwi_missing_styles_' . uniqid('', true) . '.css';
    $css_url = 'https://backend.kiwimobile.de/wp-content/plugins/kiwi-backend/landing-pages/lp4-fr/styles.css';

    $html_with_link = '<!doctype html><html><head><link rel="stylesheet" href="./styles.css"></head><body>LP</body></html>';
    $output_with_link = $apply_stylesheet_method->invoke(
        $router,
        $html_with_link,
        $missing_styles_path,
        'https://backend.kiwimobile.de/wp-content/uploads/assets/',
        $css_url
    );

    kiwi_assert_contains(
        'href="' . $css_url . '"',
        $output_with_link,
        'Expected fallback CSS handling to rewrite the existing local stylesheet link.'
    );
    kiwi_assert_true(
        strpos($output_with_link, '<style>') === false,
        'Expected fallback CSS handling not to inject an empty inline style block.'
    );

    $html_without_link = '<!doctype html><html><head><title>LP</title></head><body>LP</body></html>';
    $output_without_link = $apply_stylesheet_method->invoke(
        $router,
        $html_without_link,
        $missing_styles_path,
        'https://backend.kiwimobile.de/wp-content/uploads/assets/',
        $css_url
    );

    kiwi_assert_contains(
        '<link rel="stylesheet" href="' . $css_url . '">',
        $output_without_link,
        'Expected fallback CSS handling to insert the external stylesheet link when source HTML has none.'
    );
});

kiwi_run_test('Kiwi_Landing_Page_Router rewrites local filesystem asset paths to default landing-page asset URL', function (): void {
    $router = new Kiwi_Landing_Page_Router(
        new Kiwi_Test_Config(),
        new Kiwi_Landing_Page_Session_Repository(),
        'https://backend.kiwimobile.de/wp-content/plugins/kiwi-backend/'
    );

    $resolve_asset_base_method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'resolve_filesystem_asset_base_url');
    $replace_local_assets_method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'replace_local_asset_paths');
    $replace_local_css_assets_method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'replace_local_css_asset_paths');

    $html = '<!doctype html><html><head><link rel="preload" as="image" href="./hero-420.webp" imagesrcset="./hero-360.webp 360w, ./hero-420.webp 420w, https://cdn.example.test/hero-800.webp 800w, /rooted.webp 900w, data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw== 1x"><link rel="stylesheet" href="./styles.css"></head><body><img class="hero" src="./hero-dragonfight.jpg" srcset="./hero-360.webp 360w, ./hero-420.webp 420w, https://cdn.example.test/hero-800.webp 800w, /rooted.webp 900w, data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw== 1x"><script src="./core.js"></script><a href="./terms.pdf">Terms</a></body></html>';
    $asset_base_url = $resolve_asset_base_method->invoke($router, [], 'lp4-fr');
    $html = $replace_local_assets_method->invoke($router, $html, $asset_base_url);
    $css = $replace_local_css_assets_method->invoke($router, ".hero { background-image: url('./hero-bg.png'); }", $asset_base_url);

    kiwi_assert_contains(
        'src="https://backend.kiwimobile.de/wp-content/uploads/assets/hero-dragonfight.jpg"',
        $html,
        'Expected local img src paths to be rewritten to the default landing-page asset URL.'
    );
    kiwi_assert_contains(
        'imagesrcset="https://backend.kiwimobile.de/wp-content/uploads/assets/hero-360.webp 360w, https://backend.kiwimobile.de/wp-content/uploads/assets/hero-420.webp 420w, https://cdn.example.test/hero-800.webp 800w, /rooted.webp 900w, data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw== 1x"',
        $html,
        'Expected local imagesrcset candidates to be rewritten while preserving descriptors and non-local candidates.'
    );
    kiwi_assert_contains(
        'srcset="https://backend.kiwimobile.de/wp-content/uploads/assets/hero-360.webp 360w, https://backend.kiwimobile.de/wp-content/uploads/assets/hero-420.webp 420w, https://cdn.example.test/hero-800.webp 800w, /rooted.webp 900w, data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw== 1x"',
        $html,
        'Expected local img srcset candidates to be rewritten while preserving descriptors and non-local candidates.'
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
    $replace_local_assets_method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'replace_local_asset_paths');

    $html = '<!doctype html><html><head><link rel="stylesheet" href="./styles.css"></head><body><img class="hero" src="./FR-Joyplay_LandingPage_Overview_Collage.png" srcset="./FR-Joyplay_LandingPage_Overview_Collage_360.png 360w, ./FR-Joyplay_LandingPage_Overview_Collage_420.png 420w, https://cdn.example.test/keep.png 800w, /root/keep.png 900w, data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw== 1x"></body></html>';
    $external_asset_base_url = $resolve_asset_base_method->invoke($router, [
        'asset_base_url' => 'https://assets.example.test/custom/',
    ], 'lp2-fr');

    $html = $replace_local_assets_method->invoke($router, $html, $external_asset_base_url);

    kiwi_assert_contains(
        'src="https://assets.example.test/custom/FR-Joyplay_LandingPage_Overview_Collage.png"',
        $html,
        'Expected local img src paths to be rewritten to the configured external asset base URL.'
    );
    kiwi_assert_contains(
        'srcset="https://assets.example.test/custom/FR-Joyplay_LandingPage_Overview_Collage_360.png 360w, https://assets.example.test/custom/FR-Joyplay_LandingPage_Overview_Collage_420.png 420w, https://cdn.example.test/keep.png 800w, /root/keep.png 900w, data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw== 1x"',
        $html,
        'Expected local srcset candidates to be rewritten to the configured external asset base URL while preserving non-local candidates.'
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
            'tksource' => ' Propeller<>Ads ',
            'tkzone' => ' zone<>123:abc ',
        ]
    );

    kiwi_assert_true(is_array($record), 'Expected click attribution capture to create a persisted record.');
    kiwi_assert_same('abc:123', $record['click_id'] ?? '', 'Expected captured clickid to be persisted in server-side storage.');
    kiwi_assert_same('partner_A:1', $record['pid'] ?? '', 'Expected capture to sanitize and persist pid as first-class attribution field.');
    kiwi_assert_same('PropellerAds', $record['tksource'] ?? '', 'Expected capture to sanitize and persist tksource as first-class attribution field.');
    kiwi_assert_same('zone123:abc', $record['tkzone'] ?? '', 'Expected capture to sanitize and persist tkzone as first-class attribution field.');
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
    kiwi_assert_same('PropellerAds', $saved['tksource'] ?? '', 'Expected repeated capture without tksource input to keep the previously captured tksource value.');
    kiwi_assert_same('zone123:abc', $saved['tkzone'] ?? '', 'Expected repeated capture without tkzone input to keep the previously captured tkzone value.');

    $service->capture_from_request(
        [
            'key' => 'lp2-fr',
            'provider' => 'nth',
            'flow' => 'nth-fr-one-off',
            'service_key' => 'nth_fr_one_off_jplay',
        ],
        'landing-session-1',
        [
            'clickid' => 'new-click:123',
            'pid' => 'new_partner',
            'tksource' => 'NewSource',
            'tkzone' => 'new-zone-9',
        ]
    );

    kiwi_assert_same(1, count($repository->rows), 'Expected repeated capture with new source context to reuse the same attribution row.');
    $refreshed = array_values($repository->rows)[0];
    kiwi_assert_same('new-click:123', $refreshed['click_id'] ?? '', 'Expected repeated capture to refresh clickid when a new click arrives.');
    kiwi_assert_same('new_partner', $refreshed['pid'] ?? '', 'Expected repeated capture with non-empty pid input to refresh the stored pid value.');
    kiwi_assert_same('NewSource', $refreshed['tksource'] ?? '', 'Expected repeated capture with non-empty tksource input to refresh the stored tksource value.');
    kiwi_assert_same('new-zone-9', $refreshed['tkzone'] ?? '', 'Expected repeated capture with non-empty tkzone input to refresh the stored tkzone value.');
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

kiwi_run_test('Kiwi_Conversion_Attribution_Resolver preserves landing session when binding provider refs', function (): void {
    $repository = new Kiwi_Test_Click_Attribution_Repository();
    $dispatcher = new Kiwi_Test_Affiliate_Postback_Dispatcher(new Kiwi_Test_Attribution_Config());
    $resolver = new Kiwi_Conversion_Attribution_Resolver($repository, $dispatcher);

    $capture = $repository->upsert_capture([
        'tracking_token' => 'TOKSESSIONPRESERVE',
        'click_id' => 'aff:session:preserve',
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'landing_page_key' => 'lp2-fr',
        'session_ref' => 'landing-session-1',
        'expires_at' => '2026-04-05 12:00:00',
    ]);

    $updated = $resolver->attach_provider_references([
        'tracking_token' => (string) ($capture['tracking_token'] ?? ''),
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'session_ref' => 'provider-session-1',
        'external_ref' => 'provider-session-1',
        'transaction_ref' => 'flow-session-preserve-1',
        'sale_reference' => 'sale-session-preserve-1',
    ]);

    kiwi_assert_same('landing-session-1', (string) ($updated['session_ref'] ?? ''), 'Expected provider binding not to overwrite the landing session reference.');
    kiwi_assert_same('provider-session-1', (string) ($updated['external_ref'] ?? ''), 'Expected provider external ref to remain available for conversion matching.');
});

kiwi_run_test('Kiwi_Conversion_Attribution_Resolver records SMS body variant conversion once', function (): void {
    $repository = new Kiwi_Test_Click_Attribution_Repository();
    $dispatcher = new Kiwi_Test_Affiliate_Postback_Dispatcher(new Kiwi_Test_Attribution_Config());
    $kpi_summary_repository = new Kiwi_Test_Landing_Kpi_Summary_Repository();
    $variant_repository = new Kiwi_Test_Sms_Body_Variant_Repository();
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
    $resolver = new Kiwi_Conversion_Attribution_Resolver(
        $repository,
        $dispatcher,
        $kpi_service,
        null,
        $variant_repository
    );
    $capture = $repository->upsert_capture([
        'tracking_token' => 'TOKVARCONV123456',
        'transaction_id' => 'txn_variant_conv_123',
        'click_id' => 'aff:variant:conv',
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'landing_page_key' => 'lp2-fr',
        'flow_key' => 'one-off',
        'expires_at' => '2026-04-05 12:00:00',
    ]);
    $variant_repository->insert_if_new([
        'landing_key' => 'lp2-fr',
        'service_key' => 'nth_fr_one_off_jplay',
        'provider_key' => 'nth',
        'flow_key' => 'one-off',
        'country' => 'FR',
        'keyword' => 'JPLAY',
        'shortcode' => '84072',
        'session_token' => 'sess-variant-conv',
        'transaction_id' => (string) ($capture['transaction_id'] ?? ''),
        'visible_token' => 'variant_conv_123',
        'variant_key' => 'bare_id',
        'sms_body' => 'JPLAY variant_conv_123',
    ]);

    $resolver->handle_confirmed_conversion([
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'confirmed' => true,
        'transaction_id' => (string) ($capture['transaction_id'] ?? ''),
    ]);
    $resolver->handle_confirmed_conversion([
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'confirmed' => true,
        'transaction_id' => (string) ($capture['transaction_id'] ?? ''),
    ]);
    $variant_summary = $variant_repository->get_summary_rows()[0] ?? [];

    kiwi_assert_same(1, (int) ($variant_summary['conv'] ?? 0), 'Expected SMS body variant conversion counter to increment once across duplicate confirmed callbacks.');
});

kiwi_run_test('Kiwi_Conversion_Attribution_Resolver appends custom_field1 from persisted sales operator_name', function (): void {
    $repository = new Kiwi_Test_Click_Attribution_Repository();
    $sales_repository = new Kiwi_Test_Sales_Repository();
    $sales_repository->upsert([
        'sale_reference' => 'sale-custom-field1-1',
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
        'tracking_token' => 'TOKCUSTOMFIELDONE',
        'click_id' => 'aff:click:custom-field1',
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'expires_at' => '2026-04-05 12:00:00',
    ]);

    $resolver->attach_provider_references([
        'tracking_token' => (string) ($capture['tracking_token'] ?? ''),
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'transaction_ref' => 'flow-custom-field1-1',
        'sale_reference' => 'sale-custom-field1-1',
    ]);

    $result = $resolver->handle_confirmed_conversion([
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'confirmed' => true,
        'transaction_ref' => 'flow-custom-field1-1',
        'sale_reference' => 'sale-custom-field1-1',
    ]);

    kiwi_assert_true($result['dispatched'] ?? false, 'Expected confirmed conversion to dispatch postback in custom_field1 enrichment flow.');
    kiwi_assert_same(1, count($dispatcher->calls), 'Expected one outbound postback call for custom_field1 enrichment flow.');
    kiwi_assert_true(
        strpos((string) ($dispatcher->calls[0] ?? ''), 'custom_field1=20820') !== false,
        'Expected postback URL to include custom_field1 from wp_kiwi_sales.operator_name.'
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

kiwi_run_test('Kiwi_Conversion_Attribution_Resolver persists sales attribution snapshot from landing context', function (): void {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.77';

    $repository = new Kiwi_Test_Click_Attribution_Repository();
    $sales_repository = new Kiwi_Test_Sales_Repository();
    $landing_session_repository = new Kiwi_Test_Landing_Page_Session_Repository();
    $engagement_repository = new Kiwi_Test_Premium_Sms_Landing_Engagement_Repository();

    $sales_repository->upsert([
        'sale_reference' => 'sale-snapshot-1',
        'transaction_id' => 'txn_snapshot_123456',
        'provider_key' => 'nth',
        'status' => 'completed',
        'completed_at' => '2026-04-02 15:00:00',
        'context_json' => [
            'transaction' => ['sale_reference' => 'sale-snapshot-1'],
            'report_event' => ['status' => 'delivered'],
        ],
    ]);
    $landing_session_repository->insert([
        'created_at' => '2026-04-01 08:00:00',
        'landing_key' => 'lp2-fr',
        'service_key' => 'nth_fr_one_off_jplay',
        'session_token' => 'sess-snapshot-1',
        'remote_ip' => '203.0.113.44',
        'client_ip_version' => 'ipv4',
        'client_ip_prefix' => '203.0.113.0/24',
        'user_agent' => 'Mozilla/5.0 (Linux; Android 14; SM-A536B) AppleWebKit/537.36 Chrome/125.0 Mobile Safari/537.36',
    ]);
    $engagement_repository->upsert_event([
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-snapshot-1',
        'service_key' => 'nth_fr_one_off_jplay',
        'provider_key' => 'nth',
        'flow_key' => 'one-off',
        'click_id' => 'aff:snapshot:1',
        'tksource' => 'source_a',
        'tkzone' => 'zone_b',
        'ua_ch_supported' => 1,
        'ua_ch_platform' => 'Android',
        'ua_ch_platform_version' => '14.0.0',
        'ua_ch_model' => 'SM-A536B',
        'ua_ch_brands' => 'Google Chrome 125',
        'ua_ch_full_version_list' => 'Google Chrome 125.0.0.0',
        'user_agent' => 'Mozilla/5.0 (Linux; Android 14; SM-A536B) AppleWebKit/537.36 Chrome/125.0 Mobile Safari/537.36',
    ], 'page_loaded', '2026-04-01 08:00:10');

    $capture = $repository->upsert_capture([
        'created_at' => '2026-03-31 23:59:59',
        'tracking_token' => 'TOKSNAPSHOT00001',
        'transaction_id' => 'txn_snapshot_123456',
        'click_id' => 'aff:snapshot:1',
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'landing_page_key' => 'lp2-fr',
        'flow_key' => 'one-off',
        'pid' => 'pid-snapshot',
        'tksource' => 'source_a',
        'tkzone' => 'zone_b',
        'session_ref' => 'sess-snapshot-1',
        'raw_context' => [
            'query_params' => [
                'pid' => 'pid-snapshot',
            ],
        ],
        'expires_at' => '2026-04-05 12:00:00',
    ]);

    $dispatcher = new Kiwi_Test_Affiliate_Postback_Dispatcher(new Kiwi_Test_Attribution_Config(
        'https://offers.example.test/postback?clickid={clickid}&goal=sale',
        ''
    ));
    $snapshot_builder = new Kiwi_Sales_Attribution_Snapshot_Builder(
        $landing_session_repository,
        $engagement_repository
    );
    $resolver = new Kiwi_Conversion_Attribution_Resolver(
        $repository,
        $dispatcher,
        null,
        $sales_repository,
        null,
        $snapshot_builder
    );

    $result = $resolver->handle_confirmed_conversion([
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'confirmed' => true,
        'transaction_id' => (string) ($capture['transaction_id'] ?? ''),
        'sale_reference' => 'sale-snapshot-1',
        'occurred_at' => '2026-04-02 15:00:00',
    ]);

    $sale = $sales_repository->find_by_sale_reference('sale-snapshot-1') ?? [];

    kiwi_assert_true($result['dispatched'] ?? false, 'Expected snapshot conversion flow to dispatch the postback.');
    kiwi_assert_same(1, count($sales_repository->snapshot_updates), 'Expected one sales snapshot update for confirmed attributed sale.');
    kiwi_assert_same('nth_fr_one_off_jplay', (string) ($sale['service_key'] ?? ''), 'Expected sale snapshot to persist service_key.');
    kiwi_assert_same('lp2-fr', (string) ($sale['landing_key'] ?? ''), 'Expected sale snapshot to persist landing_key.');
    kiwi_assert_same('sess-snapshot-1', (string) ($sale['session_ref'] ?? ''), 'Expected sale snapshot to preserve the landing session reference.');
    kiwi_assert_same('aff:snapshot:1', (string) ($sale['click_id'] ?? ''), 'Expected sale snapshot to persist click_id.');
    kiwi_assert_same('source_a', (string) ($sale['tksource'] ?? ''), 'Expected sale snapshot to persist tksource.');
    kiwi_assert_same('zone_b', (string) ($sale['tkzone'] ?? ''), 'Expected sale snapshot to persist tkzone.');
    kiwi_assert_same('2026-03-31', (string) ($sale['attribution_metric_date'] ?? ''), 'Expected metric date to prefer attribution capture date.');
    kiwi_assert_same('203.0.113.44', (string) ($sale['client_ip'] ?? ''), 'Expected client IP to come from landing session, not callback REMOTE_ADDR.');
    kiwi_assert_same('ipv4', (string) ($sale['client_ip_version'] ?? ''), 'Expected IPv4 version marker.');
    kiwi_assert_same('203.0.113.0/24', (string) ($sale['client_ip_prefix'] ?? ''), 'Expected IPv4 /24 prefix.');
    kiwi_assert_same(64, strlen((string) ($sale['client_ip_hash'] ?? '')), 'Expected client IP hash to be persisted.');
    kiwi_assert_same('Samsung', (string) ($sale['device_brand'] ?? ''), 'Expected UA context to normalize device brand.');
    kiwi_assert_same('Android', (string) ($sale['os'] ?? ''), 'Expected UA context to normalize OS.');
    kiwi_assert_same('14', (string) ($sale['os_version'] ?? ''), 'Expected UA context to normalize OS version.');
    kiwi_assert_same('Chrome', (string) ($sale['browser'] ?? ''), 'Expected UA context to normalize browser.');
    kiwi_assert_same('delivered', (string) ($sale['context_json']['report_event']['status'] ?? ''), 'Expected existing provider report context to be preserved.');
    kiwi_assert_same(
        'landing_page_session.client_ip_buckets',
        (string) ($sale['context_json']['attribution_snapshot']['debug']['ip_source'] ?? ''),
        'Expected context_json attribution_snapshot debug data to record the IP source.'
    );

    unset($_SERVER['REMOTE_ADDR']);
});

kiwi_run_test('Kiwi_Device_Context_Normalizer normalizes durable device OS and browser buckets', function (): void {
    $brand_map = new Kiwi_Test_Device_Model_Brand_Map_Repository();
    $brand_map->brands_by_model_key['MOTO G POWER'] = 'Motorola Mobility';
    $normalizer = new Kiwi_Device_Context_Normalizer($brand_map);

    $android = $normalizer->normalize([
        'ua_ch_platform' => 'Android',
        'ua_ch_platform_version' => '14.0.0',
        'ua_ch_model' => 'SM-S921B',
        'ua_ch_brands' => 'Google Chrome 125',
        'ua_ch_full_version_list' => 'Google Chrome 125.0.0.0',
        'user_agent' => 'Mozilla/5.0 (Linux; Android 14; SM-S921B) AppleWebKit/537.36 Chrome/125.0 Mobile Safari/537.36',
    ]);
    $mapped = $normalizer->normalize([
        'ua_ch_platform' => 'Android',
        'ua_ch_platform_version' => '13.0.0',
        'ua_ch_model' => 'Moto G Power',
        'ua_ch_brands' => 'Google Chrome 125',
        'ua_ch_full_version_list' => 'Google Chrome 125.0.0.0',
        'user_agent' => 'Mozilla/5.0 (Linux; Android 13; Moto G Power Build/T1) AppleWebKit/537.36 Chrome/125.0 Mobile Safari/537.36',
    ]);
    $ios = $normalizer->normalize([
        'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Version/17.5 Mobile/15E148 Safari/604.1',
    ]);
    $merged = $normalizer->merge(
        ['device_brand' => 'Samsung', 'os' => 'Android', 'os_version' => '14', 'browser' => 'Chrome'],
        ['device_brand' => '(unknown)', 'os' => '(unknown)', 'os_version' => '(unknown)', 'browser' => 'Samsung Internet']
    );

    kiwi_assert_same('Samsung', $android['device_brand'] ?? '', 'Expected Samsung model buckets to normalize to Samsung.');
    kiwi_assert_same('Android', $android['os'] ?? '', 'Expected Android OS bucket from UA-CH.');
    kiwi_assert_same('14', $android['os_version'] ?? '', 'Expected Android version bucket to use the major version.');
    kiwi_assert_same('Chrome', $android['browser'] ?? '', 'Expected Chrome browser bucket from UA-CH.');
    kiwi_assert_same('Motorola Mobility', $mapped['device_brand'] ?? '', 'Expected exact model map entries to supply brands before heuristic rules.');
    kiwi_assert_same('Apple', $ios['device_brand'] ?? '', 'Expected iOS devices to normalize to Apple.');
    kiwi_assert_same('iOS', $ios['os'] ?? '', 'Expected iOS OS bucket from user agent.');
    kiwi_assert_same('17.5', $ios['os_version'] ?? '', 'Expected iOS version to preserve dotted precision.');
    kiwi_assert_same('Safari', $ios['browser'] ?? '', 'Expected Safari browser bucket from user agent.');
    kiwi_assert_same('Samsung', $merged['device_brand'] ?? '', 'Expected unknown incoming values not to overwrite known existing buckets.');
    kiwi_assert_same('Samsung Internet', $merged['browser'] ?? '', 'Expected more specific browser buckets to replace generic Chrome.');
});

kiwi_run_test('Kiwi_Device_Model_Brand_Harvest_Service inserts frequent unknown UA-CH models only', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new class {
        public $prefix = 'abc_';
        public $result_rows_queue = [];
        public $inserted = [];

        public function __construct()
        {
            $this->result_rows_queue = [
                [
                    [
                        'landing_key' => 'lp-a',
                        'session_token' => 'sess-1',
                        'ua_ch_platform' => 'Android',
                        'ua_ch_platform_version' => '14.0.0',
                        'ua_ch_model' => 'CPH9999',
                        'ua_ch_brands' => 'Google Chrome 125',
                        'ua_ch_full_version_list' => 'Google Chrome 125.0.0.0',
                        'user_agent' => '',
                    ],
                    [
                        'landing_key' => 'lp-a',
                        'session_token' => 'sess-2',
                        'ua_ch_platform' => 'Android',
                        'ua_ch_platform_version' => '14.0.0',
                        'ua_ch_model' => 'CPH9999',
                        'ua_ch_brands' => 'Google Chrome 125',
                        'ua_ch_full_version_list' => 'Google Chrome 125.0.0.0',
                        'user_agent' => '',
                    ],
                    [
                        'landing_key' => 'lp-a',
                        'session_token' => 'sess-known',
                        'ua_ch_platform' => 'Android',
                        'ua_ch_platform_version' => '14.0.0',
                        'ua_ch_model' => 'SM-S921B',
                        'ua_ch_brands' => 'Google Chrome 125',
                        'ua_ch_full_version_list' => 'Google Chrome 125.0.0.0',
                        'user_agent' => '',
                    ],
                    [
                        'landing_key' => 'lp-a',
                        'session_token' => 'sess-low',
                        'ua_ch_platform' => 'Android',
                        'ua_ch_platform_version' => '14.0.0',
                        'ua_ch_model' => 'NX711J',
                        'ua_ch_brands' => 'Google Chrome 125',
                        'ua_ch_full_version_list' => 'Google Chrome 125.0.0.0',
                        'user_agent' => '',
                    ],
                ],
                [
                    [
                        'landing_key' => 'lp-a',
                        'session_token' => 'sess-2',
                        'ua_ch_platform' => 'Android',
                        'ua_ch_platform_version' => '14.0.0',
                        'ua_ch_model' => 'CPH9999',
                        'ua_ch_brands' => 'Google Chrome 125',
                        'ua_ch_full_version_list' => 'Google Chrome 125.0.0.0',
                        'user_agent' => '',
                    ],
                    [
                        'landing_key' => 'lp-a',
                        'session_token' => 'sess-3',
                        'ua_ch_platform' => 'Android',
                        'ua_ch_platform_version' => '14.0.0',
                        'ua_ch_model' => 'CPH9999',
                        'ua_ch_brands' => 'Google Chrome 125',
                        'ua_ch_full_version_list' => 'Google Chrome 125.0.0.0',
                        'user_agent' => '',
                    ],
                ],
            ];
        }

        public function prepare($query, ...$args)
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }

            return [
                'query' => (string) $query,
                'args' => $args,
            ];
        }

        public function get_results($statement, $output = null): array
        {
            return array_shift($this->result_rows_queue) ?? [];
        }

        public function get_row($statement, $output = null)
        {
            return null;
        }

        public function query($statement)
        {
            $query = is_array($statement) ? (string) ($statement['query'] ?? '') : (string) $statement;
            $args = is_array($statement) ? (array) ($statement['args'] ?? []) : [];

            if (stripos($query, 'INSERT IGNORE INTO') === 0) {
                $this->inserted[] = $args;

                return 1;
            }

            return 0;
        }
    };
    $config = new class extends Kiwi_Config {
        public function get_device_model_brand_harvest_min_daily_sessions(): int
        {
            return 2;
        }
    };
    $repository = new Kiwi_Device_Model_Brand_Map_Repository();
    $service = new Kiwi_Device_Model_Brand_Harvest_Service(
        $config,
        $repository,
        new Kiwi_Device_Context_Normalizer($repository)
    );

    $result = $service->harvest_date('2026-05-31');

    kiwi_assert_true($result['success'], 'Expected device model harvest to succeed for a valid date.');
    kiwi_assert_same(2, $result['unknown_model_keys'], 'Expected frequent and below-threshold unknown model keys to be counted.');
    kiwi_assert_same(1, $result['eligible_model_keys'], 'Expected only unknown models meeting the distinct-session threshold to be eligible.');
    kiwi_assert_same(1, $result['inserted'], 'Expected only one observed unknown model key to be inserted.');
    kiwi_assert_same('CPH9999', $wpdb->inserted[0][0] ?? '', 'Expected the frequent unknown model key to be inserted.');
    kiwi_assert_same('(unknown)', $wpdb->inserted[0][1] ?? '', 'Expected harvested unknown models to remain review placeholders.');
    kiwi_assert_same('observed', $wpdb->inserted[0][2] ?? '', 'Expected harvested rows to identify the observed source.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Sales_Attribution_Snapshot_Builder restricts device brands to known rules', function (): void {
    $engagement_repository = new Kiwi_Test_Premium_Sms_Landing_Engagement_Repository();
    $builder = new Kiwi_Sales_Attribution_Snapshot_Builder(null, $engagement_repository);

    $assert_brand = static function (string $expected, string $session_token, string $model, string $user_agent = '') use ($builder, $engagement_repository): void {
        $engagement_repository->upsert_event([
            'landing_key' => 'lp-brand',
            'session_token' => $session_token,
            'service_key' => 'svc-brand',
            'ua_ch_supported' => 1,
            'ua_ch_platform' => 'Android',
            'ua_ch_model' => $model,
            'user_agent' => $user_agent,
        ], 'page_loaded', '2026-04-01 08:00:00');

        $snapshot = $builder->build([
            'landing_page_key' => 'lp-brand',
            'service_key' => 'svc-brand',
            'session_ref' => $session_token,
        ]);

        kiwi_assert_same($expected, (string) ($snapshot['device_brand'] ?? ''), 'Expected device brand normalization for model ' . $model . '.');
    };

    $assert_brand('(unknown)', 'sess-brand-unknown', 'Moto G Power');
    $assert_brand('Samsung', 'sess-brand-samsung', 'SM-S921B');
    $assert_brand('Google', 'sess-brand-pixel', 'Pixel 8');
    $assert_brand('Xiaomi', 'sess-brand-xiaomi', 'Xiaomi 14');
    $assert_brand('Xiaomi', 'sess-brand-redmi', 'Redmi Note 13');
    $assert_brand('Xiaomi', 'sess-brand-poco', 'POCO F6');
});

kiwi_run_test('Kiwi_Sales_Attribution_Snapshot_Builder uses stored IP buckets and rejects legacy-only IPs', function (): void {
    $landing_session_repository = new Kiwi_Test_Landing_Page_Session_Repository();
    $builder = new Kiwi_Sales_Attribution_Snapshot_Builder($landing_session_repository);

    $landing_session_repository->insert([
        'landing_key' => 'lp-v6',
        'service_key' => 'svc-v6',
        'session_token' => 'sess-v6',
        'remote_ip' => '2001:db8:85a3::8a2e:370:7334',
        'client_ip_version' => 'ipv6',
        'client_ip_prefix' => '2001:db8:85a3::/48',
    ]);
    $ipv6_snapshot = $builder->build([
        'landing_page_key' => 'lp-v6',
        'service_key' => 'svc-v6',
        'session_ref' => 'sess-v6',
    ]);

    $landing_session_repository->insert([
        'landing_key' => 'lp-legacy-ip',
        'service_key' => 'svc-legacy-ip',
        'session_token' => 'sess-legacy-ip',
        'remote_ip' => '203.0.113.44',
    ]);
    $legacy_snapshot = $builder->build([
        'landing_page_key' => 'lp-legacy-ip',
        'service_key' => 'svc-legacy-ip',
        'session_ref' => 'sess-legacy-ip',
    ]);

    kiwi_assert_same('ipv6', (string) ($ipv6_snapshot['client_ip_version'] ?? ''), 'Expected IPv6 version marker.');
    kiwi_assert_same('2001:db8:85a3::/48', (string) ($ipv6_snapshot['client_ip_prefix'] ?? ''), 'Expected IPv6 /48 prefix.');
    kiwi_assert_same('', (string) ($legacy_snapshot['client_ip'] ?? ''), 'Expected legacy-only remote_ip values not to be promoted into sales snapshots.');
    kiwi_assert_same('(unknown)', (string) ($legacy_snapshot['client_ip_version'] ?? ''), 'Expected missing stored IP versions to use the unknown bucket.');
    kiwi_assert_same('(unknown)', (string) ($legacy_snapshot['client_ip_prefix'] ?? ''), 'Expected missing stored IP prefixes to use the unknown bucket.');
    kiwi_assert_same('landing_page_session.client_ip_buckets_unknown', (string) ($legacy_snapshot['attribution_snapshot']['debug']['ip_source'] ?? ''), 'Expected debug data to show the unknown stored bucket path.');
});

kiwi_run_test('Kiwi_Sales_Attribution_Snapshot_Builder copies stored landing-session IP buckets', function (): void {
    $landing_session_repository = new Kiwi_Test_Landing_Page_Session_Repository();
    $builder = new Kiwi_Sales_Attribution_Snapshot_Builder($landing_session_repository);

    $landing_session_repository->insert([
        'landing_key' => 'lp-stored-ip',
        'service_key' => 'svc-stored-ip',
        'session_token' => 'sess-stored-ip',
        'remote_ip' => '198.51.100.77',
        'client_ip_version' => 'ipv4',
        'client_ip_prefix' => '203.0.113.0/24',
    ]);

    $snapshot = $builder->build([
        'landing_page_key' => 'lp-stored-ip',
        'service_key' => 'svc-stored-ip',
        'session_ref' => 'sess-stored-ip',
    ]);

    kiwi_assert_same('198.51.100.77', (string) ($snapshot['client_ip'] ?? ''), 'Expected raw sale IP to remain copied from the landing session.');
    kiwi_assert_same('203.0.113.0/24', (string) ($snapshot['client_ip_prefix'] ?? ''), 'Expected sales snapshot to use stored landing-session IP buckets instead of re-deriving from remote_ip.');
    kiwi_assert_same('landing_page_session.client_ip_buckets', (string) ($snapshot['attribution_snapshot']['debug']['ip_source'] ?? ''), 'Expected debug data to show stored landing-session bucket usage.');
});

kiwi_run_test('Kiwi_Shared_Sales_Recorder writes transaction_id to sales records when provided', function (): void {
    $repository = new Kiwi_Test_Sales_Repository();
    $recorder = new Kiwi_Shared_Sales_Recorder($repository);

    $recorder->record_successful_one_off_sale([
        'sale_reference' => 'sale-explicit-1',
        'flow_reference' => 'txn_1234567890abcdef1234567890abcd-a1b2c3d4e5f6',
        'transaction_id' => 'txn_explicit_123456789012',
        'service_key' => 'nth_fr_one_off_jplay',
        'landing_key' => 'lp2-fr',
        'landing_session_token' => 'sess-explicit-1',
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
    kiwi_assert_same('nth_fr_one_off_jplay', $repository->upsert_calls[0]['service_key'] ?? '', 'Expected shared sales recorder to persist service_key.');
    kiwi_assert_same('lp2-fr', $repository->upsert_calls[0]['landing_key'] ?? '', 'Expected shared sales recorder to persist landing_key.');
    kiwi_assert_same('sess-explicit-1', $repository->upsert_calls[0]['session_ref'] ?? '', 'Expected shared sales recorder to persist landing session reference when available.');
    kiwi_assert_same('2026-04-01', $repository->upsert_calls[0]['attribution_metric_date'] ?? '', 'Expected shared sales recorder to fall back metric date to sale completion date.');
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

kiwi_run_test('Kiwi_Affiliate_Postback_Dispatcher maps custom_field1 placeholder to operator_name', function (): void {
    $config = new Kiwi_Test_Attribution_Config(
        'https://offers-kiwimobile.affise.com/postback?clickid={clickid}&custom_field1={{custom_field1}}&goal=sale',
        ''
    );
    $dispatcher = new Kiwi_Test_Affiliate_Postback_Dispatcher($config);

    $url = $dispatcher->build_postback_url('aff:custom:field1', [], [
        'operator_name' => 'Orange FR',
    ]);

    kiwi_assert_true(
        strpos($url, 'custom_field1=Orange%20FR') !== false,
        'Expected dispatcher to replace custom_field1 placeholders with URL-encoded operator_name.'
    );
    kiwi_assert_same(
        1,
        substr_count($url, 'custom_field1='),
        'Expected dispatcher not to append a duplicate custom_field1 when the template defines it.'
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
        'tksource' => 'PropellerAds',
        'tkzone' => '10766952',
    ]));
    $cta_click_a = $routes->handle_event(new WP_REST_Request([], [
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-kpi-1',
        'event_type' => 'cta_click',
        'cta_step' => 'cta2',
        'event_value' => 'cta2:.cta',
    ]));
    $cta_click_b = $routes->handle_event(new WP_REST_Request([], [
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-kpi-1',
        'event_type' => 'cta_click',
        'cta_step' => 'cta2',
        'event_value' => 'cta2:.cta-confirm',
    ]));

    $row = $engagement_repository->get_by_landing_session('lp2-fr', 'sess-kpi-1');

    kiwi_assert_true(($page_loaded->data['engagement_recorded'] ?? false), 'Expected page_loaded engagement event to be stored.');
    kiwi_assert_true(($cta_click_a->data['engagement_recorded'] ?? false), 'Expected first cta_click engagement event to be stored.');
    kiwi_assert_true(($cta_click_b->data['engagement_recorded'] ?? false), 'Expected repeated cta_click engagement events to update click count.');
    kiwi_assert_true(is_array($row), 'Expected engagement storage row to be persisted by landing/session.');
    kiwi_assert_same('2026-04-01 12:00:00', (string) ($row['page_loaded_at'] ?? ''), 'Expected first page_loaded timestamp to be captured.');
    kiwi_assert_same(2, (int) ($row['cta_click_count'] ?? 0), 'Expected cta_click count to increment for repeated click events.');
    kiwi_assert_same(2, (int) ($row['cta2_click_count'] ?? 0), 'Expected cta_step=cta2 engagement events to increment only the CTA2 engagement count.');
    kiwi_assert_same(0, (int) ($row['cta1_click_count'] ?? 0), 'Expected cta_step=cta2 engagement events not to increment CTA1 engagement count.');
    kiwi_assert_same('affpid_42', (string) ($row['pid'] ?? ''), 'Expected landing engagement storage to persist pid from KPI event payload.');
    kiwi_assert_same('aff-click-42', (string) ($row['click_id'] ?? ''), 'Expected landing engagement storage to persist clickid from KPI event payload.');
    kiwi_assert_same('PropellerAds', (string) ($row['tksource'] ?? ''), 'Expected landing engagement storage to persist tksource from KPI event payload.');
    kiwi_assert_same('10766952', (string) ($row['tkzone'] ?? ''), 'Expected landing engagement storage to persist tkzone from KPI event payload.');
    kiwi_assert_same(0, (int) ($summary_repository->rows['lp2-fr']['cta1'] ?? 0), 'Expected engagement-only events not to mutate KPI CTA counters.');
    kiwi_assert_same(0, (int) ($summary_repository->rows['lp2-fr']['cta2'] ?? 0), 'Expected cta_step engagement metadata not to double-increment KPI CTA2 counters.');
});

kiwi_run_test('Kiwi_Landing_Kpi_Rest_Routes enriches landing session device context on page load', function (): void {
    $landing_pages = [
        'lp2-fr' => [
            'service_key' => 'nth_fr_one_off_jplay',
            'provider' => 'nth',
            'flow' => 'nth-fr-one-off',
        ],
    ];
    $previous_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 16; SM-S921B) AppleWebKit/537.36 Chrome/147.0.0.0 Mobile Safari/537.36';

    try {
        $summary_repository = new Kiwi_Test_Landing_Kpi_Summary_Repository();
        $engagement_repository = new Kiwi_Test_Premium_Sms_Landing_Engagement_Repository();
        $landing_session_repository = new Kiwi_Test_Landing_Page_Session_Repository();
        $landing_session_repository->insert([
            'landing_key' => 'lp2-fr',
            'service_key' => 'nth_fr_one_off_jplay',
            'session_token' => 'sess-device-enrich',
        ]);
        $config = new Kiwi_Test_Landing_Ua_Config('onload', $landing_pages);
        $service = new Kiwi_Landing_Kpi_Service($config, $summary_repository);
        $routes = new Kiwi_Landing_Kpi_Rest_Routes(
            $config,
            $service,
            $engagement_repository,
            null,
            null,
            null,
            $landing_session_repository,
            new Kiwi_Device_Context_Normalizer()
        );

        $routes->handle_event(new WP_REST_Request([], [
            'landing_key' => 'lp2-fr',
            'session_token' => 'sess-device-enrich',
            'event_type' => 'page_loaded',
            'ua_ch_supported' => 1,
            'ua_ch_mobile' => 1,
            'ua_ch_platform' => 'Android',
            'ua_ch_platform_version' => '16.0.0',
            'ua_ch_model' => 'SM-S921B',
            'ua_ch_brands' => 'Google Chrome 147',
            'ua_ch_full_version_list' => 'Google Chrome 147.0.0.0',
        ]));
        $enriched = $landing_session_repository->find_by_landing_session('lp2-fr', 'sess-device-enrich') ?? [];

        unset($_SERVER['HTTP_USER_AGENT']);
        $routes->handle_event(new WP_REST_Request([], [
            'landing_key' => 'lp2-fr',
            'session_token' => 'sess-device-enrich',
            'event_type' => 'page_loaded',
        ]));
        $after_unknown = $landing_session_repository->find_by_landing_session('lp2-fr', 'sess-device-enrich') ?? [];

        kiwi_assert_same('Samsung', (string) ($enriched['device_brand'] ?? ''), 'Expected page_loaded UA context to enrich the landing-session device brand.');
        kiwi_assert_same('Android', (string) ($enriched['os'] ?? ''), 'Expected page_loaded UA context to enrich the landing-session OS.');
        kiwi_assert_same('16', (string) ($enriched['os_version'] ?? ''), 'Expected page_loaded UA context to enrich the landing-session OS version.');
        kiwi_assert_same('Chrome', (string) ($enriched['browser'] ?? ''), 'Expected page_loaded UA context to enrich the landing-session browser.');
        kiwi_assert_same('Samsung', (string) ($after_unknown['device_brand'] ?? ''), 'Expected unknown follow-up context not to overwrite known landing-session device data.');
        kiwi_assert_same('Android', (string) ($after_unknown['os'] ?? ''), 'Expected unknown follow-up context not to overwrite known OS data.');
    } finally {
        if ($previous_user_agent === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $previous_user_agent;
        }
    }
});

kiwi_run_test('Kiwi_Landing_Kpi_Rest_Routes enriches landing session device context on CTA click', function (): void {
    $landing_pages = [
        'lp2-fr' => [
            'service_key' => 'nth_fr_one_off_jplay',
            'provider' => 'nth',
            'flow' => 'nth-fr-one-off',
        ],
    ];
    $previous_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 16; SM-S921B) AppleWebKit/537.36 Chrome/147.0.0.0 Mobile Safari/537.36';

    try {
        $summary_repository = new Kiwi_Test_Landing_Kpi_Summary_Repository();
        $engagement_repository = new Kiwi_Test_Premium_Sms_Landing_Engagement_Repository();
        $landing_session_repository = new Kiwi_Test_Landing_Page_Session_Repository();
        $landing_session_repository->insert([
            'landing_key' => 'lp2-fr',
            'service_key' => 'nth_fr_one_off_jplay',
            'session_token' => 'sess-device-onclick',
        ]);
        $config = new Kiwi_Test_Landing_Ua_Config('onclick', $landing_pages);
        $service = new Kiwi_Landing_Kpi_Service($config, $summary_repository);
        $routes = new Kiwi_Landing_Kpi_Rest_Routes(
            $config,
            $service,
            $engagement_repository,
            null,
            null,
            null,
            $landing_session_repository,
            new Kiwi_Device_Context_Normalizer()
        );

        $routes->handle_event(new WP_REST_Request([], [
            'landing_key' => 'lp2-fr',
            'session_token' => 'sess-device-onclick',
            'event_type' => 'page_loaded',
            'ua_ch_supported' => 1,
            'ua_ch_platform' => 'Android',
            'ua_ch_model' => 'SM-S921B',
        ]));
        $after_load = $landing_session_repository->find_by_landing_session('lp2-fr', 'sess-device-onclick') ?? [];

        $routes->handle_event(new WP_REST_Request([], [
            'landing_key' => 'lp2-fr',
            'session_token' => 'sess-device-onclick',
            'event_type' => 'cta_click',
            'cta_step' => 'cta1',
            'ua_ch_supported' => 1,
            'ua_ch_mobile' => 1,
            'ua_ch_platform' => 'Android',
            'ua_ch_platform_version' => '16.0.0',
            'ua_ch_model' => 'SM-S921B',
            'ua_ch_brands' => 'Google Chrome 147',
            'ua_ch_full_version_list' => 'Google Chrome 147.0.0.0',
        ]));
        $after_click = $landing_session_repository->find_by_landing_session('lp2-fr', 'sess-device-onclick') ?? [];

        kiwi_assert_same('(unknown)', (string) ($after_load['device_brand'] ?? ''), 'Expected onclick page_loaded events not to enrich landing-session device buckets.');
        kiwi_assert_same('Samsung', (string) ($after_click['device_brand'] ?? ''), 'Expected onclick CTA UA context to enrich the landing-session device brand.');
        kiwi_assert_same('Android', (string) ($after_click['os'] ?? ''), 'Expected onclick CTA UA context to enrich the landing-session OS.');
        kiwi_assert_same('16', (string) ($after_click['os_version'] ?? ''), 'Expected onclick CTA UA context to enrich the landing-session OS version.');
        kiwi_assert_same('Chrome', (string) ($after_click['browser'] ?? ''), 'Expected onclick CTA UA context to enrich the landing-session browser.');
    } finally {
        if ($previous_user_agent === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $previous_user_agent;
        }
    }
});

kiwi_run_test('Kiwi_Landing_Kpi_Rest_Routes gates UA context by landing tracking mode', function (): void {
    $landing_pages = [
        'lp2-fr' => [
            'service_key' => 'nth_fr_one_off_jplay',
            'provider' => 'nth',
            'flow' => 'nth-fr-one-off',
        ],
    ];
    $previous_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 16; SM-S921B) AppleWebKit/537.36 Chrome/147.0.0.0 Mobile Safari/537.36';

    try {
        $summary_repository = new Kiwi_Test_Landing_Kpi_Summary_Repository();
        $service = new Kiwi_Landing_Kpi_Service(new Kiwi_Test_Landing_Ua_Config('disabled', $landing_pages), $summary_repository);
        $disabled_engagement_repository = new Kiwi_Test_Premium_Sms_Landing_Engagement_Repository();
        $disabled_handoff_repository = new Kiwi_Test_Landing_Handoff_Event_Repository();
        $disabled_routes = new Kiwi_Landing_Kpi_Rest_Routes(
            new Kiwi_Test_Landing_Ua_Config('disabled', $landing_pages),
            $service,
            $disabled_engagement_repository,
            null,
            $disabled_handoff_repository
        );

        $disabled_routes->handle_event(new WP_REST_Request([], [
            'landing_key' => 'lp2-fr',
            'session_token' => 'sess-disabled',
            'event_type' => 'page_loaded',
            'ua_ch_supported' => 1,
            'ua_ch_mobile' => 1,
            'ua_ch_platform' => 'Android',
            'ua_ch_model' => 'SM-S921B',
        ]));
        $disabled_routes->handle_event(new WP_REST_Request([], [
            'landing_key' => 'lp2-fr',
            'session_token' => 'sess-disabled',
            'event_type' => 'sms_handoff_attempted',
            'handoff_id' => 'hof-disabled',
            'href_scheme' => 'sms',
            'sms_recipient' => '84072',
            'ua_ch_supported' => 1,
            'ua_ch_mobile' => 1,
            'ua_ch_platform' => 'Android',
            'ua_ch_model' => 'SM-S921B',
        ]));

        $disabled_row = $disabled_engagement_repository->get_by_landing_session('lp2-fr', 'sess-disabled');
        $disabled_handoff = array_values($disabled_handoff_repository->rows)[0] ?? [];

        kiwi_assert_same(0, (int) ($disabled_row['ua_ch_supported'] ?? 0), 'Expected disabled mode to drop posted engagement UA Client Hints.');
        kiwi_assert_same('', (string) ($disabled_row['ua_ch_platform'] ?? ''), 'Expected disabled mode not to persist engagement UA platform.');
        kiwi_assert_same('', (string) ($disabled_row['user_agent'] ?? ''), 'Expected disabled mode not to persist engagement user agent.');
        kiwi_assert_same(0, (int) ($disabled_handoff['ua_ch_supported'] ?? 0), 'Expected disabled mode to drop posted handoff UA Client Hints.');
        kiwi_assert_same('', (string) ($disabled_handoff['user_agent'] ?? ''), 'Expected disabled mode not to persist handoff user agent.');

        $onclick_config = new Kiwi_Test_Landing_Ua_Config('onclick', $landing_pages);
        $onclick_service = new Kiwi_Landing_Kpi_Service($onclick_config, new Kiwi_Test_Landing_Kpi_Summary_Repository());
        $onclick_repository = new Kiwi_Test_Premium_Sms_Landing_Engagement_Repository();
        $onclick_routes = new Kiwi_Landing_Kpi_Rest_Routes($onclick_config, $onclick_service, $onclick_repository);

        $onclick_routes->handle_event(new WP_REST_Request([], [
            'landing_key' => 'lp2-fr',
            'session_token' => 'sess-onclick',
            'event_type' => 'page_loaded',
            'ua_ch_supported' => 1,
            'ua_ch_platform' => 'Android',
        ]));
        $row_after_load = $onclick_repository->get_by_landing_session('lp2-fr', 'sess-onclick');
        kiwi_assert_same('', (string) ($row_after_load['ua_ch_platform'] ?? ''), 'Expected onclick mode not to persist UA Client Hints on page_loaded.');

        $onclick_routes->handle_event(new WP_REST_Request([], [
            'landing_key' => 'lp2-fr',
            'session_token' => 'sess-onclick',
            'event_type' => 'cta_click',
            'ua_ch_supported' => 1,
            'ua_ch_mobile' => 1,
            'ua_ch_platform' => 'Android',
            'ua_ch_platform_version' => '16.0.0',
            'ua_ch_model' => 'SM-S921B',
            'ua_ch_brands' => 'Chromium 147, Google Chrome 147',
            'ua_ch_full_version_list' => 'Google Chrome 147.0.7727.138',
        ]));
        $row_after_click = $onclick_repository->get_by_landing_session('lp2-fr', 'sess-onclick');
        kiwi_assert_same('Android', (string) ($row_after_click['ua_ch_platform'] ?? ''), 'Expected onclick mode to persist UA Client Hints on CTA engagement.');
        kiwi_assert_same('SM-S921B', (string) ($row_after_click['ua_ch_model'] ?? ''), 'Expected onclick mode to persist UA Client Hints model on CTA engagement.');
        kiwi_assert_contains('Android 16', (string) ($row_after_click['user_agent'] ?? ''), 'Expected allowed UA engagement persistence to store the request user agent.');

        $onload_config = new Kiwi_Test_Landing_Ua_Config('onload', $landing_pages);
        $onload_service = new Kiwi_Landing_Kpi_Service($onload_config, new Kiwi_Test_Landing_Kpi_Summary_Repository());
        $onload_repository = new Kiwi_Test_Premium_Sms_Landing_Engagement_Repository();
        $onload_routes = new Kiwi_Landing_Kpi_Rest_Routes($onload_config, $onload_service, $onload_repository);
        $onload_routes->handle_event(new WP_REST_Request([], [
            'landing_key' => 'lp2-fr',
            'session_token' => 'sess-onload',
            'event_type' => 'page_loaded',
            'ua_ch_supported' => 1,
            'ua_ch_mobile' => 1,
            'ua_ch_platform' => 'Android',
            'ua_ch_platform_version' => '16.0.0',
            'ua_ch_model' => 'SM-S921B',
        ]));
        $onload_row = $onload_repository->get_by_landing_session('lp2-fr', 'sess-onload');
        kiwi_assert_same('Android', (string) ($onload_row['ua_ch_platform'] ?? ''), 'Expected onload mode to persist UA Client Hints with page_loaded.');
        kiwi_assert_same('16.0.0', (string) ($onload_row['ua_ch_platform_version'] ?? ''), 'Expected onload mode to persist platform version with page_loaded.');
    } finally {
        if ($previous_user_agent === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $previous_user_agent;
        }
    }
});

kiwi_run_test('Kiwi_Landing_Kpi_Rest_Routes resolves traffic source fields from attribution fallback', function (): void {
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
    $click_repository = new Kiwi_Test_Click_Attribution_Repository();
    $click_repository->upsert_capture([
        'tracking_token' => 'TOKSOURCEFALLBACK1',
        'service_key' => 'nth_fr_one_off_jplay',
        'landing_page_key' => 'lp2-fr',
        'session_ref' => 'sess-source-fallback',
        'transaction_id' => 'txn_source_fallback_1',
        'click_id' => 'click-source-fallback',
        'pid' => 'pid-source-fallback',
        'tksource' => 'PropellerAds',
        'tkzone' => '10766952',
        'expires_at' => '2026-04-03 12:00:00',
    ]);
    $service = new Kiwi_Landing_Kpi_Service($config, $summary_repository);
    $routes = new Kiwi_Landing_Kpi_Rest_Routes($config, $service, $engagement_repository, $click_repository);

    $routes->handle_event(new WP_REST_Request([], [
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-source-fallback',
        'event_type' => 'page_loaded',
    ]));

    $row = $engagement_repository->get_by_landing_session('lp2-fr', 'sess-source-fallback');

    kiwi_assert_same('pid-source-fallback', (string) ($row['pid'] ?? ''), 'Expected engagement fallback to resolve pid from attribution.');
    kiwi_assert_same('click-source-fallback', (string) ($row['click_id'] ?? ''), 'Expected engagement fallback to resolve click_id from attribution.');
    kiwi_assert_same('PropellerAds', (string) ($row['tksource'] ?? ''), 'Expected engagement fallback to resolve tksource from attribution.');
    kiwi_assert_same('10766952', (string) ($row['tkzone'] ?? ''), 'Expected engagement fallback to resolve tkzone from attribution.');
});

kiwi_run_test('Kiwi_Landing_Handoff_Event_Repository stores handoff events idempotently', function (): void {
    $repository = new Kiwi_Test_Landing_Handoff_Event_Repository();

    $first = $repository->insert_if_new([
        'landing_key' => 'lp2-fr',
        'service_key' => 'nth_fr_one_off_jplay',
        'provider_key' => 'nth',
        'flow_key' => 'nth-fr-one-off',
        'pid' => 'pid-1',
        'click_id' => 'click-1',
        'tksource' => 'source-1',
        'tkzone' => 'zone-1',
        'session_token' => 'sess-handoff-1',
        'handoff_id' => 'hof_abc123',
        'event_type' => 'sms_handoff_attempted',
        'href_scheme' => 'sms',
        'sms_recipient' => '84072',
        'sms_body_present' => true,
        'sms_body_has_transaction' => true,
        'elapsed_ms' => 15,
        'visibility_state' => 'visible',
        'ua_ch_supported' => 1,
        'ua_ch_mobile' => 1,
        'ua_ch_platform' => 'Android',
        'ua_ch_platform_version' => '16.0.0',
        'ua_ch_model' => 'SM-S921B',
        'ua_ch_brands' => 'Chromium 147, Google Chrome 147',
        'ua_ch_full_version_list' => 'Google Chrome 147.0.7727.138',
        'user_agent' => 'Android Test UA',
    ]);
    $duplicate = $repository->insert_if_new([
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-handoff-1',
        'handoff_id' => 'hof_abc123',
        'event_type' => 'sms_handoff_attempted',
    ]);

    kiwi_assert_true(($first['inserted'] ?? false) === true, 'Expected first handoff event to be inserted.');
    kiwi_assert_true(($duplicate['inserted'] ?? true) === false, 'Expected duplicate handoff event identity not to insert again.');
    kiwi_assert_same(1, count($repository->rows), 'Expected only one handoff row after duplicate insert.');
    kiwi_assert_same('84072', (string) ($first['row']['sms_recipient'] ?? ''), 'Expected handoff storage to persist SMS recipient.');
    kiwi_assert_same(1, (int) ($first['row']['sms_body_has_transaction'] ?? 0), 'Expected handoff storage to persist transaction-token presence.');
    kiwi_assert_same('source-1', (string) ($first['row']['tksource'] ?? ''), 'Expected handoff storage to persist tksource context.');
    kiwi_assert_same('zone-1', (string) ($first['row']['tkzone'] ?? ''), 'Expected handoff storage to persist tkzone context.');
    kiwi_assert_same(1, (int) ($first['row']['ua_ch_supported'] ?? 0), 'Expected handoff storage to persist UA Client Hints support.');
    kiwi_assert_same(1, (int) ($first['row']['ua_ch_mobile'] ?? 0), 'Expected handoff storage to persist UA Client Hints mobile flag.');
    kiwi_assert_same('Android', (string) ($first['row']['ua_ch_platform'] ?? ''), 'Expected handoff storage to persist UA Client Hints platform.');
    kiwi_assert_same('16.0.0', (string) ($first['row']['ua_ch_platform_version'] ?? ''), 'Expected handoff storage to persist UA Client Hints platform version.');
    kiwi_assert_same('SM-S921B', (string) ($first['row']['ua_ch_model'] ?? ''), 'Expected handoff storage to persist UA Client Hints model.');
    kiwi_assert_same('Chromium 147, Google Chrome 147', (string) ($first['row']['ua_ch_brands'] ?? ''), 'Expected handoff storage to persist UA Client Hints brands.');
    kiwi_assert_same('Google Chrome 147.0.7727.138', (string) ($first['row']['ua_ch_full_version_list'] ?? ''), 'Expected handoff storage to persist UA Client Hints full version list.');
});

kiwi_run_test('Kiwi_Landing_Handoff_Event_Repository handles duplicate DB inserts without wpdb insert errors', function (): void {
    global $wpdb;

    $had_wpdb = array_key_exists('wpdb', $GLOBALS);
    $previous_wpdb = $GLOBALS['wpdb'] ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Landing_Handoff_Event();

    try {
        $repository = new Kiwi_Landing_Handoff_Event_Repository();
        $event = [
            'landing_key' => 'lp3-fr',
            'service_key' => 'nth_fr_one_off_jplay',
            'provider_key' => 'nth',
            'flow_key' => 'nth-fr-one-off',
            'pid' => '106',
            'click_id' => 'click-db-dup',
            'tksource' => 'PropellerAds',
            'tkzone' => 'zone_10766952',
            'session_token' => 'sess-db-dup',
            'handoff_id' => 'hof_duplicate_hidden',
            'event_type' => 'sms_handoff_hidden',
            'href_scheme' => 'sms',
            'sms_recipient' => '84072',
            'sms_body_present' => true,
            'sms_body_has_transaction' => true,
            'elapsed_ms' => 36,
            'visibility_state' => 'visible',
            'ua_ch_supported' => true,
            'ua_ch_mobile' => true,
            'ua_ch_platform' => 'Android',
            'ua_ch_platform_version' => '9.0.0',
            'ua_ch_model' => 'SM-G955F',
            'ua_ch_brands' => 'Android WebView 138',
            'ua_ch_full_version_list' => 'Android WebView 138.0.7204.179',
            'user_agent' => 'Android WebView Test UA',
        ];

        $first = $repository->insert_if_new($event);
        $duplicate = $repository->insert_if_new($event);
        $table = $wpdb->prefix . 'kiwi_landing_handoff_events';

        kiwi_assert_true(($first['inserted'] ?? false) === true, 'Expected first DB-backed handoff event to insert.');
        kiwi_assert_true(($duplicate['inserted'] ?? true) === false, 'Expected duplicate DB-backed handoff event to be returned as idempotent.');
        kiwi_assert_same((int) ($first['row']['id'] ?? 0), (int) ($duplicate['row']['id'] ?? -1), 'Expected duplicate DB-backed handoff event to resolve the stored row.');
        kiwi_assert_same(1, count($wpdb->tables[$table] ?? []), 'Expected duplicate DB-backed handoff event to keep a single row.');
        kiwi_assert_same(0, (int) $wpdb->insert_calls, 'Expected handoff repository to avoid wpdb::insert duplicate-key errors.');
        kiwi_assert_contains('ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)', implode("\n", $wpdb->queries), 'Expected handoff repository to use duplicate-safe insert SQL.');
    } finally {
        if ($had_wpdb) {
            $wpdb = $previous_wpdb;
        } else {
            unset($GLOBALS['wpdb']);
        }
    }
});

kiwi_run_test('Kiwi_Sms_Body_Variant_Repository stores assignments and summary idempotently', function (): void {
    $repository = new Kiwi_Test_Sms_Body_Variant_Repository();

    $first = $repository->insert_if_new([
        'landing_key' => 'lp2-fr',
        'service_key' => 'nth_fr_one_off_jplay',
        'provider_key' => 'nth',
        'flow_key' => 'nth-fr-one-off',
        'country' => 'FR',
        'keyword' => 'JPLAY',
        'shortcode' => '84072',
        'session_token' => 'sess-variant-1',
        'transaction_id' => 'txn_variant_12345678',
        'visible_token' => 'ArcadeHerovariant_12345678',
        'variant_key' => 'game_word',
        'seed' => 'ArcadeHero',
        'sms_body' => 'JPLAY ArcadeHerovariant_12345678',
    ]);
    $duplicate = $repository->insert_if_new([
        'landing_key' => 'lp2-fr',
        'service_key' => 'nth_fr_one_off_jplay',
        'transaction_id' => 'txn_variant_12345678',
        'visible_token' => 'ArcadeHerovariant_12345678',
        'variant_key' => 'game_word',
        'sms_body' => 'JPLAY ArcadeHerovariant_12345678',
    ]);
    $visible_conflict = $repository->insert_if_new([
        'landing_key' => 'lp2-fr',
        'service_key' => 'nth_fr_one_off_jplay',
        'transaction_id' => 'txn_variant_other_123',
        'visible_token' => 'ArcadeHerovariant_12345678',
        'variant_key' => 'game_word',
        'sms_body' => 'JPLAY ArcadeHerovariant_12345678',
    ]);
    $repository->mark_event_by_transaction_id('txn_variant_12345678', 'cta1');
    $repository->mark_event_by_transaction_id('txn_variant_12345678', 'cta1');

    $summary = $repository->get_summary_rows()[0] ?? [];

    kiwi_assert_true(($first['inserted'] ?? false), 'Expected first SMS body assignment to insert.');
    kiwi_assert_true(($duplicate['inserted'] ?? true) === false, 'Expected duplicate transaction assignment not to insert.');
    kiwi_assert_true(($visible_conflict['row'] ?? null) === null, 'Expected visible-token uniqueness to reject conflicting assignments.');
    kiwi_assert_same(1, count($repository->assignments), 'Expected one assignment row after duplicate/conflict attempts.');
    kiwi_assert_same(1, (int) ($summary['assignments'] ?? 0), 'Expected assignment summary counter to increment once.');
    kiwi_assert_same(1, (int) ($summary['cta1'] ?? 0), 'Expected CTA1 summary counter to be idempotent per assignment.');
});

kiwi_run_test('Kiwi_Sms_Body_Variant_Repository recalculates summary rates from persisted counters', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $had_wpdb = isset($wpdb);
    $wpdb = new Kiwi_Test_Wpdb_Sms_Body_Variant();

    try {
        $repository = new Kiwi_Sms_Body_Variant_Repository();
        $insert = $repository->insert_if_new([
            'landing_key' => 'lp2-fr',
            'service_key' => 'nth_fr_one_off_jplay',
            'provider_key' => 'nth',
            'flow_key' => 'nth-fr-one-off',
            'country' => 'FR',
            'keyword' => 'JPLAY',
            'shortcode' => '84072',
            'session_token' => 'sess-rate-regression',
            'transaction_id' => 'txn_rate_regression_12345678',
            'visible_token' => 'rate_regression_12345678',
            'variant_key' => 'bare_id',
            'sms_body' => 'JPLAY rate_regression_12345678',
        ]);

        $repository->mark_event_by_transaction_id('txn_rate_regression_12345678', 'cta1');
        $repository->mark_event_by_transaction_id('txn_rate_regression_12345678', 'sms_handoff_attempted');
        $repository->mark_event_by_transaction_id('txn_rate_regression_12345678', 'sms_handoff_hidden');
        $repository->mark_event_by_transaction_id('txn_rate_regression_12345678', 'conv');

        $rows = $repository->get_summary_rows();
        $summary = $rows[0] ?? [];

        kiwi_assert_true(($insert['inserted'] ?? false), 'Expected actual repository assignment insert to succeed with test wpdb.');
        kiwi_assert_same(1, (int) ($summary['assignments'] ?? 0), 'Expected persisted assignments counter to be 1.');
        kiwi_assert_same(1, (int) ($summary['cta1'] ?? 0), 'Expected persisted CTA1 counter to be 1.');
        kiwi_assert_same(1, (int) ($summary['handoff_attempted'] ?? 0), 'Expected persisted attempted handoff counter to be 1.');
        kiwi_assert_same(1, (int) ($summary['handoff_hidden'] ?? 0), 'Expected persisted hidden handoff counter to be 1.');
        kiwi_assert_same(1, (int) ($summary['conv'] ?? 0), 'Expected persisted conversion counter to be 1.');
        kiwi_assert_same(100.0, (float) ($summary['cta1_cr'] ?? 0), 'Expected cta1_cr to be calculated from persisted counters without double-counting.');
        kiwi_assert_same(100.0, (float) ($summary['handoff_hidden_cr'] ?? 0), 'Expected handoff_hidden_cr to be calculated from persisted counters without double-counting.');
        kiwi_assert_same(100.0, (float) ($summary['conv_cr'] ?? 0), 'Expected conv_cr to be calculated from persisted counters without double-counting.');
        kiwi_assert_same(100.0, (float) ($summary['conv_per_cta1_cr'] ?? 0), 'Expected conv_per_cta1_cr to be calculated from persisted counters without double-counting.');
        kiwi_assert_same(100.0, (float) ($summary['conv_per_hidden_cr'] ?? 0), 'Expected conv_per_hidden_cr to be calculated from persisted counters without double-counting.');
    } finally {
        if ($had_wpdb) {
            $wpdb = $previous_wpdb;
        } else {
            unset($GLOBALS['wpdb']);
        }
    }
});

kiwi_run_test('Kiwi_Sms_Body_Variant_Service builds stable SMS body variants', function (): void {
    $config = new Kiwi_Test_Config();
    $repository = new Kiwi_Test_Sms_Body_Variant_Repository();
    $service = new Kiwi_Sms_Body_Variant_Service($config, $repository);

    $landing = [
        'key' => 'lp2-fr',
        'country' => 'FR',
        'provider' => 'nth',
        'flow' => 'nth-fr-one-off',
        'service_key' => 'nth_fr_one_off_jplay',
    ];
    $nth_service = [
        'country' => 'FR',
        'provider' => 'nth',
        'flow' => 'one-off',
        'service_key' => 'nth_fr_one_off_jplay',
    ];
    $attribution = [
        'transaction_id' => 'txn_abcdef1234567890',
        'session_ref' => 'sess-variant-2',
        'click_id' => 'click-variant',
        'pid' => 'pid-variant',
    ];

    $first = $service->build_variant_body('Jplay*', '84072', $landing, $nth_service, $attribution);
    $second = $service->build_variant_body('Jplay*', '84072', $landing, $nth_service, $attribution);
    $variant_key = (string) ($first['assignment']['variant_key'] ?? '');
    $seed = (string) ($first['assignment']['seed'] ?? '');

    kiwi_assert_same('txn_abcdef1234567890', $service->build_visible_token('txn_abcdef1234567890', 'as_is_txn_prefix'), 'Expected as-is variant to keep txn_ prefix.');
    kiwi_assert_same('abcdef1234567890', $service->build_visible_token('txn_abcdef1234567890', 'bare_id'), 'Expected bare variant to remove txn_ prefix.');
    kiwi_assert_same('ArcadeHeroabcdef1234567890', $service->build_visible_token('txn_abcdef1234567890', 'game_word', 'ArcadeHero'), 'Expected game-word variant to prepend deterministic seed.');
    kiwi_assert_same('ActiverJeuxabcdef1234567890', $service->build_visible_token('txn_abcdef1234567890', 'cta_phrase', 'ActiverJeux'), 'Expected CTA phrase variant to prepend deterministic seed.');
    kiwi_assert_true(in_array($variant_key, ['as_is_txn_prefix', 'bare_id', 'game_word', 'cta_phrase'], true), 'Expected service to assign one of the four configured variants.');
    kiwi_assert_true($variant_key === 'game_word' || $variant_key === 'cta_phrase' || $seed === '', 'Expected non-speaking variants to have no seed.');
    kiwi_assert_same((string) ($first['body'] ?? ''), (string) ($second['body'] ?? ''), 'Expected repeated body resolution for one transaction to stay stable.');
    kiwi_assert_same(1, count($repository->assignments), 'Expected service to create one idempotent assignment.');
});

kiwi_run_test('Kiwi_Landing_Kpi_Rest_Routes records SMS handoff events without changing KPI counters', function (): void {
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Android SmsDiag';
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
    $handoff_repository = new Kiwi_Test_Landing_Handoff_Event_Repository();
    $landing_session_repository = new Kiwi_Test_Landing_Page_Session_Repository();
    $landing_session_repository->insert([
        'landing_key' => 'lp2-fr',
        'service_key' => 'nth_fr_one_off_jplay',
        'session_token' => 'sess-handoff-2',
    ]);
    $service = new Kiwi_Landing_Kpi_Service($config, $summary_repository);
    $routes = new Kiwi_Landing_Kpi_Rest_Routes(
        $config,
        $service,
        null,
        null,
        $handoff_repository,
        null,
        $landing_session_repository,
        new Kiwi_Device_Context_Normalizer()
    );

    $attempt = $routes->handle_event(new WP_REST_Request([], [
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-handoff-2',
        'event_type' => 'sms_handoff_attempted',
        'handoff_id' => 'hof_xyz789',
        'href_scheme' => 'sms',
        'sms_recipient' => '84072',
        'sms_body_present' => 1,
        'sms_body_has_transaction' => 1,
        'elapsed_ms' => 0,
        'visibility_state' => 'visible',
        'pid' => 'pid-handoff',
        'clickid' => 'click-handoff',
        'tksource' => 'source-handoff',
        'tkzone' => 'zone-handoff',
        'ua_ch_supported' => 1,
        'ua_ch_mobile' => 1,
        'ua_ch_platform' => 'Android',
        'ua_ch_platform_version' => '15.0.0',
        'ua_ch_model' => 'Pixel 8',
        'ua_ch_brands' => 'Chromium 147, Google Chrome 147',
        'ua_ch_full_version_list' => 'Google Chrome 147.0.7727.138',
    ]));
    $hidden = $routes->handle_event(new WP_REST_Request([], [
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-handoff-2',
        'event_type' => 'sms_handoff_hidden',
        'handoff_id' => 'hof_xyz789',
        'href_scheme' => 'sms',
        'sms_recipient' => '84072',
        'sms_body_present' => 1,
        'sms_body_has_transaction' => 1,
        'elapsed_ms' => 120,
        'visibility_state' => 'hidden',
    ]));
    $invalid = $routes->handle_event(new WP_REST_Request([], [
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-handoff-2',
        'event_type' => 'sms_handoff_unknown',
        'handoff_id' => 'hof_xyz789',
    ]));

    kiwi_assert_true(($attempt->data['handoff_recorded'] ?? false), 'Expected attempted handoff event to be recorded.');
    kiwi_assert_true(($hidden->data['handoff_recorded'] ?? false), 'Expected hidden handoff event to be recorded.');
    kiwi_assert_same(2, count($handoff_repository->rows), 'Expected two distinct handoff event types for one handoff id.');
    kiwi_assert_same('pid-handoff', (string) ($handoff_repository->rows[1]['pid'] ?? ''), 'Expected handoff events to persist pid context.');
    kiwi_assert_same('click-handoff', (string) ($handoff_repository->rows[1]['click_id'] ?? ''), 'Expected handoff events to persist clickid context.');
    kiwi_assert_same('source-handoff', (string) ($handoff_repository->rows[1]['tksource'] ?? ''), 'Expected handoff events to persist tksource context.');
    kiwi_assert_same('zone-handoff', (string) ($handoff_repository->rows[1]['tkzone'] ?? ''), 'Expected handoff events to persist tkzone context.');
    kiwi_assert_same(1, (int) ($handoff_repository->rows[1]['ua_ch_supported'] ?? 0), 'Expected handoff events to persist UA Client Hints support.');
    kiwi_assert_same('Pixel 8', (string) ($handoff_repository->rows[1]['ua_ch_model'] ?? ''), 'Expected handoff events to persist UA Client Hints model from REST payload.');
    kiwi_assert_same('Google Chrome 147.0.7727.138', (string) ($handoff_repository->rows[1]['ua_ch_full_version_list'] ?? ''), 'Expected handoff events to persist UA Client Hints full version list from REST payload.');
    kiwi_assert_same('Pixel 8', (string) ($handoff_repository->rows[1]['raw_context']['ua_client_hints']['ua_ch_model'] ?? ''), 'Expected handoff raw context to include a compact UA Client Hints snapshot.');
    $enriched_session = $landing_session_repository->find_by_landing_session('lp2-fr', 'sess-handoff-2') ?? [];
    kiwi_assert_same('Google', (string) ($enriched_session['device_brand'] ?? ''), 'Expected handoff UA context to enrich the landing-session device brand.');
    kiwi_assert_same('Android', (string) ($enriched_session['os'] ?? ''), 'Expected handoff UA context to enrich the landing-session OS.');
    kiwi_assert_same('15', (string) ($enriched_session['os_version'] ?? ''), 'Expected handoff UA context to enrich the landing-session OS version.');
    kiwi_assert_same('Chrome', (string) ($enriched_session['browser'] ?? ''), 'Expected handoff UA context to enrich the landing-session browser.');
    kiwi_assert_same(0, (int) ($summary_repository->rows['lp2-fr']['cta1'] ?? 0), 'Expected handoff-only events not to mutate KPI CTA counters.');
    kiwi_assert_same(400, $invalid->status, 'Expected unknown handoff event types to be rejected.');

    unset($_SERVER['HTTP_USER_AGENT']);
});

kiwi_run_test('Kiwi_Landing_Kpi_Rest_Routes ignores UA Client Hints when disabled server-side', function (): void {
    $config = new Kiwi_Test_Ua_Client_Hints_Disabled_Config(
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
    $handoff_repository = new Kiwi_Test_Landing_Handoff_Event_Repository();
    $routes = new Kiwi_Landing_Kpi_Rest_Routes(
        $config,
        new Kiwi_Landing_Kpi_Service($config, $summary_repository),
        null,
        null,
        $handoff_repository
    );

    $response = $routes->handle_event(new WP_REST_Request([], [
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-handoff-disabled-ua-ch',
        'event_type' => 'sms_handoff_attempted',
        'handoff_id' => 'hof_disabled_ua_ch',
        'href_scheme' => 'sms',
        'sms_recipient' => '84072',
        'sms_body_present' => 1,
        'sms_body_has_transaction' => 1,
        'ua_ch_supported' => 1,
        'ua_ch_mobile' => 1,
        'ua_ch_platform' => 'Android',
        'ua_ch_platform_version' => '16.0.0',
        'ua_ch_model' => 'SM-S921B',
        'ua_ch_brands' => 'Chromium 147, Google Chrome 147',
        'ua_ch_full_version_list' => 'Google Chrome 147.0.7727.138',
    ]));

    kiwi_assert_true(($response->data['handoff_recorded'] ?? false), 'Expected handoff event to record when UA Client Hints are disabled.');
    kiwi_assert_same(1, count($handoff_repository->rows), 'Expected one handoff row to be stored.');
    kiwi_assert_same(0, (int) ($handoff_repository->rows[1]['ua_ch_supported'] ?? 1), 'Expected server-side disabled switch to clear UA Client Hints support.');
    kiwi_assert_same(0, (int) ($handoff_repository->rows[1]['ua_ch_mobile'] ?? 1), 'Expected server-side disabled switch to clear UA Client Hints mobile flag.');
    kiwi_assert_same('', (string) ($handoff_repository->rows[1]['ua_ch_platform'] ?? 'unexpected'), 'Expected server-side disabled switch to clear UA Client Hints platform.');
    kiwi_assert_same('', (string) ($handoff_repository->rows[1]['ua_ch_model'] ?? 'unexpected'), 'Expected server-side disabled switch to clear UA Client Hints model.');
    kiwi_assert_same([], $handoff_repository->rows[1]['raw_context']['ua_client_hints'] ?? ['unexpected'], 'Expected raw UA Client Hints context to stay empty when disabled.');
});

kiwi_run_test('Kiwi_Landing_Kpi_Rest_Routes updates SMS body variant metrics alongside KPI events', function (): void {
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
    $handoff_repository = new Kiwi_Test_Landing_Handoff_Event_Repository();
    $variant_repository = new Kiwi_Test_Sms_Body_Variant_Repository();
    $variant_repository->insert_if_new([
        'landing_key' => 'lp2-fr',
        'service_key' => 'nth_fr_one_off_jplay',
        'provider_key' => 'nth',
        'flow_key' => 'nth-fr-one-off',
        'country' => 'FR',
        'keyword' => 'JPLAY',
        'shortcode' => '84072',
        'session_token' => 'sess-variant-rest',
        'transaction_id' => 'txn_variant_rest_123',
        'visible_token' => 'variant_rest_123',
        'variant_key' => 'bare_id',
        'sms_body' => 'JPLAY variant_rest_123',
    ]);
    $service = new Kiwi_Landing_Kpi_Service($config, $summary_repository);
    $routes = new Kiwi_Landing_Kpi_Rest_Routes(
        $config,
        $service,
        null,
        null,
        $handoff_repository,
        $variant_repository
    );

    $cta_a = $routes->handle_event(new WP_REST_Request([], [
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-variant-rest',
        'step' => 'cta1',
    ]));
    $cta_b = $routes->handle_event(new WP_REST_Request([], [
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-variant-rest',
        'step' => 'cta1',
    ]));
    $handoff = $routes->handle_event(new WP_REST_Request([], [
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-variant-rest',
        'event_type' => 'sms_handoff_hidden',
        'handoff_id' => 'hof_variant_rest',
        'href_scheme' => 'sms',
        'sms_recipient' => '84072',
        'sms_body_present' => 1,
        'sms_body_has_transaction' => 0,
        'elapsed_ms' => 150,
        'visibility_state' => 'hidden',
    ]));
    $variant_summary = $variant_repository->get_summary_rows()[0] ?? [];

    kiwi_assert_true(($cta_a->data['sms_body_variant_recorded'] ?? false), 'Expected first CTA1 event to mark variant CTA1.');
    kiwi_assert_true(($cta_b->data['incremented'] ?? false), 'Expected global KPI CTA1 counter to keep incrementing on repeated CTA clicks.');
    kiwi_assert_true(($handoff->data['sms_body_variant_recorded'] ?? false), 'Expected handoff event to mark variant handoff metrics.');
    kiwi_assert_same(2, (int) ($summary_repository->rows['lp2-fr']['cta1'] ?? 0), 'Expected global KPI CTA1 counter to remain unchanged in behavior.');
    kiwi_assert_same(1, (int) ($variant_summary['cta1'] ?? 0), 'Expected variant CTA1 metric to be idempotent per assignment.');
    kiwi_assert_same(1, (int) ($variant_summary['handoff_hidden'] ?? 0), 'Expected variant handoff hidden metric to increment.');
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
            'cta4' => '.future-confirm',
        ],
    ]);

    kiwi_assert_same('.cta', $steps['cta1'] ?? '', 'Expected class=\"cta\" shorthand to normalize into .cta selector.');
    kiwi_assert_same('.mobile_number_input', $steps['cta2'] ?? '', 'Expected direct CSS selectors to remain unchanged.');
    kiwi_assert_true(!isset($steps['cta4']), 'Expected v1 KPI selector mapping to ignore unsupported CTA steps beyond CTA3.');
});

kiwi_run_test('Kiwi_Landing_Page_Router injects same-origin SMS handoff telemetry', function (): void {
    $router = new Kiwi_Landing_Page_Router(
        new Kiwi_Test_Config(),
        new Kiwi_Landing_Page_Session_Repository(),
        'https://example.test/plugin/'
    );
    $endpoint_method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'resolve_kpi_event_endpoint');
    $inject_method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'inject_kpi_tracker_script');

    $endpoint = $endpoint_method->invoke($router);
    $html = '<!doctype html><html><body><a class="cta" href="sms:84072?body=JPLAY%20txn_demo_12345678">CTA</a></body></html>';
    $output = $inject_method->invoke($router, $html, [
        'kpi_cta_steps' => [
            'cta1' => 'class="cta"',
        ],
    ], 'lp2-fr', 'sess-router-1');

    kiwi_assert_same('/wp-json/kiwi-backend/v1/landing-kpi/event', $endpoint, 'Expected injected telemetry endpoint to stay same-origin even when rest_url returns an absolute backend URL.');
    kiwi_assert_true(strpos($output, 'backend.example.test') === false, 'Expected injected telemetry config not to expose an absolute backend host.');
    kiwi_assert_contains('sms_handoff_attempted', $output, 'Expected injected tracker to include attempted SMS handoff event.');
    kiwi_assert_contains('sms_handoff_hidden', $output, 'Expected injected tracker to include hidden SMS handoff event.');
    kiwi_assert_contains('sms_handoff_returned', $output, 'Expected injected tracker to include returned SMS handoff event.');
    kiwi_assert_contains('sms_handoff_no_hide', $output, 'Expected injected tracker to include no-hide SMS handoff event.');
    kiwi_assert_contains('uaTrackingMode', $output, 'Expected injected tracker config to expose the generic UA tracking mode.');
    kiwi_assert_contains('uaClientHintsEnabled', $output, 'Expected injected tracker config to expose the UA Client Hints switch.');
    kiwi_assert_contains("eventType==='page_loaded'", $output, 'Expected injected tracker to decide whether page_loaded can carry UA context.');
    kiwi_assert_contains('navigator.userAgentData', $output, 'Expected injected tracker to detect UA Client Hints support.');
    kiwi_assert_contains('getHighEntropyValues', $output, 'Expected injected tracker to request high-entropy UA Client Hints.');
    kiwi_assert_contains('ua_ch_model', $output, 'Expected injected tracker to send UA Client Hints model when available.');
    kiwi_assert_contains("addEventListener('pointerdown'", $output, 'Expected injected tracker to prewarm UA Client Hints on pointer interaction.');
    kiwi_assert_contains("scheme!=='sms'&&scheme!=='smsto'", $output, 'Expected injected tracker to recognize both sms and smsto schemes.');
    kiwi_assert_contains('fetch(cfg.endpoint', $output, 'Expected injected tracker to prefer fetch delivery.');
    kiwi_assert_contains('sendBeacon', $output, 'Expected injected tracker to retain sendBeacon only as a fallback path.');
    kiwi_assert_contains('sendStep(step,selector)', $output, 'Expected injected tracker to keep CTA step binding behavior.');
    kiwi_assert_contains('payload.cta_step=ctaStep', $output, 'Expected CTA engagement telemetry to send cta_step separately from KPI step events.');
    kiwi_assert_contains("sendEngagement('cta_click',step+':'+selector,'',step)", $output, 'Expected CTA clicks to send cta_step on the engagement request.');
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

kiwi_run_test('Kiwi_Landing_Primary_Cta_Resolver can render assigned SMS body variants', function (): void {
    $config = new Kiwi_Test_Config();
    $repository = new Kiwi_Test_Sms_Body_Variant_Repository();
    $repository->insert_if_new([
        'landing_key' => 'lp2-fr',
        'service_key' => 'nth_fr_one_off_jplay',
        'provider_key' => 'nth',
        'flow_key' => 'nth-fr-one-off',
        'country' => 'FR',
        'keyword' => 'JPLAY',
        'shortcode' => '84072',
        'session_token' => 'sess-cta-variant',
        'transaction_id' => 'txn_cta_variant_12345678',
        'visible_token' => 'Arcadecta_variant_12345678',
        'variant_key' => 'game_word',
        'seed' => 'Arcade',
        'sms_body' => 'JPLAY Arcadecta_variant_12345678',
    ]);
    $resolver = new Kiwi_Landing_Primary_Cta_Resolver([
        new Kiwi_Nth_Primary_Cta_Adapter(new Kiwi_Sms_Body_Variant_Service($config, $repository)),
    ]);

    $href = $resolver->resolve(
        [
            'key' => 'lp2-fr',
            'country' => 'FR',
            'provider' => 'nth',
            'flow' => 'nth-fr-one-off',
            'service_key' => 'nth_fr_one_off_jplay',
            'shortcode' => '84072',
            'keyword' => 'JPLAY*',
        ],
        [
            'country' => 'FR',
            'provider' => 'nth',
            'flow' => 'one-off',
            'service_key' => 'nth_fr_one_off_jplay',
        ],
        [
            'transaction_id' => 'txn_cta_variant_12345678',
            'session_ref' => 'sess-cta-variant',
        ]
    );

    kiwi_assert_same(
        'sms:84072?body=JPLAY%20Arcadecta_variant_12345678',
        $href,
        'Expected NTH CTA resolution to use the assigned visible SMS token without changing the sms: scheme.'
    );
});

kiwi_run_test('Kiwi_Landing_Page_Router derives config-driven CTA and price content for rendering', function (): void {
    $landing_page = [
        'page_title' => 'Joyplay',
        'asset_base_url' => 'https://assets.example.test/joyplay',
        'background_image_path' => 'background.png',
        'hero_image_path' => 'hero.png',
        'cta_label' => 'CONTINUER ET PAYER',
        'terms_url' => 'https://example.test/terms',
        'terms_label' => 'TERMES ET CONDITIONS',
        'short_description' => 'Short copy',
        'long_description' => 'Long copy',
        'disclaimer_html' => 'Disclaimer',
    ];
    $service = [
        'keyword' => 'JPLAY',
        'shortcode' => '84072',
        'landing_price_label' => '4,50 EUR / SMS + prix d\'un SMS',
    ];
    $router = new Kiwi_Landing_Page_Router(
        new Kiwi_Test_Config(),
        new Kiwi_Landing_Page_Session_Repository(),
        'https://example.test/plugin/'
    );
    $method = new ReflectionMethod(Kiwi_Landing_Page_Router::class, 'build_render_landing_page');
    $render_landing_page = $method->invoke($router, $landing_page, $service);

    kiwi_assert_same('sms:84072?body=JPLAY', $render_landing_page['cta_href'] ?? '', 'Expected render data to derive the click-to-SMS CTA href from service config.');
    kiwi_assert_same('JPLAY', $render_landing_page['keyword'] ?? '', 'Expected render data to inherit the keyword from service config.');
    kiwi_assert_same('84072', $render_landing_page['shortcode'] ?? '', 'Expected render data to inherit the shortcode from service config.');
    kiwi_assert_contains('Activer en envoyant JPLAY au 84072', $render_landing_page['price_info'] ?? '', 'Expected render data to derive FR click-to-SMS price text from config.');
    kiwi_assert_contains('4,50 EUR / SMS + prix d\'un SMS', $render_landing_page['price_info'] ?? '', 'Expected render data to preserve the configured price label.');
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

kiwi_run_test('Kiwi_Premium_Sms_Landing_Engagement_Soft_Flag_Service evaluates landing engagement UI rules', function (): void {
    $default_service = new Kiwi_Premium_Sms_Landing_Engagement_Soft_Flag_Service(new Kiwi_Test_Config());
    $strict_service = new Kiwi_Premium_Sms_Landing_Engagement_Soft_Flag_Service(
        new Kiwi_Test_Config(100, 0, 0, [], [], [], [], 180, 3, 6, 'observe', true, true, 3)
    );

    $missing_load = $default_service->evaluate([
        'page_loaded_at' => '',
        'first_cta_click_at' => '2026-04-01 12:00:00',
        'last_cta_click_at' => '2026-04-01 12:00:00',
        'cta_click_count' => 1,
    ]);
    $click_before_load = $default_service->evaluate([
        'page_loaded_at' => '2026-04-01 12:00:02',
        'first_cta_click_at' => '2026-04-01 12:00:01',
        'cta_click_count' => 1,
    ]);
    $fast_click = $strict_service->evaluate([
        'page_loaded_at' => '2026-04-01 12:00:00',
        'first_cta_click_at' => '2026-04-01 12:00:02',
        'cta_click_count' => 1,
    ]);
    $normal = $strict_service->evaluate([
        'page_loaded_at' => '2026-04-01 12:00:00',
        'first_cta_click_at' => '2026-04-01 12:00:03',
        'cta_click_count' => 1,
    ]);

    kiwi_assert_true(!empty($missing_load['is_soft_flag']), 'Expected click signal without page load to be soft-flagged.');
    kiwi_assert_same('missing_load', (string) ($missing_load['soft_flag_reason'] ?? ''), 'Expected missing load reason to match UI contract.');
    kiwi_assert_same('click_before_load', (string) ($click_before_load['soft_flag_reason'] ?? ''), 'Expected click before load reason to match UI contract.');
    kiwi_assert_same('fast_click', (string) ($fast_click['soft_flag_reason'] ?? ''), 'Expected configurable fast click reason to match UI contract.');
    kiwi_assert_true(empty($normal['is_soft_flag']), 'Expected click at the configured minimum seconds to stay unflagged.');
    kiwi_assert_same(Kiwi_Premium_Sms_Landing_Engagement_Soft_Flag_Service::RULE_KEY, (string) ($normal['soft_flag_rule_key'] ?? ''), 'Expected evaluation to expose the persisted rule key.');
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
        'cta_step' => 'cta1',
    ], 'cta_click', '2026-04-01 12:00:07');
    $second_click = $repository->upsert_event([
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-1',
        'cta_step' => 'cta2',
    ], 'cta_click', '2026-04-01 12:00:09');

    kiwi_assert_same(1, count($repository->rows), 'Expected one engagement row per landing/session pair.');
    kiwi_assert_same('2026-04-01 12:00:01', (string) ($first_load['page_loaded_at'] ?? ''), 'Expected first page_loaded timestamp to be persisted.');
    kiwi_assert_same('2026-04-01 12:00:01', (string) ($second_load['page_loaded_at'] ?? ''), 'Expected repeated page_loaded events not to overwrite initial page_loaded_at.');
    kiwi_assert_same('2026-04-01 12:00:07', (string) ($first_click['first_cta_click_at'] ?? ''), 'Expected first cta click timestamp to be recorded once.');
    kiwi_assert_same('2026-04-01 12:00:09', (string) ($second_click['last_cta_click_at'] ?? ''), 'Expected last cta click timestamp to advance with later clicks.');
    kiwi_assert_same(2, (int) ($second_click['cta_click_count'] ?? 0), 'Expected cta click count to increment on each click event.');
    kiwi_assert_same('2026-04-01 12:00:07', (string) ($first_click['first_cta1_click_at'] ?? ''), 'Expected CTA1 first-click timestamp to be recorded when cta_step=cta1.');
    kiwi_assert_same(1, (int) ($second_click['cta1_click_count'] ?? 0), 'Expected CTA1 step count to remain isolated from CTA2 clicks.');
    kiwi_assert_same('2026-04-01 12:00:09', (string) ($second_click['first_cta2_click_at'] ?? ''), 'Expected CTA2 first-click timestamp to be recorded when cta_step=cta2.');
    kiwi_assert_same(1, (int) ($second_click['cta2_click_count'] ?? 0), 'Expected CTA2 step count to increment independently.');
    kiwi_assert_same(0, (int) ($second_click['cta3_click_count'] ?? 0), 'Expected untouched CTA3 step count to remain zero.');
});

kiwi_run_test('Kiwi_Premium_Sms_Landing_Engagement_Repository ignores invalid cta_step for step-specific counts', function (): void {
    $repository = new Kiwi_Test_Premium_Sms_Landing_Engagement_Repository();

    $row = $repository->upsert_event([
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-invalid-step',
        'cta_step' => 'cta4',
    ], 'cta_click', '2026-04-01 12:00:07');

    kiwi_assert_same(1, (int) ($row['cta_click_count'] ?? 0), 'Expected invalid cta_step to preserve legacy CTA click count behavior.');
    kiwi_assert_same(0, (int) ($row['cta1_click_count'] ?? 0), 'Expected invalid cta_step not to increment CTA1 count.');
    kiwi_assert_same(0, (int) ($row['cta2_click_count'] ?? 0), 'Expected invalid cta_step not to increment CTA2 count.');
    kiwi_assert_same(0, (int) ($row['cta3_click_count'] ?? 0), 'Expected invalid cta_step not to increment CTA3 count.');
});

kiwi_run_test('Kiwi_Premium_Sms_Landing_Engagement_Repository persists landing engagement soft-flag snapshots', function (): void {
    $repository = new Kiwi_Test_Premium_Sms_Landing_Engagement_Repository();

    $page_load = $repository->upsert_event([
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-fast-click',
    ], 'page_loaded', '2026-04-01 12:00:00');
    $fast_click = $repository->upsert_event([
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-fast-click',
    ], 'cta_click', '2026-04-01 12:00:00');
    $missing_load = $repository->upsert_event([
        'landing_key' => 'lp2-fr',
        'session_token' => 'sess-missing-load',
    ], 'cta_click', '2026-04-01 12:00:02');

    kiwi_assert_same(0, (int) ($page_load['is_soft_flag'] ?? 0), 'Expected page-load-only row to start unflagged.');
    kiwi_assert_same('', (string) ($page_load['soft_flag_reason'] ?? ''), 'Expected unflagged page-load-only row to keep empty reason.');
    kiwi_assert_same(1, (int) ($fast_click['is_soft_flag'] ?? 0), 'Expected fast CTA update to persist soft flag.');
    kiwi_assert_same('fast_click', (string) ($fast_click['soft_flag_reason'] ?? ''), 'Expected fast CTA update to persist reason.');
    kiwi_assert_same(Kiwi_Premium_Sms_Landing_Engagement_Soft_Flag_Service::RULE_KEY, (string) ($fast_click['soft_flag_rule_key'] ?? ''), 'Expected persisted row to store the rule key.');
    kiwi_assert_same('2026-04-01 12:00:00', (string) ($fast_click['soft_flag_evaluated_at'] ?? ''), 'Expected persisted row to store evaluation timestamp.');
    kiwi_assert_same(1, (int) ($missing_load['is_soft_flag'] ?? 0), 'Expected CTA without page load to persist soft flag.');
    kiwi_assert_same('missing_load', (string) ($missing_load['soft_flag_reason'] ?? ''), 'Expected CTA without page load to persist reason.');
});

kiwi_run_test('Kiwi_Premium_Sms_Landing_Engagement_Repository filters flagged engagement rows before applying limit', function (): void {
    $repository = new Kiwi_Test_Premium_Sms_Landing_Engagement_Repository();

    $repository->upsert_event([
        'landing_key' => 'lp5-fr',
        'session_token' => 'older-flagged-fast-click',
        'service_key' => 'nth_fr_one_off_jplay',
        'provider_key' => 'nth',
        'flow_key' => 'nth-fr-one-off',
    ], 'page_loaded', '2026-04-01 11:59:00');
    $repository->upsert_event([
        'landing_key' => 'lp5-fr',
        'session_token' => 'older-flagged-fast-click',
        'service_key' => 'nth_fr_one_off_jplay',
        'provider_key' => 'nth',
        'flow_key' => 'nth-fr-one-off',
    ], 'cta_click', '2026-04-01 11:59:00');

    for ($index = 1; $index <= 520; $index++) {
        $session_token = 'newer-unflagged-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT);
        $repository->upsert_event([
            'landing_key' => 'lp5-fr',
            'session_token' => $session_token,
            'service_key' => 'nth_fr_one_off_jplay',
            'provider_key' => 'nth',
            'flow_key' => 'nth-fr-one-off',
        ], 'page_loaded', '2026-04-01 12:00:00');
        $repository->upsert_event([
            'landing_key' => 'lp5-fr',
            'session_token' => $session_token,
            'service_key' => 'nth_fr_one_off_jplay',
            'provider_key' => 'nth',
            'flow_key' => 'nth-fr-one-off',
        ], 'cta_click', '2026-04-01 12:00:03');
    }

    $rows = $repository->get_recent(['flagged_only' => true], 100);

    kiwi_assert_same(1, count($rows), 'Expected flagged_only query to ignore newer unflagged rows before applying the limit.');
    kiwi_assert_same('older-flagged-fast-click', (string) ($rows[0]['session_token'] ?? ''), 'Expected older flagged row to remain visible beyond the raw candidate window.');
    kiwi_assert_same('fast_click', (string) ($rows[0]['soft_flag_reason'] ?? ''), 'Expected persisted flagged reason to be returned from storage.');
});

kiwi_run_test('Kiwi_Premium_Sms_Landing_Engagement_Repository SQL filters flagged rows before limit', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new class {
        public $prefix = 'abc_';
        public $prepared = [];

        public function prepare($query, ...$args)
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }

            $this->prepared = [
                'query' => (string) $query,
                'args' => $args,
            ];

            return $this->prepared;
        }

        public function get_results($statement, $output)
        {
            return [];
        }
    };

    $repository = new Kiwi_Premium_Sms_Landing_Engagement_Repository();
    $repository->get_recent([
        'service_key' => 'nth_fr_one_off_jplay',
        'flagged_only' => true,
    ], 100);
    $sql = (string) ($wpdb->prepared['query'] ?? '');

    kiwi_assert_contains('WHERE 1 = 1 AND service_key = %s AND is_soft_flag = 1', $sql, 'Expected flagged_only to be part of the SQL WHERE clause.');
    kiwi_assert_true(strpos($sql, 'is_soft_flag = 1') < strpos($sql, 'ORDER BY updated_at DESC'), 'Expected flagged filter to be applied before ordering and limit.');
    kiwi_assert_same(['nth_fr_one_off_jplay', 100], $wpdb->prepared['args'] ?? [], 'Expected flagged_only not to add SQL bind parameters.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Premium_Sms_Landing_Engagement_Repository schema includes CTA step columns', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $previous_queries = $GLOBALS['kiwi_test_dbdelta_queries'];
    $GLOBALS['kiwi_test_dbdelta_queries'] = [];
    $wpdb = new class {
        public $prefix = 'abc_';

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARSET=utf8mb4';
        }
    };

    $repository = new Kiwi_Premium_Sms_Landing_Engagement_Repository();
    $repository->create_table();
    $sql = implode("\n", $GLOBALS['kiwi_test_dbdelta_queries']);

    foreach (['cta1', 'cta2', 'cta3'] as $step) {
        kiwi_assert_contains('first_' . $step . '_click_at DATETIME NULL', $sql, 'Expected schema to include first timestamp for ' . $step . '.');
        kiwi_assert_contains('last_' . $step . '_click_at DATETIME NULL', $sql, 'Expected schema to include last timestamp for ' . $step . '.');
        kiwi_assert_contains($step . '_click_count INT UNSIGNED NOT NULL DEFAULT 0', $sql, 'Expected schema to include click count for ' . $step . '.');
    }
    kiwi_assert_contains('is_soft_flag TINYINT(1) NOT NULL DEFAULT 0', $sql, 'Expected schema to persist engagement soft-flag state.');
    kiwi_assert_contains("soft_flag_reason VARCHAR(191) NOT NULL DEFAULT ''", $sql, 'Expected schema to persist engagement soft-flag reason.');
    kiwi_assert_contains("soft_flag_rule_key VARCHAR(100) NOT NULL DEFAULT ''", $sql, 'Expected schema to persist engagement soft-flag rule key.');
    kiwi_assert_contains('soft_flag_evaluated_at DATETIME NULL', $sql, 'Expected schema to persist engagement soft-flag evaluation time.');
    kiwi_assert_contains('KEY is_soft_flag_updated (is_soft_flag, updated_at, id)', $sql, 'Expected schema to index flagged engagement lookups.');

    $GLOBALS['kiwi_test_dbdelta_queries'] = $previous_queries;
    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Signal_Repository schema includes billing outcome columns', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $previous_queries = $GLOBALS['kiwi_test_dbdelta_queries'];
    $GLOBALS['kiwi_test_dbdelta_queries'] = [];
    $wpdb = new class {
        public $prefix = 'abc_';

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARSET=utf8mb4';
        }
    };

    (new Kiwi_Premium_Sms_Fraud_Signal_Repository())->create_table();
    $sql = implode("\n", $GLOBALS['kiwi_test_dbdelta_queries']);

    foreach ([
        "billing_outcome VARCHAR(50) NOT NULL DEFAULT 'mo_received'",
        'billing_outcome_at DATETIME NULL',
        'billing_transaction_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'sale_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'sale_completed_at DATETIME NULL',
        "aggregator_status_code VARCHAR(50) NOT NULL DEFAULT ''",
        "aggregator_status_text VARCHAR(191) NOT NULL DEFAULT ''",
        'KEY billing_outcome (billing_outcome)',
        'KEY billing_transaction_id (billing_transaction_id)',
        'KEY sale_id (sale_id)',
    ] as $schema_fragment) {
        kiwi_assert_contains($schema_fragment, $sql, 'Expected fraud signal schema to include: ' . $schema_fragment);
    }

    $GLOBALS['kiwi_test_dbdelta_queries'] = $previous_queries;
    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Landing_Page_Session_Repository creates canonical dimension schema', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $previous_queries = $GLOBALS['kiwi_test_dbdelta_queries'];
    $GLOBALS['kiwi_test_dbdelta_queries'] = [];
    $wpdb = new class {
        public $prefix = 'abc_';

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARSET=utf8mb4';
        }
    };

    (new Kiwi_Landing_Page_Session_Repository())->create_table();
    $sql = implode("\n", $GLOBALS['kiwi_test_dbdelta_queries']);

    foreach ([
        'provider_key VARCHAR(50) NOT NULL DEFAULT \'\'',
        'flow_key VARCHAR(50) NOT NULL DEFAULT \'\'',
        'country VARCHAR(10) NOT NULL DEFAULT \'\'',
        'pid VARCHAR(191) NOT NULL DEFAULT \'\'',
        'tksource VARCHAR(191) NOT NULL DEFAULT \'\'',
        'tkzone VARCHAR(191) NOT NULL DEFAULT \'\'',
        "browser_language VARCHAR(20) NOT NULL DEFAULT '(unknown)'",
        "device_brand VARCHAR(100) NOT NULL DEFAULT '(unknown)'",
        "os VARCHAR(50) NOT NULL DEFAULT '(unknown)'",
        "os_version VARCHAR(50) NOT NULL DEFAULT '(unknown)'",
        "browser VARCHAR(100) NOT NULL DEFAULT '(unknown)'",
        "client_ip_version VARCHAR(10) NOT NULL DEFAULT '(unknown)'",
        "client_ip_prefix VARCHAR(120) NOT NULL DEFAULT '(unknown)'",
    ] as $column) {
        kiwi_assert_contains($column, $sql, 'Expected canonical landing-session dimension column: ' . $column);
    }

    foreach ([
        'KEY provider_key (provider_key)',
        'KEY flow_key (flow_key)',
        'KEY country (country)',
        'KEY pid (pid)',
        'KEY tksource (tksource)',
        'KEY tkzone (tkzone)',
        'KEY browser_language (browser_language)',
        'KEY device_brand (device_brand)',
        'KEY os (os)',
        'KEY os_version (os_version)',
        'KEY browser (browser)',
        'KEY client_ip_version (client_ip_version)',
        'KEY client_ip_prefix (client_ip_prefix)',
    ] as $index) {
        kiwi_assert_contains($index, $sql, 'Expected canonical landing-session dimension index: ' . $index);
    }

    $GLOBALS['kiwi_test_dbdelta_queries'] = $previous_queries;
    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Device_Model_Brand_Map_Repository creates exact model map schema', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $previous_queries = $GLOBALS['kiwi_test_dbdelta_queries'];
    $GLOBALS['kiwi_test_dbdelta_queries'] = [];
    $wpdb = new class {
        public $prefix = 'abc_';

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARSET=utf8mb4';
        }
    };

    (new Kiwi_Device_Model_Brand_Map_Repository())->create_table();
    $sql = implode("\n", $GLOBALS['kiwi_test_dbdelta_queries']);

    kiwi_assert_contains('CREATE TABLE abc_kiwi_device_model_brand_map', $sql, 'Expected model brand map schema to use the configured prefix.');
    kiwi_assert_contains('model_key VARCHAR(191) NOT NULL DEFAULT \'\'', $sql, 'Expected exact model map schema to store normalized model keys.');
    kiwi_assert_contains('brand VARCHAR(100) NOT NULL DEFAULT \'\'', $sql, 'Expected exact model map schema to store normalized brands.');
    kiwi_assert_contains('source VARCHAR(100) NOT NULL DEFAULT \'\'', $sql, 'Expected exact model map schema to keep source metadata.');
    kiwi_assert_contains('UNIQUE KEY model_key (model_key)', $sql, 'Expected model keys to be unique for exact lookups.');
    kiwi_assert_contains('KEY brand (brand)', $sql, 'Expected model map schema to index brand values.');

    $GLOBALS['kiwi_test_dbdelta_queries'] = $previous_queries;
    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Device_Model_Brand_Map_Repository caches lookups and lets unknown rows fall through', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new class {
        public $prefix = 'abc_';
        public $get_row_calls = 0;
        public $rows = [
            'CPH2609' => ['brand' => '(unknown)'],
            'LE2113' => ['brand' => 'OnePlus'],
        ];

        public function prepare($query, ...$args)
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }

            return [
                'query' => (string) $query,
                'args' => $args,
            ];
        }

        public function get_row($statement, $output = null)
        {
            $this->get_row_calls++;
            $args = is_array($statement) ? (array) ($statement['args'] ?? []) : [];
            $model_key = (string) ($args[0] ?? '');

            return $this->rows[$model_key] ?? null;
        }
    };

    $repository = new Kiwi_Device_Model_Brand_Map_Repository();

    kiwi_assert_same('', $repository->find_brand_for_model('CPH2609'), 'Expected observed unknown mappings not to block normalizer heuristics.');
    kiwi_assert_same('', $repository->find_brand_for_model('CPH2609'), 'Expected cached observed unknown mappings to remain fall-through values.');
    kiwi_assert_same('OnePlus', $repository->find_brand_for_model('LE2113'), 'Expected known exact mappings to be returned.');
    kiwi_assert_same('OnePlus', $repository->find_brand_for_model('LE2113'), 'Expected known exact mappings to be cached.');
    kiwi_assert_same(2, $wpdb->get_row_calls, 'Expected each model key to hit the database only once per repository instance.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Device_Model_Brand_Map_Repository seeds defaults without overwriting known manual mappings', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new class {
        public $prefix = 'abc_';
        public $rows = [
            'M2102K1G' => ['brand' => '(unknown)', 'source' => 'observed'],
            'LE2113' => ['brand' => 'ManualBrand', 'source' => 'manual'],
        ];

        public function prepare($query, ...$args)
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }

            return [
                'query' => (string) $query,
                'args' => $args,
            ];
        }

        public function get_row($statement, $output = null)
        {
            $args = is_array($statement) ? (array) ($statement['args'] ?? []) : [];
            $model_key = (string) ($args[0] ?? '');

            return $this->rows[$model_key] ?? null;
        }

        public function query($statement)
        {
            $query = is_array($statement) ? (string) ($statement['query'] ?? '') : (string) $statement;
            $args = is_array($statement) ? (array) ($statement['args'] ?? []) : [];

            if (stripos($query, 'INSERT INTO') === 0) {
                $this->rows[(string) ($args[0] ?? '')] = [
                    'brand' => (string) ($args[1] ?? ''),
                    'source' => (string) ($args[2] ?? ''),
                ];

                return 1;
            }

            if (stripos($query, 'UPDATE') === 0) {
                $this->rows[(string) ($args[3] ?? '')] = [
                    'brand' => (string) ($args[0] ?? ''),
                    'source' => (string) ($args[1] ?? ''),
                ];

                return 1;
            }

            return 0;
        }
    };

    $repository = new Kiwi_Device_Model_Brand_Map_Repository();
    $seeded = $repository->seed_default_mappings();

    kiwi_assert_true($seeded > 0, 'Expected default model-brand seeds to insert or promote rows.');
    kiwi_assert_same('Xiaomi', $wpdb->rows['M2102K1G']['brand'] ?? '', 'Expected seed mappings to promote observed unknown rows.');
    kiwi_assert_same('ManualBrand', $wpdb->rows['LE2113']['brand'] ?? '', 'Expected seed mappings not to overwrite known manual rows.');
    kiwi_assert_same('Honor', $wpdb->rows['NTH-NX9']['brand'] ?? '', 'Expected missing seed mappings to be inserted.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Landing_Page_Session_Repository persists canonical dimension values', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new class {
        public $prefix = 'abc_';
        public $inserted_table = '';
        public $inserted_data = [];
        public $inserted_formats = [];

        public function insert(string $table, array $data, array $formats = [])
        {
            $this->inserted_table = $table;
            $this->inserted_data = $data;
            $this->inserted_formats = $formats;

            return 1;
        }
    };

    $repository = new Kiwi_Landing_Page_Session_Repository();
    $inserted = $repository->insert([
        'landing_key' => 'lp2-fr',
        'service_key' => 'nth_fr_one_off_jplay',
        'provider_key' => 'nth',
        'flow_key' => 'nth-fr-one-off',
        'country' => 'FR',
        'pid' => 'partner123',
        'tksource' => 'src-a',
        'tkzone' => 'zone-b',
        'browser_language' => 'fr',
        'device_brand' => 'Samsung',
        'os' => 'Android',
        'os_version' => '14',
        'browser' => 'Chrome',
        'remote_ip' => '203.0.113.44',
        'client_ip_version' => 'ipv4',
        'client_ip_prefix' => '203.0.113.0/24',
        'session_token' => 'sess-canonical',
    ]);

    kiwi_assert_true($inserted, 'Expected landing-session insert to succeed with canonical dimensions.');
    kiwi_assert_same('abc_kiwi_landing_page_sessions', $wpdb->inserted_table, 'Expected insert to target the prefixed landing-session table.');
    kiwi_assert_same('nth', $wpdb->inserted_data['provider_key'] ?? '', 'Expected provider_key to be passed to wpdb insert.');
    kiwi_assert_same('nth-fr-one-off', $wpdb->inserted_data['flow_key'] ?? '', 'Expected flow_key to be passed to wpdb insert.');
    kiwi_assert_same('FR', $wpdb->inserted_data['country'] ?? '', 'Expected country to be passed to wpdb insert.');
    kiwi_assert_same('partner123', $wpdb->inserted_data['pid'] ?? '', 'Expected pid to be passed to wpdb insert.');
    kiwi_assert_same('src-a', $wpdb->inserted_data['tksource'] ?? '', 'Expected tksource to be passed to wpdb insert.');
    kiwi_assert_same('zone-b', $wpdb->inserted_data['tkzone'] ?? '', 'Expected tkzone to be passed to wpdb insert.');
    kiwi_assert_same('fr', $wpdb->inserted_data['browser_language'] ?? '', 'Expected browser_language to be passed to wpdb insert.');
    kiwi_assert_same('Samsung', $wpdb->inserted_data['device_brand'] ?? '', 'Expected device_brand to be passed to wpdb insert.');
    kiwi_assert_same('Android', $wpdb->inserted_data['os'] ?? '', 'Expected os to be passed to wpdb insert.');
    kiwi_assert_same('14', $wpdb->inserted_data['os_version'] ?? '', 'Expected os_version to be passed to wpdb insert.');
    kiwi_assert_same('Chrome', $wpdb->inserted_data['browser'] ?? '', 'Expected browser to be passed to wpdb insert.');
    kiwi_assert_same('203.0.113.44', $wpdb->inserted_data['remote_ip'] ?? '', 'Expected resolved landing client IP to be passed to wpdb insert.');
    kiwi_assert_same('ipv4', $wpdb->inserted_data['client_ip_version'] ?? '', 'Expected client_ip_version to be passed to wpdb insert.');
    kiwi_assert_same('203.0.113.0/24', $wpdb->inserted_data['client_ip_prefix'] ?? '', 'Expected client_ip_prefix to be passed to wpdb insert.');
    kiwi_assert_same(count($wpdb->inserted_data), count($wpdb->inserted_formats), 'Expected insert formats to cover every landing-session column.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Landing funnel source schemas include daily refresh composite indexes', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $previous_queries = $GLOBALS['kiwi_test_dbdelta_queries'];
    $GLOBALS['kiwi_test_dbdelta_queries'] = [];
    $wpdb = new class {
        public $prefix = 'abc_';

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARSET=utf8mb4';
        }
    };

    (new Kiwi_Landing_Page_Session_Repository())->create_table();
    (new Kiwi_Premium_Sms_Landing_Engagement_Repository())->create_table();
    (new Kiwi_Landing_Handoff_Event_Repository())->create_table();
    (new Kiwi_Sales_Repository())->create_table();

    $sql = implode("\n", $GLOBALS['kiwi_test_dbdelta_queries']);

    kiwi_assert_same(
        2,
        substr_count($sql, 'KEY created_landing_session (created_at, landing_key, session_token)'),
        'Expected landing sessions and engagement sessions to support date-bounded landing/session scans.'
    );
    kiwi_assert_contains('KEY created_landing_session_event (created_at, landing_key, session_token, event_type)', $sql, 'Expected handoff events to support date-bounded session/event scans.');
    kiwi_assert_contains('KEY status_attribution_metric_date (status, attribution_metric_date)', $sql, 'Expected sales snapshots to support completed sales lookup by attribution metric date.');
    kiwi_assert_contains('KEY status_completed_at (status, completed_at)', $sql, 'Expected legacy sales fallback to support completed_at date scans.');
    kiwi_assert_contains('KEY completed_subscriber_context (status, service_key, subscriber_reference, shortcode, keyword, completed_at)', $sql, 'Expected sales snapshots to support completed-sale cooldown lookup by subscriber context.');

    $GLOBALS['kiwi_test_dbdelta_queries'] = $previous_queries;
    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Premium_Sms_Mo_Engagement_Evaluator_Service records unknown links without soft-flagging and flags fast MO deltas', function (): void {
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
        'tksource' => 'source-fast',
        'tkzone' => 'zone-fast',
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

    kiwi_assert_true(in_array('unknown_link', $unknown['link_reasons'] ?? [], true), 'Expected missing attribution/engagement linkage to be recorded as unknown_link.');
    kiwi_assert_true(!in_array('unknown_link', $unknown['reasons'] ?? [], true), 'Expected unknown_link not to be treated as a soft-flag reason.');
    kiwi_assert_true(($unknown['has_soft_flag'] ?? false) === false, 'Expected unknown link evaluation not to be soft-flagged.');
    kiwi_assert_true(in_array('mo_too_fast_after_load<1s', $fast['reasons'] ?? [], true), 'Expected sub-1s MO delta to be flagged as suspicious.');
    kiwi_assert_true(($fast['linked'] ?? false) === true, 'Expected evaluator to treat matched attribution + engagement rows as linked.');
    kiwi_assert_same('aff-fast-1', (string) ($fast['click_id'] ?? ''), 'Expected evaluator to expose resolved click_id from linked attribution.');
    kiwi_assert_same('source-fast', (string) ($fast['tksource'] ?? ''), 'Expected evaluator to expose resolved tksource from linked attribution.');
    kiwi_assert_same('zone-fast', (string) ($fast['tkzone'] ?? ''), 'Expected evaluator to expose resolved tkzone from linked attribution.');
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Monitor_Service does not soft-flag unknown engagement links', function (): void {
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

    $result = $service->capture_inbound_mo([
        'provider_key' => 'nth',
        'service_key' => 'svc_unknown_link',
        'flow_key' => 'nth-fr-one-off',
        'country' => 'FR',
        'source_event_key' => 'event-unknown-link-1',
        'occurred_at' => '2026-04-01 12:00:00',
        'subscriber_reference' => 'enc-unknown-link-1',
        'session_ref' => 'sess-unknown-link-1',
        'transaction_id' => 'txn_unknown_link_1',
    ]);

    $first_row = $fraud_repository->rows[0] ?? [];
    $engagement = $result['engagement'] ?? [];

    kiwi_assert_true(($result['has_soft_flag'] ?? false) === false, 'Expected unknown engagement link not to set monitor soft-flag.');
    kiwi_assert_true(($result['should_block'] ?? false) === false, 'Expected block mode not to block unknown engagement link by itself.');
    kiwi_assert_same(0, (int) ($first_row['is_soft_flag'] ?? 0), 'Expected persisted fraud row not to be soft-flagged for unknown link only.');
    kiwi_assert_same('', (string) ($first_row['soft_flag_reason'] ?? ''), 'Expected persisted soft_flag_reason to stay empty for unknown link only.');
    kiwi_assert_true(in_array('unknown_link', $engagement['link_reasons'] ?? [], true), 'Expected unknown link audit reason to remain available in engagement metadata.');
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
        'tksource' => 'source-block',
        'tkzone' => 'zone-block',
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
    kiwi_assert_same('source-block', (string) ($first_row['tksource'] ?? ''), 'Expected fraud signal rows to snapshot tksource from linked attribution context.');
    kiwi_assert_same('zone-block', (string) ($first_row['tkzone'] ?? ''), 'Expected fraud signal rows to snapshot tkzone from linked attribution context.');
    kiwi_assert_contains('mo_too_fast_after_load<1s', (string) ($first_row['soft_flag_reason'] ?? ''), 'Expected persisted soft_flag_reason to include engagement rule trigger.');
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Monitor_Service records subscriber identity and keeps session only as metadata', function (): void {
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
        'identity_type' => 'subscriber',
        'identity_value' => 'enc-123',
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
        'identity_type' => 'subscriber',
        'identity_value' => 'enc-123',
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
        'tksource' => 'source-direct-a',
        'tkzone' => 'zone-direct-a',
        'subscriber_reference' => 'enc-123',
        'session_ref' => 'session-abc',
    ]);

    kiwi_assert_same(1, count($result['signals'] ?? []), 'Expected fraud monitor to persist only subscriber identity rows.');
    kiwi_assert_true(!empty($result['has_soft_flag']), 'Expected subscriber threshold logic to mark the capture result as soft-flagged.');
    kiwi_assert_same(['subscriber'], $result['soft_flagged_identity_types'] ?? [], 'Expected subscriber identity to carry the soft flag in this scenario.');
    kiwi_assert_same(3, count($repository->rows), 'Expected seed rows plus the new subscriber identity row to be persisted.');
    kiwi_assert_same('pid-direct-a', (string) ($repository->rows[2]['pid'] ?? ''), 'Expected fraud signal rows to include pid from inbound signal context.');
    kiwi_assert_same('click-direct-a', (string) ($repository->rows[2]['click_id'] ?? ''), 'Expected fraud signal rows to include click_id from inbound signal context.');
    kiwi_assert_same('source-direct-a', (string) ($repository->rows[2]['tksource'] ?? ''), 'Expected fraud signal rows to include tksource from inbound signal context.');
    kiwi_assert_same('zone-direct-a', (string) ($repository->rows[2]['tkzone'] ?? ''), 'Expected fraud signal rows to include tkzone from inbound signal context.');
    kiwi_assert_contains('session-abc', (string) ($repository->rows[2]['meta_json'] ?? ''), 'Expected session_ref to remain available as non-counting metadata.');
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Monitor_Service skips session-only identities', function (): void {
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

    kiwi_assert_same(0, count($result['signals'] ?? []), 'Expected no fraud identity row when subscriber is empty.');
    kiwi_assert_same(0, count($repository->rows), 'Expected session-only MO context not to write a counting fraud signal.');
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

kiwi_run_test('Kiwi_Nth_Fr_One_Off_Service blocks parallel MT while an earlier billing attempt is pending', function (): void {
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
                'completed_sale_cooldown_days' => 7,
            ],
        ]
    );
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);
    $client = new Kiwi_Test_Nth_Client([
        [
            'success' => true,
            'status_code' => 200,
            'body' => '<response><message_id>msg-pending-1</message_id><status>submitted</status></response>',
            'request' => [],
            'error' => '',
        ],
    ]);
    $event_repository = new Kiwi_Test_Nth_Event_Repository();
    $transaction_repository = new Kiwi_Test_Nth_Flow_Transaction_Repository();
    $sales_repository = new Kiwi_Test_Sales_Repository();
    $fraud_repository = new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository();
    $fraud_monitor = new Kiwi_Premium_Sms_Fraud_Monitor_Service($config, $fraud_repository);
    $service = new Kiwi_Nth_Fr_One_Off_Service(
        $config,
        $normalizer,
        $client,
        $event_repository,
        $transaction_repository,
        new Kiwi_Test_Shared_Sales_Recorder(),
        null,
        $fraud_monitor,
        null,
        new Kiwi_Premium_Sms_Completed_Sale_Cooldown_Service($sales_repository)
    );

    $first = $service->handle_inbound_mo('nth_fr_one_off_jplay', [
        'Encrypted_MSISDN' => 'enc-pending-1',
        'Business_Number' => '84072',
        'Message' => 'JPLAY',
        'NWC' => '20801',
        'Operator' => 'Orange',
        'session_id' => 'session-pending-1',
    ]);
    $second = $service->handle_inbound_mo('nth_fr_one_off_jplay', [
        'Encrypted_MSISDN' => 'enc-pending-1',
        'Business_Number' => '84072',
        'Message' => 'JPLAY',
        'NWC' => '20801',
        'Operator' => 'Orange',
        'session_id' => 'session-pending-2',
    ]);

    kiwi_assert_true($first['success'], 'Expected first MO to submit an MT.');
    kiwi_assert_same('duplicate_pending_ignored', $second['message'] ?? '', 'Expected second distinct MO to be ignored only because the first MT is pending.');
    kiwi_assert_same(1, count($client->calls), 'Expected pending duplicate guard to prevent a parallel MT submit.');
    kiwi_assert_same('mt_submitted', (string) ($transaction_repository->rows[1]['current_status'] ?? ''), 'Expected pending duplicate not to overwrite the visible transaction status.');
    kiwi_assert_same('pending', (string) ($fraud_repository->rows[0]['billing_outcome'] ?? ''), 'Expected first MO fraud row to show pending billing outcome.');
    kiwi_assert_same('duplicate_pending_ignored', (string) ($fraud_repository->rows[1]['billing_outcome'] ?? ''), 'Expected ignored MO fraud row to show duplicate pending outcome.');
    kiwi_assert_same(1, (int) ($fraud_repository->rows[1]['billing_transaction_id'] ?? 0), 'Expected ignored MO fraud row to reference the pending transaction.');
});

kiwi_run_test('Kiwi_Nth_Fr_One_Off_Service allows retry after terminal failed delivery report', function (): void {
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
                    '20810' => '20810',
                ],
                'completed_sale_cooldown_days' => 7,
            ],
        ]
    );
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);
    $client = new Kiwi_Test_Nth_Client([
        [
            'success' => true,
            'status_code' => 200,
            'body' => '<response><message_id>msg-failed-1</message_id><status>submitted</status></response>',
            'request' => [],
            'error' => '',
        ],
        [
            'success' => true,
            'status_code' => 200,
            'body' => '<response><message_id>msg-retry-1</message_id><status>submitted</status></response>',
            'request' => [],
            'error' => '',
        ],
    ]);
    $event_repository = new Kiwi_Test_Nth_Event_Repository();
    $transaction_repository = new Kiwi_Test_Nth_Flow_Transaction_Repository();
    $sales_repository = new Kiwi_Test_Sales_Repository();
    $fraud_repository = new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository();
    $fraud_monitor = new Kiwi_Premium_Sms_Fraud_Monitor_Service($config, $fraud_repository);
    $service = new Kiwi_Nth_Fr_One_Off_Service(
        $config,
        $normalizer,
        $client,
        $event_repository,
        $transaction_repository,
        new Kiwi_Test_Shared_Sales_Recorder(),
        null,
        $fraud_monitor,
        null,
        new Kiwi_Premium_Sms_Completed_Sale_Cooldown_Service($sales_repository)
    );

    $service->handle_inbound_mo('nth_fr_one_off_jplay', [
        'Encrypted_MSISDN' => 'enc-failed-retry-1',
        'Business_Number' => '84072',
        'Message' => 'JPLAY',
        'NWC' => '20810',
        'Operator' => 'SFR',
        'session_id' => 'session-failed-1',
    ]);
    $notification = $service->handle_notification('nth_fr_one_off_jplay', [
        'message_id' => 'msg-failed-1',
        'messageStatus' => '-9',
        'messageStatusText' => 'Delivery failed',
        'encrypted_msisdn' => 'enc-failed-retry-1',
        'businessNumber' => '84072',
        'keyword' => 'JPLAY',
    ]);
    $retry = $service->handle_inbound_mo('nth_fr_one_off_jplay', [
        'Encrypted_MSISDN' => 'enc-failed-retry-1',
        'Business_Number' => '84072',
        'Message' => 'JPLAY',
        'NWC' => '20810',
        'Operator' => 'SFR',
        'session_id' => 'session-failed-2',
    ]);

    kiwi_assert_true(!$notification['success'], 'Expected -9 delivery report to remain a failed terminal notification.');
    kiwi_assert_true($retry['success'], 'Expected a new MO after terminal failed report to submit a new MT.');
    kiwi_assert_same(2, count($client->calls), 'Expected failed terminal report not to keep the old 24h MO block active.');
    kiwi_assert_same('failed', (string) ($fraud_repository->rows[0]['billing_outcome'] ?? ''), 'Expected first MO fraud row to be updated as failed.');
    kiwi_assert_same('-9', (string) ($fraud_repository->rows[0]['aggregator_status_code'] ?? ''), 'Expected failed outcome to preserve the aggregator status code.');
    kiwi_assert_same('Delivery failed', (string) ($fraud_repository->rows[0]['aggregator_status_text'] ?? ''), 'Expected failed outcome to preserve the aggregator status text.');
    kiwi_assert_same('pending', (string) ($fraud_repository->rows[1]['billing_outcome'] ?? ''), 'Expected retry MO fraud row to show a fresh pending billing attempt.');
});

kiwi_run_test('Kiwi_Nth_Fr_One_Off_Service blocks new billing attempt after recent completed sale', function (): void {
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
                'completed_sale_cooldown_days' => 7,
            ],
        ]
    );
    $sales_repository = new Kiwi_Test_Sales_Repository();
    $sale = $sales_repository->upsert([
        'sale_reference' => 'sale-cooldown-1',
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'flow_key' => 'one-off',
        'sale_type' => 'premium_sms_one_off',
        'status' => 'completed',
        'subscriber_reference' => 'enc-sale-cooldown-1',
        'shortcode' => '84072',
        'keyword' => 'JPLAY',
        'completed_at' => '2026-03-31 12:00:00',
    ]);
    $fraud_repository = new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository();
    $service = new Kiwi_Nth_Fr_One_Off_Service(
        $config,
        new Kiwi_Nth_Premium_Sms_Normalizer($config),
        new Kiwi_Test_Nth_Client([]),
        new Kiwi_Test_Nth_Event_Repository(),
        new Kiwi_Test_Nth_Flow_Transaction_Repository(),
        new Kiwi_Test_Shared_Sales_Recorder(),
        null,
        new Kiwi_Premium_Sms_Fraud_Monitor_Service($config, $fraud_repository),
        null,
        new Kiwi_Premium_Sms_Completed_Sale_Cooldown_Service($sales_repository)
    );

    $result = $service->handle_inbound_mo('nth_fr_one_off_jplay', [
        'Encrypted_MSISDN' => 'enc-sale-cooldown-1',
        'Business_Number' => '84072',
        'Message' => 'JPLAY',
        'NWC' => '20801',
        'Operator' => 'Orange',
        'session_id' => 'session-sale-cooldown-1',
    ]);

    kiwi_assert_true($result['success'], 'Expected sale cooldown policy to acknowledge the MO callback.');
    kiwi_assert_same('sale_cooldown_ignored', $result['message'] ?? '', 'Expected completed sale cooldown to be surfaced as the handling result.');
    kiwi_assert_same((int) ($sale['id'] ?? 0), (int) ($result['sale']['id'] ?? 0), 'Expected result to expose the blocking sale.');
    kiwi_assert_same('sale_cooldown_ignored', (string) ($fraud_repository->rows[0]['billing_outcome'] ?? ''), 'Expected fraud row to record sale cooldown outcome.');
    kiwi_assert_same((int) ($sale['id'] ?? 0), (int) ($fraud_repository->rows[0]['sale_id'] ?? 0), 'Expected fraud row to reference the blocking sale id.');
});

kiwi_run_test('Kiwi_Nth_Fr_One_Off_Service allows completed sale retry when cooldown is disabled', function (): void {
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
                'completed_sale_cooldown_days' => 0,
            ],
        ]
    );
    $sales_repository = new Kiwi_Test_Sales_Repository();
    $sales_repository->upsert([
        'sale_reference' => 'sale-cooldown-disabled-1',
        'provider_key' => 'nth',
        'service_key' => 'nth_fr_one_off_jplay',
        'flow_key' => 'one-off',
        'sale_type' => 'premium_sms_one_off',
        'status' => 'completed',
        'subscriber_reference' => 'enc-cooldown-disabled-1',
        'shortcode' => '84072',
        'keyword' => 'JPLAY',
        'completed_at' => '2026-03-31 12:00:00',
    ]);
    $client = new Kiwi_Test_Nth_Client([
        [
            'success' => true,
            'status_code' => 200,
            'body' => '<response><message_id>msg-cooldown-disabled-1</message_id><status>submitted</status></response>',
            'request' => [],
            'error' => '',
        ],
    ]);
    $service = new Kiwi_Nth_Fr_One_Off_Service(
        $config,
        new Kiwi_Nth_Premium_Sms_Normalizer($config),
        $client,
        new Kiwi_Test_Nth_Event_Repository(),
        new Kiwi_Test_Nth_Flow_Transaction_Repository(),
        new Kiwi_Test_Shared_Sales_Recorder(),
        null,
        new Kiwi_Premium_Sms_Fraud_Monitor_Service($config, new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository()),
        null,
        new Kiwi_Premium_Sms_Completed_Sale_Cooldown_Service($sales_repository)
    );

    $result = $service->handle_inbound_mo('nth_fr_one_off_jplay', [
        'Encrypted_MSISDN' => 'enc-cooldown-disabled-1',
        'Business_Number' => '84072',
        'Message' => 'JPLAY',
        'NWC' => '20801',
        'Operator' => 'Orange',
        'session_id' => 'session-cooldown-disabled-1',
    ]);

    kiwi_assert_true($result['success'], 'Expected disabled completed-sale cooldown to allow a fresh billing attempt.');
    kiwi_assert_same(1, count($client->calls), 'Expected MT submit to run when completed_sale_cooldown_days is 0.');
});

kiwi_run_test('Kiwi_Nth_Fr_One_Off_Service updates fraud outcome on successful terminal delivery', function (): void {
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
    $fraud_repository = new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository();
    $service = new Kiwi_Nth_Fr_One_Off_Service(
        $config,
        new Kiwi_Nth_Premium_Sms_Normalizer($config),
        new Kiwi_Test_Nth_Client([
            [
                'success' => true,
                'status_code' => 200,
                'body' => '<response><message_id>msg-delivered-outcome-1</message_id><status>submitted</status></response>',
                'request' => [],
                'error' => '',
            ],
        ]),
        new Kiwi_Test_Nth_Event_Repository(),
        new Kiwi_Test_Nth_Flow_Transaction_Repository(),
        new Kiwi_Test_Shared_Sales_Recorder(),
        null,
        new Kiwi_Premium_Sms_Fraud_Monitor_Service($config, $fraud_repository)
    );

    $service->handle_inbound_mo('nth_fr_one_off_jplay', [
        'Encrypted_MSISDN' => 'enc-delivered-outcome-1',
        'Business_Number' => '84072',
        'Message' => 'JPLAY',
        'NWC' => '20801',
        'Operator' => 'Orange',
        'session_id' => 'session-delivered-outcome-1',
    ]);
    $service->handle_notification('nth_fr_one_off_jplay', [
        'message_id' => 'msg-delivered-outcome-1',
        'messageStatus' => '2',
        'messageStatusText' => 'Delivered',
        'encrypted_msisdn' => 'enc-delivered-outcome-1',
        'businessNumber' => '84072',
        'keyword' => 'JPLAY',
    ]);

    kiwi_assert_same('completed', (string) ($fraud_repository->rows[0]['billing_outcome'] ?? ''), 'Expected terminal success to mark the MO fraud row as completed.');
    kiwi_assert_same(1, (int) ($fraud_repository->rows[0]['sale_id'] ?? 0), 'Expected completed fraud row to carry the shared sale id.');
    kiwi_assert_same('2026-04-01 12:00:00', (string) ($fraud_repository->rows[0]['sale_completed_at'] ?? ''), 'Expected completed fraud row to carry sale completion time.');
    kiwi_assert_same('2', (string) ($fraud_repository->rows[0]['aggregator_status_code'] ?? ''), 'Expected completed fraud row to carry aggregator status code.');
    kiwi_assert_same('Delivered', (string) ($fraud_repository->rows[0]['aggregator_status_text'] ?? ''), 'Expected completed fraud row to carry aggregator status text.');
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
        'engagement_soft_flag_reasons' => ['missing_cta_click'],
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

kiwi_run_test('Kiwi_Nth_Fr_One_Off_Service resolves assigned visible SMS body tokens', function (): void {
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
    $variant_repository = new Kiwi_Test_Sms_Body_Variant_Repository();
    $variant_repository->insert_if_new([
        'landing_key' => 'lp2-fr',
        'service_key' => 'nth_fr_one_off_jplay',
        'provider_key' => 'nth',
        'flow_key' => 'one-off',
        'country' => 'FR',
        'keyword' => 'JPLAY',
        'shortcode' => '84072',
        'session_token' => 'sess-mo-visible-token',
        'transaction_id' => 'txn_visible_token_12345678',
        'visible_token' => 'ArcadeHerovisible_token_12345678',
        'variant_key' => 'game_word',
        'seed' => 'ArcadeHero',
        'sms_body' => 'JPLAY ArcadeHerovisible_token_12345678',
    ]);
    $normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);
    $client = new Kiwi_Test_Nth_Client([
        [
            'success' => true,
            'status_code' => 200,
            'body' => '<response><message_id>msg-visible-token</message_id><status>submitted</status></response>',
            'request' => [],
            'error' => '',
        ],
    ]);
    $service = new Kiwi_Nth_Fr_One_Off_Service(
        $config,
        $normalizer,
        $client,
        new Kiwi_Test_Nth_Event_Repository(),
        new Kiwi_Test_Nth_Flow_Transaction_Repository(),
        new Kiwi_Test_Shared_Sales_Recorder(),
        null,
        null,
        new Kiwi_Sms_Body_Variant_Service($config, $variant_repository)
    );

    $service->handle_inbound_mo('nth_fr_one_off_jplay', [
        'Encrypted_MSISDN' => 'enc-visible-token-1',
        'Business_Number' => '84072',
        'Message' => 'JPLAY+ArcadeHerovisible_token_12345678',
        'keyword' => 'JPLAY',
        'NWC' => '20801',
        'Operator' => 'Orange',
        'session_id' => 'session-visible-token-1',
    ]);

    kiwi_assert_same(1, count($client->calls), 'Expected MO with assigned visible token to continue MT submission flow.');
    kiwi_assert_true(
        strpos((string) ($client->calls[0]['transaction']['flow_reference'] ?? ''), 'txn_visible_token_12345678-') === 0,
        'Expected assigned visible token to resolve back to the internal transaction_id.'
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

kiwi_run_test('Kiwi_Landing_Funnel_Daily_Summary_Repository creates schema-managed prefixed table', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $previous_queries = $GLOBALS['kiwi_test_dbdelta_queries'];
    $GLOBALS['kiwi_test_dbdelta_queries'] = [];
    $wpdb = new Kiwi_Test_Wpdb_Landing_Funnel_Daily_Summary();
    $wpdb->prefix = 'abc_';

    $repository = new Kiwi_Landing_Funnel_Daily_Summary_Repository();
    $repository->create_table();
    $sql = implode("\n", $GLOBALS['kiwi_test_dbdelta_queries']);

    kiwi_assert_contains('CREATE TABLE abc_kiwi_landing_funnel_daily_summary', $sql, 'Expected daily summary schema to use the configured table prefix.');
    foreach ([
        'metric_date DATE NOT NULL',
        "landing_key VARCHAR(100) NOT NULL DEFAULT '(unknown)'",
        "service_key VARCHAR(100) NOT NULL DEFAULT '(unknown)'",
        "provider_key VARCHAR(50) NOT NULL DEFAULT '(unknown)'",
        "flow_key VARCHAR(50) NOT NULL DEFAULT '(unknown)'",
        "country VARCHAR(10) NOT NULL DEFAULT '(unknown)'",
        "pid VARCHAR(191) NOT NULL DEFAULT '(unknown)'",
        "tksource VARCHAR(191) NOT NULL DEFAULT '(unknown)'",
        "device_brand VARCHAR(100) NOT NULL DEFAULT '(unknown)'",
        "os VARCHAR(50) NOT NULL DEFAULT '(unknown)'",
        "os_version VARCHAR(50) NOT NULL DEFAULT '(unknown)'",
        "browser VARCHAR(100) NOT NULL DEFAULT '(unknown)'",
        "client_ip_version VARCHAR(10) NOT NULL DEFAULT '(unknown)'",
        "client_ip_prefix VARCHAR(120) NOT NULL DEFAULT '(unknown)'",
        'dimension_hash CHAR(64) NOT NULL',
    ] as $column) {
        kiwi_assert_contains($column, $sql, 'Expected required summary dimension column: ' . $column);
    }
    foreach ([
        'sessions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'page_loaded_sessions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'cta1_sessions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'cta1_click_events BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'cta2_sessions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'cta2_click_events BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'cta3_sessions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'cta3_click_events BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'handoff_attempts BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'handoff_successes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'handoff_fails BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'handoff_rate_pct DECIMAL(7,2) NOT NULL DEFAULT 0',
        'min_hidden_seconds DECIMAL(12,2) NULL',
        'max_hidden_seconds DECIMAL(12,2) NULL',
        'sales BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'sales_amount_minor BIGINT(20) NOT NULL DEFAULT 0',
    ] as $column) {
        kiwi_assert_contains($column, $sql, 'Expected required summary metric column: ' . $column);
    }
    kiwi_assert_contains('UNIQUE KEY metric_date_dimension_hash (metric_date, dimension_hash)', $sql, 'Expected idempotent uniqueness by metric_date and dimension hash.');
    foreach ([
        'KEY device_brand (device_brand)',
        'KEY os (os)',
        'KEY os_version (os_version)',
        'KEY browser (browser)',
        'KEY client_ip_version (client_ip_version)',
        'KEY client_ip_prefix (client_ip_prefix)',
    ] as $index) {
        kiwi_assert_contains($index, $sql, 'Expected filterable summary dimension index: ' . $index);
    }
    kiwi_assert_true(strpos($sql, 'client_ip VARCHAR') === false, 'Expected daily summary schema not to store raw client IPs.');
    kiwi_assert_true(strpos($sql, 'client_ip_hash') === false, 'Expected daily summary schema not to store client IP hashes.');
    kiwi_assert_true(strpos($sql, 'tkzone') === false, 'Expected main daily summary schema not to store tkzone dimensions.');
    kiwi_assert_true(strpos($sql, 'median_hidden_seconds') === false, 'Expected main daily summary schema not to store hidden-time medians.');
    kiwi_assert_true(strpos($sql, 'landing_page_aufrufe') === false, 'Expected new summary schema not to carry old landing_page_aufrufe output.');
    kiwi_assert_true(strpos($sql, 'engaged_sessions') === false, 'Expected new summary schema not to add engaged_sessions.');
    kiwi_assert_true(strpos($sql, 'wp_kiwi_') === false, 'Expected daily summary schema not to hardcode wp_ table prefixes.');

    $GLOBALS['kiwi_test_dbdelta_queries'] = $previous_queries;
    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service builds idempotent bounded aggregate refresh', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Landing_Funnel_Daily_Summary();
    $wpdb->prefix = 'abc_';

    $repository = new Kiwi_Landing_Funnel_Daily_Summary_Repository();
    $service = new Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service($repository);
    $result = $service->refresh_range('2026-05-22', '2026-05-22');
    $service->refresh_range('2026-05-22', '2026-05-22');

    kiwi_assert_true($result['success'], 'Expected aggregate refresh to succeed when delete and insert queries succeed.');
    kiwi_assert_same(4, $result['deleted'], 'Expected refresh result to expose deleted row count.');
    kiwi_assert_same(6, $result['inserted'], 'Expected refresh result to expose inserted row count.');

    $prepared = $wpdb->prepared_statements;
    kiwi_assert_same(
        ['2026-05-22'],
        $prepared[0]['args'] ?? [],
        'Expected refresh to delete the exact metric_date before inserting replacement rows.'
    );
    kiwi_assert_same(
        [
            '2026-05-22 00:00:00',
            '2026-05-23 00:00:00',
            '2026-05-22 00:00:00',
            '2026-05-24 00:00:00',
            '2026-05-22 00:00:00',
            '2026-05-24 00:00:00',
            '2026-05-22 00:00:00',
            '2026-05-23 00:00:00',
            '2026-05-22',
            '2026-05-22',
            '2026-05-22',
        ],
        $prepared[1]['args'] ?? [],
        'Expected refresh insert to bind day-bounded sessions/sales plus next-day handoff origin carryover.'
    );
    $insert_sql = (string) ($prepared[1]['query'] ?? '');
    $normalized_insert_sql = str_replace("\r\n", "\n", $insert_sql);

    kiwi_assert_contains('INSERT INTO abc_kiwi_landing_funnel_daily_summary', $insert_sql, 'Expected refresh to populate the persistent summary table.');
    kiwi_assert_contains('FROM abc_kiwi_landing_page_sessions', $insert_sql, 'Expected refresh to aggregate canonical landing sessions.');
    kiwi_assert_true(strpos($insert_sql, 'engagement_sessions AS') === false, 'Expected main summary refresh not to materialize engagement sessions before joining.');
    kiwi_assert_contains('LEFT JOIN abc_kiwi_premium_sms_landing_engagements e', $insert_sql, 'Expected engagement metrics to join directly to landing sessions.');
    kiwi_assert_contains('AND e.created_at >= %s', $insert_sql, 'Expected direct engagement joins to keep the refresh day lower bound.');
    kiwi_assert_contains('AND e.created_at < %s', $insert_sql, 'Expected direct engagement joins to keep the refresh day upper bound.');
    kiwi_assert_contains('LEFT JOIN handoff_by_session h', $insert_sql, 'Expected handoff metrics to join to landing sessions.');
    kiwi_assert_contains('FROM abc_kiwi_sales s', $insert_sql, 'Expected refresh to aggregate completed sales from durable sales snapshots.');
    kiwi_assert_true(strpos($insert_sql, 'abc_kiwi_click_attributions') === false, 'Expected daily summary sales aggregation not to depend on temporary click attribution rows.');
    kiwi_assert_contains("SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(service_key, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1) AS service_key", $insert_sql, 'Expected landing loads to read canonical service_key from landing sessions.');
    kiwi_assert_contains("SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(provider_key, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1) AS provider_key", $insert_sql, 'Expected landing loads to read canonical provider_key from landing sessions.');
    kiwi_assert_contains("SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(pid, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1) AS pid", $insert_sql, 'Expected landing loads to read canonical pid from landing sessions.');
    kiwi_assert_contains('DATE(l.first_landing_at) AS metric_date', $insert_sql, 'Expected session metric dates to come from deduplicated landing sessions.');
    kiwi_assert_contains('1 AS sessions', $insert_sql, 'Expected each canonical landing session fact to count as one session.');
    kiwi_assert_contains("COALESCE(NULLIF(l.service_key, ''), '(unknown)') AS service_key", $insert_sql, 'Expected service_key dimensions to come from landing sessions only.');
    kiwi_assert_contains("COALESCE(NULLIF(l.provider_key, ''), '(unknown)') AS provider_key", $insert_sql, 'Expected provider_key dimensions to come from landing sessions only.');
    kiwi_assert_contains("COALESCE(NULLIF(l.flow_key, ''), '(unknown)') AS flow_key", $insert_sql, 'Expected flow_key dimensions to come from landing sessions only.');
    kiwi_assert_contains("COALESCE(NULLIF(l.country, ''), '(unknown)') AS country", $insert_sql, 'Expected country dimensions to come from landing sessions only.');
    kiwi_assert_contains("COALESCE(NULLIF(l.pid, ''), '(unknown)') AS pid", $insert_sql, 'Expected pid dimensions to come from landing sessions only.');
    kiwi_assert_contains("COALESCE(NULLIF(l.tksource, ''), '(unknown)') AS tksource", $insert_sql, 'Expected tksource dimensions to come from landing sessions only.');
    kiwi_assert_true(strpos($insert_sql, 'COALESCE(NULLIF(e.provider_key') === false, 'Expected provider_key not to be repaired from engagement rows.');
    kiwi_assert_true(strpos($insert_sql, 'COALESCE(NULLIF(e.pid') === false, 'Expected pid not to be repaired from engagement rows.');
    kiwi_assert_true(strpos($insert_sql, 'NULLIF(h.tksource') === false, 'Expected tksource not to be repaired from handoff rows.');
    kiwi_assert_true(strpos($insert_sql, "'(unknown)' AS country") === false, 'Expected country to use the canonical session column instead of a hardcoded unknown bucket.');
    kiwi_assert_contains('client_ip_version', $insert_sql, 'Expected daily summary refresh to carry client IP version as a coarse dimension.');
    kiwi_assert_contains('client_ip_prefix', $insert_sql, 'Expected daily summary refresh to carry client IP prefix as a coarse dimension.');
    kiwi_assert_contains("SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(NULLIF(device_brand, ''), '(unknown)') ORDER BY created_at ASC SEPARATOR '|'), '|', 1) AS device_brand", $insert_sql, 'Expected landing loads to read normalized device brands from landing sessions.');
    kiwi_assert_contains("SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(NULLIF(os, ''), '(unknown)') ORDER BY created_at ASC SEPARATOR '|'), '|', 1) AS os", $insert_sql, 'Expected landing loads to read normalized OS buckets from landing sessions.');
    kiwi_assert_contains("SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(NULLIF(os_version, ''), '(unknown)') ORDER BY created_at ASC SEPARATOR '|'), '|', 1) AS os_version", $insert_sql, 'Expected landing loads to read normalized OS versions from landing sessions.');
    kiwi_assert_contains("SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(NULLIF(browser, ''), '(unknown)') ORDER BY created_at ASC SEPARATOR '|'), '|', 1) AS browser", $insert_sql, 'Expected landing loads to read normalized browser buckets from landing sessions.');
    kiwi_assert_contains("SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(NULLIF(client_ip_version, ''), '(unknown)') ORDER BY created_at ASC SEPARATOR '|'), '|', 1) AS client_ip_version", $insert_sql, 'Expected landing loads to read stored client IP versions from landing sessions.');
    kiwi_assert_contains("SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(NULLIF(client_ip_prefix, ''), '(unknown)') ORDER BY created_at ASC SEPARATOR '|'), '|', 1) AS client_ip_prefix", $insert_sql, 'Expected landing loads to read stored client IP prefixes from landing sessions.');
    kiwi_assert_contains("COALESCE(NULLIF(l.device_brand, ''), '(unknown)') AS device_brand", $insert_sql, 'Expected session facts to use normalized landing-session device brands.');
    kiwi_assert_contains("COALESCE(NULLIF(l.os, ''), '(unknown)') AS os", $insert_sql, 'Expected session facts to use normalized landing-session OS buckets.');
    kiwi_assert_contains("COALESCE(NULLIF(l.os_version, ''), '(unknown)') AS os_version", $insert_sql, 'Expected session facts to use normalized landing-session OS versions.');
    kiwi_assert_contains("COALESCE(NULLIF(l.browser, ''), '(unknown)') AS browser", $insert_sql, 'Expected session facts to use normalized landing-session browsers.');
    kiwi_assert_contains("COALESCE(NULLIF(l.client_ip_version, ''), '(unknown)') AS client_ip_version", $insert_sql, 'Expected session facts to use stored landing-session IP version buckets.');
    kiwi_assert_contains("COALESCE(NULLIF(l.client_ip_prefix, ''), '(unknown)') AS client_ip_prefix", $insert_sql, 'Expected session facts to use stored landing-session IP prefix buckets.');
    kiwi_assert_contains('CASE WHEN e.page_loaded_at IS NOT NULL THEN 1 ELSE 0 END AS page_loaded_sessions', $insert_sql, 'Expected page-loaded metrics to read from the directly joined engagement row.');
    kiwi_assert_contains('CASE WHEN e.first_cta1_click_at IS NOT NULL THEN 1 ELSE 0 END AS cta1_sessions', $insert_sql, 'Expected CTA session metrics to read from the directly joined engagement row.');
    kiwi_assert_contains('COALESCE(e.cta1_click_count, 0) AS cta1_click_events', $insert_sql, 'Expected CTA event metrics to read from the directly joined engagement row.');
    kiwi_assert_contains("COALESCE(NULLIF(s.os, ''), '(unknown)') AS os", $insert_sql, 'Expected sales facts to use durable sale OS snapshots.');
    kiwi_assert_contains("COALESCE(NULLIF(s.os_version, ''), '(unknown)') AS os_version", $insert_sql, 'Expected sales facts to use durable sale OS version snapshots.');
    kiwi_assert_contains("COALESCE(NULLIF(s.client_ip_version, ''), '(unknown)') AS client_ip_version", $insert_sql, 'Expected sales facts to use durable sale IP version snapshots with unknown fallback.');
    kiwi_assert_contains("COALESCE(NULLIF(s.client_ip_prefix, ''), '(unknown)') AS client_ip_prefix", $insert_sql, 'Expected sales facts to use durable sale IP prefix snapshots with unknown fallback.');
    kiwi_assert_true(strpos($insert_sql, 'l.remote_ip') === false, 'Expected session IP aggregation not to parse raw landing-session IPs.');
    kiwi_assert_true(strpos($insert_sql, 'INET6_ATON') === false, 'Expected daily summary aggregation not to normalize IPs in SQL.');
    kiwi_assert_true(strpos($insert_sql, 'ua_ch_model LIKE') === false, 'Expected daily summary aggregation not to parse device brands from UA-CH in SQL.');
    kiwi_assert_true(strpos($insert_sql, "raw_user_agent LIKE '%%Android %%'") === false, 'Expected daily summary aggregation not to parse OS versions from user agents in SQL.');
    kiwi_assert_true(strpos($insert_sql, 'android_version') === false, 'Expected daily summary aggregation to use os/os_version instead of the legacy android_version dimension.');
    kiwi_assert_true(strpos($insert_sql, 's.client_ip,') === false, 'Expected daily summary refresh not to select raw sale IPs.');
    kiwi_assert_true(strpos($insert_sql, 's.client_ip_hash') === false, 'Expected daily summary refresh not to select sale IP hashes.');
    kiwi_assert_true(strpos($insert_sql, 'first_engagement_at AS metric_at') === false, 'Expected engagement-only sessions not to create main summary session facts.');
    kiwi_assert_true(strpos($insert_sql, 'session_keys') === false, 'Expected main summary not to union engagement/handoff-only session keys.');
    kiwi_assert_true(strpos($insert_sql, 'has_session_fact') === false, 'Expected main summary session counts to come only from canonical landing sessions.');
    kiwi_assert_contains('COALESCE(e.cta1_click_count, 0) AS cta1_click_events', $insert_sql, 'Expected CTA1 events to use step-specific engagement counts from the direct session lookup.');
    kiwi_assert_contains('COALESCE(e.cta2_click_count, 0) AS cta2_click_events', $insert_sql, 'Expected CTA2 events to use step-specific engagement counts from the direct session lookup.');
    kiwi_assert_contains('COALESCE(e.cta3_click_count, 0) AS cta3_click_events', $insert_sql, 'Expected CTA3 events to use step-specific engagement counts from the direct session lookup.');
    kiwi_assert_contains("SUM(CASE WHEN event_type = 'sms_handoff_attempted' THEN 1 ELSE 0 END) AS handoff_attempts", $insert_sql, 'Expected handoff attempts to use event counts under the handoff uniqueness contract.');
    kiwi_assert_contains('MIN(CASE WHEN event_type = \'sms_handoff_hidden\'', $insert_sql, 'Expected min hidden seconds to remain as a light aggregate.');
    kiwi_assert_contains('MAX(CASE WHEN event_type = \'sms_handoff_hidden\'', $insert_sql, 'Expected max hidden seconds to remain as a light aggregate.');
    kiwi_assert_contains('handoff_origin_events AS', $insert_sql, 'Expected handoffs to resolve an origin landing-session day before aggregation.');
    kiwi_assert_contains('DATE(MAX(ls.created_at)) AS metric_date', $insert_sql, 'Expected handoff carryover to use the latest matching landing row before the event as the origin metric date.');
    kiwi_assert_contains('AND ls.created_at <= h.created_at', $insert_sql, 'Expected handoffs not to attach to landing rows that happened after the handoff event.');
    kiwi_assert_contains('GROUP BY h.id, h.landing_key, h.session_token, h.event_type, h.elapsed_ms', $insert_sql, 'Expected each handoff event to be assigned to one origin day before session aggregation.');
    kiwi_assert_contains('AND h.metric_date = DATE(l.first_landing_at)', $insert_sql, 'Expected handoff metrics to join only to the landing-session day they originated from.');
    kiwi_assert_contains('s.attribution_metric_date AS metric_date', $insert_sql, 'Expected sales metric date to use durable attribution metric date only.');
    kiwi_assert_contains('s.attribution_metric_date BETWEEN %s AND %s', $insert_sql, 'Expected sales refresh to use attribution metric date bounds.');
    kiwi_assert_contains('COUNT(*) AS sales', $insert_sql, 'Expected completed sales to use COUNT(*) without join multiplication.');
    kiwi_assert_true(strpos($insert_sql, 'DATE(s.completed_at)') === false, 'Expected main summary sales not to fall back to completion date.');
    kiwi_assert_true(strpos($insert_sql, 's.completed_at >= %s') === false, 'Expected legacy completed_at lower-bound fallback to be removed.');
    kiwi_assert_true(strpos($insert_sql, 's.completed_at < %s') === false, 'Expected legacy completed_at upper-bound fallback to be removed.');
    kiwi_assert_contains('WHERE a.metric_date = %s', $insert_sql, 'Expected each daily insert to write only the metric_date deleted by the chunk.');
    kiwi_assert_contains("COALESCE(NULLIF(s.landing_key, ''), '(unknown)') AS landing_key", $insert_sql, 'Expected unattributed sales to land in unknown dimension buckets.');
    kiwi_assert_contains('SHA2(CONCAT_WS', $insert_sql, 'Expected stable dimension_hash computation from normalized dimension fields.');
    kiwi_assert_contains("a.landing_key,\n                    a.service_key,\n                    a.provider_key,\n                    a.flow_key,\n                    a.country,\n                    a.pid,\n                    a.tksource,\n                    a.device_brand,\n                    a.os,\n                    a.os_version,\n                    a.browser,\n                    a.client_ip_version,\n                    a.client_ip_prefix", $normalized_insert_sql, 'Expected dimension_hash to use the slim main summary dimension basis.');
    kiwi_assert_contains('WHEN a.handoff_attempts <= 0 THEN 0', $insert_sql, 'Expected handoff rate to short-circuit when attempts are zero.');
    foreach ([
        'tkzone',
        'hidden_medians',
        'hidden_ranked',
        'ROW_NUMBER() OVER',
        'median_hidden_seconds',
        'COUNT(DISTINCT',
    ] as $excluded) {
        kiwi_assert_true(strpos($insert_sql, $excluded) === false, 'Expected slim main refresh SQL to omit broad or heavy expression: ' . $excluded);
    }
    kiwi_assert_true(strpos($insert_sql, 'landing_page_aufrufe') === false, 'Expected aggregate refresh not to emit old landing_page_aufrufe metric.');
    kiwi_assert_true(strpos($insert_sql, 'engaged_sessions') === false, 'Expected aggregate refresh not to emit engaged_sessions.');
    preg_match_all("/LIKE\\s+'([^']*%[^']*)'/", $insert_sql, $like_matches);
    foreach ($like_matches[1] as $like_pattern) {
        $length = strlen($like_pattern);

        for ($i = 0; $i < $length; $i++) {
            if ($like_pattern[$i] !== '%') {
                continue;
            }

            $previous_is_percent = $i > 0 && $like_pattern[$i - 1] === '%';
            $next_is_percent = $i + 1 < $length && $like_pattern[$i + 1] === '%';
            kiwi_assert_true(
                $previous_is_percent || $next_is_percent,
                'Expected all literal percent wildcards in prepared summary LIKE patterns to be escaped: ' . $like_pattern
            );
        }
    }

    kiwi_assert_same('DELETE FROM abc_kiwi_landing_funnel_daily_summary WHERE metric_date = %s', $prepared[0]['query'] ?? '', 'Expected first prepared statement to delete one metric_date before insert for idempotent recompute.');
    kiwi_assert_same('DELETE FROM abc_kiwi_landing_funnel_daily_summary WHERE metric_date = %s', $prepared[2]['query'] ?? '', 'Expected repeated refresh to delete the same metric_date again instead of accumulating rows.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service chunks multi-day refresh by metric date', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Landing_Funnel_Daily_Summary();
    $wpdb->prefix = 'abc_';

    $repository = new Kiwi_Landing_Funnel_Daily_Summary_Repository();
    $service = new Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service($repository);
    $result = $service->refresh_range('2026-05-20', '2026-05-22');

    kiwi_assert_true($result['success'], 'Expected multi-day aggregate refresh to succeed when all daily chunks succeed.');
    kiwi_assert_same(12, $result['deleted'], 'Expected multi-day refresh to aggregate daily deleted row counts.');
    kiwi_assert_same(18, $result['inserted'], 'Expected multi-day refresh to aggregate daily inserted row counts.');
    kiwi_assert_same(3, count($result['daily_results'] ?? []), 'Expected one compact daily result per metric date.');
    kiwi_assert_same('2026-05-20', $result['daily_results'][0]['metric_date'] ?? '', 'Expected daily results to retain the first chunk date.');
    kiwi_assert_same('2026-05-22', $result['daily_results'][2]['metric_date'] ?? '', 'Expected daily results to retain the last chunk date.');

    $prepared = $wpdb->prepared_statements;
    kiwi_assert_same(['2026-05-20'], $prepared[0]['args'] ?? [], 'Expected first chunk to delete only the first metric date.');
    kiwi_assert_same(['2026-05-21'], $prepared[2]['args'] ?? [], 'Expected second chunk to delete only the second metric date.');
    kiwi_assert_same(['2026-05-22'], $prepared[4]['args'] ?? [], 'Expected third chunk to delete only the third metric date.');
    kiwi_assert_same(
        [
            '2026-05-21 00:00:00',
            '2026-05-22 00:00:00',
            '2026-05-21 00:00:00',
            '2026-05-23 00:00:00',
            '2026-05-21 00:00:00',
            '2026-05-23 00:00:00',
            '2026-05-21 00:00:00',
            '2026-05-22 00:00:00',
            '2026-05-21',
            '2026-05-21',
            '2026-05-21',
        ],
        $prepared[3]['args'] ?? [],
        'Expected each chunk insert to bind only its own metric day while keeping next-day handoff origin carryover.'
    );

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service reports empty insert query failures', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Landing_Funnel_Daily_Summary();
    $wpdb->prefix = 'abc_';
    $wpdb->query_failure_prefix = 'INSERT INTO';

    $repository = new Kiwi_Landing_Funnel_Daily_Summary_Repository();
    $service = new Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service($repository);

    $result = $service->refresh_range('2026-05-22', '2026-05-22');

    kiwi_assert_true(!$result['success'], 'Expected aggregate refresh to fail when the insert query fails.');
    kiwi_assert_same(4, $result['deleted'], 'Expected failed insert result to retain the delete count.');
    kiwi_assert_same(0, $result['inserted'], 'Expected failed insert result to expose zero inserted rows.');
    kiwi_assert_contains('2026-05-22 insert aggregate rows:', $result['error'], 'Expected insert failures to name the failing metric date and step.');
    kiwi_assert_contains('insert daily summary aggregate rows query failed without database error detail', $result['error'], 'Expected empty wpdb errors to be replaced with a diagnosable summary refresh error.');
    kiwi_assert_same($result['error'], $service->get_last_error(), 'Expected service last_error to match the persisted refresh result error.');
    kiwi_assert_same($result['error'], $result['daily_results'][0]['error'] ?? '', 'Expected daily chunk error to match the range refresh error.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service reports delete step failures by metric date', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Landing_Funnel_Daily_Summary();
    $wpdb->prefix = 'abc_';
    $wpdb->query_failure_prefix = 'DELETE FROM';

    $repository = new Kiwi_Landing_Funnel_Daily_Summary_Repository();
    $service = new Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service($repository);

    $result = $service->refresh_range('2026-05-22', '2026-05-22');

    kiwi_assert_true(!$result['success'], 'Expected aggregate refresh to fail when the daily delete fails.');
    kiwi_assert_same(0, $result['deleted'], 'Expected failed delete result to expose zero deleted rows.');
    kiwi_assert_same(0, $result['inserted'], 'Expected failed delete result not to insert rows.');
    kiwi_assert_contains('2026-05-22 delete:', $result['error'], 'Expected delete failures to name the failing metric date and step.');
    kiwi_assert_contains('delete daily summary metric date query failed without database error detail', $result['error'], 'Expected empty delete wpdb errors to use the repository fallback diagnostic.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Landing_Funnel_Daily_Summary_Repository reads filtered statistics rows from the summary table', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Landing_Funnel_Daily_Summary();
    $wpdb->prefix = 'abc_';
    $wpdb->result_rows = [
        [
            'metric_date' => '2026-05-25',
            'landing_key' => 'lp2-fr',
            'service_key' => 'svc_a',
            'provider_key' => 'nth',
            'flow_key' => 'one-off',
            'country' => 'FR',
            'pid' => 'pid_a',
            'tksource' => 'src_a',
            'device_brand' => 'Samsung',
            'os' => 'Android',
            'os_version' => '14',
            'browser' => 'Chrome',
            'client_ip_version' => 'ipv4',
            'client_ip_prefix' => '203.0.113.0/24',
            'sessions' => 10,
            'page_loaded_sessions' => 9,
            'cta1_sessions' => 7,
            'cta1_click_events' => 8,
            'cta2_sessions' => 4,
            'cta2_click_events' => 4,
            'cta3_sessions' => 2,
            'cta3_click_events' => 2,
            'handoff_attempts' => 5,
            'handoff_successes' => 4,
            'handoff_fails' => 1,
            'handoff_rate_pct' => '80.00',
            'min_hidden_seconds' => '1.00',
            'max_hidden_seconds' => '3.00',
            'sales' => 2,
            'sales_amount_minor' => 900,
        ],
    ];

    $repository = new Kiwi_Landing_Funnel_Daily_Summary_Repository();
    $rows = $repository->get_rows([
        'from' => '2026-05-24T10:30:00',
        'to' => '2026-05-25 23:59:59',
        'service_key' => 'svc_a',
        'landing_key' => 'lp2-fr',
        'tksource' => 'src_a',
        'device_brand' => 'Samsung',
        'os' => 'Android',
        'os_version' => '14',
        'browser' => 'Chrome',
        'client_ip_version' => 'ipv4',
        'client_ip_prefix' => '203.0.113.0/24',
    ], 999);
    $statement = $wpdb->prepared_statements[count($wpdb->prepared_statements) - 1] ?? [];
    $query = (string) ($statement['query'] ?? '');

    kiwi_assert_same($wpdb->result_rows, $rows, 'Expected summary repository to return database rows when the summary table is readable.');
    kiwi_assert_contains('FROM abc_kiwi_landing_funnel_daily_summary', $query, 'Expected statistics query to read from the daily summary table.');
    kiwi_assert_contains('WHERE metric_date >= %s AND metric_date <= %s AND service_key = %s AND landing_key = %s AND tksource = %s AND device_brand = %s AND os = %s AND os_version = %s AND browser = %s AND client_ip_version = %s AND client_ip_prefix = %s', $query, 'Expected supported repository filters to be applied to summary rows.');
    kiwi_assert_contains('cta1_sessions', $query, 'Expected query to expose CTA1 metrics.');
    kiwi_assert_contains('cta2_sessions', $query, 'Expected query to expose CTA2 metrics.');
    kiwi_assert_contains('cta3_sessions', $query, 'Expected query to expose CTA3 metrics.');
    kiwi_assert_contains('handoff_attempts', $query, 'Expected query to expose handoff metrics.');
    kiwi_assert_contains('sales_amount_minor', $query, 'Expected query to expose sales amount metrics.');
    kiwi_assert_true(strpos($query, 'successful_sale_ids') === false, 'Expected summary read path not to expose legacy drilldown sale IDs.');
    kiwi_assert_same(
        ['2026-05-24', '2026-05-25', 'svc_a', 'lp2-fr', 'src_a', 'Samsung', 'Android', '14', 'Chrome', 'ipv4', '203.0.113.0/24', 500],
        $statement['args'] ?? [],
        'Expected repository query args to normalize date filters and cap the limit to 500.'
    );
    kiwi_assert_contains('client_ip_version', $query, 'Expected summary rows to expose client IP version.');
    kiwi_assert_contains('client_ip_prefix', $query, 'Expected summary rows to expose client IP prefix.');
    kiwi_assert_true(strpos($query, 'client_ip_hash') === false, 'Expected summary rows not to expose client IP hashes.');
    kiwi_assert_true(strpos($query, 'tkzone') === false, 'Expected main summary read path not to expose tkzone.');
    kiwi_assert_true(strpos($query, 'median_hidden_seconds') === false, 'Expected main summary read path not to expose hidden-time medians.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Landing_Funnel_Daily_Summary_Repository exposes summary filter options', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Landing_Funnel_Daily_Summary();
    $wpdb->prefix = 'abc_';
    $wpdb->result_rows_queue = [
        [['service_key' => 'svc_b'], ['service_key' => 'svc_a'], ['service_key' => 'svc_a']],
        [['landing_key' => 'lp6-fr'], ['landing_key' => 'lp2-fr']],
        [['tksource' => 'src_b'], ['tksource' => 'src_a']],
        [['device_brand' => 'Samsung'], ['device_brand' => 'Google']],
        [['os' => 'iOS'], ['os' => 'Android']],
        [['os_version' => '14'], ['os_version' => '13']],
        [['browser' => 'Chrome'], ['browser' => 'Safari']],
    ];

    $repository = new Kiwi_Landing_Funnel_Daily_Summary_Repository();
    $options = $repository->get_filter_options([
        'from' => '2026-05-24T10:30:00',
        'to' => '2026-05-25 23:59:59',
    ]);

    kiwi_assert_same(['svc_a', 'svc_b'], $options['service_keys'] ?? [], 'Expected service filter options to be distinct and sorted.');
    kiwi_assert_same(['lp2-fr', 'lp6-fr'], $options['landing_keys'] ?? [], 'Expected landing filter options to be distinct and sorted.');
    kiwi_assert_same(['src_a', 'src_b'], $options['tksources'] ?? [], 'Expected TK source filter options to be distinct and sorted.');
    kiwi_assert_true(!array_key_exists('tkzones', $options), 'Expected TK zone values not to be exposed as main summary dropdown options.');
    kiwi_assert_same(['Google', 'Samsung'], $options['device_brands'] ?? [], 'Expected device-brand filter options to be distinct and sorted.');
    kiwi_assert_same(['Android', 'iOS'], $options['os_values'] ?? [], 'Expected OS filter options to be distinct and sorted.');
    kiwi_assert_same(['13', '14'], $options['os_versions'] ?? [], 'Expected OS version filter options to be distinct and sorted.');
    kiwi_assert_same(['Chrome', 'Safari'], $options['browsers'] ?? [], 'Expected browser filter options to be distinct and sorted.');
    kiwi_assert_true(!array_key_exists('client_ip_versions', $options), 'Expected IP version values not to be exposed as normal dropdown options.');
    kiwi_assert_true(!array_key_exists('client_ip_prefixes', $options), 'Expected IP prefix values not to be exposed as normal dropdown options.');
    kiwi_assert_contains('SELECT DISTINCT service_key', (string) ($wpdb->prepared_statements[0]['query'] ?? ''), 'Expected service options to query distinct service keys from the summary table.');
    kiwi_assert_contains('SELECT DISTINCT browser', (string) ($wpdb->prepared_statements[12]['query'] ?? ''), 'Expected browser options to query distinct browsers from the summary table.');
    kiwi_assert_same(14, count($wpdb->prepared_statements), 'Expected normal filter option queries to exclude TK zone and IP dimensions.');
    kiwi_assert_same(
        ['2026-05-24', '2026-05-25'],
        $wpdb->prepared_statements[0]['args'] ?? [],
        'Expected options query to reuse normalized date filters.'
    );

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository creates slim tkzone schema-managed table', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $previous_queries = $GLOBALS['kiwi_test_dbdelta_queries'];
    $GLOBALS['kiwi_test_dbdelta_queries'] = [];
    $wpdb = new Kiwi_Test_Wpdb_Landing_Funnel_Daily_Summary();
    $wpdb->prefix = 'abc_';

    $repository = new Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository();
    $repository->create_table();
    $sql = implode("\n", $GLOBALS['kiwi_test_dbdelta_queries']);

    kiwi_assert_contains('CREATE TABLE abc_kiwi_landing_funnel_daily_tkzone_summary', $sql, 'Expected tkzone summary schema to use the configured table prefix.');
    foreach ([
        'metric_date DATE NOT NULL',
        "provider_key VARCHAR(50) NOT NULL DEFAULT '(unknown)'",
        "flow_key VARCHAR(50) NOT NULL DEFAULT '(unknown)'",
        "country VARCHAR(10) NOT NULL DEFAULT '(unknown)'",
        "service_key VARCHAR(100) NOT NULL DEFAULT '(unknown)'",
        "landing_key VARCHAR(100) NOT NULL DEFAULT '(unknown)'",
        "tksource VARCHAR(191) NOT NULL DEFAULT '(unknown)'",
        "tkzone VARCHAR(191) NOT NULL DEFAULT '(unknown)'",
        'dimension_hash CHAR(64) NOT NULL',
        "pid_set_hash CHAR(64) NOT NULL DEFAULT ''",
    ] as $column) {
        kiwi_assert_contains($column, $sql, 'Expected required tkzone summary dimension column: ' . $column);
    }
    foreach ([
        'sessions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'page_loaded_sessions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'cta1_sessions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'cta1_click_events BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'cta2_sessions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'cta2_click_events BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'cta3_sessions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'cta3_click_events BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'handoff_attempts BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'handoff_successes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'handoff_fails BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'handoff_rate_pct DECIMAL(7,2) NOT NULL DEFAULT 0',
        'sales BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'sales_amount_minor BIGINT(20) NOT NULL DEFAULT 0',
    ] as $column) {
        kiwi_assert_contains($column, $sql, 'Expected required tkzone summary metric column: ' . $column);
    }
    kiwi_assert_contains('UNIQUE KEY metric_date_dimension_hash (metric_date, dimension_hash)', $sql, 'Expected idempotent uniqueness by metric_date and dimension hash.');
    foreach ([
        'KEY provider_key (provider_key)',
        'KEY flow_key (flow_key)',
        'KEY country (country)',
        'KEY service_key (service_key)',
        'KEY landing_key (landing_key)',
        'KEY tksource (tksource)',
        'KEY tkzone (tkzone)',
        'KEY pid_set_hash (pid_set_hash)',
        'KEY metric_date_pid_set_hash (metric_date, pid_set_hash)',
    ] as $index) {
        kiwi_assert_contains($index, $sql, 'Expected filterable tkzone summary dimension index: ' . $index);
    }
    foreach ([
        'pid VARCHAR',
        'device_brand',
        'os_version',
        'browser VARCHAR',
        'client_ip_version',
        'client_ip_prefix',
        'median_hidden_seconds',
        'min_hidden_seconds',
        'max_hidden_seconds',
    ] as $excluded) {
        kiwi_assert_true(strpos($sql, $excluded) === false, 'Expected tkzone summary schema to omit broad summary field: ' . $excluded);
    }
    kiwi_assert_true(strpos($sql, 'wp_kiwi_') === false, 'Expected tkzone summary schema not to hardcode wp_ table prefixes.');

    $GLOBALS['kiwi_test_dbdelta_queries'] = $previous_queries;
    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Aggregation_Service builds bounded canonical-session aggregate refresh', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Landing_Funnel_Daily_Summary();
    $wpdb->prefix = 'abc_';

    $repository = new Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository();
    $config = new class(['106', '207']) extends Kiwi_Config {
        private $tkzone_summary_pids;

        public function __construct(array $tkzone_summary_pids)
        {
            $this->tkzone_summary_pids = $tkzone_summary_pids;
        }

        public function get_landing_funnel_tkzone_summary_pids(): array
        {
            return $this->tkzone_summary_pids;
        }
    };
    $service = new Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Aggregation_Service($repository, $config);
    $result = $service->refresh_range('2026-05-22', '2026-05-22');
    $service->refresh_range('2026-05-22', '2026-05-22');

    kiwi_assert_true($result['success'], 'Expected tkzone aggregate refresh to succeed when delete and insert queries succeed.');
    kiwi_assert_same(4, $result['deleted'], 'Expected tkzone refresh result to expose deleted row count.');
    kiwi_assert_same(6, $result['inserted'], 'Expected tkzone refresh result to expose inserted row count.');

    $prepared = $wpdb->prepared_statements;
    kiwi_assert_same(['2026-05-22'], $prepared[0]['args'] ?? [], 'Expected tkzone refresh to delete the exact metric_date before inserting replacement rows.');
    kiwi_assert_same(
        [
            '2026-05-22 00:00:00',
            '2026-05-23 00:00:00',
            '106',
            '207',
            '2026-05-22 00:00:00',
            '2026-05-24 00:00:00',
            '2026-05-22 00:00:00',
            '2026-05-23 00:00:00',
            '2026-05-22',
            '2026-05-22',
            '106',
            '207',
            hash('sha256', '106|207'),
            '2026-05-22',
        ],
        $prepared[1]['args'] ?? [],
        'Expected tkzone insert to bind day-bounded sessions/sales, allowed PIDs, and next-day handoff carryover.'
    );
    $insert_sql = (string) ($prepared[1]['query'] ?? '');
    $normalized_insert_sql = str_replace("\r\n", "\n", $insert_sql);

    kiwi_assert_contains('INSERT INTO abc_kiwi_landing_funnel_daily_tkzone_summary', $insert_sql, 'Expected refresh to populate the tkzone summary table.');
    kiwi_assert_contains('pid_set_hash', $insert_sql, 'Expected tkzone refresh to persist the current PID-set coverage hash.');
    kiwi_assert_contains('FROM abc_kiwi_landing_page_sessions', $insert_sql, 'Expected tkzone refresh to aggregate canonical landing sessions.');
    kiwi_assert_contains('AND pid IN (%s, %s)', $insert_sql, 'Expected tkzone session facts to be limited to configured PIDs.');
    kiwi_assert_true(strpos($insert_sql, 'engagement_sessions AS') === false, 'Expected tkzone summary refresh not to materialize engagement sessions before joining.');
    kiwi_assert_contains('LEFT JOIN abc_kiwi_premium_sms_landing_engagements e', $insert_sql, 'Expected engagement metrics to join directly to landing sessions.');
    kiwi_assert_contains('AND e.created_at >= %s', $insert_sql, 'Expected direct tkzone engagement joins to keep the refresh day lower bound.');
    kiwi_assert_contains('AND e.created_at < %s', $insert_sql, 'Expected direct tkzone engagement joins to keep the refresh day upper bound.');
    kiwi_assert_contains('LEFT JOIN handoff_by_session h', $insert_sql, 'Expected handoff metrics to join to landing sessions.');
    kiwi_assert_contains('FROM abc_kiwi_sales s', $insert_sql, 'Expected tkzone refresh to aggregate completed sales from durable sales snapshots.');
    kiwi_assert_contains("SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(tkzone, '') ORDER BY created_at ASC SEPARATOR '|'), '|', 1) AS tkzone", $insert_sql, 'Expected tkzone dimensions to come from landing sessions for session facts.');
    kiwi_assert_contains("COALESCE(NULLIF(s.tkzone, ''), '(unknown)') AS tkzone", $insert_sql, 'Expected sales facts to use durable sale tkzone snapshots.');
    kiwi_assert_contains('s.attribution_metric_date AS metric_date', $insert_sql, 'Expected sales metric date to use durable attribution metric date.');
    kiwi_assert_contains('s.attribution_metric_date BETWEEN %s AND %s', $insert_sql, 'Expected sales refresh to use attribution metric date bounds.');
    kiwi_assert_contains('AND s.pid IN (%s, %s)', $insert_sql, 'Expected tkzone sales facts to be limited to configured PIDs.');
    kiwi_assert_true(strpos($insert_sql, 'DATE(s.completed_at)') === false, 'Expected tkzone summary sales not to fall back to completion date.');
    kiwi_assert_true(strpos($insert_sql, 'first_engagement_at AS metric_at') === false, 'Expected engagement-only sessions not to create tkzone session facts.');
    kiwi_assert_true(strpos($insert_sql, 'session_keys') === false, 'Expected tkzone summary not to union engagement/handoff-only session keys.');
    kiwi_assert_contains('CASE WHEN e.page_loaded_at IS NOT NULL THEN 1 ELSE 0 END AS page_loaded_sessions', $insert_sql, 'Expected tkzone page-loaded metrics to read from the directly joined engagement row.');
    kiwi_assert_contains('CASE WHEN e.first_cta1_click_at IS NOT NULL THEN 1 ELSE 0 END AS cta1_sessions', $insert_sql, 'Expected tkzone CTA session metrics to read from the directly joined engagement row.');
    kiwi_assert_contains('COALESCE(e.cta1_click_count, 0) AS cta1_click_events', $insert_sql, 'Expected tkzone CTA event metrics to read from the directly joined engagement row.');
    kiwi_assert_contains("SUM(CASE WHEN event_type = 'sms_handoff_attempted' THEN 1 ELSE 0 END) AS handoff_attempts", $insert_sql, 'Expected handoff attempts to use event counts under the handoff uniqueness contract.');
    kiwi_assert_contains('WHEN a.handoff_attempts <= 0 THEN 0', $insert_sql, 'Expected handoff rate to short-circuit when attempts are zero.');
    kiwi_assert_contains("SHA2(CONCAT_WS('|',", $insert_sql, 'Expected stable tkzone dimension_hash computation.');
    kiwi_assert_contains("a.provider_key,\n                    a.flow_key,\n                    a.country,\n                    a.service_key,\n                    a.landing_key,\n                    a.tksource,\n                    a.tkzone", $normalized_insert_sql, 'Expected tkzone dimension_hash to use only the tkzone summary dimension basis.');
    foreach ([
        'device_brand',
        'os_version',
        'browser',
        'client_ip',
        'hidden_seconds',
        'median_hidden_seconds',
        'ua_ch_',
        'user_agent',
        'COUNT(DISTINCT',
    ] as $excluded) {
        kiwi_assert_true(strpos($insert_sql, $excluded) === false, 'Expected tkzone refresh SQL to omit broad or heavy summary expression: ' . $excluded);
    }
    kiwi_assert_same('DELETE FROM abc_kiwi_landing_funnel_daily_tkzone_summary WHERE metric_date = %s', $prepared[0]['query'] ?? '', 'Expected first tkzone refresh statement to delete one metric_date.');
    kiwi_assert_same('DELETE FROM abc_kiwi_landing_funnel_daily_tkzone_summary WHERE metric_date = %s', $prepared[2]['query'] ?? '', 'Expected repeated tkzone refresh to delete the same metric_date again.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository reads rows with only supported zone filters', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Landing_Funnel_Daily_Summary();
    $wpdb->prefix = 'abc_';
    $wpdb->result_rows = [
        [
            'metric_date' => '2026-05-24',
            'provider_key' => 'nth',
            'flow_key' => 'one-off',
            'country' => 'FR',
            'service_key' => 'svc_a',
            'landing_key' => 'lp2-fr',
            'tksource' => 'src_a',
            'tkzone' => 'zone_a',
            'sessions' => 10,
            'page_loaded_sessions' => 9,
            'cta1_sessions' => 7,
            'cta1_click_events' => 8,
            'cta2_sessions' => 4,
            'cta2_click_events' => 4,
            'cta3_sessions' => 2,
            'cta3_click_events' => 2,
            'handoff_attempts' => 5,
            'handoff_successes' => 4,
            'handoff_fails' => 1,
            'handoff_rate_pct' => '80.00',
            'sales' => 2,
            'sales_amount_minor' => 900,
        ],
    ];

    $repository = new Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository();
    $normalized = $repository->normalize_filters([
        'from' => '2026-05-24T10:30:00',
        'to' => '2026-05-25 23:59:59',
        'provider_key' => 'nth',
        'flow_key' => 'one-off',
        'country' => 'FR',
        'service_key' => 'svc_a',
        'landing_key' => 'lp2-fr',
        'tksource' => 'src_a',
        'tkzone' => 'zone_a',
        'device_brand' => 'Samsung',
        'client_ip_prefix' => '203.0.113.0/24',
    ]);
    $rows = $repository->get_rows($normalized, 999);
    $statement = $wpdb->prepared_statements[count($wpdb->prepared_statements) - 1] ?? [];
    $query = (string) ($statement['query'] ?? '');

    kiwi_assert_same($wpdb->result_rows, $rows, 'Expected tkzone summary repository to return database rows when the table is readable.');
    kiwi_assert_true(!array_key_exists('device_brand', $normalized), 'Expected tkzone filters not to normalize unsupported device dimensions.');
    kiwi_assert_true(!array_key_exists('client_ip_prefix', $normalized), 'Expected tkzone filters not to normalize unsupported IP dimensions.');
    kiwi_assert_contains('FROM abc_kiwi_landing_funnel_daily_tkzone_summary', $query, 'Expected tkzone query to read from the tkzone summary table.');
    kiwi_assert_contains('WHERE metric_date >= %s AND pid_set_hash = %s AND metric_date <= %s AND provider_key = %s AND flow_key = %s AND country = %s AND service_key = %s AND landing_key = %s AND tksource = %s AND tkzone = %s', $query, 'Expected current PID-set hash and all supported tkzone filters to be applied.');
    kiwi_assert_contains('handoff_rate_pct', $query, 'Expected tkzone query to expose handoff rate.');
    kiwi_assert_contains('sales_amount_minor', $query, 'Expected tkzone query to expose sales amount metrics.');
    kiwi_assert_true(strpos($query, 'pid = %s') === false, 'Expected tkzone query not to expose unsupported PID filters.');
    foreach (['device_brand', 'os_version', 'browser', 'client_ip_prefix', 'median_hidden_seconds'] as $excluded) {
        kiwi_assert_true(strpos($query, $excluded) === false, 'Expected tkzone query not to expose unsupported field: ' . $excluded);
    }
    kiwi_assert_same(
        ['2026-05-24', hash('sha256', '106'), '2026-05-25', 'nth', 'one-off', 'FR', 'svc_a', 'lp2-fr', 'src_a', 'zone_a', 500],
        $statement['args'] ?? [],
        'Expected tkzone query args to include the current PID-set hash, normalize date filters, and cap the limit to 500.'
    );

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository exposes zone filter options only', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Landing_Funnel_Daily_Summary();
    $wpdb->prefix = 'abc_';
    $wpdb->result_rows_queue = [
        [['provider_key' => 'nth'], ['provider_key' => 'dimoco']],
        [['flow_key' => 'pin'], ['flow_key' => 'one-off']],
        [['country' => 'PL'], ['country' => 'FR']],
        [['service_key' => 'svc_b'], ['service_key' => 'svc_a']],
        [['landing_key' => 'lp6-fr'], ['landing_key' => 'lp2-fr']],
        [['tksource' => 'src_b'], ['tksource' => 'src_a']],
        [['tkzone' => 'zone_b'], ['tkzone' => 'zone_a']],
    ];

    $repository = new Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository();
    $options = $repository->get_filter_options([
        'from' => '2026-05-24T10:30:00',
        'to' => '2026-05-25 23:59:59',
    ]);

    kiwi_assert_same(['dimoco', 'nth'], $options['provider_keys'] ?? [], 'Expected provider filter options to be distinct and sorted.');
    kiwi_assert_same(['one-off', 'pin'], $options['flow_keys'] ?? [], 'Expected flow filter options to be distinct and sorted.');
    kiwi_assert_same(['FR', 'PL'], $options['countries'] ?? [], 'Expected country filter options to be distinct and sorted.');
    kiwi_assert_same(['svc_a', 'svc_b'], $options['service_keys'] ?? [], 'Expected service filter options to be distinct and sorted.');
    kiwi_assert_same(['lp2-fr', 'lp6-fr'], $options['landing_keys'] ?? [], 'Expected landing filter options to be distinct and sorted.');
    kiwi_assert_same(['src_a', 'src_b'], $options['tksources'] ?? [], 'Expected source filter options to be distinct and sorted.');
    kiwi_assert_same(['zone_a', 'zone_b'], $options['tkzones'] ?? [], 'Expected zone filter options to be distinct and sorted.');
    foreach (['device_brands', 'os_values', 'os_versions', 'browsers', 'client_ip_versions', 'client_ip_prefixes'] as $excluded_option_key) {
        kiwi_assert_true(!array_key_exists($excluded_option_key, $options), 'Expected tkzone filter options not to expose unsupported option set: ' . $excluded_option_key);
    }
    kiwi_assert_contains('SELECT DISTINCT provider_key', (string) ($wpdb->prepared_statements[0]['query'] ?? ''), 'Expected provider options to query distinct provider keys from the tkzone summary table.');
    kiwi_assert_contains('SELECT DISTINCT tkzone', (string) ($wpdb->prepared_statements[12]['query'] ?? ''), 'Expected tkzone options to query distinct zones from the tkzone summary table.');
    kiwi_assert_contains('pid_set_hash = %s', (string) ($wpdb->prepared_statements[0]['query'] ?? ''), 'Expected tkzone option queries to stay scoped to the current PID-set hash.');
    kiwi_assert_same(
        ['2026-05-24', hash('sha256', '106'), '2026-05-25'],
        $wpdb->prepared_statements[0]['args'] ?? [],
        'Expected tkzone options query to include the current PID-set hash and reuse normalized date filters.'
    );

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Traffic_Source_Funnel_Statistics_Repository creates plugin-managed prefixed view', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Traffic_Source_Statistics();
    $wpdb->prefix = 'abc_';

    $repository = new Kiwi_Traffic_Source_Funnel_Statistics_Repository();
    $created = $repository->create_view();

    kiwi_assert_true($created, 'Expected traffic-source statistics view setup to succeed without database errors.');
    kiwi_assert_contains('CREATE OR REPLACE VIEW abc_kiwi_v_load_to_cta_by_tksource_tkzone AS', $wpdb->queries[0] ?? '', 'Expected setup to replace the managed statistics view non-destructively.');
    kiwi_assert_contains('abc_kiwi_premium_sms_landing_engagements', $wpdb->queries[0] ?? '', 'Expected view SQL to read the prefixed landing engagement table.');
    kiwi_assert_contains('abc_kiwi_click_attributions', $wpdb->queries[0] ?? '', 'Expected view SQL to read the prefixed click-attribution table.');
    kiwi_assert_contains('abc_kiwi_sales', $wpdb->queries[0] ?? '', 'Expected view SQL to read the prefixed sales table.');
    kiwi_assert_contains('s.completed_at AS metric_at', $wpdb->queries[0] ?? '', 'Expected completed sale metrics to be timestamped by completion time.');
    kiwi_assert_contains("COALESCE(NULLIF(s.service_key, ''), NULLIF(ca.service_key, ''), '(empty)') AS service_key", $wpdb->queries[0] ?? '', 'Expected completed sale metrics to prefer durable sales service snapshots.');
    kiwi_assert_contains("COALESCE(NULLIF(s.tksource, ''), NULLIF(ca.tksource, ''), '(empty)') AS tksource", $wpdb->queries[0] ?? '', 'Expected completed sale metrics to prefer durable sales source snapshots.');
    kiwi_assert_contains("AND s.completed_at >= '2026-05-12 20:00:00'", $wpdb->queries[0] ?? '', 'Expected completed sale cutoff to align with completion timestamps.');
    kiwi_assert_contains('PARTITION BY ca.transaction_id', $wpdb->queries[0] ?? '', 'Expected view SQL to deduplicate attribution rows before joining completed sales.');
    kiwi_assert_contains('ranked_ca.kiwi_attribution_rank = 1', $wpdb->queries[0] ?? '', 'Expected view SQL to pick one canonical attribution row per transaction_id.');
    kiwi_assert_contains('LEFT JOIN', $wpdb->queries[0] ?? '', 'Expected completed sale metrics to tolerate missing temporary attribution rows.');
    kiwi_assert_contains('CREATE OR REPLACE VIEW abc_kiwi_v_one_for_all AS', $wpdb->queries[1] ?? '', 'Expected setup to also replace the one-for-all analytics view.');
    kiwi_assert_contains('abc_kiwi_landing_page_sessions', $wpdb->queries[1] ?? '', 'Expected one-for-all view SQL to include landing page sessions.');
    kiwi_assert_contains('abc_kiwi_premium_sms_landing_engagements', $wpdb->queries[1] ?? '', 'Expected one-for-all view SQL to include landing engagement UA context.');
    kiwi_assert_contains('abc_kiwi_landing_handoff_events', $wpdb->queries[1] ?? '', 'Expected one-for-all view SQL to include handoff diagnostics.');
    kiwi_assert_contains('device_brand', $wpdb->queries[1] ?? '', 'Expected one-for-all view to expose computed device_brand.');
    kiwi_assert_true(strpos($wpdb->queries[1] ?? '', "WHEN ua_ch_model <> '' THEN SUBSTRING_INDEX(ua_ch_model, ' ', 1)") === false, 'Expected one-for-all device brand logic not to promote unknown model tokens to brands.');
    kiwi_assert_contains("WHEN ua_ch_model LIKE 'SM-%' OR raw_user_agent LIKE '%Samsung%' THEN 'Samsung'", $wpdb->queries[1] ?? '', 'Expected one-for-all view to keep Samsung brand rules.');
    kiwi_assert_contains("WHEN ua_ch_model LIKE 'Xiaomi%' OR ua_ch_model LIKE 'Redmi%' OR ua_ch_model LIKE 'POCO%'", $wpdb->queries[1] ?? '', 'Expected one-for-all view to group Xiaomi-family models defensively.');
    kiwi_assert_contains("WHEN ua_ch_model LIKE 'Pixel%' OR raw_user_agent LIKE '%Pixel%' THEN 'Google'", $wpdb->queries[1] ?? '', 'Expected one-for-all view to keep Pixel as Google.');
    kiwi_assert_contains('android_version', $wpdb->queries[1] ?? '', 'Expected one-for-all view to expose computed android_version.');
    kiwi_assert_contains('browser', $wpdb->queries[1] ?? '', 'Expected one-for-all view to expose computed browser.');
    kiwi_assert_contains('handoff_rate_pct', $wpdb->queries[1] ?? '', 'Expected one-for-all view to expose handoff rate.');
    kiwi_assert_contains('median_hidden_seconds', $wpdb->queries[1] ?? '', 'Expected one-for-all view to expose hidden-time median.');
    kiwi_assert_contains("COALESCE(NULLIF(s.landing_key, ''), NULLIF(ca.landing_page_key, '')) AS landing_key", $wpdb->queries[1] ?? '', 'Expected one-for-all sales counts to prefer durable sales landing snapshots.');
    kiwi_assert_contains("COALESCE(NULLIF(s.session_ref, ''), NULLIF(ca.session_ref, '')) AS session_token", $wpdb->queries[1] ?? '', 'Expected one-for-all sales counts to prefer durable sales session snapshots.');
    kiwi_assert_true(strpos($wpdb->queries[0] ?? '', 'wp_kiwi_') === false, 'Expected view SQL not to hardcode wp_ table prefixes.');
    kiwi_assert_true(strpos($wpdb->queries[1] ?? '', 'wp_kiwi_') === false, 'Expected one-for-all view SQL not to hardcode wp_ table prefixes.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Traffic_Source_Funnel_Statistics_Repository create_table tolerates view migration errors', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Traffic_Source_Statistics();
    $wpdb->prefix = 'abc_';
    $wpdb->last_error = 'CREATE VIEW command denied';

    $repository = new Kiwi_Traffic_Source_Funnel_Statistics_Repository();

    $repository->create_table();

    kiwi_assert_contains('CREATE VIEW command denied', $repository->get_last_error(), 'Expected repository to preserve database error details for migration diagnostics.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Traffic_Source_Funnel_Statistics_Repository filters before aggregation and caps limit', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Traffic_Source_Statistics();
    $wpdb->prefix = 'abc_';
    $wpdb->result_rows = [
        [
            'service_key' => 'svc_a',
            'tksource' => 'src_a',
            'tkzone' => '(empty)',
            'sessions' => 2,
            'loaded_sessions' => 2,
            'cta_sessions' => 1,
            'cta_click_events' => 3,
            'median_seconds_load_to_cta' => '2.00',
            'successful_sales' => 1,
            'successful_sales_amount_minor' => 450,
        ],
    ];

    $repository = new Kiwi_Traffic_Source_Funnel_Statistics_Repository();
    $rows = $repository->get_rows([
        'from' => '2026-05-13 00:00:00',
        'to' => '2026-05-14 00:00:00',
        'service_key' => 'svc_a',
        'tksource' => 'src_a',
    ], 999);
    $statement = $wpdb->prepared_statements[count($wpdb->prepared_statements) - 1] ?? [];
    $query = (string) ($statement['query'] ?? '');

    kiwi_assert_same($wpdb->result_rows, $rows, 'Expected repository to return database rows when the view is readable.');
    kiwi_assert_contains('FROM abc_kiwi_v_load_to_cta_by_tksource_tkzone', $query, 'Expected statistics query to read from the managed view.');
    kiwi_assert_contains('WHERE metric_at >= %s AND metric_at <= %s AND service_key = %s AND tksource = %s', $query, 'Expected filters to be applied inside the filtered CTE before aggregation.');
    kiwi_assert_contains('median_seconds_load_to_cta', $query, 'Expected query to calculate and expose the median field.');
    kiwi_assert_same(
        ['2026-05-13 00:00:00', '2026-05-14 00:00:00', 'svc_a', 'src_a', 500],
        $statement['args'] ?? [],
        'Expected repository query args to include filters and cap the limit to 500.'
    );

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Traffic_Source_Funnel_Statistics_Repository exposes distinct filter options', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Traffic_Source_Statistics();
    $wpdb->prefix = 'abc_';
    $wpdb->result_rows_queue = [
        [
            ['service_key' => 'svc_b'],
            ['service_key' => 'svc_a'],
            ['service_key' => 'svc_a'],
        ],
        [
            ['tksource' => 'src_b'],
            ['tksource' => 'src_a'],
        ],
    ];

    $repository = new Kiwi_Traffic_Source_Funnel_Statistics_Repository();
    $options = $repository->get_filter_options([
        'from' => '2026-05-13T00:00',
        'to' => '2026-05-14T00:00',
    ]);

    kiwi_assert_same(['svc_a', 'svc_b'], $options['service_keys'] ?? [], 'Expected service filter options to be distinct and sorted.');
    kiwi_assert_same(['src_a', 'src_b'], $options['tksources'] ?? [], 'Expected TK source filter options to be distinct and sorted.');
    kiwi_assert_contains('SELECT DISTINCT service_key', (string) ($wpdb->prepared_statements[0]['query'] ?? ''), 'Expected service options to query distinct service keys from the statistics view.');
    kiwi_assert_contains('SELECT DISTINCT tksource', (string) ($wpdb->prepared_statements[2]['query'] ?? ''), 'Expected TK source options to query distinct TK sources from the statistics view.');
    kiwi_assert_same(
        ['2026-05-13 00:00:00', '2026-05-14 00:00:00'],
        $wpdb->prepared_statements[0]['args'] ?? [],
        'Expected options query to reuse normalized date filters.'
    );

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi statistics date filters normalize datetime input to summary dates', function (): void {
    $previous_timezone = date_default_timezone_get();
    date_default_timezone_set('Europe/Berlin');

    try {
        $repository = new Kiwi_Test_Landing_Funnel_Daily_Summary_Repository();
        $filters = $repository->normalize_filters([
            'from' => '2026-05-13 00:00:30',
            'to' => '2026-05-13T00:00:45',
            'client_ip_version' => 'ipv4',
            'client_ip_prefix' => '203.0.113.0/24',
        ]);

        kiwi_assert_same('2026-05-13', $filters['from'] ?? '', 'Expected timezone-less MySQL from filter to map to the same summary date.');
        kiwi_assert_same('2026-05-13', $filters['to'] ?? '', 'Expected timezone-less datetime-local to filter to map to the same summary date.');
        kiwi_assert_same('ipv4', $filters['client_ip_version'] ?? '', 'Expected IP version filter to be preserved.');
        kiwi_assert_same('203.0.113.0/24', $filters['client_ip_prefix'] ?? '', 'Expected IP prefix filter to preserve slash suffixes.');

        $malformed_filters = $repository->normalize_filters([
            'from' => '2026-13-99T25:61',
            'to' => '2026-02-31 12:00:00',
        ]);

        kiwi_assert_same(
            Kiwi_Landing_Funnel_Daily_Summary_Repository::DEFAULT_FROM,
            $malformed_filters['from'] ?? '',
            'Expected malformed datetime-local from filter to fall back instead of reaching SQL as a date literal.'
        );
        kiwi_assert_same('', $malformed_filters['to'] ?? '', 'Expected malformed optional datetime-local to filter to be rejected.');

        $_GET = [
            'kiwi_stats_from' => '2026-05-13 00:00:30',
            'kiwi_stats_to' => '2026-05-13 00:00:45',
        ];
        $repository->rows = [
            [
                'metric_date' => '2026-05-13',
                'landing_key' => 'lp2-fr',
                'service_key' => 'svc_a',
                'tksource' => 'src_a',
            ],
        ];

        $shortcode = new Kiwi_Statistics_Shortcode($repository, new Kiwi_Frontend_Auth_Gate());
        $output = $shortcode->render();

        kiwi_assert_contains('name="kiwi_stats_from" value="2026-05-13"', $output, 'Expected date from value not to shift to UTC while rendering.');
        kiwi_assert_contains('name="kiwi_stats_to" value="2026-05-13"', $output, 'Expected date to value not to shift to UTC while rendering.');
        kiwi_assert_true(strpos($output, '2026-05-12') === false, 'Expected statistics filter rendering not to shift Europe/Berlin dates to UTC.');
    } finally {
        $_GET = [];
        date_default_timezone_set($previous_timezone);
    }
});

kiwi_run_test('Kiwi_Statistics_Shortcode renders summary filters, CTA metrics, handoff, and sales', function (): void {
    $_GET = [
        'kiwi_stats_from' => '2026-05-13T00:00:30',
        'kiwi_stats_to' => '2026-05-14T00:00:45',
        'kiwi_stats_service_key' => 'svc_a',
        'kiwi_stats_landing_key' => 'lp2-fr',
        'kiwi_stats_tksource' => 'src_a',
        'kiwi_stats_tkzone' => 'legacy-zone-ignored',
        'kiwi_stats_device_brand' => 'Samsung',
        'kiwi_stats_os' => 'Android',
        'kiwi_stats_os_version' => '14',
        'kiwi_stats_browser' => 'Chrome',
        'kiwi_stats_client_ip_version' => 'ipv4',
        'kiwi_stats_client_ip_prefix' => '203.0.113.0/24',
        'kiwi_stats_limit' => '50',
    ];

    $repository = new Kiwi_Test_Landing_Funnel_Daily_Summary_Repository();
    $repository->filter_options = [
        'service_keys' => ['svc_a', 'svc_b'],
        'landing_keys' => ['lp2-fr', 'lp6-fr'],
        'tksources' => ['src_a', 'src_b'],
        'device_brands' => ['Google', 'Samsung'],
        'os_values' => ['Android', 'iOS'],
        'os_versions' => ['13', '14'],
        'browsers' => ['Chrome', 'Safari'],
    ];
    $repository->rows = [
        [
            'metric_date' => '2026-05-13',
            'landing_key' => 'lp2-fr',
            'service_key' => 'svc_a',
            'provider_key' => 'nth',
            'flow_key' => 'one-off',
            'country' => 'FR',
            'pid' => 'pid_a',
            'tksource' => 'src_a',
            'device_brand' => 'Samsung',
            'os' => 'Android',
            'os_version' => '14',
            'browser' => 'Chrome',
            'client_ip_version' => 'ipv4',
            'client_ip_prefix' => '203.0.113.0/24',
            'sessions' => 4,
            'page_loaded_sessions' => 3,
            'cta1_sessions' => 2,
            'cta1_click_events' => 5,
            'cta2_sessions' => 1,
            'cta2_click_events' => 2,
            'cta3_sessions' => 1,
            'cta3_click_events' => 1,
            'handoff_attempts' => 2,
            'handoff_successes' => 1,
            'handoff_fails' => 1,
            'handoff_rate_pct' => '50.00',
            'min_hidden_seconds' => '1.00',
            'max_hidden_seconds' => '4.00',
            'sales' => 1,
            'sales_amount_minor' => 450,
        ],
    ];
    $shortcode = new Kiwi_Statistics_Shortcode($repository, new Kiwi_Frontend_Auth_Gate());
    $output = $shortcode->render();

    kiwi_assert_contains('Landing Funnel Daily Summary', $output, 'Expected statistics shortcode shell to render summary wording.');
    kiwi_assert_contains('type="date" name="kiwi_stats_from" value="2026-05-13"', $output, 'Expected From filter to render as a date input for summary metric_date.');
    kiwi_assert_contains('type="date" name="kiwi_stats_to" value="2026-05-14"', $output, 'Expected To filter to render as a date input for summary metric_date.');
    kiwi_assert_contains('<select id="kiwi_stats_service_key" class="kiwi-select kiwi-width-small" name="kiwi_stats_service_key">', $output, 'Expected Service Key filter to render as a select.');
    kiwi_assert_contains('<option value="">all</option><option value="svc_a" selected="selected">svc_a</option><option value="svc_b">svc_b</option>', $output, 'Expected Service Key select to include all plus dynamic selected options.');
    kiwi_assert_contains('<select id="kiwi_stats_landing_key" class="kiwi-select kiwi-width-small" name="kiwi_stats_landing_key">', $output, 'Expected Landing Key filter to render as a select.');
    kiwi_assert_contains('<select id="kiwi_stats_tksource" class="kiwi-select kiwi-width-small" name="kiwi_stats_tksource">', $output, 'Expected TK Source filter to render as a select.');
    kiwi_assert_contains('<option value="">all</option><option value="src_a" selected="selected">src_a</option><option value="src_b">src_b</option>', $output, 'Expected TK Source select to include all plus dynamic selected options.');
    kiwi_assert_true(strpos($output, 'id="kiwi_stats_tkzone"') === false, 'Expected TK Zone not to render as a main summary filter.');
    kiwi_assert_contains('<select id="kiwi_stats_device_brand" class="kiwi-select kiwi-width-small" name="kiwi_stats_device_brand">', $output, 'Expected Device Brand filter to render as a select.');
    kiwi_assert_contains('<select id="kiwi_stats_os" class="kiwi-select kiwi-width-small" name="kiwi_stats_os">', $output, 'Expected OS filter to render as a select.');
    kiwi_assert_contains('<select id="kiwi_stats_os_version" class="kiwi-select kiwi-width-small" name="kiwi_stats_os_version">', $output, 'Expected OS Version filter to render as a select.');
    kiwi_assert_contains('<select id="kiwi_stats_browser" class="kiwi-select kiwi-width-small" name="kiwi_stats_browser">', $output, 'Expected Browser filter to render as a select.');
    kiwi_assert_true(strpos($output, 'id="kiwi_stats_client_ip_version"') === false, 'Expected IP version not to render as a normal dropdown filter.');
    kiwi_assert_true(strpos($output, 'id="kiwi_stats_client_ip_prefix"') === false, 'Expected IP prefix not to render as a normal dropdown filter.');
    kiwi_assert_contains('kiwi-table kiwi-table--statistics', $output, 'Expected statistics table modifier for compact scrolling layout.');
    kiwi_assert_contains('title="CTA1 Sessions">CTA1 Sessions</th>', $output, 'Expected statistics table to render CTA1 summary column.');
    kiwi_assert_contains('title="CTA2 Sessions">CTA2 Sessions</th>', $output, 'Expected statistics table to render CTA2 summary column.');
    kiwi_assert_contains('title="CTA3 Sessions">CTA3 Sessions</th>', $output, 'Expected statistics table to render CTA3 summary column.');
    kiwi_assert_contains('title="Handoff Attempts">Handoff Attempts</th>', $output, 'Expected statistics table to render handoff summary column.');
    kiwi_assert_contains('title="IP Version">IP Version</th>', $output, 'Expected statistics table to render IP version column.');
    kiwi_assert_contains('title="IP Prefix">IP Prefix</th>', $output, 'Expected statistics table to render IP prefix column.');
    kiwi_assert_true(strpos($output, 'title="TK Zone"') === false, 'Expected statistics table not to render TK Zone in the main summary.');
    kiwi_assert_true(strpos($output, 'title="Median Hidden s"') === false, 'Expected statistics table not to render hidden-time median in the main summary.');
    kiwi_assert_contains('title="Sales">Sales</th>', $output, 'Expected statistics table to render sales column.');
    kiwi_assert_contains('title="Samsung">Samsung</td>', $output, 'Expected device dimension to render in the summary table.');
    kiwi_assert_contains('title="203.0.113.0/24">203.0.113.0/24</td>', $output, 'Expected coarse IP prefix dimension to render in the summary table.');
    kiwi_assert_true(strpos($output, 'client_ip_hash') === false, 'Expected statistics output not to expose client IP hashes.');
    kiwi_assert_true(strpos($output, 'txn-7-long-reference') === false, 'Expected legacy transaction drilldown references not to render from summary rows.');
    kiwi_assert_same('2026-05-13', $repository->calls[0]['filters']['from'] ?? '', 'Expected shortcode to pass normalized date from filter to repository.');
    kiwi_assert_same('2026-05-14', $repository->calls[0]['filters']['to'] ?? '', 'Expected shortcode to pass normalized date to filter to repository.');
    kiwi_assert_same('svc_a', $repository->calls[0]['filters']['service_key'] ?? '', 'Expected shortcode to pass service_key filter to repository.');
    kiwi_assert_same('lp2-fr', $repository->calls[0]['filters']['landing_key'] ?? '', 'Expected shortcode to pass landing_key filter to repository.');
    kiwi_assert_same('src_a', $repository->calls[0]['filters']['tksource'] ?? '', 'Expected shortcode to pass tksource filter to repository.');
    kiwi_assert_true(!array_key_exists('tkzone', $repository->calls[0]['filters'] ?? []), 'Expected legacy tkzone request parameters to be ignored by the main summary.');
    kiwi_assert_same('Samsung', $repository->calls[0]['filters']['device_brand'] ?? '', 'Expected shortcode to pass device_brand filter to repository.');
    kiwi_assert_same('Android', $repository->calls[0]['filters']['os'] ?? '', 'Expected shortcode to pass os filter to repository.');
    kiwi_assert_same('14', $repository->calls[0]['filters']['os_version'] ?? '', 'Expected shortcode to pass os_version filter to repository.');
    kiwi_assert_same('Chrome', $repository->calls[0]['filters']['browser'] ?? '', 'Expected shortcode to pass browser filter to repository.');
    kiwi_assert_same('ipv4', $repository->calls[0]['filters']['client_ip_version'] ?? '', 'Expected shortcode to pass IP version filter to repository.');
    kiwi_assert_same('203.0.113.0/24', $repository->calls[0]['filters']['client_ip_prefix'] ?? '', 'Expected shortcode to pass IP prefix filter to repository.');
    kiwi_assert_contains('kiwi_stats_from=2026-05-13', $output, 'Expected CSV export URL to preserve normalized from date filter.');
    kiwi_assert_contains('kiwi_stats_service_key=svc_a', $output, 'Expected CSV export URL to preserve service filter.');
    kiwi_assert_contains('kiwi_stats_landing_key=lp2-fr', $output, 'Expected CSV export URL to preserve landing filter.');
    kiwi_assert_contains('kiwi_stats_tksource=src_a', $output, 'Expected CSV export URL to preserve TK source filter.');
    kiwi_assert_true(strpos($output, 'kiwi_stats_tkzone=') === false, 'Expected CSV export URL not to preserve legacy TK zone filters.');
    kiwi_assert_contains('kiwi_stats_device_brand=Samsung', $output, 'Expected CSV export URL to preserve device-brand filter.');
    kiwi_assert_contains('kiwi_stats_os=Android', $output, 'Expected CSV export URL to preserve OS filter.');
    kiwi_assert_contains('kiwi_stats_os_version=14', $output, 'Expected CSV export URL to preserve OS version filter.');
    kiwi_assert_contains('kiwi_stats_browser=Chrome', $output, 'Expected CSV export URL to preserve browser filter.');
    kiwi_assert_contains('kiwi_stats_client_ip_version=ipv4', $output, 'Expected CSV export URL to preserve IP version filter.');
    kiwi_assert_contains('kiwi_stats_client_ip_prefix=203.0.113.0%2F24', $output, 'Expected CSV export URL to preserve encoded IP prefix filter.');

    $_GET = [];
});

kiwi_run_test('Kiwi_Statistics_Shortcode shows an admin error when the summary table is unreadable', function (): void {
    $_GET = [];

    $repository = new Kiwi_Test_Landing_Funnel_Daily_Summary_Repository();
    $repository->error = 'table command denied';
    $repository->source_name = 'wp_kiwi_landing_funnel_daily_summary';
    $shortcode = new Kiwi_Statistics_Shortcode($repository, new Kiwi_Frontend_Auth_Gate());
    $output = $shortcode->render();

    kiwi_assert_contains('Statistics source wp_kiwi_landing_funnel_daily_summary is not readable', $output, 'Expected clear admin error when the managed summary table cannot be read.');
    kiwi_assert_contains('table command denied', $output, 'Expected database read error details to be shown without credentials.');
});

kiwi_run_test('Kiwi_Plugin statistics CSV export reads the daily summary with the same filters', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $previous_get = $_GET;
    $wpdb = new Kiwi_Test_Wpdb_Landing_Funnel_Daily_Summary();
    $wpdb->prefix = 'abc_';
    $wpdb->result_rows = [
        [
            'metric_date' => '2026-05-13',
            'landing_key' => 'lp2-fr',
            'service_key' => 'svc_a',
            'tksource' => 'src_a',
            'device_brand' => 'Samsung',
            'os' => 'Android',
            'os_version' => '14',
            'browser' => 'Chrome',
            'client_ip_version' => 'ipv4',
            'client_ip_prefix' => '203.0.113.0/24',
            'sessions' => 4,
            'cta1_sessions' => 2,
            'handoff_attempts' => 2,
            'sales' => 1,
        ],
    ];
    $_GET = [
        'kiwi_statistics_export' => '1',
        'kiwi_stats_from' => '2026-05-13T00:00:30',
        'kiwi_stats_to' => '2026-05-14T00:00:45',
        'kiwi_stats_service_key' => 'svc_a',
        'kiwi_stats_landing_key' => 'lp2-fr',
        'kiwi_stats_tksource' => 'src_a',
        'kiwi_stats_tkzone' => 'legacy-zone-ignored',
        'kiwi_stats_device_brand' => 'Samsung',
        'kiwi_stats_os' => 'Android',
        'kiwi_stats_os_version' => '14',
        'kiwi_stats_browser' => 'Chrome',
        'kiwi_stats_client_ip_version' => 'ipv4',
        'kiwi_stats_client_ip_prefix' => '203.0.113.0/24',
        'kiwi_stats_limit' => '50',
    ];

    $plugin = new Kiwi_Test_Plugin(dirname(__DIR__), 'https://example.test/plugin/');
    $plugin->maybe_export_statistics();
    $statement = $wpdb->prepared_statements[count($wpdb->prepared_statements) - 1] ?? [];

    kiwi_assert_same($wpdb->result_rows, $plugin->exported_statistics_rows, 'Expected Statistics CSV export to return summary rows.');
    kiwi_assert_contains('FROM abc_kiwi_landing_funnel_daily_summary', (string) ($statement['query'] ?? ''), 'Expected CSV export to read from the summary table.');
    kiwi_assert_same(
        ['2026-05-13', '2026-05-14', 'svc_a', 'lp2-fr', 'src_a', 'Samsung', 'Android', '14', 'Chrome', 'ipv4', '203.0.113.0/24', 50],
        $statement['args'] ?? [],
        'Expected CSV export filters to ignore legacy tkzone and match supported request filters.'
    );

    $_GET = $previous_get;
    $wpdb = $previous_wpdb;
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
            'schedule_landing_funnel_daily_summary_refresh',
            'schedule_device_model_brand_harvest',
            'schedule_retention_cleanup',
            'maybe_export_statistics',
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
    kiwi_assert_true(isset($GLOBALS['kiwi_test_hooks']['kiwi_device_model_brand_harvest']), 'Expected device model brand harvest cron hook to be registered.');

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

kiwi_run_test('Kiwi_Plugin includes landing analytics repositories in schema repository list', function (): void {
    $plugin = new Kiwi_Test_Plugin_Performance_Gates(dirname(__DIR__), 'https://example.test/plugin/');
    $method = new ReflectionMethod(Kiwi_Plugin::class, 'build_schema_repositories');
    $repositories = $method->invoke($plugin);
    $classes = array_map(static function ($repository): string {
        return is_object($repository) ? get_class($repository) : '';
    }, is_array($repositories) ? $repositories : []);

    kiwi_assert_true(
        in_array(Kiwi_Landing_Handoff_Event_Repository::class, $classes, true),
        'Expected schema migrations to include the landing handoff event repository.'
    );
    kiwi_assert_true(
        in_array(Kiwi_Device_Model_Brand_Map_Repository::class, $classes, true),
        'Expected schema migrations to include the device model brand map repository.'
    );
    kiwi_assert_true(
        in_array(Kiwi_Sms_Body_Variant_Repository::class, $classes, true),
        'Expected schema migrations to include the SMS body variant repository.'
    );
    kiwi_assert_true(
        in_array(Kiwi_Traffic_Source_Funnel_Statistics_Repository::class, $classes, true),
        'Expected schema migrations to include the traffic-source funnel statistics view repository.'
    );
    kiwi_assert_true(
        in_array(Kiwi_Landing_Funnel_Daily_Summary_Repository::class, $classes, true),
        'Expected schema migrations to include the landing funnel daily summary repository.'
    );
    kiwi_assert_true(
        in_array(Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository::class, $classes, true),
        'Expected schema migrations to include the landing funnel daily tkzone summary repository.'
    );
    kiwi_assert_true(
        in_array(Kiwi_Retention_Cleanup_Run_Repository::class, $classes, true),
        'Expected schema migrations to include the retention cleanup run repository.'
    );
    kiwi_assert_true(
        in_array(Kiwi_Retention_Table_Growth_Snapshot_Repository::class, $classes, true),
        'Expected schema migrations to include the retention growth snapshot repository.'
    );
});

kiwi_run_test('Kiwi_Config exposes final retention defaults for landing page sessions', function (): void {
    $GLOBALS['kiwi_test_options'] = [];

    $config = new Kiwi_Config();
    $settings = $config->get_retention_source_settings('landing_page_sessions');

    kiwi_assert_same(false, $settings['enabled'], 'Expected landing-page-session retention to be disabled by default.');
    kiwi_assert_same(true, $settings['dry_run'], 'Expected landing-page-session retention to default to dry-run.');
    kiwi_assert_same(14, $settings['retention_days'], 'Expected landing-page-session retention to default to fourteen days.');
    kiwi_assert_same('/home/u367252972/kiwi-backend-archives/db-retention', $config->get_retention_archive_root(), 'Expected Hostinger archive root to be the default.');
});

kiwi_run_test('Kiwi_Plugin schedules the retention cleanup daily cron hook once', function (): void {
    $GLOBALS['kiwi_test_hooks'] = [];
    $GLOBALS['kiwi_test_cron_events'] = [];
    $GLOBALS['kiwi_test_next_scheduled'] = [];

    $reflection = new ReflectionClass(Kiwi_Plugin::class);
    $hook = (string) $reflection->getConstant('RETENTION_CLEANUP_DAILY_HOOK');

    $plugin = new Kiwi_Test_Plugin_Performance_Gates(dirname(__DIR__), 'https://example.test/plugin/');
    $plugin->register();
    $plugin->schedule_retention_cleanup();
    $plugin->schedule_retention_cleanup();

    kiwi_assert_true(isset($GLOBALS['kiwi_test_hooks'][$hook]), 'Expected the retention cleanup cron hook to be registered.');
    kiwi_assert_same(1, count(array_filter($GLOBALS['kiwi_test_cron_events'], static function (array $event) use ($hook): bool {
        return ($event['hook'] ?? '') === $hook;
    })), 'Expected retention cleanup to be scheduled only once.');
    kiwi_assert_same('daily', $GLOBALS['kiwi_test_cron_events'][0]['recurrence'] ?? '', 'Expected retention cleanup to use a daily cron recurrence.');
});

kiwi_run_test('Kiwi_Retention_Sqlite_Archive_Service fails closed after archive finalization errors', function (): void {
    $service = new Kiwi_Test_Retention_Sqlite_Archive_Failure_Service();
    $result = $service->apply_archive_failure_for_test(
        [
            'success' => true,
            'archive_integrity_check' => 'ok',
            'archived_rows' => 12,
            'error_code' => '',
            'error_message' => '',
        ],
        new RuntimeException('finalization failed')
    );

    kiwi_assert_same(false, $result['success'] ?? null, 'Expected archive finalization errors to fail closed even after an ok integrity check.');
    kiwi_assert_same('archive_failed', $result['error_code'] ?? '', 'Expected archive finalization errors to use the generic archive failure code.');
    kiwi_assert_same('finalization failed', $result['error_message'] ?? '', 'Expected archive finalization error detail to be retained.');
    kiwi_assert_same('ok', $result['archive_integrity_check'] ?? '', 'Expected failure handling not to erase the recorded integrity check result.');
    kiwi_assert_same(12, $result['archived_rows'] ?? 0, 'Expected failure handling not to erase archived row counts.');
});

kiwi_run_test('Kiwi_Retention_Coverage_Gate fails closed when summary coverage query errors', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new class {
        public $prefix = 'wp_';
        public $last_error = 'summary table missing';
        public $prepared_statements = [];

        public function prepare($query, ...$args)
        {
            $statement = [
                'query' => (string) $query,
                'args' => $args,
            ];
            $this->prepared_statements[] = $statement;

            return $statement;
        }

        public function get_results($statement, $output = null)
        {
            return false;
        }
    };
    $source = (new Kiwi_Retention_Source_Registry())->get('landing_page_sessions');

    $result = (new Kiwi_Retention_Coverage_Gate(new Kiwi_Config()))
        ->check_landing_page_sessions($source, '2026-06-12 00:00:00');

    kiwi_assert_same('failed', $result['status'], 'Expected coverage gate to fail closed when a coverage query errors.');
    kiwi_assert_true(
        in_array('main_summary_query_failed', $result['blocking_errors'] ?? [], true),
        'Expected main summary query failures to block cleanup.'
    );
    kiwi_assert_same('failed', $result['main_summary']['status'] ?? '', 'Expected failed main summary query to mark that gate section failed.');
    kiwi_assert_contains('summary table missing', $result['main_summary']['error_message'] ?? '', 'Expected coverage gate error details to retain wpdb error context.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Retention_Coverage_Gate matches main summary coverage by dimensions and sessions', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new class {
        public $prefix = 'wp_';
        public $last_error = '';
        public $prepared_statements = [];

        public function prepare($query, ...$args)
        {
            $statement = [
                'query' => (string) $query,
                'args' => $args,
            ];
            $this->prepared_statements[] = $statement;

            return $statement;
        }

        public function get_results($statement, $output = null): array
        {
            $query = is_array($statement) ? (string) ($statement['query'] ?? '') : (string) $statement;

            if (strpos($query, 'kiwi_landing_funnel_daily_summary') !== false
                && strpos($query, 'summary.sessions = raw.sessions') !== false
            ) {
                return [['metric_date' => '2026-06-11']];
            }

            return [];
        }
    };
    $source = (new Kiwi_Retention_Source_Registry())->get('landing_page_sessions');

    $result = (new Kiwi_Retention_Coverage_Gate(new Kiwi_Config()))
        ->check_landing_page_sessions($source, '2026-06-12 00:00:00');
    $main_query = (string) ($wpdb->prepared_statements[0]['query'] ?? '');

    kiwi_assert_same('failed', $result['status'], 'Expected main summary dimension/session mismatch to fail the gate.');
    kiwi_assert_same(['2026-06-11'], $result['main_summary']['blocking_missing_dates'] ?? [], 'Expected main summary mismatch date to block cleanup.');
    kiwi_assert_contains('1 AS sessions', $main_query, 'Expected main coverage gate to derive one raw session fact per canonical landing session.');
    kiwi_assert_contains('GROUP BY DATE(created_at), landing_key, session_token', $main_query, 'Expected main coverage gate to mirror the per-day landing/session refresh grain.');
    kiwi_assert_contains('summary.sessions = raw.sessions', $main_query, 'Expected main coverage gate to compare raw and summary session counts.');
    foreach ([
        'page_loaded_sessions',
        'cta1_sessions',
        'cta1_click_events',
        'cta2_sessions',
        'cta2_click_events',
        'cta3_sessions',
        'cta3_click_events',
        'handoff_attempts',
        'handoff_successes',
        'handoff_fails',
        'handoff_rate_pct',
        'sales',
        'sales_amount_minor',
    ] as $metric) {
        kiwi_assert_contains('summary.' . $metric . ' = raw.' . $metric, $main_query, 'Expected main coverage gate to compare summary metric: ' . $metric);
    }
    kiwi_assert_contains('summary.min_hidden_seconds <=> raw.min_hidden_seconds', $main_query, 'Expected main coverage gate to compare nullable min hidden seconds.');
    kiwi_assert_contains('summary.max_hidden_seconds <=> raw.max_hidden_seconds', $main_query, 'Expected main coverage gate to compare nullable max hidden seconds.');
    kiwi_assert_contains('LEFT JOIN wp_kiwi_premium_sms_landing_engagements', $main_query, 'Expected main coverage gate to recompute engagement metrics from persisted engagement rows.');
    kiwi_assert_contains('FROM wp_kiwi_landing_handoff_events h', $main_query, 'Expected main coverage gate to recompute handoff metrics from persisted handoff rows.');
    kiwi_assert_contains('handoff_origin_events AS', $main_query, 'Expected main coverage gate to mirror summary handoff attribution through origin events.');
    kiwi_assert_contains('DATE(MAX(ls.created_at)) AS metric_date', $main_query, 'Expected main coverage gate to attribute handoffs to the latest landing row before the event.');
    kiwi_assert_contains('ls.created_at <= h.created_at', $main_query, 'Expected main coverage gate not to attribute handoffs to future landing rows.');
    kiwi_assert_contains('GROUP BY h.id, h.landing_key, h.session_token, h.event_type, h.elapsed_ms', $main_query, 'Expected main coverage gate to attribute each handoff event once before aggregating by session.');
    kiwi_assert_contains('HAVING handoff_created_at < DATE_ADD(metric_date, INTERVAL 2 DAY)', $main_query, 'Expected main coverage gate to preserve the daily summary carryover window.');
    kiwi_assert_true(strpos($main_query, 'h.created_at >= l.first_landing_at') === false, 'Expected main coverage gate not to broadly join handoffs back to earlier reused-session landing days.');
    kiwi_assert_contains('FROM wp_kiwi_sales s', $main_query, 'Expected main coverage gate to recompute sale metrics from durable sales rows.');
    foreach ([
        'landing_key',
        'service_key',
        'provider_key',
        'flow_key',
        'country',
        'pid',
        'tksource',
        'device_brand',
        'os',
        'os_version',
        'browser',
        'client_ip_version',
        'client_ip_prefix',
    ] as $dimension) {
        kiwi_assert_contains('summary.' . $dimension . ' = raw.' . $dimension, $main_query, 'Expected main coverage gate to match dimension: ' . $dimension);
    }
    foreach (['device_brand', 'os', 'os_version', 'browser', 'client_ip_version', 'client_ip_prefix'] as $dimension) {
        kiwi_assert_contains(
            "GROUP_CONCAT(NULLIF(NULLIF({$dimension}, ''), '(unknown)') ORDER BY created_at ASC SEPARATOR '|')",
            $main_query,
            'Expected main coverage gate to mirror summary unknown-bucket normalization for dimension: ' . $dimension
        );
    }
    kiwi_assert_true(strpos($main_query, 'SELECT DISTINCT metric_date') === false, 'Expected main coverage gate not to accept any summary row for a date as full coverage.');
    kiwi_assert_same(
        ['2026-06-12 00:00:00', '2026-06-12 00:00:00', '2026-06-12 00:00:00', '2026-06-12 00:00:00', '2026-06-12 00:00:00'],
        $wpdb->prepared_statements[0]['args'] ?? [],
        'Expected main coverage gate to bind cutoff for landing, handoff origin, sales, and summary coverage windows.'
    );

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Retention_Coverage_Gate matches TK-zone coverage to current PID set', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new class {
        public $prefix = 'wp_';
        public $last_error = '';
        public $prepared_statements = [];

        public function prepare($query, ...$args)
        {
            $statement = [
                'query' => (string) $query,
                'args' => $args,
            ];
            $this->prepared_statements[] = $statement;

            return $statement;
        }

        public function get_results($statement, $output = null): array
        {
            $query = is_array($statement) ? (string) ($statement['query'] ?? '') : (string) $statement;

            if (strpos($query, 'kiwi_landing_funnel_daily_tkzone_summary') !== false
                && strpos($query, 'summary.sessions = raw.sessions') !== false
            ) {
                return [['metric_date' => '2026-06-10']];
            }

            return [];
        }
    };
    $source = (new Kiwi_Retention_Source_Registry())->get('landing_page_sessions');

    $result = (new Kiwi_Retention_Coverage_Gate(new Kiwi_Config()))
        ->check_landing_page_sessions($source, '2026-06-12 00:00:00');

    kiwi_assert_same('failed', $result['status'], 'Expected TK-zone coverage mismatch for the current PID set to fail the gate.');
    kiwi_assert_same(['2026-06-10'], $result['tkzone_summary']['blocking_missing_dates'] ?? [], 'Expected current-PID TK-zone mismatch date to block cleanup.');
    kiwi_assert_contains('pid IN', (string) ($wpdb->prepared_statements[1]['query'] ?? ''), 'Expected TK-zone gate to filter raw sessions by the current PID allow-list.');
    kiwi_assert_contains('summary.sessions = raw.sessions', (string) ($wpdb->prepared_statements[1]['query'] ?? ''), 'Expected TK-zone gate to compare current raw PID sessions against summary sessions.');
    foreach ([
        'page_loaded_sessions',
        'cta1_sessions',
        'cta1_click_events',
        'cta2_sessions',
        'cta2_click_events',
        'cta3_sessions',
        'cta3_click_events',
        'handoff_attempts',
        'handoff_successes',
        'handoff_fails',
        'handoff_rate_pct',
        'sales',
        'sales_amount_minor',
    ] as $metric) {
        kiwi_assert_contains('summary.' . $metric . ' = raw.' . $metric, (string) ($wpdb->prepared_statements[1]['query'] ?? ''), 'Expected TK-zone coverage gate to compare summary metric: ' . $metric);
    }
    kiwi_assert_contains('WHERE pid_set_hash = %s', (string) ($wpdb->prepared_statements[1]['query'] ?? ''), 'Expected TK-zone gate to require current PID-set coverage metadata.');
    kiwi_assert_contains('DATE(created_at) AS metric_date', (string) ($wpdb->prepared_statements[1]['query'] ?? ''), 'Expected TK-zone gate to derive raw coverage metric dates per row date.');
    kiwi_assert_contains('GROUP BY DATE(created_at), landing_key, session_token', (string) ($wpdb->prepared_statements[1]['query'] ?? ''), 'Expected TK-zone gate to mirror the per-day refresh grouping for reused session cookies.');
    kiwi_assert_true(strpos((string) ($wpdb->prepared_statements[1]['query'] ?? ''), 'DATE(MIN(created_at)) AS metric_date') === false, 'Expected TK-zone gate not to collapse reused cookies across days.');
    kiwi_assert_contains('LEFT JOIN wp_kiwi_premium_sms_landing_engagements', (string) ($wpdb->prepared_statements[1]['query'] ?? ''), 'Expected TK-zone coverage gate to recompute engagement metrics from persisted engagement rows.');
    kiwi_assert_contains('INNER JOIN wp_kiwi_landing_handoff_events', (string) ($wpdb->prepared_statements[1]['query'] ?? ''), 'Expected TK-zone coverage gate to recompute handoff metrics from persisted handoff rows.');
    kiwi_assert_contains('h.created_at < DATE_ADD(l.metric_date, INTERVAL 2 DAY)', (string) ($wpdb->prepared_statements[1]['query'] ?? ''), 'Expected TK-zone coverage gate to mirror the refresh handoff carryover window.');
    kiwi_assert_true(strpos((string) ($wpdb->prepared_statements[1]['query'] ?? ''), 'h.created_at < DATE_ADD(l.metric_date, INTERVAL 1 DAY)') === false, 'Expected TK-zone coverage gate not to stop handoff coverage at midnight.');
    kiwi_assert_contains('FROM wp_kiwi_sales s', (string) ($wpdb->prepared_statements[1]['query'] ?? ''), 'Expected TK-zone coverage gate to recompute sale metrics from durable sales rows.');
    kiwi_assert_same(
        ['2026-06-12 00:00:00', '106', '2026-06-12 00:00:00', '106', hash('sha256', '106'), '2026-06-12 00:00:00'],
        $wpdb->prepared_statements[1]['args'] ?? [],
        'Expected TK-zone coverage gate to bind cutoff and current PID set for landing, sales, and summary coverage windows.'
    );

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Retention_Cleanup_Service records disabled landing-page-session runs without archive or delete', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = (object) ['prefix' => 'wp_'];
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_deleted_transients'] = [];
    $GLOBALS['kiwi_test_options'] = [
        'kiwi_retention_settings' => [
            'landing_page_sessions' => [
                'enabled' => false,
                'dry_run' => true,
                'retention_days' => 14,
            ],
        ],
    ];

    $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
    $snapshots = new Kiwi_Test_Retention_Table_Growth_Snapshot_Repository();
    $archive = new Kiwi_Test_Retention_Sqlite_Archive_Service();
    $gate = new Kiwi_Test_Retention_Coverage_Gate(['status' => 'passed']);
    $service = new Kiwi_Test_Retention_Cleanup_Service(
        new Kiwi_Config(),
        new Kiwi_Retention_Source_Registry(),
        $runs,
        $snapshots,
        $archive,
        $gate
    );
    $service->eligible_rows = 42;

    $result = $service->run_source('landing_page_sessions', 'manual');

    kiwi_assert_same('skipped', $result['status'], 'Expected disabled retention cleanup to be skipped.');
    kiwi_assert_same('cleanup_disabled', $result['error_code'], 'Expected disabled skip to be explicit in the run result.');
    kiwi_assert_same([], $archive->calls, 'Expected disabled cleanup not to archive rows.');
    kiwi_assert_same(['before_cleanup', 'after_cleanup'], array_column($snapshots->snapshots, 'snapshot_phase'), 'Expected disabled run to still capture growth snapshots.');
    kiwi_assert_true(!array_key_exists('kiwi_retention_cleanup_last_result', $GLOBALS['kiwi_test_options']), 'Expected cleanup not to create a last-result WordPress option.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Retention_Cleanup_Service aborts when run audit creation fails', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = (object) ['prefix' => 'wp_'];
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_deleted_transients'] = [];
    $GLOBALS['kiwi_test_options'] = [
        'kiwi_retention_settings' => [
            'landing_page_sessions' => [
                'enabled' => true,
                'dry_run' => false,
                'retention_days' => 14,
            ],
        ],
    ];

    $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
    $runs->create_run_result = 0;
    $snapshots = new Kiwi_Test_Retention_Table_Growth_Snapshot_Repository();
    $archive = new Kiwi_Test_Retention_Sqlite_Archive_Service();
    $gate = new Kiwi_Test_Retention_Coverage_Gate(['status' => 'passed']);
    $service = new Kiwi_Test_Retention_Cleanup_Service(
        new Kiwi_Config(),
        new Kiwi_Retention_Source_Registry(),
        $runs,
        $snapshots,
        $archive,
        $gate
    );
    $service->eligible_rows = 3;
    $service->delete_result = ['deleted_rows' => 3, 'delete_batches' => 1];

    $result = $service->run_source('landing_page_sessions', 'manual');

    kiwi_assert_same(false, $result['success'], 'Expected cleanup to fail when the audit run row cannot be created.');
    kiwi_assert_same('failed', $result['status'], 'Expected missing audit row to produce a failed cleanup result.');
    kiwi_assert_same('run_audit_create_failed', $result['error_code'], 'Expected missing audit row failure to be explicit.');
    kiwi_assert_same([], $snapshots->snapshots, 'Expected cleanup not to capture snapshots without a durable run row.');
    kiwi_assert_same([], $archive->calls, 'Expected cleanup not to archive rows without a durable run row.');
    kiwi_assert_same([], $gate->calls, 'Expected cleanup not to run coverage gates without a durable run row.');
    kiwi_assert_same(['count'], $service->events, 'Expected cleanup not to delete rows after audit run creation fails.');
    kiwi_assert_same([], $GLOBALS['kiwi_test_transients'], 'Expected cleanup not to take a lock without a durable run row.');
    kiwi_assert_same([], $GLOBALS['kiwi_test_deleted_transients'], 'Expected cleanup not to clear an uncreated lock.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Retention_Cleanup_Service dry-run records accepted historical coverage gaps without deleting', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = (object) ['prefix' => 'wp_'];
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_deleted_transients'] = [];
    $GLOBALS['kiwi_test_options'] = [
        'kiwi_retention_settings' => [
            'landing_page_sessions' => [
                'enabled' => true,
                'dry_run' => true,
                'retention_days' => 14,
            ],
        ],
    ];

    $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
    $snapshots = new Kiwi_Test_Retention_Table_Growth_Snapshot_Repository();
    $archive = new Kiwi_Test_Retention_Sqlite_Archive_Service();
    $gate_result = [
        'status' => 'passed',
        'main_summary' => [
            'accepted_missing_dates' => ['2026-05-15', '2026-05-16'],
            'blocking_missing_dates' => [],
        ],
    ];
    $gate = new Kiwi_Test_Retention_Coverage_Gate($gate_result);
    $service = new Kiwi_Test_Retention_Cleanup_Service(
        new Kiwi_Config(),
        new Kiwi_Retention_Source_Registry(),
        $runs,
        $snapshots,
        $archive,
        $gate
    );
    $service->eligible_rows = 9;

    $result = $service->run_source('landing_page_sessions', 'manual');
    $run_row = $runs->rows[1] ?? [];

    kiwi_assert_same('success', $result['status'], 'Expected dry-run to finish successfully when the only gaps are accepted historical dates.');
    kiwi_assert_same('passed', $result['gate_status'], 'Expected accepted historical gaps to pass the gate.');
    kiwi_assert_same([], $archive->calls, 'Expected dry-run not to archive rows.');
    kiwi_assert_same('2026-03-18 00:00:00', $run_row['cutoff_value'] ?? '', 'Expected cutoff to use start of today minus fourteen complete days.');
    $gate_results_json = $runs->rows[1]['gate_results_json'] ?? $runs->updates[0]['data']['gate_results_json'] ?? '';
    if (is_array($gate_results_json)) {
        $gate_results_json = wp_json_encode($gate_results_json);
    }
    kiwi_assert_contains('2026-05-15', (string) $gate_results_json, 'Expected accepted historical dates to be persisted in gate results.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Retention_Cleanup_Service blocks active deletes for non-accepted coverage gaps', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = (object) ['prefix' => 'wp_'];
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_deleted_transients'] = [];
    $GLOBALS['kiwi_test_options'] = [
        'kiwi_retention_settings' => [
            'landing_page_sessions' => [
                'enabled' => true,
                'dry_run' => false,
                'retention_days' => 14,
            ],
        ],
    ];

    $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
    $snapshots = new Kiwi_Test_Retention_Table_Growth_Snapshot_Repository();
    $archive = new Kiwi_Test_Retention_Sqlite_Archive_Service();
    $gate = new Kiwi_Test_Retention_Coverage_Gate([
        'status' => 'failed',
        'main_summary' => [
            'accepted_missing_dates' => [],
            'blocking_missing_dates' => ['2026-06-18'],
        ],
    ]);
    $service = new Kiwi_Test_Retention_Cleanup_Service(
        new Kiwi_Config(),
        new Kiwi_Retention_Source_Registry(),
        $runs,
        $snapshots,
        $archive,
        $gate
    );
    $service->eligible_rows = 5;

    $result = $service->run_source('landing_page_sessions', 'manual');

    kiwi_assert_same('skipped', $result['status'], 'Expected active cleanup to skip deletion when future coverage is missing.');
    kiwi_assert_same('coverage_gate_failed', $result['error_code'], 'Expected coverage gate skip to be explicit.');
    kiwi_assert_same([], $archive->calls, 'Expected failed coverage gate not to archive rows.');
    kiwi_assert_same(['count'], $service->events, 'Expected failed coverage gate not to delete rows.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Retention_Cleanup_Service archives before deleting active landing-page-session rows', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = (object) ['prefix' => 'wp_'];
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_deleted_transients'] = [];
    $GLOBALS['kiwi_test_options'] = [
        'kiwi_retention_settings' => [
            'landing_page_sessions' => [
                'enabled' => true,
                'dry_run' => false,
                'retention_days' => 14,
            ],
        ],
    ];

    $events = [];
    $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
    $snapshots = new Kiwi_Test_Retention_Table_Growth_Snapshot_Repository();
    $archive = new Kiwi_Test_Retention_Sqlite_Archive_Service();
    $archive->events =& $events;
    $archive->result = [
        'success' => true,
        'archive_db_path' => '/tmp/kiwi_retention_archive_2026.sqlite',
        'archived_rows' => 3,
        'archive_inserted_rows' => 2,
        'archive_duplicate_rows' => 1,
        'archive_integrity_check' => 'ok',
    ];
    $archive->archived_primary_keys = [101, 102, 103];
    $gate = new Kiwi_Test_Retention_Coverage_Gate(['status' => 'passed']);
    $service = new Kiwi_Test_Retention_Cleanup_Service(
        new Kiwi_Config(),
        new Kiwi_Retention_Source_Registry(),
        $runs,
        $snapshots,
        $archive,
        $gate
    );
    $service->events =& $events;
    $service->eligible_rows = 3;
    $service->delete_result = ['deleted_rows' => 3, 'delete_batches' => 1];

    $result = $service->run_source('landing_page_sessions', 'wp_cli');
    $run_row = $runs->rows[1] ?? [];

    kiwi_assert_same('success', $result['status'], 'Expected active cleanup to succeed when archive and gates pass.');
    kiwi_assert_same(['count', 'archive', 'delete'], $events, 'Expected cleanup to archive rows before deleting them.');
    kiwi_assert_same([101, 102, 103], $service->deleted_primary_keys, 'Expected cleanup to delete only rows proven archived by primary key.');
    kiwi_assert_same(3, $run_row['archived_rows'] ?? 0, 'Expected archived row count to be persisted.');
    kiwi_assert_same(3, $run_row['deleted_rows'] ?? 0, 'Expected deleted row count to be persisted.');
    kiwi_assert_same('ok', $run_row['archive_integrity_check'] ?? '', 'Expected archive integrity result to be persisted.');
    kiwi_assert_same(['before_cleanup', 'after_cleanup'], array_column($snapshots->snapshots, 'snapshot_phase'), 'Expected active cleanup to capture before/after snapshots.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Retention_Cleanup_Service reports final audit update failures after active deletes', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = (object) ['prefix' => 'wp_'];
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_deleted_transients'] = [];
    $GLOBALS['kiwi_test_options'] = [
        'kiwi_retention_settings' => [
            'landing_page_sessions' => [
                'enabled' => true,
                'dry_run' => false,
                'retention_days' => 14,
            ],
        ],
    ];

    $events = [];
    $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
    $runs->update_run_result = false;
    $snapshots = new Kiwi_Test_Retention_Table_Growth_Snapshot_Repository();
    $archive = new Kiwi_Test_Retention_Sqlite_Archive_Service();
    $archive->events =& $events;
    $archive->result = [
        'success' => true,
        'archive_db_path' => '/tmp/kiwi_retention_archive_2026.sqlite',
        'archived_rows' => 3,
        'archive_inserted_rows' => 3,
        'archive_duplicate_rows' => 0,
        'archive_integrity_check' => 'ok',
    ];
    $archive->archived_primary_keys = [301, 302, 303];
    $gate = new Kiwi_Test_Retention_Coverage_Gate(['status' => 'passed']);
    $service = new Kiwi_Test_Retention_Cleanup_Service(
        new Kiwi_Config(),
        new Kiwi_Retention_Source_Registry(),
        $runs,
        $snapshots,
        $archive,
        $gate
    );
    $service->events =& $events;
    $service->eligible_rows = 3;
    $service->delete_result = ['deleted_rows' => 3, 'delete_batches' => 1];

    $result = $service->run_source('landing_page_sessions', 'wp_cli');
    $run_row = $runs->rows[1] ?? [];

    kiwi_assert_same(false, $result['success'], 'Expected cleanup result to fail when final audit update is not persisted.');
    kiwi_assert_same('failed', $result['status'], 'Expected audit update failure to mark the returned cleanup result failed.');
    kiwi_assert_same('run_audit_update_failed', $result['error_code'], 'Expected audit update persistence failures to be explicit.');
    kiwi_assert_same(false, $result['audit_persisted'] ?? true, 'Expected returned result to expose the missing audit persistence.');
    kiwi_assert_same('success', $result['cleanup_status_before_audit_failure'] ?? '', 'Expected returned result to retain the cleanup outcome before the audit failure.');
    kiwi_assert_same(3, $result['archived_rows'] ?? 0, 'Expected archive count to remain visible when the audit update fails.');
    kiwi_assert_same(3, $result['deleted_rows'] ?? 0, 'Expected delete count to remain visible when the audit update fails.');
    kiwi_assert_same(['count', 'archive', 'delete'], $events, 'Expected audit update failure to happen only after archive and delete work completed.');
    kiwi_assert_same([301, 302, 303], $service->deleted_primary_keys, 'Expected cleanup to still delete only archived primary keys before reporting audit failure.');
    kiwi_assert_same('skipped', $run_row['status'] ?? '', 'Expected failed audit update not to mutate the stored initial audit row in the test double.');
    kiwi_assert_same(1, count($runs->updates), 'Expected cleanup to attempt the final audit update exactly once.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Retention_Cleanup_Service fails when archived primary-key deletes do not match', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = (object) ['prefix' => 'wp_'];
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_deleted_transients'] = [];
    $GLOBALS['kiwi_test_options'] = [
        'kiwi_retention_settings' => [
            'landing_page_sessions' => [
                'enabled' => true,
                'dry_run' => false,
                'retention_days' => 14,
            ],
        ],
    ];

    $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
    $snapshots = new Kiwi_Test_Retention_Table_Growth_Snapshot_Repository();
    $archive = new Kiwi_Test_Retention_Sqlite_Archive_Service();
    $archive->result = [
        'success' => true,
        'archive_db_path' => '/tmp/kiwi_retention_archive_2026.sqlite',
        'archived_rows' => 2,
        'archive_inserted_rows' => 2,
        'archive_duplicate_rows' => 0,
        'archive_integrity_check' => 'ok',
    ];
    $archive->archived_primary_keys = [201, 202];
    $gate = new Kiwi_Test_Retention_Coverage_Gate(['status' => 'passed']);
    $service = new Kiwi_Test_Retention_Cleanup_Service(
        new Kiwi_Config(),
        new Kiwi_Retention_Source_Registry(),
        $runs,
        $snapshots,
        $archive,
        $gate
    );
    $service->eligible_rows = 2;
    $service->delete_result = ['deleted_rows' => 1, 'delete_batches' => 1];

    $result = $service->run_source('landing_page_sessions', 'manual');

    kiwi_assert_same(false, $result['success'], 'Expected cleanup to fail when not every archived primary key is deleted.');
    kiwi_assert_same('failed', $result['status'], 'Expected partial archived-ID delete to mark the run failed.');
    kiwi_assert_same('delete_count_mismatch', $result['error_code'], 'Expected partial archived-ID delete to expose a count mismatch.');
    kiwi_assert_same([201, 202], $service->deleted_primary_keys, 'Expected cleanup to attempt deletion by archived primary keys only.');
    kiwi_assert_same(1, $runs->rows[1]['deleted_rows'] ?? 0, 'Expected partial delete count to be audited.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Plugin backfills legacy Android version before dropping old columns', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new class {
        public $prefix = 'abc_';
        public $queries = [];
        public $columns = [
            'abc_kiwi_sales' => [
                'android_version' => true,
                'os' => true,
                'os_version' => true,
            ],
            'abc_kiwi_landing_funnel_daily_summary' => [
                'android_version' => true,
                'os' => true,
                'os_version' => true,
            ],
        ];

        public function prepare($query, ...$args)
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }

            return [
                'query' => (string) $query,
                'args' => $args,
            ];
        }

        public function get_var($statement)
        {
            $query = is_array($statement) ? (string) ($statement['query'] ?? '') : (string) $statement;
            $args = is_array($statement) ? (array) ($statement['args'] ?? []) : [];

            if (preg_match('/SHOW COLUMNS FROM ([A-Za-z0-9_]+) LIKE %s/', $query, $matches) !== 1) {
                return null;
            }

            $table_name = (string) ($matches[1] ?? '');
            $column_name = (string) ($args[0] ?? '');

            return !empty($this->columns[$table_name][$column_name]) ? $column_name : null;
        }

        public function query($statement)
        {
            $query = is_array($statement) ? (string) ($statement['query'] ?? '') : (string) $statement;
            $this->queries[] = $query;

            if (preg_match('/ALTER TABLE ([A-Za-z0-9_]+) DROP COLUMN ([A-Za-z0-9_]+)/', $query, $matches) === 1) {
                unset($this->columns[(string) ($matches[1] ?? '')][(string) ($matches[2] ?? '')]);
            }

            return 1;
        }
    };

    $plugin = new Kiwi_Test_Plugin_Device_Dimension_Migration(dirname(__DIR__), 'https://example.test/plugin/');
    $plugin->run_device_dimension_migration_for_test();
    $sql = implode("\n", $wpdb->queries);
    $sales_update_position = strpos($sql, 'UPDATE abc_kiwi_sales');
    $sales_drop_position = strpos($sql, 'ALTER TABLE abc_kiwi_sales DROP COLUMN android_version');
    $summary_update_position = strpos($sql, 'UPDATE abc_kiwi_landing_funnel_daily_summary');
    $summary_drop_position = strpos($sql, 'ALTER TABLE abc_kiwi_landing_funnel_daily_summary DROP COLUMN android_version');

    kiwi_assert_true(is_int($sales_update_position), 'Expected sales migration to backfill from legacy android_version before dropping it.');
    kiwi_assert_true(is_int($summary_update_position), 'Expected summary migration to backfill from legacy android_version before dropping it.');
    kiwi_assert_true(is_int($sales_drop_position) && $sales_drop_position > $sales_update_position, 'Expected sales legacy column drop to run after backfill.');
    kiwi_assert_true(is_int($summary_drop_position) && $summary_drop_position > $summary_update_position, 'Expected summary legacy column drop to run after backfill.');
    kiwi_assert_contains("THEN 'Android'", $sql, 'Expected non-empty legacy Android buckets to backfill os=Android.');
    kiwi_assert_contains("REGEXP '^[1-9][0-9]?([._][0-9]+)*$'", $sql, 'Expected legacy Android version backfill to accept only numeric major/dotted values.');
    kiwi_assert_contains("SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(COALESCE(android_version, '')), '.', 1), '_', 1)", $sql, 'Expected dotted legacy versions such as 16.0.0 to backfill as major-only buckets.');
    kiwi_assert_contains("WHEN TRIM(COALESCE(android_version, '')) <> '' AND TRIM(COALESCE(android_version, '')) <> '(unknown)' THEN '(unknown)'", $sql, 'Expected non-numeric legacy Android versions to backfill as unknown os_version.');
    kiwi_assert_true(strpos($sql, 'os_version = android_version') === false, 'Expected migration not to blindly copy diluted legacy Android version values.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Plugin consolidates and drops retired main daily summary columns during schema migration', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new class {
        public $prefix = 'abc_';
        public $queries = [];
        public $columns = [
            'abc_kiwi_landing_funnel_daily_summary' => [
                'tkzone' => true,
                'median_hidden_seconds' => true,
            ],
        ];

        public function prepare($query, ...$args)
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }

            return [
                'query' => (string) $query,
                'args' => $args,
            ];
        }

        public function get_var($statement)
        {
            $query = is_array($statement) ? (string) ($statement['query'] ?? '') : (string) $statement;
            $args = is_array($statement) ? (array) ($statement['args'] ?? []) : [];

            if (preg_match('/SHOW COLUMNS FROM ([A-Za-z0-9_]+) LIKE %s/', $query, $matches) !== 1) {
                return null;
            }

            $table_name = (string) ($matches[1] ?? '');
            $column_name = (string) ($args[0] ?? '');

            return !empty($this->columns[$table_name][$column_name]) ? $column_name : null;
        }

        public function query($statement)
        {
            $query = is_array($statement) ? (string) ($statement['query'] ?? '') : (string) $statement;
            $this->queries[] = $query;

            if (preg_match('/ALTER TABLE ([A-Za-z0-9_]+) DROP COLUMN ([A-Za-z0-9_]+)/', $query, $matches) === 1) {
                unset($this->columns[(string) ($matches[1] ?? '')][(string) ($matches[2] ?? '')]);
            }

            return 1;
        }
    };

    $plugin = new Kiwi_Test_Plugin_Device_Dimension_Migration(dirname(__DIR__), 'https://example.test/plugin/');
    $plugin->run_slim_daily_summary_migration_for_test();
    $sql = implode("\n", $wpdb->queries);

    $rollup_position = strpos($sql, 'CREATE TEMPORARY TABLE abc_kiwi_landing_funnel_daily_summary_slim_rollup_tmp AS');
    $delete_position = strpos($sql, 'DELETE FROM abc_kiwi_landing_funnel_daily_summary');
    $insert_position = strpos($sql, 'INSERT INTO abc_kiwi_landing_funnel_daily_summary (metric_date, landing_key, service_key, provider_key, flow_key, country, pid, tksource, device_brand, os, os_version, browser, client_ip_version, client_ip_prefix, dimension_hash');
    $drop_tkzone_position = strpos($sql, 'ALTER TABLE abc_kiwi_landing_funnel_daily_summary DROP COLUMN tkzone');

    kiwi_assert_true(is_int($rollup_position), 'Expected migration to roll existing rows into a slim-dimension temporary table before dropping tkzone.');
    kiwi_assert_true(is_int($delete_position) && $delete_position > $rollup_position, 'Expected migration to clear old rows only after creating the slim rollup table.');
    kiwi_assert_true(is_int($insert_position) && $insert_position > $delete_position, 'Expected migration to restore consolidated slim rows before dropping retired columns.');
    kiwi_assert_true(is_int($drop_tkzone_position) && $drop_tkzone_position > $insert_position, 'Expected migration to drop tkzone only after existing rows were consolidated.');
    kiwi_assert_contains("SHA2(CONCAT_WS('|',", $sql, 'Expected migration to recompute the slim summary dimension hash.');
    kiwi_assert_contains('GROUP BY metric_date, landing_key, service_key, provider_key, flow_key, country, pid, tksource, device_brand, os, os_version, browser, client_ip_version, client_ip_prefix', $sql, 'Expected migration to consolidate rows by the slim main summary dimensions.');
    kiwi_assert_contains('SUM(sessions) AS sessions', $sql, 'Expected migration to preserve session totals when rolling up legacy tkzone-split rows.');
    kiwi_assert_contains('SUM(handoff_attempts) AS handoff_attempts', $sql, 'Expected migration to preserve handoff totals when rolling up legacy tkzone-split rows.');
    kiwi_assert_contains('WHEN SUM(handoff_attempts) <= 0 THEN 0', $sql, 'Expected migration to recalculate handoff rate from rolled-up totals.');
    kiwi_assert_contains('START TRANSACTION', $sql, 'Expected migration to protect the delete/reinsert rollup with a transaction.');
    kiwi_assert_contains('COMMIT', $sql, 'Expected migration to commit the successful summary rollup before dropping columns.');
    kiwi_assert_contains('ALTER TABLE abc_kiwi_landing_funnel_daily_summary DROP COLUMN tkzone', $sql, 'Expected migration to drop retired tkzone from the main summary table.');
    kiwi_assert_contains('ALTER TABLE abc_kiwi_landing_funnel_daily_summary DROP COLUMN median_hidden_seconds', $sql, 'Expected migration to drop retired hidden median from the main summary table.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Plugin keeps retired main daily summary columns when slim rollup fails', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new class {
        public $prefix = 'abc_';
        public $queries = [];
        public $columns = [
            'abc_kiwi_landing_funnel_daily_summary' => [
                'tkzone' => true,
                'median_hidden_seconds' => true,
            ],
        ];

        public function prepare($query, ...$args)
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }

            return [
                'query' => (string) $query,
                'args' => $args,
            ];
        }

        public function get_var($statement)
        {
            $query = is_array($statement) ? (string) ($statement['query'] ?? '') : (string) $statement;
            $args = is_array($statement) ? (array) ($statement['args'] ?? []) : [];

            if (preg_match('/SHOW COLUMNS FROM ([A-Za-z0-9_]+) LIKE %s/', $query, $matches) !== 1) {
                return null;
            }

            $table_name = (string) ($matches[1] ?? '');
            $column_name = (string) ($args[0] ?? '');

            return !empty($this->columns[$table_name][$column_name]) ? $column_name : null;
        }

        public function query($statement)
        {
            $query = is_array($statement) ? (string) ($statement['query'] ?? '') : (string) $statement;
            $this->queries[] = $query;

            if (strpos($query, 'CREATE TEMPORARY TABLE abc_kiwi_landing_funnel_daily_summary_slim_rollup_tmp AS') === 0) {
                return false;
            }

            if (preg_match('/ALTER TABLE ([A-Za-z0-9_]+) DROP COLUMN ([A-Za-z0-9_]+)/', $query, $matches) === 1) {
                unset($this->columns[(string) ($matches[1] ?? '')][(string) ($matches[2] ?? '')]);
            }

            return 1;
        }
    };

    $plugin = new Kiwi_Test_Plugin_Device_Dimension_Migration(dirname(__DIR__), 'https://example.test/plugin/');
    $plugin->run_slim_daily_summary_migration_for_test();
    $sql = implode("\n", $wpdb->queries);

    kiwi_assert_contains('CREATE TEMPORARY TABLE abc_kiwi_landing_funnel_daily_summary_slim_rollup_tmp AS', $sql, 'Expected migration to attempt the slim rollup before deciding whether to drop retired columns.');
    kiwi_assert_contains('ROLLBACK', $sql, 'Expected migration to roll back when slim summary consolidation fails.');
    kiwi_assert_true(strpos($sql, 'DELETE FROM abc_kiwi_landing_funnel_daily_summary') === false, 'Expected migration not to clear existing rows after a failed slim rollup.');
    kiwi_assert_true(strpos($sql, 'INSERT INTO abc_kiwi_landing_funnel_daily_summary') === false, 'Expected migration not to reinsert rollup rows after a failed slim rollup.');
    kiwi_assert_true(strpos($sql, 'ALTER TABLE abc_kiwi_landing_funnel_daily_summary DROP COLUMN tkzone') === false, 'Expected migration to keep tkzone when row consolidation fails.');
    kiwi_assert_true(strpos($sql, 'ALTER TABLE abc_kiwi_landing_funnel_daily_summary DROP COLUMN median_hidden_seconds') === false, 'Expected migration to keep median_hidden_seconds when row consolidation fails.');

    $wpdb = $previous_wpdb;
});

kiwi_run_test('Kiwi_Plugin bumps schema version for slim main daily summary contract', function (): void {
    $reflection = new ReflectionClass(Kiwi_Plugin::class);
    $schema_option = (string) $reflection->getConstant('DB_SCHEMA_VERSION_OPTION');
    $schema_version = (string) $reflection->getConstant('DB_SCHEMA_VERSION');

    $GLOBALS['kiwi_test_options'] = [
        $schema_option => '2026-05-19-1',
    ];

    $plugin = new Kiwi_Test_Plugin_Performance_Gates(dirname(__DIR__), 'https://example.test/plugin/');
    $plugin->ensure_click_attribution_table();

    kiwi_assert_same('2026-06-27-1', $schema_version, 'Expected schema version to be bumped for the TK-zone PID-set hash schema.');
    kiwi_assert_same(1, $plugin->schema_migration_runs, 'Expected stored pre-sales-snapshot schema version to rerun dbDelta migrations.');
    kiwi_assert_same(
        $schema_version,
        $GLOBALS['kiwi_test_options'][$schema_option] ?? '',
        'Expected schema migration rerun to persist the bumped schema version.'
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

kiwi_run_test('Kiwi_Plugin schedules the landing funnel daily summary cron hook once', function (): void {
    $GLOBALS['kiwi_test_hooks'] = [];
    $GLOBALS['kiwi_test_cron_events'] = [];
    $GLOBALS['kiwi_test_next_scheduled'] = [];

    $reflection = new ReflectionClass(Kiwi_Plugin::class);
    $hook = (string) $reflection->getConstant('LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_HOOK');

    $plugin = new Kiwi_Test_Plugin_Performance_Gates(dirname(__DIR__), 'https://example.test/plugin/');
    $plugin->register();
    $plugin->schedule_landing_funnel_daily_summary_refresh();
    $plugin->schedule_landing_funnel_daily_summary_refresh();

    kiwi_assert_true(isset($GLOBALS['kiwi_test_hooks'][$hook]), 'Expected the daily summary cron hook to be registered.');
    kiwi_assert_same(1, count($GLOBALS['kiwi_test_cron_events']), 'Expected daily summary refresh to be scheduled only when no event exists.');
    kiwi_assert_same('hourly', $GLOBALS['kiwi_test_cron_events'][0]['recurrence'] ?? '', 'Expected daily summary refresh to use the documented hourly interval.');
    kiwi_assert_same($hook, $GLOBALS['kiwi_test_cron_events'][0]['hook'] ?? '', 'Expected scheduled event to use the daily summary refresh hook.');
});

kiwi_run_test('Kiwi_Plugin runs landing funnel daily summary refresh for the default rolling window', function (): void {
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_deleted_transients'] = [];
    $GLOBALS['kiwi_test_options'] = [];

    $reflection = new ReflectionClass(Kiwi_Plugin::class);
    $lock_key = (string) $reflection->getConstant('LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_LOCK_KEY');
    $last_result_option = (string) $reflection->getConstant('LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_LAST_RESULT_OPTION');
    $service = new Kiwi_Test_Landing_Funnel_Daily_Summary_Refresh_Service([
        'success' => true,
        'from_date' => '2026-05-19',
        'to_date' => '2026-05-26',
        'deleted' => 4,
        'inserted' => 6,
        'error' => '',
    ]);
    $tkzone_service = new Kiwi_Test_Landing_Funnel_Daily_Tkzone_Summary_Refresh_Service([
        'success' => true,
        'from_date' => '2026-05-19',
        'to_date' => '2026-05-26',
        'deleted' => 1,
        'inserted' => 2,
        'error' => '',
    ]);
    $plugin = new Kiwi_Test_Plugin_Landing_Funnel_Daily_Summary_Refresh($service, $tkzone_service);

    $result = $plugin->run_landing_funnel_daily_summary_refresh();

    kiwi_assert_true($result['success'], 'Expected the daily summary refresh wrapper to return the service success.');
    kiwi_assert_same([['2026-05-19', '2026-05-26']], $service->calls, 'Expected default refresh window to cover today minus seven lookback days through today.');
    kiwi_assert_same([['2026-05-19', '2026-05-26']], $tkzone_service->calls, 'Expected tkzone summary refresh to use the same rolling window.');
    kiwi_assert_same(5, $result['deleted'], 'Expected combined refresh result to expose deleted rows across both summaries.');
    kiwi_assert_same(8, $result['inserted'], 'Expected combined refresh result to expose inserted rows across both summaries.');
    kiwi_assert_same(4, $result['summaries']['main']['deleted'] ?? null, 'Expected persisted result to keep main summary refresh counters.');
    kiwi_assert_same(2, $result['summaries']['tkzone']['inserted'] ?? null, 'Expected persisted result to keep tkzone summary refresh counters.');
    kiwi_assert_true(!isset($GLOBALS['kiwi_test_transients'][$lock_key]), 'Expected daily summary refresh lock to be cleared after normal completion.');
    kiwi_assert_same([$lock_key], $GLOBALS['kiwi_test_deleted_transients'], 'Expected normal completion to explicitly delete the refresh lock.');
    kiwi_assert_same($result, $GLOBALS['kiwi_test_options'][$last_result_option] ?? null, 'Expected last refresh result to be persisted as an option.');
    kiwi_assert_contains('Refresh succeeded for 2026-05-19 to 2026-05-26', implode("\n", $plugin->logs), 'Expected successful refresh to be visibly logged.');
});

kiwi_run_test('Kiwi_Plugin keeps a prior-day carryover when daily summary refresh days is zero', function (): void {
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_deleted_transients'] = [];
    $GLOBALS['kiwi_test_options'] = [];

    $service = new Kiwi_Test_Landing_Funnel_Daily_Summary_Refresh_Service([
        'success' => true,
        'from_date' => '2026-05-25',
        'to_date' => '2026-05-26',
        'deleted' => 2,
        'inserted' => 3,
        'error' => '',
    ]);
    $plugin = new Kiwi_Test_Plugin_Landing_Funnel_Daily_Summary_Refresh($service);
    $plugin->refresh_days = 0;

    $result = $plugin->run_landing_funnel_daily_summary_refresh();

    kiwi_assert_true($result['success'], 'Expected zero-lookback refresh with handoff carryover to succeed.');
    kiwi_assert_same([['2026-05-25', '2026-05-26']], $service->calls, 'Expected hourly zero-lookback refresh to include yesterday so cross-midnight handoff completions update the first-handoff day.');
    kiwi_assert_same('2026-05-25', $result['from_date'], 'Expected persisted result to expose the effective carryover start date.');
    kiwi_assert_contains('Refresh succeeded for 2026-05-25 to 2026-05-26', implode("\n", $plugin->logs), 'Expected carryover refresh range to be visible in logs.');
});

kiwi_run_test('Kiwi_Plugin skips landing funnel daily summary refresh while lock is active', function (): void {
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_deleted_transients'] = [];
    $GLOBALS['kiwi_test_options'] = [];

    $reflection = new ReflectionClass(Kiwi_Plugin::class);
    $lock_key = (string) $reflection->getConstant('LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_LOCK_KEY');
    $last_result_option = (string) $reflection->getConstant('LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_LAST_RESULT_OPTION');
    $GLOBALS['kiwi_test_transients'][$lock_key] = '1';
    $service = new Kiwi_Test_Landing_Funnel_Daily_Summary_Refresh_Service([
        'success' => true,
        'from_date' => '2026-05-19',
        'to_date' => '2026-05-26',
        'deleted' => 4,
        'inserted' => 6,
        'error' => '',
    ]);
    $plugin = new Kiwi_Test_Plugin_Landing_Funnel_Daily_Summary_Refresh($service);

    $result = $plugin->run_landing_funnel_daily_summary_refresh();

    kiwi_assert_true($result['skipped_due_to_lock'], 'Expected active lock to produce an explicit skip result.');
    kiwi_assert_same([], $service->calls, 'Expected locked refresh not to call the aggregation service.');
    kiwi_assert_same('1', $GLOBALS['kiwi_test_transients'][$lock_key] ?? '', 'Expected existing lock to remain untouched on skip.');
    kiwi_assert_same($result, $GLOBALS['kiwi_test_options'][$last_result_option] ?? null, 'Expected lock skip result to be persisted as the latest operational state.');
    kiwi_assert_contains('lock is active', implode("\n", $plugin->logs), 'Expected lock skip to be visibly logged.');
});

kiwi_run_test('Kiwi_Plugin persists landing funnel daily summary refresh failures', function (): void {
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_deleted_transients'] = [];
    $GLOBALS['kiwi_test_options'] = [];

    $reflection = new ReflectionClass(Kiwi_Plugin::class);
    $lock_key = (string) $reflection->getConstant('LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_LOCK_KEY');
    $last_result_option = (string) $reflection->getConstant('LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_LAST_RESULT_OPTION');
    $service = new Kiwi_Test_Landing_Funnel_Daily_Summary_Refresh_Service([
        'success' => false,
        'from_date' => '2026-05-19',
        'to_date' => '2026-05-26',
        'deleted' => 0,
        'inserted' => 0,
        'error' => 'summary insert failed',
    ], 'summary insert failed');
    $plugin = new Kiwi_Test_Plugin_Landing_Funnel_Daily_Summary_Refresh($service);

    $result = $plugin->run_landing_funnel_daily_summary_refresh();

    kiwi_assert_true(!$result['success'], 'Expected failed aggregation service result to remain failed.');
    kiwi_assert_same('main: summary insert failed', $result['error'], 'Expected failed refresh error to name the failed summary.');
    kiwi_assert_true(!isset($GLOBALS['kiwi_test_transients'][$lock_key]), 'Expected refresh lock to be cleared after failed service completion.');
    kiwi_assert_same($result, $GLOBALS['kiwi_test_options'][$last_result_option] ?? null, 'Expected failed refresh result to be persisted for operations.');
    kiwi_assert_contains('Refresh failed for 2026-05-19 to 2026-05-26: main: summary insert failed', implode("\n", $plugin->logs), 'Expected failed refresh to be visibly logged.');
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

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Shortcode defaults to flagged only on initial load', function (): void {
    $_POST = [];
    $_GET = [];

    $repository = new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository();
    $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_default',
        'source_event_key' => 'row-default-flagged',
        'identity_type' => 'session',
        'identity_value' => 'session-default-flagged',
        'occurred_at' => '2026-04-01 12:00:00',
        'count_1h' => 3,
        'count_24h' => 3,
        'count_total' => 3,
        'is_soft_flag' => true,
        'soft_flag_reason' => 'count_1h>=3',
    ]);
    $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_default',
        'source_event_key' => 'row-default-unflagged',
        'identity_type' => 'session',
        'identity_value' => 'session-default-unflagged',
        'occurred_at' => '2026-04-01 12:01:00',
        'count_1h' => 1,
        'count_24h' => 1,
        'count_total' => 1,
        'is_soft_flag' => false,
        'soft_flag_reason' => '',
    ]);

    $shortcode = new Kiwi_Premium_Sms_Fraud_Shortcode($repository, null, new Kiwi_Frontend_Auth_Gate());
    $output = $shortcode->render();

    kiwi_assert_contains('name="kiwi_fraud_filters_applied" value="1"', $output, 'Expected fraud filters form to mark submitted filter requests.');
    kiwi_assert_contains('name="kiwi_fraud_flagged_only" value="1" checked="checked"', $output, 'Expected Flagged only to be checked by default.');
    kiwi_assert_contains('session-default-flagged', $output, 'Expected flagged row to remain visible on initial load.');
    kiwi_assert_true(strpos($output, 'session-default-unflagged') === false, 'Expected initial load to hide unflagged rows.');

    $_GET = [];
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Shortcode allows submitted filters to disable flagged only', function (): void {
    $_POST = [];
    $_GET = [
        'kiwi_fraud_filters_applied' => '1',
    ];

    $repository = new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository();
    $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_unchecked',
        'source_event_key' => 'row-unchecked-flagged',
        'identity_type' => 'session',
        'identity_value' => 'session-unchecked-flagged',
        'occurred_at' => '2026-04-01 12:00:00',
        'count_1h' => 3,
        'count_24h' => 3,
        'count_total' => 3,
        'is_soft_flag' => true,
        'soft_flag_reason' => 'count_1h>=3',
    ]);
    $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_unchecked',
        'source_event_key' => 'row-unchecked-unflagged',
        'identity_type' => 'session',
        'identity_value' => 'session-unchecked-unflagged',
        'occurred_at' => '2026-04-01 12:01:00',
        'count_1h' => 1,
        'count_24h' => 1,
        'count_total' => 1,
        'is_soft_flag' => false,
        'soft_flag_reason' => '',
    ]);

    $shortcode = new Kiwi_Premium_Sms_Fraud_Shortcode($repository, null, new Kiwi_Frontend_Auth_Gate());
    $output = $shortcode->render();

    kiwi_assert_true(strpos($output, 'name="kiwi_fraud_flagged_only" value="1" checked="checked"') === false, 'Expected submitted unchecked Flagged only filter to stay unchecked.');
    kiwi_assert_contains('session-unchecked-flagged', $output, 'Expected flagged row to remain visible when filter is disabled.');
    kiwi_assert_contains('session-unchecked-unflagged', $output, 'Expected submitted unchecked filter to show unflagged rows.');

    $_GET = [];
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Shortcode honors explicit flagged only deep-link values', function (): void {
    $_POST = [];
    $_GET = [
        'kiwi_fraud_flagged_only' => '0',
    ];

    $repository = new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository();
    $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_deeplink',
        'source_event_key' => 'row-deeplink-flagged',
        'identity_type' => 'session',
        'identity_value' => 'session-deeplink-flagged',
        'occurred_at' => '2026-04-01 12:00:00',
        'count_1h' => 3,
        'count_24h' => 3,
        'count_total' => 3,
        'is_soft_flag' => true,
        'soft_flag_reason' => 'count_1h>=3',
    ]);
    $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_deeplink',
        'source_event_key' => 'row-deeplink-unflagged',
        'identity_type' => 'session',
        'identity_value' => 'session-deeplink-unflagged',
        'occurred_at' => '2026-04-01 12:01:00',
        'count_1h' => 1,
        'count_24h' => 1,
        'count_total' => 1,
        'is_soft_flag' => false,
        'soft_flag_reason' => '',
    ]);

    $shortcode = new Kiwi_Premium_Sms_Fraud_Shortcode($repository, null, new Kiwi_Frontend_Auth_Gate());
    $output = $shortcode->render();

    kiwi_assert_true(strpos($output, 'name="kiwi_fraud_flagged_only" value="1" checked="checked"') === false, 'Expected explicit deep-link value 0 to keep Flagged only unchecked.');
    kiwi_assert_contains('session-deeplink-flagged', $output, 'Expected flagged row to remain visible with explicit deep-link value 0.');
    kiwi_assert_contains('session-deeplink-unflagged', $output, 'Expected explicit deep-link value 0 to show unflagged rows.');

    $_GET = [];
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
        'tksource' => 'source-fast',
        'tkzone' => 'zone-fast',
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
    kiwi_assert_contains('<th>TK Source</th>', $output, 'Expected engagement table to include TK Source column.');
    kiwi_assert_contains('<th>TK Zone</th>', $output, 'Expected engagement table to include TK Zone column.');
    kiwi_assert_contains('<th>Delta (Load->First CTA)</th>', $output, 'Expected engagement table to include load-to-first-CTA delta column.');
    kiwi_assert_contains('<th>Soft Flag</th>', $output, 'Expected engagement table to include soft-flag column.');
    kiwi_assert_contains('<th>Reason</th>', $output, 'Expected engagement table to include reason column.');
    kiwi_assert_contains('pid-fast', $output, 'Expected engagement rows to render persisted pid values.');
    kiwi_assert_contains('click-fast', $output, 'Expected engagement rows to render persisted click_id values.');
    kiwi_assert_contains('source-fast', $output, 'Expected engagement rows to render persisted tksource values.');
    kiwi_assert_contains('zone-fast', $output, 'Expected engagement rows to render persisted tkzone values.');
    kiwi_assert_contains('0s', $output, 'Expected engagement table to render computed delta in seconds when both timestamps are present.');
    kiwi_assert_contains('fast_click', $output, 'Expected engagement soft-flag reason fast_click to be rendered.');
    kiwi_assert_contains('sess-fast-flagged', $output, 'Expected flagged engagement row to be present.');
    kiwi_assert_true(strpos($output, 'sess-normal-unflagged') === false, 'Expected flagged_only filter to hide non-flagged engagement rows.');

    $_GET = [];
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Shortcode renders persisted flagged engagement rows beyond raw candidate window', function (): void {
    $_POST = [];
    $_GET = [
        'kiwi_fraud_flagged_only' => '1',
        'kiwi_fraud_limit' => '100',
    ];

    $fraud_repository = new Kiwi_Test_Premium_Sms_Fraud_Signal_Repository();
    $engagement_repository = new Kiwi_Test_Premium_Sms_Landing_Engagement_Repository();

    $engagement_repository->upsert_event([
        'landing_key' => 'lp5-fr',
        'session_token' => 'older-flagged-fast-click',
        'service_key' => 'nth_fr_one_off_jplay',
        'provider_key' => 'nth',
        'flow_key' => 'nth-fr-one-off',
    ], 'page_loaded', '2026-04-01 11:59:00');
    $engagement_repository->upsert_event([
        'landing_key' => 'lp5-fr',
        'session_token' => 'older-flagged-fast-click',
        'service_key' => 'nth_fr_one_off_jplay',
        'provider_key' => 'nth',
        'flow_key' => 'nth-fr-one-off',
    ], 'cta_click', '2026-04-01 11:59:00');

    for ($index = 1; $index <= 520; $index++) {
        $session_token = 'newer-unflagged-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT);
        $engagement_repository->upsert_event([
            'landing_key' => 'lp5-fr',
            'session_token' => $session_token,
            'service_key' => 'nth_fr_one_off_jplay',
            'provider_key' => 'nth',
            'flow_key' => 'nth-fr-one-off',
        ], 'page_loaded', '2026-04-01 12:00:00');
        $engagement_repository->upsert_event([
            'landing_key' => 'lp5-fr',
            'session_token' => $session_token,
            'service_key' => 'nth_fr_one_off_jplay',
            'provider_key' => 'nth',
            'flow_key' => 'nth-fr-one-off',
        ], 'cta_click', '2026-04-01 12:00:03');
    }

    $shortcode = new Kiwi_Premium_Sms_Fraud_Shortcode(
        $fraud_repository,
        new Kiwi_Test_Config(),
        new Kiwi_Frontend_Auth_Gate(),
        $engagement_repository
    );

    $output = $shortcode->render();

    kiwi_assert_contains('older-flagged-fast-click', $output, 'Expected flagged_only engagement filter to render persisted flagged rows beyond the raw candidate window.');
    kiwi_assert_contains('fast_click', $output, 'Expected older flagged engagement row to retain its computed reason.');
    kiwi_assert_true(strpos($output, 'newer-unflagged-520') === false, 'Expected flagged_only engagement filter to hide newer unflagged rows.');

    $_GET = [];
});

kiwi_run_test('Kiwi_Premium_Sms_Fraud_Shortcode renders filtered flagged rows from fraud signal storage', function (): void {
    $_POST = [];
    $_GET = [
        'kiwi_fraud_service_key' => 'svc_a',
        'kiwi_fraud_provider_key' => 'nth',
        'kiwi_fraud_pid' => 'pid-a',
        'kiwi_fraud_tksource' => 'source-a',
        'kiwi_fraud_tkzone' => 'zone-a',
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
        'tksource' => 'source-a',
        'tkzone' => 'zone-a',
        'source_event_key' => 'row-1',
        'identity_type' => 'session',
        'identity_value' => 'session-flagged-1',
        'occurred_at' => '2026-04-01 12:00:00',
        'count_1h' => 3,
        'count_24h' => 4,
        'count_total' => 4,
        'is_soft_flag' => true,
        'soft_flag_reason' => 'count_1h>=3',
        'billing_outcome' => 'failed',
        'billing_outcome_at' => '2026-04-01 16:00:00',
        'billing_transaction_id' => 42,
        'sale_id' => 0,
        'aggregator_status_code' => '-9',
        'aggregator_status_text' => 'Delivery failed',
    ]);
    $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_a',
        'flow_key' => 'flow-a',
        'pid' => 'pid-a',
        'click_id' => 'click-a',
        'tksource' => 'source-a',
        'tkzone' => 'zone-a',
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
        'tksource' => 'source-b',
        'tkzone' => 'zone-b',
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
        'tksource' => 'source-other',
        'tkzone' => 'zone-other',
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
    $repository->insert_if_new([
        'provider_key' => 'nth',
        'service_key' => 'svc_a',
        'flow_key' => 'flow-a',
        'pid' => 'pid-a',
        'click_id' => 'click-a',
        'tksource' => 'source-a',
        'tkzone' => 'zone-a',
        'source_event_key' => 'row-legacy-unknown-link',
        'identity_type' => 'session',
        'identity_value' => 'session-legacy-unknown-link',
        'occurred_at' => '2026-04-01 12:04:00',
        'count_1h' => 1,
        'count_24h' => 1,
        'count_total' => 1,
        'is_soft_flag' => true,
        'soft_flag_reason' => 'unknown_link',
    ]);

    $shortcode = new Kiwi_Premium_Sms_Fraud_Shortcode($repository, null, new Kiwi_Frontend_Auth_Gate());
    $output = $shortcode->render();

    kiwi_assert_contains('Premium SMS Fraud Monitor', $output, 'Expected fraud shortcode title to render.');
    kiwi_assert_contains('<th>PID</th>', $output, 'Expected shortcode tables to render a PID column.');
    kiwi_assert_contains('<th>Click ID</th>', $output, 'Expected shortcode tables to render a Click ID column.');
    kiwi_assert_contains('<th>TK Source</th>', $output, 'Expected shortcode tables to render a TK Source column.');
    kiwi_assert_contains('<th>TK Zone</th>', $output, 'Expected shortcode tables to render a TK Zone column.');
    kiwi_assert_contains('<th>Billing Outcome</th>', $output, 'Expected fraud table to render billing outcome column.');
    kiwi_assert_contains('<th>Aggregator Code</th>', $output, 'Expected fraud table to render aggregator status code column.');
    kiwi_assert_contains('<th>Aggregator Text</th>', $output, 'Expected fraud table to render aggregator status text column.');
    kiwi_assert_contains('click-a', $output, 'Expected filtered fraud rows to render click_id values.');
    kiwi_assert_contains('source-a', $output, 'Expected filtered fraud rows to render tksource values.');
    kiwi_assert_contains('zone-a', $output, 'Expected filtered fraud rows to render tkzone values.');
    kiwi_assert_contains('failed', $output, 'Expected filtered fraud rows to render billing outcome values.');
    kiwi_assert_contains('-9', $output, 'Expected filtered fraud rows to render aggregator status codes.');
    kiwi_assert_contains('Delivery failed', $output, 'Expected filtered fraud rows to render aggregator status text.');
    kiwi_assert_contains('session-flagged-1', $output, 'Expected filtered flagged row to be rendered.');
    kiwi_assert_true(strpos($output, 'session-not-flagged') === false, 'Expected flagged_only filter to remove non-flagged rows.');
    kiwi_assert_true(strpos($output, 'session-other-service') === false, 'Expected service_key filter to remove rows from other services.');
    kiwi_assert_true(strpos($output, 'session-other-pid') === false, 'Expected pid filter to remove flagged rows from other pid values.');
    kiwi_assert_true(strpos($output, 'session-legacy-unknown-link') === false, 'Expected flagged_only filter to remove legacy unknown_link-only rows.');
    kiwi_assert_true(strpos($output, 'unknown_link') === false, 'Expected shortcode not to render unknown_link as a soft-flag reason.');

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

kiwi_run_test('Kiwi_Config exposes generic landing UA tracking modes', function (): void {
    $config = new Kiwi_Config();
    $options = $config->get_landing_ua_tracking_mode_options();
    $invalid = new Kiwi_Test_Landing_Ua_Config('unexpected');
    $onload = new Kiwi_Test_Landing_Ua_Config('onload');

    kiwi_assert_same('onload', $config->get_landing_ua_tracking_mode(), 'Expected default UA tracking mode to capture page-load device context.');
    kiwi_assert_same(['disabled', 'onclick', 'onload'], array_keys($options), 'Expected config to expose the supported UA tracking modes for a future settings UI.');
    kiwi_assert_same('onload', $invalid->get_landing_ua_tracking_mode(), 'Expected invalid UA tracking modes to fall back to onload.');
    kiwi_assert_same('onload', $onload->get_landing_ua_tracking_mode(), 'Expected explicit onload mode to be representable in config tests.');
});

kiwi_run_test('Kiwi_Config allows disabling landing handoff UA Client Hints telemetry by constant', function (): void {
    if (!defined('KIWI_LANDING_HANDOFF_UA_CLIENT_HINTS_ENABLED')) {
        define('KIWI_LANDING_HANDOFF_UA_CLIENT_HINTS_ENABLED', false);
    }

    $config = new Kiwi_Test_Config();

    kiwi_assert_true(!$config->is_landing_handoff_ua_client_hints_enabled(), 'Expected landing handoff UA Client Hints telemetry to be disabled by constant.');
});

kiwi_run_test('Kiwi_Config exposes landing funnel daily summary refresh days', function (): void {
    $config = new Kiwi_Config();

    kiwi_assert_same(7, $config->get_landing_funnel_summary_refresh_days(), 'Expected daily summary refresh window to default to seven lookback days.');

    if (!defined('KIWI_LANDING_FUNNEL_SUMMARY_REFRESH_DAYS')) {
        define('KIWI_LANDING_FUNNEL_SUMMARY_REFRESH_DAYS', -4);
    }

    kiwi_assert_same(0, $config->get_landing_funnel_summary_refresh_days(), 'Expected negative daily summary refresh windows to be clamped to zero.');
});

kiwi_run_test('Kiwi_Config exposes tkzone summary PID allow-list', function (): void {
    $config = new Kiwi_Config();

    kiwi_assert_same(['106'], $config->get_landing_funnel_tkzone_summary_pids(), 'Expected tkzone summary PID allow-list to default to pid 106.');

    if (!defined('KIWI_LANDING_FUNNEL_TKZONE_SUMMARY_PIDS')) {
        define('KIWI_LANDING_FUNNEL_TKZONE_SUMMARY_PIDS', ['106', ' 207 ', 'bad pid', '']);
    }

    kiwi_assert_same(['106', '207', 'badpid'], $config->get_landing_funnel_tkzone_summary_pids(), 'Expected tkzone summary PID allow-list to sanitize configured PID values.');
    kiwi_assert_same(hash('sha256', '106|207|badpid'), $config->get_landing_funnel_tkzone_summary_pid_set_hash(), 'Expected tkzone summary PID-set hash to be stable over normalized PID values.');
});

kiwi_run_test('Kiwi_Config exposes device model brand harvest threshold', function (): void {
    $config = new Kiwi_Config();

    kiwi_assert_same(5, $config->get_device_model_brand_harvest_min_daily_sessions(), 'Expected device model brand harvester to default to five distinct sessions.');

    if (!defined('KIWI_DEVICE_MODEL_BRAND_HARVEST_MIN_DAILY_SESSIONS')) {
        define('KIWI_DEVICE_MODEL_BRAND_HARVEST_MIN_DAILY_SESSIONS', 0);
    }

    kiwi_assert_same(1, $config->get_device_model_brand_harvest_min_daily_sessions(), 'Expected configured harvester thresholds to be clamped to at least one.');
});
