<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Pages_Gallery_Shortcode
{
    private $gallery_service;

    public function __construct(Kiwi_Landing_Page_Gallery_Service $gallery_service)
    {
        $this->gallery_service = $gallery_service;
    }

    public function register(): void
    {
        add_shortcode('kiwi_landing_pages_gallery', [$this, 'render']);
    }

    public function render(): string
    {
        $gallery_data = $this->gallery_service->build_gallery_data();
        $entries = is_array($gallery_data['entries'] ?? null) ? $gallery_data['entries'] : [];
        $errors = is_array($gallery_data['errors'] ?? null) ? $gallery_data['errors'] : [];
        $entry_count = (int) ($gallery_data['count'] ?? count($entries));

        $output = '<section class="kiwi-page-shell kiwi-page-shell--fullwidth" aria-label="Landing Pages Gallery">';
        $output .= '<header class="kiwi-section-header">';
        $output .= '<div class="kiwi-section-header-content">';
        $output .= '<h2 class="kiwi-page-title">Landing Pages</h2>';
        $output .= '<p class="kiwi-page-subtitle">Filesystem-discovered previews with routing metadata.</p>';
        $output .= '</div>';
        $output .= '<p class="kiwi-count-badge" aria-live="polite">' . esc_html((string) $entry_count) . ' page(s)</p>';
        $output .= '</header>';

        if (!empty($errors)) {
            $output .= '<details class="kiwi-notice kiwi-notice--warning kiwi-warning-list">';
            $output .= '<summary>Discovery warnings (' . esc_html((string) count($errors)) . ')</summary>';
            $output .= '<ul>';

            foreach ($errors as $error) {
                $output .= '<li>' . esc_html((string) $error) . '</li>';
            }

            $output .= '</ul>';
            $output .= '</details>';
        }

        if (empty($entries)) {
            $output .= '<div class="kiwi-empty-state">';
            $output .= '<h3>No valid landing pages found</h3>';
            $output .= '<p>Check <code>landing-pages/</code> folders and <code>integration.php</code> metadata.</p>';
            $output .= '</div>';
            $output .= '</section>';

            return $output;
        }

        $output .= '<div class="kiwi-card-grid">';

        foreach ($entries as $entry) {
            $output .= $this->render_entry_card($entry);
        }

        $output .= '</div>';
        $output .= '</section>';

        return $output;
    }

    private function render_entry_card(array $entry): string
    {
        $key = (string) ($entry['key'] ?? '');
        $country = (string) ($entry['country'] ?? '');
        $country_display = $this->format_country_for_display($country);
        $routing_mode = (string) ($entry['routing_mode'] ?? 'unknown');
        $flow = trim((string) ($entry['flow'] ?? ''));
        $service_key = trim((string) ($entry['service_key'] ?? ''));
        $provider = trim((string) ($entry['provider'] ?? ''));

        $output = '<article class="kiwi-card kiwi-preview-card" tabindex="0">';
        $output .= '<header class="kiwi-card-header">';
        $output .= '<h3 class="kiwi-card-title">' . esc_html($key) . '</h3>';
        $output .= '<div class="kiwi-badge-group">';
        $output .= '<span class="kiwi-badge">' . esc_html($country !== '' ? $country : 'N/A') . '</span>';
        $output .= '<span class="kiwi-badge kiwi-badge--muted">' . esc_html($routing_mode) . '</span>';
        $output .= '</div>';
        $output .= '</header>';

        $output .= $this->render_preview_block($entry);

        $output .= '<dl class="kiwi-meta-list">';
        $output .= $this->render_meta_item('Country', $country_display);
        $output .= $this->render_meta_item('Key', $key);
        $output .= $this->render_meta_item('Flow', $flow);
        $output .= $this->render_meta_item('Service', $service_key);
        $output .= $this->render_meta_item('Provider', $provider);
        $output .= '</dl>';
        $output .= '</article>';

        return $output;
    }

    private function render_preview_block(array $entry): string
    {
        $preview_url = trim((string) ($entry['preview_url'] ?? ''));
        $key = (string) ($entry['key'] ?? 'landing-page');
        $preview_srcdoc = $this->build_local_preview_srcdoc($entry);

        $output = '<div class="kiwi-preview">';

        if ($preview_url === '' && $preview_srcdoc === '') {
            $output .= '<div class="kiwi-preview-placeholder">Preview unavailable</div>';
            $output .= '</div>';

            return $output;
        }

        $output .= '<iframe loading="lazy" title="Preview of ' . esc_attr($key) . '"';

        if ($preview_srcdoc !== '') {
            $output .= ' srcdoc="' . esc_attr($preview_srcdoc) . '"';
        } else {
            $output .= ' src="' . esc_attr($preview_url) . '"';
        }

        $output .= ' sandbox="allow-forms allow-same-origin allow-scripts" referrerpolicy="no-referrer"></iframe>';

        $public_urls = is_array($entry['public_urls'] ?? null) ? $entry['public_urls'] : [];
        $primary_url = $this->resolve_primary_url_for_display($public_urls);
        $primary_url_value = '';

        if (is_array($primary_url) && trim((string) ($primary_url['url'] ?? '')) !== '') {
            $primary_url_value = trim((string) ($primary_url['url'] ?? ''));
        } elseif ($preview_url !== '') {
            $primary_url_value = $preview_url;
        }

        if ($primary_url_value !== '') {
            $output .= '<div class="kiwi-preview-urlbar">';
            $output .= '<span class="kiwi-url-label">URL:</span> ';

            if (is_array($primary_url) && !empty($primary_url['path_only'])) {
                $output .= '<code>' . esc_html($primary_url_value) . '</code>';
            } else {
                $output .= '<a class="kiwi-preview-url" href="' . esc_attr($primary_url_value) . '" target="_blank" rel="noopener noreferrer">';
                $output .= esc_html($primary_url_value);
                $output .= '</a>';
            }

            $output .= '<button type="button" class="kiwi-copy-button" aria-label="Copy URL" title="Copy URL" data-copy-text="' . esc_attr($primary_url_value) . '">';
            $output .= '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">';
            $output .= '<path d="M16 1H6c-1.1 0-2 .9-2 2v12h2V3h10V1zm3 4H10c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h9c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H10V7h9v14z"/>';
            $output .= '</svg>';
            $output .= '</button>';
            $output .= '</div>';
        }

        $output .= '</div>';

        return $output;
    }

    private function resolve_primary_url_for_display(array $public_urls): ?array
    {
        foreach ($public_urls as $url_item) {
            if (!is_array($url_item)) {
                continue;
            }

            if (($url_item['absolute'] ?? false) && !empty($url_item['inferred'])) {
                return $url_item;
            }
        }

        foreach ($public_urls as $url_item) {
            if (!is_array($url_item)) {
                continue;
            }

            if (($url_item['absolute'] ?? false) && empty($url_item['inferred'])) {
                return $url_item;
            }
        }

        foreach ($public_urls as $url_item) {
            if (!is_array($url_item)) {
                continue;
            }

            if (!empty($url_item['path_only'])) {
                return $url_item;
            }
        }

        return null;
    }

    private function render_meta_item(string $label, string $value): string
    {
        $display_value = trim($value) !== '' ? $value : 'N/A';

        return '<div class="kiwi-meta-item"><dt>' . esc_html($label) . '</dt><dd>' . esc_html($display_value) . '</dd></div>';
    }

    private function format_country_for_display(string $country): string
    {
        $country = strtoupper(trim($country));

        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return $country !== '' ? $country : 'N/A';
        }

        $first = ord($country[0]) - 65 + 0x1F1E6;
        $second = ord($country[1]) - 65 + 0x1F1E6;
        $flag = html_entity_decode('&#' . $first . ';&#' . $second . ';', ENT_NOQUOTES, 'UTF-8');

        return $flag . ' ' . $country;
    }

    private function build_local_preview_srcdoc(array $entry): string
    {
        $index_path = trim((string) ($entry['index_path'] ?? ''));
        $styles_path = trim((string) ($entry['styles_path'] ?? ''));

        if ($index_path === '' || !is_readable($index_path)) {
            return '';
        }

        $html = file_get_contents($index_path);

        if (!is_string($html) || trim($html) === '') {
            return '';
        }

        $html = str_replace('{{KIWI_PRIMARY_CTA_HREF}}', '#', $html);

        if ($styles_path !== '' && is_readable($styles_path)) {
            $css = file_get_contents($styles_path);

            if (is_string($css) && trim($css) !== '') {
                $style_block = "<style>\n" . $css . "\n</style>";

                if (stripos($html, '</head>') !== false) {
                    $replaced = preg_replace('/<\/head>/i', $style_block . "\n</head>", $html, 1);

                    if (is_string($replaced) && $replaced !== '') {
                        $html = $replaced;
                    }
                } else {
                    $html = $style_block . "\n" . $html;
                }
            }
        }

        return $html;
    }
}
