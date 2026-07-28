<?php

/**
 * One-time external recovery command for the Main daily-summary data gap
 * caused by the 2026-07-21 historical migration incident.
 *
 * Load only through WP-CLI's global --require option. The command halts after
 * plugins_loaded and before init so no website or cron runtime hooks execute.
 */

function kiwi_main_summary_backfill_cli_has_required_api(string $class_name): bool
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

if (!defined('WP_CLI') || !WP_CLI || !kiwi_main_summary_backfill_cli_has_required_api('WP_CLI')) {
    if (defined('STDERR')) {
        fwrite(
            STDERR,
            "This backfill requires WP-CLI 2.12 core APIs and must be loaded through --require.\n"
        );
    }

    exit(1);
}

final class Kiwi_Main_Summary_Backfill_20260706_13_Namespace
{
}

final class Kiwi_Main_Summary_Backfill_20260706_13_Command
{
    private const FROM_DATE = '2026-07-06';
    private const TO_DATE = '2026-07-13';
    private const CUTOFF_VALUE = '2026-07-14 00:00:00';
    private const CONFIRM_VALUE = 'backfill-main-summary-20260706-13';
    private const LOCK_KEY = 'kiwi_landing_funnel_daily_main_summary_refresh_lock';
    private const LOCK_VALUE = 'main_summary_backfill_20260706_13';
    private const LOCK_TTL_SECONDS = 900;

    /**
     * Inspect the exact target range without changing data.
     */
    public function preflight(array $args, array $assoc_args): void
    {
        $this->run('preflight');
    }

    /**
     * Rebuild the exact missing Main-summary range after an explicit confirmation.
     */
    public function apply(array $args, array $assoc_args): void
    {
        if ((string) ($assoc_args['confirm'] ?? '') !== self::CONFIRM_VALUE) {
            $this->emit_and_halt([
                'success' => false,
                'mode' => 'apply',
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
            $this->fail('WP-CLI could not register the backfill lifecycle hook.');
        }

        $runner->load_wordpress();

        if (!$executed) {
            $this->fail('WordPress did not reach plugins_loaded; no backfill operation was executed.');
        }

        $this->fail('The backfill command returned without stopping before WordPress init.');
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
            'Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service',
            'Kiwi_Landing_Funnel_Daily_Summary_Repository',
            'Kiwi_Retention_Source_Registry',
            'Kiwi_Retention_Coverage_Gate',
            'Kiwi_Config',
        ] as $required_class) {
            if (!class_exists($required_class)) {
                $this->fail('Kiwi Backend must be active and fully loaded before the backfill runs.');
            }
        }

        $preflight = $this->inspect_target_range();
        if (empty($preflight['success'])) {
            $this->emit_and_halt([
                'success' => false,
                'mode' => $mode,
                'preflight' => $preflight,
            ], 1);
        }

        if ($mode === 'preflight') {
            $this->emit_and_halt([
                'success' => true,
                'mode' => 'preflight',
                'preflight' => $preflight,
            ], 0);
        }

        if (!empty($preflight['refresh_lock_active'])) {
            $this->emit_and_halt([
                'success' => false,
                'mode' => 'apply',
                'error_code' => 'main_summary_refresh_lock_active',
                'preflight' => $preflight,
            ], 1);
        }

        $lock_set = false;
        $final_result = null;
        $exit_code = 1;
        try {
            if (!function_exists('set_transient') || !function_exists('get_transient')) {
                throw new RuntimeException('WordPress transient locking is unavailable.');
            }

            set_transient(self::LOCK_KEY, self::LOCK_VALUE, self::LOCK_TTL_SECONDS);
            $lock_set = get_transient(self::LOCK_KEY) === self::LOCK_VALUE;
            if (!$lock_set) {
                throw new RuntimeException('The Main summary refresh lock could not be acquired.');
            }

            $locked_preflight = $this->inspect_target_range();
            if (empty($locked_preflight['success'])) {
                $final_result = [
                    'success' => false,
                    'mode' => 'apply',
                    'preflight' => $locked_preflight,
                ];
            } else {
                $service = new Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service(
                    new Kiwi_Landing_Funnel_Daily_Summary_Repository()
                );
                $backfill_result = $service->refresh_range(self::FROM_DATE, self::TO_DATE);
                if (!is_array($backfill_result) || empty($backfill_result['success'])) {
                    $final_result = [
                        'success' => false,
                        'mode' => 'apply',
                        'preflight' => $locked_preflight,
                        'backfill' => is_array($backfill_result) ? $backfill_result : [],
                        'error_code' => 'main_summary_refresh_failed',
                    ];
                } else {
                    $postflight = $this->inspect_target_range();
                    $coverage_gate = $this->check_coverage_gate();
                    $success = !empty($postflight['success'])
                        && $this->all_target_days_have_summary_rows($postflight)
                        && (string) ($coverage_gate['status'] ?? '') === 'passed';

                    $final_result = [
                        'success' => $success,
                        'mode' => 'apply',
                        'preflight' => $locked_preflight,
                        'backfill' => $backfill_result,
                        'postflight' => $postflight,
                        'coverage_gate' => $this->compact_gate_result($coverage_gate),
                        'error_code' => $success ? '' : 'postflight_validation_failed',
                    ];
                    $exit_code = $success ? 0 : 1;
                }
            }
        } catch (Throwable $error) {
            $final_result = [
                'success' => false,
                'mode' => 'apply',
                'error_code' => 'backfill_command_failed',
                'error_message' => 'The Main-summary backfill command failed before safe completion.',
            ];
        } finally {
            if ($lock_set && function_exists('delete_transient')) {
                delete_transient(self::LOCK_KEY);
            }
        }

        $this->emit_and_halt(
            is_array($final_result) ? $final_result : [
                    'success' => false,
                    'mode' => 'apply',
                    'error_code' => 'backfill_command_failed',
                    'error_message' => 'The Main-summary backfill command did not produce a result.',
                ],
            $exit_code
        );
    }

    private function inspect_target_range(): array
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            return [
                'success' => false,
                'error_code' => 'wpdb_unavailable',
                'error_message' => 'WordPress database access is unavailable.',
            ];
        }

        $summary_table = (new Kiwi_Landing_Funnel_Daily_Summary_Repository())->get_table_name();
        $source_table = $wpdb->prefix . 'kiwi_landing_page_sessions';
        if (!$this->is_identifier($summary_table) || !$this->is_identifier($source_table)) {
            return [
                'success' => false,
                'error_code' => 'table_identifier_invalid',
                'error_message' => 'The expected Main-summary or landing-session table identifier is invalid.',
            ];
        }

        $to_exclusive = '2026-07-14 00:00:00';
        $from_inclusive = self::FROM_DATE . ' 00:00:00';
        $raw_query = $wpdb->prepare(
            "SELECT DATE(created_at) AS metric_date, COUNT(*) AS row_count
             FROM {$source_table}
             WHERE created_at >= %s AND created_at < %s
             GROUP BY DATE(created_at)
             ORDER BY metric_date ASC",
            $from_inclusive,
            $to_exclusive
        );
        $summary_query = $wpdb->prepare(
            "SELECT metric_date, COUNT(*) AS row_count
             FROM {$summary_table}
             WHERE metric_date >= %s AND metric_date < %s
             GROUP BY metric_date
             ORDER BY metric_date ASC",
            self::FROM_DATE,
            '2026-07-14'
        );
        if ($raw_query === false || $summary_query === false) {
            return [
                'success' => false,
                'error_code' => 'preflight_query_prepare_failed',
                'error_message' => 'The bounded Main-summary preflight query could not be prepared.',
            ];
        }

        $raw_rows = $wpdb->get_results($raw_query, ARRAY_A);
        $summary_rows = $wpdb->get_results($summary_query, ARRAY_A);
        if (!is_array($raw_rows) || !is_array($summary_rows)) {
            return [
                'success' => false,
                'error_code' => 'preflight_query_failed',
                'error_message' => 'The bounded Main-summary preflight query failed.',
            ];
        }

        $raw_counts = $this->map_daily_counts($raw_rows);
        $summary_counts = $this->map_daily_counts($summary_rows);
        $days = $this->target_days();
        $missing_raw_days = [];
        $per_day = [];
        foreach ($days as $date) {
            $raw_count = (int) ($raw_counts[$date] ?? 0);
            if ($raw_count < 1) {
                $missing_raw_days[] = $date;
            }
            $per_day[] = [
                'metric_date' => $date,
                'raw_rows' => $raw_count,
                'main_summary_rows' => (int) ($summary_counts[$date] ?? 0),
            ];
        }

        return [
            'success' => empty($missing_raw_days),
            'from_date' => self::FROM_DATE,
            'to_date' => self::TO_DATE,
            'refresh_lock_active' => function_exists('get_transient')
                && get_transient(self::LOCK_KEY) !== false,
            'missing_raw_days' => $missing_raw_days,
            'per_day' => $per_day,
        ];
    }

    private function check_coverage_gate(): array
    {
        $source = (new Kiwi_Retention_Source_Registry())->get(
            Kiwi_Retention_Source_Registry::SOURCE_LANDING_PAGE_SESSIONS
        );
        if (!is_array($source)) {
            return [
                'status' => 'failed',
                'blocking_errors' => ['retention_source_unavailable'],
            ];
        }

        return (new Kiwi_Retention_Coverage_Gate(new Kiwi_Config()))
            ->check_landing_page_sessions($source, self::CUTOFF_VALUE);
    }

    private function compact_gate_result(array $result): array
    {
        return [
            'status' => (string) ($result['status'] ?? ''),
            'verified_until_date' => (string) ($result['verified_until_date'] ?? ''),
            'blocked_dates' => array_values((array) ($result['blocked_dates'] ?? [])),
            'warning_dates' => array_values((array) ($result['warning_dates'] ?? [])),
            'blocking_errors' => array_values((array) ($result['blocking_errors'] ?? [])),
            'main_summary_status' => (string) (($result['main_summary']['status'] ?? '')),
            'tkzone_summary_status' => (string) (($result['tkzone_summary']['status'] ?? '')),
        ];
    }

    private function all_target_days_have_summary_rows(array $preflight): bool
    {
        foreach ((array) ($preflight['per_day'] ?? []) as $day) {
            if ((int) ($day['main_summary_rows'] ?? 0) < 1) {
                return false;
            }
        }

        return true;
    }

    private function map_daily_counts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $date = (string) ($row['metric_date'] ?? '');
            if ($date !== '') {
                $counts[$date] = (int) ($row['row_count'] ?? 0);
            }
        }

        return $counts;
    }

    private function target_days(): array
    {
        $days = [];
        $current = self::FROM_DATE;
        while (strcmp($current, self::TO_DATE) <= 0) {
            $days[] = $current;
            $timestamp = strtotime($current . ' +1 day');
            if ($timestamp === false) {
                break;
            }
            $current = gmdate('Y-m-d', $timestamp);
        }

        return $days;
    }

    private function is_identifier(string $value): bool
    {
        return $value !== '' && preg_match('/^[A-Za-z0-9_]+$/', $value) === 1;
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

WP_CLI::add_command('kiwi', new Kiwi_Main_Summary_Backfill_20260706_13_Namespace());
$registered = WP_CLI::add_command(
    'kiwi main-summary-backfill-20260706-13',
    new Kiwi_Main_Summary_Backfill_20260706_13_Command(),
    [
        'when' => 'before_wp_load',
        'shortdesc' => 'Externally backfill Main daily summaries for 2026-07-06 through 2026-07-13.',
    ]
);

if (!$registered) {
    WP_CLI::error('WP-CLI could not register the Main-summary backfill command.', false);
    WP_CLI::halt(1);
}
