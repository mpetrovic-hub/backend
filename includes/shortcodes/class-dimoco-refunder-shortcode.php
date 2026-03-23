<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Dimoco_Refunder_Shortcode
{
    /**
     * Batch service for processing multiple refund transaction IDs
     */
    private $batch_service;

    /**
     * Global plugin config
     * Used here mainly for loading DIMOCO service options
     */
    private $config;

    public function __construct(
        Kiwi_Dimoco_Refund_Batch_Service $batch_service,
        Kiwi_Config $config
    ) {
        $this->batch_service = $batch_service;
        $this->config = $config;
    }

    /**
     * Register shortcode: [kiwi_dimoco_refunder]
     */
    public function register(): void
    {
        add_shortcode('kiwi_dimoco_refunder', [$this, 'render']);
    }

    /**
     * Render the full refunder UI:
     * - service dropdown
     * - MSISDN input field
     * - transaction ID textarea
     * - submit button
     * - sync response table below the form
     */
    public function render(): string
    {
        $output = '';

        // Current form values / state
        $service_key = '';
        $msisdn = '';
        $transactions_input = '';

        // Batch result from the synchronous refund run
        $batch_result = null;

        /**
         * Handle form submission
         *
         * This block:
         * - validates the nonce
         * - reads service / msisdn / transaction list from POST
         * - calls the refund batch service
         */
        if (
            isset($_POST['kiwi_dimoco_refunder_action']) &&
            wp_unslash($_POST['kiwi_dimoco_refunder_action']) === 'refund' &&
            isset($_POST['kiwi_dimoco_refunder_nonce']) &&
            wp_verify_nonce($_POST['kiwi_dimoco_refunder_nonce'], 'kiwi_dimoco_refunder_action')
        ) {
            $service_key = isset($_POST['kiwi_dimoco_service'])
                ? sanitize_text_field(wp_unslash($_POST['kiwi_dimoco_service']))
                : '';

            $msisdn = isset($_POST['kiwi_dimoco_msisdn'])
                ? sanitize_text_field(wp_unslash($_POST['kiwi_dimoco_msisdn']))
                : '';

            $transactions_input = isset($_POST['kiwi_dimoco_transactions'])
                ? wp_unslash($_POST['kiwi_dimoco_transactions'])
                : '';

            $batch_result = $this->batch_service->process($service_key, $msisdn, $transactions_input);
        }

        /**
         * Load the DIMOCO service options for the dropdown
         */
        $service_options = $this->config->get_dimoco_service_options();

        /**
         * ---------------------------------------------------------
         * FORM / UI BLOCK
         * ---------------------------------------------------------
         * This is the visible refund UI:
         * 1. Service selection dropdown
         * 2. MSISDN input field
         * 3. Transaction ID textarea
         * 4. Submit button + loading indicator
         */

        $output .= '<form method="post" class="kiwi-form kiwi-dimoco-refunder-form">';
        $output .= wp_nonce_field('kiwi_dimoco_refunder_action', 'kiwi_dimoco_refunder_nonce', true, false);
        $output .= '<input type="hidden" name="kiwi_dimoco_refunder_action" value="refund">';

        /**
         * Service selection dropdown
         * The user chooses which configured DIMOCO service/order setup to use
         */
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

        /**
         * MSISDN input field
         * This is mainly contextual for the operator / support user
         */
        $output .= '<p class="kiwi-field">';
        $output .= '<label for="kiwi_dimoco_msisdn"><strong>MSISDN</strong></label><br>';
        $output .= '<input type="text" id="kiwi_dimoco_msisdn" name="kiwi_dimoco_msisdn" value="' . esc_attr($msisdn) . '" class="kiwi-input" style="width:100%; max-width:420px;" placeholder="43664...">';
        $output .= '</p>';

        /**
         * Transaction ID textarea
         * One RD-* transaction/debit ID per line
         */
        $output .= '<p class="kiwi-field">';
        $output .= '<label for="kiwi_dimoco_transactions"><strong>Transaction IDs</strong></label><br>';
        $output .= '<textarea id="kiwi_dimoco_transactions" name="kiwi_dimoco_transactions" rows="10" cols="50" class="kiwi-textarea" style="width:100%; max-width:700px;" placeholder="RD-p-123...&#10;RD-p-456...&#10;RD-p-789...">' . esc_textarea($transactions_input) . '</textarea>';
        $output .= '</p>';

        /**
         * Submit button + loading indicator
         * The loading text is controlled by the generic frontend JS
         */
        $output .= '<p class="kiwi-actions">';
        $output .= '<button type="submit" name="kiwi_dimoco_refunder_submit" value="1" class="kiwi-button kiwi-submit-button">Run Refund</button>';
        $output .= '<span class="kiwi-loading" style="display:none; margin-left:10px;">Processing...</span>';
        $output .= '</p>';

        $output .= '</form>';

        /**
         * ---------------------------------------------------------
         * RESULTS BLOCK
         * ---------------------------------------------------------
         * This is intentionally rendered BELOW the form.
         *
         * Important:
         * This table shows only the synchronous response returned
         * immediately by DIMOCO after the refund request.
         *
         * Final refund status may arrive later via callback.
         */
        if (is_array($batch_result)) {
            $messages = $batch_result['messages'] ?? [];

            /**
             * Validation or batch-level error messages
             */
            if (!empty($messages) && is_array($messages)) {
                $output .= '<div class="kiwi-notice kiwi-notice--error">';
                foreach ($messages as $message) {
                    $output .= '<p>' . esc_html((string) $message) . '</p>';
                }
                $output .= '</div>';
            }

            /**
             * Small note explaining that the table shows the sync response only
             */
            $output .= '<div class="kiwi-notice kiwi-notice--info">';
            $output .= '<p><strong>Note:</strong> The table below shows the immediate synchronous DIMOCO response. Final refund status may arrive later via callback.</p>';
            $output .= '</div>';

            /**
             * Meta information block:
             * selected service, MSISDN, total/unique/processed count
             */
            $output .= '<div class="kiwi-results-meta">';
            $output .= '<p><strong>Service:</strong> ' . esc_html((string) ($batch_result['service_label'] ?? '')) . '</p>';
            $output .= '<p><strong>MSISDN:</strong> ' . esc_html((string) ($batch_result['msisdn'] ?? '')) . '</p>';
            $output .= '<p><strong>Total input:</strong> ' . esc_html((string) ($batch_result['total_input'] ?? 0)) . '</p>';
            $output .= '<p><strong>Unique input:</strong> ' . esc_html((string) ($batch_result['unique_input'] ?? 0)) . '</p>';
            $output .= '<p><strong>Processed:</strong> ' . esc_html((string) ($batch_result['processed'] ?? 0)) . '</p>';
            $output .= '</div>';

            /**
             * Table with the synchronous refund response rows
             */
            if (!empty($batch_result['results']) && is_array($batch_result['results'])) {
                $output .= '<div class="kiwi-table-wrap">';
                $output .= '<table class="kiwi-table">';
                $output .= '<thead>';
                $output .= '<tr>';
                $output .= '<th>MSISDN</th>';
                $output .= '<th>Service</th>';
                $output .= '<th>Transaction ID</th>';
                $output .= '<th>Reference</th>';
                $output .= '<th>HTTP Status</th>';
                $output .= '<th>Refund Status</th>';
                $output .= '<th>Detail</th>';
                $output .= '</tr>';
                $output .= '</thead>';
                $output .= '<tbody>';

                foreach ($batch_result['results'] as $row) {
                    /**
                     * Raw status text from parser
                     * Examples: pending, success, failure, validation_failed
                     */
                    $status_text = (string) ($row['action_status_text'] ?? '');

                    /**
                     * Detail text from DIMOCO
                     * Example: "Payment call successfully accepted"
                     */
                    $detail = (string) ($row['detail'] ?? '');

                    /**
                     * CSS class for colored status styling
                     */
                    $status_class = '';

                    if ($status_text === 'pending') {
                        $status_class = 'kiwi-status--pending';
                        $status_text = 'Pending';
                    } elseif ($status_text === 'success') {
                        $status_class = 'kiwi-status--success';
                        $status_text = 'Success';
                    } elseif ($status_text === 'failure') {
                        $status_class = 'kiwi-status--failure';
                        $status_text = 'Failure';
                    } elseif ($status_text === 'validation_failed') {
                        $status_class = 'kiwi-status--warning';
                        $status_text = 'Validation failed';
                    }

                    /**
                     * Use parsed transaction_id if available,
                     * otherwise fall back to the originally entered input ID
                     */
                    $transaction_id = (string) ($row['transaction_id'] ?? $row['input_transaction_id'] ?? '');

                    $output .= '<tr>';
                    $output .= '<td>' . esc_html((string) ($row['msisdn'] ?? '')) . '</td>';
                    $output .= '<td>' . esc_html((string) ($row['service_label'] ?? '')) . '</td>';
                    $output .= '<td>' . esc_html($transaction_id) . '</td>';
                    $output .= '<td>' . esc_html((string) ($row['reference'] ?? '')) . '</td>';
                    $output .= '<td>' . esc_html((string) ($row['status_code'] ?? '')) . '</td>';
                    $output .= '<td class="' . esc_attr($status_class) . '">' . esc_html($status_text) . '</td>';
                    $output .= '<td>' . esc_html($detail) . '</td>';
                    $output .= '</tr>';
                }

                $output .= '</tbody>';
                $output .= '</table>';
                $output .= '</div>';
            } else {
                $output .= '<p>No refund results found.</p>';
            }
        }

        return $output;
    }
}