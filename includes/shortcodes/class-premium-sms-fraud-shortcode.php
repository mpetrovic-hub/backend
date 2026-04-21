<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Premium_Sms_Fraud_Shortcode
{
    private $repository;
    private $frontend_auth_gate;

    public function __construct(
        Kiwi_Premium_Sms_Fraud_Signal_Repository $repository,
        ?Kiwi_Frontend_Auth_Gate $frontend_auth_gate = null
    ) {
        $this->repository = $repository;
        $this->frontend_auth_gate = $frontend_auth_gate instanceof Kiwi_Frontend_Auth_Gate
            ? $frontend_auth_gate
            : new Kiwi_Frontend_Auth_Gate();
    }

    public function register(): void
    {
        add_shortcode('kiwi_premium_sms_fraud', [$this, 'render']);
    }

    public function render(): string
    {
        if (!$this->frontend_auth_gate->can_access_tools()) {
            return $this->frontend_auth_gate->render_login_form([
                'message' => 'Please sign in to access the Premium SMS fraud monitor tool.',
            ]);
        }

        $filters = $this->read_filters_from_request();
        $rows = $this->repository->get_recent($filters, (int) ($filters['limit'] ?? 100));

        $output = '';
        $output .= '<section class="kiwi-page-shell" aria-label="Premium SMS Fraud Monitor">';
        $output .= '<header class="kiwi-section-header">';
        $output .= '<div class="kiwi-section-header-content">';
        $output .= '<h2 class="kiwi-page-title">Premium SMS Fraud Monitor</h2>';
        $output .= '<p class="kiwi-page-subtitle">Review inbound MO volume snapshots and soft flags by identity.</p>';
        $output .= '</div>';
        $output .= '</header>';
        $output .= $this->render_filter_form($filters);

        if (empty($rows)) {
            $output .= '<div class="kiwi-notice kiwi-notice--info"><p>No fraud-monitor rows found for the selected filters.</p></div>';
            $output .= '</section>';

            return $output;
        }

        $output .= '<section class="kiwi-card kiwi-table-card">';
        $output .= '<h4 class="kiwi-section-title">Fraud Signals</h4>';
        $output .= '<div class="kiwi-table-wrap">';
        $output .= '<table class="kiwi-table">';
        $output .= '<thead><tr>';
        $output .= '<th>Last Seen</th>';
        $output .= '<th>Service</th>';
        $output .= '<th>Provider</th>';
        $output .= '<th>Flow</th>';
        $output .= '<th>Identity Type</th>';
        $output .= '<th>Identity</th>';
        $output .= '<th>1h</th>';
        $output .= '<th>24h</th>';
        $output .= '<th>Total</th>';
        $output .= '<th>Soft Flag</th>';
        $output .= '<th>Reason</th>';
        $output .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $flag_text = !empty($row['is_soft_flag']) ? '1' : '0';
            $flag_class = !empty($row['is_soft_flag']) ? 'kiwi-status--failure' : 'kiwi-status--success';

            $output .= '<tr>';
            $output .= '<td>' . esc_html((string) ($row['occurred_at'] ?? '')) . '</td>';
            $output .= '<td>' . esc_html((string) ($row['service_key'] ?? '')) . '</td>';
            $output .= '<td>' . esc_html((string) ($row['provider_key'] ?? '')) . '</td>';
            $output .= '<td>' . esc_html((string) ($row['flow_key'] ?? '')) . '</td>';
            $output .= '<td>' . esc_html((string) ($row['identity_type'] ?? '')) . '</td>';
            $output .= '<td>' . esc_html((string) ($row['identity_value'] ?? '')) . '</td>';
            $output .= '<td>' . esc_html((string) ($row['count_1h'] ?? '0')) . '</td>';
            $output .= '<td>' . esc_html((string) ($row['count_24h'] ?? '0')) . '</td>';
            $output .= '<td>' . esc_html((string) ($row['count_total'] ?? '0')) . '</td>';
            $output .= '<td class="' . esc_attr($flag_class) . '">' . esc_html($flag_text) . '</td>';
            $output .= '<td>' . esc_html((string) ($row['soft_flag_reason'] ?? '')) . '</td>';
            $output .= '</tr>';
        }

        $output .= '</tbody></table>';
        $output .= '</div>';
        $output .= '</section>';
        $output .= '</section>';

        return $output;
    }

    private function render_filter_form(array $filters): string
    {
        $service_key = (string) ($filters['service_key'] ?? '');
        $provider_key = (string) ($filters['provider_key'] ?? '');
        $flow_key = (string) ($filters['flow_key'] ?? '');
        $identity_type = (string) ($filters['identity_type'] ?? '');
        $flagged_only = !empty($filters['flagged_only']);
        $limit = (int) ($filters['limit'] ?? 100);

        $output = '';
        $output .= '<form method="get" class="kiwi-form kiwi-form-card">';
        $output .= '<div class="kiwi-form-row kiwi-field">';
        $output .= '<label class="kiwi-field-label" for="kiwi_fraud_service_key">Service Key</label>';
        $output .= '<input id="kiwi_fraud_service_key" class="kiwi-input kiwi-width-medium" type="text" name="kiwi_fraud_service_key" value="' . esc_attr($service_key) . '">';
        $output .= '</div>';
        $output .= '<div class="kiwi-form-row kiwi-field">';
        $output .= '<label class="kiwi-field-label" for="kiwi_fraud_provider_key">Provider Key</label>';
        $output .= '<input id="kiwi_fraud_provider_key" class="kiwi-input kiwi-width-medium" type="text" name="kiwi_fraud_provider_key" value="' . esc_attr($provider_key) . '">';
        $output .= '</div>';
        $output .= '<div class="kiwi-form-row kiwi-field">';
        $output .= '<label class="kiwi-field-label" for="kiwi_fraud_flow_key">Flow Key</label>';
        $output .= '<input id="kiwi_fraud_flow_key" class="kiwi-input kiwi-width-medium" type="text" name="kiwi_fraud_flow_key" value="' . esc_attr($flow_key) . '">';
        $output .= '</div>';
        $output .= '<div class="kiwi-form-row kiwi-field">';
        $output .= '<label class="kiwi-field-label" for="kiwi_fraud_identity_type">Identity Type</label>';
        $output .= '<select id="kiwi_fraud_identity_type" class="kiwi-select kiwi-width-small" name="kiwi_fraud_identity_type">';
        $output .= '<option value="">all</option>';
        $output .= '<option value="subscriber"' . selected($identity_type, 'subscriber', false) . '>subscriber</option>';
        $output .= '<option value="session"' . selected($identity_type, 'session', false) . '>session</option>';
        $output .= '</select>';
        $output .= '</div>';
        $output .= '<div class="kiwi-form-row kiwi-field">';
        $output .= '<label class="kiwi-field-label" for="kiwi_fraud_limit">Limit</label>';
        $output .= '<input id="kiwi_fraud_limit" class="kiwi-input kiwi-width-small" type="number" min="1" max="500" name="kiwi_fraud_limit" value="' . esc_attr((string) $limit) . '">';
        $output .= '</div>';
        $output .= '<div class="kiwi-form-row kiwi-field">';
        $output .= '<label class="kiwi-field-label" for="kiwi_fraud_flagged_only">Flagged only</label>';
        $output .= '<input id="kiwi_fraud_flagged_only" type="checkbox" name="kiwi_fraud_flagged_only" value="1"' . ($flagged_only ? ' checked="checked"' : '') . '>';
        $output .= '</div>';
        $output .= '<div class="kiwi-form-actions kiwi-actions">';
        $output .= '<button type="submit" class="kiwi-button kiwi-submit-button">Apply Filters</button>';
        $output .= '</div>';
        $output .= '</form>';

        return $output;
    }

    private function read_filters_from_request(): array
    {
        $service_key = isset($_GET['kiwi_fraud_service_key'])
            ? sanitize_text_field(wp_unslash((string) $_GET['kiwi_fraud_service_key']))
            : '';
        $provider_key = isset($_GET['kiwi_fraud_provider_key'])
            ? sanitize_text_field(wp_unslash((string) $_GET['kiwi_fraud_provider_key']))
            : '';
        $flow_key = isset($_GET['kiwi_fraud_flow_key'])
            ? sanitize_text_field(wp_unslash((string) $_GET['kiwi_fraud_flow_key']))
            : '';
        $identity_type = isset($_GET['kiwi_fraud_identity_type'])
            ? strtolower(sanitize_text_field(wp_unslash((string) $_GET['kiwi_fraud_identity_type'])))
            : '';
        $limit = isset($_GET['kiwi_fraud_limit'])
            ? (int) wp_unslash((string) $_GET['kiwi_fraud_limit'])
            : 100;

        if (!in_array($identity_type, ['', 'subscriber', 'session'], true)) {
            $identity_type = '';
        }

        return [
            'service_key' => $service_key,
            'provider_key' => $provider_key,
            'flow_key' => $flow_key,
            'identity_type' => $identity_type,
            'flagged_only' => isset($_GET['kiwi_fraud_flagged_only']) && wp_unslash((string) $_GET['kiwi_fraud_flagged_only']) === '1',
            'limit' => max(1, min(500, $limit)),
        ];
    }
}
