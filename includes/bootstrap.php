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
require_once __DIR__ . '/providers/lily/class-lily-operator-lookup-provider.php';

/**
 * DIMOCO
 */
require_once __DIR__ . '/providers/dimoco/class-dimoco-digest.php';
require_once __DIR__ . '/providers/dimoco/class-dimoco-client.php';
require_once __DIR__ . '/providers/dimoco/class-dimoco-response-parser.php';
require_once __DIR__ . '/providers/dimoco/class-dimoco-callback-verifier.php';
require_once __DIR__ . '/services/class-dimoco-refund-batch-service.php';
require_once __DIR__ . '/repositories/class-dimoco-callback-refund-repository.php';

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
require_once __DIR__ . '/shortcodes/class-dimoco-refunder-shortcode.php';

/**
 * HTTP / REST
 */
require_once __DIR__ . '/http/class-rest-routes.php';

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'kiwi-backend-components',
        plugin_dir_url(dirname(__FILE__)) . 'assets/css/components.css',
        [],
        filemtime(dirname(__DIR__) . '/assets/css/components.css')
    );

    wp_enqueue_style(
        'kiwi-backend-forms',
        plugin_dir_url(dirname(__FILE__)) . 'assets/css/forms.css',
        ['kiwi-backend-components'],
        filemtime(dirname(__DIR__) . '/assets/css/forms.css')
    );

    wp_enqueue_style(
        'kiwi-backend-tables',
        plugin_dir_url(dirname(__FILE__)) . 'assets/css/tables.css',
        ['kiwi-backend-components'],
        filemtime(dirname(__DIR__) . '/assets/css/tables.css')
    );

    wp_enqueue_style(
        'kiwi-backend-frontend',
        plugin_dir_url(dirname(__FILE__)) . 'assets/css/frontend.css',
        [
            'kiwi-backend-components',
            'kiwi-backend-forms',
            'kiwi-backend-tables',
        ],
        filemtime(dirname(__DIR__) . '/assets/css/frontend.css')
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
    $config = new Kiwi_Config();

    // HLR / Lily
    $lily_client                    = new Kiwi_Lily_Client($config);
    $lily_parser                    = new Kiwi_Lily_Response_Parser();
    $lily_operator_lookup_provider  = new Kiwi_Lily_Operator_Lookup_Provider($lily_client, $lily_parser);
    $msisdn_normalizer              = new Kiwi_Msisdn_Normalizer();
    $operator_lookup_service        = new Kiwi_Operator_Lookup_Service($lily_operator_lookup_provider, $msisdn_normalizer);
    $hlr_batch_service              = new Kiwi_Batch_Service($operator_lookup_service, $config, $msisdn_normalizer);
    $hlr_shortcode                  = new Kiwi_Hlr_Lookup_Shortcode($hlr_batch_service);
    $hlr_shortcode->register();

    // DIMOCO / Refunder
    $dimoco_digest               = new Kiwi_Dimoco_Digest();
    $dimoco_client               = new Kiwi_Dimoco_Client($config, $dimoco_digest);
    $dimoco_response_parser      = new Kiwi_Dimoco_Response_Parser();
    $dimoco_refund_batch_service = new Kiwi_Dimoco_Refund_Batch_Service(
        $dimoco_client,
        $dimoco_response_parser,
        $config
    );
    $dimoco_callback_refund_repository = new Kiwi_Dimoco_Callback_Refund_Repository();

    $dimoco_refund_shortcode = new Kiwi_Dimoco_Refunder_Shortcode(
        $dimoco_refund_batch_service,
        $config,
        $dimoco_callback_refund_repository
    );
    $dimoco_refund_shortcode->register();
});

add_action('init', function () {
    $config                    = new Kiwi_Config();
    $dimoco_callback_verifier  = new Kiwi_Dimoco_Callback_Verifier();
    $dimoco_response_parser    = new Kiwi_Dimoco_Response_Parser();
    $dimoco_callback_refund_repository = new Kiwi_Dimoco_Callback_Refund_Repository();

    $rest_routes = new Kiwi_Rest_Routes(
        $config,
        $dimoco_callback_verifier,
        $dimoco_response_parser,
        $dimoco_callback_refund_repository
    );
    $rest_routes->register();
});

// DIMOCO Refund Callback Repository - create table on init if not exists
add_action('init', function () {
    $repository = new Kiwi_Dimoco_Callback_Refund_Repository();
    $repository->create_table();
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
RD-p-123456-8338-45c6-af9e-fe43d2ea5201bla
RD-p-123456-8338-45c6-af9e-fe43d2ea5201blub
RD-p-abcdef12-3456-7890-abcd-1234567890abblurp
TEXT;

    $result = $batch->process('at_service_getstronger', '436641234567', $input);

    print_r($result);
    exit;
});