<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core
 */
require_once __DIR__ . '/core/class-config.php';

/**
 * Lily
 */
require_once __DIR__ . '/providers/lily/class-lily-client.php';
require_once __DIR__ . '/providers/lily/class-lily-response-parser.php';
require_once __DIR__ . '/providers/lily/class-lily-hlr-provider.php';

/**
 * DIMOCO
 */
require_once __DIR__ . '/providers/dimoco/class-dimoco-digest.php';
require_once __DIR__ . '/providers/dimoco/class-dimoco-client.php';
require_once __DIR__ . '/providers/dimoco/class-dimoco-response-parser.php';
require_once __DIR__ . '/providers/dimoco/class-dimoco-callback-verifier.php';
require_once __DIR__ . '/services/class-dimoco-refund-batch-service.php';

/**
 * Services
 */
require_once __DIR__ . '/services/class-hlr-service.php';
require_once __DIR__ . '/services/class-msisdn-normalizer.php';
require_once __DIR__ . '/services/class-batch-service.php';

/**
 * Exporters
 */
require_once __DIR__ . '/exporters/class-csv-exporter.php';

/**
 * Shortcodes
 */
require_once __DIR__ . '/shortcodes/class-hlr-lookup-shortcode.php';

/**
 * HTTP / REST
 */
require_once __DIR__ . '/http/class-rest-routes.php';

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'kiwi-backend-components',
        plugin_dir_url(dirname(__FILE__)) . 'assets/css/components.css',
        [],
        '0.1'
    );

    wp_enqueue_style(
        'kiwi-backend-forms',
        plugin_dir_url(dirname(__FILE__)) . 'assets/css/forms.css',
        ['kiwi-backend-components'],
        '0.1'
    );

    wp_enqueue_style(
        'kiwi-backend-tables',
        plugin_dir_url(dirname(__FILE__)) . 'assets/css/tables.css',
        ['kiwi-backend-components'],
        '0.1'
    );

    wp_enqueue_style(
        'kiwi-backend-frontend',
        plugin_dir_url(dirname(__FILE__)) . 'assets/css/frontend.css',
        [
            'kiwi-backend-components',
            'kiwi-backend-forms',
            'kiwi-backend-tables',
        ],
        '0.1'
    );

    wp_enqueue_script(
        'kiwi-backend-core',
        plugin_dir_url(dirname(__FILE__)) . 'assets/js/core.js',
        [],
        '0.1',
        true
    );

    wp_enqueue_script(
        'kiwi-backend-frontend',
        plugin_dir_url(dirname(__FILE__)) . 'assets/js/frontend.js',
        ['kiwi-backend-core'],
        '0.1',
        true
    );
});

add_action('init', function () {
    $config      = new Kiwi_Config();
    $client      = new Kiwi_Lily_Client($config);
    $parser      = new Kiwi_Lily_Response_Parser();
    $provider    = new Kiwi_Lily_Hlr_Provider($client, $parser);
    $normalizer  = new Kiwi_Msisdn_Normalizer();
    $hlr_service = new Kiwi_Hlr_Service($provider, $normalizer);
    $batch       = new Kiwi_Batch_Service($hlr_service, $config, $normalizer);

    $shortcode = new Kiwi_Hlr_Lookup_Shortcode($batch);
    $shortcode->register();
});

add_action('init', function () {
    $config   = new Kiwi_Config();
    $verifier = new Kiwi_Dimoco_Callback_Verifier();
    $parser   = new Kiwi_Dimoco_Response_Parser();

    $rest_routes = new Kiwi_Rest_Routes($config, $verifier, $parser);
    $rest_routes->register();
});

/* Temporary test route Dimoco Refund */

add_action('init', function () {

    if (!isset($_GET['kiwi_dimoco_test'])) {
        return;
    }

    header('Content-Type: text/plain; charset=utf-8');

    $config  = new Kiwi_Config();
    $digest  = new Kiwi_Dimoco_Digest();
    $client  = new Kiwi_Dimoco_Client($config, $digest);

    $result = $client->refund('at_service_getstronger', '1234567890');

    print_r($result);
    exit;
}); 

add_action('init', function () {
    if (!isset($_GET['kiwi_dimoco_refund_batch_test'])) {
        return;
    }

    header('Content-Type: text/plain; charset=utf-8');

    $config  = new Kiwi_Config();
    $digest  = new Kiwi_Dimoco_Digest();
    $client  = new Kiwi_Dimoco_Client($config, $digest);
    $parser  = new Kiwi_Dimoco_Response_Parser();
    $batch   = new Kiwi_Dimoco_Refund_Batch_Service($client, $parser, $config);

    $input = <<<TEXT
RD-p-123456-8338-45c6-af9e-fe43d2ea5201
RD-p-123456-8338-45c6-af9e-fe43d2ea5201
RD-p-abcdef12-3456-7890-abcd-1234567890ab
TEXT;

    $result = $batch->process('at_service_getstronger', '436641234567', $input);

    print_r($result);
    exit;
});