<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Dimoco_Blacklister_Shortcode
{
    private const ASYNC_TIMEOUT_SECONDS = 5;
    private const ASYNC_POLL_INTERVAL_MICROSECONDS = 500000;
    private const RESULT_STATE_QUERY_ARG = 'kiwi_dimoco_blacklister_result';

    /**
     * Batch service for processing multiple DIMOCO add-blocklist requests
     */
    private $batch_service;

    /**
     * Global plugin config
     * Used here mainly for loading DIMOCO service options
     */
    private $config;

    /**
     * Repository for storing and retrieving DIMOCO callback responses related to blacklist actions
     */
    private $callback_blacklist_repository;
    private $frontend_auth_gate;

    public function __construct(
        Kiwi_Dimoco_Blacklist_Batch_Service $batch_service,
        Kiwi_Config $config,
        Kiwi_Dimoco_Callback_Blacklist_Repository $callback_blacklist_repository,
        ?Kiwi_Frontend_Auth_Gate $frontend_auth_gate = null
    ) {
        $this->batch_service = $batch_service;
        $this->config = $config;
        $this->callback_blacklist_repository = $callback_blacklist_repository;
        $this->frontend_auth_gate = $frontend_auth_gate instanceof Kiwi_Frontend_Auth_Gate
            ? $frontend_auth_gate
            : new Kiwi_Frontend_Auth_Gate();
    }

    /**
     * Register shortcode: [kiwi_dimoco_blacklister]
     */
    public function register(): void
    {
        add_shortcode('kiwi_dimoco_blacklister', [$this, 'render']);
    }

    /**
     * Render the full blacklister UI:
     * - service dropdown
     * - blocklist scope dropdown
     * - MSISDN textarea
     * - submit button
     * - sync response table below the form
     * - async callback table below that
     */
    public function render(): string
    {
        if (!$this->frontend_auth_gate->can_access_tools()) {
            return $this->frontend_auth_gate->render_login_form([
                'message' => 'Please sign in to access the DIMOCO blacklist tool.',
            ]);
        }

        $output = '';

        // Current form values / state
        $service_key = '';
        $blocklist_scope = 'merchant';
        $msisdns_input = '';
        $async_results = [];

        // Batch result from the synchronous blacklist run
        $batch_result = null;

        $restored_state = $this->load_result_state_from_request();

        if (is_array($restored_state)) {
            $service_key = (string) ($restored_state['service_key'] ?? '');
            $blocklist_scope = (string) ($restored_state['blocklist_scope'] ?? 'merchant');
            $msisdns_input = (string) ($restored_state['msisdns_input'] ?? '');

            $restored_batch_result = $restored_state['batch_result'] ?? null;
            if (is_array($restored_batch_result)) {
                $batch_result = $restored_batch_result;
            }

            $restored_async_results = $restored_state['async_results'] ?? [];
            if (is_array($restored_async_results)) {
                $async_results = $restored_async_results;
            }
        }

        /**
        * Handle form submission
        *
        * This block:
        * - validates the nonce
        * - reads service / blocklist scope / msisdn list from POST
        * - calls the blacklist batch service
        * - waits briefly for async blacklist callbacks to arrive
        */
        if (
            isset($_POST['kiwi_dimoco_blacklister_action']) &&
            wp_unslash($_POST['kiwi_dimoco_blacklister_action']) === 'blacklist' &&
            isset($_POST['kiwi_dimoco_blacklister_nonce']) &&
            wp_verify_nonce($_POST['kiwi_dimoco_blacklister_nonce'], 'kiwi_dimoco_blacklister_action')
        ) {
            $service_key = isset($_POST['kiwi_dimoco_service'])
                ? sanitize_text_field(wp_unslash($_POST['kiwi_dimoco_service']))
                : '';

            $blocklist_scope = isset($_POST['kiwi_dimoco_blocklist_scope'])
                ? sanitize_text_field(wp_unslash($_POST['kiwi_dimoco_blocklist_scope']))
                : 'merchant';

            $msisdns_input = isset($_POST['kiwi_dimoco_msisdns'])
                ? wp_unslash($_POST['kiwi_dimoco_msisdns'])
                : '';

            $batch_result = $this->batch_service->process($service_key, $blocklist_scope, $msisdns_input);

            if (
                is_array($batch_result) &&
                !empty($batch_result['results']) &&
                is_array($batch_result['results'])
            ) {
                $request_ids = [];

                foreach ($batch_result['results'] as $row) {
                    $request_id = (string) ($row['request_id'] ?? '');

                    if ($request_id !== '') {
                        $request_ids[] = $request_id;
                    }
                }

                $request_ids = array_values(array_unique($request_ids));

                if (!empty($request_ids)) {
                    $async_results = $this->wait_for_async_blacklist_callbacks($request_ids);
                }
            }

            if ($this->maybe_store_and_redirect_result_state([
                'service_key' => $service_key,
                'blocklist_scope' => $blocklist_scope,
                'msisdns_input' => $msisdns_input,
                'batch_result' => $batch_result,
                'async_results' => $async_results,
            ])) {
                return '';
            }
        }

        /**
         * Load the DIMOCO service options for the dropdown
         */
        $service_options = $this->config->get_dimoco_service_options();

        /**
         * ---------------------------------------------------------
         * FORM / UI BLOCK
         * ---------------------------------------------------------
         */
        $output .= '<section class="kiwi-page-shell" aria-label="DIMOCO Blacklist">';
        $output .= '<header class="kiwi-section-header">';
        $output .= '<div class="kiwi-section-header-content">';
        $output .= '<h2 class="kiwi-page-title">DIMOCO Blacklist</h2>';
        $output .= '<p class="kiwi-page-subtitle">Add MSISDNs to DIMOCO blocklists and review synchronous plus callback responses.</p>';
        $output .= '</div>';
        $output .= '</header>';

        $output .= '<form method="post" class="kiwi-form kiwi-form-card">';
        $output .= wp_nonce_field('kiwi_dimoco_blacklister_action', 'kiwi_dimoco_blacklister_nonce', true, false);
        $output .= '<input type="hidden" name="kiwi_dimoco_blacklister_action" value="blacklist">';

        // Service selection dropdown
        $output .= '<div class="kiwi-form-row kiwi-field">';
        $output .= '<label for="kiwi_dimoco_service" class="kiwi-field-label">Service</label>';
        $output .= '<select id="kiwi_dimoco_service" name="kiwi_dimoco_service" class="kiwi-select kiwi-width-medium">';
        $output .= '<option value="">Select a service</option>';

        foreach ($service_options as $key => $label) {
            $selected = selected($service_key, $key, false);
            $output .= '<option value="' . esc_attr((string) $key) . '"' . $selected . '>' . esc_html((string) $label) . '</option>';
        }

        $output .= '</select>';
        $output .= '</div>';

        // Blocklist scope dropdown
        $output .= '<div class="kiwi-form-row kiwi-field">';
        $output .= '<label for="kiwi_dimoco_blocklist_scope" class="kiwi-field-label">Blocklist Scope</label>';
        $output .= '<select id="kiwi_dimoco_blocklist_scope" name="kiwi_dimoco_blocklist_scope" class="kiwi-select kiwi-width-small">';
        $output .= '<option value="merchant"' . selected($blocklist_scope, 'merchant', false) . '>merchant</option>';
        $output .= '<option value="order"' . selected($blocklist_scope, 'order', false) . '>order</option>';
        $output .= '</select>';
        $output .= '</div>';

        // MSISDN textarea
        $output .= '<div class="kiwi-form-row kiwi-field">';
        $output .= '<label for="kiwi_dimoco_msisdns" class="kiwi-field-label">MSISDNs</label>';
        $output .= '<textarea id="kiwi_dimoco_msisdns" name="kiwi_dimoco_msisdns" rows="10" cols="50" class="kiwi-textarea kiwi-width-large" placeholder="43664...&#10;43676...&#10;43650...">' . esc_textarea($msisdns_input) . '</textarea>';
        $output .= '</div>';

        // Submit button + loading indicator
        $output .= '<div class="kiwi-form-actions kiwi-actions">';
        $output .= '<button type="submit" name="kiwi_dimoco_blacklister_submit" value="1" class="kiwi-button kiwi-submit-button submit-button">Run Blacklist</button>';
        $output .= '<span class="kiwi-loading" style="display:none;">Processing...</span>';
        $output .= '</div>';

        $output .= '</form>';

        /**
         * ---------------------------------------------------------
         * SYNC RESULT SUMMARY
         * ---------------------------------------------------------
         */
        if (is_array($batch_result)) {
            $messages = isset($batch_result['messages']) && is_array($batch_result['messages'])
                ? $batch_result['messages']
                : [];

            $output .= '<section class="kiwi-card kiwi-results-meta kiwi-result-summary">';
            $output .= '<h4 class="kiwi-section-title">Blacklist Batch Result</h4>';
            $output .= '<p>';
            $output .= '<strong>Service:</strong> ' . esc_html((string) ($batch_result['service_label'] ?? $service_key)) . '<br>';
            $output .= '<strong>Scope:</strong> ' . esc_html((string) ($batch_result['blocklist_scope'] ?? $blocklist_scope)) . '<br>';
            $output .= '<strong>Total input:</strong> ' . esc_html((string) ($batch_result['total_input'] ?? 0)) . '<br>';
            $output .= '<strong>Unique input:</strong> ' . esc_html((string) ($batch_result['unique_input'] ?? 0)) . '<br>';
            $output .= '<strong>Processed:</strong> ' . esc_html((string) ($batch_result['processed'] ?? 0));
            $output .= '</p>';

            if (!empty($messages)) {
                $output .= '<div class="kiwi-notice kiwi-notice--warning"><ul>';

                foreach ($messages as $message) {
                    $output .= '<li>' . esc_html((string) $message) . '</li>';
                }

                $output .= '</ul></div>';
            }

            $output .= '</section>';
        }

        /**
         * ---------------------------------------------------------
         * SYNC RESULT TABLE
         * ---------------------------------------------------------
         */
        if (
            is_array($batch_result) &&
            !empty($batch_result['results']) &&
            is_array($batch_result['results'])
        ) {
            $output .= '<section class="kiwi-card kiwi-results-meta kiwi-result-table kiwi-table-card">';
            $output .= '<h4 class="kiwi-section-title">Synchronous Responses</h4>';
            $output .= '<div class="kiwi-table-wrap">';
            $output .= '<table class="kiwi-table">';
            $output .= '<thead><tr>';
            $output .= '<th>MSISDN</th>';
            $output .= '<th>Operator</th>';
            $output .= '<th>Service</th>';
            $output .= '<th>Scope</th>';
            $output .= '<th>HTTP</th>';
            $output .= '<th>Status</th>';
            $output .= '<th>Action</th>';
            $output .= '<th>Request ID</th>';
            $output .= '<th>Reference</th>';
            $output .= '<th>Detail</th>';
            $output .= '</tr></thead>';
            $output .= '<tbody>';

            foreach ($batch_result['results'] as $row) {
                $detail = (string) ($row['detail'] ?? '');
                $detail_psp = (string) ($row['detail_psp'] ?? '');

                if ($detail === '' && !empty($row['messages']) && is_array($row['messages'])) {
                    $detail = implode(' | ', array_map('strval', $row['messages']));
                }

                if ($detail_psp !== '') {
                    $detail = trim($detail . ' | ' . $detail_psp, ' |');
                }

                $output .= '<tr>';
                $output .= '<td>' . esc_html((string) ($row['msisdn'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['operator'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['service_label'] ?? $row['service_key'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['blocklist_scope'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['status_code'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['action_status_text'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['action'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['request_id'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['reference'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html($detail) . '</td>';
                $output .= '</tr>';
            }

            $output .= '</tbody></table>';
            $output .= '</div>';
            $output .= '</section>';
        }

        /**
         * ---------------------------------------------------------
         * ASYNC CALLBACK TABLE
         * ---------------------------------------------------------
         */
        if (!empty($async_results) && is_array($async_results)) {
            $output .= '<section class="kiwi-card kiwi-results-meta kiwi-result-table kiwi-table-card">';
            $output .= '<h4 class="kiwi-section-title">Asynchronous Callback Responses</h4>';
            $output .= '<div class="kiwi-table-wrap">';
            $output .= '<table class="kiwi-table">';
            $output .= '<thead><tr>';
            $output .= '<th>Created</th>';
            $output .= '<th>MSISDN</th>';
            $output .= '<th>Operator</th>';
            $output .= '<th>Service</th>';
            $output .= '<th>Scope</th>';
            $output .= '<th>Status</th>';
            $output .= '<th>Action</th>';
            $output .= '<th>Request ID</th>';
            $output .= '<th>Reference</th>';
            $output .= '<th>Detail</th>';
            $output .= '</tr></thead>';
            $output .= '<tbody>';

            foreach ($async_results as $row) {
                $detail = (string) ($row['detail'] ?? '');
                $detail_psp = (string) ($row['detail_psp'] ?? '');

                if ($detail_psp !== '') {
                    $detail = trim($detail . ' | ' . $detail_psp, ' |');
                }

                $output .= '<tr>';
                $output .= '<td>' . esc_html((string) ($row['created_at'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['msisdn'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['operator'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['service_label'] ?? $row['service_key'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['blocklist_scope'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['action_status_text'] ?? $row['action_status'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['action'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['request_id'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['reference'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html($detail) . '</td>';
                $output .= '</tr>';
            }

            $output .= '</tbody></table>';
            $output .= '</div>';
            $output .= '</section>';
        }

        $output .= '</section>';

        return $output;
    }

    protected function wait_for_async_blacklist_callbacks(array $request_ids): array
    {
        $async_results = [];
        $timeout_seconds = $this->get_async_timeout_seconds();
        $poll_interval_microseconds = $this->get_async_poll_interval_microseconds();
        $started_at = time();

        do {
            $async_results = $this->callback_blacklist_repository->get_recent_by_request_ids($request_ids, 100);

            if ($this->has_async_results_for_all_request_ids($request_ids, $async_results)) {
                break;
            }

            if ($poll_interval_microseconds > 0) {
                usleep($poll_interval_microseconds);
            }
        } while ((time() - $started_at) < $timeout_seconds);

        return $async_results;
    }

    protected function get_async_timeout_seconds(): int
    {
        return self::ASYNC_TIMEOUT_SECONDS;
    }

    protected function get_async_poll_interval_microseconds(): int
    {
        return self::ASYNC_POLL_INTERVAL_MICROSECONDS;
    }

    protected function maybe_store_and_redirect_result_state(array $state): bool
    {
        if (!$this->can_redirect_after_submission()) {
            return false;
        }

        $result_state_id = $this->store_result_state($state);
        $this->redirect_to_result_state($result_state_id);

        return true;
    }

    protected function load_result_state_from_request(): ?array
    {
        if (!isset($_GET[self::RESULT_STATE_QUERY_ARG])) {
            return null;
        }

        $result_state_id = sanitize_text_field(wp_unslash($_GET[self::RESULT_STATE_QUERY_ARG]));

        if ($result_state_id === '') {
            return null;
        }

        $state = get_transient($result_state_id);

        return is_array($state) ? $state : null;
    }

    protected function can_redirect_after_submission(): bool
    {
        return !headers_sent();
    }

    protected function store_result_state(array $state): string
    {
        $result_state_id = $this->generate_result_state_id();

        set_transient(
            $result_state_id,
            $state,
            $this->get_result_state_ttl_seconds()
        );

        return $result_state_id;
    }

    protected function generate_result_state_id(): string
    {
        return 'kiwi_dimoco_blacklister_' . wp_generate_password(16, false, false);
    }

    protected function get_result_state_ttl_seconds(): int
    {
        return 15 * 60;
    }

    protected function redirect_to_result_state(string $result_state_id): void
    {
        $redirect_url = add_query_arg(
            self::RESULT_STATE_QUERY_ARG,
            $result_state_id,
            remove_query_arg(self::RESULT_STATE_QUERY_ARG)
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    private function has_async_results_for_all_request_ids(array $request_ids, array $async_results): bool
    {
        $found_request_ids = [];

        foreach ($async_results as $async_row) {
            $async_request_id = (string) ($async_row['request_id'] ?? '');

            if ($async_request_id !== '') {
                $found_request_ids[$async_request_id] = true;
            }
        }

        return count($found_request_ids) >= count($request_ids);
    }
}
