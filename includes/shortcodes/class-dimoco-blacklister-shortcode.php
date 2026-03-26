<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Dimoco_Blacklister_Shortcode
{
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

    public function __construct(
        Kiwi_Dimoco_Blacklist_Batch_Service $batch_service,
        Kiwi_Config $config,
        Kiwi_Dimoco_Callback_Blacklist_Repository $callback_blacklist_repository
    ) {
        $this->batch_service = $batch_service;
        $this->config = $config;
        $this->callback_blacklist_repository = $callback_blacklist_repository;
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
        $output = '';

        // Current form values / state
        $service_key = '';
        $blocklist_scope = 'merchant';
        $msisdns_input = '';
        $async_results = [];

        // Batch result from the synchronous blacklist run
        $batch_result = null;

        /**
         * Handle form submission
         *
         * This block:
         * - validates the nonce
         * - reads service / blocklist scope / msisdn list from POST
         * - calls the blacklist batch service
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
                    $async_results = $this->callback_blacklist_repository->get_recent_by_request_ids($request_ids, 100);
                }
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
        $output .= '<form method="post" class="kiwi-form kiwi-dimoco-blacklister-form">';
        $output .= wp_nonce_field('kiwi_dimoco_blacklister_action', 'kiwi_dimoco_blacklister_nonce', true, false);
        $output .= '<input type="hidden" name="kiwi_dimoco_blacklister_action" value="blacklist">';

        // Service selection dropdown
        $output .= '<p class="kiwi-field">';
        $output .= '<label for="kiwi_dimoco_service"><strong>Service</strong></label><br>';
        $output .= '<select id="kiwi_dimoco_service" name="kiwi_dimoco_service" class="kiwi-select" style="min-width:320px;">';
        $output .= '<option value="">Select a service</option>';

        foreach ($service_options as $key => $label) {
            $selected = selected($service_key, $key, false);
            $output .= '<option value="' . esc_attr((string) $key) . '"' . $selected . '>' . esc_html((string) $label) . '</option>';
        }

        $output .= '</select>';
        $output .= '</p>';

        // Blocklist scope dropdown
        $output .= '<p class="kiwi-field">';
        $output .= '<label for="kiwi_dimoco_blocklist_scope"><strong>Blocklist Scope</strong></label><br>';
        $output .= '<select id="kiwi_dimoco_blocklist_scope" name="kiwi_dimoco_blocklist_scope" class="kiwi-select" style="min-width:220px;">';
        $output .= '<option value="merchant"' . selected($blocklist_scope, 'merchant', false) . '>merchant</option>';
        $output .= '<option value="order"' . selected($blocklist_scope, 'order', false) . '>order</option>';
        $output .= '</select>';
        $output .= '</p>';

        // MSISDN textarea
        $output .= '<p class="kiwi-field">';
        $output .= '<label for="kiwi_dimoco_msisdns"><strong>MSISDNs</strong></label><br>';
        $output .= '<textarea id="kiwi_dimoco_msisdns" name="kiwi_dimoco_msisdns" rows="10" cols="50" class="kiwi-textarea" style="width:100%; max-width:700px;" placeholder="43664...&#10;43676...&#10;43650...">' . esc_textarea($msisdns_input) . '</textarea>';
        $output .= '</p>';

        // Submit button + loading indicator
        $output .= '<p class="kiwi-actions">';
        $output .= '<button type="submit" name="kiwi_dimoco_blacklister_submit" value="1" class="kiwi-button kiwi-submit-button">Run Blacklist</button>';
        $output .= ' <span class="kiwi-loading-text" style="display:none;">Processing...</span>';
        $output .= '</p>';

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

            $output .= '<div class="kiwi-result-block kiwi-result-summary">';
            $output .= '<h3>Blacklist Batch Result</h3>';

            $output .= '<p>';
            $output .= '<strong>Service:</strong> ' . esc_html((string) ($batch_result['service_label'] ?? $service_key)) . '<br>';
            $output .= '<strong>Scope:</strong> ' . esc_html((string) ($batch_result['blocklist_scope'] ?? $blocklist_scope)) . '<br>';
            $output .= '<strong>Total input:</strong> ' . esc_html((string) ($batch_result['total_input'] ?? 0)) . '<br>';
            $output .= '<strong>Unique input:</strong> ' . esc_html((string) ($batch_result['unique_input'] ?? 0)) . '<br>';
            $output .= '<strong>Processed:</strong> ' . esc_html((string) ($batch_result['processed'] ?? 0));
            $output .= '</p>';

            if (!empty($messages)) {
                $output .= '<div class="kiwi-notice kiwi-notice-warning"><ul>';

                foreach ($messages as $message) {
                    $output .= '<li>' . esc_html((string) $message) . '</li>';
                }

                $output .= '</ul></div>';
            }

            $output .= '</div>';
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
            $output .= '<div class="kiwi-result-block kiwi-result-table">';
            $output .= '<h3>Synchronous Responses</h3>';
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
            $output .= '</div>';
        }

        /**
         * ---------------------------------------------------------
         * ASYNC CALLBACK TABLE
         * ---------------------------------------------------------
         */
        if (!empty($async_results) && is_array($async_results)) {
            $output .= '<div class="kiwi-result-block kiwi-result-table">';
            $output .= '<h3>Asynchronous Callback Responses</h3>';
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
            $output .= '</div>';
        }

        return $output;
    }
}