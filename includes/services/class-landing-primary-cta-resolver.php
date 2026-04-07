<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Primary_Cta_Resolver
{
    /** @var Kiwi_Landing_Primary_Cta_Adapter_Interface[] */
    private $adapters = [];

    public function __construct(array $adapters = [])
    {
        foreach ($adapters as $adapter) {
            if (!$adapter instanceof Kiwi_Landing_Primary_Cta_Adapter_Interface) {
                continue;
            }

            $this->adapters[] = $adapter;
        }
    }

    public function resolve(array $landing_page, array $service = [], ?array $attribution = null): string
    {
        foreach ($this->adapters as $adapter) {
            if (!$adapter->supports($landing_page, $service)) {
                continue;
            }

            $href = trim((string) $adapter->build_primary_cta_href(
                $landing_page,
                $service,
                $attribution
            ));

            if ($href !== '') {
                return $href;
            }
        }

        $configured_href = trim((string) ($landing_page['cta_href'] ?? ''));

        if ($configured_href !== '') {
            return $configured_href;
        }

        $shortcode = trim((string) ($landing_page['shortcode'] ?? ($service['shortcode'] ?? '')));
        $keyword = trim((string) ($landing_page['keyword'] ?? ($service['keyword'] ?? '')));

        if ($shortcode === '' || $keyword === '') {
            return '#';
        }

        return 'sms:' . $shortcode . '?body=' . rawurlencode($keyword);
    }
}
