<?php

/**
 * One-time external rebuild of the Main landing-funnel daily summary for
 * 2026-07-18.
 *
 * It is intentionally a dated WP-CLI artifact, not WordPress runtime logic.
 * The apply command requires an exact confirmation, holds the existing Main
 * summary refresh lock, refreshes exactly one metric date transactionally, and
 * proves afterwards that the current retention coverage gate can safely pass.
 */

function kiwi_main_summary_backfill_20260718_cli_has_required_api(string $class_name): bool
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

if (!defined('WP_CLI') || !WP_CLI || !kiwi_main_summary_backfill_20260718_cli_has_required_api('WP_CLI')) {
    if (defined('STDERR')) {
        fwrite(STDERR, "This backfill requires WP-CLI 2.12 core APIs and must be loaded through --require.\n");
    }

    exit(1);
}

final class Kiwi_Main_Summary_Backfill_20260718_Namespace
{
}

final class Kiwi_Main_Summary_Backfill_20260718_Command
{
    private const METRIC_DATE = '2026-07-18';
    private const RETENTION_CUTOFF = '2026-07-21 00:00:00';
    private const CONFIRM_VALUE = 'backfill-main-summary-20260718';
    private const LOCK_KEY = 'kiwi_landing_funnel_daily_main_summary_refresh_lock';
    private const LOCK_VALUE = 'main_summary_backfill_20260718';
    private const LOCK_TTL_SECONDS = 900;

    public function preflight(array $args, array $assoc_args): void
    {
        $this->run('preflight');
    }

    public function apply(array $args, array $assoc_args): void
    {
        if ((string) ($assoc_args['confirm'] ?? '') !== self::CONFIRM_VALUE) {
            $this->emit_and_halt([
                'success' => false,
                'mode' => 'apply',
                'changed' => false,
                'error_code' => 'confirmation_required',
                'error_message' => 'Apply requires --confirm=' . self::CONFIRM_VALUE . '.',
            ], 1);
        }

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
            $this->fail('WP-CLI could not register the Main-summary backfill lifecycle hook.');
        }

        $runner->load_wordpress();
        if (!$executed) {
            $this->fail('WordPress did not reach plugins_loaded; no backfill operation was executed.');
        }

        $this->fail('The Main-summary backfill returned without stopping before WordPress init.');
    }

    private function execute(string $mode): void
    {
        if (!function_exists('did_action')
            || did_action('plugins_loaded') < 1
            || did_action('init') > 0
        ) {
            $this->fail('The backfill must execute after plugins_loaded and before WordPress init.');
        }

        foreach ([
            'Kiwi_Config',
            'Kiwi_Retention_Source_Registry',
            'Kiwi_Retention_Coverage_Gate',
            'Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service',
            'Kiwi_Landing_Funnel_Daily_Summary_Repository',
        ] as $required_class) {
            if (!class_exists($required_class)) {
                $this->fail('Kiwi Backend must be active and fully loaded before the Main-summary backfill runs.');
            }
        }

        $preflight = $this->inspect(false);
        if (empty($preflight['success'])) {
            $this->emit_and_halt([
                'success' => false,
                'mode' => $mode,
                'changed' => false,
                'preflight' => $preflight,
            ], 1);
        }

        if ($mode === 'preflight') {
            $this->emit_and_halt([
                'success' => true,
                'mode' => 'preflight',
                'changed' => false,
                'preflight' => $preflight,
                'next_step' => 'The exact 2026-07-18 Main-summary mismatch is still present and may be rebuilt only with explicit apply confirmation.',
            ], 0);
        }

        $lock_set = false;
        $result = null;
        try {
            if (!function_exists('set_transient') || !function_exists('get_transient')) {
                throw new RuntimeException('WordPress transient locking is unavailable.');
            }

            set_transient(self::LOCK_KEY, self::LOCK_VALUE, self::LOCK_TTL_SECONDS);
            $lock_set = get_transient(self::LOCK_KEY) === self::LOCK_VALUE;
            if (!$lock_set) {
                throw new RuntimeException('The Main-summary refresh lock could not be acquired.');
            }

            $locked_preflight = $this->inspect(true);
            if (empty($locked_preflight['success'])) {
                $result = [
                    'success' => false,
                    'mode' => 'apply',
                    'changed' => false,
                    'error_code' => 'locked_preflight_failed',
                    'preflight' => $locked_preflight,
                ];
            } else {
                $refresh = (new Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service(
                    new Kiwi_Landing_Funnel_Daily_Summary_Repository()
                ))->refresh_range(self::METRIC_DATE, self::METRIC_DATE);

                $postflight = $this->inspect_after_refresh(true);
                $success = !empty($refresh['success']) && !empty($postflight['success']);
                $result = [
                    'success' => $success,
                    'mode' => 'apply',
                    'changed' => true,
                    'preflight' => $locked_preflight,
                    'refresh' => is_array($refresh) ? $refresh : [],
                    'postflight' => $postflight,
                    'error_code' => $success ? '' : 'postflight_validation_failed',
                    'next_step' => $success
                        ? 'The Main summary for 2026-07-18 was rebuilt and the retention coverage gate now passes.'
                        : 'The date refresh did not meet every postcondition. Do not start retention manually; inspect the returned audit evidence.',
                ];
            }
        } catch (Throwable $error) {
            $result = [
                'success' => false,
                'mode' => 'apply',
                'changed' => false,
                'error_code' => 'backfill_command_failed',
                'error_message' => 'The Main-summary backfill stopped before safe completion.',
            ];
        } finally {
            if ($lock_set && function_exists('delete_transient')) {
                delete_transient(self::LOCK_KEY);
            }
        }

        $this->emit_and_halt(
            is_array($result) ? $result : [
                'success' => false,
                'mode' => 'apply',
                'changed' => false,
                'error_code' => 'backfill_command_failed',
                'error_message' => 'The Main-summary backfill did not produce a result.',
            ],
            !empty($result['success']) ? 0 : 1
        );
    }

    private function inspect(bool $allow_own_lock): array
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            return $this->failure('wpdb_unavailable', 'WordPress database access is unavailable.');
        }

        $summary_table = (new Kiwi_Landing_Funnel_Daily_Summary_Repository())->get_table_name();
        $source_table = $wpdb->prefix . 'kiwi_landing_page_sessions';
        if (!$this->is_identifier($summary_table) || !$this->is_identifier($source_table)) {
            return $this->failure('table_identifier_invalid', 'The expected Main-summary or raw-session table identifier is invalid.');
        }

        $raw_rows = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$source_table} WHERE created_at >= %s AND created_at < %s",
            self::METRIC_DATE . ' 00:00:00',
            '2026-07-19 00:00:00'
        ));
        $summary_rows = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$summary_table} WHERE metric_date = %s",
            self::METRIC_DATE
        ));
        $gate = $this->check_coverage_gate();
        $refresh_lock = function_exists('get_transient') ? get_transient(self::LOCK_KEY) : false;
        $lock_ok = $refresh_lock === false || ($allow_own_lock && $refresh_lock === self::LOCK_VALUE);
        $gate_matches_expected_failure = (string) ($gate['status'] ?? '') === 'failed'
            && in_array(self::METRIC_DATE, (array) ($gate['blocked_dates'] ?? []), true)
            && (string) (($gate['main_summary']['status'] ?? '')) === 'failed';

        return [
            'success' => $raw_rows > 0 && $summary_rows > 0 && $lock_ok && $gate_matches_expected_failure,
            'metric_date' => self::METRIC_DATE,
            'raw_rows' => $raw_rows,
            'main_summary_rows_before_refresh' => $summary_rows,
            'refresh_lock_active' => $refresh_lock !== false,
            'refresh_lock_owned_by_runner' => $refresh_lock === self::LOCK_VALUE,
            'expected_gate_failure_present' => $gate_matches_expected_failure,
            'coverage_gate' => $this->compact_gate($gate),
            'blocking_checks' => array_values(array_filter([
                $raw_rows > 0 ? '' : 'target_raw_rows_missing',
                $summary_rows > 0 ? '' : 'target_main_summary_rows_missing',
                $lock_ok ? '' : 'main_summary_refresh_lock_active',
                $gate_matches_expected_failure ? '' : 'expected_2026_07_18_gate_failure_not_present',
            ])),
        ];
    }

    private function inspect_after_refresh(bool $allow_own_lock): array
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            return $this->failure('wpdb_unavailable', 'WordPress database access is unavailable.');
        }

        $summary_table = (new Kiwi_Landing_Funnel_Daily_Summary_Repository())->get_table_name();
        $source_table = $wpdb->prefix . 'kiwi_landing_page_sessions';
        if (!$this->is_identifier($summary_table) || !$this->is_identifier($source_table)) {
            return $this->failure('table_identifier_invalid', 'The expected Main-summary or raw-session table identifier is invalid.');
        }

        $raw_rows = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$source_table} WHERE created_at >= %s AND created_at < %s",
            self::METRIC_DATE . ' 00:00:00',
            '2026-07-19 00:00:00'
        ));
        $summary_rows = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$summary_table} WHERE metric_date = %s",
            self::METRIC_DATE
        ));
        $gate = $this->check_coverage_gate();
        $refresh_lock = function_exists('get_transient') ? get_transient(self::LOCK_KEY) : false;
        $lock_ok = $refresh_lock === false || ($allow_own_lock && $refresh_lock === self::LOCK_VALUE);
        $gate_passed = (string) ($gate['status'] ?? '') === 'passed'
            && !in_array(self::METRIC_DATE, (array) ($gate['blocked_dates'] ?? []), true);

        return [
            'success' => $raw_rows > 0 && $summary_rows > 0 && $lock_ok && $gate_passed,
            'metric_date' => self::METRIC_DATE,
            'raw_rows' => $raw_rows,
            'main_summary_rows_after_refresh' => $summary_rows,
            'refresh_lock_active' => $refresh_lock !== false,
            'refresh_lock_owned_by_runner' => $refresh_lock === self::LOCK_VALUE,
            'coverage_gate_passed' => $gate_passed,
            'coverage_gate' => $this->compact_gate($gate),
            'blocking_checks' => array_values(array_filter([
                $raw_rows > 0 ? '' : 'target_raw_rows_missing',
                $summary_rows > 0 ? '' : 'target_main_summary_rows_missing',
                $lock_ok ? '' : 'main_summary_refresh_lock_active',
                $gate_passed ? '' : 'coverage_gate_not_passed_after_refresh',
            ])),
        ];
    }

    private function check_coverage_gate(): array
    {
        $source = (new Kiwi_Retention_Source_Registry())->get(
            Kiwi_Retention_Source_Registry::SOURCE_LANDING_PAGE_SESSIONS
        );
        if (!is_array($source)) {
            return ['status' => 'failed', 'blocking_errors' => ['retention_source_unavailable']];
        }

        return (new Kiwi_Retention_Coverage_Gate(new Kiwi_Config()))
            ->check_landing_page_sessions($source, self::RETENTION_CUTOFF);
    }

    private function compact_gate(array $gate): array
    {
        return [
            'status' => (string) ($gate['status'] ?? ''),
            'requested_cutoff_value' => (string) ($gate['requested_cutoff_value'] ?? ''),
            'effective_cutoff_value' => (string) ($gate['effective_cutoff_value'] ?? ''),
            'verified_until_date' => (string) ($gate['verified_until_date'] ?? ''),
            'blocked_dates' => array_values((array) ($gate['blocked_dates'] ?? [])),
            'warning_dates' => array_values((array) ($gate['warning_dates'] ?? [])),
            'blocking_errors' => array_values((array) ($gate['blocking_errors'] ?? [])),
            'main_summary_status' => (string) (($gate['main_summary']['status'] ?? '')),
            'tkzone_summary_status' => (string) (($gate['tkzone_summary']['status'] ?? '')),
        ];
    }

    private function is_identifier(string $value): bool
    {
        return $value !== '' && preg_match('/^[A-Za-z0-9_]+$/', $value) === 1;
    }

    private function failure(string $error_code, string $error_message): array
    {
        return [
            'success' => false,
            'error_code' => $error_code,
            'error_message' => $error_message,
        ];
    }

    private function emit_and_halt(array $result, int $exit_code): void
    {
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            WP_CLI::line('{"success":false,"error_code":"json_encode_failed"}');
            WP_CLI::halt(1);
        }

        WP_CLI::line($json);
        WP_CLI::halt($exit_code);
    }

    private function fail(string $message): void
    {
        WP_CLI::error($message, false);
        WP_CLI::halt(1);
    }
}

WP_CLI::add_command('kiwi', new Kiwi_Main_Summary_Backfill_20260718_Namespace());
$registered = WP_CLI::add_command(
    'kiwi main-summary-backfill-20260718',
    new Kiwi_Main_Summary_Backfill_20260718_Command(),
    [
        'when' => 'before_wp_load',
        'shortdesc' => 'Externally rebuild the Main daily summary for 2026-07-18.',
    ]
);

if (!$registered) {
    WP_CLI::error('WP-CLI could not register the 2026-07-18 Main-summary backfill command.', false);
    WP_CLI::halt(1);
}
