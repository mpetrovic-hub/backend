<?php

return [
    'key' => 'lp4-fr',
    'country' => 'FR',
    'flow' => 'nth-fr-one-off',
    'provider' => 'nth',
    'documentation' => '/integrations/nth/fr/one-off/README.md',

    'locale' => 'fr',
    'service_type' => 'premium_sms',
    'business_number' => '84072',
    'keyword' => 'Jplay*',
    'title' => 'LP4 France One-off',
    'active' => true,

    'backend_path' => '/lp/fr/myjoyplay4',
    'dedicated_path' => '/',
    'hostnames' => ['frlp4.joy-play.com'],
    'service_key' => 'nth_fr_one_off_jplay',
    'shortcode' => '84072',
    'price_label' => '4,50 EUR / SMS + prix d\'un SMS',
    'kpi_cta_steps' => [
        'cta1' => 'class="cta"',
    ],
];
