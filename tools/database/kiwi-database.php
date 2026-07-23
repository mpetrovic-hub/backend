<?php

function kiwi_database_cli_has_required_api(string $class_name): bool
{
    if (!class_exists($class_name)) {
        return false;
    }

    foreach (['add_command', 'add_wp_hook', 'get_runner', 'error', 'halt', 'line'] as $method) {
        if (!method_exists($class_name, $method)) {
            return false;
        }
    }

    return true;
}

if (!defined('WP_CLI') || !WP_CLI || !kiwi_database_cli_has_required_api('WP_CLI')) {
    if (defined('STDERR')) {
        fwrite(
            STDERR,
            "This runner requires WP-CLI 2.12 core APIs and must be loaded through --require.\n"
        );
    }

    exit(1);
}

/**
 * Empty container for repository-owned Kiwi WP-CLI commands.
 */
final class Kiwi_WP_CLI_Command_Namespace
{
}

/**
 * External database deployment commands for WP-CLI.
 */
final class Kiwi_Database_Command
{
    private $required_classes;
    private $service_factory;
    private $json_encoder;

    public function __construct(
        ?array $required_classes = null,
        ?callable $service_factory = null,
        ?callable $json_encoder = null
    ) {
        $this->required_classes = is_array($required_classes)
            ? $required_classes
            : $this->default_required_classes();
        $this->service_factory = $service_factory ?? static function () {
            require_once __DIR__ . '/class-database-deployment-service.php';

            return new Kiwi_Database_Deployment_Service();
        };
        $this->json_encoder = $json_encoder ?? static function (array $result) {
            return function_exists('wp_json_encode')
                ? wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        };
    }

    /**
     * Inspect real schema postconditions without mutating the database.
     */
    public function status(array $args, array $assoc_args): void
    {
        $this->run('status');
    }

    /**
     * Apply the canonical schema after explicit deployment authorization.
     */
    public function apply(array $args, array $assoc_args): void
    {
        $this->run('apply');
    }

    private function run(string $mode): void
    {
        $runner = WP_CLI::get_runner();
        if (!is_object($runner) || !method_exists($runner, 'load_wordpress')) {
            $this->fail('WP-CLI cannot provide the required WordPress loader.');
        }

        $executed = false;
        $hook_added = WP_CLI::add_wp_hook(
            'plugins_loaded',
            function () use ($mode, &$executed): void {
                $executed = true;
                $this->execute($mode);
            }
        );

        if (!$hook_added) {
            $this->fail('WP-CLI could not register the database runner lifecycle hook.');
        }

        $runner->load_wordpress();

        if (!$executed) {
            $this->fail('WordPress did not reach plugins_loaded; no database operation was executed.');
        }

        $this->fail('The database runner returned without stopping before WordPress init.');
    }

    private function execute(string $mode): void
    {
        if (!function_exists('did_action')
            || did_action('plugins_loaded') < 1
            || did_action('init') > 0
        ) {
            $this->fail(
                'The database runner must execute after plugins_loaded and before WordPress init.'
            );
        }

        foreach ($this->required_classes as $required_class) {
            if (!is_string($required_class) || !class_exists($required_class)) {
                $this->fail(
                    'Kiwi Backend must be active and loaded before the database runner is executed.'
                );
            }
        }

        try {
            $service = call_user_func($this->service_factory);
            if (!is_object($service) || !method_exists($service, $mode)) {
                $this->fail('The database deployment service is unavailable.');
            }

            $result = $service->{$mode}();
        } catch (Throwable $error) {
            $this->fail('The database runner failed before producing a safe result.');
        }

        if (!is_array($result)) {
            $this->fail('The database runner returned an invalid result.');
        }

        $json = call_user_func($this->json_encoder, $result);
        if (!is_string($json)) {
            WP_CLI::line('{"success":false,"error_code":"json_encode_failed"}');
            WP_CLI::halt(1);
        }

        WP_CLI::line($json);
        WP_CLI::halt(empty($result['success']) ? 1 : 0);
    }

    private function fail(string $message): void
    {
        WP_CLI::error(
            $message,
            false
        );
        WP_CLI::halt(1);
    }

    private function default_required_classes(): array
    {
        return [
            'Kiwi_Dimoco_Callback_Operator_Lookup_Repository',
            'Kiwi_Dimoco_Callback_Refund_Repository',
            'Kiwi_Dimoco_Callback_Blacklist_Repository',
            'Kiwi_Device_Model_Brand_Map_Repository',
            'Kiwi_Landing_Page_Session_Repository',
            'Kiwi_Nth_Event_Repository',
            'Kiwi_Nth_Flow_Transaction_Repository',
            'Kiwi_Click_Attribution_Repository',
            'Kiwi_Sales_Repository',
            'Kiwi_Landing_Kpi_Summary_Repository',
            'Kiwi_Landing_Handoff_Event_Repository',
            'Kiwi_Sms_Body_Variant_Repository',
            'Kiwi_Premium_Sms_Landing_Engagement_Repository',
            'Kiwi_Premium_Sms_Fraud_Signal_Repository',
            'Kiwi_Operational_Event_Repository',
            'Kiwi_Retention_Cleanup_Run_Repository',
            'Kiwi_Retention_Table_Growth_Snapshot_Repository',
            'Kiwi_Landing_Funnel_Daily_Summary_Repository',
            'Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository',
            'Kiwi_Traffic_Source_Funnel_Statistics_Repository',
        ];
    }
}

WP_CLI::add_command('kiwi', new Kiwi_WP_CLI_Command_Namespace());
$registered = WP_CLI::add_command(
    'kiwi database',
    new Kiwi_Database_Command(),
    [
        'when' => 'before_wp_load',
        'shortdesc' => 'Inspect or apply the Kiwi database deployment contract.',
    ]
);

if (!$registered) {
    WP_CLI::error('WP-CLI could not register the kiwi database command.', false);
    WP_CLI::halt(1);
}
