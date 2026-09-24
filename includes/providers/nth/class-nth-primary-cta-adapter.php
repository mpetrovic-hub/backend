<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Nth_Primary_Cta_Adapter implements Kiwi_Landing_Primary_Cta_Adapter_Interface
{
    private $sms_body_variant_service;

    public function __construct(?Kiwi_Sms_Body_Variant_Service $sms_body_variant_service = null)
    {
        $this->sms_body_variant_service = $sms_body_variant_service;
    }

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

        if ($transaction_id !== '' && $this->sms_body_variant_service instanceof Kiwi_Sms_Body_Variant_Service
            && $this->uses_fr_one_off_allocation($landing_page, $service)) {
            $variant = $this->sms_body_variant_service->build_variant_body(
                $keyword,
                $shortcode,
                $landing_page,
                $service,
                $attribution,
                require __DIR__ . '/config/fr-one-off-sms-body-variants.php'
            );

            if (is_array($variant) && trim((string) ($variant['body'] ?? '')) !== '') {
                $body = trim((string) ($variant['body'] ?? ''));
            } else {
                $body .= ' ' . $transaction_id;
            }
        } elseif ($transaction_id !== '') {
            $body .= ' ' . $transaction_id;
        }

        return 'sms:' . $shortcode . '?body=' . rawurlencode($body);
    }

    private function uses_fr_one_off_allocation(array $landing_page, array $service): bool
    {
        if (!$this->supports($landing_page, $service)) {
            return false;
        }

        $country = strtoupper(trim((string) ($landing_page['country'] ?? ($service['country'] ?? ''))));
        $flow = strtolower(trim((string) ($landing_page['flow'] ?? ($service['flow'] ?? ''))));

        // A configured service must not contradict the landing's integration boundary.
        foreach (['provider' => ['nth'], 'country' => ['fr'], 'flow' => ['one-off', 'nth-fr-one-off']] as $field => $allowed) {
            if (isset($service[$field]) && !in_array(strtolower(trim((string) $service[$field])), $allowed, true)) {
                return false;
            }
        }

        return $country === 'FR' && in_array($flow, ['nth-fr-one-off', 'one-off'], true);
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
