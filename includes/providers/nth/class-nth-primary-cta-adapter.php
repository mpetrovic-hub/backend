<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Nth_Primary_Cta_Adapter implements Kiwi_Landing_Primary_Cta_Adapter_Interface
{
    public function supports(array $landing_page, array $service): bool
    {
        $provider = strtolower(trim((string) ($landing_page['provider'] ?? '')));

        if ($provider === '' && isset($service['provider'])) {
            $provider = strtolower(trim((string) $service['provider']));
        }

        return $provider === 'nth';
    }

    public function build_primary_cta_href(
        array $landing_page,
        array $service,
        ?array $attribution
    ): ?string {
        $shortcode = trim((string) ($landing_page['shortcode'] ?? ($service['shortcode'] ?? '')));
        $keyword = $this->normalize_keyword_seed((string) ($landing_page['keyword'] ?? ($service['keyword'] ?? '')));

        if ($shortcode === '' || $keyword === '') {
            return null;
        }

        $transaction_id = trim((string) (($attribution['transaction_id'] ?? '')));
        $body = $keyword;

        if ($transaction_id !== '') {
            $body .= ' ' . $transaction_id;
        }

        return 'sms:' . $shortcode . '?body=' . rawurlencode($body);
    }

    private function normalize_keyword_seed(string $keyword): string
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $keyword);
        $keyword = is_array($parts) && !empty($parts)
            ? (string) $parts[0]
            : $keyword;

        return trim(rtrim($keyword, '*'));
    }
}
