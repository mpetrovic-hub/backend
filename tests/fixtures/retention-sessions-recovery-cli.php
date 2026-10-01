<?php

// Exercise the runner's early WP-CLI lifecycle against the real recovery service.
define('WP_CLI', true);
class Recovery_CLI_Halt extends RuntimeException {}
class WP_CLI
{
    public static $command;
    public static $registration;
    public static $callbacks = [];
    public static $output = '';
    public static $init_reached = false;
    public static function add_command($name, $command, $options): bool
    {
        self::$command = $command;
        self::$registration = [$name, $options];
        return true;
    }
    public static function add_wp_hook($name, $callback): bool
    {
        sandbox_assert($name === 'plugins_loaded', 'Recovery must run before init.');
        self::$callbacks[] = $callback;
        return true;
    }
    public static function get_runner()
    {
        return new class {
            public function load_wordpress(): void
            {
                foreach (WP_CLI::$callbacks as $callback) { $callback(); }
                WP_CLI::$init_reached = true;
            }
        };
    }
    public static function line($line): void { self::$output = $line; }
    public static function halt($code): void { throw new Recovery_CLI_Halt('halt', $code); }
    public static function error($message): void { throw new RuntimeException($message); }
}
function did_action($action): int { return $action === 'plugins_loaded' ? 1 : 0; }
require __DIR__ . '/../../tools/database/kiwi-retention-sessions-recovery.php';

function run_cli_preview(array $options): array
{
    WP_CLI::$callbacks = [];
    WP_CLI::$output = '';
    try { WP_CLI::$command->preview([], $options); }
    catch (Recovery_CLI_Halt $halt) { return ['exit' => $halt->getCode(), 'result' => json_decode(WP_CLI::$output, true)]; }
    throw new RuntimeException('Recovery CLI did not halt.');
}
