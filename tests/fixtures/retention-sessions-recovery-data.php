<?php

final class Recovery_Fixture_Config extends Kiwi_Config
{
    private $archive_root;
    public int $handoff_days = 13;
    public function __construct(string $archive_root) { $this->archive_root = $archive_root; }
    public function get_retention_archive_root(): string { return $this->archive_root; }
    public function get_retention_worker_row_limit(): int { return 7; }
    public function get_retention_default_batch_limit(): int { return 3; }
    public function get_retention_source_settings(string $source): array
    {
        return ['enabled' => true, 'dry_run' => false, 'retention_days' => $source === 'landing_handoff_events' ? $this->handoff_days : 14];
    }
}
const FROM_DATE = '2026-08-30';
const TO_DATE = '2026-09-06';
const CUTOFF = '2026-09-07 00:00:00';
const MAIN_TABLE = 'wp_kiwi_landing_funnel_daily_summary';
const TK_TABLE = 'wp_kiwi_landing_funnel_daily_tkzone_summary';
const SESSIONS = 'wp_kiwi_landing_page_sessions';
const HANDOFFS = 'wp_kiwi_landing_handoff_events';
const ENGAGEMENTS = 'wp_kiwi_landing_session_engagements';

function metric_dates(): array
{
    return array_map(fn($i) => gmdate('Y-m-d', strtotime(FROM_DATE . ' UTC') + $i * 86400), range(0, 7));
}

function refresh_candidates(Recovery_Fixture_Config $config): void
{
    $main = (new Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service())->refresh_range(FROM_DATE, TO_DATE);
    sandbox_assert(!empty($main['success']), 'Main refresh: ' . ($main['error'] ?? 'failed'));
    $tk = (new Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Aggregation_Service(null, $config))->refresh_range(FROM_DATE, TO_DATE);
    sandbox_assert(!empty($tk['success']), 'TK refresh: ' . ($tk['error'] ?? 'failed'));
}

function fixture(string $scenario): array
{
    global $admin, $sandbox, $wpdb;
    $name = 'retention_sessions_recovery_' . bin2hex(random_bytes(6));
    $admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = new PDO('mysql:unix_socket=' . $sandbox . '/mariadb.sock;dbname=' . $name, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $wpdb = new Sandbox_Wpdb($pdo);
    $GLOBALS['sandbox_transients'] = [];
    $config = new Recovery_Fixture_Config($sandbox . '/' . $scenario . '/archive');
    foreach ([
        new Kiwi_Landing_Page_Session_Repository(), new Kiwi_Landing_Session_Engagement_Repository(),
        new Kiwi_Landing_Handoff_Event_Repository(), new Kiwi_Sales_Repository(),
        new Kiwi_Landing_Funnel_Daily_Summary_Repository(), new Kiwi_Landing_Funnel_Daily_Tkzone_Summary_Repository($config),
        new Kiwi_Retention_Cleanup_Run_Repository(), new Kiwi_Retention_Table_Growth_Snapshot_Repository(),
        new Kiwi_Operational_Event_Repository(),
    ] as $repository) { $repository->create_table(); }

    foreach (metric_dates() as $i => $date) {
        foreach (['106', '207'] as $pid) {
            $context = ['landing_key' => 'fixture-landing', 'service_key' => 'fixture-service',
                'provider_key' => 'fixture-aggregator', 'flow_key' => 'click', 'pid' => $pid,
                'tksource' => 'fixture-source', 'tkzone' => 'zone-' . $pid, 'session_token' => 'session-' . $i . '-' . $pid];
            sandbox_insert(SESSIONS, $context + ['created_at' => $date . ' 10:00:00', 'country' => 'DE', 'raw_context' => '{}']);
            if ($pid === '106') { // repeated raw page load, still one canonical session
                sandbox_insert(SESSIONS, $context + ['created_at' => $date . ' 10:00:01', 'country' => 'DE', 'raw_context' => '{}']);
            }
            $engagement = $context;
            unset($engagement['country']);
            sandbox_insert(ENGAGEMENTS, $engagement + [
                'created_at' => $date . ' 10:00:00', 'updated_at' => $date . ' 10:01:00',
                'last_event_at' => $date . ' 10:01:00', 'page_loaded_at' => $date . ' 10:00:00',
                'first_cta1_click_at' => $date . ' 10:01:00',
                'cta1_click_count' => $i === 0 && $pid === '106' ? 5475 : 1,
            ]);
            sandbox_insert(HANDOFFS, $context + ['created_at' => $date . ' 11:00:00',
                'handoff_id' => 'handoff-' . $i . '-' . $pid, 'event_type' => 'sms_handoff_attempted']);
            sandbox_insert(HANDOFFS, $context + ['created_at' => $date . ' 11:00:02',
                'handoff_id' => 'handoff-' . $i . '-' . $pid,
                'event_type' => $pid === '106' ? 'sms_handoff_hidden' : 'sms_handoff_no_hide', 'elapsed_ms' => 1250]);
        }
    }
    // The last metric day's late events must remain in the archive-read window.
    $late = ['landing_key' => 'fixture-landing', 'service_key' => 'fixture-service',
        'provider_key' => 'fixture-aggregator', 'flow_key' => 'click', 'pid' => '106',
        'tksource' => 'fixture-source', 'tkzone' => 'zone-106', 'session_token' => 'session-7-106', 'handoff_id' => 'late-handoff'];
    sandbox_insert(HANDOFFS, $late + ['created_at' => '2026-09-07 00:03:00', 'event_type' => 'sms_handoff_attempted']);
    sandbox_insert(HANDOFFS, $late + ['created_at' => '2026-09-07 00:03:02', 'event_type' => 'sms_handoff_hidden', 'elapsed_ms' => 2500]);
    sandbox_insert(SESSIONS, ['created_at' => '2026-09-07 10:00:00', 'landing_key' => 'newer-landing', 'session_token' => 'newer-session', 'pid' => '106']);
    sandbox_insert('wp_kiwi_sales', ['created_at' => '2026-08-30 13:00:00', 'updated_at' => '2026-08-30 13:00:00',
        'sale_reference' => 'fixture-sale', 'status' => 'completed', 'amount_minor' => 450, 'pid' => '106',
        'attribution_metric_date' => '2026-08-30', 'landing_key' => 'fixture-landing', 'service_key' => 'fixture-service',
        'provider_key' => 'fixture-aggregator', 'flow_key' => 'click', 'country' => 'DE', 'tksource' => 'fixture-source', 'tkzone' => 'zone-106']);
    refresh_candidates($config);
    foreach ([MAIN_TABLE, TK_TABLE] as $table) {
        foreach (['2026-08-29', '2026-09-07'] as $date) {
            sandbox_insert($table, ['metric_date' => $date, 'dimension_hash' => hash('sha256', $date),
                'sessions' => 99, 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00']);
        }
    }
    sandbox_sql("UPDATE " . ENGAGEMENTS . " SET cta1_click_count = 5483, updated_at = '2026-09-13 00:00:00' WHERE session_token = 'session-0-106'");

    $registry = new Kiwi_Retention_Source_Registry();
    $handoff_source = $registry->get('landing_handoff_events');
    $cleanup = new Kiwi_Retention_Cleanup_Service($config);
    if ($scenario === 'multiple-generations') { $config->handoff_days = 17; }
    $started = $cleanup->run_source('landing_handoff_events', 'manual');
    sandbox_assert($started['status'] === 'pending', 'Handoff fixture must start normally: ' . json_encode($started));
    for ($i = 0; $i < 20; $i++) {
        $worker = $cleanup->run_worker('landing_handoff_events');
        sandbox_assert(!empty($worker['success']), 'Fixture handoff worker failed: ' . json_encode($worker));
        if ($worker['status'] === 'completed') { break; }
    }
    sandbox_assert($worker['status'] === 'completed', 'Fixture handoff retention must complete.');
    $audit = $wpdb->get_row("SELECT * FROM wp_kiwi_retention_cleanup_runs WHERE source_key = 'landing_handoff_events' ORDER BY id DESC LIMIT 1", ARRAY_A);
    if (in_array($scenario, ['multiple-generations', 'generation-overlap'], true)) {
        if ($scenario === 'generation-overlap') {
            $reader = new PDO('sqlite:' . $audit['archive_db_path']);
            $row = $reader->query('SELECT * FROM wp_kiwi_landing_handoff_events WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            foreach (['_source_pk', '_archive_batch_id', '_archived_at'] as $column) { unset($row[$column]); }
            sandbox_insert(HANDOFFS, $row);
            $reader = null;
        }
        $next_path = dirname($audit['archive_db_path']) . '/' . Kiwi_Retention_Archive_Name::build('2026', 2);
        $empty = new PDO('sqlite:' . $next_path);
        $empty = null;
        $config->handoff_days = 13;
        $second = $cleanup->run_source('landing_handoff_events', 'manual');
        sandbox_assert($second['status'] === 'pending', 'Second-generation fixture did not start.');
        for ($i = 0; $i < 20; $i++) {
            $worker = $cleanup->run_worker('landing_handoff_events');
            sandbox_assert(!empty($worker['success']), 'Second-generation fixture worker failed.');
            if ($worker['status'] === 'completed') { break; }
        }
        sandbox_assert($worker['status'] === 'completed', 'Second generation must complete.');
        $audit = $wpdb->get_row("SELECT * FROM wp_kiwi_retention_cleanup_runs WHERE source_key = 'landing_handoff_events' ORDER BY id DESC LIMIT 1", ARRAY_A);
    }

    return ['config' => $config, 'source' => $registry->get('landing_page_sessions'),
        'handoff_source' => $handoff_source, 'archive' => $audit['archive_db_path']];
}
