<?php
/*
Plugin Name: Kiwi Backend
Description: Internal backend tools for Kiwi mVAS services (HLR, SMS, etc.)
Version: 0.1
Author: Kiwi
*/

/* This file is the main entry point for the Kiwi Backend plugin. It loads all necessary classes and sets up hooks for frontend assets, 
shortcodes, and export functionality. */

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Load core classes
 */

/*

require_once plugin_dir_path(__FILE__) . 'includes/http/class-rest-routes.php';

require_once plugin_dir_path(__FILE__) . 'includes/core/class-config.php';
require_once plugin_dir_path(__FILE__) . 'includes/providers/lily/class-lily-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/providers/lily/class-lily-response-parser.php';
require_once plugin_dir_path(__FILE__) . 'includes/providers/lily/class-lily-hlr-provider.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/class-hlr-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/class-msisdn-normalizer.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/class-batch-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/exporters/class-csv-exporter.php';
require_once plugin_dir_path(__FILE__) . 'includes/shortcodes/class-hlr-lookup-shortcode.php';

require_once plugin_dir_path(__FILE__) . 'includes/providers/dimoco/class-dimoco-digest.php';
require_once plugin_dir_path(__FILE__) . 'includes/providers/dimoco/class-dimoco-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/providers/dimoco/class-dimoco-response-parser.php';
require_once plugin_dir_path(__FILE__) . 'includes/providers/dimoco/class-dimoco-callback-verifier.php';
require_once plugin_dir_path(__FILE__) . 'includes/providers/dimoco/class-dimoco-callback-handler.php';

/**
 * Enqueue frontend assets (CSS/JS)
 /

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'kiwi-backend-components',
        plugin_dir_url(__FILE__) . 'assets/css/components.css',
        [],
        '0.1'
    );

    wp_enqueue_style(
        'kiwi-backend-forms',
        plugin_dir_url(__FILE__) . 'assets/css/forms.css',
        ['kiwi-backend-components'],
        '0.1'
    );

    wp_enqueue_style(
        'kiwi-backend-tables',
        plugin_dir_url(__FILE__) . 'assets/css/tables.css',
        ['kiwi-backend-components'],
        '0.1'
    );

    wp_enqueue_style(
        'kiwi-backend-frontend',
        plugin_dir_url(__FILE__) . 'assets/css/frontend.css',
        [
            'kiwi-backend-components',
            'kiwi-backend-forms',
            'kiwi-backend-tables',
        ],
        '0.1'
    );

    wp_enqueue_script(
        'kiwi-backend-core',
        plugin_dir_url(__FILE__) . 'assets/js/core.js',
        [],
        '0.1',
        true
    );

    wp_enqueue_script(
        'kiwi-backend-frontend',
        plugin_dir_url(__FILE__) . 'assets/js/frontend.js',
        ['kiwi-backend-core'],
        '0.1',
        true
    );
});

/**
 * L I L Y  
 */

/* HLR - Register Shortcode "Kiwi_Hlr_Lookup_Shortcode" /

add_action('init', function () {
    $config      = new Kiwi_Config();
    $client      = new Kiwi_Lily_Client($config);
    $parser      = new Kiwi_Lily_Response_Parser();
    $provider    = new Kiwi_Lily_Hlr_Provider($client, $parser);
    $normalizer  = new Kiwi_Msisdn_Normalizer();
    $hlr_service = new Kiwi_Hlr_Service($provider, $normalizer);
    $batch       = new Kiwi_Batch_Service($hlr_service, $config, $normalizer);
    $exporter    = new Kiwi_Csv_Exporter();

    $shortcode = new Kiwi_Hlr_Lookup_Shortcode($batch);
    $shortcode->register();
});

/**
 * Export to .csv
 /

add_action('init', function () {

    if (
        !isset($_GET['kiwi_hlr_export']) ||
        !isset($_GET['batch_id'])
    ) {
        return;
    }

    $batch_id = sanitize_text_field(wp_unslash($_GET['batch_id']));
    $results  = get_transient($batch_id);

    if (!is_array($results) || empty($results)) {
        wp_die('Invalid or expired export data.');
    }

    $exporter = new Kiwi_Csv_Exporter();
    $exporter->export($results, 'kiwi-hlr-results.csv');
});

/**
 * Temporary test route Lily HLR
 /

add_action('init', function () {

    if (!isset($_GET['kiwi_hlr_test'])) {
        return;
    }

    // header('Content-Type: text/plain; charset=utf-8');
    // echo "hlr-test reached\n\n";

    $config      = new Kiwi_Config();
    $client      = new Kiwi_Lily_Client($config);
    $parser      = new Kiwi_Lily_Response_Parser();
    $provider    = new Kiwi_Lily_Hlr_Provider($client, $parser);
    $normalizer  = new Kiwi_Msisdn_Normalizer();
    $hlr_service = new Kiwi_Hlr_Service($provider, $normalizer);
    $batch       = new Kiwi_Batch_Service($hlr_service, $config, $normalizer);
    $exporter    = new Kiwi_Csv_Exporter();   

    $input = <<<TEXT
+30 695 582 5585

306955825585

TEXT;

    $batch_result = $batch->process($input); 
    echo "Batch processing completed. Results exported to kiwi-hlr-test.csv\n";    
    
});

/**
 * D I M O C O
 */

/* Temporary test route Dimoco Refund /

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

*/

require_once plugin_dir_path(__FILE__) . 'includes/bootstrap.php';