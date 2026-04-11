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

        $output = '<section class="kiwi-lp-gallery" aria-label="Landing Pages Gallery">';
        $output .= '<header class="kiwi-lp-gallery__header">';
        $output .= '<div>';
        $output .= '<h2 class="kiwi-lp-gallery__title">Landing Pages</h2>';
        $output .= '<p class="kiwi-lp-gallery__subtitle">Filesystem-discovered previews with routing metadata.</p>';
        $output .= '</div>';
        $output .= '<p class="kiwi-lp-gallery__count" aria-live="polite">' . esc_html((string) $entry_count) . ' page(s)</p>';
        $output .= '</header>';

        if (!empty($errors)) {
            $output .= '<details class="kiwi-lp-gallery__warnings">';
            $output .= '<summary>Discovery warnings (' . esc_html((string) count($errors)) . ')</summary>';
            $output .= '<ul>';

            foreach ($errors as $error) {
                $output .= '<li>' . esc_html((string) $error) . '</li>';
            }

            $output .= '</ul>';
            $output .= '</details>';
        }

        if (empty($entries)) {
            $output .= '<div class="kiwi-lp-gallery__empty">';
            $output .= '<h3>No valid landing pages found</h3>';
            $output .= '<p>Check <code>landing-pages/</code> folders and <code>integration.php</code> metadata.</p>';
            $output .= '</div>';
            $output .= '</section>';

            return $output;
        }

        $output .= '<div class="kiwi-lp-gallery__grid">';

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
        $routing_mode = (string) ($entry['routing_mode'] ?? 'unknown');
        $flow = trim((string) ($entry['flow'] ?? ''));
        $service_key = trim((string) ($entry['service_key'] ?? ''));
        $provider = trim((string) ($entry['provider'] ?? ''));

        $output = '<article class="kiwi-lp-card" tabindex="0">';
        $output .= '<header class="kiwi-lp-card__header">';
        $output .= '<h3 class="kiwi-lp-card__title">' . esc_html($key) . '</h3>';
        $output .= '<div class="kiwi-lp-card__badges">';
        $output .= '<span class="kiwi-lp-card__badge">' . esc_html($country !== '' ? $country : 'N/A') . '</span>';
        $output .= '<span class="kiwi-lp-card__badge kiwi-lp-card__badge--mode">' . esc_html($routing_mode) . '</span>';
        $output .= '</div>';
        $output .= '</header>';

        $output .= $this->render_preview_block($entry);

        $output .= '<dl class="kiwi-lp-card__meta">';
        $output .= $this->render_meta_item('Country', $country);
        $output .= $this->render_meta_item('Key', $key);
        $output .= $this->render_meta_item('Flow', $flow);
        $output .= $this->render_meta_item('Service', $service_key);
        $output .= $this->render_meta_item('Provider', $provider);
        $output .= '</dl>';

        $output .= $this->render_url_block($entry);
        $output .= '</article>';

        return $output;
    }

    private function render_preview_block(array $entry): string
    {
        $preview_url = trim((string) ($entry['preview_url'] ?? ''));
        $key = (string) ($entry['key'] ?? 'landing-page');
        $preview_srcdoc = $this->build_local_preview_srcdoc($entry);

        $output = '<div class="kiwi-lp-card__preview">';

        if ($preview_url === '' && $preview_srcdoc === '') {
            $output .= '<div class="kiwi-lp-card__preview-placeholder">Preview unavailable</div>';
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

        if ($preview_url !== '') {
            $output .= '<a class="kiwi-lp-card__preview-link" href="' . esc_attr($preview_url) . '" target="_blank" rel="noopener noreferrer">';
            $output .= 'Open preview URL';
            $output .= '</a>';
        }

        $output .= '</div>';

        return $output;
    }

    private function render_url_block(array $entry): string
    {
        $public_urls = is_array($entry['public_urls'] ?? null) ? $entry['public_urls'] : [];
        $backend_path = trim((string) ($entry['backend_path'] ?? ''));

        $output = '<section class="kiwi-lp-card__urls">';
        $output .= '<h4>URL</h4>';

        if (empty($public_urls)) {
            $output .= '<p class="kiwi-lp-card__hint">No reachable URL metadata found.</p>';

            if ($backend_path !== '') {
                $output .= '<p class="kiwi-lp-card__hint">Backend path strategy: <code>' . esc_html($backend_path) . '</code></p>';
            }

            $output .= '</section>';

            return $output;
        }

        $primary_url = $this->resolve_primary_url_for_display($public_urls);

        if (is_array($primary_url)) {
            $primary_display = $primary_url;
            $primary_display['label'] = 'URL';
            $primary_display['inferred'] = false;

            $output .= '<p class="kiwi-lp-card__primary-url">';
            $output .= $this->render_single_url($primary_display);
            $output .= '</p>';
        }

        $output .= '</section>';

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

    private function render_single_url(array $url_item): string
    {
        $url = trim((string) ($url_item['url'] ?? ''));
        $label = trim((string) ($url_item['label'] ?? 'URL'));
        $is_inferred = !empty($url_item['inferred']);
        $is_path_only = !empty($url_item['path_only']);

        if ($url === '') {
            return '';
        }

        $label_suffix = $is_inferred ? ' (inferred)' : '';
        $label_text = $label . $label_suffix;

        if ($is_path_only) {
            return '<span class="kiwi-lp-card__url-label">' . esc_html($label_text) . ':</span> <code>' . esc_html($url) . '</code>';
        }

        return '<span class="kiwi-lp-card__url-label">' . esc_html($label_text) . ':</span> '
            . '<a href="' . esc_attr($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($url) . '</a>';
    }

    private function render_meta_item(string $label, string $value): string
    {
        $display_value = trim($value) !== '' ? $value : 'N/A';

        return '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html($display_value) . '</dd></div>';
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
