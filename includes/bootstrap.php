<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core
 */
require_once __DIR__ . '/core/class-config.php';
require_once __DIR__ . '/core/class-plugin.php';

/**
 * Lily Provider
 */
require_once __DIR__ . '/providers/lily/class-lily-client.php';
require_once __DIR__ . '/providers/lily/class-lily-response-parser.php';
require_once __DIR__ . '/providers/lily/class-lily-operator-lookup-provider.php';

/**
 * Dimoco Provider
 */
require_once __DIR__ . '/providers/dimoco/class-dimoco-digest.php';
require_once __DIR__ . '/providers/dimoco/class-dimoco-client.php';
require_once __DIR__ . '/providers/dimoco/class-dimoco-response-parser.php';
require_once __DIR__ . '/providers/dimoco/class-dimoco-callback-verifier.php';
require_once __DIR__ . '/providers/dimoco/class-dimoco-operator-lookup-provider.php';

/**
 * Generic Provider
 */
require_once __DIR__ . '/providers/class-routed-operator-lookup-provider.php';

/**
 * NTH Provider
 */
require_once __DIR__ . '/providers/nth/class-nth-client.php';
require_once __DIR__ . '/providers/nth/class-nth-premium-sms-normalizer.php';

/**
 * Services
 */
/*require_once __DIR__ . '/services/class-dimoco-refund-batch-service.php';
require_once __DIR__ . '/services/class-dimoco-blacklist-batch-service.php';
require_once __DIR__ . '/services/class-msisdn-normalizer.php';
require_once __DIR__ . '/services/class-operator-lookup-batch-service.php';
require_once __DIR__ . '/services/class-operator-lookup-service.php'; */

require_once __DIR__ . '/services/class-msisdn-normalizer.php';
require_once __DIR__ . '/services/class-operator-lookup-service.php';
require_once __DIR__ . '/services/class-operator-lookup-batch-service.php';
require_once __DIR__ . '/services/class-dimoco-refund-batch-service.php';
require_once __DIR__ . '/services/class-dimoco-blacklist-batch-service.php';
require_once __DIR__ . '/services/class-shared-sales-recorder.php';
require_once __DIR__ . '/services/class-nth-fr-one-off-service.php';

/**
 * Repositories
 */
require_once __DIR__ . '/repositories/class-dimoco-callback-refund-repository.php';
require_once __DIR__ . '/repositories/class-dimoco-callback-blacklist-repository.php';
require_once __DIR__ . '/repositories/class-dimoco-callback-operator-lookup-repository.php';
require_once __DIR__ . '/repositories/class-landing-page-session-repository.php';
require_once __DIR__ . '/repositories/class-nth-event-repository.php';
require_once __DIR__ . '/repositories/class-nth-flow-transaction-repository.php';
require_once __DIR__ . '/repositories/class-sales-repository.php';

/**
 * Exporters
 */
require_once __DIR__ . '/exporters/class-csv-exporter.php';

/**
 * Shortcodes
 */
require_once __DIR__ . '/shortcodes/class-hlr-lookup-shortcode.php';
require_once __DIR__ . '/shortcodes/class-dimoco-refunder-shortcode.php';
require_once __DIR__ . '/shortcodes/class-dimoco-blacklister-shortcode.php';

/**
 * HTTP / REST
 */
require_once __DIR__ . '/http/class-rest-routes.php';
require_once __DIR__ . '/http/class-nth-rest-routes.php';
require_once __DIR__ . '/http/class-landing-page-router.php';

$plugin = new Kiwi_Plugin(
    dirname(__DIR__),
    plugin_dir_url(dirname(__FILE__))
);
$plugin->register();
