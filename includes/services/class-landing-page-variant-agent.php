<?php
if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Page_Variant_Agent
{
    private $project_root;
    private $landing_pages_root;
    private $research_fetcher;
    private $instructions_loaded = false;
    private $instructions_cache = [];

    public function __construct(string $project_root, ?string $landing_pages_root = null, $research_fetcher = null)
    {
        $this->project_root = rtrim($project_root, '/\\');
        $this->landing_pages_root = $landing_pages_root !== null && trim($landing_pages_root) !== ''
            ? rtrim($landing_pages_root, '/\\')
            : $this->project_root . DIRECTORY_SEPARATOR . 'landing-pages';
        $this->research_fetcher = $research_fetcher;
    }

    public function create_landing_page_variants_by_title(string $landing_page_title, array $options = []): array
    {
        $instructions = $this->read_repository_instructions();
        $landing_page = $this->resolve_landing_page_by_title($landing_page_title);
        $source_html = $this->read_required_file((string) ($landing_page['index_path'] ?? ''), 'Landing-page HTML source');
        $protected = $this->extract_protected_content($source_html);
        $integration = $this->read_integration_metadata((string) ($landing_page['integration_path'] ?? ''));
        $guardrails = $this->build_integration_guardrails($integration);
        $research = $this->collect_research_insights($landing_page, $integration, $options);
        $variant_html = $this->inject_style_block($source_html, $this->minimal_variant_css(), 'minimal-cta-emphasis');
        $this->assert_protected_content_preserved($variant_html, $protected);

        return [
            'workflow' => [
                'mandatory_instruction_read' => true,
                'sequence' => [
                    'read README.md',
                    'read agents.md',
                    'locate landing page by title',
                    'read landing-page HTML source',
                    'extract .price and .disclaimer',
                    'read integration.php',
                    'collect research insights',
                    'generate minimal variant(s)',
                ],
            ],
            'instructions' => ['readme_path' => $instructions['readme_path'], 'agents_path' => $instructions['agents_path']],
            'landing_page' => $landing_page,
            'protected_content' => $protected,
            'integration' => $integration,
            'integration_guardrails' => $guardrails,
            'research' => $research,
            'variants' => [[
                'id' => 'minimal-cta-emphasis',
                'name' => 'Minimal CTA Emphasis',
                'type' => 'minimal',
                'description' => 'Adds subtle CTA animation and hierarchy polish without changing offer/compliance text.',
                'html' => $variant_html,
                'integration_guardrails' => $guardrails,
                'applied_insights' => $research['insights'] ?? [],
            ]],
        ];
    }

    public function read_repository_instructions(): array
    {
        if ($this->instructions_loaded) {
            return $this->instructions_cache;
        }
        $readme_path = $this->project_root . DIRECTORY_SEPARATOR . 'README.md';
        $agents_path = $this->project_root . DIRECTORY_SEPARATOR . 'agents.md';
        $this->read_required_file($readme_path, 'README.md');
        $this->read_required_file($agents_path, 'agents.md');
        $this->instructions_loaded = true;
        $this->instructions_cache = ['readme_path' => $readme_path, 'agents_path' => $agents_path];

        return $this->instructions_cache;
    }

    private function resolve_landing_page_by_title(string $landing_page_title): array
    {
        $this->assert_instructions_loaded();
        $requested = $this->normalize_match_value($landing_page_title);

        if ($requested === '') {
            throw new InvalidArgumentException('Landing-page title is required.');
        }

        $registry = new Kiwi_Landing_Page_Registry($this->landing_pages_root, $this->project_root);
        $entries = $registry->get_registry();
        if (empty($entries)) {
            throw new RuntimeException('Unable to resolve a landing page by title. ' . implode(' | ', array_map('strval', $registry->get_errors())));
        }

        ksort($entries, SORT_STRING);
        $by_integration_title = [];
        $by_html_title = [];
        $by_key = [];

        foreach ($entries as $fallback_key => $landing_page) {
            if (!is_array($landing_page)) {
                continue;
            }

            $ref = $this->build_landing_page_reference((string) $fallback_key, $landing_page);
            $integration_title = $this->normalize_match_value((string) ($landing_page['title'] ?? ''));

            if ($integration_title !== '' && $integration_title === $requested) {
                $by_integration_title[] = $ref;
                continue;
            }

            $html_title = $this->normalize_match_value($this->extract_html_title_from_path((string) ($ref['index_path'] ?? '')));
            if ($html_title !== '' && $html_title === $requested) {
                $by_html_title[] = $ref;
                continue;
            }

            if ($this->normalize_match_value((string) ($ref['key'] ?? '')) === $requested) {
                $by_key[] = $ref;
            }
        }

        foreach ([
            ['matches' => $by_integration_title, 'label' => 'integration.php title'],
            ['matches' => $by_html_title, 'label' => 'HTML <title>'],
            ['matches' => $by_key, 'label' => 'landing key'],
        ] as $candidate_set) {
            $resolved = $this->resolve_single_match($candidate_set['matches'], $candidate_set['label']);
            if (is_array($resolved)) {
                return $resolved;
            }
        }

        throw new RuntimeException('Landing page not found for title "' . trim($landing_page_title) . '".');
    }

    private function resolve_single_match(array $matches, string $label): ?array
    {
        if (empty($matches)) {
            return null;
        }
        if (count($matches) > 1) {
            $keys = [];
            foreach ($matches as $match) {
                $key = trim((string) ($match['key'] ?? ''));
                if ($key !== '') {
                    $keys[] = $key;
                }
            }
            throw new RuntimeException('Landing-page title is ambiguous for ' . $label . ' (matched keys: ' . implode(', ', $keys) . ').');
        }

        return $matches[0];
    }

    private function build_landing_page_reference(string $fallback_key, array $landing_page): array
    {
        $key = trim((string) ($landing_page['key'] ?? $fallback_key));
        $folder = trim((string) ($landing_page['folder_path'] ?? ''));
        $integration_path = $folder !== ''
            ? $folder . DIRECTORY_SEPARATOR . 'integration.php'
            : $this->landing_pages_root . DIRECTORY_SEPARATOR . $key . DIRECTORY_SEPARATOR . 'integration.php';

        return [
            'key' => $key,
            'title' => trim((string) ($landing_page['title'] ?? '')),
            'folder_path' => $folder,
            'index_path' => trim((string) ($landing_page['index_path'] ?? '')),
            'styles_path' => trim((string) ($landing_page['styles_path'] ?? '')),
            'integration_path' => $integration_path,
        ];
    }

    private function read_integration_metadata(string $integration_path): array
    {
        $this->assert_instructions_loaded();
        if ($integration_path === '' || !is_file($integration_path) || !is_readable($integration_path)) {
            throw new RuntimeException('integration.php is missing or unreadable at "' . $integration_path . '".');
        }
        $integration = include $integration_path;
        if (!is_array($integration)) {
            throw new RuntimeException('integration.php must return an array at "' . $integration_path . '".');
        }

        return $integration;
    }

    private function extract_protected_content(string $html): array
    {
        $price = $this->extract_first_element_by_class($html, 'price');
        $disclaimer = $this->extract_first_element_by_class($html, 'disclaimer');

        return [
            'price_html' => $price['inner_html'],
            'price_text' => $price['text'],
            'disclaimer_html' => $disclaimer['inner_html'],
            'disclaimer_text' => $disclaimer['text'],
        ];
    }

    private function extract_first_element_by_class(string $html, string $class_name): array
    {
        $html = trim($html);
        if ($html === '') {
            throw new RuntimeException('Cannot extract .' . $class_name . ' from empty HTML.');
        }

        if (class_exists('DOMDocument') && class_exists('DOMXPath')) {
            $dom = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if ($loaded) {
                $query = sprintf('//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]', preg_replace('/[^a-zA-Z0-9_-]/', '', $class_name));
                $nodes = (new DOMXPath($dom))->query($query);
                if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
                    $node = $nodes->item(0);
                    if ($node instanceof DOMNode && $node->ownerDocument instanceof DOMDocument) {
                        $inner_html = '';
                        foreach ($node->childNodes as $child) {
                            $inner_html .= $node->ownerDocument->saveHTML($child);
                        }

                        return ['inner_html' => $inner_html, 'text' => trim((string) $node->textContent)];
                    }
                }
            }
        }

        $pattern = '/<([a-z0-9]+)\b[^>]*class=(["\'])[^"\']*\b' . preg_quote($class_name, '/') . '\b[^"\']*\2[^>]*>(.*?)<\/\1>/is';
        if (preg_match($pattern, $html, $matches)) {
            $inner_html = (string) ($matches[3] ?? '');
            $text = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $inner_html));

            return ['inner_html' => $inner_html, 'text' => trim($text)];
        }

        throw new RuntimeException('Landing-page HTML is missing required .' . $class_name . ' element.');
    }

    private function build_integration_guardrails(array $integration): array
    {
        $guardrails = [];
        foreach (['key', 'title', 'country', 'flow', 'provider', 'service_key', 'service_type', 'business_number', 'keyword', 'shortcode', 'price_label', 'documentation'] as $field) {
            if (!array_key_exists($field, $integration)) {
                continue;
            }
            $value = is_scalar($integration[$field]) ? trim((string) $integration[$field]) : '';
            if ($value !== '') {
                $guardrails[$field] = $value;
            }
        }

        return $guardrails;
    }

    private function collect_research_insights(array $landing_page, array $integration, array $options): array
    {
        $insights = $this->fallback_research_insights();
        $mode = 'repository_fallback';
        $fetcher = $options['research_fetcher'] ?? $this->research_fetcher;

        if (!empty($options['allow_web_research']) && is_callable($fetcher)) {
            try {
                $external = call_user_func($fetcher, [
                    'landing_key' => (string) ($landing_page['key'] ?? ''),
                    'title' => (string) ($landing_page['title'] ?? ''),
                    'country' => (string) ($integration['country'] ?? ''),
                    'flow' => (string) ($integration['flow'] ?? ''),
                    'provider' => (string) ($integration['provider'] ?? ''),
                ]);
                if (is_array($external) && !empty($external)) {
                    $normalized = [];
                    foreach ($external as $index => $insight) {
                        $summary = is_string($insight) ? trim($insight) : trim((string) ($insight['summary'] ?? ''));
                        if ($summary === '') {
                            continue;
                        }
                        $normalized[] = ['id' => 'external-' . ($index + 1), 'summary' => $summary, 'source' => 'external'];
                    }
                    if (!empty($normalized)) {
                        $insights = $normalized;
                        $mode = 'external_research';
                    }
                }
            } catch (Throwable $throwable) {
                $mode = 'repository_fallback';
            }
        }

        return ['mode' => $mode, 'insights' => $insights];
    }

    private function fallback_research_insights(): array
    {
        return [
            ['id' => 'cta-clarity', 'summary' => 'Keep one dominant CTA above the fold with high contrast.', 'source' => 'repository_fallback'],
            ['id' => 'readability', 'summary' => 'Use tighter visual hierarchy and spacing to improve scanning.', 'source' => 'repository_fallback'],
            ['id' => 'ethical-persuasion', 'summary' => 'Avoid dark patterns and preserve informed user choice.', 'source' => 'repository_fallback'],
        ];
    }

    private function minimal_variant_css(): string
    {
        return <<<CSS
.lp-container { max-width: 32rem; margin: 0 auto; }
.lp-container .cta { box-shadow: 0 10px 22px rgba(0, 0, 0, 0.22); transition: transform 160ms ease, box-shadow 160ms ease; animation: kiwiPulse 2.3s ease-in-out infinite; }
.lp-container .cta:hover, .lp-container .cta:focus-visible { transform: translateY(-1px); box-shadow: 0 14px 28px rgba(0, 0, 0, 0.28); }
.lp-container .price { margin-top: 1rem; }
.lp-container .disclaimer { margin-top: 1.1rem; line-height: 1.45; }
@keyframes kiwiPulse { 0%, 100% { filter: saturate(100%); } 50% { filter: saturate(114%); } }
CSS;
    }

    private function inject_style_block(string $html, string $css, string $variant_id): string
    {
        $style_block = '<style data-kiwi-variant="' . htmlspecialchars($variant_id, ENT_QUOTES, 'UTF-8') . '">' . "\n" . trim($css) . "\n</style>";
        $updated = stripos($html, '</head>') !== false
            ? preg_replace('/<\/head>/i', $style_block . "\n</head>", $html, 1)
            : $style_block . "\n" . $html;

        return is_string($updated) && $updated !== '' ? $updated : $html;
    }

    private function assert_protected_content_preserved(string $variant_html, array $source_protected): void
    {
        $variant_protected = $this->extract_protected_content($variant_html);
        if (($variant_protected['price_text'] ?? '') !== ($source_protected['price_text'] ?? '')) {
            throw new RuntimeException('Variant generation changed protected .price content.');
        }
        if (($variant_protected['disclaimer_text'] ?? '') !== ($source_protected['disclaimer_text'] ?? '')) {
            throw new RuntimeException('Variant generation changed protected .disclaimer content.');
        }
    }

    private function extract_html_title_from_path(string $index_path): string
    {
        if ($index_path === '' || !is_file($index_path) || !is_readable($index_path)) {
            return '';
        }
        $html = file_get_contents($index_path);
        if (!is_string($html) || !preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            return '';
        }

        return trim(html_entity_decode(strip_tags((string) ($matches[1] ?? '')), ENT_QUOTES, 'UTF-8'));
    }

    private function normalize_match_value(string $value): string
    {
        return strtolower(trim($value));
    }

    private function assert_instructions_loaded(): void
    {
        if (!$this->instructions_loaded) {
            throw new RuntimeException('README.md and agents.md must be read before variant generation begins.');
        }
    }

    private function read_required_file(string $path, string $label): string
    {
        $path = trim($path);
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException($label . ' is missing at "' . $path . '".');
        }
        if (!is_readable($path)) {
            throw new RuntimeException($label . ' is not readable at "' . $path . '".');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException($label . ' could not be read at "' . $path . '".');
        }

        return $contents;
    }
}
