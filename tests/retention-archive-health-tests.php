<?php

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

class Kiwi_Test_Flaky_Operational_Event_Repository extends Kiwi_Test_Operational_Event_Repository
{
    public $fail_next_insert = false;

    public function insert_event(array $event): int
    {
        if ($this->fail_next_insert) {
            $this->fail_next_insert = false;

            return 0;
        }

        return parent::insert_event($event);
    }
}

class Kiwi_Test_Failing_Open_Archive_Run_Repository extends Kiwi_Test_Retention_Cleanup_Run_Repository
{
    public function find_open_archive_db_path(): ?string
    {
        return null;
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

kiwi_run_test('Kiwi_Retention_Archive_Lock is shared and non-blocking per generation', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_archive_lock');
    $config = new Kiwi_Test_Retention_Archive_Health_Config($root);
    $archive_service = new Kiwi_Retention_Sqlite_Archive_Service($config);
    $path = kiwi_test_create_retention_archive(
        $archive_service,
        'kiwi_retention_archive_2026.sqlite'
    );
    $locks = new Kiwi_Retention_Archive_Lock();

    try {
        $first = $locks->acquire_for_archive($path);
        $second = $locks->acquire_for_archive($path);
        kiwi_assert_true(!empty($first['success']) && !empty($first['acquired']), 'Expected first generation lock acquisition.');
        kiwi_assert_true(!empty($second['success']) && empty($second['acquired']), 'Expected concurrent generation lock to defer without blocking.');

        $locks->release($first['handle'] ?? null);
        $third = $locks->acquire_for_archive($path);
        kiwi_assert_true(!empty($third['success']) && !empty($third['acquired']), 'Expected released generation lock to be reacquired.');
        $locks->release($third['handle'] ?? null);
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
    $runs->quarantine_successor_result = [
        'id' => 2,
        'run_id' => 'quarantine_race_successor',
        'triggered_by' => 'archive_recovery',
        'archive_db_path' => $new_archive_path,
        'error_code' => 'archive_recovery_pending',
        'error_message' => json_encode($context),
    ];
    $archive = new Kiwi_Test_Retention_Sqlite_Archive_Service();
    $archive->new_archive_db_path = $new_archive_path;
    $archive->quarantine_results = [true];
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

    try {
        $result = $service->run_worker('landing_page_sessions');
        $reacquired = $lock_service->acquire_for_archive($old_archive_path);

        kiwi_assert_same('pending', $result['status'] ?? '', 'Expected deterministic successor scheduling.');
        kiwi_assert_same(true, $result['schedule_worker'] ?? false, 'Expected successor worker scheduling.');
        kiwi_assert_same([], $archive->chunk_calls, 'Expected no archive write after locked quarantine recheck.');
        kiwi_assert_same([], $service->deleted_primary_keys, 'Expected no source delete after quarantine detection.');
        kiwi_assert_same(1, count($runs->quarantine_successor_calls), 'Expected one atomic successor transition.');
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

kiwi_run_test('Kiwi_Retention_Archive_Health_Service status is read-only and rejects invalid state', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_status');
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    [$service, $archive_service] = kiwi_test_health_service(
        $root,
        $now,
        static function (): array {
            throw new RuntimeException('Status must not run a check.');
        }
    );
    $state_path = $archive_service->get_archive_directory()
        . DIRECTORY_SEPARATOR
        . 'kiwi_retention_archive_health_state.json';

    try {
        $status = $service->status();
        kiwi_assert_same('ok', $status['result'] ?? '', 'Expected read-only status without an existing state file.');
        kiwi_assert_same(false, $status['state_exists'] ?? true, 'Expected status to report absent state.');
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

kiwi_run_test('Kiwi_Retention_Archive_Health_Service schedules weekday quick and Sunday integrity checks', function (): void {
    foreach ([
        ['2026-07-27 01:30:00', 'quick'],
        ['2026-08-02 01:30:00', 'integrity'],
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
            kiwi_assert_same($case[1], $result['check'] ?? '', 'Expected Europe/Berlin weekday check selection.');
            kiwi_assert_same($case[1], $calls[0]['check'] ?? '', 'Expected child check to receive selected check type.');
            kiwi_assert_same(0, $result['exit_code'] ?? -1, 'Expected successful scheduled exit code 0.');
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

kiwi_run_test('Kiwi_Retention_Archive_Health_Service fails closed on unsafe active archive resolution', function (): void {
    $now = new DateTimeImmutable('2027-01-01 01:30:00', new DateTimeZone('Europe/Berlin'));
    $cases = [
        [
            'name' => 'lookup_failure',
            'runs' => new Kiwi_Test_Failing_Open_Archive_Run_Repository(),
            'reason_code' => 'active_archive_lookup_failed',
        ],
        [
            'name' => 'invalid_path',
            'runs' => new Kiwi_Test_Retention_Cleanup_Run_Repository(),
            'reason_code' => 'active_archive_path_invalid',
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
            if ($case['name'] === 'invalid_path') {
                $runs->rows[1] = [
                    'id' => 1,
                    'status' => 'running',
                    'finished_at' => null,
                    'archive_db_path' => $root
                        . DIRECTORY_SEPARATOR
                        . 'kiwi_retention_archive_2026.sqlite',
                ];
            }

            $result = $service->scheduled();

            kiwi_assert_same('error', $result['result'] ?? '', 'Expected unsafe active archive resolution to fail.');
            kiwi_assert_same($case['reason_code'], $result['reason_code'] ?? '', 'Expected explicit active archive failure reason.');
            kiwi_assert_same(0, $check_calls, 'Expected no SQLite child against a guessed archive.');
        } finally {
            kiwi_remove_directory($root);
        }
    }
});

kiwi_run_test('Kiwi_Retention_Archive_Health_Service raises incomplete incident only after third attempt', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_attempts');
    $now = new DateTimeImmutable('2026-07-27 01:30:00', new DateTimeZone('Europe/Berlin'));
    $events = new Kiwi_Test_Operational_Event_Repository();
    [$service, $archive_service] = kiwi_test_health_service(
        $root,
        $now,
        static function (): array {
            return [
                'result' => 'inconclusive',
                'reason_code' => 'health_child_timeout',
                'duration_seconds' => 600.0,
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
        $first = $service->scheduled();
        $second = $service->scheduled();
        $third = $service->scheduled();
        $fourth = $service->scheduled();
        kiwi_assert_same(1, $first['exit_code'] ?? 0, 'Expected first incomplete attempt exit code 1.');
        kiwi_assert_same(1, $second['exit_code'] ?? 0, 'Expected second incomplete attempt exit code 1.');
        kiwi_assert_same('raised', $third['incident_action'] ?? '', 'Expected third incomplete attempt to raise an incident.');
        kiwi_assert_same('daily_attempt_limit_reached', $fourth['reason_code'] ?? '', 'Expected no unbounded fourth daily attempt.');
        kiwi_assert_same(1, count($events->rows), 'Expected exactly one incomplete Operational Incident.');
        kiwi_assert_same(
            'retention_archive_health_check_incomplete',
            array_values($events->rows)[0]['event_type'] ?? '',
            'Expected central incomplete health event type.'
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

kiwi_run_test('Kiwi_Retention_Archive_Health_Service resets stale daily attempt state for no work', function (): void {
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

        $no_work = $service->scheduled();
        $status = $service->status();

        kiwi_assert_same('no_work', $no_work['result'] ?? '', 'Expected no work after the incomplete archive disappears.');
        kiwi_assert_same('ok', $status['result'] ?? '', 'Expected controller to accept its persisted no-work state.');
        kiwi_assert_same('', $status['state']['daily']['archive'] ?? 'unexpected', 'Expected no-work archive identity reset.');
        kiwi_assert_same(0, $status['state']['daily']['attempts'] ?? -1, 'Expected no-work attempt counter reset.');
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

kiwi_run_test('Kiwi_Retention_Archive_Health_Service reconciles quarantined annual snapshot entry', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_health_annual_quarantine_reconcile');
    $now = new DateTimeImmutable('2026-01-02 01:30:00', new DateTimeZone('Europe/Berlin'));
    $calls = 0;
    [$service, $archive_service] = kiwi_test_health_service(
        $root,
        $now,
        static function () use (&$calls): array {
            $calls++;

            return [
                'result' => $calls === 1 ? 'ok' : 'deferred',
                'reason_code' => $calls === 1 ? 'sqlite_check_ok' : 'archive_lock_active',
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
        $first_annual = $service->scheduled();
        kiwi_assert_same('deferred', $first_annual['result'] ?? '', 'Expected persisted annual campaign before crash fixture.');
        kiwi_assert_true($archive_service->mark_quarantined($annual_archive, [
            'detected_at' => '2026-01-02T01:35:00+01:00',
            'check' => 'integrity',
            'reason_code' => 'sqlite_check_reported_corruption',
        ]), 'Expected annual crash-window quarantine marker fixture.');

        $reconciled = $service->scheduled();
        $status = $service->status();

        kiwi_assert_same('corruption_detected', $reconciled['result'] ?? '', 'Expected annual marker reconciliation instead of skipped result.');
        kiwi_assert_same(2, $calls, 'Expected annual reconciliation not to rerun the quarantined archive check.');
        kiwi_assert_same(
            'corruption_detected',
            $status['state']['annual']['results']['kiwi_retention_archive_2025.sqlite'] ?? '',
            'Expected durable annual corruption result.'
        );
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
        kiwi_test_create_retention_archive($archive_service, 'kiwi_retention_archive_2026.sqlite');
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
        ]), 'Expected crash-window quarantine marker fixture.');
        $events->fail_next_insert = true;

        $result = $service->scheduled();
        $status = $service->status();

        kiwi_assert_same('corruption_detected', $result['result'] ?? '', 'Expected quarantine marker reconciliation instead of no work.');
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
            kiwi_assert_same(false, $quick['child_running'] ?? true, 'Expected completed child to be reaped.');
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
        kiwi_assert_same('inconclusive', $timeout['result'] ?? '', 'Expected timed-out child to be inconclusive.');
        kiwi_assert_same('health_child_timeout', $timeout['reason_code'] ?? '', 'Expected explicit timeout reason.');
        kiwi_assert_same(false, $timeout['child_running'] ?? true, 'Expected timed-out child to be killed and reaped.');
        kiwi_assert_same(1, $timeout['exit_code'] ?? 0, 'Expected timeout exit code 1.');

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
    [$service] = kiwi_test_health_service(
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
