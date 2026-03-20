<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Hlr_Lookup_Shortcode
{
    private $batch_service;    

     public function __construct(Kiwi_Batch_Service $batch_service)
    {
        $this->batch_service = $batch_service;
    }

    public function register(): void
    {
        add_shortcode('kiwi_hlr_lookup', [$this, 'render']);
    }

    public function render(): string
    {
        $output = '';
        $submitted_input = '';
        $batch_result = null;
        $batch_id = ''; //new

        if (
            isset($_POST['kiwi_hlr_form_action']) &&
            $_POST['kiwi_hlr_form_action'] === 'lookup' &&
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
                set_transient($batch_id, $batch_result['results'], 30 * MINUTE_IN_SECONDS);
            }
        }

        if (is_array($batch_result)) {
            $output .= '<div class="kiwi-hlr-results-meta">';
            $output .= '<p><strong>Total input:</strong> ' . esc_html((string) $batch_result['total_input']) . '</p>';
            $output .= '<p><strong>Unique input:</strong> ' . esc_html((string) $batch_result['unique_input']) . '</p>';
            $output .= '<p><strong>Processed:</strong> ' . esc_html((string) $batch_result['processed']) . '</p>';
            $output .= '</div>';

            if (!empty($batch_result['results'])) {
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
                    $messages = '';

                    if (!empty($row['messages']) && is_array($row['messages'])) {
                        $messages = implode(' | ', $row['messages']);
                    }

                    $output .= '<tr>';
                    $output .= '<td>' . esc_html((string) ($row['msisdn'] ?? '')) . '</td>';
                    $output .= '<td>' . esc_html((string) ($row['provider'] ?? '')) . '</td>';
                    $output .= '<td>' . esc_html((string) ($row['feature'] ?? '')) . '</td>';
                    $output .= '<td>' . esc_html(!empty($row['success']) ? '1' : '0') . '</td>';
                    $output .= '<td>' . esc_html((string) ($row['status_code'] ?? '')) . '</td>';
                    $output .= '<td>' . esc_html((string) ($row['api_status'] ?? '')) . '</td>';
                    $output .= '<td>' . esc_html((string) ($row['hlr_status'] ?? '')) . '</td>';
                    $output .= '<td>' . esc_html((string) ($row['operator'] ?? '')) . '</td>';
                    $output .= '<td>' . esc_html($messages) . '</td>';
                    $output .= '</tr>';
                }

                $output .= '</tbody>';
                $output .= '</table>';

                // Export link/form - only show if we have a valid batch ID and results

                if ($batch_id !== '') {
                    $export_url = add_query_arg([
                        'kiwi_hlr_export' => '1',
                        'batch_id'        => $batch_id,
                    ]);

                    $output .= '<form method="get" style="margin-top:20px;">';
                    $output .= '<input type="hidden" name="kiwi_hlr_export" value="1">';
                    $output .= '<input type="hidden" name="batch_id" value="' . esc_attr($batch_id) . '">';
                    $output .= '<button type="submit" class="kiwi-button">Export CSV</button>';
                    $output .= '</form>';
                }
            } else {
                $output .= '<p>No results found.</p>';
            }
        }

        $output .= '<form method="post" class="kiwi-form">';
        $output .= wp_nonce_field('kiwi_hlr_lookup_action', 'kiwi_hlr_lookup_nonce', true, false);
        $output .= '<input type="hidden" name="kiwi_hlr_form_action" value="lookup">';

        $output .= '<p class="kiwi-field">';        
        $output .= '<label for="kiwi_hlr_input"><strong>MSISDNs</strong></label><br>';
        $output .= '<textarea id="kiwi_hlr_input" name="kiwi_hlr_input" class="kiwi-textarea" rows="10" cols="50" placeholder="+30 69...&#10;3069...&#10;69..." style="width:100%; max-width:700px;">' . esc_textarea($submitted_input) . '</textarea>';
        $output .= '</p>';

        $output .= '<p>';
        $output .= '<p class="kiwi-hlr-actions">';
        $output .= '<button type="submit" name="kiwi_hlr_lookup_submit" value="1" class="kiwi-button kiwi-submit-button">Run HLR Lookup</button>';
        $output .= '<span class="kiwi-hlr-loading" style="display:none; margin-left:10px;">Processing...</span>';
        $output .= '</p>';
        $output .= '</form>';

        return $output;
    }
}