<?php

// Explicit external deployment command; never include this from the plugin bootstrap.
if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) {
    if (defined('STDERR')) { fwrite(STDERR, "Load this runner through WP-CLI --require.\n"); }
    exit(1);
}

final class Kiwi_Retention_Sessions_Recovery_Command
{
    /**
     * Preview a bounded repair without persistent database writes.
     *
     * @synopsis --from=<date> --to=<date> --cutoff=<timestamp> --report=<path>
     * @when before_wp_load
     */
    public function preview(array $args, array $assoc_args): void { $this->run('preview', $assoc_args); }

    /**
     * Apply exactly the reviewed preview and start normal retention.
     *
     * @synopsis --from=<date> --to=<date> --cutoff=<timestamp> --expected-preview=<hash> --report=<path> [--max-workers=<n>]
     * @when before_wp_load
     */
    public function apply(array $args, array $assoc_args): void { $this->run('apply', $assoc_args); }

    /**
     * Continue the same receipt-backed recovery run.
     *
     * @synopsis --run-id=<id> --expected-preview=<hash> --report=<path> [--max-workers=<n>]
     * @when before_wp_load
     */
    public function resume(array $args, array $assoc_args): void { $this->run('resume', $assoc_args); }

    private function run(string $mode, array $options): void
    {
        $runner = WP_CLI::get_runner();
        if (!is_object($runner) || !method_exists($runner, 'load_wordpress')) {
            WP_CLI::error('WordPress loader unavailable.');
        }
        $executed = false;
        $registered = WP_CLI::add_wp_hook('plugins_loaded', function () use ($mode, $options, &$executed): void {
            $executed = true;
            $this->execute($mode, $options);
        });
        if (!$registered) { WP_CLI::error('Recovery lifecycle hook unavailable.'); }
        $runner->load_wordpress();
        WP_CLI::error($executed ? 'Recovery returned without halting before init.' : 'WordPress bootstrap incomplete.');
    }

    private function execute(string $mode, array $options): void
    {
        $stream = null;
        try {
            if (!function_exists('did_action') || did_action('plugins_loaded') < 1 || did_action('init') > 0
                || !class_exists('Kiwi_Landing_Funnel_Read_Context')) {
                throw new RuntimeException('recovery_bootstrap_not_ready');
            }
            foreach (['class-retention-recovery-sql.php', 'class-retention-recovery-handoff-reader.php', 'class-retention-sessions-recovery-service.php'] as $file) {
                require_once __DIR__ . '/' . $file;
            }
            $report_path = (string) ($options['report'] ?? '');
            $parent = realpath(dirname($report_path));
            $web = realpath(ABSPATH);
            if (!$parent || !$web || $report_path === '' || $report_path[0] !== DIRECTORY_SEPARATOR
                || $parent === $web || strpos($parent . DIRECTORY_SEPARATOR, $web . DIRECTORY_SEPARATOR) === 0
                || (fileperms($parent) & 0077) !== 0 || is_link($report_path) || file_exists($report_path)) {
                throw new RuntimeException('private_new_report_path_required');
            }
            $stream = @fopen($report_path, 'x');
            if (!$stream || !chmod($report_path, 0600) || !flock($stream, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('recovery_report_unavailable');
            }
            $journal = static function (array $record) use ($stream): void {
                $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
                if (fwrite($stream, $line) !== strlen($line) || !fflush($stream)
                    || (function_exists('fsync') && !fsync($stream))) {
                    throw new RuntimeException('recovery_report_write_failed');
                }
            };
            $service = new Kiwi_Retention_Sessions_Recovery_Service(null, null, $journal);
            $workers = (string) ($options['max-workers'] ?? '1');
            if (preg_match('/^(?:[1-9]|1[0-9]|20)$/D', $workers) !== 1) { throw new RuntimeException('recovery_worker_limit_invalid'); }
            if ($mode === 'resume') {
                $result = $service->resume((string) ($options['run-id'] ?? ''), (string) ($options['expected-preview'] ?? ''), (int) $workers);
            } elseif ($mode === 'apply') {
                $result = $service->apply((string) ($options['from'] ?? ''), (string) ($options['to'] ?? ''),
                    (string) ($options['cutoff'] ?? ''), (string) ($options['expected-preview'] ?? ''), (int) $workers);
            } else {
                $result = $service->preview((string) ($options['from'] ?? ''), (string) ($options['to'] ?? ''), (string) ($options['cutoff'] ?? ''));
            }
            $journal(['phase' => 'result', 'result' => $result]);
        } catch (Throwable $error) {
            $code = preg_match('/^[a-z][a-z0-9_]+$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'recovery_command_failed';
            $result = array_merge($result ?? [], ['success' => false, 'error_code' => $code]);
        } finally {
            if (is_resource($stream)) { fclose($stream); }
        }
        WP_CLI::line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $exit = empty($result['success']) ? 1 : (!empty($result['resume_required']) || ($mode === 'preview' && empty($result['ready_to_apply'])) ? 2 : 0);
        WP_CLI::halt($exit);
    }
}

if (!WP_CLI::add_command('kiwi retention-sessions-recovery', new Kiwi_Retention_Sessions_Recovery_Command(), ['when' => 'before_wp_load'])) {
    WP_CLI::error('Recovery command registration failed.');
}
