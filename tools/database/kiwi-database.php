<?php

if (!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) {
    if (defined('STDERR')) {
        fwrite(STDERR, "This runner must be executed through WP-CLI.\n");
    }

    exit(1);
}

require_once __DIR__ . '/class-database-deployment-service.php';

if (!function_exists('did_action')
    || did_action('plugins_loaded') < 1
    || did_action('init') > 0
) {
    WP_CLI::error(
        'Run this file with --hook=plugins_loaded so plugin classes are loaded before WordPress init side effects.',
        false
    );
    WP_CLI::halt(1);
}

$required_classes = [
    Kiwi_Dimoco_Callback_Operator_Lookup_Repository::class,
    Kiwi_Dimoco_Callback_Refund_Repository::class,
    Kiwi_Dimoco_Callback_Blacklist_Repository::class,
    Kiwi_Device_Model_Brand_Map_Repository::class,
    Kiwi_Landing_Page_Session_Repository::class,
    Kiwi_Nth_Event_Repository::class,
    Kiwi_Nth_Flow_Transaction_Repository::class,
    Kiwi_Click_Attribution_Repository::class,
    Kiwi_Sales_Repository::class,
    Kiwi_Landing_Kpi_Summary_Repository::class,
    Kiwi_Landing_Handoff_Event_Repository::class,
    Kiwi_Sms_Body_Variant_Repository::class,
    Kiwi_Premium_Sms_Landing_Engagement_Repository::class,
    Kiwi_Premium_Sms_Fraud_Signal_Repository::class,
    Kiwi_Operational_Event_Repository::class,
    Kiwi_Retention_Cleanup_Run_Repository::class,
    Kiwi_Retention_Table_Growth_Snapshot_Repository::class,
    Kiwi_Landing_Funnel_Daily_Summary_Repository::class,
    Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository::class,
    Kiwi_Traffic_Source_Funnel_Statistics_Repository::class,
];

foreach ($required_classes as $required_class) {
    if (!class_exists($required_class)) {
        WP_CLI::error(
            'Kiwi Backend must be active and loaded before the database runner is executed.',
            false
        );
        WP_CLI::halt(1);
    }
}

$mode = strtolower(trim((string) ($args[0] ?? 'status')));
if (!in_array($mode, ['status', 'apply'], true)) {
    WP_CLI::error(
        'Usage: wp eval-file tools/database/kiwi-database.php [status|apply] --hook=plugins_loaded',
        false
    );
    WP_CLI::halt(1);
}

$service = new Kiwi_Database_Deployment_Service();
$result = $mode === 'apply' ? $service->apply() : $service->status();
$json = function_exists('wp_json_encode')
    ? wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

WP_CLI::line(is_string($json) ? $json : '{"success":false,"error_code":"json_encode_failed"}');

if (empty($result['success'])) {
    WP_CLI::halt(1);
}
