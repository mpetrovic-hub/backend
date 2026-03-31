<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Hlr_Lookup_Shortcode
{
    private const RESULT_STATE_QUERY_ARG = 'kiwi_hlr_lookup_result';

    /**
     * Batch service for processing multiple MSISDN lookups
     */
    private $batch_service;

    /* public function __construct(Kiwi_Batch_Service $batch_service) */
    public function __construct(Kiwi_Operator_Lookup_Batch_Service $batch_service)
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

        $restored_state = $this->load_result_state_from_request();

        if (is_array($restored_state)) {
            $submitted_input = (string) ($restored_state['submitted_input'] ?? '');
            $batch_id = (string) ($restored_state['batch_id'] ?? '');

            $restored_batch_result = $restored_state['batch_result'] ?? null;
            if (is_array($restored_batch_result)) {
                $batch_result = $restored_batch_result;
            }
        }

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
                $batch_id = $this->generate_export_batch_id();
                $this->store_export_rows($batch_id, $batch_result['results']);
            }

            if ($this->maybe_store_and_redirect_result_state([
                'submitted_input' => $submitted_input,
                'batch_result' => $batch_result,
                'batch_id' => $batch_id,
            ])) {
                return '';
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
        return 'kiwi_hlr_lookup_' . wp_generate_password(16, false, false);
    }

    protected function get_result_state_ttl_seconds(): int
    {
        return 15 * 60;
    }

    protected function generate_export_batch_id(): string
    {
        return 'kiwi_hlr_' . wp_generate_password(16, false, false);
    }

    protected function store_export_rows(string $batch_id, array $rows): void
    {
        set_transient($batch_id, $rows, $this->get_export_result_ttl_seconds());
    }

    protected function get_export_result_ttl_seconds(): int
    {
        return 15 * MINUTE_IN_SECONDS;
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
}
