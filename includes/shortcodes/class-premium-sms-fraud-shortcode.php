<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Premium_Sms_Fraud_Shortcode
{
    private $repository;
    private $landing_engagement_repository;
    private $config;
    private $frontend_auth_gate;

    public function __construct(
        Kiwi_Premium_Sms_Fraud_Signal_Repository $repository,
        ?Kiwi_Config $config = null,
        ?Kiwi_Frontend_Auth_Gate $frontend_auth_gate = null,
        ?Kiwi_Premium_Sms_Landing_Engagement_Repository $landing_engagement_repository = null
    ) {
        $this->repository = $repository;
        $this->config = $config instanceof Kiwi_Config
            ? $config
            : new Kiwi_Config();
        $this->frontend_auth_gate = $frontend_auth_gate instanceof Kiwi_Frontend_Auth_Gate
            ? $frontend_auth_gate
            : new Kiwi_Frontend_Auth_Gate();
        $this->landing_engagement_repository = $landing_engagement_repository;
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
        $filter_options = $this->build_filter_options();
        $rows = $this->repository->get_recent($filters, (int) ($filters['limit'] ?? 100));
        $engagement_rows = $this->load_engagement_rows($filters);

        $output = '';
        $output .= '<section class="kiwi-page-shell" aria-label="Premium SMS Fraud Monitor">';
        $output .= '<header class="kiwi-section-header">';
        $output .= '<div class="kiwi-section-header-content">';
        $output .= '<h2 class="kiwi-page-title">Premium SMS Fraud Monitor</h2>';
        $output .= '<p class="kiwi-page-subtitle">Review inbound MO volume snapshots and soft flags by identity.</p>';
        $output .= '</div>';
        $output .= '</header>';
        $output .= $this->render_filter_form($filters, $filter_options);

        if (empty($rows) && empty($engagement_rows)) {
            $output .= '<div class="kiwi-notice kiwi-notice--info"><p>No fraud-monitor rows found for the selected filters.</p></div>';
            $output .= '</section>';

            return $output;
        }

        if (!empty($rows)) {
            $output .= '<section class="kiwi-card kiwi-table-card">';
            $output .= '<h4 class="kiwi-section-title">MO Fraud Signals</h4>';
            $output .= '<div class="kiwi-table-wrap">';
            $output .= '<table class="kiwi-table">';
            $output .= '<thead><tr>';
            $output .= '<th>Last Updated</th>';
            $output .= '<th>Service</th>';
            $output .= '<th>Provider</th>';
            $output .= '<th>PID</th>';
            $output .= '<th>Click ID</th>';
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
                $output .= '<td>' . esc_html((string) ($row['pid'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['click_id'] ?? '')) . '</td>';
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
        }

        if (!empty($engagement_rows)) {
            $output .= '<section class="kiwi-card kiwi-table-card">';
            $output .= '<h4 class="kiwi-section-title">Landing Engagement Signals</h4>';
            $output .= '<div class="kiwi-table-wrap">';
            $output .= '<table class="kiwi-table">';
            $output .= '<thead><tr>';
            $output .= '<th>Last Updated</th>';
            $output .= '<th>Service</th>';
            $output .= '<th>Provider</th>';
            $output .= '<th>PID</th>';
            $output .= '<th>Click ID</th>';
            $output .= '<th>Landing</th>';
            $output .= '<th>Session</th>';
            $output .= '<th>Page Loaded</th>';
            $output .= '<th>First CTA Click</th>';
            $output .= '<th>Delta (Load->First CTA)</th>';
            $output .= '<th>Last CTA Click</th>';
            $output .= '<th>CTA Clicks</th>';
            $output .= '<th>Last Event</th>';
            $output .= '<th>Soft Flag</th>';
            $output .= '<th>Reason</th>';
            $output .= '</tr></thead><tbody>';

            foreach ($engagement_rows as $row) {
                $engagement_soft_flag = $this->resolve_engagement_soft_flag($row);
                $engagement_delta = $this->resolve_engagement_delta_label($row);
                $flag_text = !empty($engagement_soft_flag['is_soft_flag']) ? '1' : '0';
                $flag_class = !empty($engagement_soft_flag['is_soft_flag']) ? 'kiwi-status--failure' : 'kiwi-status--success';

                $output .= '<tr>';
                $output .= '<td>' . esc_html((string) ($row['updated_at'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['service_key'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['provider_key'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['pid'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['click_id'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['landing_key'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['session_token'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['page_loaded_at'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['first_cta_click_at'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html($engagement_delta) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['last_cta_click_at'] ?? '')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['cta_click_count'] ?? '0')) . '</td>';
                $output .= '<td>' . esc_html((string) ($row['last_event_at'] ?? '')) . '</td>';
                $output .= '<td class="' . esc_attr($flag_class) . '">' . esc_html($flag_text) . '</td>';
                $output .= '<td>' . esc_html((string) ($engagement_soft_flag['soft_flag_reason'] ?? '')) . '</td>';
                $output .= '</tr>';
            }

            $output .= '</tbody></table>';
            $output .= '</div>';
            $output .= '</section>';
        }

        $output .= '</section>';

        return $output;
    }

    private function render_filter_form(array $filters, array $filter_options): string
    {
        $service_key = (string) ($filters['service_key'] ?? '');
        $provider_key = (string) ($filters['provider_key'] ?? '');
        $pid = (string) ($filters['pid'] ?? '');
        $identity_type = (string) ($filters['identity_type'] ?? '');
        $flagged_only = !empty($filters['flagged_only']);
        $limit = (int) ($filters['limit'] ?? 100);
        $service_keys = isset($filter_options['service_keys']) && is_array($filter_options['service_keys'])
            ? $filter_options['service_keys']
            : [];
        $provider_keys = isset($filter_options['provider_keys']) && is_array($filter_options['provider_keys'])
            ? $filter_options['provider_keys']
            : [];

        $output = '';
        $output .= '<form method="get" class="kiwi-form kiwi-form-card">';
        $output .= '<div class="kiwi-form-row kiwi-form-row-inline">';
        $output .= '<div class="kiwi-field kiwi-field--compact">';
        $output .= '<label class="kiwi-field-label" for="kiwi_fraud_service_key">Service Key</label>';
        $output .= '<select id="kiwi_fraud_service_key" class="kiwi-select kiwi-width-small" name="kiwi_fraud_service_key">';
        $output .= '<option value="">all</option>';

        foreach ($service_keys as $option) {
            $output .= '<option value="' . esc_attr($option) . '"' . selected($service_key, $option, false) . '>' . esc_html($option) . '</option>';
        }

        $output .= '</select>';
        $output .= '</div>';
        $output .= '<div class="kiwi-field kiwi-field--compact">';
        $output .= '<label class="kiwi-field-label" for="kiwi_fraud_provider_key">Provider Key</label>';
        $output .= '<select id="kiwi_fraud_provider_key" class="kiwi-select kiwi-width-small" name="kiwi_fraud_provider_key">';
        $output .= '<option value="">all</option>';

        foreach ($provider_keys as $option) {
            $output .= '<option value="' . esc_attr($option) . '"' . selected($provider_key, $option, false) . '>' . esc_html($option) . '</option>';
        }

        $output .= '</select>';
        $output .= '</div>';
        $output .= '<div class="kiwi-field kiwi-field--compact">';
        $output .= '<label class="kiwi-field-label" for="kiwi_fraud_pid">PID</label>';
        $output .= '<input id="kiwi_fraud_pid" class="kiwi-input kiwi-width-small" type="text" name="kiwi_fraud_pid" value="' . esc_attr($pid) . '">';
        $output .= '</div>';
        $output .= '<div class="kiwi-field kiwi-field--compact">';
        $output .= '<label class="kiwi-field-label" for="kiwi_fraud_identity_type">Identity Type</label>';
        $output .= '<select id="kiwi_fraud_identity_type" class="kiwi-select kiwi-width-small" name="kiwi_fraud_identity_type">';
        $output .= '<option value="">all</option>';
        $output .= '<option value="subscriber"' . selected($identity_type, 'subscriber', false) . '>subscriber</option>';
        $output .= '<option value="session"' . selected($identity_type, 'session', false) . '>session</option>';
        $output .= '</select>';
        $output .= '</div>';
        $output .= '<div class="kiwi-field kiwi-field--compact">';
        $output .= '<label class="kiwi-field-label" for="kiwi_fraud_limit">Limit</label>';
        $output .= '<input id="kiwi_fraud_limit" class="kiwi-input kiwi-width-small" type="number" min="1" max="500" name="kiwi_fraud_limit" value="' . esc_attr((string) $limit) . '">';
        $output .= '</div>';
        $output .= '<div class="kiwi-field kiwi-field--checkbox">';
        $output .= '<label class="kiwi-field-label" for="kiwi_fraud_flagged_only">Flagged only</label>';
        $output .= '<input id="kiwi_fraud_flagged_only" type="checkbox" name="kiwi_fraud_flagged_only" value="1"' . ($flagged_only ? ' checked="checked"' : '') . '>';
        $output .= '</div>';
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
        $pid = isset($_GET['kiwi_fraud_pid'])
            ? $this->sanitize_pid_from_request(wp_unslash((string) $_GET['kiwi_fraud_pid']))
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
            'pid' => $pid,
            'identity_type' => $identity_type,
            'flagged_only' => isset($_GET['kiwi_fraud_flagged_only']) && wp_unslash((string) $_GET['kiwi_fraud_flagged_only']) === '1',
            'limit' => max(1, min(500, $limit)),
        ];
    }

    private function build_filter_options(): array
    {
        $rows = $this->repository->get_recent([], 500);
        $service_keys = [];
        $provider_keys = [];

        foreach ($rows as $row) {
            $service_key = trim((string) ($row['service_key'] ?? ''));
            $provider_key = trim((string) ($row['provider_key'] ?? ''));

            if ($service_key !== '') {
                $service_keys[] = $service_key;
            }

            if ($provider_key !== '') {
                $provider_keys[] = $provider_key;
            }
        }

        $service_keys = array_merge($service_keys, $this->discover_configured_service_keys());

        foreach ($this->config->get_landing_pages() as $landing_page) {
            if (!is_array($landing_page)) {
                continue;
            }

            $landing_service_key = trim((string) ($landing_page['service_key'] ?? ''));
            $landing_provider_key = trim((string) ($landing_page['provider'] ?? ''));

            if ($landing_service_key !== '') {
                $service_keys[] = $landing_service_key;
            }

            if ($landing_provider_key !== '') {
                $provider_keys[] = $landing_provider_key;
            }
        }

        $service_keys = array_values(array_unique($service_keys));
        $provider_keys = array_values(array_unique($provider_keys));
        sort($service_keys, SORT_STRING);
        sort($provider_keys, SORT_STRING);

        return [
            'service_keys' => $service_keys,
            'provider_keys' => $provider_keys,
        ];
    }

    private function discover_configured_service_keys(): array
    {
        $service_keys = [];
        $methods = get_class_methods($this->config);

        if (!is_array($methods)) {
            return [];
        }

        foreach ($methods as $method) {
            if (!is_string($method) || !preg_match('/^get_.+_services$/', $method)) {
                continue;
            }

            if (!is_callable([$this->config, $method])) {
                continue;
            }

            try {
                $reflection = new ReflectionMethod($this->config, $method);

                if ($reflection->getNumberOfRequiredParameters() > 0) {
                    continue;
                }

                $services = $this->config->{$method}();
            } catch (Throwable $error) {
                continue;
            }

            if (!is_array($services)) {
                continue;
            }

            foreach ($services as $service_key => $service_config) {
                $candidate = trim((string) $service_key);

                if ($candidate !== '' && is_array($service_config)) {
                    $service_keys[] = $candidate;
                }

                if (is_array($service_config)) {
                    $embedded_service_key = trim((string) ($service_config['service_key'] ?? ''));

                    if ($embedded_service_key !== '') {
                        $service_keys[] = $embedded_service_key;
                    }
                }
            }
        }

        return array_values(array_unique($service_keys));
    }

    private function load_engagement_rows(array $filters): array
    {
        if (!$this->landing_engagement_repository instanceof Kiwi_Premium_Sms_Landing_Engagement_Repository) {
            return [];
        }

        $rows = $this->landing_engagement_repository->get_recent([
            'service_key' => (string) ($filters['service_key'] ?? ''),
            'provider_key' => (string) ($filters['provider_key'] ?? ''),
            'flow_key' => (string) ($filters['flow_key'] ?? ''),
            'pid' => (string) ($filters['pid'] ?? ''),
        ], (int) ($filters['limit'] ?? 100));

        if (empty($filters['flagged_only'])) {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row): bool {
            $result = $this->resolve_engagement_soft_flag($row);

            return !empty($result['is_soft_flag']);
        }));
    }

    private function resolve_engagement_soft_flag(array $row): array
    {
        $reasons = [];
        $page_loaded_at = trim((string) ($row['page_loaded_at'] ?? ''));
        $first_cta_click_at = trim((string) ($row['first_cta_click_at'] ?? ''));
        $last_cta_click_at = trim((string) ($row['last_cta_click_at'] ?? ''));
        $cta_click_count = max(0, (int) ($row['cta_click_count'] ?? 0));
        $has_click_signal = $cta_click_count > 0 || $first_cta_click_at !== '' || $last_cta_click_at !== '';

        if ($has_click_signal && $page_loaded_at === '') {
            $reasons[] = 'missing_load';
        }

        if ($page_loaded_at !== '' && $first_cta_click_at !== '') {
            $delta_seconds = $this->seconds_delta($page_loaded_at, $first_cta_click_at);
            $min_seconds = max(0, (int) $this->config->get_premium_sms_fraud_mo_min_seconds_after_load());

            if ($delta_seconds !== null && $delta_seconds < 0) {
                $reasons[] = 'click_before_load';
            } elseif ($delta_seconds !== null && $delta_seconds < $min_seconds) {
                $reasons[] = 'fast_click';
            }
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'is_soft_flag' => !empty($reasons),
            'soft_flag_reason' => implode(' OR ', $reasons),
        ];
    }

    private function resolve_engagement_delta_label(array $row): string
    {
        $page_loaded_at = trim((string) ($row['page_loaded_at'] ?? ''));
        $first_cta_click_at = trim((string) ($row['first_cta_click_at'] ?? ''));

        if ($page_loaded_at === '' || $first_cta_click_at === '') {
            return '';
        }

        $delta_seconds = $this->seconds_delta($page_loaded_at, $first_cta_click_at);

        if ($delta_seconds === null) {
            return '';
        }

        return (string) $delta_seconds . 's';
    }

    private function seconds_delta(string $from, string $to): ?int
    {
        $from_ts = strtotime($from);
        $to_ts = strtotime($to);

        if ($from_ts === false || $to_ts === false) {
            return null;
        }

        return (int) ($to_ts - $from_ts);
    }

    private function sanitize_pid_from_request(string $pid): string
    {
        $pid = trim($pid);

        if ($pid === '') {
            return '';
        }

        $pid = preg_replace('/[^A-Za-z0-9._~:-]/', '', $pid);
        $pid = is_string($pid) ? $pid : '';

        return substr($pid, 0, 191);
    }
}
