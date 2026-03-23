<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Hlr_Lookup_Shortcode
{
    /**
     * Batch service for processing multiple MSISDN lookups
     */
    private $batch_service;

    public function __construct(Kiwi_Batch_Service $batch_service)
    {
        $this->batch_service = $batch_service;
    }

    /**
     * Register shortcode: [kiwi_hlr_lookup]
     */
    public function register(): void
    {
        add_shortcode('kiwi_hlr_lookup', [$this, 'render']);
    }

    /**
     * Render the full HLR lookup UI:
     * - MSISDN textarea
     * - submit button
     * - export button/link (after results)
     * - result summary + table below the form
     */
    public function render(): string
    {
        $output = '';

        // Current form state
        $submitted_input = '';

        // Result state
        $batch_result = null;
        $batch_id = '';

        /**
         * Handle form submission
         *
         * This block:
         * - validates the nonce
         * - reads the textarea input
         * - calls the HLR batch service
         * - stores results temporarily in a transient for CSV export
         */
        if (
            isset($_POST['kiwi_hlr_form_action']) &&
            wp_unslash($_POST['kiwi_hlr_form_action']) === 'lookup' &&
            isset($_POST['kiwi_hlr_lookup_nonce']) &&
            wp_verify_nonce($_POST['kiwi_hlr_lookup_nonce'], 'kiwi_hlr_lookup_action')
        ) {
            $submitted_input = isset($_POST['kiwi_hlr_input'])
                ? wp_unslash($_POST['kiwi_hlr_input'])
                : '';

            $batch_result = $this->batch_service->process($submitted_input);

            if (
                is_array($batch_result) &&
                !empty($batch_result['results']) &&
                is_array($batch_result['results'])
            ) {
                $batch_id = 'kiwi_hlr_' . wp_generate_password(16, false, false);
                set_transient($batch_id, $batch_result['results'], 15 * MINUTE_IN_SECONDS);
            }
        }

        /**
         * ---------------------------------------------------------
         * FORM / UI BLOCK
         * ---------------------------------------------------------
         * This is the visible HLR lookup UI:
         * 1. MSISDN textarea
         * 2. Submit button + loading indicator
         */

        $output .= '<form method="post" class="kiwi-form kiwi-hlr-form">';
        $output .= wp_nonce_field('kiwi_hlr_lookup_action', 'kiwi_hlr_lookup_nonce', true, false);
        $output .= '<input type="hidden" name="kiwi_hlr_form_action" value="lookup">';

        /**
         * Textarea for one or multiple MSISDNs
         * One number per line
         */
        $output .= '<p class="kiwi-field">';
        $output .= '<label for="kiwi_hlr_input"><strong>MSISDNs</strong></label><br>';
        $output .= '<textarea id="kiwi_hlr_input" name="kiwi_hlr_input" rows="10" cols="50" class="kiwi-textarea" style="width:100%; max-width:700px;" placeholder="+30 69...&#10;3069...&#10;69...">' . esc_textarea($submitted_input) . '</textarea>';
        $output .= '</p>';

        /**
         * Submit button + loading indicator
         * The loading text is controlled by the generic frontend JS
         */
        $output .= '<p class="kiwi-actions">';
        $output .= '<button type="submit" name="kiwi_hlr_lookup_submit" value="1" class="kiwi-button kiwi-submit-button">Run HLR Lookup</button>';
        $output .= '<span class="kiwi-loading" style="display:none; margin-left:10px;">Processing...</span>';
        $output .= '</p>';

        $output .= '</form>';

        /**
         * ---------------------------------------------------------
         * RESULTS BLOCK
         * ---------------------------------------------------------
         * This is intentionally rendered BELOW the form.
         *
         * It contains:
         * - summary/meta information
         * - export button (if results exist)
         * - result table
         */
        if (is_array($batch_result)) {
            /**
             * Meta information block:
             * total lines, unique numbers, processed count
             */
            $output .= '<div class="kiwi-results-meta">';
            $output .= '<p><strong>Total input:</strong> ' . esc_html((string) ($batch_result['total_input'] ?? 0)) . '</p>';
            $output .= '<p><strong>Unique input:</strong> ' . esc_html((string) ($batch_result['unique_input'] ?? 0)) . '</p>';
            $output .= '<p><strong>Processed:</strong> ' . esc_html((string) ($batch_result['processed'] ?? 0)) . '</p>';
            $output .= '</div>';

            

            /**
             * Result table
             */
            if (!empty($batch_result['results']) && is_array($batch_result['results'])) {
                $output .= '<div class="kiwi-table-wrap">';
                $output .= '<table class="kiwi-table">';
                $output .= '<thead>';
                $output .= '<tr>';
                $output .= '<th>MSISDN</th>';
                $output .= '<th>Provider</th>';
                $output .= '<th>Feature</th>';
                $output .= '<th>Success</th>';
                $output .= '<th>Status Code</th>';
                $output .= '<th>API Status</th>';
                $output .= '<th>HLR Status</th>';
                $output .= '<th>Operator</th>';
                $output .= '<th>Messages</th>';
                $output .= '</tr>';
                $output .= '</thead>';
                $output .= '<tbody>';

                foreach ($batch_result['results'] as $row) {
                    /**
                     * Messages array from the parsed/provider result
                     * Flattened into one table cell
                     */
                    $messages = '';

                    if (!empty($row['messages']) && is_array($row['messages'])) {
                        $messages = implode(' | ', $row['messages']);
                    }

                    /**
                     * Success styling
                     * This makes success/failure easier to scan visually
                     */
                    $success_class = !empty($row['success'])
                        ? 'kiwi-status--success'
                        : 'kiwi-status--failure';

                    $success_text = !empty($row['success']) ? '1' : '0';

                    $output .= '<tr>';
                    $output .= '<td>' . esc_html((string) ($row['msisdn'] ?? '')) . '</td>';
                    $output .= '<td>' . esc_html((string) ($row['provider'] ?? '')) . '</td>';
                    $output .= '<td>' . esc_html((string) ($row['feature'] ?? '')) . '</td>';
                    $output .= '<td class="' . esc_attr($success_class) . '">' . esc_html($success_text) . '</td>';
                    $output .= '<td>' . esc_html((string) ($row['status_code'] ?? '')) . '</td>';
                    $output .= '<td>' . esc_html((string) ($row['api_status'] ?? '')) . '</td>';
                    $output .= '<td>' . esc_html((string) ($row['hlr_status'] ?? '')) . '</td>';
                    $output .= '<td>' . esc_html((string) ($row['operator'] ?? '')) . '</td>';
                    $output .= '<td>' . esc_html($messages) . '</td>';
                    $output .= '</tr>';
                }

                $output .= '</tbody>';
                $output .= '</table>';
                $output .= '</div>';
            } else {
                $output .= '<p>No results found.</p>';
            }

            /**
             * Export button
             *
             * Results are stored server-side in a transient.
             * The export request uses GET + batch_id to avoid POST-replay issues.
             */
            if ($batch_id !== '') {
                $output .= '<form method="get" style="margin-top:20px;">';
                $output .= '<input type="hidden" name="kiwi_hlr_export" value="1">';
                $output .= '<input type="hidden" name="batch_id" value="' . esc_attr($batch_id) . '">';
                $output .= '<button type="submit" class="kiwi-button">Export CSV</button>';
                $output .= '</form>';
            }    

        }

        return $output;
    }
}