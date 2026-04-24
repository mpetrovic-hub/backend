<?php

return [
    'key' => 'lp3-fr',
    'country' => 'FR',
    'flow' => 'nth-fr-one-off',
    'provider' => 'nth',
    'documentation' => '/integrations/nth/fr/one-off/README.md',

    'locale' => 'fr',
    'service_type' => 'premium_sms',
    'business_number' => '84072',
    'keyword' => 'Jplay*',
    'title' => 'LP3 France One-off',
    'active' => true,

    'backend_path' => '/lp/fr/myjoyplay3', // defines the path on which the backend will be available, e.g. /lp/fr/myjoyplay3
    'dedicated_path' => '/',
    'hostnames' => ['your.joy-play.com'], // defines the hostnames on which the landing page will be available, e.g. ['frlp3.joy-play.com', 'your.joy-play.com']
    'service_key' => 'nth_fr_one_off_jplay',
    'shortcode' => '84072',
    'price_label' => '4,50 EUR / SMS + prix d\'un SMS',
    'kpi_cta_steps' => [
        'cta1' => 'class="cta"',
    ],
];
