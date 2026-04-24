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

    'backend_path' => '/lp/fr/myjoyplay4', // defines the path on which the backend will be available, e.g. /lp/fr/myjoyplay3
    'dedicated_path' => '/',
    'hostnames' => ['your.joy-play.com'], // defines the hostnames on which the landing page will be available, e.g. ['frlp4.joy-play.com', 'your.joy-play.com']
    'asset_base_url' => 'https://backend.kiwimobile.de/wp-content/uploads/assets/', // defines the base URL for assets, e.g. 'https://backend.kiwimobile.de/wp-content/uploads/assets/'
    'service_key' => 'nth_fr_one_off_jplay',
    'shortcode' => '84072',
    'price_label' => '4,50 EUR / SMS + prix d\'un SMS',
    'kpi_cta_steps' => [
        'cta1' => 'class="cta"',
    ],
];
