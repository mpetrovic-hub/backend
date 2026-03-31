<?php

define('ABSPATH', __DIR__ . '/../');

$GLOBALS['kiwi_test_hooks'] = [];
$GLOBALS['kiwi_test_styles'] = [];
$GLOBALS['kiwi_test_scripts'] = [];
$GLOBALS['kiwi_test_transients'] = [];

function add_action($hook, $callback): void
{
    $GLOBALS['kiwi_test_hooks'][$hook][] = $callback;
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

require_once __DIR__ . '/../includes/core/class-config.php';
require_once __DIR__ . '/../includes/core/class-plugin.php';
require_once __DIR__ . '/../includes/exporters/class-csv-exporter.php';
require_once __DIR__ . '/../includes/providers/dimoco/class-dimoco-client.php';
require_once __DIR__ . '/../includes/services/class-msisdn-normalizer.php';
require_once __DIR__ . '/../includes/services/class-dimoco-blacklist-batch-service.php';
require_once __DIR__ . '/../includes/services/class-operator-lookup-service.php';
require_once __DIR__ . '/../includes/services/class-operator-lookup-batch-service.php';
require_once __DIR__ . '/../includes/providers/lily/class-lily-operator-lookup-provider.php';
require_once __DIR__ . '/../includes/providers/dimoco/class-dimoco-operator-lookup-provider.php';
require_once __DIR__ . '/../includes/providers/class-routed-operator-lookup-provider.php';
require_once __DIR__ . '/../includes/providers/dimoco/class-dimoco-response-parser.php';
require_once __DIR__ . '/../includes/providers/dimoco/class-dimoco-callback-verifier.php';
require_once __DIR__ . '/../includes/repositories/class-dimoco-callback-blacklist-repository.php';
require_once __DIR__ . '/../includes/repositories/class-dimoco-callback-operator-lookup-repository.php';
require_once __DIR__ . '/../includes/shortcodes/class-dimoco-blacklister-shortcode.php';

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

    public function __construct(
        int $hlr_batch_limit = 100,
        int $hlr_request_delay_ms = 0,
        int $hlr_retry_delay_seconds = 0,
        array $operator_lookup_routes = [],
        array $dimoco_services = []
    ) {
        $this->hlr_batch_limit = $hlr_batch_limit;
        $this->hlr_request_delay_ms = $hlr_request_delay_ms;
        $this->hlr_retry_delay_seconds = $hlr_retry_delay_seconds;
        $this->operator_lookup_routes = $operator_lookup_routes;
        $this->dimoco_services = $dimoco_services;
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

    public function get_dimoco_service_options(): array
    {
        $options = [];

        foreach ($this->dimoco_services as $key => $service) {
            $options[$key] = $service['label'] ?? $key;
        }

        return $options;
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

    protected function export_hlr_rows(array $rows): void
    {
        $this->exported_rows = $rows;
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
        string $generated_result_state_id = 'kiwi_dimoco_blacklister_test_state'
    ) {
        parent::__construct($batch_service, $config, $callback_blacklist_repository);

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
            'register_shortcodes',
            'register_rest_routes',
            'ensure_operator_lookup_callback_table',
            'ensure_refund_callback_table',
            'ensure_blacklist_callback_table',
            'maybe_export_hlr_results',
            'maybe_run_dimoco_test',
            'maybe_run_refund_batch_test',
        ],
        array_map(static function ($callback) {
            return is_array($callback) ? $callback[1] : null;
        }, $init_callbacks),
        'Expected init callbacks to stay registered in the current order.'
    );

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
        'Expected the existing stylesheet handles to remain unchanged.'
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
