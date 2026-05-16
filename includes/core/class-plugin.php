<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralizes WordPress hook registration while preserving the existing plugin
 * bootstrap order and runtime wiring.
 */
class Kiwi_Plugin
{
    private const DB_SCHEMA_VERSION_OPTION = 'kiwi_backend_db_schema_version';
    private const DB_SCHEMA_VERSION = '2026-05-15-2';
    private const CLICK_ATTR_CLEANUP_LOCK_KEY = 'kiwi_click_attribution_cleanup_lock';
    private const CLICK_ATTR_CLEANUP_LOCK_TTL_SECONDS = 300;

    private $plugin_root_path;
    private $plugin_base_url;
    private $schema_checked = false;
    private $frontend_auth_gate;

    public function __construct(
        string $plugin_root_path,
        string $plugin_base_url,
        ?Kiwi_Frontend_Auth_Gate $frontend_auth_gate = null
    )
    {
        $this->plugin_root_path = rtrim($plugin_root_path, '/\\');
        $this->plugin_base_url = rtrim($plugin_base_url, '/\\') . '/';
        $this->frontend_auth_gate = $frontend_auth_gate instanceof Kiwi_Frontend_Auth_Gate
            ? $frontend_auth_gate
            : new Kiwi_Frontend_Auth_Gate();
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('init', [$this, 'handle_frontend_auth']);
        add_action('init', [$this, 'register_shortcodes']);
        add_action('init', [$this, 'register_rest_routes']);
        add_action('init', [$this, 'ensure_operator_lookup_callback_table']);
        add_action('init', [$this, 'ensure_refund_callback_table']);
        add_action('init', [$this, 'ensure_blacklist_callback_table']);
        add_action('init', [$this, 'ensure_nth_operational_tables']);
        add_action('init', [$this, 'ensure_click_attribution_table']);
        add_action('init', [$this, 'ensure_sales_table']);
        add_action('init', [$this, 'cleanup_expired_click_attributions']);
        add_action('init', [$this, 'maybe_export_statistics']);
        add_action('init', [$this, 'maybe_export_hlr_results']);
        add_action('init', [$this, 'maybe_run_dimoco_test']);
        add_action('init', [$this, 'maybe_run_refund_batch_test']);
        add_action('template_redirect', [$this, 'maybe_render_landing_page']);
    }

    public function enqueue_assets(): void
    {
        $this->enqueue_style_asset(
            'kiwi-backend-components',
            'assets/css/components.css'
        );
        $this->enqueue_style_asset(
            'kiwi-backend-forms',
            'assets/css/forms.css',
            ['kiwi-backend-components']
        );
        $this->enqueue_style_asset(
            'kiwi-backend-tables',
            'assets/css/tables.css',
            ['kiwi-backend-components']
        );
        $this->enqueue_style_asset(
            'kiwi-backend-frontend',
            'assets/css/frontend.css',
            [
                'kiwi-backend-components',
                'kiwi-backend-forms',
                'kiwi-backend-tables',
            ]
        );

        $this->enqueue_script_asset(
            'kiwi-backend-core',
            'assets/js/core.js'
        );
        $this->enqueue_script_asset(
            'kiwi-backend-frontend',
            'assets/js/frontend.js',
            ['kiwi-backend-core']
        );
    }

    public function register_shortcodes(): void
    {
        $runtime = $this->build_shortcode_runtime();

        $hlr_shortcode = new Kiwi_Hlr_Lookup_Shortcode(
            $runtime['operator_lookup_batch_service'],
            $runtime['dimoco_callback_operator_lookup_repository'],
            $this->frontend_auth_gate
        );
        $hlr_shortcode->register();

        $dimoco_refund_shortcode = new Kiwi_Dimoco_Refunder_Shortcode(
            $runtime['dimoco_refund_batch_service'],
            $runtime['config'],
            $runtime['dimoco_callback_refund_repository'],
            $this->frontend_auth_gate
        );
        $dimoco_refund_shortcode->register();

        $dimoco_blacklist_shortcode = new Kiwi_Dimoco_Blacklister_Shortcode(
            $runtime['dimoco_blacklist_batch_service'],
            $runtime['config'],
            $runtime['dimoco_callback_blacklist_repository'],
            $this->frontend_auth_gate
        );
        $dimoco_blacklist_shortcode->register();

        $landing_pages_gallery_shortcode = new Kiwi_Landing_Pages_Gallery_Shortcode(
            new Kiwi_Landing_Page_Gallery_Service($runtime['config']),
            $this->frontend_auth_gate
        );
        $landing_pages_gallery_shortcode->register();

        $premium_sms_fraud_shortcode = new Kiwi_Premium_Sms_Fraud_Shortcode(
            $runtime['premium_sms_fraud_signal_repository'],
            $runtime['config'],
            $this->frontend_auth_gate,
            $runtime['premium_sms_landing_engagement_repository']
        );
        $premium_sms_fraud_shortcode->register();

        $statistics_shortcode = new Kiwi_Statistics_Shortcode(
            $runtime['traffic_source_funnel_statistics_repository'],
            $this->frontend_auth_gate
        );
        $statistics_shortcode->register();
    }

    public function handle_frontend_auth(): void
    {
        $this->frontend_auth_gate->handle_auth_request();
    }

    public function register_rest_routes(): void
    {
        $config = new Kiwi_Config();
        $dimoco_callback_verifier = new Kiwi_Dimoco_Callback_Verifier();
        $dimoco_response_parser = new Kiwi_Dimoco_Response_Parser();
        $dimoco_callback_refund_repository = new Kiwi_Dimoco_Callback_Refund_Repository();
        $dimoco_callback_blacklist_repository = new Kiwi_Dimoco_Callback_Blacklist_Repository();
        $dimoco_callback_operator_lookup_repository = new Kiwi_Dimoco_Callback_Operator_Lookup_Repository();

        $dimoco_rest_routes = new Kiwi_Dimoco_Rest_Routes(
            $config,
            $dimoco_callback_verifier,
            $dimoco_response_parser,
            $dimoco_callback_refund_repository,
            $dimoco_callback_blacklist_repository,
            $dimoco_callback_operator_lookup_repository
        );
        $dimoco_rest_routes->register();

        $nth_runtime = $this->build_nth_runtime($config);
        $nth_rest_routes = new Kiwi_Nth_Rest_Routes(
            $config,
            $nth_runtime['nth_fr_one_off_service']
        );
        $nth_rest_routes->register();

        $landing_kpi_summary_repository = new Kiwi_Landing_Kpi_Summary_Repository();
        $landing_engagement_repository = new Kiwi_Premium_Sms_Landing_Engagement_Repository();
        $landing_handoff_event_repository = new Kiwi_Landing_Handoff_Event_Repository();
        $sms_body_variant_repository = new Kiwi_Sms_Body_Variant_Repository();
        $click_attribution_repository = new Kiwi_Click_Attribution_Repository();
        $landing_kpi_service = new Kiwi_Landing_Kpi_Service(
            $config,
            $landing_kpi_summary_repository
        );
        $landing_kpi_rest_routes = new Kiwi_Landing_Kpi_Rest_Routes(
            $config,
            $landing_kpi_service,
            $landing_engagement_repository,
            $click_attribution_repository,
            $landing_handoff_event_repository,
            $sms_body_variant_repository
        );
        $landing_kpi_rest_routes->register();
    }

    public function ensure_operator_lookup_callback_table(): void
    {
        $this->ensure_schema_if_needed();
    }

    public function ensure_refund_callback_table(): void
    {
        $this->ensure_schema_if_needed();
    }

    public function ensure_blacklist_callback_table(): void
    {
        $this->ensure_schema_if_needed();
    }

    public function ensure_nth_operational_tables(): void
    {
        $this->ensure_schema_if_needed();
    }

    public function ensure_sales_table(): void
    {
        $this->ensure_schema_if_needed();
    }

    public function ensure_click_attribution_table(): void
    {
        $this->ensure_schema_if_needed();
    }

    public function cleanup_expired_click_attributions(): void
    {
        if (!$this->should_run_click_attribution_cleanup()) {
            return;
        }

        $this->cleanup_click_attribution_records(
            $this->get_click_attribution_cleanup_limit()
        );
    }

    public function maybe_render_landing_page(): void
    {
        $config = new Kiwi_Config();
        $landing_page_session_repository = new Kiwi_Landing_Page_Session_Repository();
        $click_attribution_repository = new Kiwi_Click_Attribution_Repository();
        $tracking_capture_service = new Kiwi_Tracking_Capture_Service(
            $config,
            $click_attribution_repository
        );
        $landing_kpi_service = new Kiwi_Landing_Kpi_Service(
            $config,
            new Kiwi_Landing_Kpi_Summary_Repository()
        );
        $sms_body_variant_repository = new Kiwi_Sms_Body_Variant_Repository();
        $sms_body_variant_service = new Kiwi_Sms_Body_Variant_Service(
            $config,
            $sms_body_variant_repository
        );
        $primary_cta_resolver = new Kiwi_Landing_Primary_Cta_Resolver([
            new Kiwi_Nth_Primary_Cta_Adapter($sms_body_variant_service),
        ]);
        $router = new Kiwi_Landing_Page_Router(
            $config,
            $landing_page_session_repository,
            $this->plugin_base_url,
            $tracking_capture_service,
            $primary_cta_resolver,
            $landing_kpi_service
        );

        $router->maybe_render_current_request();
    }

    public function maybe_export_hlr_results(): void
    {
        if (!isset($_GET['kiwi_hlr_export'])) {
            return;
        }

        if (!$this->frontend_auth_gate->can_access_tools()) {
            $this->frontend_auth_gate->render_login_required_and_exit(
                'Please sign in to export HLR results.'
            );
        }

        $batch_id = '';

        if (isset($_GET['batch_id'])) {
            $batch_id = sanitize_text_field(wp_unslash($_GET['batch_id']));
        }

        if ($batch_id === '') {
            return;
        }

        $rows = $this->resolve_hlr_export_rows(get_transient($batch_id));

        if (!is_array($rows) || empty($rows)) {
            return;
        }

        $this->export_hlr_rows($rows);
    }

    public function maybe_export_statistics(): void
    {
        if (!isset($_GET['kiwi_statistics_export'])) {
            return;
        }

        if (!$this->frontend_auth_gate->can_access_tools()) {
            $this->frontend_auth_gate->render_login_required_and_exit(
                'Please sign in to export Statistics results.'
            );
        }

        $repository = new Kiwi_Traffic_Source_Funnel_Statistics_Repository();
        $shortcode = new Kiwi_Statistics_Shortcode($repository, $this->frontend_auth_gate);
        $filters = $shortcode->read_filters_from_request();
        $rows = $repository->get_rows($filters, (int) ($filters['limit'] ?? 100));

        if ($repository->get_last_error() !== '') {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Statistics view ' . $repository->get_view_name() . ' is not readable.';
            exit;
        }

        $this->export_statistics_rows($rows);
    }

    public function maybe_run_dimoco_test(): void
    {
        if (!isset($_GET['kiwi_dimoco_test'])) {
            return;
        }

        header('Content-Type: text/plain; charset=utf-8');

        $config = new Kiwi_Config();
        $digest = new Kiwi_Dimoco_Digest();
        $client = new Kiwi_Dimoco_Client($config, $digest);

        $result = $client->refund('at_service_getstronger', '1234567890');

        print_r($result);
        exit;
    }

    public function maybe_run_refund_batch_test(): void
    {
        if (!isset($_GET['kiwi_dimoco_refund_batch_test'])) {
            return;
        }

        header('Content-Type: text/plain; charset=utf-8');

        $config = new Kiwi_Config();
        $digest = new Kiwi_Dimoco_Digest();
        $client = new Kiwi_Dimoco_Client($config, $digest);
        $parser = new Kiwi_Dimoco_Response_Parser();
        $batch = new Kiwi_Dimoco_Refund_Batch_Service($client, $parser, $config);

        $input = <<<TEXT
RD-p-123456-8338-45c6-af9e-fe43d2ea5201bla
RD-p-123456-8338-45c6-af9e-fe43d2ea5201blub
RD-p-abcdef12-3456-7890-abcd-1234567890abblurp
TEXT;

        $result = $batch->process('at_service_getstronger', '436641234567', $input);

        print_r($result);
        exit;
    }

    private function build_shortcode_runtime(): array
    {
        $config = new Kiwi_Config();
        $msisdn_normalizer = new Kiwi_Msisdn_Normalizer();

        $lily_client = new Kiwi_Lily_Client($config);
        $lily_parser = new Kiwi_Lily_Response_Parser();
        $lily_operator_lookup_provider = new Kiwi_Lily_Operator_Lookup_Provider(
            $lily_client,
            $lily_parser
        );

        $dimoco_digest = new Kiwi_Dimoco_Digest();
        $dimoco_client = new Kiwi_Dimoco_Client($config, $dimoco_digest);
        $dimoco_response_parser = new Kiwi_Dimoco_Response_Parser();
        $dimoco_operator_lookup_provider = new Kiwi_Dimoco_Operator_Lookup_Provider(
            $dimoco_client,
            $dimoco_response_parser
        );

        $routed_operator_lookup_provider = new Kiwi_Routed_Operator_Lookup_Provider(
            $config,
            $lily_operator_lookup_provider,
            $dimoco_operator_lookup_provider
        );

        $dimoco_callback_operator_lookup_repository = new Kiwi_Dimoco_Callback_Operator_Lookup_Repository();
        $dimoco_callback_refund_repository = new Kiwi_Dimoco_Callback_Refund_Repository();
        $dimoco_callback_blacklist_repository = new Kiwi_Dimoco_Callback_Blacklist_Repository();
        $premium_sms_fraud_signal_repository = new Kiwi_Premium_Sms_Fraud_Signal_Repository();
        $premium_sms_landing_engagement_repository = new Kiwi_Premium_Sms_Landing_Engagement_Repository();
        $traffic_source_funnel_statistics_repository = new Kiwi_Traffic_Source_Funnel_Statistics_Repository();

        $operator_lookup_service = new Kiwi_Operator_Lookup_Service(
            $routed_operator_lookup_provider,
            $msisdn_normalizer
        );
        $operator_lookup_batch_service = new Kiwi_Operator_Lookup_Batch_Service(
            $operator_lookup_service,
            $config,
            $msisdn_normalizer
        );
        $dimoco_refund_batch_service = new Kiwi_Dimoco_Refund_Batch_Service(
            $dimoco_client,
            $dimoco_response_parser,
            $config
        );
        $dimoco_blacklist_batch_service = new Kiwi_Dimoco_Blacklist_Batch_Service(
            $operator_lookup_service,
            $dimoco_callback_operator_lookup_repository,
            $dimoco_client,
            $dimoco_response_parser,
            $config,
            $msisdn_normalizer
        );

        return [
            'config' => $config,
            'dimoco_callback_blacklist_repository' => $dimoco_callback_blacklist_repository,
            'dimoco_callback_operator_lookup_repository' => $dimoco_callback_operator_lookup_repository,
            'dimoco_callback_refund_repository' => $dimoco_callback_refund_repository,
            'dimoco_refund_batch_service' => $dimoco_refund_batch_service,
            'dimoco_blacklist_batch_service' => $dimoco_blacklist_batch_service,
            'operator_lookup_batch_service' => $operator_lookup_batch_service,
            'premium_sms_fraud_signal_repository' => $premium_sms_fraud_signal_repository,
            'premium_sms_landing_engagement_repository' => $premium_sms_landing_engagement_repository,
            'traffic_source_funnel_statistics_repository' => $traffic_source_funnel_statistics_repository,
        ];
    }

    private function build_nth_runtime(?Kiwi_Config $config = null): array
    {
        $config = $config instanceof Kiwi_Config ? $config : new Kiwi_Config();
        $nth_client = new Kiwi_Nth_Client($config);
        $nth_normalizer = new Kiwi_Nth_Premium_Sms_Normalizer($config);
        $nth_event_repository = new Kiwi_Nth_Event_Repository();
        $nth_flow_transaction_repository = new Kiwi_Nth_Flow_Transaction_Repository();
        $sales_repository = new Kiwi_Sales_Repository();
        $sales_recorder = new Kiwi_Shared_Sales_Recorder($sales_repository);
        $click_attribution_repository = new Kiwi_Click_Attribution_Repository();
        $sms_body_variant_repository = new Kiwi_Sms_Body_Variant_Repository();
        $sms_body_variant_service = new Kiwi_Sms_Body_Variant_Service(
            $config,
            $sms_body_variant_repository
        );
        $affiliate_postback_dispatcher = new Kiwi_Affiliate_Postback_Dispatcher($config);
        $landing_kpi_service = new Kiwi_Landing_Kpi_Service(
            $config,
            new Kiwi_Landing_Kpi_Summary_Repository()
        );
        $landing_engagement_repository = new Kiwi_Premium_Sms_Landing_Engagement_Repository();
        $premium_sms_fraud_signal_repository = new Kiwi_Premium_Sms_Fraud_Signal_Repository();
        $premium_sms_mo_engagement_evaluator = new Kiwi_Premium_Sms_Mo_Engagement_Evaluator_Service(
            $config,
            $click_attribution_repository,
            $landing_engagement_repository
        );
        $premium_sms_fraud_monitor_service = new Kiwi_Premium_Sms_Fraud_Monitor_Service(
            $config,
            $premium_sms_fraud_signal_repository,
            $premium_sms_mo_engagement_evaluator
        );
        $conversion_attribution_resolver = new Kiwi_Conversion_Attribution_Resolver(
            $click_attribution_repository,
            $affiliate_postback_dispatcher,
            $landing_kpi_service,
            $sales_repository,
            $sms_body_variant_repository
        );
        $nth_fr_one_off_service = new Kiwi_Nth_Fr_One_Off_Service(
            $config,
            $nth_normalizer,
            $nth_client,
            $nth_event_repository,
            $nth_flow_transaction_repository,
            $sales_recorder,
            $conversion_attribution_resolver,
            $premium_sms_fraud_monitor_service,
            $sms_body_variant_service
        );

        return [
            'config' => $config,
            'nth_client' => $nth_client,
            'nth_normalizer' => $nth_normalizer,
            'nth_event_repository' => $nth_event_repository,
            'nth_flow_transaction_repository' => $nth_flow_transaction_repository,
            'sales_recorder' => $sales_recorder,
            'sales_repository' => $sales_repository,
            'click_attribution_repository' => $click_attribution_repository,
            'sms_body_variant_repository' => $sms_body_variant_repository,
            'sms_body_variant_service' => $sms_body_variant_service,
            'landing_engagement_repository' => $landing_engagement_repository,
            'affiliate_postback_dispatcher' => $affiliate_postback_dispatcher,
            'landing_kpi_service' => $landing_kpi_service,
            'conversion_attribution_resolver' => $conversion_attribution_resolver,
            'premium_sms_fraud_signal_repository' => $premium_sms_fraud_signal_repository,
            'premium_sms_mo_engagement_evaluator' => $premium_sms_mo_engagement_evaluator,
            'premium_sms_fraud_monitor_service' => $premium_sms_fraud_monitor_service,
            'nth_fr_one_off_service' => $nth_fr_one_off_service,
        ];
    }

    protected function export_hlr_rows(array $rows): void
    {
        $exporter = new Kiwi_Csv_Exporter();
        $exporter->export($rows);
    }

    protected function export_statistics_rows(array $rows): void
    {
        $exporter = new Kiwi_Csv_Exporter();
        $exporter->export_columns(
            $rows,
            Kiwi_Statistics_Shortcode::EXPORT_COLUMNS,
            'kiwi-statistics.csv'
        );
    }

    protected function load_hlr_async_export_rows(array $request_ids): array
    {
        $repository = new Kiwi_Dimoco_Callback_Operator_Lookup_Repository();

        return $repository->get_recent_by_request_ids($request_ids, 100);
    }

    private function resolve_hlr_export_rows($export_state): array
    {
        if (!is_array($export_state)) {
            return [];
        }

        if (!array_key_exists('sync_rows', $export_state) && !array_key_exists('request_ids', $export_state)) {
            return $export_state;
        }

        $request_ids = $export_state['request_ids'] ?? [];
        $request_ids = array_values(array_unique(array_filter(array_map('strval', is_array($request_ids) ? $request_ids : []))));

        $sync_rows = $export_state['sync_rows'] ?? [];
        $sync_rows = is_array($sync_rows) ? $sync_rows : [];

        $msisdns = $this->extract_hlr_async_candidate_msisdns($sync_rows);
        $callback_rows_by_request_id = [];
        $callback_rows_by_msisdn = [];

        if (!empty($request_ids)) {
            $callback_rows_by_request_id = $this->index_hlr_callback_rows_by_request_id(
                $this->load_hlr_async_export_rows($request_ids)
            );
        }

        if (!empty($msisdns)) {
            $callback_rows_by_msisdn = $this->index_hlr_callback_rows_by_msisdn(
                $this->load_hlr_async_export_rows_by_msisdns($msisdns)
            );
        }

        $resolved_async_rows = $this->resolve_hlr_async_rows_for_sync_results(
            $sync_rows,
            $callback_rows_by_request_id,
            $callback_rows_by_msisdn
        );
        $normalized_async_rows = $this->normalize_hlr_async_export_rows($resolved_async_rows);

        if (!empty($normalized_async_rows)) {
            return $normalized_async_rows;
        }

        return is_array($sync_rows) ? $sync_rows : [];
    }

    protected function load_hlr_async_export_rows_by_msisdns(array $msisdns): array
    {
        $repository = new Kiwi_Dimoco_Callback_Operator_Lookup_Repository();

        return $repository->get_recent_by_msisdns($msisdns, 100);
    }

    private function normalize_hlr_async_export_rows(array $async_rows): array
    {
        $normalized_rows = [];

        foreach ($async_rows as $row) {
            $messages = [];

            $detail = (string) ($row['detail'] ?? '');
            if ($detail !== '') {
                $messages[] = $detail;
            }

            $detail_psp = (string) ($row['detail_psp'] ?? '');
            if ($detail_psp !== '') {
                $messages[] = $detail_psp;
            }

            $normalized_rows[] = [
                'msisdn' => (string) ($row['msisdn'] ?? ''),
                'provider' => 'dimoco',
                'feature' => (string) ($row['action'] ?? 'operator-lookup'),
                'success' => isset($row['action_status'])
                    ? (int) $row['action_status'] === 0
                    : (string) ($row['action_status_text'] ?? '') === 'success',
                'status_code' => (string) ($row['action_code'] ?? ''),
                'api_status' => (string) ($row['action_status_text'] ?? ''),
                'hlr_status' => '',
                'operator' => (string) ($row['operator'] ?? ''),
                'messages' => $messages,
            ];
        }

        return $normalized_rows;
    }

    private function extract_hlr_async_candidate_msisdns(array $sync_rows): array
    {
        $msisdns = [];

        foreach ($sync_rows as $sync_row) {
            if (($sync_row['provider'] ?? '') !== 'dimoco') {
                continue;
            }

            $msisdn = trim((string) ($sync_row['msisdn'] ?? ''));

            if ($msisdn !== '') {
                $msisdns[] = $msisdn;
            }
        }

        return array_values(array_unique($msisdns));
    }

    private function index_hlr_callback_rows_by_request_id(array $rows): array
    {
        $indexed_rows = [];

        foreach ($rows as $row) {
            $request_id = trim((string) ($row['request_id'] ?? ''));

            if ($request_id === '' || isset($indexed_rows[$request_id])) {
                continue;
            }

            $indexed_rows[$request_id] = $row;
        }

        return $indexed_rows;
    }

    private function index_hlr_callback_rows_by_msisdn(array $rows): array
    {
        $indexed_rows = [];

        foreach ($rows as $row) {
            $msisdn = trim((string) ($row['msisdn'] ?? ''));

            if ($msisdn === '' || isset($indexed_rows[$msisdn])) {
                continue;
            }

            $indexed_rows[$msisdn] = $row;
        }

        return $indexed_rows;
    }

    private function resolve_hlr_async_rows_for_sync_results(
        array $sync_rows,
        array $callback_rows_by_request_id,
        array $callback_rows_by_msisdn
    ): array {
        $resolved_rows = [];
        $seen_keys = [];

        foreach ($sync_rows as $sync_row) {
            if (($sync_row['provider'] ?? '') !== 'dimoco') {
                continue;
            }

            $request_id = trim((string) ($sync_row['request_id'] ?? ''));
            $msisdn = trim((string) ($sync_row['msisdn'] ?? ''));
            $resolved_row = null;

            if ($request_id !== '' && isset($callback_rows_by_request_id[$request_id])) {
                $resolved_row = $callback_rows_by_request_id[$request_id];
            } elseif ($msisdn !== '' && isset($callback_rows_by_msisdn[$msisdn])) {
                $resolved_row = $callback_rows_by_msisdn[$msisdn];
            }

            if (!is_array($resolved_row)) {
                continue;
            }

            $resolved_key = trim((string) ($resolved_row['request_id'] ?? ''));

            if ($resolved_key === '') {
                $resolved_key = trim((string) ($resolved_row['msisdn'] ?? ''));
            }

            if ($resolved_key !== '' && isset($seen_keys[$resolved_key])) {
                continue;
            }

            if ($resolved_key !== '') {
                $seen_keys[$resolved_key] = true;
            }

            $resolved_rows[] = $resolved_row;
        }

        return $resolved_rows;
    }

    private function ensure_schema_if_needed(): void
    {
        if ($this->schema_checked) {
            return;
        }

        $this->schema_checked = true;

        if ($this->get_installed_schema_version() === self::DB_SCHEMA_VERSION) {
            return;
        }

        $this->run_schema_migrations();
        $this->persist_schema_version(self::DB_SCHEMA_VERSION);
    }

    protected function run_schema_migrations(): void
    {
        foreach ($this->build_schema_repositories() as $repository) {
            if (!is_object($repository) || !method_exists($repository, 'create_table')) {
                continue;
            }

            $repository->create_table();
        }
    }

    protected function build_schema_repositories(): array
    {
        return [
            new Kiwi_Dimoco_Callback_Operator_Lookup_Repository(),
            new Kiwi_Dimoco_Callback_Refund_Repository(),
            new Kiwi_Dimoco_Callback_Blacklist_Repository(),
            new Kiwi_Landing_Page_Session_Repository(),
            new Kiwi_Nth_Event_Repository(),
            new Kiwi_Nth_Flow_Transaction_Repository(),
            new Kiwi_Click_Attribution_Repository(),
            new Kiwi_Sales_Repository(),
            new Kiwi_Landing_Kpi_Summary_Repository(),
            new Kiwi_Landing_Handoff_Event_Repository(),
            new Kiwi_Sms_Body_Variant_Repository(),
            new Kiwi_Premium_Sms_Landing_Engagement_Repository(),
            new Kiwi_Premium_Sms_Fraud_Signal_Repository(),
            new Kiwi_Traffic_Source_Funnel_Statistics_Repository(),
        ];
    }

    protected function get_click_attribution_cleanup_limit(): int
    {
        $config = new Kiwi_Config();

        return $config->get_click_attribution_cleanup_limit();
    }

    protected function cleanup_click_attribution_records(int $limit): void
    {
        $repository = new Kiwi_Click_Attribution_Repository();
        $repository->cleanup_expired($limit);
    }

    private function should_run_click_attribution_cleanup(): bool
    {
        if (!function_exists('get_transient') || !function_exists('set_transient')) {
            return true;
        }

        if (get_transient(self::CLICK_ATTR_CLEANUP_LOCK_KEY) !== false) {
            return false;
        }

        set_transient(
            self::CLICK_ATTR_CLEANUP_LOCK_KEY,
            '1',
            self::CLICK_ATTR_CLEANUP_LOCK_TTL_SECONDS
        );

        return true;
    }

    private function get_installed_schema_version(): string
    {
        if (!function_exists('get_option')) {
            return '';
        }

        $version = get_option(self::DB_SCHEMA_VERSION_OPTION, '');

        return is_string($version) ? $version : '';
    }

    private function persist_schema_version(string $schema_version): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        update_option(self::DB_SCHEMA_VERSION_OPTION, $schema_version, true);
    }

    private function enqueue_style_asset(
        string $handle,
        string $relative_path,
        array $dependencies = []
    ): void {
        wp_enqueue_style(
            $handle,
            $this->build_asset_url($relative_path),
            $dependencies,
            filemtime($this->build_asset_path($relative_path))
        );
    }

    private function enqueue_script_asset(
        string $handle,
        string $relative_path,
        array $dependencies = []
    ): void {
        wp_enqueue_script(
            $handle,
            $this->build_asset_url($relative_path),
            $dependencies,
            filemtime($this->build_asset_path($relative_path)),
            true
        );
    }

    private function build_asset_path(string $relative_path): string
    {
        $normalized_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative_path);
        $normalized_path = ltrim($normalized_path, DIRECTORY_SEPARATOR);

        return $this->plugin_root_path . DIRECTORY_SEPARATOR . $normalized_path;
    }

    private function build_asset_url(string $relative_path): string
    {
        $normalized_path = str_replace('\\', '/', $relative_path);
        $normalized_path = ltrim($normalized_path, '/');

        return $this->plugin_base_url . $normalized_path;
    }
}
