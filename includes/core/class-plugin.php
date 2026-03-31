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
    private $plugin_root_path;
    private $plugin_base_url;

    public function __construct(string $plugin_root_path, string $plugin_base_url)
    {
        $this->plugin_root_path = rtrim($plugin_root_path, '/\\');
        $this->plugin_base_url = rtrim($plugin_base_url, '/\\') . '/';
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('init', [$this, 'register_shortcodes']);
        add_action('init', [$this, 'register_rest_routes']);
        add_action('init', [$this, 'ensure_operator_lookup_callback_table']);
        add_action('init', [$this, 'ensure_refund_callback_table']);
        add_action('init', [$this, 'ensure_blacklist_callback_table']);
        add_action('init', [$this, 'maybe_export_hlr_results']);
        add_action('init', [$this, 'maybe_run_dimoco_test']);
        add_action('init', [$this, 'maybe_run_refund_batch_test']);
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
            $runtime['dimoco_callback_operator_lookup_repository']
        );
        $hlr_shortcode->register();

        $dimoco_refund_shortcode = new Kiwi_Dimoco_Refunder_Shortcode(
            $runtime['dimoco_refund_batch_service'],
            $runtime['config'],
            $runtime['dimoco_callback_refund_repository']
        );
        $dimoco_refund_shortcode->register();

        $dimoco_blacklist_shortcode = new Kiwi_Dimoco_Blacklister_Shortcode(
            $runtime['dimoco_blacklist_batch_service'],
            $runtime['config'],
            $runtime['dimoco_callback_blacklist_repository']
        );
        $dimoco_blacklist_shortcode->register();
    }

    public function register_rest_routes(): void
    {
        $config = new Kiwi_Config();
        $dimoco_callback_verifier = new Kiwi_Dimoco_Callback_Verifier();
        $dimoco_response_parser = new Kiwi_Dimoco_Response_Parser();
        $dimoco_callback_refund_repository = new Kiwi_Dimoco_Callback_Refund_Repository();
        $dimoco_callback_blacklist_repository = new Kiwi_Dimoco_Callback_Blacklist_Repository();
        $dimoco_callback_operator_lookup_repository = new Kiwi_Dimoco_Callback_Operator_Lookup_Repository();

        $rest_routes = new Kiwi_Rest_Routes(
            $config,
            $dimoco_callback_verifier,
            $dimoco_response_parser,
            $dimoco_callback_refund_repository,
            $dimoco_callback_blacklist_repository,
            $dimoco_callback_operator_lookup_repository
        );
        $rest_routes->register();
    }

    public function ensure_operator_lookup_callback_table(): void
    {
        $repository = new Kiwi_Dimoco_Callback_Operator_Lookup_Repository();
        $repository->create_table();
    }

    public function ensure_refund_callback_table(): void
    {
        $repository = new Kiwi_Dimoco_Callback_Refund_Repository();
        $repository->create_table();
    }

    public function ensure_blacklist_callback_table(): void
    {
        $repository = new Kiwi_Dimoco_Callback_Blacklist_Repository();
        $repository->create_table();
    }

    public function maybe_export_hlr_results(): void
    {
        if (!isset($_GET['kiwi_hlr_export'])) {
            return;
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
        ];
    }

    protected function export_hlr_rows(array $rows): void
    {
        $exporter = new Kiwi_Csv_Exporter();
        $exporter->export($rows);
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
            '0.1',
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
