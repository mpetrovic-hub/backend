<?php

require_once dirname(__DIR__)
    . DIRECTORY_SEPARATOR
    . 'tools'
    . DIRECTORY_SEPARATOR
    . 'database'
    . DIRECTORY_SEPARATOR
    . 'class-retention-archive-health-bootstrap-recorder.php';

class Kiwi_Test_Retention_Archive_Health_Config extends Kiwi_Config
{
    private $archive_root;
    private $timeout_seconds;

    public function __construct(string $archive_root, int $timeout_seconds = 600)
    {
        $this->archive_root = $archive_root;
        $this->timeout_seconds = $timeout_seconds;
    }

    public function get_retention_archive_root(): string
    {
        return $this->archive_root;
    }

    public function get_retention_archive_health_timeout_seconds(): int
    {
        return $this->timeout_seconds;
    }
}

class Kiwi_Test_Lock_Observed_Retention_Sqlite_Archive_Service extends Kiwi_Retention_Sqlite_Archive_Service
{
    public $quarantine_lock_held = false;

    public function mark_quarantined(string $archive_db_path, array $details): bool
    {
        $locks = new Kiwi_Retention_Archive_Lock();
        $probe = $locks->acquire_for_archive($archive_db_path);
        $this->quarantine_lock_held = !empty($probe['success']) && empty($probe['acquired']);
        if (!empty($probe['acquired'])) {
            $locks->release($probe['handle'] ?? null);
        }

        return parent::mark_quarantined($archive_db_path, $details);
    }
}

class Kiwi_Test_Failing_Quarantine_Retention_Sqlite_Archive_Service extends Kiwi_Retention_Sqlite_Archive_Service
{
    public function mark_quarantined(string $archive_db_path, array $details): bool
    {
        return false;
    }
}

class Kiwi_Test_One_Failure_Quarantine_Retention_Sqlite_Archive_Service extends Kiwi_Retention_Sqlite_Archive_Service
{
    public $quarantine_attempts = 0;

    public function mark_quarantined(string $archive_db_path, array $details): bool
    {
        $this->quarantine_attempts++;
        if ($this->quarantine_attempts === 1) {
            return false;
        }

        return parent::mark_quarantined($archive_db_path, $details);
    }
}

class Kiwi_Test_Wpdb_Retention_Quarantine_Transition
{
    public $prefix = 'wp_';
    public $rows = [];
    public $insert_id = 0;
    public $last_error = '';

    public function prepare($query, ...$args): array
    {
        return ['query' => (string) $query, 'args' => $args];
    }

    public function query($query)
    {
        return true;
    }

    public function get_row($statement, $output = null)
    {
        $query = is_array($statement) ? (string) ($statement['query'] ?? '') : (string) $statement;
        $args = is_array($statement) ? (array) ($statement['args'] ?? []) : [];
        if (strpos($query, 'WHERE id = %d') !== false) {
            return $this->rows[(int) ($args[0] ?? 0)] ?? null;
        }
        if (strpos($query, 'WHERE run_id = %s') !== false) {
            foreach ($this->rows as $row) {
                if ((string) ($row['run_id'] ?? '') === (string) ($args[0] ?? '')) {
                    return $row;
                }
            }
        }

        return null;
    }

    public function insert($table, array $row, $formats = null)
    {
        $this->insert_id = empty($this->rows) ? 1 : max(array_keys($this->rows)) + 1;
        $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $row);

        return 1;
    }

    public function update($table, array $row, array $where, $formats = null, $where_formats = null)
    {
        $id = (int) ($where['id'] ?? 0);
        if (!isset($this->rows[$id])) {
            return false;
        }
        $this->rows[$id] = array_merge($this->rows[$id], $row);

        return 1;
    }
}

class Kiwi_Test_Wpdb_Open_Archive_Lookup
{
    public $prefix = 'wp_';
    public $last_error = '';
    public $rows = [];
    public $last_query = '';
    public $last_args = [];

    public function prepare($query, ...$args): array
    {
        return [
            'query' => (string) $query,
            'args' => $args,
        ];
    }

    public function get_results($statement, $output = null)
    {
        $this->last_query = is_array($statement)
            ? (string) ($statement['query'] ?? '')
            : (string) $statement;
        $this->last_args = is_array($statement)
            ? (array) ($statement['args'] ?? [])
            : [];

        return $this->rows;
    }

    public function get_row($statement, $output = null)
    {
        $this->get_results($statement, $output);

        return null;
    }
}

class Kiwi_Test_Flaky_Operational_Event_Repository extends Kiwi_Test_Operational_Event_Repository
{
    public $fail_next_insert = false;
    public $fail_next_insert_event_type = '';
    public $fail_next_latest_lookup = false;

    public function insert_event(array $event): int
    {
        if ($this->fail_next_insert
            || (
                $this->fail_next_insert_event_type !== ''
                && (string) ($event['event_type'] ?? '') === $this->fail_next_insert_event_type
            )
        ) {
            $this->fail_next_insert = false;
            $this->fail_next_insert_event_type = '';

            return 0;
        }

        return parent::insert_event($event);
    }

    public function find_latest_by_correlation_key(string $correlation_key): ?array
    {
        if ($this->fail_next_latest_lookup) {
            $this->fail_next_latest_lookup = false;

            throw new RuntimeException('Synthetic operational event lookup failure.');
        }

        return parent::find_latest_by_correlation_key($correlation_key);
    }
}

class Kiwi_Test_Failing_Open_Archive_Run_Repository extends Kiwi_Test_Retention_Cleanup_Run_Repository
{
    public function find_open_archive_state(): ?array
    {
        return null;
    }
}

class Kiwi_Test_Controllable_Open_Archive_Run_Repository extends Kiwi_Test_Retention_Cleanup_Run_Repository
{
    public $fail_lookup = true;

    public function find_open_archive_state(): ?array
    {
        return $this->fail_lookup ? null : parent::find_open_archive_state();
    }
}

class Kiwi_Test_Failing_Archive_Discovery_Service extends Kiwi_Retention_Sqlite_Archive_Service
{
    public function list_archive_files(): array
    {
        throw new Kiwi_Retention_Archive_Discovery_Exception('archive_discovery_failed');
    }
}

class Kiwi_Test_Chained_Predecessor_Retention_Sqlite_Archive_Service extends Kiwi_Test_Retention_Sqlite_Archive_Service
{
    public $predecessors_by_archive = [];

    public function find_quarantined_predecessor(string $successor_archive_db_path): ?array
    {
        $successor = basename($successor_archive_db_path);

        return array_key_exists($successor, $this->predecessors_by_archive)
            && is_array($this->predecessors_by_archive[$successor])
                ? $this->predecessors_by_archive[$successor]
                : null;
    }
}

function kiwi_test_create_retention_archive(
    Kiwi_Retention_Sqlite_Archive_Service $archive_service,
    string $name
): string {
    $directory = $archive_service->get_archive_directory();
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create retention archive test directory.');
    }

    $path = $directory . DIRECTORY_SEPARATOR . $name;
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        if (file_put_contents($path, 'sqlite-test-placeholder') === false) {
            throw new RuntimeException('Unable to create retention archive placeholder.');
        }

        return $path;
    }

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE receipt_probe (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $pdo->exec("INSERT INTO receipt_probe (value) VALUES ('ok')");

    return $path;
}

function kiwi_test_health_service(
    string $archive_root,
    DateTimeImmutable $now,
    callable $runner,
    ?Kiwi_Test_Operational_Event_Repository $events = null,
    ?Kiwi_Test_Retention_Cleanup_Run_Repository $runs = null
): array {
    $config = new Kiwi_Test_Retention_Archive_Health_Config($archive_root);
    $archive_service = new Kiwi_Retention_Sqlite_Archive_Service($config);
    $events = $events ?? new Kiwi_Test_Operational_Event_Repository();
    $runs = $runs ?? new Kiwi_Test_Retention_Cleanup_Run_Repository();
    $service = new Kiwi_Retention_Archive_Health_Service(
        $config,
        $archive_service,
        new Kiwi_Retention_Archive_Lock(),
        new Kiwi_Operational_Event_Service($events),
        static function () use ($now): DateTimeImmutable {
            return $now;
        },
        $runner,
        '',
        $runs
    );

    return [$service, $archive_service, $events, $runs];
}

kiwi_run_test('Kiwi_Config exposes bounded retention archive health timeout', function (): void {
    $config = new Kiwi_Config();

    kiwi_assert_same(
        600,
        $config->get_retention_archive_health_timeout_seconds(),
        'Expected ten-minute external archive-health child timeout by default.'
    );
});

kiwi_run_test('Kiwi_Retention_Archive_Lock supports shared health readers and exclusive writers', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_archive_lock');
    $config = new Kiwi_Test_Retention_Archive_Health_Config($root);
    $archive_service = new Kiwi_Retention_Sqlite_Archive_Service($config);
    $path = kiwi_test_create_retention_archive(
        $archive_service,
        'kiwi_retention_archive_2026.sqlite'
    );
    $locks = new Kiwi_Retention_Archive_Lock();

    try {
        $first_reader = $locks->acquire_shared_for_archive($path);
        $second_reader = $locks->acquire_shared_for_archive($path);
        $blocked_writer = $locks->acquire_for_archive($path);
        kiwi_assert_true(!empty($first_reader['success']) && !empty($first_reader['acquired']), 'Expected first shared health lock acquisition.');
        kiwi_assert_true(!empty($second_reader['success']) && !empty($second_reader['acquired']), 'Expected concurrent shared health lock acquisition.');
        kiwi_assert_true(!empty($blocked_writer['success']) && empty($blocked_writer['acquired']), 'Expected exclusive writer to defer while health readers are active.');
        kiwi_assert_true(
            ($first_reader['handle'] ?? null) instanceof Kiwi_Retention_Archive_Lock_Handle
                && $first_reader['handle']->persist_write_blocked(),
            'Expected a health reader to persist the corruption write block while another diagnostic reader is active.'
        );

        $locks->release($first_reader['handle'] ?? null);
        $still_blocked_writer = $locks->acquire_for_archive($path);
        kiwi_assert_true(!empty($still_blocked_writer['success']) && empty($still_blocked_writer['acquired']), 'Expected writer to remain deferred until every shared health lock is released.');

        $locks->release($second_reader['handle'] ?? null);
        $writer = $locks->acquire_for_archive($path);
        $second_writer = $locks->acquire_for_archive($path);
        kiwi_assert_true(!empty($writer['success']) && !empty($writer['acquired']), 'Expected writer acquisition after shared health locks are released.');
        kiwi_assert_true(!empty($second_writer['success']) && empty($second_writer['acquired']), 'Expected concurrent exclusive writer to defer without blocking.');
        kiwi_assert_true(
            ($writer['handle'] ?? null) instanceof Kiwi_Retention_Archive_Lock_Handle
                && $writer['handle']->is_write_blocked(),
            'Expected a later writer to observe the persisted corruption write block.'
        );
        $locks->release($writer['handle'] ?? null);
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Retention archive validators accept every successor generation of two or greater', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_archive_generation_names');
    $config = new Kiwi_Test_Retention_Archive_Health_Config($root);
    $archive_service = new Kiwi_Retention_Sqlite_Archive_Service($config);
    $locks = new Kiwi_Retention_Archive_Lock();
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    $health_service = new Kiwi_Retention_Archive_Health_Service(
        $config,
        $archive_service,
        $locks,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        static function () use ($now): DateTimeImmutable {
            return $now;
        },
        static function (): array {
            return [
                'result' => 'ok',
                'reason_code' => 'sqlite_check_ok',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        }
    );

    try {
        $valid_generations = [2, 9, 10, 19, 20, 1000000];
        foreach ($valid_generations as $generation) {
            $name = 'kiwi_retention_archive_2026_part_' . $generation . '.sqlite';
            $path = kiwi_test_create_retention_archive($archive_service, $name);
            $lock = $locks->acquire_for_archive($path);
            kiwi_assert_true(
                !empty($lock['success']) && !empty($lock['acquired']),
                'Expected lock validator to accept generation ' . $generation . '.'
            );
            $locks->release($lock['handle'] ?? null);
            kiwi_assert_same('ok', $health_service->diagnose($name, 'quick')['result'] ?? '', 'Expected health validator to accept generation ' . $generation . '.');
        }

        foreach ([1, '01', '02'] as $generation) {
            $name = 'kiwi_retention_archive_2026_part_' . $generation . '.sqlite';
            $path = kiwi_test_create_retention_archive($archive_service, $name);
            $lock = $locks->acquire_for_archive($path);
            kiwi_assert_same(false, $lock['success'] ?? true, 'Expected invalid generation rejection for ' . $generation . '.');
            kiwi_assert_same('error', $health_service->diagnose($name, 'quick')['result'] ?? '', 'Expected health validator rejection for ' . $generation . '.');
        }

        $discovered_generations = array_column($archive_service->list_archive_files(), 'generation');
        kiwi_assert_same(
            $valid_generations,
            $discovered_generations,
            'Expected archive discovery to retain all and only valid successor generations.'
        );

        $base_archive = kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2026.sqlite'
        );
        kiwi_assert_true(
            $archive_service->mark_quarantined($base_archive, [
                'detected_at' => '2026-07-27T01:35:00+02:00',
                'check' => 'quick',
                'reason_code' => 'sqlite_check_reported_corruption',
                'active_generation' => true,
            ]),
            'Expected quarantined predecessor fixture.'
        );
        kiwi_assert_same(false, $archive_service->is_quarantine_reconciled($base_archive), 'Expected fresh marker to block recovery.');
        kiwi_assert_true(
            $archive_service->mark_quarantine_reconciled(
                $base_archive,
                '2026-07-27T01:36:00+02:00'
            ),
            'Expected marker acknowledgement after Incident persistence.'
        );
        kiwi_assert_same(true, $archive_service->is_quarantine_reconciled($base_archive), 'Expected acknowledged marker to permit recovery.');
        $predecessor = $archive_service->find_quarantined_predecessor(
            $archive_service->get_archive_directory()
            . DIRECTORY_SEPARATOR
            . 'kiwi_retention_archive_2026_part_2.sqlite'
        );
        kiwi_assert_same(
            'kiwi_retention_archive_2026.sqlite',
            $predecessor['name'] ?? '',
            'Expected exact prior quarantined generation for a replacement archive.'
        );
        $prior_year_archive = kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2025.sqlite'
        );
        kiwi_assert_true(
            $archive_service->mark_quarantined($prior_year_archive, [
                'detected_at' => '2026-01-01T00:05:00+01:00',
                'check' => 'integrity',
                'reason_code' => 'sqlite_check_reported_corruption',
                'active_generation' => true,
            ]),
            'Expected prior-year quarantine fixture.'
        );
        kiwi_assert_same(
            'kiwi_retention_archive_2025_part_2.sqlite',
            basename($archive_service->resolve_quarantine_successor_path($prior_year_archive)),
            'Expected quarantine recovery to stay in the quarantined archive year.'
        );
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Cleanup_Service resolves active corruption only after successor receipt delete audit', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = (object) ['prefix' => 'wp_'];
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_deleted_transients'] = [];
    $old_archive = 'kiwi_retention_archive_2026.sqlite';
    $test_root = kiwi_create_temp_directory('kiwi_retention_recovery_resolution');
    $new_archive_path = $test_root
        . DIRECTORY_SEPARATOR
        . 'kiwi_retention_archive_2026_part_2.sqlite';
    $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
    $runs->rows[1] = [
        'id' => 1,
        'run_id' => 'retention_recovery_test',
        'source_key' => 'landing_page_sessions',
        'source_table' => 'wp_kiwi_landing_page_sessions',
        'status' => 'running',
        'triggered_by' => 'archive_recovery',
        'enabled' => 1,
        'dry_run' => 0,
        'retention_days_effective' => 14,
        'cutoff_value' => '2026-07-01 00:00:00',
        'eligible_rows' => 2,
        'archived_rows' => 2,
        'archive_inserted_rows' => 2,
        'archive_duplicate_rows' => 0,
        'deleted_rows' => 0,
        'delete_batches' => 0,
        'gate_status' => 'passed',
        'worker_phase' => 'receipt_verified',
        'target_max_primary_key' => 2,
        'archive_last_primary_key' => 2,
        'delete_last_primary_key' => 0,
        'worker_runs' => 0,
        'archive_batch_id' => 'archive_recovery_batch_test',
        'archive_db_path' => $new_archive_path,
        'archive_integrity_check' => 'receipt_verified',
        'error_code' => 'archive_recovery_pending',
        'error_message' => json_encode([
            'old_run_id' => 'retention_original_test',
            'old_archive' => $old_archive,
            'new_archive' => basename($new_archive_path),
            'remaining_rows' => 2,
        ]),
        'finished_at' => null,
    ];
    $archive = new Kiwi_Test_Retention_Sqlite_Archive_Service();
    $archive->verified_receipt_batches[] = [
        'success' => true,
        'primary_keys' => [1, 2],
        'last_primary_key' => 2,
        'has_more' => false,
        'error_code' => '',
        'error_message' => '',
    ];
    $events = new Kiwi_Test_Flaky_Operational_Event_Repository();
    $event_service = new Kiwi_Operational_Event_Service($events);
    $correlation_key = 'retention_archive_corruption_' . hash('sha256', $old_archive);
    $event_service->record_failure([
        'area' => 'retention',
        'severity' => 'critical',
        'event_type' => 'retention_archive_corruption_detected',
        'correlation_key' => $correlation_key,
        'reference_type' => 'retention_archive',
        'reference_id' => $old_archive,
        'message' => 'Synthetic confirmed archive corruption.',
        'idempotency_key' => 'retention_archive_corruption_test',
    ]);
    $events->fail_next_insert = true;
    $service = new Kiwi_Test_Retention_Cleanup_Service(
        new Kiwi_Config(),
        new Kiwi_Retention_Source_Registry(),
        $runs,
        new Kiwi_Test_Retention_Table_Growth_Snapshot_Repository(),
        $archive,
        new Kiwi_Test_Retention_Coverage_Gate(['status' => 'passed']),
        $event_service
    );
    $service->existing_primary_keys = [1, 2];
    $service->delete_result = ['deleted_rows' => 2];

    try {
        $pending = $service->run_worker('landing_page_sessions');
        kiwi_assert_same(false, $pending['success'] ?? true, 'Expected mandatory transition event failure to stay visible.');
        kiwi_assert_same(
            'archive_recovery_transition_event_pending',
            $pending['error_code'] ?? '',
            'Expected explicit transition-event retry state.'
        );
        kiwi_assert_same([], $service->deleted_primary_keys, 'Expected no source delete before transition evidence persists.');
        kiwi_assert_same(true, $pending['reschedule_worker'] ?? false, 'Expected automatic transition-event retry.');

        $result = $service->run_worker('landing_page_sessions');
        $event_rows = array_values($events->rows);
        $resolved = $event_rows[count($event_rows) - 1] ?? [];
        $context = json_decode((string) ($resolved['context_json'] ?? ''), true);

        kiwi_assert_same('completed', $result['status'] ?? '', 'Expected successful successor completion.');
        kiwi_assert_same('resolved', $resolved['lifecycle_action'] ?? '', 'Expected corruption incident resolution.');
        kiwi_assert_same(
            ['raised', 'resolved', 'resolved'],
            array_column($event_rows, 'lifecycle_action'),
            'Expected closed transition evidence before corruption resolution.'
        );
        kiwi_assert_same(
            'retention_archive_recovery_transition',
            $event_rows[1]['event_type'] ?? '',
            'Expected append-only quarantine transition event.'
        );
        kiwi_assert_same(
            [],
            $events->get_open_incidents(),
            'Expected successful transition and corruption correlations to remain closed.'
        );
        kiwi_assert_same(
            'quarantined_and_replaced',
            $context['resolution_reason'] ?? '',
            'Expected qualified recovery reason after the first complete successor batch.'
        );
        kiwi_assert_same($old_archive, $context['old_archive'] ?? '', 'Expected old corrupt generation evidence.');
        kiwi_assert_same(
            basename($new_archive_path),
            $context['new_archive'] ?? '',
            'Expected replacement generation evidence.'
        );
    } finally {
        $wpdb = $previous_wpdb;
        kiwi_remove_directory($test_root);
    }
});

kiwi_run_test('Kiwi_Retention_Cleanup_Service carries empty recovery context to the first ordinary replacement batch', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = (object) ['prefix' => 'wp_'];
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_deleted_transients'] = [];
    $old_archive = 'kiwi_retention_archive_2026.sqlite';
    $test_root = kiwi_create_temp_directory('kiwi_retention_empty_recovery_carry');
    $new_archive_path = $test_root
        . DIRECTORY_SEPARATOR
        . 'kiwi_retention_archive_2026_part_2.sqlite';
    $current_archive_path = $test_root
        . DIRECTORY_SEPARATOR
        . 'kiwi_retention_archive_2027.sqlite';
    $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
    $runs->rows[1] = [
        'id' => 1,
        'run_id' => 'retention_empty_recovery',
        'source_key' => 'landing_page_sessions',
        'status' => 'completed',
        'triggered_by' => 'archive_recovery',
        'eligible_rows' => 0,
        'archive_db_path' => $new_archive_path,
        'error_message' => json_encode([
            'old_run_id' => 'retention_empty_recovery_original',
            'old_archive' => $old_archive,
            'new_archive' => basename($new_archive_path),
            'remaining_rows' => 0,
        ]),
        'finished_at' => '2026-07-29 10:00:00',
    ];
    $runs->rows[2] = [
        'id' => 2,
        'run_id' => 'retention_ordinary_replacement_batch',
        'source_key' => 'landing_page_sessions',
        'source_table' => 'wp_kiwi_landing_page_sessions',
        'status' => 'running',
        'triggered_by' => 'cron',
        'enabled' => 1,
        'dry_run' => 0,
        'retention_days_effective' => 14,
        'cutoff_value' => '2026-07-01 00:00:00',
        'eligible_rows' => 2,
        'archived_rows' => 2,
        'archive_inserted_rows' => 2,
        'archive_duplicate_rows' => 0,
        'deleted_rows' => 0,
        'delete_batches' => 0,
        'gate_status' => 'passed',
        'worker_phase' => 'receipt_verified',
        'target_max_primary_key' => 2,
        'archive_last_primary_key' => 2,
        'delete_last_primary_key' => 0,
        'worker_runs' => 0,
        'archive_batch_id' => 'ordinary_replacement_batch',
        'archive_db_path' => $current_archive_path,
        'archive_integrity_check' => 'receipt_verified',
        'error_code' => '',
        'error_message' => '',
        'finished_at' => null,
    ];
    $archive = new Kiwi_Test_Retention_Sqlite_Archive_Service();
    $archive->verified_receipt_batches[] = [
        'success' => true,
        'primary_keys' => [1, 2],
        'last_primary_key' => 2,
        'has_more' => false,
        'error_code' => '',
        'error_message' => '',
    ];
    $events = new Kiwi_Test_Operational_Event_Repository();
    $event_service = new Kiwi_Operational_Event_Service($events);
    $correlation_key = 'retention_archive_corruption_' . hash('sha256', $old_archive);
    $event_service->record_failure([
        'area' => 'retention',
        'severity' => 'critical',
        'event_type' => 'retention_archive_corruption_detected',
        'correlation_key' => $correlation_key,
        'reference_type' => 'retention_archive',
        'reference_id' => $old_archive,
        'message' => 'Synthetic confirmed archive corruption.',
        'idempotency_key' => 'retention_archive_empty_recovery_test',
    ]);
    $service = new Kiwi_Test_Retention_Cleanup_Service(
        new Kiwi_Config(),
        new Kiwi_Retention_Source_Registry(),
        $runs,
        new Kiwi_Test_Retention_Table_Growth_Snapshot_Repository(),
        $archive,
        new Kiwi_Test_Retention_Coverage_Gate(['status' => 'passed']),
        $event_service
    );
    $service->existing_primary_keys = [1, 2];
    $service->delete_result = ['deleted_rows' => 2];

    try {
        $result = $service->run_worker('landing_page_sessions');
        $corruption_events = array_values(array_filter(
            $events->rows,
            static function (array $event) use ($correlation_key): bool {
                return (string) ($event['correlation_key'] ?? '') === $correlation_key;
            }
        ));
        $resolved = $corruption_events[count($corruption_events) - 1] ?? [];
        $context = json_decode((string) ($resolved['context_json'] ?? ''), true);

        kiwi_assert_same('completed', $result['status'] ?? '', 'Expected the ordinary replacement run to complete.');
        kiwi_assert_same('resolved', $resolved['lifecycle_action'] ?? '', 'Expected carried corruption incident resolution.');
        kiwi_assert_same(
            'retention_empty_recovery',
            $context['successor_run_id'] ?? '',
            'Expected the empty deterministic successor to remain recovery evidence.'
        );
        kiwi_assert_same(
            'retention_ordinary_replacement_batch',
            $context['qualifying_run_id'] ?? '',
            'Expected the first non-empty ordinary replacement batch as qualifying evidence.'
        );
        kiwi_assert_same(
            basename($current_archive_path),
            $context['new_archive'] ?? '',
            'Expected the qualifying new-year archive in recovery evidence.'
        );
        kiwi_assert_same(
            'archive_recovery_resolved',
            $runs->rows[1]['error_code'] ?? '',
            'Expected durable resolution of the carried prior-year context.'
        );
        kiwi_assert_same([], $events->get_open_incidents(), 'Expected no open corruption incident after the qualifying batch.');
    } finally {
        $wpdb = $previous_wpdb;
        kiwi_remove_directory($test_root);
    }
});

kiwi_run_test('Kiwi_Retention_Cleanup_Service resolves prior-year quarantine without a recovery run', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = (object) ['prefix' => 'wp_'];
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_deleted_transients'] = [];
    $old_archive = 'kiwi_retention_archive_2026.sqlite';
    $test_root = kiwi_create_temp_directory('kiwi_retention_predecessor_recovery');
    $old_archive_path = $test_root . DIRECTORY_SEPARATOR . $old_archive;
    $new_archive_path = $test_root
        . DIRECTORY_SEPARATOR
        . 'kiwi_retention_archive_2027.sqlite';
    $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
    $runs->rows[1] = [
        'id' => 1,
        'run_id' => 'retention_first_run_after_idle_quarantine',
        'source_key' => 'landing_page_sessions',
        'source_table' => 'wp_kiwi_landing_page_sessions',
        'status' => 'running',
        'triggered_by' => 'cron',
        'enabled' => 1,
        'dry_run' => 0,
        'retention_days_effective' => 14,
        'cutoff_value' => '2026-07-01 00:00:00',
        'eligible_rows' => 2,
        'archived_rows' => 2,
        'archive_inserted_rows' => 2,
        'archive_duplicate_rows' => 0,
        'deleted_rows' => 0,
        'delete_batches' => 0,
        'gate_status' => 'passed',
        'worker_phase' => 'receipt_verified',
        'target_max_primary_key' => 2,
        'archive_last_primary_key' => 2,
        'delete_last_primary_key' => 0,
        'worker_runs' => 0,
        'archive_batch_id' => 'first_batch_after_idle_quarantine',
        'archive_db_path' => $new_archive_path,
        'archive_integrity_check' => 'receipt_verified',
        'error_code' => '',
        'error_message' => '',
        'finished_at' => null,
    ];
    $archive = new Kiwi_Test_Retention_Sqlite_Archive_Service();
    $archive->archive_files = [[
        'name' => $old_archive,
        'path' => $old_archive_path,
        'quarantined' => true,
    ]];
    $archive->verified_receipt_batches[] = [
        'success' => true,
        'primary_keys' => [1, 2],
        'last_primary_key' => 2,
        'has_more' => false,
        'error_code' => '',
        'error_message' => '',
    ];
    $events = new Kiwi_Test_Operational_Event_Repository();
    $event_service = new Kiwi_Operational_Event_Service($events);
    $correlation_key = 'retention_archive_corruption_' . hash('sha256', $old_archive);
    $event_service->record_failure([
        'area' => 'retention',
        'severity' => 'critical',
        'event_type' => 'retention_archive_corruption_detected',
        'correlation_key' => $correlation_key,
        'reference_type' => 'retention_archive',
        'reference_id' => $old_archive,
        'message' => 'Synthetic idle-period archive corruption.',
        'idempotency_key' => 'retention_archive_idle_quarantine_test',
    ]);
    $service = new Kiwi_Test_Retention_Cleanup_Service(
        new Kiwi_Config(),
        new Kiwi_Retention_Source_Registry(),
        $runs,
        new Kiwi_Test_Retention_Table_Growth_Snapshot_Repository(),
        $archive,
        new Kiwi_Test_Retention_Coverage_Gate(['status' => 'passed']),
        $event_service
    );
    $service->existing_primary_keys = [1, 2];
    $service->delete_result = ['deleted_rows' => 2];

    try {
        $result = $service->run_worker('landing_page_sessions');
        $latest = $events->find_latest_by_correlation_key($correlation_key);
        $context = json_decode((string) ($latest['context_json'] ?? ''), true);

        kiwi_assert_same('completed', $result['status'] ?? '', 'Expected the first ordinary replacement run to complete.');
        kiwi_assert_same('resolved', $latest['lifecycle_action'] ?? '', 'Expected quarantined predecessor incident resolution.');
        kiwi_assert_same($old_archive, $context['old_archive'] ?? '', 'Expected predecessor archive evidence.');
        kiwi_assert_same(
            basename($new_archive_path),
            $context['new_archive'] ?? '',
            'Expected replacement archive evidence.'
        );
        kiwi_assert_same(
            'retention_first_run_after_idle_quarantine',
            $context['successor_run_id'] ?? '',
            'Expected the first ordinary replacement run as successor evidence.'
        );
        kiwi_assert_same(
            'retention_first_run_after_idle_quarantine',
            $context['qualifying_run_id'] ?? '',
            'Expected the same non-empty run as qualifying evidence.'
        );
        kiwi_assert_same([], $events->get_open_incidents(), 'Expected no open corruption incident after replacement progress.');
    } finally {
        $wpdb = $previous_wpdb;
        kiwi_remove_directory($test_root);
    }
});

kiwi_run_test('Kiwi_Retention_Cleanup_Service carries recovery through repeated quarantine generations', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = (object) ['prefix' => 'wp_'];
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_deleted_transients'] = [];
    $test_root = kiwi_create_temp_directory('kiwi_retention_chained_recovery');
    $archive_a = 'kiwi_retention_archive_2026.sqlite';
    $archive_b = 'kiwi_retention_archive_2026_part_2.sqlite';
    $archive_c = 'kiwi_retention_archive_2026_part_3.sqlite';
    $archive_b_path = $test_root . DIRECTORY_SEPARATOR . $archive_b;
    $archive_c_path = $test_root . DIRECTORY_SEPARATOR . $archive_c;
    $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
    $runs->rows[1] = [
        'id' => 1,
        'run_id' => 'retention_empty_a_to_b',
        'source_key' => 'landing_page_sessions',
        'status' => 'completed',
        'triggered_by' => 'archive_recovery',
        'eligible_rows' => 0,
        'archive_db_path' => $archive_b_path,
        'error_message' => json_encode([
            'old_run_id' => 'retention_quarantined_a',
            'old_archive' => $archive_a,
            'new_archive' => $archive_b,
            'remaining_rows' => 0,
        ]),
        'finished_at' => '2026-07-29 10:00:00',
    ];
    $runs->rows[2] = [
        'id' => 2,
        'run_id' => 'retention_first_batch_on_c',
        'source_key' => 'landing_page_sessions',
        'source_table' => 'wp_kiwi_landing_page_sessions',
        'status' => 'running',
        'triggered_by' => 'cron',
        'enabled' => 1,
        'dry_run' => 0,
        'retention_days_effective' => 14,
        'cutoff_value' => '2026-07-01 00:00:00',
        'eligible_rows' => 2,
        'archived_rows' => 2,
        'archive_inserted_rows' => 2,
        'archive_duplicate_rows' => 0,
        'deleted_rows' => 0,
        'delete_batches' => 0,
        'gate_status' => 'passed',
        'worker_phase' => 'receipt_verified',
        'target_max_primary_key' => 2,
        'archive_last_primary_key' => 2,
        'delete_last_primary_key' => 0,
        'worker_runs' => 0,
        'archive_batch_id' => 'first_batch_on_c',
        'archive_db_path' => $archive_c_path,
        'archive_integrity_check' => 'receipt_verified',
        'error_code' => '',
        'error_message' => '',
        'finished_at' => null,
    ];
    $archive = new Kiwi_Test_Chained_Predecessor_Retention_Sqlite_Archive_Service();
    $archive->predecessors_by_archive = [
        $archive_c => [
            'name' => $archive_b,
            'path' => $archive_b_path,
        ],
        $archive_b => [
            'name' => $archive_a,
            'path' => $test_root . DIRECTORY_SEPARATOR . $archive_a,
        ],
    ];
    $archive->verified_receipt_batches[] = [
        'success' => true,
        'primary_keys' => [1, 2],
        'last_primary_key' => 2,
        'has_more' => false,
        'error_code' => '',
        'error_message' => '',
    ];
    $events = new Kiwi_Test_Operational_Event_Repository();
    $event_service = new Kiwi_Operational_Event_Service($events);
    foreach ([$archive_a, $archive_b] as $quarantined_archive) {
        $event_service->record_failure([
            'area' => 'retention',
            'severity' => 'critical',
            'event_type' => 'retention_archive_corruption_detected',
            'correlation_key' => 'retention_archive_corruption_' . hash('sha256', $quarantined_archive),
            'reference_type' => 'retention_archive',
            'reference_id' => $quarantined_archive,
            'message' => 'Synthetic chained archive corruption.',
            'idempotency_key' => 'retention_archive_chained_' . hash('sha256', $quarantined_archive),
        ]);
    }
    $service = new Kiwi_Test_Retention_Cleanup_Service(
        new Kiwi_Config(),
        new Kiwi_Retention_Source_Registry(),
        $runs,
        new Kiwi_Test_Retention_Table_Growth_Snapshot_Repository(),
        $archive,
        new Kiwi_Test_Retention_Coverage_Gate(['status' => 'passed']),
        $event_service
    );
    $service->existing_primary_keys = [1, 2];
    $service->delete_result = ['deleted_rows' => 2];

    try {
        $result = $service->run_worker('landing_page_sessions');
        kiwi_assert_same('completed', $result['status'] ?? '', 'Expected the batch on generation C to complete.');
        foreach ([$archive_a, $archive_b] as $quarantined_archive) {
            $latest = $events->find_latest_by_correlation_key(
                'retention_archive_corruption_' . hash('sha256', $quarantined_archive)
            );
            $context = json_decode((string) ($latest['context_json'] ?? ''), true);
            kiwi_assert_same('resolved', $latest['lifecycle_action'] ?? '', 'Expected every carried corruption incident to resolve.');
            kiwi_assert_same($archive_c, $context['new_archive'] ?? '', 'Expected generation C as qualifying replacement.');
        }
        kiwi_assert_same([], $events->get_open_incidents(), 'Expected no corruption incident to remain open.');
    } finally {
        $wpdb = $previous_wpdb;
        kiwi_remove_directory($test_root);
    }
});

kiwi_run_test('Kiwi_Retention_Cleanup_Service rechecks quarantine after archive lock before any write', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = (object) ['prefix' => 'wp_'];
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_deleted_transients'] = [];
    $test_root = kiwi_create_temp_directory('kiwi_retention_quarantine_race');
    $old_archive_path = $test_root
        . DIRECTORY_SEPARATOR
        . 'kiwi_retention_archive_2026.sqlite';
    $new_archive_path = $test_root
        . DIRECTORY_SEPARATOR
        . 'kiwi_retention_archive_2026_part_2.sqlite';
    $context = [
        'old_run_id' => 'quarantine_race_original',
        'old_archive' => basename($old_archive_path),
        'new_archive' => basename($new_archive_path),
        'remaining_rows' => 2,
    ];
    $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
    $runs->rows[1] = [
        'id' => 1,
        'run_id' => 'quarantine_race_original',
        'source_key' => 'landing_page_sessions',
        'source_table' => 'wp_kiwi_landing_page_sessions',
        'status' => 'running',
        'triggered_by' => 'cron',
        'enabled' => 1,
        'dry_run' => 0,
        'retention_days_effective' => 14,
        'cutoff_value' => '2026-07-01 00:00:00',
        'eligible_rows' => 2,
        'archived_rows' => 0,
        'archive_inserted_rows' => 0,
        'archive_duplicate_rows' => 0,
        'deleted_rows' => 0,
        'delete_batches' => 0,
        'gate_status' => 'passed',
        'worker_phase' => 'archive_pending',
        'target_max_primary_key' => 2,
        'archive_last_primary_key' => 0,
        'delete_last_primary_key' => 0,
        'worker_runs' => 0,
        'archive_batch_id' => 'quarantine_race_batch',
        'archive_db_path' => $old_archive_path,
        'archive_integrity_check' => 'external_health_pending',
        'error_code' => '',
        'error_message' => '',
        'finished_at' => null,
    ];
    $successor = [
        'id' => 2,
        'run_id' => 'quarantine_race_successor',
        'triggered_by' => 'archive_recovery',
        'archive_db_path' => $new_archive_path,
        'error_code' => 'archive_recovery_pending',
        'error_message' => json_encode($context),
    ];
    $archive = new Kiwi_Test_Retention_Sqlite_Archive_Service();
    $archive->new_archive_db_path = $new_archive_path;
    $archive->quarantine_results = [true, true, true];
    $events = new Kiwi_Test_Operational_Event_Repository();
    $lock_service = new Kiwi_Retention_Archive_Lock();
    $service = new Kiwi_Test_Retention_Cleanup_Service(
        new Kiwi_Config(),
        new Kiwi_Retention_Source_Registry(),
        $runs,
        new Kiwi_Test_Retention_Table_Growth_Snapshot_Repository(),
        $archive,
        new Kiwi_Test_Retention_Coverage_Gate(['status' => 'passed']),
        new Kiwi_Operational_Event_Service($events),
        $lock_service
    );
    $service->eligible_rows = 2;
    $service->remaining_row_count_failures = 1;

    try {
        $retry = $service->run_worker('landing_page_sessions');
        kiwi_assert_same(false, $retry['success'] ?? true, 'Expected count failure to stay visible.');
        kiwi_assert_same(true, $retry['reschedule_worker'] ?? false, 'Expected count failure to reschedule recovery.');
        kiwi_assert_same(
            'archive_quarantine_transition_retry',
            $retry['error_code'] ?? '',
            'Expected explicit quarantine transition retry reason.'
        );
        kiwi_assert_same('running', $runs->rows[1]['status'] ?? '', 'Expected original run to remain resumable.');
        kiwi_assert_same(0, count($runs->quarantine_successor_calls), 'Expected no successor before count succeeds.');

        $transition_retry = $service->run_worker('landing_page_sessions');
        kiwi_assert_same(false, $transition_retry['success'] ?? true, 'Expected successor persistence failure to stay visible.');
        kiwi_assert_same(true, $transition_retry['reschedule_worker'] ?? false, 'Expected successor persistence failure to reschedule.');
        kiwi_assert_same(
            'archive_quarantine_transition_retry',
            $transition_retry['error_code'] ?? '',
            'Expected the same retryable quarantine transition reason.'
        );
        kiwi_assert_same('running', $runs->rows[1]['status'] ?? '', 'Expected original run to remain open after successor failure.');
        kiwi_assert_same(null, $runs->rows[1]['finished_at'] ?? null, 'Expected no terminal finish timestamp after successor failure.');
        kiwi_assert_same(1, count($runs->quarantine_successor_calls), 'Expected one failed atomic successor attempt.');

        $runs->quarantine_successor_result = $successor;
        $result = $service->run_worker('landing_page_sessions');
        $reacquired = $lock_service->acquire_for_archive($old_archive_path);

        kiwi_assert_same('pending', $result['status'] ?? '', 'Expected deterministic successor scheduling.');
        kiwi_assert_same(true, $result['schedule_worker'] ?? false, 'Expected successor worker scheduling.');
        kiwi_assert_same([], $archive->chunk_calls, 'Expected no archive write after locked quarantine recheck.');
        kiwi_assert_same([], $service->deleted_primary_keys, 'Expected no source delete after quarantine detection.');
        kiwi_assert_same(2, count($runs->quarantine_successor_calls), 'Expected the atomic successor transition to retry once.');
        kiwi_assert_true(
            !empty($reacquired['success']) && !empty($reacquired['acquired']),
            'Expected the quarantined generation lock to be released after transition.'
        );
        $lock_service->release($reacquired['handle'] ?? null);
    } finally {
        $wpdb = $previous_wpdb;
        kiwi_remove_directory($test_root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service fails closed when archive discovery fails', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_discovery_failure');
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    $config = new Kiwi_Test_Retention_Archive_Health_Config($root);
    $archive_service = new Kiwi_Test_Failing_Archive_Discovery_Service($config);
    $check_calls = 0;
    $service = new Kiwi_Retention_Archive_Health_Service(
        $config,
        $archive_service,
        new Kiwi_Retention_Archive_Lock(),
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
        static function () use (&$check_calls): array {
            $check_calls++;

            return [
                'result' => 'ok',
                'reason_code' => 'unexpected_check',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        }
    );

    try {
        foreach ([
            $service->status(),
            $service->scheduled(),
            $service->diagnose('kiwi_retention_archive_2026.sqlite', 'quick'),
        ] as $result) {
            kiwi_assert_same('error', $result['result'] ?? '', 'Expected discovery failure to return an error.');
            kiwi_assert_same(2, $result['exit_code'] ?? 0, 'Expected nonzero discovery failure exit.');
            kiwi_assert_same(
                'archive_discovery_failed',
                $result['reason_code'] ?? '',
                'Expected explicit archive discovery reason.'
            );
        }
        kiwi_assert_same(0, $check_calls, 'Expected no child check after discovery failure.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service status is read-only and rejects invalid state', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_status');
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    [$service, $archive_service, , $runs] = kiwi_test_health_service(
        $root,
        $now,
        static function (): array {
            throw new RuntimeException('Status must not run a check.');
        }
    );
    $runs->rows[1] = [
        'status' => 'running',
        'finished_at' => null,
        'archive_db_path' => $archive_service->get_archive_directory()
            . DIRECTORY_SEPARATOR
            . 'kiwi_retention_archive_2026.sqlite',
        'archived_rows' => 0,
        'deleted_rows' => 0,
        'archive_last_primary_key' => 0,
        'delete_last_primary_key' => 0,
    ];
    $state_path = $archive_service->get_archive_directory()
        . DIRECTORY_SEPARATOR
        . 'kiwi_retention_archive_health_state.json';

    try {
        $status = $service->status();
        kiwi_assert_same('ok', $status['result'] ?? '', 'Expected read-only status without an existing state file.');
        kiwi_assert_same(false, $status['state_exists'] ?? true, 'Expected status to report absent state.');
        kiwi_assert_true(array_key_exists('check', $status), 'Expected required check field.');
        kiwi_assert_same(null, $status['check'], 'Expected no check value when status did not start SQLite.');
        kiwi_assert_true(array_key_exists('archive', $status), 'Expected required archive field.');
        kiwi_assert_same(null, $status['archive'], 'Expected no archive value for repository status.');
        kiwi_assert_true(array_key_exists('incident_action', $status), 'Expected required incident action field.');
        kiwi_assert_same(null, $status['incident_action'], 'Expected no incident action for repository status.');
        kiwi_assert_same(
            'active_archive_path_invalid',
            $status['active_archive_error'] ?? '',
            'Expected the absent frozen archive root to fail without mutation.'
        );
        kiwi_assert_true(!is_dir($archive_service->get_archive_directory()), 'Expected status not to create the archive directory.');

        mkdir($archive_service->get_archive_directory(), 0770, true);
        kiwi_write_file($state_path, '{"schema_version":1,"daily":{"attempts":"3","status":"completed","result":"ok"},"annual":[]}');
        $invalid = $service->status();
        kiwi_assert_same('error', $invalid['result'] ?? '', 'Expected malformed state to fail closed.');
        kiwi_assert_same(2, $invalid['exit_code'] ?? 0, 'Expected malformed state exit code 2.');
        kiwi_assert_same('health_state_invalid', $invalid['reason_code'] ?? '', 'Expected explicit invalid-state reason.');

        kiwi_write_file(
            $state_path,
            '{"schema_version":1,"daily":{"date":"2026-07-27","archive":"","check":"","attempts":0,"status":"completed","result":"no_work","reason_code":"","completed_at":""},"annual":{"cycle_year":"","snapshot":[],"completed":[],"results":[],"status":"pending"}}'
        );
        $contradictory = $service->status();
        kiwi_assert_same('error', $contradictory['result'] ?? '', 'Expected contradictory completed state to fail closed.');
        kiwi_assert_same(2, $contradictory['exit_code'] ?? 0, 'Expected contradictory state exit code 2.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Operational_Event_Repository propagates open incident query failures', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Open_Archive_Lookup();
    $wpdb->last_error = 'Synthetic operational incident query failure.';
    $failed = false;

    try {
        (new Kiwi_Operational_Event_Repository())->get_open_incidents(
            ['event_type' => 'retention_archive_corruption_detected'],
            100
        );
    } catch (RuntimeException $error) {
        $failed = true;
    } finally {
        $wpdb = $previous_wpdb;
    }

    kiwi_assert_true($failed, 'Expected open Incident query errors to remain distinguishable.');
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service filters relevant incidents before status limits', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_status_incidents');
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    $events = new Kiwi_Test_Operational_Event_Repository();
    $events->rows[] = [
        'correlation_key' => 'retention_archive_relevant_old',
        'lifecycle_action' => 'raised',
        'severity' => 'critical',
        'event_type' => 'retention_archive_corruption_detected',
        'reference_type' => 'retention_archive',
        'reference_id' => 'kiwi_retention_archive_2025.sqlite',
        'message' => 'Older relevant archive incident.',
        'created_at' => '2026-07-01 00:00:00',
    ];
    for ($index = 0; $index < 101; $index++) {
        $events->rows[] = [
            'correlation_key' => 'unrelated_incident_' . $index,
            'lifecycle_action' => 'raised',
            'severity' => 'warning',
            'event_type' => 'unrelated_operational_event',
            'reference_type' => 'test',
            'reference_id' => (string) $index,
            'message' => 'Newer unrelated incident.',
            'created_at' => '2026-07-02 00:00:00',
        ];
    }
    [$service] = kiwi_test_health_service(
        $root,
        $now,
        static function (): array {
            throw new RuntimeException('Status must not run a check.');
        },
        $events
    );

    try {
        $status = $service->status();
        kiwi_assert_same('ok', $status['result'] ?? '', 'Expected status incident lookup to succeed.');
        kiwi_assert_same(
            ['retention_archive_corruption_detected'],
            array_column($status['open_incidents'] ?? [], 'event_type'),
            'Expected the older relevant incident to survive newer unrelated incident volume.'
        );
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service audits scheduled bootstrap failures', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_bootstrap_failures');
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    [$service, $archive_service, $events] = kiwi_test_health_service(
        $root,
        $now,
        static function (): array {
            throw new RuntimeException('Bootstrap audit must not start SQLite.');
        }
    );

    try {
        $first = $service->record_scheduled_bootstrap_failure('wordpress_load_failed');
        $second = $service->record_scheduled_bootstrap_failure('wordpress_load_failed');
        $third = $service->record_scheduled_bootstrap_failure('wordpress_load_failed');
        $status = $service->status();

        kiwi_assert_same(2, $first['exit_code'] ?? 0, 'Expected first bootstrap failure to remain a runner error.');
        kiwi_assert_same(2, $second['exit_code'] ?? 0, 'Expected second bootstrap failure to remain a runner error.');
        kiwi_assert_same('raised', $third['incident_action'] ?? '', 'Expected the third bootstrap failure to raise the daily Incident.');
        kiwi_assert_same(3, $status['state']['daily']['attempts'] ?? 0, 'Expected all scheduled bootstrap failures to persist.');
        kiwi_assert_same(
            'wordpress_load_failed',
            $status['state']['daily']['reason_code'] ?? '',
            'Expected the WordPress loader failure reason in controller state.'
        );
        kiwi_assert_same(
            ['retention_archive_health_check_incomplete'],
            array_column($status['open_incidents'] ?? [], 'event_type'),
            'Expected the bootstrap failure Incident in read-only status.'
        );
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi bootstrap recorder audits without the health service graph', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_independent_bootstrap_failures');
    $archive_directory = $root . DIRECTORY_SEPARATOR . 'sqlite';
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    $events = [];
    $recorder = new Kiwi_Retention_Archive_Health_Bootstrap_Recorder(
        $archive_directory,
        static function () use ($now): DateTimeImmutable {
            return $now;
        },
        static function (array $event) use (&$events): string {
            $events[] = $event;

            return 'raised';
        }
    );
    [$service] = kiwi_test_health_service(
        $root,
        $now,
        static function (): array {
            throw new RuntimeException('Independent bootstrap audit must not start SQLite.');
        }
    );

    try {
        $first = $recorder->record('wordpress_load_failed');
        $second = $recorder->record('wordpress_load_failed');
        $third = $recorder->record('wordpress_load_failed');
        $status = $service->status();

        kiwi_assert_same(2, $first['exit_code'] ?? 0, 'Expected the independent recorder to preserve runner failure.');
        kiwi_assert_same(2, $second['exit_code'] ?? 0, 'Expected the second independent audit failure.');
        kiwi_assert_same('raised', $third['incident_action'] ?? '', 'Expected the third independent failure to raise an Incident.');
        kiwi_assert_same(3, $status['state']['daily']['attempts'] ?? 0, 'Expected the main service to accept independent state.');
        kiwi_assert_same(
            'wordpress_load_failed',
            $status['state']['daily']['reason_code'] ?? '',
            'Expected the loader failure reason in shared controller state.'
        );
        kiwi_assert_same(1, count($events), 'Expected exactly one threshold Incident attempt.');
        kiwi_assert_same(
            'active_archive_lookup',
            $events[0]['reference_id'] ?? '',
            'Expected the dependency-independent active lookup subject.'
        );
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service accounts controller deferrals exactly once', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_controller_deferrals');
    $now = new DateTimeImmutable(
        '2026-07-27 01:30:00',
        new DateTimeZone('Europe/Berlin')
    );
    $events = new Kiwi_Test_Operational_Event_Repository();
    $config = new Kiwi_Test_Retention_Archive_Health_Config($root);
    $archive_service = new Kiwi_Retention_Sqlite_Archive_Service($config);
    $locks = new Kiwi_Retention_Archive_Lock();
    $check_calls = 0;
    $service = new Kiwi_Retention_Archive_Health_Service(
        $config,
        $archive_service,
        $locks,
        new Kiwi_Operational_Event_Service($events),
        static function () use ($now): DateTimeImmutable {
            return $now;
        },
        static function () use (&$check_calls): array {
            $check_calls++;

            return [
                'result' => 'inconclusive',
                'reason_code' => 'health_child_timeout',
                'duration_seconds' => 600.0,
                'child_running' => false,
            ];
        },
        '',
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );
    $controller = null;

    try {
        kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2026.sqlite'
        );
        $first = $service->scheduled();
        kiwi_assert_same('inconclusive', $first['result'] ?? '', 'Expected the first real check to stay incomplete.');

        $controller = $locks->acquire_controller($archive_service->get_archive_directory());
        kiwi_assert_true(!empty($controller['acquired']), 'Expected external controller-lock fixture.');
        $second = $service->record_scheduled_bootstrap_failure('health_service_exception');
        $recorder = new Kiwi_Retention_Archive_Health_Bootstrap_Recorder(
            $archive_service->get_archive_directory(),
            static function () use (&$now): DateTimeImmutable {
                return $now;
            },
            static function (array $event) use ($events): string {
                return (new Kiwi_Operational_Event_Service($events))
                    ->record_failure_action($event);
            }
        );
        $third = $recorder->record('health_service_exception');
        kiwi_assert_same('archive_lock_active', $second['reason_code'] ?? '', 'Expected main bootstrap overlap receipt.');
        kiwi_assert_same('archive_lock_active', $third['reason_code'] ?? '', 'Expected standalone bootstrap overlap receipt.');

        $receipt_paths = glob(
            $archive_service->get_archive_directory()
            . DIRECTORY_SEPARATOR
            . 'kiwi_retention_archive_health_deferral_*.json'
        );
        $receipt_paths = is_array($receipt_paths) ? array_values($receipt_paths) : [];
        sort($receipt_paths);
        kiwi_assert_same(2, count($receipt_paths), 'Expected one durable receipt per overlapping invocation.');
        preg_match(
            '/_([a-f0-9]{32})\.json$/',
            basename((string) ($receipt_paths[0] ?? '')),
            $receipt_match
        );
        $first_receipt_id = (string) ($receipt_match[1] ?? '');
        kiwi_assert_true($first_receipt_id !== '', 'Expected a valid first receipt ID.');

        $state_path = $archive_service->get_archive_directory()
            . DIRECTORY_SEPARATOR
            . 'kiwi_retention_archive_health_state.json';
        $state = json_decode((string) file_get_contents($state_path), true);
        kiwi_assert_true(is_array($state), 'Expected durable controller state fixture.');
        $state['daily']['attempts'] = 2;
        $state['daily']['status'] = 'incomplete';
        $state['daily']['result'] = 'deferred';
        $state['daily']['reason_code'] = 'controller_lock_active';
        $state['daily']['completed_at'] = '';
        $state['daily']['controller_deferral_receipts'] = [$first_receipt_id];
        kiwi_write_file(
            $state_path,
            (string) json_encode($state) . "\n"
        );

        $locks->release($controller['handle'] ?? null);
        $controller = null;
        $now = new DateTimeImmutable(
            '2026-07-28 01:30:00',
            new DateTimeZone('Europe/Berlin')
        );
        $reconciled = $recorder->record('health_service_exception');
        $status = $service->status();
        $remaining_receipts = glob(
            $archive_service->get_archive_directory()
            . DIRECTORY_SEPARATOR
            . 'kiwi_retention_archive_health_deferral_*.json'
        );

        kiwi_assert_same('error', $reconciled['result'] ?? '', 'Expected the current bootstrap failure after prior-day receipt reconciliation.');
        kiwi_assert_same('raised', $reconciled['incident_action'] ?? '', 'Expected the prior day final slot to raise the incomplete Incident.');
        kiwi_assert_same(1, $status['state']['daily']['attempts'] ?? 0, 'Expected the new attempt window to start after prior-day reconciliation.');
        kiwi_assert_same(
            '2026-07-27',
            $status['state']['daily']['date'] ?? '',
            'Expected the overdue daily target date to remain frozen.'
        );
        kiwi_assert_same(
            '2026-07-28',
            $status['state']['daily']['attempt_date'] ?? '',
            'Expected the fallback failure in the new Berlin-day attempt window.'
        );
        kiwi_assert_same(
            'health_service_exception',
            $status['state']['daily']['reason_code'] ?? '',
            'Expected the new bootstrap failure only after prior receipt accounting.'
        );
        kiwi_assert_same(
            [],
            $status['state']['daily']['controller_deferral_receipts'] ?? [],
            'Expected accounted receipt IDs to prune after file cleanup.'
        );
        kiwi_assert_same([], is_array($remaining_receipts) ? $remaining_receipts : [], 'Expected all durable receipt files to be removed.');
        kiwi_assert_same(1, $check_calls, 'Expected overlap reconciliation not to rerun SQLite.');
        kiwi_assert_same(1, count($events->get_open_incidents()), 'Expected one open incomplete Incident.');
    } finally {
        if (is_array($controller)) {
            $locks->release($controller['handle'] ?? null);
        }
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service reconciles prior-day deferral before full-service bootstrap alignment', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_full_bootstrap_deferral');
    $now = new DateTimeImmutable(
        '2026-07-27 01:30:00',
        new DateTimeZone('Europe/Berlin')
    );
    $events = new Kiwi_Test_Operational_Event_Repository();
    $config = new Kiwi_Test_Retention_Archive_Health_Config($root);
    $archive_service = new Kiwi_Retention_Sqlite_Archive_Service($config);
    $locks = new Kiwi_Retention_Archive_Lock();
    $check_calls = 0;
    $service = new Kiwi_Retention_Archive_Health_Service(
        $config,
        $archive_service,
        $locks,
        new Kiwi_Operational_Event_Service($events),
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
        static function () use (&$check_calls): array {
            $check_calls++;

            return [
                'result' => 'inconclusive',
                'reason_code' => 'health_child_timeout',
                'duration_seconds' => 600.0,
                'child_running' => false,
            ];
        },
        '',
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );
    $controller = null;

    try {
        kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2026.sqlite'
        );
        $service->scheduled();
        $second = $service->scheduled();
        kiwi_assert_same('inconclusive', $second['result'] ?? '', 'Expected two persisted prior-day attempts.');

        $controller = $locks->acquire_controller($archive_service->get_archive_directory());
        kiwi_assert_true(!empty($controller['acquired']), 'Expected external controller-lock fixture.');
        $deferred = $service->record_scheduled_bootstrap_failure('health_service_exception');
        kiwi_assert_same('archive_lock_active', $deferred['reason_code'] ?? '', 'Expected durable third-slot deferral receipt.');
        $locks->release($controller['handle'] ?? null);
        $controller = null;

        $now = new DateTimeImmutable(
            '2026-07-28 01:30:00',
            new DateTimeZone('Europe/Berlin')
        );
        $reconciled = $service->record_scheduled_bootstrap_failure('health_service_exception');
        $status = $service->status();
        $remaining_receipts = glob(
            $archive_service->get_archive_directory()
            . DIRECTORY_SEPARATOR
            . 'kiwi_retention_archive_health_deferral_*.json'
        );

        kiwi_assert_same('raised', $reconciled['incident_action'] ?? '', 'Expected the prior-day third slot to raise before current-day alignment.');
        kiwi_assert_same(1, $status['state']['daily']['attempts'] ?? 0, 'Expected the new attempt window only after prior-day reconciliation.');
        kiwi_assert_same('2026-07-27', $status['state']['daily']['date'] ?? '', 'Expected the overdue target date to remain frozen.');
        kiwi_assert_same('2026-07-28', $status['state']['daily']['attempt_date'] ?? '', 'Expected current-day bootstrap accounting after reconciliation.');
        kiwi_assert_same('health_service_exception', $status['state']['daily']['reason_code'] ?? '', 'Expected the current bootstrap failure after receipt accounting.');
        kiwi_assert_same([], is_array($remaining_receipts) ? $remaining_receipts : [], 'Expected the reconciled receipt file to be removed.');
        kiwi_assert_same(2, $check_calls, 'Expected bootstrap reconciliation not to rerun SQLite.');
        kiwi_assert_same(1, count($events->get_open_incidents()), 'Expected the prior-day incomplete Incident to remain open.');
    } finally {
        if (is_array($controller)) {
            $locks->release($controller['handle'] ?? null);
        }
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service schedules weekday quick and Sunday integrity checks', function (): void {
    foreach ([
        ['2026-07-27 01:30:00', 'quick', 'quick_check'],
        ['2026-08-02 01:30:00', 'integrity', 'integrity_check'],
    ] as $case) {
        $root = kiwi_create_temp_directory('kiwi_retention_health_calendar');
        $now = new DateTimeImmutable($case[0], new DateTimeZone('Europe/Berlin'));
        $calls = [];
        [$service, $archive_service] = kiwi_test_health_service(
            $root,
            $now,
            static function (string $path, string $check) use (&$calls): array {
                $calls[] = ['path' => $path, 'check' => $check];

                return [
                    'result' => 'ok',
                    'reason_code' => 'sqlite_check_ok',
                    'duration_seconds' => 0.01,
                    'child_running' => false,
                ];
            }
        );

        try {
            kiwi_test_create_retention_archive(
                $archive_service,
                'kiwi_retention_archive_2026.sqlite'
            );
            $result = $service->scheduled();
            kiwi_assert_same('ok', $result['result'] ?? '', 'Expected scheduled daily health check to pass.');
            kiwi_assert_same($case[2], $result['check'] ?? '', 'Expected normalized public check value.');
            kiwi_assert_same($case[1], $calls[0]['check'] ?? '', 'Expected child check to receive selected check type.');
            kiwi_assert_same(0, $result['exit_code'] ?? -1, 'Expected successful scheduled exit code 0.');
            kiwi_assert_same(
                'kiwi_retention_archive_2026.sqlite',
                $result['archive'] ?? '',
                'Expected public archive to remain a relative filename.'
            );
            kiwi_assert_true(array_key_exists('incident_action', $result), 'Expected required incident action field.');
            kiwi_assert_same(null, $result['incident_action'], 'Expected no incident action for a successful check.');
        } finally {
            kiwi_remove_directory($root);
        }
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service follows an open run archive across year rollover', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_year_rollover');
    $now = new DateTimeImmutable('2027-01-01 01:30:00', new DateTimeZone('Europe/Berlin'));
    $calls = [];
    $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
    [$service, $archive_service] = kiwi_test_health_service(
        $root,
        $now,
        static function (string $path, string $check) use (&$calls): array {
            $calls[] = [basename($path), $check];

            return [
                'result' => 'ok',
                'reason_code' => 'sqlite_check_ok',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        },
        null,
        $runs
    );

    try {
        $prior_year_path = kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2026.sqlite'
        );
        kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2027.sqlite'
        );
        $runs->rows[1] = [
            'id' => 1,
            'status' => 'partial',
            'finished_at' => null,
            'archive_db_path' => $prior_year_path,
        ];

        $result = $service->scheduled();

        kiwi_assert_same('ok', $result['result'] ?? '', 'Expected rollover daily check to complete.');
        kiwi_assert_same(
            'kiwi_retention_archive_2026.sqlite',
            $result['archive'] ?? '',
            'Expected the open run frozen archive instead of the new calendar-year archive.'
        );
        kiwi_assert_same(
            [['kiwi_retention_archive_2026.sqlite', 'quick']],
            $calls,
            'Expected exactly one recurring check against the actively written prior-year generation.'
        );
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Cleanup_Run_Repository distinguishes active archive lookup errors', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Open_Archive_Lookup();
    $repository = new Kiwi_Retention_Cleanup_Run_Repository();

    try {
        $wpdb->last_error = 'Synthetic open-run query failure.';
        $open_run_failed = false;
        try {
            $repository->find_open_run_for_source('landing_page_sessions');
        } catch (RuntimeException $error) {
            $open_run_failed = true;
        }
        kiwi_assert_true($open_run_failed, 'Expected open-run query errors to remain distinguishable.');

        $wpdb->last_error = 'Synthetic query failure.';
        kiwi_assert_same(null, $repository->find_open_archive_state(), 'Expected wpdb last_error to fail the lookup.');

        $wpdb->last_error = '';
        kiwi_assert_same([], $repository->find_open_archive_state(), 'Expected a successful empty lookup to mean no active archive.');

        $wpdb->rows = [[
            'archive_db_path' => '/safe/kiwi_retention_archive_2026.sqlite',
            'archived_rows' => '12',
            'deleted_rows' => '8',
            'archive_last_primary_key' => '15',
            'delete_last_primary_key' => '10',
        ]];
        $state = $repository->find_open_archive_state();
        kiwi_assert_same(12, $state['archived_rows'] ?? -1, 'Expected normalized active archive progress.');
        kiwi_assert_same(10, $state['delete_last_primary_key'] ?? -1, 'Expected normalized active delete cursor.');
        kiwi_assert_contains(
            "'blocked'",
            $wpdb->last_query,
            'Expected receipt-blocked run to retain its frozen archive scope.'
        );

        $replacement_path = '/safe/kiwi_retention_archive_2026_part_2.sqlite';
        $wpdb->rows = [[
            'id' => '17',
            'run_id' => 'retention_empty_recovery',
            'source_key' => 'landing_page_sessions',
            'archive_db_path' => $replacement_path,
            'error_message' => '{"old_archive":"kiwi_retention_archive_2026.sqlite"}',
        ]];
        $contexts = $repository->find_unresolved_completed_empty_recovery_contexts(
            'landing_page_sessions'
        );
        kiwi_assert_same(
            'retention_empty_recovery',
            $contexts[0]['run_id'] ?? '',
            'Expected completed empty recovery evidence lookup.'
        );
        kiwi_assert_contains(
            "eligible_rows = 0",
            $wpdb->last_query,
            'Expected only zero-row recovery runs to carry resolution evidence.'
        );
        kiwi_assert_contains(
            "status IN ('completed', 'completed_noop')",
            $wpdb->last_query,
            'Expected only completed recovery runs to carry resolution evidence.'
        );
        kiwi_assert_contains(
            "error_code <> 'archive_recovery_resolved'",
            $wpdb->last_query,
            'Expected resolved recovery contexts to be excluded.'
        );
        kiwi_assert_same(
            ['landing_page_sessions'],
            $wpdb->last_args,
            'Expected the source key to remain a bound SQL parameter across archive years.'
        );

        $wpdb->last_error = 'Synthetic recovery lookup failure.';
        kiwi_assert_same(
            null,
            $repository->find_unresolved_completed_empty_recovery_contexts(
                'landing_page_sessions'
            ),
            'Expected recovery-context query errors to fail closed.'
        );

        $event_lookup_failed = false;
        try {
            (new Kiwi_Operational_Event_Repository())->find_latest_by_correlation_key(
                'retention_archive_health_lookup_failure'
            );
        } catch (RuntimeException $error) {
            $event_lookup_failed = true;
        }
        kiwi_assert_true(
            $event_lookup_failed,
            'Expected Operational Event lookup errors to remain distinguishable from no open incident.'
        );
    } finally {
        $wpdb = $previous_wpdb;
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service distinguishes unsafe and pre-write active archives', function (): void {
    $now = new DateTimeImmutable('2027-01-01 01:30:00', new DateTimeZone('Europe/Berlin'));
    $cases = [
        [
            'name' => 'lookup_failure',
            'runs' => new Kiwi_Test_Failing_Open_Archive_Run_Repository(),
            'result' => 'error',
            'reason_code' => 'active_archive_lookup_failed',
        ],
        [
            'name' => 'invalid_path',
            'runs' => new Kiwi_Test_Retention_Cleanup_Run_Repository(),
            'result' => 'error',
            'reason_code' => 'active_archive_path_invalid',
        ],
        [
            'name' => 'missing_after_progress',
            'runs' => new Kiwi_Test_Retention_Cleanup_Run_Repository(),
            'result' => 'error',
            'reason_code' => 'active_archive_missing',
        ],
        [
            'name' => 'missing_before_write',
            'runs' => new Kiwi_Test_Retention_Cleanup_Run_Repository(),
            'result' => 'no_work',
            'reason_code' => 'active_archive_unavailable',
        ],
    ];

    foreach ($cases as $case) {
        $root = kiwi_create_temp_directory('kiwi_retention_health_' . $case['name']);
        $check_calls = 0;
        $runs = $case['runs'];
        [$service, $archive_service] = kiwi_test_health_service(
            $root,
            $now,
            static function () use (&$check_calls): array {
                $check_calls++;

                return [
                    'result' => 'ok',
                    'reason_code' => 'unexpected_check',
                    'duration_seconds' => 0.01,
                    'child_running' => false,
                ];
            },
            null,
            $runs
        );

        try {
            kiwi_test_create_retention_archive(
                $archive_service,
                'kiwi_retention_archive_2027.sqlite'
            );
            if (in_array($case['name'], ['invalid_path', 'missing_after_progress', 'missing_before_write'], true)) {
                $missing_path = $archive_service->get_archive_directory()
                    . DIRECTORY_SEPARATOR
                    . 'kiwi_retention_archive_2026.sqlite';
                $runs->rows[1] = [
                    'id' => 1,
                    'status' => 'running',
                    'finished_at' => null,
                    'archive_db_path' => $case['name'] === 'invalid_path'
                        ? $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite'
                        : $missing_path,
                    'archived_rows' => $case['name'] === 'missing_after_progress' ? 2 : 0,
                    'deleted_rows' => 0,
                    'archive_last_primary_key' => $case['name'] === 'missing_after_progress' ? 2 : 0,
                    'delete_last_primary_key' => 0,
                ];
            }

            $result = $service->scheduled();

            kiwi_assert_same($case['result'], $result['result'] ?? '', 'Expected explicit active archive resolution result.');
            kiwi_assert_same($case['reason_code'], $result['reason_code'] ?? '', 'Expected explicit active archive failure reason.');
            kiwi_assert_same(0, $check_calls, 'Expected no SQLite child against a guessed archive.');
        } finally {
            kiwi_remove_directory($root);
        }
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service counts active archive lookup failures', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_lookup_attempts');
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    $events = new Kiwi_Test_Operational_Event_Repository();
    [$service] = kiwi_test_health_service(
        $root,
        $now,
        static function (): array {
            throw new RuntimeException('Child check must not run after lookup failure.');
        },
        $events,
        new Kiwi_Test_Failing_Open_Archive_Run_Repository()
    );

    try {
        $first = $service->scheduled();
        $second = $service->scheduled();
        $third = $service->scheduled();
        $status = $service->status();
        $fourth = $service->scheduled();

        kiwi_assert_same('active_archive_lookup_failed', $first['reason_code'] ?? '', 'Expected first lookup failure.');
        kiwi_assert_same('active_archive_lookup_failed', $second['reason_code'] ?? '', 'Expected second lookup failure.');
        kiwi_assert_same('raised', $third['incident_action'] ?? '', 'Expected third lookup failure to raise an incident.');
        kiwi_assert_same(3, $status['state']['daily']['attempts'] ?? 0, 'Expected persisted lookup attempt limit.');
        kiwi_assert_same('incomplete', $status['state']['daily']['status'] ?? '', 'Expected retryable lookup state.');
        kiwi_assert_same('', $status['state']['daily']['archive'] ?? 'unexpected', 'Expected unresolved archive identity.');
        kiwi_assert_same('daily_attempt_limit_reached', $fourth['reason_code'] ?? '', 'Expected bounded fourth slot.');
        kiwi_assert_same(1, count($events->rows), 'Expected one central lookup incident.');
        $event = array_values($events->rows)[0] ?? [];
        kiwi_assert_same('retention_archive_lookup', $event['reference_type'] ?? '', 'Expected lookup incident reference.');
        kiwi_assert_same('active_archive_lookup', $event['reference_id'] ?? '', 'Expected stable lookup correlation subject.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service resolves lookup incident only after a completed check', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_lookup_recovery');
    $current_time = new DateTimeImmutable(
        '2026-07-27 01:30:00',
        new DateTimeZone('Europe/Berlin')
    );
    $events = new Kiwi_Test_Operational_Event_Repository();
    $runs = new Kiwi_Test_Controllable_Open_Archive_Run_Repository();
    $outcomes = [
        [
            'result' => 'inconclusive',
            'reason_code' => 'health_child_timeout',
            'duration_seconds' => 600.0,
            'child_running' => false,
        ],
        [
            'result' => 'ok',
            'reason_code' => 'sqlite_check_ok',
            'duration_seconds' => 0.01,
            'child_running' => false,
        ],
    ];
    $config = new Kiwi_Test_Retention_Archive_Health_Config($root);
    $archive_service = new Kiwi_Retention_Sqlite_Archive_Service($config);
    $service = new Kiwi_Retention_Archive_Health_Service(
        $config,
        $archive_service,
        new Kiwi_Retention_Archive_Lock(),
        new Kiwi_Operational_Event_Service($events),
        static function () use (&$current_time): DateTimeImmutable {
            return $current_time;
        },
        static function () use (&$outcomes): array {
            return array_shift($outcomes);
        },
        '',
        $runs
    );

    try {
        $service->scheduled();
        $service->scheduled();
        $raised = $service->scheduled();
        kiwi_assert_same('raised', $raised['incident_action'] ?? '', 'Expected lookup incident after three failures.');

        $runs->fail_lookup = false;
        $current_time = new DateTimeImmutable(
            '2026-07-28 01:30:00',
            new DateTimeZone('Europe/Berlin')
        );

        $no_work = $service->scheduled();
        kiwi_assert_same('no_work', $no_work['result'] ?? '', 'Expected successful lookup without an archive to remain no-work.');
        kiwi_assert_same(null, $no_work['incident_action'] ?? null, 'Expected no-work not to report an Incident resolution.');
        kiwi_assert_same(
            ['raised'],
            array_column(array_values($events->rows), 'lifecycle_action'),
            'Expected no-work not to resolve a lookup Incident without a completed SQLite check.'
        );
        kiwi_assert_same(1, count($events->get_open_incidents()), 'Expected the lookup Incident to remain open after no-work.');

        kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2026.sqlite'
        );
        $current_time = new DateTimeImmutable(
            '2026-07-29 01:30:00',
            new DateTimeZone('Europe/Berlin')
        );

        $inconclusive = $service->scheduled();
        kiwi_assert_same('inconclusive', $inconclusive['result'] ?? '', 'Expected the first post-lookup check to stay incomplete.');
        kiwi_assert_same(
            ['raised'],
            array_column(array_values($events->rows), 'lifecycle_action'),
            'Expected lookup incident to remain open until a completed SQLite result.'
        );
        kiwi_assert_same(1, count($events->get_open_incidents()), 'Expected the lookup incident to remain open.');

        $completed = $service->scheduled();
        kiwi_assert_same('ok', $completed['result'] ?? '', 'Expected the next SQLite check to complete.');
        kiwi_assert_same(
            'resolved',
            $completed['incident_action'] ?? '',
            'Expected command JSON to report the persisted Incident resolution.'
        );
        kiwi_assert_same(
            ['raised', 'resolved'],
            array_column(array_values($events->rows), 'lifecycle_action'),
            'Expected lookup incident resolution only after the completed check.'
        );
        kiwi_assert_same([], $events->get_open_incidents(), 'Expected completed check to close the lookup incident.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service raises incomplete incident only after third attempt', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_attempts');
    $current_time = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    $events = new Kiwi_Test_Operational_Event_Repository();
    $config = new Kiwi_Test_Retention_Archive_Health_Config($root);
    $archive_service = new Kiwi_Retention_Sqlite_Archive_Service($config);
    $service = new Kiwi_Retention_Archive_Health_Service(
        $config,
        $archive_service,
        new Kiwi_Retention_Archive_Lock(),
        new Kiwi_Operational_Event_Service($events),
        static function () use (&$current_time): DateTimeImmutable {
            return $current_time;
        },
        static function (): array {
            return [
                'result' => 'inconclusive',
                'reason_code' => 'health_child_timeout',
                'duration_seconds' => 600.0,
                'child_running' => false,
            ];
        },
        '',
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    try {
        kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2026.sqlite'
        );
        $first = $service->scheduled();
        $second = $service->scheduled();
        $third = $service->scheduled();
        $fourth = $service->scheduled();
        $current_time = new DateTimeImmutable(
            '2026-07-28 01:30:00',
            new DateTimeZone('Europe/Berlin')
        );
        $service->scheduled();
        $service->scheduled();
        $repeated = $service->scheduled();
        kiwi_assert_same(1, $first['exit_code'] ?? 0, 'Expected first incomplete attempt exit code 1.');
        kiwi_assert_same(1, $second['exit_code'] ?? 0, 'Expected second incomplete attempt exit code 1.');
        kiwi_assert_same('raised', $third['incident_action'] ?? '', 'Expected third incomplete attempt to raise an incident.');
        kiwi_assert_same('daily_attempt_limit_reached', $fourth['reason_code'] ?? '', 'Expected no unbounded fourth daily attempt.');
        kiwi_assert_same('repeated', $repeated['incident_action'] ?? '', 'Expected the next daily attempt cycle to report a repeated incident.');
        kiwi_assert_same(2, count($events->rows), 'Expected one raised and one repeated incomplete Operational Incident.');
        kiwi_assert_same(
            'retention_archive_health_check_incomplete',
            array_values($events->rows)[0]['event_type'] ?? '',
            'Expected central incomplete health event type.'
        );
        kiwi_assert_same(
            ['raised', 'repeated'],
            array_column(array_values($events->rows), 'lifecycle_action'),
            'Expected JSON lifecycle actions to match the append-only Incident rows.'
        );
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service prioritizes overdue daily target after date change', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_overdue');
    $current_time = new DateTimeImmutable(
        '2026-07-27 01:30:00',
        new DateTimeZone('Europe/Berlin')
    );
    $config = new Kiwi_Test_Retention_Archive_Health_Config($root);
    $archive_service = new Kiwi_Retention_Sqlite_Archive_Service($config);
    $events = new Kiwi_Test_Operational_Event_Repository();
    $calls = [];
    $service = new Kiwi_Retention_Archive_Health_Service(
        $config,
        $archive_service,
        new Kiwi_Retention_Archive_Lock(),
        new Kiwi_Operational_Event_Service($events),
        static function () use (&$current_time): DateTimeImmutable {
            return $current_time;
        },
        static function (string $path, string $check) use (&$calls): array {
            $calls[] = [basename($path), $check];

            return count($calls) <= 3
                ? [
                    'result' => 'inconclusive',
                    'reason_code' => 'health_child_timeout',
                    'duration_seconds' => 600.0,
                    'child_running' => false,
                ]
                : [
                    'result' => 'ok',
                    'reason_code' => 'sqlite_check_ok',
                    'duration_seconds' => 0.01,
                    'child_running' => false,
                ];
        },
        '',
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    try {
        kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2026.sqlite'
        );
        $service->scheduled();
        $service->scheduled();
        $service->scheduled();
        kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2026_part_2.sqlite'
        );

        $current_time = new DateTimeImmutable(
            '2026-07-28 01:30:00',
            new DateTimeZone('Europe/Berlin')
        );
        $overdue = $service->scheduled();
        $overdue_status = $service->status();
        $current = $service->scheduled();

        kiwi_assert_same(
            'ok',
            $overdue['result'] ?? '',
            'Expected next-day slot to complete overdue work first: ' . json_encode($overdue)
        );
        kiwi_assert_same(
            'kiwi_retention_archive_2026.sqlite',
            $overdue['archive'] ?? '',
            'Expected overdue check to retain its original archive generation.'
        );
        kiwi_assert_same(
            ['kiwi_retention_archive_2026.sqlite', 'quick'],
            $calls[3] ?? [],
            'Expected original due-date check mode and target after the Berlin date change.'
        );
        kiwi_assert_same(
            '2026-07-27',
            $overdue_status['state']['daily']['date'] ?? '',
            'Expected persisted due date to remain attached to the overdue result.'
        );
        kiwi_assert_same(
            '2026-07-28',
            $overdue_status['state']['daily']['attempt_date'] ?? '',
            'Expected the retry budget to advance to the current Berlin date.'
        );
        kiwi_assert_same(
            'kiwi_retention_archive_2026_part_2.sqlite',
            $current['archive'] ?? '',
            'Expected the next invocation to initialize the current-day active generation.'
        );
        kiwi_assert_same(
            ['kiwi_retention_archive_2026_part_2.sqlite', 'quick'],
            $calls[4] ?? [],
            'Expected current-day work only after the overdue target completed.'
        );
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service persists daily success when recovery logging fails', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_recovery_logging');
    $now = new DateTimeImmutable('2026-01-01 01:30:00', new DateTimeZone('Europe/Berlin'));
    $check_calls = 0;
    $events = new Kiwi_Test_Flaky_Operational_Event_Repository();
    [$service, $archive_service] = kiwi_test_health_service(
        $root,
        $now,
        static function () use (&$check_calls): array {
            $check_calls++;

            return [
                'result' => 'ok',
                'reason_code' => 'sqlite_check_ok',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        },
        $events
    );

    try {
        $archive_name = 'kiwi_retention_archive_2026.sqlite';
        kiwi_test_create_retention_archive($archive_service, $archive_name);
        $event_service = new Kiwi_Operational_Event_Service($events);
        kiwi_assert_true($event_service->record_failure([
            'area' => 'retention',
            'severity' => 'error',
            'event_type' => 'retention_archive_health_check_incomplete',
            'correlation_key' => 'retention_archive_health_incomplete_' . hash('sha256', $archive_name),
            'idempotency_key' => 'retention_archive_health_incomplete_test',
            'reference_type' => 'retention_archive',
            'reference_id' => $archive_name,
            'message' => 'Synthetic incomplete health cycle.',
        ]), 'Expected incomplete incident fixture.');
        $events->fail_next_insert = true;

        $result = $service->scheduled();
        $status = $service->status();
        $next_slot = $service->scheduled();

        kiwi_assert_same('ok', $result['result'] ?? '', 'Expected successful check despite best-effort recovery logging failure.');
        kiwi_assert_same('completed', $status['state']['daily']['status'] ?? '', 'Expected successful daily state to persist first.');
        kiwi_assert_same('ok', $status['state']['daily']['result'] ?? '', 'Expected durable successful daily result.');
        kiwi_assert_same('annual_campaign_not_due', $next_slot['reason_code'] ?? '', 'Expected the next slot not to repeat the completed daily check.');
        kiwi_assert_same(1, $check_calls, 'Expected no duplicate daily SQLite check after recovery logging failure.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service fails closed when a persisted daily target disappears', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_no_work_reset');
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    [$service, $archive_service] = kiwi_test_health_service(
        $root,
        $now,
        static function (): array {
            return [
                'result' => 'deferred',
                'reason_code' => 'archive_lock_active',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        }
    );

    try {
        $archive_path = kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2026.sqlite'
        );
        $first = $service->scheduled();
        kiwi_assert_same('deferred', $first['result'] ?? '', 'Expected persisted incomplete daily attempt fixture.');
        @unlink($archive_path);

        $missing = $service->scheduled();
        $status = $service->status();

        kiwi_assert_same('error', $missing['result'] ?? '', 'Expected a missing persisted daily target to fail closed.');
        kiwi_assert_same('daily_archive_unavailable', $missing['reason_code'] ?? '', 'Expected explicit persisted-target loss evidence.');
        kiwi_assert_same('ok', $status['result'] ?? '', 'Expected controller state to remain readable after target loss.');
        kiwi_assert_same(
            'kiwi_retention_archive_2026.sqlite',
            $status['state']['daily']['archive'] ?? '',
            'Expected the missing persisted target identity to remain audited.'
        );
        kiwi_assert_same(2, $status['state']['daily']['attempts'] ?? 0, 'Expected target loss to consume the next bounded attempt.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service annual campaign processes one snapshot file per free slot', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_annual');
    $now = new DateTimeImmutable('2026-01-02 01:30:00', new DateTimeZone('Europe/Berlin'));
    $calls = [];
    [$service, $archive_service] = kiwi_test_health_service(
        $root,
        $now,
        static function (string $path, string $check) use (&$calls): array {
            $calls[] = [basename($path), $check];

            return [
                'result' => 'ok',
                'reason_code' => 'sqlite_check_ok',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        }
    );

    try {
        kiwi_test_create_retention_archive($archive_service, 'kiwi_retention_archive_2025.sqlite');
        kiwi_test_create_retention_archive($archive_service, 'kiwi_retention_archive_2026.sqlite');
        $daily = $service->scheduled();
        $first_annual = $service->scheduled();
        $second_annual = $service->scheduled();
        kiwi_assert_same('daily', $daily['scope'] ?? '', 'Expected daily work to consume the first slot.');
        kiwi_assert_same('annual', $first_annual['scope'] ?? '', 'Expected first free slot to enter annual campaign.');
        kiwi_assert_same('annual', $second_annual['scope'] ?? '', 'Expected next free slot to continue annual campaign.');
        kiwi_assert_same(
            [
                ['kiwi_retention_archive_2026.sqlite', 'quick'],
                ['kiwi_retention_archive_2025.sqlite', 'integrity'],
                ['kiwi_retention_archive_2026.sqlite', 'integrity'],
            ],
            $calls,
            'Expected one deterministic annual snapshot file per invocation.'
        );
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service persists annual snapshot before first check', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_annual_snapshot');
    $now = new DateTimeImmutable('2026-01-02 01:30:00', new DateTimeZone('Europe/Berlin'));
    $state_path = $root
        . DIRECTORY_SEPARATOR
        . 'sqlite'
        . DIRECTORY_SEPARATOR
        . 'kiwi_retention_archive_health_state.json';
    $annual_state_at_check = null;
    $calls = 0;
    [$service, $archive_service] = kiwi_test_health_service(
        $root,
        $now,
        static function (string $path, string $check) use (&$calls, &$annual_state_at_check, $state_path): array {
            $calls++;
            if ($calls > 1) {
                $raw = @file_get_contents($state_path);
                $persisted = is_string($raw) ? json_decode($raw, true) : null;
                $annual_state_at_check = is_array($persisted) ? ($persisted['annual'] ?? null) : null;
            }

            return [
                'result' => $calls === 1 ? 'ok' : 'deferred',
                'reason_code' => $calls === 1 ? 'sqlite_check_ok' : 'archive_lock_active',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        }
    );

    try {
        kiwi_test_create_retention_archive($archive_service, 'kiwi_retention_archive_2025.sqlite');
        kiwi_test_create_retention_archive($archive_service, 'kiwi_retention_archive_2026.sqlite');
        $service->scheduled();
        $first_annual = $service->scheduled();

        kiwi_assert_same('deferred', $first_annual['result'] ?? '', 'Expected first annual check to remain retryable.');
        kiwi_assert_same(
            ['kiwi_retention_archive_2025.sqlite', 'kiwi_retention_archive_2026.sqlite'],
            $annual_state_at_check['snapshot'] ?? [],
            'Expected frozen annual snapshot to be durable before invoking its first check.'
        );
        kiwi_assert_same('running', $annual_state_at_check['status'] ?? '', 'Expected persisted annual campaign to be running.');

        kiwi_test_create_retention_archive($archive_service, 'kiwi_retention_archive_2026_part_2.sqlite');
        $service->scheduled();
        $status = $service->status();
        kiwi_assert_same(
            ['kiwi_retention_archive_2025.sqlite', 'kiwi_retention_archive_2026.sqlite'],
            $status['state']['annual']['snapshot'] ?? [],
            'Expected retry to keep the original annual scope after archive discovery changes.'
        );
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service keeps missing annual snapshot archives pending', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_annual_missing');
    $now = new DateTimeImmutable('2026-01-02 01:30:00', new DateTimeZone('Europe/Berlin'));
    $calls = 0;
    [$service, $archive_service] = kiwi_test_health_service(
        $root,
        $now,
        static function () use (&$calls): array {
            $calls++;

            return [
                'result' => $calls === 2 ? 'deferred' : 'ok',
                'reason_code' => $calls === 2 ? 'archive_lock_active' : 'sqlite_check_ok',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        }
    );

    try {
        $annual_archive = kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2025.sqlite'
        );
        kiwi_test_create_retention_archive($archive_service, 'kiwi_retention_archive_2026.sqlite');
        $service->scheduled();
        $service->scheduled();
        @unlink($annual_archive);

        $missing = $service->scheduled();
        $missing_status = $service->status();

        kiwi_assert_same('error', $missing['result'] ?? '', 'Expected missing annual archive to fail closed.');
        kiwi_assert_same('failed', $missing['status'] ?? '', 'Expected explicit failed controller status.');
        kiwi_assert_same(2, $missing['exit_code'] ?? 0, 'Expected operator-visible failure exit code.');
        kiwi_assert_same(
            'annual_archive_unavailable',
            $missing['reason_code'] ?? '',
            'Expected explicit unavailable annual archive reason.'
        );
        kiwi_assert_same([], $missing_status['state']['annual']['completed'] ?? null, 'Expected archive to remain pending.');
        kiwi_assert_same([], $missing_status['state']['annual']['results'] ?? null, 'Expected no durable skipped result.');
        kiwi_assert_same('running', $missing_status['state']['annual']['status'] ?? '', 'Expected campaign to remain retryable.');
        kiwi_assert_same(2, $calls, 'Expected no child check for an unavailable archive.');

        kiwi_test_create_retention_archive($archive_service, 'kiwi_retention_archive_2025.sqlite');
        $retried = $service->scheduled();
        $retried_status = $service->status();

        kiwi_assert_same('ok', $retried['result'] ?? '', 'Expected restored annual archive to be retried.');
        kiwi_assert_same(
            ['kiwi_retention_archive_2025.sqlite'],
            $retried_status['state']['annual']['completed'] ?? [],
            'Expected restored archive to complete only after a successful check.'
        );
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service carries unfinished annual campaign across year rollover', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_annual_rollover');
    $now = new DateTimeImmutable('2027-01-02 01:30:00', new DateTimeZone('Europe/Berlin'));
    [$service, $archive_service] = kiwi_test_health_service(
        $root,
        $now,
        static function (): array {
            return [
                'result' => 'ok',
                'reason_code' => 'sqlite_check_ok',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        }
    );
    $archive_name = 'kiwi_retention_archive_2025.sqlite';
    $state_path = $archive_service->get_archive_directory()
        . DIRECTORY_SEPARATOR
        . 'kiwi_retention_archive_health_state.json';

    try {
        kiwi_test_create_retention_archive($archive_service, 'kiwi_retention_archive_2027.sqlite');
        kiwi_write_file($state_path, json_encode([
            'schema_version' => 1,
            'daily' => [
                'date' => '2027-01-02',
                'archive' => 'kiwi_retention_archive_2027.sqlite',
                'check' => 'quick',
                'attempts' => 1,
                'status' => 'completed',
                'result' => 'ok',
                'reason_code' => 'sqlite_check_ok',
                'completed_at' => '2027-01-02T01:30:00+01:00',
            ],
            'annual' => [
                'cycle_year' => '2026',
                'snapshot' => [$archive_name],
                'completed' => [],
                'results' => [],
                'status' => 'running',
            ],
        ]));

        $missing = $service->scheduled();
        $status = $service->status();
        kiwi_assert_same('error', $missing['result'] ?? '', 'Expected the carried unavailable archive to fail visibly.');
        kiwi_assert_same('annual_archive_unavailable', $missing['reason_code'] ?? '', 'Expected the carried campaign to remain pending.');
        kiwi_assert_same('2026', $status['state']['annual']['cycle_year'] ?? '', 'Expected the unfinished prior cycle to remain active.');
        kiwi_assert_same([$archive_name], $status['state']['annual']['snapshot'] ?? [], 'Expected the prior frozen snapshot to remain intact.');

        kiwi_test_create_retention_archive($archive_service, $archive_name);
        $completed = $service->scheduled();
        kiwi_assert_same('ok', $completed['result'] ?? '', 'Expected the restored prior-cycle archive to complete.');
        $service->scheduled();
        $new_cycle = $service->status();
        kiwi_assert_same('2027', $new_cycle['state']['annual']['cycle_year'] ?? '', 'Expected the current cycle to start only after prior completion.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service resolves incomplete incident after annual success', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_annual_recovery');
    $now = new DateTimeImmutable('2026-01-02 01:30:00', new DateTimeZone('Europe/Berlin'));
    $events = new Kiwi_Test_Flaky_Operational_Event_Repository();
    $check_calls = 0;
    [$service, $archive_service] = kiwi_test_health_service(
        $root,
        $now,
        static function () use (&$check_calls): array {
            $check_calls++;

            return [
                'result' => 'ok',
                'reason_code' => 'sqlite_check_ok',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        },
        $events
    );

    try {
        $archive_name = 'kiwi_retention_archive_2025.sqlite';
        kiwi_test_create_retention_archive($archive_service, $archive_name);
        kiwi_test_create_retention_archive($archive_service, 'kiwi_retention_archive_2026.sqlite');
        $event_service = new Kiwi_Operational_Event_Service($events);
        $correlation_key = 'retention_archive_health_incomplete_' . hash('sha256', $archive_name);
        kiwi_assert_true($event_service->record_failure([
            'area' => 'retention',
            'severity' => 'error',
            'event_type' => 'retention_archive_health_check_incomplete',
            'correlation_key' => $correlation_key,
            'idempotency_key' => 'retention_archive_health_annual_recovery_test',
            'reference_type' => 'retention_archive',
            'reference_id' => $archive_name,
            'message' => 'Synthetic incomplete daily cycle before annual recovery.',
        ]), 'Expected incomplete incident fixture.');

        $service->scheduled();
        $events->fail_next_latest_lookup = true;
        $failed_lookup = $service->scheduled();
        $pending_after_lookup = $service->status();
        $events->fail_next_insert = true;
        $failed_insert = $service->scheduled();
        $pending_after_insert = $service->status();
        $annual = $service->scheduled();
        $latest = $events->find_latest_by_correlation_key($correlation_key);

        kiwi_assert_same('error', $failed_lookup['result'] ?? '', 'Expected failed annual recovery lookup to remain visible.');
        kiwi_assert_same(
            'incomplete_recovery_persist_failed',
            $failed_lookup['reason_code'] ?? '',
            'Expected ambiguous annual recovery lookup to fail closed.'
        );
        kiwi_assert_same(
            [],
            $pending_after_lookup['state']['annual']['completed'] ?? [],
            'Expected the annual archive to remain retryable after lookup failure.'
        );
        kiwi_assert_same('error', $failed_insert['result'] ?? '', 'Expected failed annual recovery insert to remain visible.');
        kiwi_assert_same(
            'incomplete_recovery_persist_failed',
            $failed_insert['reason_code'] ?? '',
            'Expected explicit retryable annual recovery persistence reason.'
        );
        kiwi_assert_same(
            [],
            $pending_after_insert['state']['annual']['completed'] ?? [],
            'Expected the annual archive to remain retryable.'
        );
        kiwi_assert_same('ok', $annual['result'] ?? '', 'Expected complete annual integrity result.');
        kiwi_assert_same(4, $check_calls, 'Expected one daily check and three bounded annual attempts.');
        kiwi_assert_same('resolved', $latest['lifecycle_action'] ?? '', 'Expected annual success to resolve incomplete incident.');
        kiwi_assert_same([], $events->get_open_incidents([
            'event_type' => 'retention_archive_health_check_incomplete',
        ]), 'Expected no open incomplete incident after annual success.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service reconciles quarantined annual snapshot entry', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_annual_quarantine_reconcile');
    $now = new DateTimeImmutable('2026-01-02 01:30:00', new DateTimeZone('Europe/Berlin'));
    $calls = 0;
    $config = new Kiwi_Test_Retention_Archive_Health_Config($root);
    $archive_service = new Kiwi_Retention_Sqlite_Archive_Service($config);
    $events = new Kiwi_Test_Operational_Event_Repository();
    $service = new Kiwi_Retention_Archive_Health_Service(
        $config,
        $archive_service,
        new Kiwi_Retention_Archive_Lock(),
        new Kiwi_Operational_Event_Service($events),
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
        static function () use (&$calls): array {
            $calls++;

            return [
                'result' => $calls === 2 ? 'deferred' : 'ok',
                'reason_code' => $calls === 2 ? 'archive_lock_active' : 'sqlite_check_ok',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        },
        '',
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    try {
        $annual_archive = kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2025.sqlite'
        );
        kiwi_test_create_retention_archive($archive_service, 'kiwi_retention_archive_2026.sqlite');
        $service->scheduled();
        $first_annual = $service->scheduled();
        kiwi_assert_same('deferred', $first_annual['result'] ?? '', 'Expected persisted annual campaign before crash fixture.');
        kiwi_assert_true($archive_service->mark_quarantined($annual_archive, [
            'detected_at' => '2026-01-02T01:35:00+01:00',
            'check' => 'integrity',
            'reason_code' => 'sqlite_check_reported_corruption',
        ]), 'Expected annual crash-window quarantine marker fixture.');
        $archive_name = basename($annual_archive);
        $correlation_key = 'retention_archive_health_incomplete_' . hash('sha256', $archive_name);
        $event_service = new Kiwi_Operational_Event_Service($events);
        kiwi_assert_true($event_service->record_failure([
            'area' => 'retention',
            'severity' => 'error',
            'event_type' => 'retention_archive_health_check_incomplete',
            'correlation_key' => $correlation_key,
            'idempotency_key' => 'retention_archive_annual_quarantine_retry_test',
            'reference_type' => 'retention_archive',
            'reference_id' => $archive_name,
            'message' => 'Synthetic incomplete daily cycle before annual quarantine retry.',
        ]), 'Expected incomplete incident fixture before annual reconciliation.');

        $now = new DateTimeImmutable('2026-01-03 01:30:00', new DateTimeZone('Europe/Berlin'));
        $new_daily = $service->scheduled();
        $before_annual = $service->status();
        kiwi_assert_same('ok', $new_daily['result'] ?? '', 'Expected the new Berlin-day active archive check first.');
        kiwi_assert_same(
            'kiwi_retention_archive_2026.sqlite',
            $new_daily['archive'] ?? '',
            'Expected the current active generation before annual marker recovery.'
        );
        kiwi_assert_same(
            '2026-01-03',
            $before_annual['state']['daily']['date'] ?? '',
            'Expected the new daily cycle to remain recorded.'
        );
        kiwi_assert_same(
            false,
            $archive_service->is_quarantine_reconciled($annual_archive),
            'Expected annual marker reconciliation to wait until after the daily check.'
        );

        $reconciled = $service->scheduled();
        $status = $service->status();
        $latest = $events->find_latest_by_correlation_key($correlation_key);

        kiwi_assert_same('corruption_detected', $reconciled['result'] ?? '', 'Expected annual marker reconciliation instead of skipped result.');
        kiwi_assert_same(3, $calls, 'Expected annual reconciliation not to rerun the quarantined archive check.');
        kiwi_assert_same(
            '2026-01-03',
            $status['state']['daily']['date'] ?? '',
            'Expected annual recovery not to replace the new daily state.'
        );
        kiwi_assert_same(
            'corruption_detected',
            $status['state']['annual']['results']['kiwi_retention_archive_2025.sqlite'] ?? '',
            'Expected durable annual corruption result.'
        );
        kiwi_assert_true(
            $archive_service->is_quarantine_reconciled($annual_archive),
            'Expected annual marker retry to acknowledge the marker after durable state.'
        );
        kiwi_assert_same(
            'resolved',
            $latest['lifecycle_action'] ?? '',
            'Expected annual quarantine retry to resolve the prior incomplete incident.'
        );
        kiwi_assert_same([], $events->get_open_incidents([
            'event_type' => 'retention_archive_health_check_incomplete',
        ]), 'Expected no open incomplete incident after annual quarantine reconciliation.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service quarantines confirmed corruption without older fallback', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_quarantine');
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    $events = new Kiwi_Test_Operational_Event_Repository();
    [$service, $archive_service] = kiwi_test_health_service(
        $root,
        $now,
        static function (): array {
            return [
                'result' => 'corruption_detected',
                'reason_code' => 'sqlite_check_reported_corruption',
                'duration_seconds' => 0.02,
                'child_running' => false,
            ];
        },
        $events
    );

    try {
        kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2026.sqlite'
        );
        $active = kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2026_part_2.sqlite'
        );
        $result = $service->scheduled();
        kiwi_assert_same('corruption_detected', $result['result'] ?? '', 'Expected complete corruption result.');
        kiwi_assert_same('raised', $result['incident_action'] ?? '', 'Expected corruption incident action.');
        kiwi_assert_true($archive_service->is_quarantined($active), 'Expected only confirmed active generation to be quarantined.');
        kiwi_assert_true(
            $archive_service->is_quarantine_reconciled($active),
            'Expected the durable daily corruption state to acknowledge the quarantine marker immediately.'
        );
        kiwi_assert_same(
            'kiwi_retention_archive_2026_part_3.sqlite',
            basename($archive_service->resolve_archive_db_path('')),
            'Expected deterministic successor generation instead of fallback to an older archive.'
        );
        kiwi_assert_same(1, count($events->rows), 'Expected one corruption incident.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service holds generation lock through quarantine transition', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_quarantine_lock');
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    $config = new Kiwi_Test_Retention_Archive_Health_Config($root);
    $archive_service = new Kiwi_Test_Lock_Observed_Retention_Sqlite_Archive_Service($config);
    $locks = new Kiwi_Retention_Archive_Lock();
    $service = new Kiwi_Retention_Archive_Health_Service(
        $config,
        $archive_service,
        $locks,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        static function () use ($now): DateTimeImmutable {
            return $now;
        },
        static function (): array {
            return [
                'result' => 'corruption_detected',
                'reason_code' => 'sqlite_check_reported_corruption',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        },
        '',
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    try {
        $archive_path = kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2026.sqlite'
        );
        $result = $service->scheduled();
        $reacquired = $locks->acquire_for_archive($archive_path);

        kiwi_assert_same('corruption_detected', $result['result'] ?? '', 'Expected corruption transition completion.');
        kiwi_assert_true($archive_service->quarantine_lock_held, 'Expected generation lock to remain held while writing quarantine marker.');
        kiwi_assert_true(
            !empty($reacquired['success']) && !empty($reacquired['acquired']),
            'Expected generation lock release after the complete quarantine transition.'
        );
        $locks->release($reacquired['handle'] ?? null);
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service keeps corruption write-blocked when later persistence fails', function (): void {
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    $runner = static function (): array {
        return [
            'result' => 'corruption_detected',
            'reason_code' => 'sqlite_check_reported_corruption',
            'duration_seconds' => 0.01,
            'child_running' => false,
        ];
    };

    $event_failure_root = kiwi_create_temp_directory('kiwi_retention_health_event_failure');
    $event_failure_config = new Kiwi_Test_Retention_Archive_Health_Config($event_failure_root);
    $event_failure_archive = new Kiwi_Retention_Sqlite_Archive_Service($event_failure_config);
    $event_failure_events = new Kiwi_Test_Flaky_Operational_Event_Repository();
    $event_failure_events->fail_next_insert = true;
    $event_failure_locks = new Kiwi_Retention_Archive_Lock();
    $event_failure_service = new Kiwi_Retention_Archive_Health_Service(
        $event_failure_config,
        $event_failure_archive,
        $event_failure_locks,
        new Kiwi_Operational_Event_Service($event_failure_events),
        static function () use ($now): DateTimeImmutable {
            return $now;
        },
        $runner,
        '',
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    try {
        $event_failure_path = kiwi_test_create_retention_archive(
            $event_failure_archive,
            'kiwi_retention_archive_2026.sqlite'
        );
        $event_failure_result = $event_failure_service->scheduled();
        $event_failure_writer = $event_failure_locks->acquire_for_archive($event_failure_path);

        kiwi_assert_same('error', $event_failure_result['result'] ?? '', 'Expected failed incident persistence not to report completed corruption handling.');
        kiwi_assert_same('corruption_state_persist_failed', $event_failure_result['reason_code'] ?? '', 'Expected explicit corruption persistence failure.');
        kiwi_assert_true($event_failure_archive->is_quarantined($event_failure_path), 'Expected quarantine marker attempt despite Incident persistence failure.');
        kiwi_assert_true(
            !empty($event_failure_writer['acquired'])
                && ($event_failure_writer['handle'] ?? null) instanceof Kiwi_Retention_Archive_Lock_Handle
                && $event_failure_writer['handle']->is_write_blocked(),
            'Expected the durable write block to survive Incident persistence failure.'
        );
        $event_failure_locks->release($event_failure_writer['handle'] ?? null);
        $event_failure_reconciled = $event_failure_service->scheduled();
        $event_failure_marker = json_decode(
            (string) file_get_contents(
                $event_failure_archive->get_quarantine_marker_path($event_failure_path)
            ),
            true
        );
        kiwi_assert_same('corruption_detected', $event_failure_reconciled['result'] ?? '', 'Expected the next slot to retry and persist the missing corruption Incident.');
        kiwi_assert_same('raised', $event_failure_reconciled['incident_action'] ?? '', 'Expected reconciled Incident lifecycle action.');
        kiwi_assert_same(1, count($event_failure_events->rows), 'Expected exactly one persisted corruption Incident after retry.');
        kiwi_assert_true(
            is_array($event_failure_marker)
                && trim((string) ($event_failure_marker['controller_recorded_at'] ?? '')) !== '',
            'Expected marker acknowledgement only after corruption Incident persistence.'
        );
    } finally {
        kiwi_remove_directory($event_failure_root);
    }

    $marker_failure_root = kiwi_create_temp_directory('kiwi_retention_health_marker_failure');
    $marker_failure_config = new Kiwi_Test_Retention_Archive_Health_Config($marker_failure_root);
    $marker_failure_archive = new Kiwi_Test_Failing_Quarantine_Retention_Sqlite_Archive_Service(
        $marker_failure_config
    );
    $marker_failure_events = new Kiwi_Test_Operational_Event_Repository();
    $marker_failure_locks = new Kiwi_Retention_Archive_Lock();
    $marker_failure_service = new Kiwi_Retention_Archive_Health_Service(
        $marker_failure_config,
        $marker_failure_archive,
        $marker_failure_locks,
        new Kiwi_Operational_Event_Service($marker_failure_events),
        static function () use ($now): DateTimeImmutable {
            return $now;
        },
        $runner,
        '',
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    try {
        $marker_failure_path = kiwi_test_create_retention_archive(
            $marker_failure_archive,
            'kiwi_retention_archive_2026.sqlite'
        );
        $marker_failure_result = $marker_failure_service->scheduled();
        $marker_failure_writer = $marker_failure_locks->acquire_for_archive($marker_failure_path);

        kiwi_assert_same('error', $marker_failure_result['result'] ?? '', 'Expected failed marker persistence not to report completed corruption handling.');
        kiwi_assert_same('corruption_state_persist_failed', $marker_failure_result['reason_code'] ?? '', 'Expected explicit quarantine persistence failure.');
        kiwi_assert_same(1, count($marker_failure_events->rows), 'Expected Incident persistence attempt despite quarantine marker failure.');
        kiwi_assert_true(
            !empty($marker_failure_writer['acquired'])
                && ($marker_failure_writer['handle'] ?? null) instanceof Kiwi_Retention_Archive_Lock_Handle
                && $marker_failure_writer['handle']->is_write_blocked(),
            'Expected the durable write block to survive quarantine marker failure.'
        );
        $marker_failure_locks->release($marker_failure_writer['handle'] ?? null);
    } finally {
        kiwi_remove_directory($marker_failure_root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service reports repeated direct corruption action', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_repeated_corruption');
    $current_time = new DateTimeImmutable(
        '2026-07-27 01:30:00',
        new DateTimeZone('Europe/Berlin')
    );
    $config = new Kiwi_Test_Retention_Archive_Health_Config($root);
    $archive_service = new Kiwi_Test_One_Failure_Quarantine_Retention_Sqlite_Archive_Service(
        $config
    );
    $events = new Kiwi_Test_Operational_Event_Repository();
    $service = new Kiwi_Retention_Archive_Health_Service(
        $config,
        $archive_service,
        new Kiwi_Retention_Archive_Lock(),
        new Kiwi_Operational_Event_Service($events),
        static function () use (&$current_time): DateTimeImmutable {
            return $current_time;
        },
        static function (): array {
            return [
                'result' => 'corruption_detected',
                'reason_code' => 'sqlite_check_reported_corruption',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        },
        '',
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    try {
        kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2026.sqlite'
        );
        $first = $service->scheduled();
        kiwi_assert_same('error', $first['result'] ?? '', 'Expected the first failed marker write to fail closed.');
        kiwi_assert_same('raised', $first['incident_action'] ?? '', 'Expected the first persisted corruption action.');

        $current_time = new DateTimeImmutable(
            '2026-07-28 01:30:00',
            new DateTimeZone('Europe/Berlin')
        );
        $repeated = $service->scheduled();

        kiwi_assert_same('corruption_detected', $repeated['result'] ?? '', 'Expected the later marker retry to complete.');
        kiwi_assert_same(
            'repeated',
            $repeated['incident_action'] ?? '',
            'Expected command JSON to preserve the actual repeated corruption action.'
        );
        kiwi_assert_same(
            ['raised', 'repeated'],
            array_column(array_values($events->rows), 'lifecycle_action'),
            'Expected append-only corruption lifecycle across Berlin dates.'
        );
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service marks current annual corruption as active generation', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_annual_active_generation');
    $now = new DateTimeImmutable('2026-01-02 01:30:00', new DateTimeZone('Europe/Berlin'));
    $events = new Kiwi_Test_Operational_Event_Repository();
    $calls = 0;
    [$service, $archive_service] = kiwi_test_health_service(
        $root,
        $now,
        static function () use (&$calls): array {
            $calls++;

            return [
                'result' => $calls === 3 ? 'corruption_detected' : 'ok',
                'reason_code' => $calls === 3 ? 'sqlite_check_reported_corruption' : 'sqlite_check_ok',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        },
        $events
    );

    try {
        kiwi_test_create_retention_archive($archive_service, 'kiwi_retention_archive_2025.sqlite');
        $active_archive = kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2026.sqlite'
        );
        $service->scheduled();
        $service->scheduled();
        $result = $service->scheduled();
        $corruption_events = array_values(array_filter(
            $events->rows,
            static function (array $event): bool {
                return (string) ($event['event_type'] ?? '') === 'retention_archive_corruption_detected';
            }
        ));
        $event = $corruption_events[0] ?? [];
        $context = json_decode((string) ($event['context_json'] ?? ''), true);

        kiwi_assert_same('corruption_detected', $result['result'] ?? '', 'Expected current annual archive corruption result.');
        kiwi_assert_true(
            $archive_service->is_quarantine_reconciled($active_archive),
            'Expected the durable annual corruption state to acknowledge the quarantine marker immediately.'
        );
        kiwi_assert_same(
            true,
            $context['active_generation'] ?? false,
            'Expected annual incident to identify the latest current-year generation as active.'
        );
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service reconciles quarantined daily generation', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_quarantine_reconcile');
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    $check_calls = 0;
    $events = new Kiwi_Test_Flaky_Operational_Event_Repository();
    [$service, $archive_service] = kiwi_test_health_service(
        $root,
        $now,
        static function () use (&$check_calls): array {
            $check_calls++;

            return [
                'result' => 'ok',
                'reason_code' => 'unexpected_check',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        },
        $events
    );

    try {
        $archive_name = 'kiwi_retention_archive_2026.sqlite';
        $archive_path = kiwi_test_create_retention_archive(
            $archive_service,
            $archive_name
        );
        $event_service = new Kiwi_Operational_Event_Service($events);
        kiwi_assert_true($event_service->record_failure([
            'area' => 'retention',
            'severity' => 'error',
            'event_type' => 'retention_archive_health_check_incomplete',
            'correlation_key' => 'retention_archive_health_incomplete_' . hash('sha256', $archive_name),
            'idempotency_key' => 'retention_archive_health_quarantine_incomplete_test',
            'reference_type' => 'retention_archive',
            'reference_id' => $archive_name,
            'message' => 'Synthetic incomplete health cycle before quarantine reconciliation.',
        ]), 'Expected incomplete incident fixture.');
        kiwi_assert_true($archive_service->mark_quarantined($archive_path, [
            'detected_at' => '2026-07-27T01:25:00+02:00',
            'check' => 'quick',
            'reason_code' => 'sqlite_check_reported_corruption',
            'active_generation' => true,
        ]), 'Expected crash-window quarantine marker fixture.');
        $events->fail_next_insert = true;

        $failed = $service->scheduled();
        $failed_marker = json_decode(
            (string) file_get_contents($archive_service->get_quarantine_marker_path($archive_path)),
            true
        );

        kiwi_assert_same('error', $failed['result'] ?? '', 'Expected failed corruption Incident retry to keep reconciliation incomplete.');
        kiwi_assert_same('corruption_incident_reconciliation_failed', $failed['reason_code'] ?? '', 'Expected explicit Incident retry failure.');
        kiwi_assert_true(
            is_array($failed_marker)
                && trim((string) ($failed_marker['controller_recorded_at'] ?? '')) === '',
            'Expected marker to remain unacknowledged until the corruption Incident is persisted.'
        );
        $events->fail_next_insert_event_type = 'retention_archive_health_check_incomplete';
        $recovery_failed = $service->scheduled();
        $recovery_failed_marker = json_decode(
            (string) file_get_contents($archive_service->get_quarantine_marker_path($archive_path)),
            true
        );
        $result = $service->scheduled();
        $status = $service->status();
        $latest = $events->find_latest_by_correlation_key(
            'retention_archive_health_incomplete_' . hash('sha256', $archive_name)
        );

        kiwi_assert_same('error', $recovery_failed['result'] ?? '', 'Expected failed incomplete-Incident recovery to keep reconciliation incomplete.');
        kiwi_assert_same(
            'incomplete_recovery_persist_failed',
            $recovery_failed['reason_code'] ?? '',
            'Expected explicit retryable recovery persistence reason.'
        );
        kiwi_assert_true(
            is_array($recovery_failed_marker)
                && trim((string) ($recovery_failed_marker['controller_recorded_at'] ?? '')) === '',
            'Expected marker to remain unacknowledged until incomplete-Incident recovery persists.'
        );
        kiwi_assert_same('no_work', $result['result'] ?? '', 'Expected the retry to finish marker recovery before normal no-work scheduling.');
        kiwi_assert_same(0, $check_calls, 'Expected reconciliation not to rerun a check against the quarantined generation.');
        kiwi_assert_same(
            $archive_name,
            $status['state']['daily']['archive'] ?? '',
            'Expected reconciled daily state to retain the quarantined generation.'
        );
        kiwi_assert_same('corruption_detected', $status['state']['daily']['result'] ?? '', 'Expected durable corruption result.');
        kiwi_assert_same(
            'sqlite_check_reported_corruption',
            $status['state']['daily']['reason_code'] ?? '',
            'Expected durable marker reason after reconciliation.'
        );
        kiwi_assert_true(
            $archive_service->is_quarantine_reconciled($archive_path),
            'Expected marker acknowledgement only after incomplete-Incident recovery persisted.'
        );
        kiwi_assert_same('resolved', $latest['lifecycle_action'] ?? '', 'Expected retry to resolve the incomplete Incident.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service reports actual action for acknowledged daily quarantine', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_acknowledged_quarantine_action');
    $current_time = new DateTimeImmutable(
        '2026-07-27 01:30:00',
        new DateTimeZone('Europe/Berlin')
    );
    $check_calls = 0;
    $events = new Kiwi_Test_Operational_Event_Repository();
    $config = new Kiwi_Test_Retention_Archive_Health_Config($root);
    $archive_service = new Kiwi_Retention_Sqlite_Archive_Service($config);
    $event_service = new Kiwi_Operational_Event_Service($events);
    $service = new Kiwi_Retention_Archive_Health_Service(
        $config,
        $archive_service,
        new Kiwi_Retention_Archive_Lock(),
        $event_service,
        static function () use (&$current_time): DateTimeImmutable {
            return $current_time;
        },
        static function () use (&$check_calls): array {
            $check_calls++;

            return [
                'result' => 'ok',
                'reason_code' => 'unexpected_check',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        },
        '',
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    try {
        $archive_name = 'kiwi_retention_archive_2026.sqlite';
        $archive_path = kiwi_test_create_retention_archive(
            $archive_service,
            $archive_name
        );
        kiwi_assert_true($event_service->record_failure([
            'area' => 'retention',
            'severity' => 'critical',
            'event_type' => 'retention_archive_corruption_detected',
            'correlation_key' => 'retention_archive_corruption_' . hash('sha256', $archive_name),
            'idempotency_key' => 'retention_archive_acknowledged_corruption_test',
            'reference_type' => 'retention_archive',
            'reference_id' => $archive_name,
            'message' => 'Synthetic acknowledged corruption Incident.',
        ]), 'Expected corruption Incident fixture.');
        kiwi_assert_true($event_service->record_failure([
            'area' => 'retention',
            'severity' => 'error',
            'event_type' => 'retention_archive_health_check_incomplete',
            'correlation_key' => 'retention_archive_health_incomplete_' . hash('sha256', $archive_name),
            'idempotency_key' => 'retention_archive_acknowledged_incomplete_test',
            'reference_type' => 'retention_archive',
            'reference_id' => $archive_name,
            'message' => 'Synthetic incomplete Incident before acknowledged quarantine.',
        ]), 'Expected incomplete Incident fixture.');
        kiwi_assert_true($archive_service->mark_quarantined($archive_path, [
            'detected_at' => '2026-07-27T01:25:00+02:00',
            'check' => 'quick',
            'reason_code' => 'sqlite_check_reported_corruption',
            'active_generation' => true,
        ]), 'Expected quarantine marker fixture.');
        kiwi_assert_true(
            $archive_service->mark_quarantine_reconciled(
                $archive_path,
                '2026-07-27T01:26:00+02:00'
            ),
            'Expected acknowledged quarantine marker fixture.'
        );

        $resolved = $service->scheduled();
        kiwi_assert_same('corruption_detected', $resolved['result'] ?? '', 'Expected acknowledged quarantine result.');
        kiwi_assert_same(
            'resolved',
            $resolved['incident_action'] ?? '',
            'Expected command JSON to report the actual incomplete-Incident resolution.'
        );

        $current_time = new DateTimeImmutable(
            '2026-07-28 01:30:00',
            new DateTimeZone('Europe/Berlin')
        );
        $unchanged = $service->scheduled();
        kiwi_assert_same('corruption_detected', $unchanged['result'] ?? '', 'Expected acknowledged quarantine to remain visible.');
        kiwi_assert_same(
            null,
            $unchanged['incident_action'] ?? null,
            'Expected no fabricated Incident action when no lifecycle transition occurred.'
        );
        kiwi_assert_same(0, $check_calls, 'Expected no SQLite check against the quarantined generation.');
        kiwi_assert_same(
            ['raised', 'raised', 'resolved'],
            array_column(array_values($events->rows), 'lifecycle_action'),
            'Expected only the real incomplete-Incident resolution after the two fixtures.'
        );
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service reconciles predecessor quarantine before active successor', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_predecessor_quarantine');
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    $check_calls = 0;
    $events = new Kiwi_Test_Operational_Event_Repository();
    $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
    [$service, $archive_service] = kiwi_test_health_service(
        $root,
        $now,
        static function () use (&$check_calls): array {
            $check_calls++;

            return [
                'result' => 'ok',
                'reason_code' => 'sqlite_check_ok',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        },
        $events,
        $runs
    );

    try {
        $predecessor_name = 'kiwi_retention_archive_2026.sqlite';
        $predecessor = kiwi_test_create_retention_archive($archive_service, $predecessor_name);
        kiwi_assert_true($archive_service->mark_quarantined($predecessor, [
            'detected_at' => '2026-07-27T01:25:00+02:00',
            'check' => 'quick',
            'reason_code' => 'sqlite_check_reported_corruption',
        ]), 'Expected unreconciled predecessor quarantine marker fixture.');
        $successor = kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2026_part_2.sqlite'
        );
        $runs->rows[1] = [
            'status' => 'running',
            'finished_at' => null,
            'archive_db_path' => $successor,
            'archived_rows' => 1,
            'deleted_rows' => 1,
            'archive_last_primary_key' => 1,
            'delete_last_primary_key' => 1,
        ];

        $reconciled = $service->scheduled();
        $status = $service->status();
        $marker_raw = @file_get_contents($archive_service->get_quarantine_marker_path($predecessor));
        $marker = is_string($marker_raw) ? json_decode($marker_raw, true) : null;

        kiwi_assert_same('corruption_detected', $reconciled['result'] ?? '', 'Expected predecessor marker reconciliation.');
        kiwi_assert_same($predecessor_name, $reconciled['archive'] ?? '', 'Expected predecessor to win before successor lookup.');
        kiwi_assert_same(0, $check_calls, 'Expected no successor check before predecessor reconciliation.');
        kiwi_assert_same(
            $predecessor_name,
            $status['state']['daily']['archive'] ?? '',
            'Expected durable predecessor corruption state.'
        );
        kiwi_assert_true(
            is_array($marker) && trim((string) ($marker['controller_recorded_at'] ?? '')) !== '',
            'Expected durable marker acknowledgement after controller state.'
        );

        $next_slot = $service->scheduled();
        kiwi_assert_same('annual', $next_slot['scope'] ?? '', 'Expected the next free slot to enter the annual campaign.');
        kiwi_assert_same(
            basename($successor),
            $next_slot['archive'] ?? '',
            'Expected acknowledged predecessor not to reconcile repeatedly.'
        );
        kiwi_assert_same(1, $check_calls, 'Expected successor check only after predecessor reconciliation.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service diagnose is exact-name scoped and lock aware', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_diagnose');
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    [$service, $archive_service] = kiwi_test_health_service(
        $root,
        $now,
        static function (): array {
            return [
                'result' => 'ok',
                'reason_code' => 'sqlite_check_ok',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        }
    );

    try {
        $path = kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2026.sqlite'
        );
        $invalid = $service->diagnose('../kiwi_retention_archive_2026.sqlite', 'quick');
        kiwi_assert_same('diagnose_input_invalid', $invalid['reason_code'] ?? '', 'Expected traversal input rejection.');

        $locks = new Kiwi_Retention_Archive_Lock();
        $held = $locks->acquire_for_archive($path);
        $deferred = $service->diagnose(basename($path), 'quick');
        kiwi_assert_same('deferred', $deferred['result'] ?? '', 'Expected diagnose to share the non-blocking generation lock.');
        kiwi_assert_same(1, $deferred['exit_code'] ?? 0, 'Expected lock deferral exit code 1.');
        $locks->release($held['handle'] ?? null);
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service supervises read-only child and reaps timeout', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_child');
    $config = new Kiwi_Test_Retention_Archive_Health_Config($root, 1);
    $archive_service = new Kiwi_Retention_Sqlite_Archive_Service($config);
    $path = kiwi_test_create_retention_archive(
        $archive_service,
        'kiwi_retention_archive_2026.sqlite'
    );
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));

    try {
        if (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $service = new Kiwi_Retention_Archive_Health_Service(
                $config,
                $archive_service,
                new Kiwi_Retention_Archive_Lock(),
                new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
                static function () use ($now): DateTimeImmutable {
                    return $now;
                }
            );
            $quick = $service->diagnose(basename($path), 'quick');
            kiwi_assert_same('ok', $quick['result'] ?? '', 'Expected supervised read-only quick_check to pass.');
            kiwi_assert_same('quick_check', $quick['check'] ?? '', 'Expected normalized public quick_check value.');
            kiwi_assert_same(false, $quick['child_running'] ?? true, 'Expected completed child to be reaped.');

            $corrupt_path = $archive_service->get_archive_directory()
                . DIRECTORY_SEPARATOR
                . 'kiwi_retention_archive_2026_part_2.sqlite';
            kiwi_assert_true(
                file_put_contents($corrupt_path, 'definitively-not-sqlite') !== false,
                'Expected malformed SQLite archive fixture.'
            );
            $corruption = $service->diagnose(basename($corrupt_path), 'quick');
            kiwi_assert_same(
                'corruption_detected',
                $corruption['result'] ?? '',
                'Expected definitive SQLite format exception to be treated as corruption.'
            );
            kiwi_assert_same(
                'sqlite_check_reported_corruption',
                $corruption['reason_code'] ?? '',
                'Expected normalized definitive corruption reason.'
            );
        }

        $timeout_service = new Kiwi_Retention_Archive_Health_Service(
            $config,
            $archive_service,
            new Kiwi_Retention_Archive_Lock(),
            new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
            static function () use ($now): DateTimeImmutable {
                return $now;
            },
            null,
            __DIR__ . DIRECTORY_SEPARATOR . 'fixtures'
                . DIRECTORY_SEPARATOR . 'retention-archive-health-sleep-child.php'
        );
        $timeout = $timeout_service->diagnose(basename($path), 'quick');
        kiwi_assert_same(
            'inconclusive',
            $timeout['result'] ?? '',
            'Expected timed-out child to be inconclusive: ' . json_encode($timeout)
        );
        kiwi_assert_same('health_child_timeout', $timeout['reason_code'] ?? '', 'Expected explicit timeout reason.');
        kiwi_assert_same('quick_check', $timeout['check'] ?? '', 'Expected timed-out child marker to prove the check started.');
        kiwi_assert_same(false, $timeout['child_running'] ?? true, 'Expected timed-out child to be killed and reaped.');
        kiwi_assert_same(1, $timeout['exit_code'] ?? 0, 'Expected timeout exit code 1.');
        $readiness_files = glob(
            $archive_service->get_archive_directory()
            . DIRECTORY_SEPARATOR
            . '.kiwi_retention_health_child_*.ready'
        );
        kiwi_assert_same(
            [],
            is_array($readiness_files) ? $readiness_files : [],
            'Expected reaped child readiness markers to be removed.'
        );

        $exit_mismatch_service = new Kiwi_Retention_Archive_Health_Service(
            $config,
            $archive_service,
            new Kiwi_Retention_Archive_Lock(),
            new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
            static function () use ($now): DateTimeImmutable {
                return $now;
            },
            null,
            __DIR__ . DIRECTORY_SEPARATOR . 'fixtures'
                . DIRECTORY_SEPARATOR . 'retention-archive-health-exit-mismatch-child.php'
        );
        $exit_mismatch = $exit_mismatch_service->diagnose(basename($path), 'quick');
        kiwi_assert_same('error', $exit_mismatch['result'] ?? '', 'Expected mismatched child exit to fail closed.');
        kiwi_assert_same(
            'health_child_exit_invalid',
            $exit_mismatch['reason_code'] ?? '',
            'Expected explicit child exit mismatch reason.'
        );
        kiwi_assert_same(2, $exit_mismatch['exit_code'] ?? 0, 'Expected child exit mismatch exit code 2.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi retention archive health child encodes URI-significant archive paths', function (): void {
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }

    $root = kiwi_create_temp_directory('kiwi_retention_health_uri_path');
    $special_root = $root
        . DIRECTORY_SEPARATOR
        . (DIRECTORY_SEPARATOR === '\\' ? 'archive # fragment' : 'archive ?# fragment');
    if (!mkdir($special_root, 0770, true) && !is_dir($special_root)) {
        throw new RuntimeException('Unable to create URI-significant archive test directory.');
    }
    $config = new Kiwi_Test_Retention_Archive_Health_Config($special_root);
    $archive_service = new Kiwi_Retention_Sqlite_Archive_Service($config);
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    $service = new Kiwi_Retention_Archive_Health_Service(
        $config,
        $archive_service,
        new Kiwi_Retention_Archive_Lock(),
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        static function () use ($now): DateTimeImmutable {
            return $now;
        }
    );

    try {
        kiwi_test_create_retention_archive(
            $archive_service,
            'kiwi_retention_archive_2026.sqlite'
        );
        $result = $service->diagnose('kiwi_retention_archive_2026.sqlite', 'quick');

        kiwi_assert_same('ok', $result['result'] ?? '', 'Expected read-only child to inspect the real URI-significant archive path.');
        kiwi_assert_same('sqlite_check_ok', $result['reason_code'] ?? '', 'Expected successful SQLite check for encoded path.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service preflight exposes safe environment result', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_preflight');
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    $archive_service = null;
    [$service, $archive_service] = kiwi_test_health_service(
        $root,
        $now,
        static function (string $scratch_path) use (&$archive_service): array {
            if (!$archive_service instanceof Kiwi_Retention_Sqlite_Archive_Service) {
                throw new RuntimeException('Archive service was not available to the preflight runner.');
            }
            kiwi_assert_true(
                dirname($scratch_path) !== $archive_service->get_archive_directory(),
                'Expected preflight SQLite to stay outside the discoverable archive root.'
            );
            kiwi_assert_true(
                strpos(
                    basename(dirname($scratch_path)),
                    '.kiwi_retention_health_preflight_'
                ) === 0,
                'Expected a hidden, unique preflight scratch directory.'
            );
            kiwi_assert_same(
                ['kiwi_retention_archive_2026.sqlite'],
                array_column($archive_service->list_archive_files(), 'name'),
                'Expected concurrent archive discovery to exclude the preflight SQLite file.'
            );

            return [
                'result' => 'ok',
                'reason_code' => 'sqlite_check_ok',
                'duration_seconds' => 0.01,
                'child_running' => false,
            ];
        }
    );
    kiwi_test_create_retention_archive(
        $archive_service,
        'kiwi_retention_archive_2026.sqlite'
    );

    try {
        $result = $service->preflight();
        if (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            kiwi_assert_same(
                'ok',
                $result['result'] ?? '',
                'Expected complete preflight with PDO SQLite: ' . json_encode($result)
            );
            kiwi_assert_same(0, $result['exit_code'] ?? -1, 'Expected successful preflight exit code 0.');
            kiwi_assert_same(
                true,
                $result['checks']['cross_process_non_blocking_lock'] ?? false,
                'Expected preflight to verify real second-process lock contention.'
            );
            kiwi_assert_same(
                true,
                $result['checks']['timeout_kill_reap'] ?? false,
                'Expected preflight to terminate and reap its supervised lock child.'
            );
            kiwi_assert_same(
                true,
                $result['checks']['lock_released_after_child_termination'] ?? false,
                'Expected child termination to release the OS lock.'
            );
            kiwi_assert_same(
                [],
                glob(
                    $archive_service->get_archive_directory()
                    . DIRECTORY_SEPARATOR
                    . '.kiwi_retention_health_preflight_*'
                ) ?: [],
                'Expected preflight scratch cleanup after the child exits.'
            );
        } else {
            kiwi_assert_same('error', $result['result'] ?? '', 'Expected missing PDO SQLite to fail preflight.');
            kiwi_assert_same('preflight_api_unavailable', $result['reason_code'] ?? '', 'Expected explicit preflight dependency reason.');
            kiwi_assert_same(2, $result['exit_code'] ?? 0, 'Expected failed preflight exit code 2.');
        }
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Kiwi_Retention_Cleanup_Run_Repository creates one deterministic quarantine successor atomically', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = new Kiwi_Test_Wpdb_Retention_Quarantine_Transition();
    $wpdb->rows[1] = [
        'id' => 1,
        'run_id' => 'retention_original',
        'source_key' => 'landing_page_sessions',
        'source_table' => 'wp_kiwi_landing_page_sessions',
        'status' => 'running',
        'triggered_by' => 'cron',
        'enabled' => 1,
        'dry_run' => 0,
        'retention_days_effective' => 14,
        'cutoff_column' => 'created_at',
        'cutoff_value' => '2026-07-01 00:00:00',
        'gate_status' => 'passed',
        'gate_results_json' => '{}',
        'target_max_primary_key' => 500,
        'archive_batch_id' => 'original_batch',
        'archive_db_path' => '/archives/kiwi_retention_archive_2026.sqlite',
    ];
    $repository = new Kiwi_Retention_Cleanup_Run_Repository();
    $new_path = '/archives/kiwi_retention_archive_2026_part_2.sqlite';
    $context = [
        'old_run_id' => 'retention_original',
        'old_archive' => 'kiwi_retention_archive_2026.sqlite',
        'new_archive' => 'kiwi_retention_archive_2026_part_2.sqlite',
        'remaining_rows' => 17,
    ];

    try {
        $first = $repository->create_quarantine_successor(1, $new_path, 17, $context);
        $second = $repository->create_quarantine_successor(1, $new_path, 17, $context);
        $mismatched = $repository->create_quarantine_successor(
            1,
            $new_path,
            17,
            array_merge($context, ['new_archive' => 'kiwi_retention_archive_2026_part_3.sqlite'])
        );
        kiwi_assert_true(is_array($first) && is_array($second), 'Expected deterministic successor creation and lookup.');
        kiwi_assert_same(null, $mismatched, 'Expected mismatched successor scope to roll back fail-closed.');
        kiwi_assert_same($first['run_id'] ?? '', $second['run_id'] ?? '', 'Expected repeat call to return the same successor.');
        kiwi_assert_same(2, count($wpdb->rows), 'Expected no duplicate successor row.');
        kiwi_assert_same('failed', $wpdb->rows[1]['status'] ?? '', 'Expected quarantined source run to become terminal.');
        kiwi_assert_same('archive_quarantined', $wpdb->rows[1]['worker_phase'] ?? '', 'Expected explicit old-run quarantine phase.');
        kiwi_assert_same('pending', $first['status'] ?? '', 'Expected successor to remain schedulable.');
        kiwi_assert_same('archive_recovery', $first['triggered_by'] ?? '', 'Expected normalized recovery trigger.');
        kiwi_assert_same(17, $first['eligible_rows'] ?? -1, 'Expected remaining source row count on successor.');
        kiwi_assert_same($new_path, $first['archive_db_path'] ?? '', 'Expected successor generation path.');
    } finally {
        $wpdb = $previous_wpdb;
    }
});

kiwi_run_test('Kiwi retention archive health runner declares command surface and one-line matrix', function (): void {
    $runner = file_get_contents(
        dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . 'tools'
        . DIRECTORY_SEPARATOR
        . 'database'
        . DIRECTORY_SEPARATOR
        . 'kiwi-retention-archive-health.php'
    );
    $runner = is_string($runner) ? $runner : '';

    foreach (['scheduled', 'status', 'diagnose', 'preflight'] as $command) {
        kiwi_assert_contains(
            'public function ' . $command,
            $runner,
            'Expected WP-CLI archive-health command: ' . $command
        );
    }
    kiwi_assert_contains(
        "'kiwi retention archive-health'",
        $runner,
        'Expected namespaced WP-CLI archive-health registration.'
    );
    kiwi_assert_contains(
        "strpos(\$json, \"\\n\")",
        $runner,
        'Expected exactly-one-JSON-line output guard.'
    );
    kiwi_assert_contains(
        'Kiwi_Retention_Archive_Health_Bootstrap_Recorder',
        $runner,
        'Expected pre-service scheduled failures to have a dependency-independent fallback.'
    );
    kiwi_assert_contains(
        'record_scheduled_bootstrap_failure',
        $runner,
        'Expected the complete health graph to retain its shared durable daily attempt path.'
    );
    kiwi_assert_contains(
        "'wordpress_load_failed'",
        $runner,
        'Expected WordPress loader exceptions to enter the scheduled bootstrap audit path.'
    );
    kiwi_assert_contains(
        "\$this->fail_before_service(\$mode, 'health_service_exception')",
        $runner,
        'Expected scheduled service exceptions to enter the durable bootstrap audit path.'
    );

    $health_service = file_get_contents(
        dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . 'includes'
        . DIRECTORY_SEPARATOR
        . 'services'
        . DIRECTORY_SEPARATOR
        . 'class-retention-archive-health-service.php'
    );
    $health_service = is_string($health_service) ? $health_service : '';
    kiwi_assert_contains(
        'private function close_process_if_stopped',
        $health_service,
        'Expected a bounded process-close guard.'
    );
    kiwi_assert_same(
        1,
        substr_count($health_service, 'proc_close('),
        'Expected proc_close only inside the stopped-process guard.'
    );
    kiwi_assert_contains(
        'acquire_shared_for_archive',
        $health_service,
        'Expected the health controller to use a shared generation lock.'
    );
    kiwi_assert_contains(
        '.kiwi_retention_health_child_',
        $health_service,
        'Expected non-blocking child lock-readiness observation.'
    );
});

kiwi_run_test('Kiwi_Retention_Cleanup_Service reconciles all six archive receipt delete crash windows', function (): void {
    $test_root = kiwi_create_temp_directory('kiwi_retention_crash_windows');
    $archive_path = $test_root
        . DIRECTORY_SEPARATOR
        . 'kiwi_retention_archive_2026.sqlite';
    $cases = [
        [
            'name' => 'before_archive_commit',
            'archive_last' => 0,
            'delete_last' => 0,
            'archived_rows' => 0,
            'deleted_rows' => 0,
            'existing' => [1, 2],
            'new_chunk' => true,
        ],
        [
            'name' => 'after_archive_commit_before_receipt_read',
            'archive_last' => 0,
            'delete_last' => 0,
            'archived_rows' => 0,
            'deleted_rows' => 0,
            'existing' => [1, 2],
            'new_chunk' => true,
        ],
        [
            'name' => 'after_receipt_audit_before_delete',
            'archive_last' => 2,
            'delete_last' => 0,
            'archived_rows' => 2,
            'deleted_rows' => 0,
            'existing' => [1, 2],
            'new_chunk' => false,
        ],
        [
            'name' => 'during_delete',
            'archive_last' => 2,
            'delete_last' => 0,
            'archived_rows' => 2,
            'deleted_rows' => 0,
            'existing' => [2],
            'new_chunk' => false,
        ],
        [
            'name' => 'after_delete_before_audit',
            'archive_last' => 2,
            'delete_last' => 0,
            'archived_rows' => 2,
            'deleted_rows' => 0,
            'existing' => [],
            'new_chunk' => false,
        ],
        [
            'name' => 'after_delete_audit',
            'archive_last' => 2,
            'delete_last' => 2,
            'archived_rows' => 2,
            'deleted_rows' => 2,
            'existing' => [],
            'new_chunk' => false,
        ],
    ];

    foreach ($cases as $case) {
        global $wpdb;
        $previous_wpdb = $wpdb ?? null;
        $wpdb = (object) ['prefix' => 'wp_'];
        $GLOBALS['kiwi_test_transients'] = [];
        $GLOBALS['kiwi_test_deleted_transients'] = [];

        $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
        $runs->rows[1] = [
            'id' => 1,
            'run_id' => 'crash_' . $case['name'],
            'source_key' => 'landing_page_sessions',
            'source_table' => 'wp_kiwi_landing_page_sessions',
            'status' => $case['archive_last'] > 0 ? 'running' : 'pending',
            'triggered_by' => 'cron',
            'enabled' => 1,
            'dry_run' => 0,
            'retention_days_effective' => 14,
            'cutoff_value' => '2026-07-01 00:00:00',
            'eligible_rows' => 2,
            'archived_rows' => $case['archived_rows'],
            'archive_inserted_rows' => $case['archived_rows'],
            'archive_duplicate_rows' => 0,
            'deleted_rows' => $case['deleted_rows'],
            'delete_batches' => $case['deleted_rows'] > 0 ? 1 : 0,
            'gate_status' => 'passed',
            'worker_phase' => $case['archive_last'] > $case['delete_last']
                ? 'receipt_verified'
                : 'archive_pending',
            'target_max_primary_key' => 2,
            'archive_last_primary_key' => $case['archive_last'],
            'delete_last_primary_key' => $case['delete_last'],
            'worker_runs' => 0,
            'archive_batch_id' => 'crash_batch_' . $case['name'],
            'archive_db_path' => $archive_path,
            'archive_integrity_check' => $case['archive_last'] > 0 ? 'receipt_verified' : '',
            'error_code' => '',
            'error_message' => '',
            'finished_at' => null,
        ];
        $archive = new Kiwi_Test_Retention_Sqlite_Archive_Service();
        $archive->archived_primary_keys = [1, 2];
        if ($case['new_chunk']) {
            $archive->chunks[] = [
                'success' => true,
                'archive_db_path' => $archive_path,
                'archived_rows' => 2,
                'archive_inserted_rows' => $case['name'] === 'before_archive_commit' ? 2 : 0,
                'archive_duplicate_rows' => $case['name'] === 'before_archive_commit' ? 0 : 2,
                'archived_primary_keys' => [1, 2],
                'last_primary_key' => 2,
                'has_more' => false,
                'receipt_status' => 'pending_verification',
            ];
        } else {
            $archive->verified_receipt_batches[] = [
                'success' => true,
                'primary_keys' => [1, 2],
                'last_primary_key' => 2,
                'has_more' => false,
                'error_code' => '',
                'error_message' => '',
            ];
            $archive->chunks[] = [
                'success' => true,
                'archive_db_path' => $archive_path,
                'archived_rows' => 0,
                'archive_inserted_rows' => 0,
                'archive_duplicate_rows' => 0,
                'archived_primary_keys' => [],
                'last_primary_key' => 2,
                'has_more' => false,
                'receipt_status' => 'pending_verification',
            ];
        }
        $service = new Kiwi_Test_Retention_Cleanup_Service(
            new Kiwi_Config(),
            new Kiwi_Retention_Source_Registry(),
            $runs,
            new Kiwi_Test_Retention_Table_Growth_Snapshot_Repository(),
            $archive,
            new Kiwi_Test_Retention_Coverage_Gate(['status' => 'passed'])
        );
        $service->existing_primary_keys = $case['existing'];
        $service->delete_result = ['deleted_rows' => count($case['existing'])];

        try {
            $result = $service->run_worker('landing_page_sessions');
            kiwi_assert_same('completed', $result['status'] ?? '', 'Expected crash recovery completion for ' . $case['name']);
            kiwi_assert_same(2, $runs->rows[1]['archived_rows'] ?? 0, 'Expected no double archive audit count for ' . $case['name']);
            kiwi_assert_same(2, $runs->rows[1]['archive_inserted_rows'] ?? 0, 'Expected original inserted-row attribution for ' . $case['name']);
            kiwi_assert_same(0, $runs->rows[1]['archive_duplicate_rows'] ?? -1, 'Expected replay not to inflate duplicate audit count for ' . $case['name']);
            kiwi_assert_same(2, $runs->rows[1]['deleted_rows'] ?? 0, 'Expected logical receipt delete count for ' . $case['name']);
            kiwi_assert_same(2, $runs->rows[1]['delete_last_primary_key'] ?? 0, 'Expected reconciled delete cursor for ' . $case['name']);
            kiwi_assert_same(
                $case['existing'],
                $service->deleted_primary_keys,
                'Expected delete only for still-present receipt rows in ' . $case['name']
            );
        } finally {
            $wpdb = $previous_wpdb;
        }
    }
    kiwi_remove_directory($test_root);
});
