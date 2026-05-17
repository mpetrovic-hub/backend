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
        $output .= $this->render_filter_form($filters);

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
        $output .= '<table class="kiwi-table">';
        $output .= '<thead><tr>';

        foreach (self::EXPORT_COLUMNS as $label) {
            $output .= '<th>' . esc_html($label) . '</th>';
        }

        $output .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $output .= '<tr>';

            foreach (array_keys(self::EXPORT_COLUMNS) as $field) {
                $output .= '<td>' . esc_html($this->format_cell($row[$field] ?? '')) . '</td>';
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

    private function render_filter_form(array $filters): string
    {
        $export_url = $this->build_export_url($filters);
        $output = '';

        $output .= '<form class="kiwi-card kiwi-form-card" method="get">';
        $output .= '<div class="kiwi-form-grid">';
        $output .= '<label><span>From</span><input type="text" name="kiwi_stats_from" value="' . esc_attr((string) ($filters['from'] ?? '')) . '" placeholder="YYYY-MM-DD HH:MM:SS"></label>';
        $output .= '<label><span>To</span><input type="text" name="kiwi_stats_to" value="' . esc_attr((string) ($filters['to'] ?? '')) . '" placeholder="optional"></label>';
        $output .= '<label><span>Service Key</span><input type="text" name="kiwi_stats_service_key" value="' . esc_attr((string) ($filters['service_key'] ?? '')) . '" placeholder="all"></label>';
        $output .= '<label><span>TK Source</span><input type="text" name="kiwi_stats_tksource" value="' . esc_attr((string) ($filters['tksource'] ?? '')) . '" placeholder="all"></label>';
        $output .= '<label><span>Limit</span><input type="number" min="1" max="500" name="kiwi_stats_limit" value="' . esc_attr((string) ($filters['limit'] ?? 100)) . '"></label>';
        $output .= '</div>';
        $output .= '<div class="kiwi-form-actions">';
        $output .= '<button class="kiwi-button" type="submit">Filter</button>';
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
}
