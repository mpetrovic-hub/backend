<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core
 */
require_once __DIR__ . '/landing-pages/class-landing-page-registry.php';
require_once __DIR__ . '/core/class-config.php';
require_once __DIR__ . '/core/class-frontend-auth-gate.php';
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
require_once __DIR__ . '/services/class-affiliate-postback-dispatcher.php';
require_once __DIR__ . '/services/class-conversion-attribution-resolver.php';
require_once __DIR__ . '/services/class-tracking-capture-service.php';
require_once __DIR__ . '/services/class-landing-primary-cta-adapter-interface.php';
require_once __DIR__ . '/services/class-landing-primary-cta-resolver.php';
require_once __DIR__ . '/services/class-landing-kpi-service.php';
require_once __DIR__ . '/services/class-landing-page-gallery-service.php';
require_once __DIR__ . '/services/class-landing-page-variant-agent.php';
require_once __DIR__ . '/services/class-premium-sms-mo-engagement-evaluator-service.php';
require_once __DIR__ . '/services/class-premium-sms-fraud-monitor-service.php';
require_once __DIR__ . '/providers/nth/class-nth-primary-cta-adapter.php';
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
require_once __DIR__ . '/repositories/class-click-attribution-repository.php';
require_once __DIR__ . '/repositories/class-sales-repository.php';
require_once __DIR__ . '/repositories/class-landing-kpi-summary-repository.php';
require_once __DIR__ . '/repositories/class-landing-handoff-event-repository.php';
require_once __DIR__ . '/repositories/class-premium-sms-landing-engagement-repository.php';
require_once __DIR__ . '/repositories/class-premium-sms-fraud-signal-repository.php';

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
require_once __DIR__ . '/shortcodes/class-landing-pages-gallery-shortcode.php';
require_once __DIR__ . '/shortcodes/class-premium-sms-fraud-shortcode.php';

/**
 * HTTP / REST
 */
require_once __DIR__ . '/http/class-dimoco-rest-routes.php';
require_once __DIR__ . '/http/class-nth-rest-routes.php';
require_once __DIR__ . '/http/class-landing-kpi-rest-routes.php';
require_once __DIR__ . '/http/class-landing-page-router.php';

$plugin = new Kiwi_Plugin(
    dirname(__DIR__),
    plugin_dir_url(dirname(__FILE__))
);
$plugin->register();
