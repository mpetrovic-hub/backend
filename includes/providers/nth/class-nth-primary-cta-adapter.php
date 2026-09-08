<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Nth_Primary_Cta_Adapter implements Kiwi_Landing_Primary_Cta_Adapter_Interface
{
    private const SMS_BODY_VARIANT_ALLOCATION = [
        'version' => 'fr_sms_v2',
        'context' => [
            'country' => 'FR',
            'provider' => 'nth',
            'flow' => 'nth-fr-one-off',
            'service_key' => 'nth_fr_one_off_jplay',
        ],
        'entries' => [
            ['variant_key' => 'as_is_txn_prefix', 'seed' => '', 'weight' => 10],
            ['variant_key' => 'cta_phrase', 'seed' => 'BonusJeux', 'weight' => 20],
            ['variant_key' => 'game_word', 'seed' => 'TopJeux', 'weight' => 20],
            ['variant_key' => 'cta_phrase', 'seed' => 'JouerPlus', 'weight' => 20],
            ['variant_key' => 'cta_phrase', 'seed' => 'AccederJeux', 'weight' => 8],
            ['variant_key' => 'game_word', 'seed' => 'JeuxMax', 'weight' => 8],
            ['variant_key' => 'download_phrase', 'seed' => 'AccederMaintenant', 'weight' => 8],
            ['variant_key' => 'game_word', 'seed' => 'GameQuest', 'weight' => 6],
        ],
    ];

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

        if ($transaction_id !== '' && $this->sms_body_variant_service instanceof Kiwi_Sms_Body_Variant_Service) {
            $variant = $this->sms_body_variant_service->build_variant_body(
                $keyword,
                $shortcode,
                $landing_page,
                $service,
                $attribution,
                $this->get_sms_body_variant_allocation()
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

    public function get_sms_body_variant_allocation(): array
    {
        return self::SMS_BODY_VARIANT_ALLOCATION;
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
