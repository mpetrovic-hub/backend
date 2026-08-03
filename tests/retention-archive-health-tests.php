<?php

require_once __DIR__ . '/../tools/database/class-retention-archive-health-bootstrap-recorder.php';

class Kiwi_Test_Lean_Archive_Config extends Kiwi_Config
{
    public $archive_root = '';
    public $timeout_seconds = 600;

    public function get_retention_archive_root(): string
    {
        return $this->archive_root;
    }

    public function get_retention_archive_health_timeout_seconds(): int
    {
        return min(3600, max(30, $this->timeout_seconds));
    }
}

class Kiwi_Test_Lean_Archive_Service extends Kiwi_Retention_Sqlite_Archive_Service
{
    public $archives = [];
    public $resolved_archive_paths = [];

    public function __construct()
    {
    }

    public function list_archive_files(): array
    {
        return $this->archives;
    }

    public function resolve_existing_archive_db_path_read_only(string $archive_db_path): string
    {
        return $archive_db_path;
    }

    public function resolve_archive_db_path(string $existing_archive_db_path = ''): string
    {
        if ($existing_archive_db_path !== '') {
            return $existing_archive_db_path;
        }
        if (!empty($this->resolved_archive_paths)) {
            return (string) array_shift($this->resolved_archive_paths);
        }

        $highest = null;
        foreach ($this->archives as $archive) {
            $identity = Kiwi_Retention_Archive_Name::parse((string) ($archive['name'] ?? ''));
            if (!is_array($identity)
                || (is_array($highest)
                    && (int) $identity['generation'] <= (int) $highest['generation'])
            ) {
                continue;
            }
            $highest = array_merge($identity, ['path' => (string) ($archive['path'] ?? '')]);
        }

        return is_array($highest) ? (string) $highest['path'] : '';
    }
}

class Kiwi_Test_One_Second_Archive_Health_Config extends Kiwi_Config
{
    public function get_retention_archive_health_timeout_seconds(): int
    {
        return 1;
    }
}

class Kiwi_Test_Lean_Safety_Gate extends Kiwi_Retention_Corruption_Safety_Gate_Coordinator
{
    public $inspect_result = [
        'allowed' => true,
        'reason_code' => 'corruption_gate_clear',
        'write_blocked' => false,
        'incident_open' => false,
        'incident_action' => 'none',
    ];
    public $block_result = [
        'allowed' => false,
        'reason_code' => 'sqlite_check_reported_corruption',
        'write_blocked' => true,
        'incident_open' => true,
        'incident_action' => 'raised',
    ];
    public $unblock_result = [
        'allowed' => true,
        'reason_code' => 'manual_repair_unblocked',
        'write_blocked' => false,
        'incident_open' => false,
        'incident_action' => 'resolved',
    ];
    public $locked_incident_result = [
        'allowed' => false,
        'reason_code' => 'sqlite_check_reported_corruption',
        'write_blocked' => false,
        'incident_open' => true,
        'incident_action' => 'raised',
    ];
    public $inspect_results = [];
    public $inspect_calls = [];
    public $block_calls = [];
    public $locked_incident_calls = [];
    public $unblock_calls = [];

    public function __construct()
    {
    }

    public function inspect(string $archive_path, bool $reconcile = false): array
    {
        $this->inspect_calls[] = [$archive_path, $reconcile];

        if (!empty($this->inspect_results)) {
            return array_shift($this->inspect_results);
        }

        return $this->inspect_result;
    }

    public function block_after_corruption(
        string $archive_path,
        string $check,
        string $reason_code
    ): array {
        $this->block_calls[] = [$archive_path, $check, $reason_code];

        return $this->block_result;
    }

    public function record_corruption_incident_while_generation_locked(
        string $archive_path,
        string $check,
        string $reason_code
    ): array {
        $this->locked_incident_calls[] = [$archive_path, $check, $reason_code];

        return $this->locked_incident_result;
    }

    public function unblock(
        string $archive_path,
        string $replacement_archive_path = ''
    ): array {
        $this->unblock_calls[] = [$archive_path, $replacement_archive_path];

        return $this->unblock_result;
    }
}

class Kiwi_Test_Gate_Clearing_Archive_Lock extends Kiwi_Retention_Archive_Lock
{
    public $clear_before_next_acquire = '';
    public $corrupt_transition_before_next_acquire = '';

    public function acquire_for_archive(string $archive_db_path): array
    {
        if ($this->clear_before_next_acquire !== ''
            && $archive_db_path === $this->clear_before_next_acquire
        ) {
            $this->clear_before_next_acquire = '';
            Kiwi_Retention_Archive_Write_Block::clear($archive_db_path . '.lock');
        }
        if ($this->corrupt_transition_before_next_acquire !== ''
            && $archive_db_path === $this->corrupt_transition_before_next_acquire
        ) {
            $this->corrupt_transition_before_next_acquire = '';
            file_put_contents(
                Kiwi_Retention_Archive_Write_Block::get_replacement_transition_path(
                    $archive_db_path . '.lock'
                ),
                "truncated-transition-state\n"
            );
        }

        return parent::acquire_for_archive($archive_db_path);
    }
}

class Kiwi_Test_Unreadable_Operational_Event_Service extends Kiwi_Operational_Event_Service
{
    public function __construct()
    {
    }

    public function get_open_incidents(array $filters = [], int $limit = 100): ?array
    {
        return null;
    }
}

class Kiwi_Test_Manual_Replacement_Run_Repository extends Kiwi_Retention_Cleanup_Run_Repository
{
    public $terminalize_result = true;
    public $terminalize_calls = [];

    public function terminalize_open_run_for_manual_replacement(
        string $old_archive_db_path,
        string $replacement_archive_db_path
    ): bool {
        $this->terminalize_calls[] = [$old_archive_db_path, $replacement_archive_db_path];

        return $this->terminalize_result;
    }
}

class Kiwi_Test_Sequenced_Safety_Gate extends Kiwi_Retention_Corruption_Safety_Gate_Coordinator
{
    public $results = [];
    public $calls = [];

    public function __construct(array $results)
    {
        $this->results = array_values($results);
    }

    public function inspect(string $archive_path, bool $reconcile = false): array
    {
        $this->calls[] = [$archive_path, $reconcile];

        return !empty($this->results)
            ? array_shift($this->results)
            : [
                'allowed' => true,
                'reason_code' => 'corruption_gate_clear',
                'write_blocked' => false,
                'incident_open' => false,
                'incident_action' => 'none',
            ];
    }
}

function kiwi_run_retention_process(array $command): array
{
    $pipes = [];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        return ['exit_code' => -1, 'stdout' => '', 'stderr' => 'process_start_failed'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'stdout' => is_string($stdout) ? trim($stdout) : '',
        'stderr' => is_string($stderr) ? trim($stderr) : '',
    ];
}

function kiwi_run_retention_lock_probe(string $archive): array
{
    $process = kiwi_run_retention_process([
        PHP_BINARY,
        '-r',
        '$h=fopen($argv[1],"c+");$locked=is_resource($h)&&flock($h,LOCK_EX|LOCK_NB);echo json_encode(["result"=>$locked?"acquired":"deferred","reason_code"=>$locked?"lock_acquired":"archive_lock_active","sqlite_opened"=>false]);if($locked){flock($h,LOCK_UN);}if(is_resource($h)){fclose($h);}exit($locked?0:1);',
        $archive . '.lock',
    ]);
    $decoded = json_decode((string) $process['stdout'], true);
    $process['result'] = is_array($decoded) ? $decoded : [];

    return $process;
}

kiwi_run_test('Kiwi_Config preserves the bounded 600 second archive health timeout', function (): void {
    $config = new Kiwi_Config();

    kiwi_assert_same(
        600,
        $config->get_retention_archive_health_timeout_seconds(),
        'Expected the external health child timeout to remain ten minutes by default.'
    );
});

kiwi_run_test('Retention archive components share one strict archive-name contract', function (): void {
    kiwi_assert_same(
        ['name' => 'kiwi_retention_archive_2026.sqlite', 'year' => '2026', 'generation' => 1],
        Kiwi_Retention_Archive_Name::parse('kiwi_retention_archive_2026.sqlite'),
        'Expected the base generation to parse.'
    );
    kiwi_assert_same(
        ['name' => 'kiwi_retention_archive_2026_part_12.sqlite', 'year' => '2026', 'generation' => 12],
        Kiwi_Retention_Archive_Name::parse('kiwi_retention_archive_2026_part_12.sqlite'),
        'Expected an explicit later generation to parse.'
    );
    kiwi_assert_same('', Kiwi_Retention_Archive_Name::build('26', 1), 'Expected invalid years to fail.');
    kiwi_assert_same(null, Kiwi_Retention_Archive_Name::parse('../kiwi_retention_archive_2026.sqlite'), 'Expected paths to fail the basename contract.');
    kiwi_assert_same('', Kiwi_Retention_Archive_Name::normalize('../kiwi_retention_archive_2026.sqlite'), 'Expected normalization not to strip path components.');
    kiwi_assert_same(null, Kiwi_Retention_Archive_Name::parse('kiwi_retention_archive_2026_part_1.sqlite'), 'Expected redundant part one to fail.');
});

kiwi_run_test('Health and cleanup use the same exclusive non-waiting generation lock', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_archive_exclusive_lock');
    $archive = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite';
    file_put_contents($archive, 'fixture');
    $locks = new Kiwi_Retention_Archive_Lock();

    try {
        $first = $locks->acquire_for_archive($archive);
        $second = $locks->acquire_for_archive($archive);
        kiwi_assert_true(!empty($first['acquired']), 'Expected first health lock acquisition.');
        kiwi_assert_same(false, !empty($second['acquired']), 'Expected a second health invocation to defer.');
        $locks->release($first['handle'] ?? null);

        $writer = $locks->acquire_for_archive($archive);
        kiwi_assert_true(!empty($writer['acquired']), 'Expected cleanup to acquire the same lock after release.');
        $locks->release($writer['handle'] ?? null);
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Early bootstrap failure leaves no state and the next controller starts normally', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_bootstrap_restart');
    $archive = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite';

    try {
        file_put_contents($archive, 'fixture');

        $failed = kiwi_run_retention_process([
            PHP_BINARY,
            __DIR__ . '/../tools/database/kiwi-retention-archive-health.php',
        ]);
        $failure_json = json_decode((string) $failed['stdout'], true);
        kiwi_assert_same(2, $failed['exit_code'], 'Expected missing WP-CLI bootstrap to exit 2.');
        kiwi_assert_same('wp_cli_api_unavailable', $failure_json['reason_code'] ?? '', 'Expected sanitized bootstrap reason.');
        kiwi_assert_same(false, is_file($root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_health_state.json'), 'Expected no bootstrap state file.');

        $archive_service = new Kiwi_Test_Lean_Archive_Service();
        $archive_service->archives = [[
            'name' => basename($archive),
            'path' => $archive,
        ]];
        $controller = new Kiwi_Retention_Archive_Health_Controller(
            $archive_service,
            new Kiwi_Retention_Archive_Check_Supervisor(
                new Kiwi_Config(),
                static function (): array {
                    return ['result' => 'ok', 'reason_code' => 'sqlite_check_ok', 'check_completed' => true];
                }
            ),
            new Kiwi_Test_Lean_Safety_Gate(),
            new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
            new Kiwi_Test_Retention_Cleanup_Run_Repository()
        );
        $next = $controller->check('quick');
        kiwi_assert_same('ok', $next['result'], 'Expected the next independent controller run to start cleanly.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Cleanup lock defers the real health child before SQLite opens', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_cleanup_lock_child');
    $archive = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite';
    file_put_contents($archive, 'not-opened-because-lock-is-active');
    $locks = new Kiwi_Retention_Archive_Lock();

    try {
        $cleanup = $locks->acquire_for_archive($archive);
        kiwi_assert_true(!empty($cleanup['acquired']), 'Expected cleanup fixture to own the generation lock.');
        $health = kiwi_run_retention_lock_probe($archive);
        kiwi_assert_same(1, $health['exit_code'], 'Expected the health child to defer.');
        kiwi_assert_same('archive_lock_active', $health['result']['reason_code'] ?? '', 'Expected explicit lock reason.');
        kiwi_assert_same(false, $health['result']['sqlite_opened'] ?? true, 'Expected no SQLite open after lock deferral.');
        $locks->release($cleanup['handle'] ?? null);
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Surviving child lock blocks a second health child and cleanup', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_surviving_child');
    $archive = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite';
    file_put_contents($archive, 'fixture');
    $holder_pipes = [];
    $holder = proc_open(
        [
            PHP_BINARY,
            '-r',
            '$h=fopen($argv[1],"c+");flock($h,LOCK_EX);echo "locked\\n";flush();usleep(750000);flock($h,LOCK_UN);fclose($h);',
            $archive . '.lock',
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $holder_pipes,
        null,
        null,
        ['bypass_shell' => true]
    );

    try {
        kiwi_assert_true(is_resource($holder), 'Expected surviving-child fixture to start.');
        fclose($holder_pipes[0]);
        kiwi_assert_same('locked', trim((string) fgets($holder_pipes[1])), 'Expected holder readiness.');

        $second_health = kiwi_run_retention_lock_probe($archive);
        kiwi_assert_same('archive_lock_active', $second_health['result']['reason_code'] ?? '', 'Expected second health child to defer.');

        $cleanup = (new Kiwi_Retention_Archive_Lock())->acquire_for_archive($archive);
        kiwi_assert_same(false, !empty($cleanup['acquired']), 'Expected cleanup to defer while the child survives.');

        fclose($holder_pipes[1]);
        fclose($holder_pipes[2]);
        kiwi_assert_same(0, proc_close($holder), 'Expected the surviving child to exit normally.');
        $holder = null;

        $after = (new Kiwi_Retention_Archive_Lock())->acquire_for_archive($archive);
        kiwi_assert_true(!empty($after['acquired']), 'Expected OS lock release after child exit.');
        (new Kiwi_Retention_Archive_Lock())->release($after['handle'] ?? null);
    } finally {
        if (is_resource($holder)) {
            @proc_terminate($holder);
            @proc_close($holder);
        }
        foreach ($holder_pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Supervisor never terminates a locked child before its timeout-edge gate is durable', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_timeout_gate_handoff');
    $archive = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite';
    $child = $root . DIRECTORY_SEPARATOR . 'timeout-edge-child.php';
    file_put_contents($archive, 'fixture');
    file_put_contents($child, <<<'PHP'
<?php
$payload = json_decode((string) base64_decode((string) ($argv[2] ?? ''), true), true);
$archive = (string) ($payload['archive_path'] ?? '');
$readiness = (string) ($payload['readiness_path'] ?? '');
$lock = fopen($archive . '.lock', 'c+');
flock($lock, LOCK_EX);
file_put_contents($readiness, 'locked');
usleep(1250000);
file_put_contents(
    $archive . '.lock.write-blocked',
    "kiwi_retention_archive_write_blocked_v1\n"
);
flock($lock, LOCK_UN);
fclose($lock);
echo '{"result":"corruption_detected","reason_code":"sqlite_check_reported_corruption","check_completed":true,"write_blocked":true}';
PHP
    );

    try {
        $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
            new Kiwi_Test_One_Second_Archive_Health_Config(),
            null,
            $child
        );
        $result = $supervisor->run($archive, 'integrity', true);

        kiwi_assert_same('inconclusive', $result['result'], 'Expected the wall-clock timeout result.');
        kiwi_assert_same('health_child_timeout', $result['reason_code'], 'Expected explicit timeout reason.');
        kiwi_assert_same(true, $result['child_running'], 'Expected the locked child to survive timeout termination.');
        kiwi_assert_same(true, $result['lock_acquired'], 'Expected observed generation-lock ownership.');

        $during = (new Kiwi_Retention_Archive_Lock())->acquire_for_archive($archive);
        kiwi_assert_same(false, !empty($during['acquired']), 'Expected cleanup to remain locked out after parent timeout.');

        $deadline = microtime(true) + 3.0;
        do {
            usleep(20000);
            $write_blocked = is_file($archive . '.lock.write-blocked');
        } while (!$write_blocked && microtime(true) < $deadline);
        kiwi_assert_same(true, $write_blocked, 'Expected the timeout-edge child to persist its corruption gate before releasing the lock.');

        $deadline = microtime(true) + 3.0;
        $after = null;
        do {
            $after = (new Kiwi_Retention_Archive_Lock())->acquire_for_archive($archive);
            if (!empty($after['acquired'])) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        kiwi_assert_true(is_array($after) && !empty($after['acquired']), 'Expected child exit only after the durable gate existed.');
        (new Kiwi_Retention_Archive_Lock())->release($after['handle'] ?? null);
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Supervisor accepts corruption only from a completed PRAGMA result', function (): void {
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function (): array {
            return [
                'result' => 'corruption_detected',
                'reason_code' => 'exception_text_looked_corrupt',
                'check_completed' => false,
            ];
        }
    );
    $result = $supervisor->run('/tmp/kiwi_retention_archive_2026.sqlite', 'quick');

    kiwi_assert_same('error', $result['result'], 'Expected incomplete corruption claims to be rejected.');
    kiwi_assert_same(false, $result['check_completed'], 'Expected rejected claims to remain incomplete.');
});

kiwi_run_test('Health controller raises repeats and resolves one availability correlation', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [[
        'name' => 'kiwi_retention_archive_2026.sqlite',
        'path' => '/tmp/kiwi_retention_archive_2026.sqlite',
        'year' => '2026',
        'generation' => 1,
    ]];
    $outcomes = [
        ['result' => 'error', 'reason_code' => 'sqlite_readonly_check_failed', 'check_completed' => false],
        ['result' => 'inconclusive', 'reason_code' => 'health_child_timeout', 'check_completed' => false],
        ['result' => 'ok', 'reason_code' => 'sqlite_check_ok', 'check_completed' => true],
    ];
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function () use (&$outcomes): array {
            return array_shift($outcomes);
        }
    );
    $event_repository = new Kiwi_Test_Operational_Event_Repository();
    $events = new Kiwi_Operational_Event_Service($event_repository);
    $gate = new Kiwi_Test_Lean_Safety_Gate();
    $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
    $times = [
        new DateTimeImmutable('2026-08-01T10:00:00+02:00'),
        new DateTimeImmutable('2026-08-01T10:00:01+02:00'),
        new DateTimeImmutable('2026-08-01T10:00:02+02:00'),
        new DateTimeImmutable('2026-08-01T10:00:03+02:00'),
        new DateTimeImmutable('2026-08-01T10:00:04+02:00'),
        new DateTimeImmutable('2026-08-01T10:00:05+02:00'),
    ];
    $clock = static function () use (&$times): DateTimeImmutable {
        return array_shift($times) ?? new DateTimeImmutable('2026-08-01T10:00:06+02:00');
    };
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        $gate,
        $events,
        $runs,
        $clock
    );

    $first = $controller->check('quick');
    $second = $controller->check('quick');
    $third = $controller->check('quick');

    kiwi_assert_same('raised', $first['incident_action'] ?? '', 'Expected first Availability failure to raise.');
    kiwi_assert_same('repeated', $second['incident_action'] ?? '', 'Expected later Availability failure to repeat.');
    kiwi_assert_same('resolved', $third['incident_action'] ?? '', 'Expected next definitive check to resolve Availability.');
    kiwi_assert_same('ok', $third['result'], 'Expected definitive healthy check.');
});

kiwi_run_test('Health controller retries failed Availability resolution from an existing corruption gate', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [[
        'name' => 'kiwi_retention_archive_2026.sqlite',
        'path' => '/tmp/kiwi_retention_archive_2026.sqlite',
    ]];
    $outcomes = [
        ['result' => 'error', 'reason_code' => 'sqlite_readonly_check_failed', 'check_completed' => false],
        [
            'result' => 'corruption_detected',
            'reason_code' => 'sqlite_check_reported_corruption',
            'check_completed' => true,
            'write_blocked' => true,
        ],
    ];
    $supervisor_calls = 0;
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function () use (&$outcomes, &$supervisor_calls): array {
            $supervisor_calls++;

            return array_shift($outcomes);
        }
    );
    $event_repository = new Kiwi_Test_One_Failure_Operational_Event_Repository();
    $events = new Kiwi_Operational_Event_Service($event_repository);
    $gate = new Kiwi_Test_Lean_Safety_Gate();
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        $gate,
        $events,
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    $first = $controller->check('quick');
    $event_repository->fail_next_insert = true;
    $second = $controller->check('integrity');
    $gate->inspect_result = [
        'allowed' => false,
        'reason_code' => 'archive_corruption_write_blocked',
        'write_blocked' => true,
        'corruption_write_blocked' => true,
        'incident_open' => true,
        'incident_action' => 'none',
    ];
    $third = $controller->check('integrity');

    kiwi_assert_same('raised', $first['incident_action'] ?? '', 'Expected initial Availability Incident.');
    kiwi_assert_same('error', $second['result'], 'Expected failed definitive resolution to remain visible.');
    kiwi_assert_same('availability_incident_resolution_failed', $second['reason_code'], 'Expected explicit resolution failure.');
    kiwi_assert_same('blocked', $third['result'], 'Expected durable corruption gate to remain fail-closed.');
    kiwi_assert_same('resolved', $third['incident_action'] ?? '', 'Expected the next gated call to retry Availability resolution exactly once.');
    kiwi_assert_same(2, $supervisor_calls, 'Expected no additional PRAGMA check after the durable gate existed.');
});

kiwi_run_test('Health controller does not resolve Availability from a transition-only gate', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [[
        'name' => 'kiwi_retention_archive_2026.sqlite',
        'path' => '/tmp/kiwi_retention_archive_2026.sqlite',
    ]];
    $supervisor_calls = 0;
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function () use (&$supervisor_calls): array {
            $supervisor_calls++;

            return [
                'result' => 'inconclusive',
                'reason_code' => 'health_child_timeout',
                'check_completed' => false,
            ];
        }
    );
    $event_repository = new Kiwi_Test_Operational_Event_Repository();
    $events = new Kiwi_Operational_Event_Service($event_repository);
    $gate = new Kiwi_Test_Lean_Safety_Gate();
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        $gate,
        $events,
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    $first = $controller->check('integrity');
    $gate->inspect_result = [
        'allowed' => false,
        'reason_code' => 'replacement_transition_write_blocked',
        'write_blocked' => true,
        'corruption_write_blocked' => false,
        'replacement_transition_blocked' => true,
        'incident_open' => false,
        'incident_action' => 'none',
    ];
    $transition = $controller->check('integrity');
    $availability = $events->get_open_incidents([
        'event_type' => 'retention_archive_health_unavailable',
    ], 10);

    kiwi_assert_same('inconclusive', $first['result'], 'Expected the timeout to open Availability.');
    kiwi_assert_same('blocked', $transition['result'], 'Expected the transition marker to remain fail closed.');
    kiwi_assert_same('none', $transition['incident_action'] ?? 'none', 'Expected no false Availability recovery from a transition marker.');
    kiwi_assert_same(1, count($availability ?? []), 'Expected the Availability Incident to remain open without definitive corruption evidence.');
    kiwi_assert_same(1, $supervisor_calls, 'Expected no additional PRAGMA while the transition marker remains active.');
});

kiwi_run_test('Health controller reports unreadable corruption gates as Availability errors', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [[
        'name' => 'kiwi_retention_archive_2026.sqlite',
        'path' => '/tmp/kiwi_retention_archive_2026.sqlite',
    ]];
    $supervisor_calls = 0;
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function () use (&$supervisor_calls): array {
            $supervisor_calls++;

            return ['result' => 'ok', 'reason_code' => 'sqlite_check_ok', 'check_completed' => true];
        }
    );
    $events = new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository());
    $gate = new Kiwi_Test_Lean_Safety_Gate();
    $gate->inspect_result = [
        'allowed' => false,
        'reason_code' => 'corruption_incident_lookup_failed',
        'write_blocked' => false,
        'corruption_write_blocked' => false,
        'incident_open' => false,
        'incident_action' => 'none',
    ];
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        $gate,
        $events,
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    $result = $controller->check('integrity');

    kiwi_assert_same('error', $result['result'], 'Expected unreadable gate state to remain a technical error.');
    kiwi_assert_same('corruption_incident_lookup_failed', $result['reason_code'], 'Expected the storage failure reason to remain visible.');
    kiwi_assert_same(2, $result['_exit_code'], 'Expected unreadable gate state to exit 2.');
    kiwi_assert_same('raised', $result['incident_action'] ?? '', 'Expected the gate outage to raise Availability.');
    kiwi_assert_same(0, $supervisor_calls, 'Expected no PRAGMA while gate state is unreadable.');

    $gate->inspect_result = [
        'allowed' => false,
        'reason_code' => 'replacement_transition_state_invalid',
        'write_blocked' => false,
        'corruption_write_blocked' => false,
        'replacement_transition_blocked' => false,
        'incident_open' => false,
        'incident_action' => 'none',
    ];
    $malformed_transition = $controller->check('integrity');

    kiwi_assert_same('error', $malformed_transition['result'], 'Expected malformed transition state to remain a technical error.');
    kiwi_assert_same(
        'replacement_transition_state_invalid',
        $malformed_transition['reason_code'],
        'Expected the malformed transition reason to remain visible.'
    );
    kiwi_assert_same(2, $malformed_transition['_exit_code'], 'Expected malformed transition state to exit 2.');
    kiwi_assert_same('repeated', $malformed_transition['incident_action'] ?? '', 'Expected the gate outage to repeat Availability.');
    kiwi_assert_same(0, $supervisor_calls, 'Expected no PRAGMA while replacement transition state is malformed.');
});

kiwi_run_test('Health controller never promotes incomplete exceptions to corruption gates', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [[
        'name' => 'kiwi_retention_archive_2026.sqlite',
        'path' => '/tmp/kiwi_retention_archive_2026.sqlite',
    ]];
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function (): array {
            return [
                'result' => 'corruption_detected',
                'reason_code' => 'database_disk_image_is_malformed',
                'check_completed' => false,
            ];
        }
    );
    $gate = new Kiwi_Test_Lean_Safety_Gate();
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        $gate,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    $result = $controller->check('quick');

    kiwi_assert_same('error', $result['result'], 'Expected incomplete result to remain a technical error.');
    kiwi_assert_same([], $gate->block_calls, 'Expected no corruption gate side effect.');
});

kiwi_run_test('Health controller persists corruption gates only after completed non-ok PRAGMA', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [[
        'name' => 'kiwi_retention_archive_2026.sqlite',
        'path' => '/tmp/kiwi_retention_archive_2026.sqlite',
    ]];
    $persist_flags = [];
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function (string $path, string $check, bool $persist_write_block) use (&$persist_flags): array {
            $persist_flags[] = $persist_write_block;

            return [
                'result' => 'corruption_detected',
                'reason_code' => 'sqlite_check_reported_corruption',
                'check_completed' => true,
                'write_blocked' => $persist_write_block,
            ];
        }
    );
    $gate = new Kiwi_Test_Lean_Safety_Gate();
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        $gate,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    $result = $controller->check('integrity');

    kiwi_assert_same('corruption_detected', $result['result'], 'Expected completed non-ok PRAGMA result.');
    kiwi_assert_same([true], $persist_flags, 'Expected scheduled check to persist the block while the child lock is held.');
    kiwi_assert_same(1, count($gate->block_calls), 'Expected one ordered corruption gate transition.');
    kiwi_assert_same(true, $result['write_blocked'] ?? false, 'Expected write-block evidence in output.');
});

kiwi_run_test('Supervisor persists fallback Incident before a corruption child releases its lock', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_corruption_handoff');
    $archive = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite';
    file_put_contents($archive, 'fixture');
    $probe = [];
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Test_Lean_Archive_Config(),
        null,
        __DIR__ . '/fixtures/retention-corruption-handoff-child.php'
    );

    try {
        $result = $supervisor->run(
            $archive,
            'integrity',
            true,
            static function () use ($archive, &$probe): array {
                $probe = kiwi_run_retention_lock_probe($archive);

                return ['incident_open' => true, 'incident_action' => 'raised'];
            }
        );

        kiwi_assert_same('corruption_detected', $result['result'], 'Expected confirmed corruption after fallback Incident persistence.');
        kiwi_assert_same(true, $result['incident_open'], 'Expected durable fallback Incident evidence.');
        kiwi_assert_same('archive_lock_active', $probe['result']['reason_code'] ?? '', 'Expected child lock to remain held during fallback persistence.');
        $after = (new Kiwi_Retention_Archive_Lock())->acquire_for_archive($archive);
        kiwi_assert_same(true, !empty($after['acquired']), 'Expected lock release only after the fallback acknowledgement.');
        (new Kiwi_Retention_Archive_Lock())->release($after['handle'] ?? null);
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Health controller reconciles a failed child sentinel from the Incident created under lock', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [[
        'name' => 'kiwi_retention_archive_2026.sqlite',
        'path' => '/tmp/kiwi_retention_archive_2026.sqlite',
    ]];
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function (): array {
            return [
                'result' => 'corruption_detected',
                'reason_code' => 'sqlite_check_reported_corruption',
                'check_completed' => true,
                'write_blocked' => false,
            ];
        }
    );
    $gate = new Kiwi_Test_Lean_Safety_Gate();
    $gate->inspect_results = [
        $gate->inspect_result,
        [
            'allowed' => false,
            'reason_code' => 'archive_corruption_write_blocked',
            'write_blocked' => true,
            'incident_open' => true,
            'incident_action' => 'none',
        ],
    ];
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        $gate,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    $result = $controller->check('integrity');

    kiwi_assert_same('corruption_detected', $result['result'], 'Expected fallback Incident to preserve definitive corruption.');
    kiwi_assert_same(1, count($gate->locked_incident_calls), 'Expected Incident persistence before child lock release.');
    kiwi_assert_same(2, count($gate->inspect_calls), 'Expected the parent to reconcile the missing sentinel after the handoff.');
    kiwi_assert_same([], $gate->block_calls, 'Expected no unsafe post-release gate creation path.');
});

kiwi_run_test('Health controller reports an error when both corruption gates fail to persist', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [[
        'name' => 'kiwi_retention_archive_2026.sqlite',
        'path' => '/tmp/kiwi_retention_archive_2026.sqlite',
    ]];
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function (): array {
            return [
                'result' => 'corruption_detected',
                'reason_code' => 'sqlite_check_reported_corruption',
                'check_completed' => true,
                'write_blocked' => false,
            ];
        }
    );
    $gate = new Kiwi_Test_Lean_Safety_Gate();
    $gate->locked_incident_result = [
        'allowed' => false,
        'reason_code' => 'corruption_incident_persist_failed',
        'write_blocked' => false,
        'incident_open' => false,
        'incident_action' => 'none',
    ];
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        $gate,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    $result = $controller->check('integrity');

    kiwi_assert_same('error', $result['result'], 'Expected combined gate persistence failure never to report success.');
    kiwi_assert_same('corruption_gate_persist_failed', $result['reason_code'], 'Expected explicit combined-gate failure.');
    kiwi_assert_same([], $gate->block_calls, 'Expected no post-release race-prone fallback attempt.');
});

kiwi_run_test('Corruption safety gate fails closed when Incident state is unreadable', function (): void {
    $coordinator = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator(
        new Kiwi_Retention_Archive_Lock(),
        new Kiwi_Test_Unreadable_Operational_Event_Service(),
        new Kiwi_Test_Manual_Replacement_Run_Repository()
    );
    $result = $coordinator->inspect('/tmp/kiwi_retention_archive_2026.sqlite');

    kiwi_assert_same(false, $result['allowed'], 'Expected unreadable Incident state to block cleanup.');
    kiwi_assert_same('corruption_incident_lookup_failed', $result['reason_code'], 'Expected explicit fail-closed reason.');
});

kiwi_run_test('Corruption safety gate rejects malformed replacement transition markers', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_invalid_transition_marker');
    $archive = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite';
    file_put_contents($archive, 'fixture');
    file_put_contents(
        Kiwi_Retention_Archive_Write_Block::get_replacement_transition_path($archive . '.lock'),
        "truncated-transition-state\n"
    );
    $coordinator = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator(
        new Kiwi_Retention_Archive_Lock(),
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        new Kiwi_Test_Manual_Replacement_Run_Repository()
    );

    try {
        $result = $coordinator->inspect($archive);

        kiwi_assert_same(false, $result['allowed'], 'Expected malformed replacement state to fail closed.');
        kiwi_assert_same(
            'replacement_transition_state_invalid',
            $result['reason_code'],
            'Expected malformed transition state to remain a technical error.'
        );
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Corruption safety gate rechecks a lone sentinel under the generation lock', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_corruption_reconcile_race');
    $archive = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite';
    file_put_contents($archive, 'fixture');
    $lock = new Kiwi_Test_Gate_Clearing_Archive_Lock();
    $setup = $lock->acquire_for_archive($archive);
    $handle = $setup['handle'] ?? null;
    kiwi_assert_true($handle instanceof Kiwi_Retention_Archive_Lock_Handle, 'Expected setup generation lock.');
    kiwi_assert_same(true, $handle->persist_write_blocked(), 'Expected lone sentinel fixture.');
    $lock->release($handle);
    $lock->clear_before_next_acquire = $archive;
    $events = new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository());
    $coordinator = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator(
        $lock,
        $events,
        new Kiwi_Test_Manual_Replacement_Run_Repository()
    );

    try {
        $result = $coordinator->inspect($archive, true);

        kiwi_assert_same(true, $result['allowed'], 'Expected the cleared sentinel to remain recovered after locked recheck.');
        kiwi_assert_same([], $events->get_open_incidents([
            'event_type' => 'retention_archive_corruption_detected',
            'reference_id' => basename($archive),
        ]), 'Expected no stale Incident after unblock won the generation lock.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Corruption safety gate writes block before Incident and resolves Incident last', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_corruption_gate');
    $archive = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite';
    file_put_contents($archive, 'fixture');
    $event_repository = new Kiwi_Test_Operational_Event_Repository();
    $events = new Kiwi_Operational_Event_Service($event_repository);
    $runs = new Kiwi_Test_Manual_Replacement_Run_Repository();
    $coordinator = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator(
        new Kiwi_Retention_Archive_Lock(),
        $events,
        $runs
    );

    try {
        $blocked = $coordinator->block_after_corruption(
            $archive,
            'integrity',
            'sqlite_check_reported_corruption'
        );
        kiwi_assert_same(true, $blocked['write_blocked'], 'Expected filesystem block first.');
        kiwi_assert_same(true, $blocked['incident_open'], 'Expected Corruption Incident second.');
        kiwi_assert_true(is_file($archive . '.lock.write-blocked'), 'Expected durable write-block sentinel.');

        $unblocked = $coordinator->unblock($archive);
        kiwi_assert_same(true, $unblocked['allowed'], 'Expected confirmed repair release.');
        kiwi_assert_same(false, is_file($archive . '.lock.write-blocked'), 'Expected sentinel removal before resolution.');
        kiwi_assert_same([], $events->get_open_incidents([
            'event_type' => 'retention_archive_corruption_detected',
            'reference_id' => basename($archive),
        ]), 'Expected Corruption Incident to resolve last.');

        $reblocked = $coordinator->block_after_corruption(
            $archive,
            'integrity',
            'sqlite_check_reported_corruption'
        );
        kiwi_assert_same(true, $reblocked['incident_open'], 'Expected identical later corruption to open a new lifecycle.');
        kiwi_assert_same('raised', $reblocked['incident_action'], 'Expected the post-recovery corruption lifecycle to start with raised.');
        kiwi_assert_same(1, count($events->get_open_incidents([
            'event_type' => 'retention_archive_corruption_detected',
            'reference_id' => basename($archive),
        ]) ?? []), 'Expected the later corruption Incident to remain durably open.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Replacement remains blocked until Corruption Incident resolution succeeds', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_replacement_gate');
    $archive = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite';
    $replacement = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026_part_2.sqlite';
    file_put_contents($archive, 'archive-a');
    file_put_contents($replacement, 'archive-b');
    $event_repository = new Kiwi_Test_One_Failure_Operational_Event_Repository();
    $events = new Kiwi_Operational_Event_Service($event_repository);
    $runs = new Kiwi_Test_Manual_Replacement_Run_Repository();
    $lock = new Kiwi_Retention_Archive_Lock();
    $coordinator = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator(
        $lock,
        $events,
        $runs
    );

    try {
        $blocked = $coordinator->block_after_corruption(
            $archive,
            'integrity',
            'sqlite_check_reported_corruption'
        );
        kiwi_assert_same(true, $blocked['write_blocked'], 'Expected A to begin durably blocked.');

        $event_repository->fail_next_insert = true;
        $failed = $coordinator->unblock($archive, $replacement);
        kiwi_assert_same(false, $failed['allowed'], 'Expected failed Incident resolution to block replacement recovery.');
        kiwi_assert_same('corruption_incident_resolution_failed', $failed['reason_code'], 'Expected explicit resolution failure.');
        kiwi_assert_same(false, $lock->is_write_blocked_for_archive($archive), 'Expected A sentinel to clear before the final Incident action.');
        kiwi_assert_same(false, $lock->is_write_blocked_for_archive($replacement), 'Expected B transition state not to masquerade as corruption.');
        kiwi_assert_same(true, $lock->is_replacement_transition_blocked_for_archive($replacement), 'Expected distinct B transition sentinel to remain fail-closed.');
        kiwi_assert_same(basename($archive), $lock->get_replacement_transition_source_for_archive($replacement), 'Expected B transition state to remain bound to source A.');
        kiwi_assert_same(1, count($runs->terminalize_calls), 'Expected A run terminalization before Incident resolution.');

        $replacement_gate = $coordinator->inspect($replacement, true);
        kiwi_assert_same('replacement_transition_write_blocked', $replacement_gate['reason_code'], 'Expected Health reconciliation to preserve transition semantics.');
        kiwi_assert_same([], $events->get_open_incidents([
            'event_type' => 'retention_archive_corruption_detected',
            'reference_id' => basename($replacement),
        ]), 'Expected no false Corruption Incident for B.');

        $retried = $coordinator->unblock($archive, $replacement);
        kiwi_assert_same(true, $retried['allowed'], 'Expected idempotent retry after Incident storage recovers.');
        kiwi_assert_same(false, $lock->is_replacement_transition_blocked_for_archive($replacement), 'Expected B to open only after successful Incident resolution.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Replacement requires an existing corruption gate on the source archive', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_replacement_requires_gate');
    $archive = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite';
    $replacement = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026_part_2.sqlite';
    file_put_contents($archive, 'archive-a');
    file_put_contents($replacement, 'archive-b');
    $runs = new Kiwi_Test_Manual_Replacement_Run_Repository();
    $lock = new Kiwi_Retention_Archive_Lock();
    $coordinator = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator(
        $lock,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        $runs
    );

    try {
        $result = $coordinator->unblock($archive, $replacement);

        kiwi_assert_same(false, $result['allowed'], 'Expected healthy A not to enter replacement recovery.');
        kiwi_assert_same('unblock_corruption_gate_required', $result['reason_code'], 'Expected explicit source-gate requirement.');
        kiwi_assert_same([], $runs->terminalize_calls, 'Expected no resumable run to be terminalized.');
        kiwi_assert_same(false, $lock->is_replacement_transition_blocked_for_archive($replacement), 'Expected no B transition state on rejected recovery.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Unblock preserves malformed source transition errors before and under lock', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_unblock_invalid_source_transition');
    $archive = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite';
    file_put_contents($archive, 'archive-a');
    $runs = new Kiwi_Test_Manual_Replacement_Run_Repository();
    $lock = new Kiwi_Test_Gate_Clearing_Archive_Lock();
    $coordinator = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator(
        $lock,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        $runs
    );
    $transition_path = Kiwi_Retention_Archive_Write_Block::get_replacement_transition_path(
        $archive . '.lock'
    );

    try {
        file_put_contents($transition_path, "truncated-transition-state\n");
        $before_lock = $coordinator->unblock($archive);
        kiwi_assert_same(
            'replacement_transition_state_invalid',
            $before_lock['reason_code'],
            'Expected the initial source-gate check to preserve malformed durable state.'
        );

        unlink($transition_path);
        $setup = $lock->acquire_for_archive($archive);
        $handle = $setup['handle'] ?? null;
        kiwi_assert_true($handle instanceof Kiwi_Retention_Archive_Lock_Handle, 'Expected setup generation lock.');
        kiwi_assert_same(true, $handle->persist_write_blocked(), 'Expected source corruption gate fixture.');
        $lock->release($handle);
        $lock->corrupt_transition_before_next_acquire = $archive;

        $under_lock = $coordinator->unblock($archive);
        kiwi_assert_same(
            'replacement_transition_state_invalid',
            $under_lock['reason_code'],
            'Expected the locked source-gate recheck to preserve malformed durable state.'
        );
        kiwi_assert_same([], $runs->terminalize_calls, 'Expected no replacement mutation for malformed source state.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Replacement requires its own corruption gate to be recovered first', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_replacement_own_gate');
    $archive = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite';
    $replacement = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026_part_2.sqlite';
    file_put_contents($archive, 'archive-a');
    file_put_contents($replacement, 'archive-b');
    $runs = new Kiwi_Test_Manual_Replacement_Run_Repository();
    $coordinator = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator(
        new Kiwi_Retention_Archive_Lock(),
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        $runs
    );

    try {
        $coordinator->block_after_corruption($archive, 'integrity', 'sqlite_check_reported_corruption');
        $coordinator->block_after_corruption($replacement, 'integrity', 'sqlite_check_reported_corruption');

        $rejected = $coordinator->unblock($archive, $replacement);
        kiwi_assert_same(false, $rejected['allowed'], 'Expected corrupt B not to complete A replacement.');
        kiwi_assert_same('replacement_corruption_gate_open', $rejected['reason_code'], 'Expected explicit B gate reason.');
        kiwi_assert_same([], $runs->terminalize_calls, 'Expected A run to remain resumable while B is blocked.');

        $replacement_recovered = $coordinator->unblock($replacement);
        kiwi_assert_same(true, $replacement_recovered['allowed'], 'Expected B to support its own confirmed recovery.');
        $completed = $coordinator->unblock($archive, $replacement);
        kiwi_assert_same(true, $completed['allowed'], 'Expected A replacement after B gate recovery.');
        kiwi_assert_same(1, count($runs->terminalize_calls), 'Expected terminalization only after both generations are safe.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Replacement revalidates the source corruption gate under its lock', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_replacement_gate_race');
    $archive = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite';
    $replacement = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026_part_2.sqlite';
    file_put_contents($archive, 'archive-a');
    file_put_contents($replacement, 'archive-b');
    $runs = new Kiwi_Test_Manual_Replacement_Run_Repository();
    $lock = new Kiwi_Test_Gate_Clearing_Archive_Lock();
    $setup_lock = $lock->acquire_for_archive($archive);
    $setup_handle = $setup_lock['handle'] ?? null;
    kiwi_assert_true($setup_handle instanceof Kiwi_Retention_Archive_Lock_Handle, 'Expected A lock fixture.');
    kiwi_assert_same(true, $setup_handle->persist_write_blocked(), 'Expected initial A corruption sentinel.');
    $lock->release($setup_handle);
    $lock->clear_before_next_acquire = $archive;
    $coordinator = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator(
        $lock,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        $runs
    );

    try {
        $result = $coordinator->unblock($archive, $replacement);

        kiwi_assert_same(false, $result['allowed'], 'Expected replacement to stop after A gate cleared before lock acquisition.');
        kiwi_assert_same('unblock_corruption_gate_required', $result['reason_code'], 'Expected locked source-gate revalidation.');
        kiwi_assert_same([], $runs->terminalize_calls, 'Expected no run terminalization after the gate race.');
        kiwi_assert_same(false, $lock->is_replacement_transition_blocked_for_archive($replacement), 'Expected no B transition marker after the gate race.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Replacement transition marker permits only its bound recovery retry', function (): void {
    $root = kiwi_create_temp_directory('kiwi_retention_replacement_retry');
    $archive = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite';
    $replacement = $root . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026_part_2.sqlite';
    file_put_contents($archive, 'archive-a');
    file_put_contents($replacement, 'archive-b');
    $runs = new Kiwi_Test_Manual_Replacement_Run_Repository();
    $lock = new Kiwi_Retention_Archive_Lock();
    $replacement_lock = $lock->acquire_for_archive($replacement);
    $replacement_handle = $replacement_lock['handle'] ?? null;
    kiwi_assert_true($replacement_handle instanceof Kiwi_Retention_Archive_Lock_Handle, 'Expected B lock fixture.');
    kiwi_assert_same(true, $replacement_handle->persist_replacement_transition_blocked(basename($archive)), 'Expected bound B transition marker fixture.');
    $lock->release($replacement_handle);
    $coordinator = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator(
        $lock,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        $runs
    );

    try {
        $result = $coordinator->unblock($archive, $replacement);

        kiwi_assert_same(true, $result['allowed'], 'Expected a bound in-progress transition to remain retryable.');
        kiwi_assert_same(1, count($runs->terminalize_calls), 'Expected the bound retry to complete idempotently.');
        kiwi_assert_same(false, $lock->is_replacement_transition_blocked_for_archive($replacement), 'Expected completed retry to clear B transition state.');
    } finally {
        kiwi_remove_directory($root);
    }
});

kiwi_run_test('Diagnose is read-only and does not reconcile gates or Incidents', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [[
        'name' => 'kiwi_retention_archive_2026.sqlite',
        'path' => '/tmp/kiwi_retention_archive_2026.sqlite',
    ]];
    $persist_flags = [];
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function (string $path, string $check, bool $persist_write_block) use (&$persist_flags): array {
            $persist_flags[] = $persist_write_block;

            return ['result' => 'ok', 'reason_code' => 'sqlite_check_ok', 'check_completed' => true];
        }
    );
    $gate = new Kiwi_Test_Lean_Safety_Gate();
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        $gate,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    $result = $controller->diagnose('kiwi_retention_archive_2026.sqlite', 'quick');

    kiwi_assert_same('ok', $result['result'], 'Expected read-only quick diagnosis.');
    kiwi_assert_same([false], $persist_flags, 'Expected diagnose never to persist a write block.');
    kiwi_assert_same([], $gate->inspect_calls, 'Expected diagnose not to reconcile durable gates.');
    kiwi_assert_same([], $gate->block_calls, 'Expected diagnose not to persist corruption state.');
});

kiwi_run_test('Diagnose and unblock reject path-like values before archive discovery', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [
        ['name' => 'kiwi_retention_archive_2026.sqlite', 'path' => '/tmp/kiwi_retention_archive_2026.sqlite'],
        ['name' => 'kiwi_retention_archive_2026_part_2.sqlite', 'path' => '/tmp/kiwi_retention_archive_2026_part_2.sqlite'],
    ];
    $supervisor_calls = 0;
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function () use (&$supervisor_calls): array {
            $supervisor_calls++;

            return ['result' => 'ok', 'reason_code' => 'sqlite_check_ok', 'check_completed' => true];
        }
    );
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        new Kiwi_Test_Lean_Safety_Gate(),
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    $diagnose = $controller->diagnose('/arbitrary/kiwi_retention_archive_2026.sqlite', 'quick');
    $unblock_archive = $controller->unblock('../kiwi_retention_archive_2026.sqlite', '', true);
    $unblock_replacement = $controller->unblock(
        'kiwi_retention_archive_2026.sqlite',
        '/arbitrary/kiwi_retention_archive_2026_part_2.sqlite',
        true
    );

    kiwi_assert_same('diagnose_input_invalid', $diagnose['reason_code'], 'Expected diagnose to require the exact basename.');
    kiwi_assert_same('unblock_input_invalid', $unblock_archive['reason_code'], 'Expected unblock A to reject path components.');
    kiwi_assert_same('unblock_input_invalid', $unblock_replacement['reason_code'], 'Expected unblock B to reject path components.');
    kiwi_assert_same(0, $supervisor_calls, 'Expected invalid path-like input never to reach the SQLite child.');
});

kiwi_run_test('Manual replacement verifies explicit B before terminalizing A', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [
        ['name' => 'kiwi_retention_archive_2026.sqlite', 'path' => '/tmp/kiwi_retention_archive_2026.sqlite'],
        ['name' => 'kiwi_retention_archive_2026_part_2.sqlite', 'path' => '/tmp/kiwi_retention_archive_2026_part_2.sqlite'],
    ];
    $verified_paths = [];
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function (string $path, string $check) use (&$verified_paths): array {
            $verified_paths[] = [$path, $check];

            return ['result' => 'ok', 'reason_code' => 'sqlite_check_ok', 'check_completed' => true];
        }
    );
    $gate = new Kiwi_Test_Lean_Safety_Gate();
    $gate->unblock_result['reason_code'] = 'manual_replacement_unblocked';
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        $gate,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    $result = $controller->unblock(
        'kiwi_retention_archive_2026.sqlite',
        'kiwi_retention_archive_2026_part_2.sqlite',
        true
    );

    kiwi_assert_same('ok', $result['result'], 'Expected explicit replacement unblock.');
    kiwi_assert_same('/tmp/kiwi_retention_archive_2026_part_2.sqlite', $verified_paths[0][0], 'Expected B to receive the full integrity check.');
    kiwi_assert_same('integrity', $verified_paths[0][1], 'Expected full integrity mode.');
    kiwi_assert_same(1, count($gate->unblock_calls), 'Expected A/B transition only after verification.');
});

kiwi_run_test('Unblock corruption verification persists a gate on the checked generation', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [[
        'name' => 'kiwi_retention_archive_2026.sqlite',
        'path' => '/tmp/kiwi_retention_archive_2026.sqlite',
    ]];
    $persist_flags = [];
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function (string $path, string $check, bool $persist) use (&$persist_flags): array {
            $persist_flags[] = $persist;

            return [
                'result' => 'corruption_detected',
                'reason_code' => 'sqlite_check_reported_corruption',
                'check_completed' => true,
                'write_blocked' => false,
            ];
        }
    );
    $gate = new Kiwi_Test_Lean_Safety_Gate();
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        $gate,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    $result = $controller->unblock('kiwi_retention_archive_2026.sqlite', '', true);

    kiwi_assert_same('blocked', $result['result'], 'Expected corruption to reject unblock.');
    kiwi_assert_same('sqlite_check_reported_corruption', $result['reason_code'], 'Expected the definitive corruption reason to remain visible.');
    kiwi_assert_same(1, $result['_exit_code'], 'Expected definitive corruption to remain an operational block.');
    kiwi_assert_same([true], $persist_flags, 'Expected unblock verification to request fail-closed gate persistence.');
    kiwi_assert_same(1, count($gate->locked_incident_calls), 'Expected durable fallback Incident for the checked generation.');
    kiwi_assert_same(1, count($gate->block_calls), 'Expected parent reconciliation to complete the corruption gate transition.');
    kiwi_assert_same([], $gate->unblock_calls, 'Expected no recovery state change after corrupt verification.');
});

kiwi_run_test('Unblock maps persistence failures to exit 2 and deferrals to exit 1', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [[
        'name' => 'kiwi_retention_archive_2026.sqlite',
        'path' => '/tmp/kiwi_retention_archive_2026.sqlite',
    ]];
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function (): array {
            return ['result' => 'ok', 'reason_code' => 'sqlite_check_ok', 'check_completed' => true];
        }
    );
    $gate = new Kiwi_Test_Lean_Safety_Gate();
    $gate->unblock_result = [
        'allowed' => false,
        'reason_code' => 'corruption_incident_resolution_failed',
        'write_blocked' => false,
        'incident_open' => true,
        'incident_action' => 'none',
    ];
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        $gate,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    $persistence_failure = $controller->unblock('kiwi_retention_archive_2026.sqlite', '', true);
    $gate->unblock_result['reason_code'] = 'archive_lock_active';
    $deferral = $controller->unblock('kiwi_retention_archive_2026.sqlite', '', true);

    kiwi_assert_same('error', $persistence_failure['result'], 'Expected persistence failure classification.');
    kiwi_assert_same(2, $persistence_failure['_exit_code'], 'Expected persistence failure exit 2.');
    kiwi_assert_same('blocked', $deferral['result'], 'Expected lock deferral classification.');
    kiwi_assert_same(1, $deferral['_exit_code'], 'Expected lock deferral exit 1.');
});

kiwi_run_test('Unblock preserves verification errors and expected retry reasons', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [[
        'name' => 'kiwi_retention_archive_2026.sqlite',
        'path' => '/tmp/kiwi_retention_archive_2026.sqlite',
    ]];
    $outcomes = [
        ['result' => 'error', 'reason_code' => 'health_child_start_failed', 'check_completed' => false],
        ['result' => 'inconclusive', 'reason_code' => 'health_child_timeout', 'check_completed' => false],
    ];
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function () use (&$outcomes): array {
            return array_shift($outcomes);
        }
    );
    $gate = new Kiwi_Test_Lean_Safety_Gate();
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        $gate,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    $technical_error = $controller->unblock('kiwi_retention_archive_2026.sqlite', '', true);
    $timeout = $controller->unblock('kiwi_retention_archive_2026.sqlite', '', true);

    kiwi_assert_same('error', $technical_error['result'], 'Expected a child start failure to remain an error.');
    kiwi_assert_same('health_child_start_failed', $technical_error['reason_code'], 'Expected the technical reason to remain visible.');
    kiwi_assert_same(2, $technical_error['_exit_code'], 'Expected a technical verification failure to exit 2.');
    kiwi_assert_same('blocked', $timeout['result'], 'Expected an inconclusive timeout to remain retryable.');
    kiwi_assert_same('health_child_timeout', $timeout['reason_code'], 'Expected the timeout reason to remain visible.');
    kiwi_assert_same(1, $timeout['_exit_code'], 'Expected an inconclusive timeout to exit 1.');
    kiwi_assert_same([], $gate->unblock_calls, 'Expected no recovery mutation after failed verification.');
});

kiwi_run_test('Unblock classifies corruption gate reconciliation failures as errors', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [[
        'name' => 'kiwi_retention_archive_2026.sqlite',
        'path' => '/tmp/kiwi_retention_archive_2026.sqlite',
    ]];
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function (): array {
            return [
                'result' => 'corruption_detected',
                'reason_code' => 'sqlite_check_reported_corruption',
                'check_completed' => true,
                'incident_open' => true,
            ];
        }
    );
    $gate = new Kiwi_Test_Lean_Safety_Gate();
    $gate->block_result = [
        'allowed' => false,
        'reason_code' => 'corruption_gate_persist_failed',
        'write_blocked' => false,
        'incident_open' => false,
        'incident_action' => 'none',
    ];
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        $gate,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    $result = $controller->unblock('kiwi_retention_archive_2026.sqlite', '', true);

    kiwi_assert_same('error', $result['result'], 'Expected failed corruption gate reconciliation to remain technical.');
    kiwi_assert_same('corruption_gate_persist_failed', $result['reason_code'], 'Expected the reconciliation failure reason to remain visible.');
    kiwi_assert_same(2, $result['_exit_code'], 'Expected failed corruption gate reconciliation to exit 2.');
    kiwi_assert_same([], $gate->unblock_calls, 'Expected no recovery mutation after gate reconciliation failed.');
});

kiwi_run_test('Manual replacement permits the active generation after year rollover', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [
        ['name' => 'kiwi_retention_archive_2025.sqlite', 'path' => '/tmp/kiwi_retention_archive_2025.sqlite'],
        ['name' => 'kiwi_retention_archive_2026.sqlite', 'path' => '/tmp/kiwi_retention_archive_2026.sqlite'],
    ];
    $archive_service->resolved_archive_paths = [
        '/tmp/kiwi_retention_archive_2026.sqlite',
        '/tmp/kiwi_retention_archive_2026.sqlite',
    ];
    $verified_paths = [];
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function (string $path, string $check) use (&$verified_paths): array {
            $verified_paths[] = [$path, $check];

            return ['result' => 'ok', 'reason_code' => 'sqlite_check_ok', 'check_completed' => true];
        }
    );
    $gate = new Kiwi_Test_Lean_Safety_Gate();
    $gate->unblock_result['reason_code'] = 'manual_replacement_unblocked';
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        $gate,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    $result = $controller->unblock(
        'kiwi_retention_archive_2025.sqlite',
        'kiwi_retention_archive_2026.sqlite',
        true
    );

    kiwi_assert_same('ok', $result['result'], 'Expected active current-year B to replace prior-year A.');
    kiwi_assert_same('/tmp/kiwi_retention_archive_2026.sqlite', $verified_paths[0][0], 'Expected full verification of active B.');
    kiwi_assert_same(1, count($gate->unblock_calls), 'Expected A/B transition after year rollover.');
});

kiwi_run_test('Manual replacement rejects a generation cleanup would not select', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [
        ['name' => 'kiwi_retention_archive_2026.sqlite', 'path' => '/tmp/kiwi_retention_archive_2026.sqlite'],
        ['name' => 'kiwi_retention_archive_2026_part_2.sqlite', 'path' => '/tmp/kiwi_retention_archive_2026_part_2.sqlite'],
        ['name' => 'kiwi_retention_archive_2026_part_3.sqlite', 'path' => '/tmp/kiwi_retention_archive_2026_part_3.sqlite'],
    ];
    $supervisor_calls = 0;
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function () use (&$supervisor_calls): array {
            $supervisor_calls++;

            return ['result' => 'ok', 'reason_code' => 'sqlite_check_ok', 'check_completed' => true];
        }
    );
    $gate = new Kiwi_Test_Lean_Safety_Gate();
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        $gate,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    $result = $controller->unblock(
        'kiwi_retention_archive_2026.sqlite',
        'kiwi_retention_archive_2026_part_2.sqlite',
        true
    );

    kiwi_assert_same('error', $result['result'], 'Expected non-active B to be rejected while C exists.');
    kiwi_assert_same('replacement_generation_not_active', $result['reason_code'], 'Expected explicit active-generation mismatch.');
    kiwi_assert_same(0, $supervisor_calls, 'Expected rejected B not to consume an integrity check.');
    kiwi_assert_same([], $gate->unblock_calls, 'Expected no recovery state change for rejected B.');
});

kiwi_run_test('Manual replacement rechecks the active generation after integrity verification', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [
        ['name' => 'kiwi_retention_archive_2026.sqlite', 'path' => '/tmp/kiwi_retention_archive_2026.sqlite'],
        ['name' => 'kiwi_retention_archive_2026_part_2.sqlite', 'path' => '/tmp/kiwi_retention_archive_2026_part_2.sqlite'],
        ['name' => 'kiwi_retention_archive_2026_part_3.sqlite', 'path' => '/tmp/kiwi_retention_archive_2026_part_3.sqlite'],
    ];
    $archive_service->resolved_archive_paths = [
        '/tmp/kiwi_retention_archive_2026_part_2.sqlite',
        '/tmp/kiwi_retention_archive_2026_part_3.sqlite',
    ];
    $supervisor_calls = 0;
    $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
        new Kiwi_Config(),
        static function () use (&$supervisor_calls): array {
            $supervisor_calls++;

            return ['result' => 'ok', 'reason_code' => 'sqlite_check_ok', 'check_completed' => true];
        }
    );
    $gate = new Kiwi_Test_Lean_Safety_Gate();
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        $supervisor,
        $gate,
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );

    $result = $controller->unblock(
        'kiwi_retention_archive_2026.sqlite',
        'kiwi_retention_archive_2026_part_2.sqlite',
        true
    );

    kiwi_assert_same('error', $result['result'], 'Expected a generation change during verification to abort recovery.');
    kiwi_assert_same('replacement_generation_not_active', $result['reason_code'], 'Expected active-generation recheck failure.');
    kiwi_assert_same(1, $supervisor_calls, 'Expected B to be verified before the generation changed.');
    kiwi_assert_same([], $gate->unblock_calls, 'Expected no recovery state change after the post-check failed.');
});

kiwi_run_test('Cleanup rechecks corruption gate after receipt commit before MySQL delete', function (): void {
    global $wpdb;

    $previous_wpdb = $wpdb ?? null;
    $wpdb = (object) ['prefix' => 'wp_'];
    $GLOBALS['kiwi_test_transients'] = [];
    $GLOBALS['kiwi_test_deleted_transients'] = [];
    $GLOBALS['kiwi_test_options'] = [
        'kiwi_retention_settings' => [
            'landing_page_sessions' => [
                'enabled' => true,
                'dry_run' => false,
                'retention_days' => 14,
            ],
        ],
    ];
    $allowed = [
        'allowed' => true,
        'reason_code' => 'corruption_gate_clear',
        'write_blocked' => false,
        'incident_open' => false,
        'incident_action' => 'none',
    ];
    $blocked = [
        'allowed' => false,
        'reason_code' => 'archive_corruption_incident_open',
        'write_blocked' => false,
        'incident_open' => true,
        'incident_action' => 'none',
    ];
    $gate = new Kiwi_Test_Sequenced_Safety_Gate([$allowed, $allowed, $blocked]);
    $runs = new Kiwi_Test_Retention_Cleanup_Run_Repository();
    $snapshots = new Kiwi_Test_Retention_Table_Growth_Snapshot_Repository();
    $archive = new Kiwi_Test_Retention_Sqlite_Archive_Service();
    $archive->chunks = [[
        'success' => true,
        'archive_db_path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2026.sqlite',
        'archived_rows' => 2,
        'archive_inserted_rows' => 2,
        'archive_duplicate_rows' => 0,
        'archived_primary_keys' => [1, 2],
        'has_more' => false,
    ]];
    $archive->receipt_results = [[
        'success' => true,
        'archive_inserted_count' => 2,
        'archive_duplicate_count' => 0,
    ]];
    $service = new Kiwi_Test_Retention_Cleanup_Service(
        new Kiwi_Config(),
        new Kiwi_Retention_Source_Registry(),
        $runs,
        $snapshots,
        $archive,
        new Kiwi_Test_Retention_Coverage_Gate(['status' => 'passed']),
        null,
        null,
        $gate
    );
    $service->eligible_rows = 2;
    $service->target_max_primary_key = 2;

    try {
        $service->run_source('landing_page_sessions', 'cron');
        $result = $service->run_worker('landing_page_sessions');

        kiwi_assert_same('archive_corruption_incident_open', $result['error_code'], 'Expected late Incident gate.');
        kiwi_assert_same([], $service->deleted_primary_keys, 'Expected no MySQL delete after the late gate.');
        kiwi_assert_true(count($gate->calls) >= 3, 'Expected gates before write and again before delete.');
    } finally {
        $wpdb = $previous_wpdb;
    }
});

kiwi_run_test('Bootstrap recorder has no state receipt retry or database fallback', function (): void {
    $recorder = new Kiwi_Retention_Archive_Health_Bootstrap_Recorder(
        static function (): DateTimeImmutable {
            return new DateTimeImmutable('2026-08-01T10:00:00+02:00');
        }
    );
    $result = $recorder->record('archive_directory_unavailable', 'check');
    $source = file_get_contents(__DIR__ . '/../tools/database/class-retention-archive-health-bootstrap-recorder.php');

    kiwi_assert_same('error', $result['result'], 'Expected sanitized bootstrap failure.');
    kiwi_assert_same(2, $result['_exit_code'], 'Expected bootstrap protocol exit two.');
    kiwi_assert_true(strpos($source, 'STATE_FILENAME') === false, 'Expected no central Health State file.');
    kiwi_assert_true(strpos($source, 'wpdb') === false, 'Expected no direct database fallback.');
    kiwi_assert_true(strpos($source, 'receipt') === false, 'Expected no bootstrap receipt path.');
});

kiwi_run_test('WP-CLI exposes exactly check diagnose and unblock with compact JSON protocol', function (): void {
    $source = file_get_contents(__DIR__ . '/../tools/database/kiwi-retention-archive-health.php');
    $public_methods = [];
    if (preg_match_all('/public function ([a-z_]+)\(/', $source, $matches)) {
        $public_methods = array_values(array_unique(array_filter(
            $matches[1],
            static function (string $method): bool {
                return $method !== '__construct';
            }
        )));
    }

    kiwi_assert_same(['check', 'diagnose', 'unblock'], $public_methods, 'Expected exactly three public WP-CLI modes.');
    kiwi_assert_true(strpos($source, "LOCK_EX | LOCK_NB") !== false, 'Expected the Health child to own the exclusive generation lock.');
    kiwi_assert_true(strpos($source, 'unset($result[\'_exit_code\'])') !== false, 'Expected process exit code not to be duplicated in public JSON.');
    kiwi_assert_true(strpos($source, "'scope'") === false, 'Expected no Daily/Annual scope field.');
    kiwi_assert_true(strpos($source, 'is_definitive_corruption') === false, 'Expected exceptions never to classify corruption.');
});

kiwi_run_test('Lean Health implementation contains no central reducer planner or calendar state', function (): void {
    $files = [
        __DIR__ . '/../includes/services/class-retention-archive-health-service.php',
        __DIR__ . '/../includes/services/class-retention-archive-health-controller.php',
        __DIR__ . '/../includes/services/class-retention-archive-check-supervisor.php',
    ];
    $source = implode("\n", array_map('file_get_contents', $files));

    foreach ([
        'HealthStateStore',
        'HealthTransitionReducer',
        'HealthPlanner',
        'STATE_FILENAME',
        'DAILY_ATTEMPT_LIMIT',
        'run_scheduled_annual',
        'quarantine',
    ] as $removed) {
        kiwi_assert_true(strpos($source, $removed) === false, 'Expected removed Health Control Plane symbol: ' . $removed);
    }
});

kiwi_run_test('Health results use mandatory compact fields without paths or raw exceptions', function (): void {
    $archive_service = new Kiwi_Test_Lean_Archive_Service();
    $archive_service->archives = [[
        'name' => 'kiwi_retention_archive_2026.sqlite',
        'path' => '/secret/path/kiwi_retention_archive_2026.sqlite',
    ]];
    $controller = new Kiwi_Retention_Archive_Health_Controller(
        $archive_service,
        new Kiwi_Retention_Archive_Check_Supervisor(
            new Kiwi_Config(),
            static function (): array {
                return ['result' => 'ok', 'reason_code' => 'sqlite_check_ok', 'check_completed' => true];
            }
        ),
        new Kiwi_Test_Lean_Safety_Gate(),
        new Kiwi_Operational_Event_Service(new Kiwi_Test_Operational_Event_Repository()),
        new Kiwi_Test_Retention_Cleanup_Run_Repository()
    );
    $result = $controller->diagnose('kiwi_retention_archive_2026.sqlite', 'integrity');

    foreach ([
        'schema_version',
        'command',
        'result',
        'reason_code',
        'archive',
        'check',
        'started_at',
        'finished_at',
        'duration_seconds',
    ] as $field) {
        kiwi_assert_true(array_key_exists($field, $result), 'Expected compact field: ' . $field);
    }
    $json = json_encode($result);
    kiwi_assert_true(strpos((string) $json, '/secret/path') === false, 'Expected no full archive path in output.');
    kiwi_assert_true(strpos((string) $json, 'exception') === false, 'Expected no raw exception text.');
});
