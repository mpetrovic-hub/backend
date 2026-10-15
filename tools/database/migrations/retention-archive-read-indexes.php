<?php

// Explicit WP-CLI artifact; not part of normal plugin bootstrap.
if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) {
    if (defined('STDERR')) { fwrite(STDERR, "Archive preparation requires WP-CLI 2.12 through --require.\n"); }
    exit(1);
}
foreach (['add_command', 'add_wp_hook', 'get_runner', 'line', 'halt', 'error'] as $method) {
    if (!method_exists('WP_CLI', $method)) {
        if (defined('STDERR')) { fwrite(STDERR, "Required WP-CLI lifecycle APIs are missing.\n"); }
        exit(1);
    }
}

final class Kiwi_Retention_Archive_Read_Indexes_Command
{
    /**
     * Inspect required archive indexes without modifying the archive.
     * @synopsis --archive=<file>
     */
    public function status(array $args, array $assoc_args): void { $this->run('status', $assoc_args); }
    /**
     * Create or replace approved read indexes as an authorized deployment step.
     * @synopsis --archive=<file>
     */
    public function apply(array $args, array $assoc_args): void { $this->run('apply', $assoc_args); }

    private function run(string $mode, array $assoc_args): void
    {
        $archive = (string) ($assoc_args['archive'] ?? '');
        if ($archive === '' || basename($archive) !== $archive) {
            $this->fail('Select one canonical archive filename with --archive.');
        }
        $executed = false;
        WP_CLI::add_wp_hook('plugins_loaded', function () use ($mode, $archive, &$executed): void {
            $executed = true;
            if (!function_exists('did_action') || did_action('plugins_loaded') < 1 || did_action('init') > 0) {
                $this->fail('Archive preparation must run after plugins_loaded and before init.');
            }
            foreach (['Kiwi_Retention_Archive_Reader', 'Kiwi_Retention_Source_Registry',
                'Kiwi_Retention_Corruption_Safety_Gate_Coordinator'] as $class) {
                if (!class_exists($class)) { $this->fail('Kiwi Backend must be active and loaded.'); }
            }
            require_once __DIR__ . '/class-retention-archive-read-indexes-migration-service.php';
            $result = (new Kiwi_Retention_Archive_Read_Indexes_Migration_Service())->{$mode}($archive);
            $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            WP_CLI::line($json);
            WP_CLI::halt(!empty($result['success']) && !empty($result['ready']) ? 0 : 2);
        }, 0);
        $runner = WP_CLI::get_runner();
        if (!is_object($runner) || !method_exists($runner, 'load_wordpress')) { $this->fail('WordPress loader is unavailable.'); }
        $runner->load_wordpress();
        $this->fail($executed ? 'Preparation did not stop before init.' : 'WordPress did not reach plugins_loaded.');
    }

    private function fail(string $message): void
    {
        WP_CLI::error($message, false);
        WP_CLI::halt(2);
    }
}

final class Kiwi_Retention_Archive_Read_Indexes_Command_Namespace {}
WP_CLI::add_command('kiwi', new Kiwi_Retention_Archive_Read_Indexes_Command_Namespace());
WP_CLI::add_command('kiwi retention', new Kiwi_Retention_Archive_Read_Indexes_Command_Namespace());
if (!WP_CLI::add_command('kiwi retention archive-read-indexes', new Kiwi_Retention_Archive_Read_Indexes_Command(),
    ['when' => 'before_wp_load', 'shortdesc' => 'Inspect or explicitly prepare retention archive read indexes.'])
) {
    WP_CLI::error('Archive read-index command registration failed.', false);
    WP_CLI::halt(2);
}
