<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Statistics_Shortcode
{
    public const EXPORT_COLUMNS = [
        'service_key' => 'Service',
        'tksource' => 'TK Source',
        'tkzone' => 'TK Zone',
        'sessions' => 'Sessions',
        'loaded_sessions' => 'Loaded Sessions',
        'cta_sessions' => 'CTA Sessions',
        'cta_click_events' => 'CTA Click Events',
        'cta_session_cr' => 'CTA Session CR %',
        'avg_seconds_load_to_cta' => 'Avg Load->CTA s',
        'median_seconds_load_to_cta' => 'Median Load->CTA s',
        'min_seconds_load_to_cta' => 'Min Load->CTA s',
        'max_seconds_load_to_cta' => 'Max Load->CTA s',
        'successful_sales' => 'Successful Sales',
        'successful_sales_amount_minor' => 'Sales Amount Minor',
        'sales_per_session_cr' => 'Sales/Session CR %',
        'sales_per_cta_session_cr' => 'Sales/CTA Session CR %',
        'successful_sale_ids' => 'Sale IDs',
        'successful_transaction_ids' => 'Transaction IDs',
    ];

    private $repository;
    private $frontend_auth_gate;

    public function __construct(
        Kiwi_Traffic_Source_Funnel_Statistics_Repository $repository,
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
        $output .= '<p class="kiwi-page-subtitle">Traffic-source funnel metrics grouped by service, TK source, and TK zone.</p>';
        $output .= '</div>';
        $output .= '</header>';
        $output .= $this->render_filter_form($filters, $filter_options);

        if ($error !== '') {
            $output .= '<div class="kiwi-notice kiwi-notice--error"><p>Statistics view '
                . esc_html($this->repository->get_view_name())
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
        $output .= '<h4 class="kiwi-section-title">Load to CTA by Traffic Source</h4>';
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
            'tksource' => '',
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

        if (isset($_GET['kiwi_stats_tksource'])) {
            $filters['tksource'] = sanitize_text_field(wp_unslash($_GET['kiwi_stats_tksource']));
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
        $tksource = (string) ($filters['tksource'] ?? '');
        $service_keys = isset($filter_options['service_keys']) && is_array($filter_options['service_keys'])
            ? $filter_options['service_keys']
            : [];
        $tksources = isset($filter_options['tksources']) && is_array($filter_options['tksources'])
            ? $filter_options['tksources']
            : [];

        if ($service_key !== '' && !in_array($service_key, $service_keys, true)) {
            $service_keys[] = $service_key;
            sort($service_keys, SORT_STRING);
        }

        if ($tksource !== '' && !in_array($tksource, $tksources, true)) {
            $tksources[] = $tksource;
            sort($tksources, SORT_STRING);
        }

        $output = '';

        $output .= '<form class="kiwi-form kiwi-form-card" method="get">';
        $output .= '<div class="kiwi-form-row kiwi-form-row-inline">';
        $output .= '<div class="kiwi-field kiwi-field--compact">';
        $output .= '<label class="kiwi-field-label" for="kiwi_stats_from">From</label>';
        $output .= '<input id="kiwi_stats_from" class="kiwi-input kiwi-width-small" type="datetime-local" step="1" name="kiwi_stats_from" value="' . esc_attr($this->format_datetime_local((string) ($filters['from'] ?? ''))) . '">';
        $output .= '</div>';
        $output .= '<div class="kiwi-field kiwi-field--compact">';
        $output .= '<label class="kiwi-field-label" for="kiwi_stats_to">To</label>';
        $output .= '<input id="kiwi_stats_to" class="kiwi-input kiwi-width-small" type="datetime-local" step="1" name="kiwi_stats_to" value="' . esc_attr($this->format_datetime_local((string) ($filters['to'] ?? ''))) . '">';
        $output .= '</div>';
        $output .= '<div class="kiwi-field kiwi-field--compact">';
        $output .= '<label class="kiwi-field-label" for="kiwi_stats_service_key">Service Key</label>';
        $output .= '<select id="kiwi_stats_service_key" class="kiwi-select kiwi-width-small" name="kiwi_stats_service_key">';
        $output .= '<option value="">all</option>';

        foreach ($service_keys as $option) {
            $output .= '<option value="' . esc_attr($option) . '"' . selected($service_key, $option, false) . '>' . esc_html($option) . '</option>';
        }

        $output .= '</select>';
        $output .= '</div>';
        $output .= '<div class="kiwi-field kiwi-field--compact">';
        $output .= '<label class="kiwi-field-label" for="kiwi_stats_tksource">TK Source</label>';
        $output .= '<select id="kiwi_stats_tksource" class="kiwi-select kiwi-width-small" name="kiwi_stats_tksource">';
        $output .= '<option value="">all</option>';

        foreach ($tksources as $option) {
            $output .= '<option value="' . esc_attr($option) . '"' . selected($tksource, $option, false) . '>' . esc_html($option) . '</option>';
        }

        $output .= '</select>';
        $output .= '</div>';
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
            'kiwi_stats_tksource' => (string) ($filters['tksource'] ?? ''),
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

    private function format_datetime_local(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $wall_clock_datetime = $this->format_wall_clock_datetime_local($value);

        if ($wall_clock_datetime !== null) {
            return $wall_clock_datetime;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?$/', $value) === 1) {
            return '';
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return '';
        }

        return gmdate('Y-m-d\TH:i:s', $timestamp);
    }

    private function format_wall_clock_datetime_local(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $matches) !== 1) {
            return null;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        $hour = (int) $matches[4];
        $minute = (int) $matches[5];
        $second = isset($matches[6]) ? (int) $matches[6] : 0;

        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return sprintf('%04d-%02d-%02dT%02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
    }

    private function get_column_class(string $field): string
    {
        $modifier = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace('_', '-', $field));
        $modifier = is_string($modifier) && $modifier !== '' ? $modifier : 'value';

        return 'kiwi-statistics-col kiwi-statistics-col--' . $modifier;
    }
}
