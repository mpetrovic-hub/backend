<?php

function kiwi_sms_body_variant_allocation_version_migration_cli_has_required_api(string $class_name): bool
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

if (!defined('WP_CLI')
    || !WP_CLI
    || !kiwi_sms_body_variant_allocation_version_migration_cli_has_required_api('WP_CLI')
) {
    if (defined('STDERR')) {
        fwrite(
            STDERR,
            "This migration requires WP-CLI 2.12 core APIs and must be loaded through --require.\n"
        );
    }

    exit(1);
}

final class Kiwi_Sms_Body_Variant_Allocation_Version_Migration_Command
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
            : [
                'Kiwi_Database_Table_Names',
                'Kiwi_Sms_Body_Variant_Repository',
            ];
        $this->service_factory = $service_factory ?? static function () {
            require_once dirname(__DIR__) . '/class-database-deployment-service.php';
            require_once __DIR__ . '/class-sms-body-variant-allocation-version-migration-service.php';

            return new Kiwi_Sms_Body_Variant_Allocation_Version_Migration_Service();
        };
        $this->json_encoder = $json_encoder ?? static function (array $result) {
            return function_exists('wp_json_encode')
                ? wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        };
    }

    /**
     * Inspect the allocation schema state without mutating the database.
     */
    public function check(array $args, array $assoc_args): void
    {
        $this->run('check');
    }

    /**
     * Apply the authorized additive migration; stop immediately on unavailable locks.
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
            $this->fail('WP-CLI could not register the migration lifecycle hook.');
        }

        $runner->load_wordpress();

        if (!$executed) {
            $this->fail('WordPress did not reach plugins_loaded; no migration operation was executed.');
        }

        $this->fail('The migration returned without stopping before WordPress init.');
    }

    private function execute(string $mode): void
    {
        if (!function_exists('did_action')
            || did_action('plugins_loaded') < 1
            || did_action('init') > 0
        ) {
            $this->fail('The migration must execute after plugins_loaded and before WordPress init.');
        }

        foreach ($this->required_classes as $required_class) {
            if (!is_string($required_class) || !class_exists($required_class)) {
                $this->fail('Kiwi Backend must be active and loaded before the migration is executed.');
            }
        }

        try {
            $service = call_user_func($this->service_factory);
            if (!is_object($service) || !method_exists($service, $mode)) {
                $this->fail('The SMS allocation-version migration service is unavailable.');
            }

            $result = $service->{$mode}();
        } catch (Throwable $error) {
            $this->fail('The migration failed before producing a safe result.');
        }

        if (!is_array($result)) {
            $this->fail('The migration returned an invalid result.');
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
        WP_CLI::error($message, false);
        WP_CLI::halt(1);
    }
}

/**
 * Empty namespace container used when the migration runner is loaded alone.
 */
final class Kiwi_Sms_Body_Variant_Allocation_Version_Migration_Command_Namespace
{
}

// Each --require invocation runs in a fresh WP-CLI process. Register the full
// hierarchy so this migration artifact does not depend on kiwi-database.php.
// Existing parents are harmless: add_command() returns false for duplicates,
// while the final leaf registration below still proves that the path is usable.
WP_CLI::add_command('kiwi', new Kiwi_Sms_Body_Variant_Allocation_Version_Migration_Command_Namespace());
WP_CLI::add_command('kiwi database', new Kiwi_Sms_Body_Variant_Allocation_Version_Migration_Command_Namespace());
WP_CLI::add_command('kiwi database migration', new Kiwi_Sms_Body_Variant_Allocation_Version_Migration_Command_Namespace());

$registered = WP_CLI::add_command(
    'kiwi database migration sms-body-variant-allocation-version',
    new Kiwi_Sms_Body_Variant_Allocation_Version_Migration_Command(),
    [
        'when' => 'before_wp_load',
        'shortdesc' => 'Check or apply the SMS allocation-version migration.',
    ]
);

if (!$registered) {
    WP_CLI::error('WP-CLI could not register the SMS allocation-version migration command.', false);
    WP_CLI::halt(1);
}
