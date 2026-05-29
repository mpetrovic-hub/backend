<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Statistics_Shortcode
{
    public const EXPORT_COLUMNS = [
        'metric_date' => 'Date',
        'landing_key' => 'Landing',
        'service_key' => 'Service',
        'provider_key' => 'Provider',
        'flow_key' => 'Flow',
        'country' => 'Country',
        'pid' => 'PID',
        'tksource' => 'TK Source',
        'tkzone' => 'TK Zone',
        'device_brand' => 'Device Brand',
        'os' => 'OS',
        'os_version' => 'OS Version',
        'browser' => 'Browser',
        'client_ip_version' => 'IP Version',
        'client_ip_prefix' => 'IP Prefix',
        'sessions' => 'Sessions',
        'page_loaded_sessions' => 'Page Loaded Sessions',
        'cta1_sessions' => 'CTA1 Sessions',
        'cta1_click_events' => 'CTA1 Click Events',
        'cta2_sessions' => 'CTA2 Sessions',
        'cta2_click_events' => 'CTA2 Click Events',
        'cta3_sessions' => 'CTA3 Sessions',
        'cta3_click_events' => 'CTA3 Click Events',
        'handoff_attempts' => 'Handoff Attempts',
        'handoff_successes' => 'Handoff Successes',
        'handoff_fails' => 'Handoff Fails',
        'handoff_rate_pct' => 'Handoff Rate %',
        'min_hidden_seconds' => 'Min Hidden s',
        'median_hidden_seconds' => 'Median Hidden s',
        'max_hidden_seconds' => 'Max Hidden s',
        'sales' => 'Sales',
        'sales_amount_minor' => 'Sales Amount Minor',
    ];

    private $repository;
    private $frontend_auth_gate;

    public function __construct(
        Kiwi_Statistics_Read_Repository_Interface $repository,
        ?Kiwi_Frontend_Auth_Gate $frontend_auth_gate = null
    ) {
        $this->repository = $repository;
        $this->frontend_auth_gate = $frontend_auth_gate instanceof Kiwi_Frontend_Auth_Gate
            ? $frontend_auth_gate
            : new Kiwi_Frontend_Auth_Gate();
    }

    public function register(): void
    {
        add_shortcode('kiwi_statistics', [$this, 'render']);
    }

    public function render(): string
    {
        if (!$this->frontend_auth_gate->can_access_tools()) {
            return $this->frontend_auth_gate->render_login_form([
                'message' => 'Please sign in to access the Statistics tool.',
            ]);
        }

        $filters = $this->read_filters_from_request();
        $filter_options = $this->repository->get_filter_options($filters);
        $rows = $this->repository->get_rows($filters, (int) ($filters['limit'] ?? 100));
        $error = $this->repository->get_last_error();

        $output = '';
        $output .= '<section class="kiwi-page-shell" aria-label="Traffic Source Funnel Statistics">';
        $output .= '<header class="kiwi-section-header">';
        $output .= '<div class="kiwi-section-header-content">';
        $output .= '<h2 class="kiwi-page-title">Statistics</h2>';
        $output .= '<p class="kiwi-page-subtitle">Daily landing funnel summary grouped by source, landing, service, and device dimensions.</p>';
        $output .= '</div>';
        $output .= '</header>';
        $output .= $this->render_filter_form($filters, $filter_options);

        if ($error !== '') {
            $output .= '<div class="kiwi-notice kiwi-notice--error"><p>Statistics source '
                . esc_html($this->repository->get_source_name())
                . ' is not readable: '
                . esc_html($error)
                . '</p></div>';
            $output .= '</section>';

            return $output;
        }

        if (empty($rows)) {
            $output .= '<div class="kiwi-notice kiwi-notice--info"><p>No statistics rows found for the selected filters.</p></div>';
            $output .= '</section>';

            return $output;
        }

        $output .= '<section class="kiwi-card kiwi-table-card">';
        $output .= '<h4 class="kiwi-section-title">Landing Funnel Daily Summary</h4>';
        $output .= '<div class="kiwi-table-wrap">';
        $output .= '<table class="kiwi-table kiwi-table--statistics">';
        $output .= '<thead><tr>';

        foreach (self::EXPORT_COLUMNS as $field => $label) {
            $output .= '<th class="' . esc_attr($this->get_column_class($field)) . '" title="' . esc_attr($label) . '">' . esc_html($label) . '</th>';
        }

        $output .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $output .= '<tr>';

            foreach (array_keys(self::EXPORT_COLUMNS) as $field) {
                $cell = $this->format_cell($row[$field] ?? '');
                $output .= '<td class="' . esc_attr($this->get_column_class($field)) . '" title="' . esc_attr($cell) . '">' . esc_html($cell) . '</td>';
            }

            $output .= '</tr>';
        }

        $output .= '</tbody></table>';
        $output .= '</div>';
        $output .= '</section>';
        $output .= '</section>';

        return $output;
    }

    public function read_filters_from_request(): array
    {
        $filters = [
            'from' => $this->repository->get_default_from(),
            'to' => '',
            'service_key' => '',
            'landing_key' => '',
            'tksource' => '',
            'tkzone' => '',
            'device_brand' => '',
            'os' => '',
            'os_version' => '',
            'browser' => '',
            'client_ip_version' => '',
            'client_ip_prefix' => '',
            'limit' => 100,
        ];

        if (isset($_GET['kiwi_stats_from'])) {
            $filters['from'] = sanitize_text_field(wp_unslash($_GET['kiwi_stats_from']));
        }

        if (isset($_GET['kiwi_stats_to'])) {
            $filters['to'] = sanitize_text_field(wp_unslash($_GET['kiwi_stats_to']));
        }

        if (isset($_GET['kiwi_stats_service_key'])) {
            $filters['service_key'] = sanitize_text_field(wp_unslash($_GET['kiwi_stats_service_key']));
        }

        if (isset($_GET['kiwi_stats_landing_key'])) {
            $filters['landing_key'] = sanitize_text_field(wp_unslash($_GET['kiwi_stats_landing_key']));
        }

        if (isset($_GET['kiwi_stats_tksource'])) {
            $filters['tksource'] = sanitize_text_field(wp_unslash($_GET['kiwi_stats_tksource']));
        }

        if (isset($_GET['kiwi_stats_tkzone'])) {
            $filters['tkzone'] = sanitize_text_field(wp_unslash($_GET['kiwi_stats_tkzone']));
        }

        if (isset($_GET['kiwi_stats_device_brand'])) {
            $filters['device_brand'] = sanitize_text_field(wp_unslash($_GET['kiwi_stats_device_brand']));
        }

        if (isset($_GET['kiwi_stats_os'])) {
            $filters['os'] = sanitize_text_field(wp_unslash($_GET['kiwi_stats_os']));
        }

        if (isset($_GET['kiwi_stats_os_version'])) {
            $filters['os_version'] = sanitize_text_field(wp_unslash($_GET['kiwi_stats_os_version']));
        } elseif (isset($_GET['kiwi_stats_android_version'])) {
            $filters['os_version'] = sanitize_text_field(wp_unslash($_GET['kiwi_stats_android_version']));
        }

        if (isset($_GET['kiwi_stats_browser'])) {
            $filters['browser'] = sanitize_text_field(wp_unslash($_GET['kiwi_stats_browser']));
        }

        if (isset($_GET['kiwi_stats_client_ip_version'])) {
            $filters['client_ip_version'] = sanitize_text_field(wp_unslash($_GET['kiwi_stats_client_ip_version']));
        }

        if (isset($_GET['kiwi_stats_client_ip_prefix'])) {
            $filters['client_ip_prefix'] = sanitize_text_field(wp_unslash($_GET['kiwi_stats_client_ip_prefix']));
        }

        if (isset($_GET['kiwi_stats_limit'])) {
            $filters['limit'] = (int) sanitize_text_field(wp_unslash($_GET['kiwi_stats_limit']));
        }

        return $this->repository->normalize_filters($filters);
    }

    private function render_filter_form(array $filters, array $filter_options): string
    {
        $export_url = $this->build_export_url($filters);
        $service_key = (string) ($filters['service_key'] ?? '');
        $landing_key = (string) ($filters['landing_key'] ?? '');
        $tksource = (string) ($filters['tksource'] ?? '');
        $tkzone = (string) ($filters['tkzone'] ?? '');
        $device_brand = (string) ($filters['device_brand'] ?? '');
        $os = (string) ($filters['os'] ?? '');
        $os_version = (string) ($filters['os_version'] ?? '');
        $browser = (string) ($filters['browser'] ?? '');
        $client_ip_version = (string) ($filters['client_ip_version'] ?? '');
        $client_ip_prefix = (string) ($filters['client_ip_prefix'] ?? '');

        $output = '';

        $output .= '<form class="kiwi-form kiwi-form-card" method="get">';
        $output .= '<div class="kiwi-form-row kiwi-form-row-inline">';
        $output .= '<div class="kiwi-field kiwi-field--compact">';
        $output .= '<label class="kiwi-field-label" for="kiwi_stats_from">From</label>';
        $output .= '<input id="kiwi_stats_from" class="kiwi-input kiwi-width-small" type="date" name="kiwi_stats_from" value="' . esc_attr($this->format_date_value((string) ($filters['from'] ?? ''))) . '">';
        $output .= '</div>';
        $output .= '<div class="kiwi-field kiwi-field--compact">';
        $output .= '<label class="kiwi-field-label" for="kiwi_stats_to">To</label>';
        $output .= '<input id="kiwi_stats_to" class="kiwi-input kiwi-width-small" type="date" name="kiwi_stats_to" value="' . esc_attr($this->format_date_value((string) ($filters['to'] ?? ''))) . '">';
        $output .= '</div>';
        $output .= $this->render_select_filter('kiwi_stats_service_key', 'Service Key', $service_key, $this->get_filter_options_for_key($filter_options, 'service_keys'));
        $output .= $this->render_select_filter('kiwi_stats_landing_key', 'Landing Key', $landing_key, $this->get_filter_options_for_key($filter_options, 'landing_keys'));
        $output .= $this->render_select_filter('kiwi_stats_tksource', 'TK Source', $tksource, $this->get_filter_options_for_key($filter_options, 'tksources'));
        $output .= $this->render_select_filter('kiwi_stats_tkzone', 'TK Zone', $tkzone, $this->get_filter_options_for_key($filter_options, 'tkzones'));
        $output .= $this->render_select_filter('kiwi_stats_device_brand', 'Device Brand', $device_brand, $this->get_filter_options_for_key($filter_options, 'device_brands'));
        $output .= $this->render_select_filter('kiwi_stats_os', 'OS', $os, $this->get_filter_options_for_key($filter_options, 'os_values'));
        $output .= $this->render_select_filter('kiwi_stats_os_version', 'OS Version', $os_version, $this->get_filter_options_for_key($filter_options, 'os_versions'));
        $output .= $this->render_select_filter('kiwi_stats_browser', 'Browser', $browser, $this->get_filter_options_for_key($filter_options, 'browsers'));
        $output .= $this->render_select_filter('kiwi_stats_client_ip_version', 'IP Version', $client_ip_version, $this->get_filter_options_for_key($filter_options, 'client_ip_versions'));
        $output .= $this->render_select_filter('kiwi_stats_client_ip_prefix', 'IP Prefix', $client_ip_prefix, $this->get_filter_options_for_key($filter_options, 'client_ip_prefixes'));
        $output .= '<div class="kiwi-field kiwi-field--compact">';
        $output .= '<label class="kiwi-field-label" for="kiwi_stats_limit">Limit</label>';
        $output .= '<input id="kiwi_stats_limit" class="kiwi-input kiwi-width-small" type="number" min="1" max="500" name="kiwi_stats_limit" value="' . esc_attr((string) ($filters['limit'] ?? 100)) . '">';
        $output .= '</div>';
        $output .= '</div>';
        $output .= '<div class="kiwi-form-actions kiwi-actions">';
        $output .= '<button class="kiwi-button kiwi-submit-button" type="submit">Filter</button>';
        $output .= '<a class="kiwi-button kiwi-button--secondary" href="' . esc_attr($export_url) . '">Export CSV</a>';
        $output .= '</div>';
        $output .= '</form>';

        return $output;
    }

    private function build_export_url(array $filters): string
    {
        $params = [
            'kiwi_statistics_export' => '1',
            'kiwi_stats_from' => (string) ($filters['from'] ?? ''),
            'kiwi_stats_to' => (string) ($filters['to'] ?? ''),
            'kiwi_stats_service_key' => (string) ($filters['service_key'] ?? ''),
            'kiwi_stats_landing_key' => (string) ($filters['landing_key'] ?? ''),
            'kiwi_stats_tksource' => (string) ($filters['tksource'] ?? ''),
            'kiwi_stats_tkzone' => (string) ($filters['tkzone'] ?? ''),
            'kiwi_stats_device_brand' => (string) ($filters['device_brand'] ?? ''),
            'kiwi_stats_os' => (string) ($filters['os'] ?? ''),
            'kiwi_stats_os_version' => (string) ($filters['os_version'] ?? ''),
            'kiwi_stats_browser' => (string) ($filters['browser'] ?? ''),
            'kiwi_stats_client_ip_version' => (string) ($filters['client_ip_version'] ?? ''),
            'kiwi_stats_client_ip_prefix' => (string) ($filters['client_ip_prefix'] ?? ''),
            'kiwi_stats_limit' => (string) ($filters['limit'] ?? 100),
        ];

        $query = http_build_query($params, '', '&');

        return '?' . $query;
    }

    private function format_cell($value): string
    {
        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    private function get_filter_options_for_key(array $filter_options, string $key): array
    {
        return isset($filter_options[$key]) && is_array($filter_options[$key])
            ? $filter_options[$key]
            : [];
    }

    private function render_select_filter(string $id, string $label, string $selected_value, array $options): string
    {
        if ($selected_value !== '' && !in_array($selected_value, $options, true)) {
            $options[] = $selected_value;
            sort($options, SORT_STRING);
        }

        $output = '';
        $output .= '<div class="kiwi-field kiwi-field--compact">';
        $output .= '<label class="kiwi-field-label" for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
        $output .= '<select id="' . esc_attr($id) . '" class="kiwi-select kiwi-width-small" name="' . esc_attr($id) . '">';
        $output .= '<option value="">all</option>';

        foreach ($options as $option) {
            $output .= '<option value="' . esc_attr($option) . '"' . selected($selected_value, $option, false) . '>' . esc_html($option) . '</option>';
        }

        $output .= '</select>';
        $output .= '</div>';

        return $output;
    }

    private function format_date_value(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T].*)?$/', $value, $matches) !== 1) {
            return '';
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];

        if (!checkdate($month, $day, $year)) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function get_column_class(string $field): string
    {
        $modifier = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace('_', '-', $field));
        $modifier = is_string($modifier) && $modifier !== '' ? $modifier : 'value';

        return 'kiwi-statistics-col kiwi-statistics-col--' . $modifier;
    }
}
