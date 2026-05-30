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
    private const DB_SCHEMA_VERSION = '2026-05-31-1';
    private const CLICK_ATTR_CLEANUP_LOCK_KEY = 'kiwi_click_attribution_cleanup_lock';
    private const CLICK_ATTR_CLEANUP_LOCK_TTL_SECONDS = 300;
    private const LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_HOOK = 'kiwi_landing_funnel_daily_summary_refresh';
    private const LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_LOCK_KEY = 'kiwi_landing_funnel_daily_summary_refresh_lock';
    private const LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_LOCK_TTL_SECONDS = 1800;
    private const LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_LAST_RESULT_OPTION = 'kiwi_landing_funnel_daily_summary_refresh_last_result';

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
        add_action('init', [$this, 'schedule_landing_funnel_daily_summary_refresh']);
        add_action(self::LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_HOOK, [$this, 'run_landing_funnel_daily_summary_refresh']);
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
            $runtime['landing_funnel_daily_summary_repository'],
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
        $landing_page_session_repository = new Kiwi_Landing_Page_Session_Repository();
        $landing_handoff_event_repository = new Kiwi_Landing_Handoff_Event_Repository();
        $sms_body_variant_repository = new Kiwi_Sms_Body_Variant_Repository();
        $click_attribution_repository = new Kiwi_Click_Attribution_Repository();
        $device_normalizer = new Kiwi_Device_Context_Normalizer(
            new Kiwi_Device_Model_Brand_Map_Repository()
        );
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
            $sms_body_variant_repository,
            $landing_page_session_repository,
            $device_normalizer
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

    public function schedule_landing_funnel_daily_summary_refresh(): void
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }

        if (wp_next_scheduled(self::LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_HOOK) !== false) {
            return;
        }

        wp_schedule_event(
            time(),
            'hourly',
            self::LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_HOOK
        );
    }

    public function run_landing_funnel_daily_summary_refresh(): array
    {
        $started_at = $this->get_current_time_mysql();

        if ($this->has_landing_funnel_daily_summary_refresh_lock()) {
            $result = [
                'success' => false,
                'from_date' => '',
                'to_date' => '',
                'deleted' => 0,
                'inserted' => 0,
                'error' => 'Landing funnel daily summary refresh skipped because lock is active.',
                'started_at' => $started_at,
                'finished_at' => $this->get_current_time_mysql(),
                'skipped_due_to_lock' => true,
            ];
            $this->persist_landing_funnel_daily_summary_refresh_result($result);
            $this->log_landing_funnel_daily_summary_refresh($result['error']);

            return $result;
        }

        $this->set_landing_funnel_daily_summary_refresh_lock();

        try {
            $range = $this->build_landing_funnel_daily_summary_refresh_range();
            $main_result = $this->run_landing_funnel_daily_refresh_service(
                $this->build_landing_funnel_daily_summary_refresh_service(),
                $range,
                $started_at
            );
            $tkzone_result = $this->run_landing_funnel_daily_refresh_service(
                $this->build_landing_funnel_daily_tkzone_summary_refresh_service(),
                $range,
                $started_at
            );
            $result = $this->combine_landing_funnel_daily_refresh_results(
                $main_result,
                $tkzone_result,
                $range['from_date'],
                $range['to_date'],
                $started_at
            );
        } catch (Throwable $error) {
            $range = isset($range) && is_array($range) ? $range : ['from_date' => '', 'to_date' => ''];
            $result = [
                'success' => false,
                'from_date' => (string) ($range['from_date'] ?? ''),
                'to_date' => (string) ($range['to_date'] ?? ''),
                'deleted' => 0,
                'inserted' => 0,
                'error' => $error->getMessage(),
                'started_at' => $started_at,
                'finished_at' => $this->get_current_time_mysql(),
                'skipped_due_to_lock' => false,
            ];
        } finally {
            $this->clear_landing_funnel_daily_summary_refresh_lock();
        }

        $this->persist_landing_funnel_daily_summary_refresh_result($result);
        $this->log_landing_funnel_daily_summary_refresh(
            $result['success']
                ? 'Refresh succeeded for ' . $result['from_date'] . ' to ' . $result['to_date'] . '; deleted=' . $result['deleted'] . '; inserted=' . $result['inserted'] . '.'
                : 'Refresh failed for ' . $result['from_date'] . ' to ' . $result['to_date'] . ': ' . $result['error']
        );

        return $result;
    }

    public function maybe_render_landing_page(): void
    {
        $config = new Kiwi_Config();
        $landing_page_session_repository = new Kiwi_Landing_Page_Session_Repository();
        $device_normalizer = new Kiwi_Device_Context_Normalizer(
            new Kiwi_Device_Model_Brand_Map_Repository()
        );
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
            $landing_kpi_service,
            $device_normalizer,
            new Kiwi_Client_Ip_Resolver()
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

        $repository = new Kiwi_Landing_Funnel_Daily_Summary_Repository();
        $shortcode = new Kiwi_Statistics_Shortcode($repository, $this->frontend_auth_gate);
        $filters = $shortcode->read_filters_from_request();
        $rows = $repository->get_rows($filters, (int) ($filters['limit'] ?? 100));

        if ($repository->get_last_error() !== '') {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Statistics source ' . $repository->get_source_name() . ' is not readable.';
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
        $landing_funnel_daily_summary_repository = new Kiwi_Landing_Funnel_Daily_Summary_Repository();
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
            'landing_funnel_daily_summary_repository' => $landing_funnel_daily_summary_repository,
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
        $landing_page_session_repository = new Kiwi_Landing_Page_Session_Repository();
        $device_normalizer = new Kiwi_Device_Context_Normalizer(
            new Kiwi_Device_Model_Brand_Map_Repository()
        );
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
        $sales_snapshot_builder = new Kiwi_Sales_Attribution_Snapshot_Builder(
            $landing_page_session_repository,
            $landing_engagement_repository,
            $device_normalizer
        );
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
            $sms_body_variant_repository,
            $sales_snapshot_builder
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
            'landing_page_session_repository' => $landing_page_session_repository,
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

        $this->migrate_legacy_android_version_columns();
        $this->migrate_slim_landing_funnel_daily_summary_columns();
    }

    protected function build_schema_repositories(): array
    {
        return [
            new Kiwi_Dimoco_Callback_Operator_Lookup_Repository(),
            new Kiwi_Dimoco_Callback_Refund_Repository(),
            new Kiwi_Dimoco_Callback_Blacklist_Repository(),
            new Kiwi_Device_Model_Brand_Map_Repository(),
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
            new Kiwi_Landing_Funnel_Daily_Summary_Repository(),
            new Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository(),
            new Kiwi_Traffic_Source_Funnel_Statistics_Repository(),
        ];
    }

    protected function migrate_legacy_android_version_columns(): void
    {
        foreach ([
            (new Kiwi_Sales_Repository())->get_table_name_for_schema(),
            (new Kiwi_Landing_Funnel_Daily_Summary_Repository())->get_table_name(),
        ] as $table_name) {
            $this->backfill_legacy_android_version_column($table_name);
            $this->drop_column_if_exists($table_name, 'android_version');
        }
    }

    protected function migrate_slim_landing_funnel_daily_summary_columns(): void
    {
        $table_name = (new Kiwi_Landing_Funnel_Daily_Summary_Repository())->get_table_name();

        $this->consolidate_slim_landing_funnel_daily_summary_rows($table_name);

        foreach (['tkzone', 'median_hidden_seconds'] as $column_name) {
            $this->drop_column_if_exists($table_name, $column_name);
        }
    }

    private function consolidate_slim_landing_funnel_daily_summary_rows(string $table_name): void
    {
        global $wpdb;

        if (preg_match('/^[A-Za-z0-9_]+$/', $table_name) !== 1) {
            return;
        }

        $temp_table_name = $table_name . '_slim_rollup_tmp';

        $dimension_columns = [
            'metric_date',
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
        ];
        $dimension_select = implode(",\n                ", $dimension_columns);
        $dimension_group_by = implode(', ', $dimension_columns);
        $dimension_hash_expression = "SHA2(CONCAT_WS('|',
                landing_key,
                service_key,
                provider_key,
                flow_key,
                country,
                pid,
                tksource,
                device_brand,
                os,
                os_version,
                browser,
                client_ip_version,
                client_ip_prefix
            ), 256)";
        $metric_columns = [
            'sessions',
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
            'sales',
            'sales_amount_minor',
        ];
        $insert_columns = array_merge(
            $dimension_columns,
            ['dimension_hash'],
            $metric_columns,
            ['handoff_rate_pct', 'min_hidden_seconds', 'max_hidden_seconds', 'created_at', 'updated_at']
        );

        $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp_table_name}");
        $wpdb->query('START TRANSACTION');
        $created_temp_table = $wpdb->query(
            "CREATE TEMPORARY TABLE {$temp_table_name} AS
            SELECT
                {$dimension_select},
                {$dimension_hash_expression} AS dimension_hash,
                SUM(sessions) AS sessions,
                SUM(page_loaded_sessions) AS page_loaded_sessions,
                SUM(cta1_sessions) AS cta1_sessions,
                SUM(cta1_click_events) AS cta1_click_events,
                SUM(cta2_sessions) AS cta2_sessions,
                SUM(cta2_click_events) AS cta2_click_events,
                SUM(cta3_sessions) AS cta3_sessions,
                SUM(cta3_click_events) AS cta3_click_events,
                SUM(handoff_attempts) AS handoff_attempts,
                SUM(handoff_successes) AS handoff_successes,
                SUM(handoff_fails) AS handoff_fails,
                SUM(sales) AS sales,
                SUM(sales_amount_minor) AS sales_amount_minor,
                CASE
                    WHEN SUM(handoff_attempts) <= 0 THEN 0
                    ELSE ROUND(SUM(handoff_successes) / SUM(handoff_attempts) * 100, 2)
                END AS handoff_rate_pct,
                MIN(min_hidden_seconds) AS min_hidden_seconds,
                MAX(max_hidden_seconds) AS max_hidden_seconds,
                MIN(created_at) AS created_at,
                MAX(updated_at) AS updated_at
            FROM {$table_name}
            GROUP BY {$dimension_group_by}"
        );

        if ($created_temp_table === false) {
            $wpdb->query('ROLLBACK');
            $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp_table_name}");

            return;
        }

        $deleted_rows = $wpdb->query("DELETE FROM {$table_name}");
        if ($deleted_rows === false) {
            $wpdb->query('ROLLBACK');
            $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp_table_name}");

            return;
        }

        $inserted_rows = $wpdb->query(
            "INSERT INTO {$table_name} (" . implode(', ', $insert_columns) . ")
             SELECT " . implode(', ', $insert_columns) . "
             FROM {$temp_table_name}"
        );

        if ($inserted_rows === false) {
            $wpdb->query('ROLLBACK');
            $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp_table_name}");

            return;
        }

        $wpdb->query('COMMIT');
        $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp_table_name}");
    }

    private function backfill_legacy_android_version_column(string $table_name): void
    {
        global $wpdb;

        if (!$this->column_exists($table_name, 'android_version')) {
            return;
        }

        if (!$this->column_exists($table_name, 'os') || !$this->column_exists($table_name, 'os_version')) {
            return;
        }

        $legacy_value = "TRIM(COALESCE(android_version, ''))";
        $legacy_has_value = "{$legacy_value} <> '' AND {$legacy_value} <> '(unknown)'";
        $legacy_major_expression = "SUBSTRING_INDEX(SUBSTRING_INDEX({$legacy_value}, '.', 1), '_', 1)";

        $wpdb->query(
            "UPDATE {$table_name}
             SET os = CASE
                    WHEN {$legacy_has_value}
                         AND (os = '' OR os = '(unknown)')
                    THEN 'Android'
                    ELSE os
                 END,
                 os_version = CASE
                    WHEN os_version <> '' AND os_version <> '(unknown)' THEN os_version
                    WHEN {$legacy_has_value}
                         AND {$legacy_value} REGEXP '^[1-9][0-9]?([._][0-9]+)*$'
                    THEN {$legacy_major_expression}
                    WHEN {$legacy_has_value} THEN '(unknown)'
                    ELSE os_version
                 END
             WHERE {$legacy_has_value}"
        );
    }

    private function drop_column_if_exists(string $table_name, string $column_name): void
    {
        global $wpdb;

        if (!$this->column_exists($table_name, $column_name)) {
            return;
        }

        $wpdb->query("ALTER TABLE {$table_name} DROP COLUMN {$column_name}");
    }

    private function column_exists(string $table_name, string $column_name): bool
    {
        global $wpdb;

        $table_name = trim($table_name);
        $column_name = trim($column_name);

        if ($table_name === '' || $column_name === '') {
            return false;
        }

        if (!is_object($wpdb)
            || !method_exists($wpdb, 'get_var')
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'query')
        ) {
            return false;
        }

        if (preg_match('/^[A-Za-z0-9_]+$/', $table_name) !== 1
            || preg_match('/^[A-Za-z0-9_]+$/', $column_name) !== 1
        ) {
            return false;
        }

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM {$table_name} LIKE %s",
                $column_name
            )
        );

        return !($exists === null || $exists === false || $exists === '');
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

    protected function build_landing_funnel_daily_summary_refresh_service(): Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service
    {
        return new Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service();
    }

    protected function build_landing_funnel_daily_tkzone_summary_refresh_service(): Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Aggregation_Service
    {
        return new Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Aggregation_Service();
    }

    protected function get_current_business_date(): string
    {
        if (function_exists('current_time')) {
            $time = current_time('mysql');

            if (is_string($time) && preg_match('/^(\d{4}-\d{2}-\d{2})/', $time, $matches) === 1) {
                return $matches[1];
            }
        }

        return date('Y-m-d', $this->get_current_timestamp());
    }

    protected function get_current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            $time = current_time('mysql');

            if (is_string($time) && $time !== '') {
                return $time;
            }
        }

        return date('Y-m-d H:i:s');
    }

    protected function log_landing_funnel_daily_summary_refresh(string $message): void
    {
        error_log('[kiwi-landing-funnel-daily-summary-refresh] ' . $message);
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

    private function build_landing_funnel_daily_summary_refresh_range(): array
    {
        $refresh_days = max(1, $this->get_landing_funnel_daily_summary_refresh_days());
        $to_date = $this->get_current_business_date();
        $timestamp = strtotime($to_date . ' -' . $refresh_days . ' days');

        return [
            'from_date' => $timestamp === false ? $to_date : date('Y-m-d', $timestamp),
            'to_date' => $to_date,
        ];
    }

    protected function get_landing_funnel_daily_summary_refresh_days(): int
    {
        $config = new Kiwi_Config();

        return $config->get_landing_funnel_summary_refresh_days();
    }

    private function normalize_landing_funnel_daily_summary_refresh_result(
        array $result,
        string $from_date,
        string $to_date,
        string $started_at
    ): array {
        $normalized = [
            'success' => (bool) ($result['success'] ?? false),
            'from_date' => (string) ($result['from_date'] ?? $from_date),
            'to_date' => (string) ($result['to_date'] ?? $to_date),
            'deleted' => (int) ($result['deleted'] ?? 0),
            'inserted' => (int) ($result['inserted'] ?? 0),
            'error' => (string) ($result['error'] ?? ''),
            'started_at' => $started_at,
            'finished_at' => $this->get_current_time_mysql(),
            'skipped_due_to_lock' => false,
        ];

        if (isset($result['daily_results']) && is_array($result['daily_results'])) {
            $normalized['daily_results'] = array_values($result['daily_results']);
        }

        return $normalized;
    }

    private function run_landing_funnel_daily_refresh_service($service, array $range, string $started_at): array
    {
        try {
            if (!is_object($service) || !method_exists($service, 'refresh_range')) {
                throw new RuntimeException('Landing funnel daily refresh service is not callable.');
            }

            $service_result = $service->refresh_range(
                (string) ($range['from_date'] ?? ''),
                (string) ($range['to_date'] ?? '')
            );
            $result = $this->normalize_landing_funnel_daily_summary_refresh_result(
                is_array($service_result) ? $service_result : [],
                (string) ($range['from_date'] ?? ''),
                (string) ($range['to_date'] ?? ''),
                $started_at
            );

            if (!$result['success'] && $result['error'] === '' && method_exists($service, 'get_last_error')) {
                $result['error'] = (string) $service->get_last_error();
            }

            return $result;
        } catch (Throwable $error) {
            return [
                'success' => false,
                'from_date' => (string) ($range['from_date'] ?? ''),
                'to_date' => (string) ($range['to_date'] ?? ''),
                'deleted' => 0,
                'inserted' => 0,
                'error' => $error->getMessage(),
                'started_at' => $started_at,
                'finished_at' => $this->get_current_time_mysql(),
                'skipped_due_to_lock' => false,
            ];
        }
    }

    private function combine_landing_funnel_daily_refresh_results(
        array $main_result,
        array $tkzone_result,
        string $from_date,
        string $to_date,
        string $started_at
    ): array {
        $errors = [];

        if (!($main_result['success'] ?? false)) {
            $errors[] = 'main: ' . $this->format_landing_funnel_daily_refresh_error($main_result);
        }

        if (!($tkzone_result['success'] ?? false)) {
            $errors[] = 'tkzone: ' . $this->format_landing_funnel_daily_refresh_error($tkzone_result);
        }

        return [
            'success' => empty($errors),
            'from_date' => $from_date,
            'to_date' => $to_date,
            'deleted' => (int) ($main_result['deleted'] ?? 0) + (int) ($tkzone_result['deleted'] ?? 0),
            'inserted' => (int) ($main_result['inserted'] ?? 0) + (int) ($tkzone_result['inserted'] ?? 0),
            'error' => implode('; ', $errors),
            'started_at' => $started_at,
            'finished_at' => $this->get_current_time_mysql(),
            'skipped_due_to_lock' => false,
            'summaries' => [
                'main' => $this->compact_landing_funnel_daily_refresh_result($main_result),
                'tkzone' => $this->compact_landing_funnel_daily_refresh_result($tkzone_result),
            ],
        ];
    }

    private function compact_landing_funnel_daily_refresh_result(array $result): array
    {
        return [
            'success' => (bool) ($result['success'] ?? false),
            'from_date' => (string) ($result['from_date'] ?? ''),
            'to_date' => (string) ($result['to_date'] ?? ''),
            'deleted' => (int) ($result['deleted'] ?? 0),
            'inserted' => (int) ($result['inserted'] ?? 0),
            'error' => (string) ($result['error'] ?? ''),
        ];
    }

    private function format_landing_funnel_daily_refresh_error(array $result): string
    {
        $error = trim((string) ($result['error'] ?? ''));

        return $error !== '' ? $error : 'failed without error detail';
    }

    private function has_landing_funnel_daily_summary_refresh_lock(): bool
    {
        return function_exists('get_transient')
            && get_transient(self::LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_LOCK_KEY) !== false;
    }

    private function set_landing_funnel_daily_summary_refresh_lock(): void
    {
        if (!function_exists('set_transient')) {
            return;
        }

        set_transient(
            self::LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_LOCK_KEY,
            '1',
            self::LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_LOCK_TTL_SECONDS
        );
    }

    private function clear_landing_funnel_daily_summary_refresh_lock(): void
    {
        if (function_exists('delete_transient')) {
            delete_transient(self::LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_LOCK_KEY);
        }
    }

    private function persist_landing_funnel_daily_summary_refresh_result(array $result): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        update_option(self::LANDING_FUNNEL_DAILY_SUMMARY_REFRESH_LAST_RESULT_OPTION, $result, false);
    }

    private function get_current_timestamp(): int
    {
        if (function_exists('current_time')) {
            $timestamp = current_time('timestamp');

            if (is_numeric($timestamp)) {
                return (int) $timestamp;
            }
        }

        return time();
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
