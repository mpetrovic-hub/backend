<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Retention_Archive_Health_Service
{
    private const STATE_SCHEMA_VERSION = 1;
    private const STATE_FILENAME = 'kiwi_retention_archive_health_state.json';
    private const CONTROLLER_DEFERRAL_RECEIPT_PREFIX = 'kiwi_retention_archive_health_deferral_';
    private const CONTROLLER_DEFERRAL_RECEIPT_SCHEMA_VERSION = 1;
    private const CONTROLLER_DEFERRAL_RECEIPT_LIMIT = 64;
    private const DAILY_ATTEMPT_LIMIT = 3;
    private const ACTIVE_ARCHIVE_RESOLUTION_FAILURES = [
        'active_archive_lookup_failed',
        'active_archive_path_invalid',
        'active_archive_missing',
        'controller_lock_active',
        'wp_cli_loader_unavailable',
        'plugins_loaded_hook_failed',
        'plugins_loaded_not_reached',
        'wordpress_load_failed',
        'wordpress_lifecycle_invalid',
        'required_class_missing',
        'health_service_exception',
        'health_service_unavailable',
        'health_bootstrap_failed',
    ];

    private $config;
    private $archive_service;
    private $lock_service;
    private $operational_event_service;
    private $run_repository;
    private $clock;
    private $check_runner;
    private $child_script_path;
    private $operation_started_microtime = 0.0;

    public function __construct(
        ?Kiwi_Config $config = null,
        ?Kiwi_Retention_Sqlite_Archive_Service $archive_service = null,
        ?Kiwi_Retention_Archive_Lock $lock_service = null,
        ?Kiwi_Operational_Event_Service $operational_event_service = null,
        ?callable $clock = null,
        ?callable $check_runner = null,
        string $child_script_path = '',
        ?Kiwi_Retention_Cleanup_Run_Repository $run_repository = null
    ) {
        $this->config = $config instanceof Kiwi_Config ? $config : new Kiwi_Config();
        $this->archive_service = $archive_service instanceof Kiwi_Retention_Sqlite_Archive_Service
            ? $archive_service
            : new Kiwi_Retention_Sqlite_Archive_Service($this->config);
        $this->lock_service = $lock_service instanceof Kiwi_Retention_Archive_Lock
            ? $lock_service
            : new Kiwi_Retention_Archive_Lock();
        $this->operational_event_service = $operational_event_service instanceof Kiwi_Operational_Event_Service
            ? $operational_event_service
            : new Kiwi_Operational_Event_Service();
        $this->run_repository = $run_repository instanceof Kiwi_Retention_Cleanup_Run_Repository
            ? $run_repository
            : new Kiwi_Retention_Cleanup_Run_Repository();
        $this->clock = $clock ?? static function (): DateTimeImmutable {
            return new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
        };
        $this->check_runner = $check_runner ?? function (string $archive_path, string $check): array {
            return $this->supervise_check($archive_path, $check);
        };
        $this->child_script_path = $child_script_path !== ''
            ? $child_script_path
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'database'
                . DIRECTORY_SEPARATOR . 'kiwi-retention-archive-health.php';
    }

    public function status(): array
    {
        $started_at = $this->start_operation();
        $state_read = $this->read_state();
        if (empty($state_read['valid'])) {
            return $this->result(
                'error',
                'failed',
                2,
                '',
                'status',
                '',
                (string) ($state_read['error_code'] ?? 'health_state_invalid'),
                $started_at,
                ['state_exists' => !empty($state_read['exists'])]
            );
        }

        try {
            $archive_files = $this->archive_service->list_archive_files();
        } catch (Kiwi_Retention_Archive_Discovery_Exception $error) {
            return $this->result(
                'error',
                'failed',
                2,
                '',
                'status',
                '',
                'archive_discovery_failed',
                $started_at,
                ['state_exists' => !empty($state_read['exists'])]
            );
        }

        $archives = array_map(static function (array $archive): array {
            return [
                'archive' => (string) ($archive['name'] ?? ''),
                'year' => (string) ($archive['year'] ?? ''),
                'generation' => (int) ($archive['generation'] ?? 0),
                'quarantined' => !empty($archive['quarantined']),
                'size_bytes' => (int) ($archive['size_bytes'] ?? 0),
            ];
        }, $archive_files);
        $relevant_types = [
            'retention_archive_health_check_incomplete',
            'retention_archive_corruption_detected',
            'retention_archive_receipt_invalid',
        ];
        $active_lookup = $this->resolve_active_archive();
        $active_archive = $active_lookup['archive'] ?? null;
        $open_incidents = [];
        foreach ($relevant_types as $event_type) {
            $typed_incidents = $this->operational_event_service->get_open_incidents(
                ['event_type' => $event_type],
                100
            );
            if ($typed_incidents === null) {
                return $this->result(
                    'error',
                    'failed',
                    2,
                    '',
                    'status',
                    '',
                    'operational_incident_lookup_failed',
                    $started_at
                );
            }
            $open_incidents = array_merge($open_incidents, $typed_incidents);
        }
        usort($open_incidents, static function (array $left, array $right): int {
            $left_time = (string) ($left['occurred_at'] ?? $left['created_at'] ?? '');
            $right_time = (string) ($right['occurred_at'] ?? $right['created_at'] ?? '');

            return strcmp($right_time, $left_time);
        });
        $open_incidents = array_values(array_map(static function (array $incident): array {
            return [
                'severity' => (string) ($incident['severity'] ?? ''),
                'event_type' => (string) ($incident['event_type'] ?? ''),
                'lifecycle_action' => (string) ($incident['lifecycle_action'] ?? ''),
                'reference_type' => (string) ($incident['reference_type'] ?? ''),
                'reference_id' => (string) ($incident['reference_id'] ?? ''),
                'message' => (string) ($incident['message'] ?? ''),
                'created_at' => (string) ($incident['created_at'] ?? ''),
            ];
        }, $open_incidents));

        return $this->result(
            'ok',
            'completed',
            0,
            '',
            'status',
            '',
            'status_read',
            $started_at,
            [
                'state_exists' => !empty($state_read['exists']),
                'state' => $state_read['state'],
                'archives' => $archives,
                'active_archive' => is_array($active_archive)
                    ? (string) ($active_archive['name'] ?? '')
                    : null,
                'active_archive_error' => empty($active_lookup['success'])
                    ? (string) ($active_lookup['error_code'] ?? 'active_archive_lookup_failed')
                    : null,
                'open_incidents' => $open_incidents,
            ]
        );
    }

    public function diagnose(string $archive_name, string $check): array
    {
        $started_at = $this->start_operation();
        $check = $this->normalize_check($check);
        try {
            $archive = $this->find_archive($archive_name);
        } catch (Kiwi_Retention_Archive_Discovery_Exception $error) {
            return $this->result(
                'error',
                'failed',
                2,
                '',
                'diagnose',
                $this->normalize_archive_name($archive_name),
                'archive_discovery_failed',
                $started_at
            );
        }

        if ($check === '' || !is_array($archive)) {
            return $this->result(
                'error',
                'failed',
                2,
                '',
                'diagnose',
                $this->normalize_archive_name($archive_name),
                'diagnose_input_invalid',
                $started_at
            );
        }

        $outcome = $this->run_locked_check((string) $archive['path'], $check);

        return $this->result_from_check(
            $outcome,
            $check,
            'diagnose',
            (string) $archive['name'],
            $started_at
        );
    }

    public function preflight(): array
    {
        $started_at = $this->start_operation();
        $preflight_check_started = false;
        $checks = [
            'pdo_sqlite' => class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true),
            'proc_open' => function_exists('proc_open'),
            'proc_terminate' => function_exists('proc_terminate'),
            'flock' => function_exists('flock'),
            'atomic_rename' => function_exists('rename'),
        ];
        if (in_array(false, $checks, true)) {
            return $this->result(
                'error',
                'failed',
                2,
                '',
                'preflight',
                '',
                'preflight_api_unavailable',
                $started_at,
                ['checks' => $checks]
            );
        }

        $archive_directory = $this->archive_service->get_archive_directory();
        if (!is_dir($archive_directory)
            && !@mkdir($archive_directory, 0770, true)
            && !is_dir($archive_directory)
        ) {
            return $this->result(
                'error',
                'failed',
                2,
                '',
                'preflight',
                '',
                'preflight_archive_directory_unavailable',
                $started_at,
                ['checks' => $checks]
            );
        }

        try {
            $scratch_generation = random_int(1000000, 9999999);
        } catch (Throwable $error) {
            $scratch_generation = (int) substr((string) time(), -7);
        }
        $scratch_directory = $archive_directory
            . DIRECTORY_SEPARATOR
            . '.kiwi_retention_health_preflight_'
            . max(2, $scratch_generation);
        $scratch_name = 'kiwi_retention_archive_9999_part_' . max(2, $scratch_generation) . '.sqlite';
        $scratch_path = $scratch_directory . DIRECTORY_SEPARATOR . $scratch_name;
        $state_probe = $scratch_directory
            . DIRECTORY_SEPARATOR
            . 'state';
        $state_probe_temp = $state_probe . '.tmp';
        $lock_ready_probe = $state_probe . '.lock-ready';
        $lock = null;

        try {
            if (!is_dir($scratch_directory)
                && !@mkdir($scratch_directory, 0770)
                && !is_dir($scratch_directory)
            ) {
                throw new RuntimeException('preflight_scratch_directory_unavailable');
            }
            @unlink($scratch_path);
            @unlink($scratch_path . '.lock');
            @unlink($state_probe);
            @unlink($state_probe_temp);
            @unlink($lock_ready_probe);

            $pdo = new PDO('sqlite:' . $scratch_path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE preflight_probe (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
            $pdo->exec("INSERT INTO preflight_probe (value) VALUES ('ok')");
            $pdo = null;

            $first_lock = $this->lock_service->acquire_for_archive($scratch_path);
            if (empty($first_lock['success']) || empty($first_lock['acquired'])) {
                throw new RuntimeException('preflight_lock_acquire_failed');
            }
            $lock = $first_lock['handle'];

            $contention_code = '$h=@fopen(base64_decode($argv[1],true),"c+");'
                . '$locked=is_resource($h)&&@flock($h,LOCK_EX|LOCK_NB);'
                . 'echo $locked?"acquired":"deferred";'
                . 'if(is_resource($h)){if($locked){@flock($h,LOCK_UN);}@fclose($h);}';
            $contention = $this->run_preflight_process(
                [
                    PHP_BINARY,
                    '-r',
                    $contention_code,
                    base64_encode($scratch_path . '.lock'),
                ],
                2.0
            );
            $checks['cross_process_non_blocking_lock'] = !empty($contention['started'])
                && empty($contention['timed_out'])
                && empty($contention['child_running'])
                && trim((string) ($contention['stdout'] ?? '')) === 'deferred'
                && trim((string) ($contention['stderr'] ?? '')) === '';
            if (!$checks['cross_process_non_blocking_lock']) {
                throw new RuntimeException('preflight_lock_contract_failed');
            }

            $this->lock_service->release($lock);
            $lock = null;

            $hold_lock_code = '$lock=base64_decode($argv[1],true);'
                . '$ready=base64_decode($argv[2],true);'
                . '$h=@fopen($lock,"c+");'
                . 'if(!is_resource($h)||!@flock($h,LOCK_EX|LOCK_NB)){exit(2);}'
                . 'if(@file_put_contents($ready,"ready",LOCK_EX)!==5){exit(2);}'
                . 'sleep(5);';
            $terminated_lock_child = $this->run_preflight_process(
                [
                    PHP_BINARY,
                    '-r',
                    $hold_lock_code,
                    base64_encode($scratch_path . '.lock'),
                    base64_encode($lock_ready_probe),
                ],
                0.25,
                $lock_ready_probe
            );
            $checks['timeout_kill_reap'] = !empty($terminated_lock_child['started'])
                && !empty($terminated_lock_child['ready'])
                && !empty($terminated_lock_child['timed_out'])
                && empty($terminated_lock_child['child_running']);
            if (!$checks['timeout_kill_reap']) {
                throw new RuntimeException('preflight_timeout_cleanup_failed');
            }

            $released_lock = $this->lock_service->acquire_for_archive($scratch_path);
            $checks['lock_released_after_child_termination'] = !empty($released_lock['success'])
                && !empty($released_lock['acquired']);
            $this->lock_service->release($released_lock['handle'] ?? null);
            if (!$checks['lock_released_after_child_termination']) {
                throw new RuntimeException('preflight_terminated_lock_not_released');
            }

            $written = @file_put_contents($state_probe_temp, '{"probe":true}');
            $checks['atomic_state_exchange'] = $written === 14 && @rename($state_probe_temp, $state_probe);
            if (!$checks['atomic_state_exchange']) {
                throw new RuntimeException('preflight_atomic_exchange_failed');
            }

            $child = call_user_func($this->check_runner, $scratch_path, 'quick');
            $preflight_check_started = array_key_exists('check_started', $child)
                ? !empty($child['check_started'])
                : (string) ($child['result'] ?? '') !== 'deferred';
            $checks['child_completed'] = (string) ($child['result'] ?? '') === 'ok';
            $checks['child_cleanup'] = empty($child['child_running']);
            if (!$checks['child_completed'] || !$checks['child_cleanup']) {
                throw new RuntimeException('preflight_child_contract_failed');
            }

            return $this->result(
                'ok',
                'completed',
                0,
                $preflight_check_started ? 'quick' : '',
                'preflight',
                '',
                'preflight_passed',
                $started_at,
                ['checks' => $checks]
            );
        } catch (Throwable $error) {
            return $this->result(
                'error',
                'failed',
                2,
                $preflight_check_started ? 'quick' : '',
                'preflight',
                '',
                $this->normalize_reason_code($error->getMessage(), 'preflight_failed'),
                $started_at,
                ['checks' => $checks]
            );
        } finally {
            $this->lock_service->release($lock);
            @unlink($scratch_path);
            @unlink($scratch_path . '-journal');
            @unlink($scratch_path . '-wal');
            @unlink($scratch_path . '-shm');
            @unlink($scratch_path . '.lock');
            @unlink($state_probe);
            @unlink($state_probe_temp);
            @unlink($lock_ready_probe);
            @rmdir($scratch_directory);
        }
    }

    public function scheduled(): array
    {
        $started_at = $this->start_operation();
        $archive_directory = $this->archive_service->get_archive_directory();
        if (!is_dir($archive_directory)
            && !@mkdir($archive_directory, 0770, true)
            && !is_dir($archive_directory)
        ) {
            return $this->result(
                'error',
                'failed',
                2,
                '',
                'scheduled',
                '',
                'archive_directory_unavailable',
                $started_at
            );
        }

        $controller = $this->lock_service->acquire_controller($archive_directory);
        if (empty($controller['success'])) {
            return $this->result(
                'error',
                'failed',
                2,
                '',
                'scheduled',
                '',
                (string) ($controller['error_code'] ?? 'controller_lock_failed'),
                $started_at
            );
        }
        if (empty($controller['acquired'])) {
            if (!$this->persist_controller_deferral_receipt($archive_directory)) {
                return $this->result(
                    'error',
                    'failed',
                    2,
                    '',
                    'scheduled',
                    '',
                    'controller_deferral_persist_failed',
                    $started_at
                );
            }

            return $this->result(
                'deferred',
                'incomplete',
                1,
                '',
                'scheduled',
                '',
                'controller_lock_active',
                $started_at
            );
        }

        try {
            $before_reconciliation = $this->reconcile_controller_deferral_receipts(
                $archive_directory
            );
            if (empty($before_reconciliation['success'])) {
                return $this->result(
                    'error',
                    'failed',
                    2,
                    '',
                    'scheduled',
                    '',
                    (string) (
                        $before_reconciliation['reason_code']
                        ?? 'controller_deferral_reconciliation_failed'
                    ),
                    $started_at,
                    [
                        'incident_action' => (string) (
                            $before_reconciliation['incident_action'] ?? 'none'
                        ),
                    ]
                );
            }

            $result = (function () use ($started_at): array {
                $state_read = $this->read_state();
                if (empty($state_read['valid'])) {
                    return $this->result(
                        'error',
                        'failed',
                        2,
                        '',
                        'scheduled',
                        '',
                        (string) ($state_read['error_code'] ?? 'health_state_invalid'),
                        $started_at
                    );
                }

                $state = $state_read['state'];
                $now = $this->current_datetime();
                $date = $now->format('Y-m-d');
                $daily_check = $now->format('N') === '7' ? 'integrity' : 'quick';
                $quarantine_reconciliation = $this->reconcile_unrecorded_quarantine_markers(
                    $state,
                    $date,
                    $daily_check,
                    $started_at
                );
                if (is_array($quarantine_reconciliation)) {
                    return $quarantine_reconciliation;
                }

                [$state, $daily_check] = $this->align_daily_state(
                    $state,
                    $date,
                    $daily_check
                );

                if ((string) ($state['daily']['status'] ?? '') !== 'completed') {
                    return $this->run_scheduled_daily(
                        $state,
                        $daily_check,
                        $started_at
                    );
                }

                return $this->run_scheduled_annual($state, $started_at);
            })();

            $after_reconciliation = $this->reconcile_controller_deferral_receipts(
                $archive_directory
            );
            if (empty($after_reconciliation['success'])) {
                return $this->result(
                    'error',
                    'failed',
                    2,
                    '',
                    'scheduled',
                    '',
                    (string) (
                        $after_reconciliation['reason_code']
                        ?? 'controller_deferral_reconciliation_failed'
                    ),
                    $started_at,
                    [
                        'incident_action' => (string) (
                            $after_reconciliation['incident_action'] ?? 'none'
                        ),
                    ]
                );
            }
            $after_action = (string) (
                $after_reconciliation['incident_action'] ?? ''
            );
            $before_action = (string) (
                $before_reconciliation['incident_action'] ?? ''
            );
            $receipt_action = in_array(
                $after_action,
                ['raised', 'repeated', 'resolved'],
                true
            ) ? $after_action : $before_action;
            if (($result['incident_action'] ?? null) === null
                && in_array($receipt_action, ['raised', 'repeated', 'resolved'], true)
            ) {
                $result['incident_action'] = $receipt_action;
            }

            return $result;
        } catch (Kiwi_Retention_Archive_Discovery_Exception $error) {
            return $this->result(
                'error',
                'failed',
                2,
                '',
                'scheduled',
                '',
                'archive_discovery_failed',
                $started_at
            );
        } finally {
            $this->lock_service->release($controller['handle'] ?? null);
        }
    }

    private function persist_controller_deferral_receipt(string $archive_directory): bool
    {
        $occurred_at = $this->current_datetime();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $receipt_id = bin2hex(random_bytes(16));
            } catch (Throwable $error) {
                $receipt_id = md5(uniqid('', true) . ':' . microtime(true));
            }
            $path = $archive_directory
                . DIRECTORY_SEPARATOR
                . self::CONTROLLER_DEFERRAL_RECEIPT_PREFIX
                . $receipt_id
                . '.json';
            if (is_file($path)) {
                continue;
            }
            $payload = [
                'schema_version' => self::CONTROLLER_DEFERRAL_RECEIPT_SCHEMA_VERSION,
                'receipt_id' => $receipt_id,
                'occurred_at' => $occurred_at->format(DATE_ATOM),
                'attempt_date' => $occurred_at->format('Y-m-d'),
                'check' => $occurred_at->format('N') === '7' ? 'integrity' : 'quick',
                'reason_code' => 'controller_lock_active',
            ];
            $json = function_exists('wp_json_encode')
                ? wp_json_encode($payload)
                : json_encode($payload);
            if (!is_string($json)) {
                return false;
            }
            $temporary_path = $path . '.tmp';
            $written = @file_put_contents($temporary_path, $json . "\n", LOCK_EX);
            if ($written === false || $written !== strlen($json) + 1) {
                @unlink($temporary_path);

                return false;
            }
            if (@rename($temporary_path, $path)) {
                return true;
            }
            @unlink($temporary_path);
            if (!is_file($path)) {
                return false;
            }
        }

        return false;
    }

    private function reconcile_controller_deferral_receipts(string $archive_directory): array
    {
        $receipt_read = $this->read_controller_deferral_receipts($archive_directory);
        if (empty($receipt_read['success'])) {
            return [
                'success' => false,
                'reason_code' => (string) (
                    $receipt_read['reason_code'] ?? 'controller_deferral_receipt_invalid'
                ),
                'incident_action' => 'none',
            ];
        }

        $receipts = (array) ($receipt_read['receipts'] ?? []);
        $state_read = $this->read_state();
        if (empty($state_read['valid'])) {
            return [
                'success' => false,
                'reason_code' => (string) ($state_read['error_code'] ?? 'health_state_invalid'),
                'incident_action' => 'none',
            ];
        }
        $state = $state_read['state'];
        $stored_receipt_ids = (array) (
            $state['daily']['controller_deferral_receipts'] ?? []
        );
        $available_receipt_ids = array_column($receipts, 'receipt_id');
        $accounted_receipt_ids = array_values(array_intersect(
            $stored_receipt_ids,
            $available_receipt_ids
        ));
        $state_changed = $accounted_receipt_ids !== $stored_receipt_ids;
        $incident_action = 'none';
        $current_date = $this->current_datetime()->format('Y-m-d');

        foreach ($receipts as $receipt) {
            $receipt_id = (string) ($receipt['receipt_id'] ?? '');
            if (in_array($receipt_id, $accounted_receipt_ids, true)) {
                continue;
            }
            $receipt_date = (string) ($receipt['attempt_date'] ?? '');
            $stored_attempt_date = (string) (
                $state['daily']['attempt_date'] ?? $state['daily']['date'] ?? ''
            );
            if ($receipt_date <= $current_date
                && ($stored_attempt_date === '' || $receipt_date >= $stored_attempt_date)
            ) {
                [$state, $receipt_check] = $this->align_daily_state(
                    $state,
                    $receipt_date,
                    (string) ($receipt['check'] ?? '')
                );
                if ((string) ($state['daily']['status'] ?? '') !== 'completed'
                    && (int) ($state['daily']['attempts'] ?? 0) < self::DAILY_ATTEMPT_LIMIT
                ) {
                    $state['daily']['check'] = $receipt_check;
                    $state['daily']['attempts'] = (int) (
                        $state['daily']['attempts'] ?? 0
                    ) + 1;
                    $state['daily']['status'] = 'incomplete';
                    $state['daily']['result'] = 'deferred';
                    $state['daily']['reason_code'] = 'controller_lock_active';
                    $state['daily']['completed_at'] = '';
                    if ((int) $state['daily']['attempts'] >= self::DAILY_ATTEMPT_LIMIT) {
                        $incident_action = $this->record_incomplete_incident(
                            $this->normalize_archive_name(
                                (string) ($state['daily']['archive'] ?? '')
                            ),
                            $receipt_check,
                            'controller_lock_active'
                        );
                        if ($incident_action === '') {
                            return [
                                'success' => false,
                                'reason_code' => 'incomplete_incident_persist_failed',
                                'incident_action' => 'none',
                            ];
                        }
                    }
                }
            }
            $accounted_receipt_ids[] = $receipt_id;
            $accounted_receipt_ids = array_values(array_unique($accounted_receipt_ids));
            $state['daily']['controller_deferral_receipts'] = $accounted_receipt_ids;
            $state_changed = true;
        }

        if ($state_changed) {
            $state['daily']['controller_deferral_receipts'] = $accounted_receipt_ids;
            if (!$this->write_state($state)) {
                return [
                    'success' => false,
                    'reason_code' => 'health_state_write_failed',
                    'incident_action' => $incident_action,
                ];
            }
        }

        foreach ($receipts as $receipt) {
            if (in_array(
                (string) ($receipt['receipt_id'] ?? ''),
                $accounted_receipt_ids,
                true
            )) {
                @unlink((string) ($receipt['path'] ?? ''));
            }
        }
        $remaining_receipt_ids = array_values(array_filter(
            $accounted_receipt_ids,
            static function (string $receipt_id) use ($archive_directory): bool {
                return is_file(
                    $archive_directory
                    . DIRECTORY_SEPARATOR
                    . self::CONTROLLER_DEFERRAL_RECEIPT_PREFIX
                    . $receipt_id
                    . '.json'
                );
            }
        ));
        if ($remaining_receipt_ids !== $accounted_receipt_ids) {
            $state['daily']['controller_deferral_receipts'] = $remaining_receipt_ids;
            if (!$this->write_state($state)) {
                return [
                    'success' => false,
                    'reason_code' => 'health_state_write_failed',
                    'incident_action' => $incident_action,
                ];
            }
        }

        return [
            'success' => true,
            'reason_code' => '',
            'incident_action' => $incident_action,
        ];
    }

    private function read_controller_deferral_receipts(string $archive_directory): array
    {
        $paths = @glob(
            $archive_directory
            . DIRECTORY_SEPARATOR
            . self::CONTROLLER_DEFERRAL_RECEIPT_PREFIX
            . '*.json'
        );
        if ($paths === false || count($paths) > self::CONTROLLER_DEFERRAL_RECEIPT_LIMIT) {
            return [
                'success' => false,
                'reason_code' => 'controller_deferral_receipt_invalid',
                'receipts' => [],
            ];
        }

        $receipts = [];
        foreach ($paths as $path) {
            $filename = basename($path);
            if (preg_match(
                '/^' . self::CONTROLLER_DEFERRAL_RECEIPT_PREFIX
                . '([a-f0-9]{32})\.json$/',
                $filename,
                $matches
            ) !== 1 || !is_file($path) || is_link($path)) {
                return [
                    'success' => false,
                    'reason_code' => 'controller_deferral_receipt_invalid',
                    'receipts' => [],
                ];
            }
            $raw = @file_get_contents($path);
            $payload = is_string($raw) && strlen($raw) <= 2048
                ? json_decode($raw, true)
                : null;
            $receipt_id = (string) ($matches[1] ?? '');
            $occurred_at = is_array($payload)
                ? (string) ($payload['occurred_at'] ?? '')
                : '';
            try {
                $timestamp = new DateTimeImmutable($occurred_at);
            } catch (Throwable $error) {
                $timestamp = null;
            }
            $attempt_date = is_array($payload)
                ? (string) ($payload['attempt_date'] ?? '')
                : '';
            $check = is_array($payload)
                ? $this->normalize_check((string) ($payload['check'] ?? ''))
                : '';
            if (!is_array($payload)
                || (int) ($payload['schema_version'] ?? 0)
                    !== self::CONTROLLER_DEFERRAL_RECEIPT_SCHEMA_VERSION
                || (string) ($payload['receipt_id'] ?? '') !== $receipt_id
                || !$timestamp instanceof DateTimeImmutable
                || $timestamp->format(DATE_ATOM) !== $occurred_at
                || $timestamp->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d')
                    !== $attempt_date
                || $check === ''
                || (string) ($payload['reason_code'] ?? '') !== 'controller_lock_active'
            ) {
                return [
                    'success' => false,
                    'reason_code' => 'controller_deferral_receipt_invalid',
                    'receipts' => [],
                ];
            }
            $receipts[] = [
                'receipt_id' => $receipt_id,
                'attempt_date' => $attempt_date,
                'check' => $check,
                'occurred_at' => $occurred_at,
                'path' => $path,
            ];
        }
        usort($receipts, static function (array $left, array $right): int {
            $time_compare = strcmp(
                (string) ($left['occurred_at'] ?? ''),
                (string) ($right['occurred_at'] ?? '')
            );

            return $time_compare !== 0
                ? $time_compare
                : strcmp(
                    (string) ($left['receipt_id'] ?? ''),
                    (string) ($right['receipt_id'] ?? '')
                );
        });

        return [
            'success' => true,
            'reason_code' => '',
            'receipts' => $receipts,
        ];
    }

    public function record_scheduled_bootstrap_failure(string $reason_code): array
    {
        $started_at = $this->start_operation();
        $reason_code = $this->normalize_reason_code($reason_code, 'health_bootstrap_failed');
        if (!in_array($reason_code, self::ACTIVE_ARCHIVE_RESOLUTION_FAILURES, true)) {
            $reason_code = 'health_bootstrap_failed';
        }
        $archive_directory = $this->archive_service->get_archive_directory();
        if (!is_dir($archive_directory)
            && !@mkdir($archive_directory, 0770, true)
            && !is_dir($archive_directory)
        ) {
            return $this->result(
                'error',
                'failed',
                2,
                '',
                'bootstrap',
                '',
                'archive_directory_unavailable',
                $started_at
            );
        }

        $controller = $this->lock_service->acquire_controller($archive_directory);
        if (empty($controller['success']) || empty($controller['acquired'])) {
            return $this->result(
                'error',
                'failed',
                2,
                '',
                'bootstrap',
                '',
                (string) ($controller['error_code'] ?? 'controller_lock_failed'),
                $started_at
            );
        }

        try {
            $state_read = $this->read_state();
            if (empty($state_read['valid'])) {
                return $this->result(
                    'error',
                    'failed',
                    2,
                    '',
                    'bootstrap',
                    '',
                    (string) ($state_read['error_code'] ?? 'health_state_invalid'),
                    $started_at
                );
            }

            $state = $state_read['state'];
            $now = $this->current_datetime();
            $date = $now->format('Y-m-d');
            $daily_check = $now->format('N') === '7' ? 'integrity' : 'quick';
            [$state, $daily_check] = $this->align_daily_state($state, $date, $daily_check);
            if ((string) ($state['daily']['status'] ?? '') === 'completed') {
                return $this->result(
                    'error',
                    'failed',
                    2,
                    '',
                    'bootstrap',
                    '',
                    $reason_code,
                    $started_at
                );
            }
            if ((int) ($state['daily']['attempts'] ?? 0) >= self::DAILY_ATTEMPT_LIMIT) {
                return $this->result(
                    'error',
                    'failed',
                    2,
                    $daily_check,
                    'daily',
                    (string) ($state['daily']['archive'] ?? ''),
                    $reason_code,
                    $started_at
                );
            }

            $archive_name = $this->normalize_archive_name((string) ($state['daily']['archive'] ?? ''));
            $state['daily']['archive'] = $archive_name;
            $state['daily']['check'] = $daily_check;
            $state['daily']['attempts'] = (int) ($state['daily']['attempts'] ?? 0) + 1;
            $state['daily']['status'] = 'incomplete';
            $state['daily']['result'] = 'error';
            $state['daily']['reason_code'] = $reason_code;
            $state['daily']['completed_at'] = '';
            $incident_action = 'none';
            if ((int) $state['daily']['attempts'] >= self::DAILY_ATTEMPT_LIMIT) {
                $incident_action = $this->record_incomplete_incident(
                    $archive_name,
                    $daily_check,
                    $reason_code
                );
                if ($incident_action === '') {
                    return $this->result(
                        'error',
                        'failed',
                        2,
                        $daily_check,
                        'daily',
                        $archive_name,
                        'incomplete_incident_persist_failed',
                        $started_at
                    );
                }
            }
            if (!$this->write_state($state)) {
                return $this->state_write_failure(
                    $daily_check,
                    'daily',
                    $archive_name,
                    $started_at
                );
            }

            return $this->result(
                'error',
                'failed',
                2,
                $daily_check,
                'daily',
                $archive_name,
                $reason_code,
                $started_at,
                ['incident_action' => $incident_action]
            );
        } finally {
            $this->lock_service->release($controller['handle'] ?? null);
        }
    }

    private function align_daily_state(array $state, string $date, string $daily_check): array
    {
        $stored_daily_date = (string) ($state['daily']['date'] ?? '');
        $stored_daily_status = (string) ($state['daily']['status'] ?? '');
        $stored_attempt_date = (string) (
            $state['daily']['attempt_date'] ?? $stored_daily_date
        );
        $daily_overdue = $stored_daily_date !== ''
            && $stored_daily_date !== $date
            && $stored_daily_status === 'incomplete';
        if ($daily_overdue) {
            $daily_check = $this->normalize_check((string) ($state['daily']['check'] ?? ''));
            if ($stored_attempt_date !== $date) {
                $state['daily']['attempt_date'] = $date;
                $state['daily']['attempts'] = 0;
                $state['daily']['controller_deferral_receipts'] = [];
            }
        } elseif ($stored_daily_date !== $date) {
            $state['daily'] = [
                'date' => $date,
                'attempt_date' => $date,
                'archive' => '',
                'check' => $daily_check,
                'attempts' => 0,
                'status' => 'pending',
                'result' => '',
                'reason_code' => '',
                'completed_at' => '',
                'controller_deferral_receipts' => [],
            ];
        }

        return [$state, $daily_check];
    }

    private function run_scheduled_daily(
        array $state,
        string $check,
        string $started_at
    ): array
    {
        if ((int) ($state['daily']['attempts'] ?? 0) >= self::DAILY_ATTEMPT_LIMIT) {
            return $this->result(
                'inconclusive',
                'incomplete',
                1,
                '',
                'daily',
                (string) ($state['daily']['archive'] ?? ''),
                'daily_attempt_limit_reached',
                $started_at
            );
        }

        $persisted_archive_name = (string) ($state['daily']['status'] ?? '') === 'incomplete'
            ? $this->normalize_archive_name((string) ($state['daily']['archive'] ?? ''))
            : '';
        if ($persisted_archive_name !== '') {
            $persisted_archive = $this->find_archive($persisted_archive_name);
            $active_lookup = is_array($persisted_archive)
                ? ['success' => true, 'archive' => $persisted_archive, 'error_code' => '']
                : [
                    'success' => false,
                    'archive' => null,
                    'error_code' => 'daily_archive_unavailable',
                ];
        } else {
            $active_lookup = $this->resolve_active_archive();
        }
        if (empty($active_lookup['success'])) {
            $reason_code = (string) ($active_lookup['error_code'] ?? 'active_archive_lookup_failed');
            $state['daily']['archive'] = $persisted_archive_name;
            $state['daily']['check'] = $check;
            $state['daily']['attempts'] = (int) ($state['daily']['attempts'] ?? 0) + 1;
            $state['daily']['status'] = 'incomplete';
            $state['daily']['result'] = 'error';
            $state['daily']['reason_code'] = $reason_code;
            $state['daily']['completed_at'] = '';
            $incident_action = 'none';
            if ((int) $state['daily']['attempts'] >= self::DAILY_ATTEMPT_LIMIT) {
                $incident_action = $this->record_incomplete_incident(
                    $persisted_archive_name,
                    $check,
                    $reason_code
                );
                if ($incident_action === '') {
                    return $this->result(
                        'error',
                        'failed',
                        2,
                        '',
                        'daily',
                        $persisted_archive_name,
                        'incomplete_incident_persist_failed',
                        $started_at
                    );
                }
            }
            if (!$this->write_state($state)) {
                return $this->state_write_failure(
                    '',
                    'daily',
                    $persisted_archive_name,
                    $started_at
                );
            }

            return $this->result(
                'error',
                'failed',
                2,
                '',
                'daily',
                $persisted_archive_name,
                $reason_code,
                $started_at,
                ['incident_action' => $incident_action]
            );
        }
        $archive = $active_lookup['archive'] ?? null;
        if (is_array($archive) && !empty($archive['quarantined'])) {
            $archive_name = (string) ($archive['name'] ?? '');
            $marker = $this->read_quarantine_marker_details((string) ($archive['path'] ?? ''));
            $marker_check = $this->normalize_check((string) ($marker['check'] ?? ''));
            $reason_code = trim((string) ($marker['reason_code'] ?? ''));
            $completed_at = (string) ($marker['detected_at'] ?? '');
            $state['daily']['archive'] = $archive_name;
            $state['daily']['check'] = $marker_check !== '' ? $marker_check : $check;
            $state['daily']['attempts'] = max(1, (int) ($state['daily']['attempts'] ?? 0));
            $state['daily']['status'] = 'completed';
            $state['daily']['result'] = 'corruption_detected';
            $state['daily']['reason_code'] = $reason_code !== ''
                ? $reason_code
                : 'sqlite_quarantine_marker_present';
            $state['daily']['completed_at'] = $this->is_valid_timestamp($completed_at)
                ? $completed_at
                : $this->now();
            if (!$this->write_state($state)) {
                return $this->state_write_failure(
                    '',
                    'daily',
                    $archive_name,
                    $started_at
                );
            }
            $lookup_recovery_action = $this->record_incomplete_recovery(
                '',
                'corruption_detected'
            );
            $archive_recovery_action = $this->record_incomplete_recovery(
                $archive_name,
                'corruption_detected'
            );
            $incident_action = in_array(
                'resolved',
                [$lookup_recovery_action, $archive_recovery_action],
                true
            ) ? 'resolved' : 'none';

            return $this->result(
                'corruption_detected',
                'completed',
                0,
                '',
                'daily',
                $archive_name,
                (string) $state['daily']['reason_code'],
                $started_at,
                ['incident_action' => $incident_action]
            );
        }
        if (!is_array($archive)) {
            $state['daily']['archive'] = '';
            $state['daily']['attempts'] = 0;
            $state['daily']['status'] = 'completed';
            $state['daily']['result'] = 'no_work';
            $state['daily']['reason_code'] = 'active_archive_unavailable';
            $state['daily']['completed_at'] = $this->now();
            if (!$this->write_state($state)) {
                return $this->state_write_failure('', 'daily', '', $started_at);
            }
            return $this->result(
                'no_work',
                'completed',
                0,
                '',
                'daily',
                '',
                'active_archive_unavailable',
                $started_at
            );
        }

        $archive_name = (string) $archive['name'];
        $state['daily']['archive'] = $archive_name;
        $state['daily']['check'] = $check;
        $state['daily']['attempts'] = (int) ($state['daily']['attempts'] ?? 0) + 1;
        $corruption_incident_action = 'none';
        $outcome = $this->run_locked_check(
            (string) $archive['path'],
            $check,
            function (array $corruption_outcome) use (
                $archive,
                $archive_name,
                $check,
                &$corruption_incident_action
            ): bool {
                $corruption_reason = (string) (
                    $corruption_outcome['reason_code'] ?? 'sqlite_check_reported_corruption'
                );

                try {
                    $marker_persisted = $this->archive_service->mark_quarantined(
                        (string) $archive['path'],
                        [
                            'detected_at' => $this->now(),
                            'check' => $check,
                            'reason_code' => $corruption_reason,
                            'active_generation' => true,
                        ]
                    );
                } catch (Throwable $error) {
                    $marker_persisted = false;
                }
                try {
                    $recorded_action = $this->record_corruption_incident(
                        $archive_name,
                        true,
                        $check,
                        $corruption_reason
                    );
                    $corruption_incident_action = $recorded_action !== ''
                        ? $recorded_action
                        : 'none';
                    $incident_persisted = $recorded_action !== '';
                } catch (Throwable $error) {
                    $corruption_incident_action = 'none';
                    $incident_persisted = false;
                }

                return $marker_persisted && $incident_persisted;
            }
        );
        $result_name = (string) ($outcome['result'] ?? 'error');
        $reason_code = (string) ($outcome['reason_code'] ?? 'health_check_failed');

        if (in_array($result_name, ['ok', 'corruption_detected'], true)) {
            $incident_action = 'none';
            if ($result_name === 'corruption_detected') {
                $incident_action = $corruption_incident_action;
                if (empty($outcome['quarantine_transition_success'])) {
                    return $this->result(
                        'error',
                        'failed',
                        2,
                        $check,
                        'daily',
                        $archive_name,
                        'corruption_state_persist_failed',
                        $started_at,
                        ['incident_action' => $incident_action]
                    );
                }
            }

            $state['daily']['status'] = 'completed';
            $state['daily']['result'] = $result_name;
            $state['daily']['reason_code'] = $reason_code;
            $state['daily']['completed_at'] = $this->now();
            if (!$this->write_state($state)) {
                return $this->state_write_failure($check, 'daily', $archive_name, $started_at);
            }
            if ($result_name === 'corruption_detected'
                && !$this->archive_service->mark_quarantine_reconciled(
                    (string) $archive['path'],
                    $this->now()
                )
            ) {
                return $this->result(
                    'error',
                    'failed',
                    2,
                    $check,
                    'daily',
                    $archive_name,
                    'quarantine_marker_reconciliation_failed',
                    $started_at,
                    ['incident_action' => $incident_action]
                );
            }
            $lookup_recovery_action = $this->record_incomplete_recovery('', $result_name);
            $archive_recovery_action = $this->record_incomplete_recovery($archive_name, $result_name);
            if ($incident_action === 'none'
                && in_array('resolved', [$lookup_recovery_action, $archive_recovery_action], true)
            ) {
                $incident_action = 'resolved';
            }

            return $this->result_from_check(
                $outcome,
                $check,
                'daily',
                $archive_name,
                $started_at,
                $incident_action
            );
        }

        $state['daily']['status'] = 'incomplete';
        $state['daily']['result'] = $result_name;
        $state['daily']['reason_code'] = $reason_code;
        $incident_action = 'none';
        if ((int) $state['daily']['attempts'] >= self::DAILY_ATTEMPT_LIMIT) {
            $incident_action = $this->record_incomplete_incident(
                $archive_name,
                $check,
                $reason_code
            );
            if ($incident_action === '') {
                return $this->result(
                    'error',
                    'failed',
                    2,
                    $check,
                    'daily',
                    $archive_name,
                    'incomplete_incident_persist_failed',
                    $started_at
                );
            }
        }
        if (!$this->write_state($state)) {
            return $this->state_write_failure($check, 'daily', $archive_name, $started_at);
        }

        return $this->result_from_check(
            $outcome,
            $check,
            'daily',
            $archive_name,
            $started_at,
            $incident_action
        );
    }

    private function run_scheduled_annual(array $state, string $started_at): array
    {
        $now = $this->current_datetime();
        $year = $now->format('Y');
        $jan_second = new DateTimeImmutable($year . '-01-02 00:00:00', new DateTimeZone('Europe/Berlin'));
        if ($now < $jan_second) {
            return $this->result(
                'no_work',
                'completed',
                0,
                '',
                'annual',
                '',
                'annual_campaign_not_due',
                $started_at
            );
        }

        $annual_cycle_year = (string) ($state['annual']['cycle_year'] ?? '');
        $annual_pending = array_values(array_diff(
            (array) ($state['annual']['snapshot'] ?? []),
            (array) ($state['annual']['completed'] ?? [])
        ));
        $unfinished_previous_campaign = $annual_cycle_year !== ''
            && $annual_cycle_year !== $year
            && (string) ($state['annual']['status'] ?? '') !== 'completed'
            && !empty($annual_pending);
        if ($annual_cycle_year !== $year && !$unfinished_previous_campaign) {
            $snapshot = [];
            foreach ($this->archive_service->list_archive_files() as $archive) {
                if (empty($archive['quarantined'])) {
                    $snapshot[] = (string) ($archive['name'] ?? '');
                }
            }
            $state['annual'] = [
                'cycle_year' => $year,
                'snapshot' => $snapshot,
                'completed' => [],
                'results' => [],
                'status' => empty($snapshot) ? 'completed' : 'running',
            ];
            if (!$this->write_state($state)) {
                return $this->state_write_failure('', 'annual', '', $started_at);
            }
        }

        $pending = array_values(array_diff(
            (array) ($state['annual']['snapshot'] ?? []),
            (array) ($state['annual']['completed'] ?? [])
        ));
        if (empty($pending)) {
            $state['annual']['status'] = 'completed';
            if (!$this->write_state($state)) {
                return $this->state_write_failure('', 'annual', '', $started_at);
            }

            return $this->result(
                'no_work',
                'completed',
                0,
                '',
                'annual',
                '',
                'annual_campaign_complete',
                $started_at
            );
        }

        $archive = $this->find_archive((string) $pending[0]);
        if (!is_array($archive)) {
            return $this->result(
                'error',
                'failed',
                2,
                '',
                'annual',
                (string) $pending[0],
                'annual_archive_unavailable',
                $started_at
            );
        }
        if (!empty($archive['quarantined'])) {
            $archive_name = (string) $archive['name'];
            $marker = $this->read_quarantine_marker_details((string) $archive['path']);
            $reason_code = trim((string) ($marker['reason_code'] ?? ''));
            $incident_action = 'none';
            if (!$this->is_valid_timestamp((string) ($marker['controller_recorded_at'] ?? ''))) {
                $incident_action = $this->record_corruption_incident(
                    $archive_name,
                    !empty($marker['active_generation']),
                    'integrity',
                    $reason_code !== '' ? $reason_code : 'sqlite_quarantine_marker_present'
                );
                if ($incident_action === '') {
                    return $this->result(
                        'error',
                        'failed',
                        2,
                        '',
                        'annual',
                        $archive_name,
                        'corruption_incident_reconciliation_failed',
                        $started_at
                    );
                }
            }
            $state['annual']['completed'][] = $archive_name;
            $state['annual']['completed'] = array_values(array_unique($state['annual']['completed']));
            $state['annual']['results'][$archive_name] = 'corruption_detected';
            $remaining = array_diff($state['annual']['snapshot'], $state['annual']['completed']);
            $state['annual']['status'] = empty($remaining) ? 'completed' : 'running';
            if (!$this->write_state($state)) {
                return $this->state_write_failure('', 'annual', $archive_name, $started_at);
            }
            if (!$this->archive_service->mark_quarantine_reconciled(
                (string) $archive['path'],
                $this->now()
            )) {
                return $this->result(
                    'error',
                    'failed',
                    2,
                    '',
                    'annual',
                    $archive_name,
                    'quarantine_marker_reconciliation_failed',
                    $started_at,
                    ['incident_action' => $incident_action]
                );
            }
            $recovery_action = $this->record_incomplete_recovery(
                $archive_name,
                'corruption_detected'
            );
            if ($incident_action === 'none' && $recovery_action === 'resolved') {
                $incident_action = 'resolved';
            }

            return $this->result(
                'corruption_detected',
                'completed',
                0,
                '',
                'annual',
                $archive_name,
                $reason_code !== '' ? $reason_code : 'sqlite_quarantine_marker_present',
                $started_at,
                ['incident_action' => $incident_action]
            );
        }

        $archive_name = (string) $archive['name'];
        $active_lookup = $this->resolve_active_archive();
        if (empty($active_lookup['success'])) {
            return $this->result(
                'error',
                'failed',
                2,
                '',
                'annual',
                $archive_name,
                (string) ($active_lookup['error_code'] ?? 'active_archive_lookup_failed'),
                $started_at
            );
        }
        $active_archive = $active_lookup['archive'] ?? null;
        $active_archive_name = is_array($active_archive)
            ? (string) ($active_archive['name'] ?? '')
            : '';
        $corruption_incident_action = 'none';
        $outcome = $this->run_locked_check(
            (string) $archive['path'],
            'integrity',
            function (array $corruption_outcome) use (
                $archive,
                $archive_name,
                $active_archive_name,
                &$corruption_incident_action
            ): bool {
                $corruption_reason = (string) (
                    $corruption_outcome['reason_code'] ?? 'sqlite_check_reported_corruption'
                );

                try {
                    $marker_persisted = $this->archive_service->mark_quarantined(
                        (string) $archive['path'],
                        [
                            'detected_at' => $this->now(),
                            'check' => 'integrity',
                            'reason_code' => $corruption_reason,
                            'active_generation' => $archive_name === $active_archive_name,
                        ]
                    );
                } catch (Throwable $error) {
                    $marker_persisted = false;
                }
                try {
                    $recorded_action = $this->record_corruption_incident(
                        $archive_name,
                        $archive_name === $active_archive_name,
                        'integrity',
                        $corruption_reason
                    );
                    $corruption_incident_action = $recorded_action !== ''
                        ? $recorded_action
                        : 'none';
                    $incident_persisted = $recorded_action !== '';
                } catch (Throwable $error) {
                    $corruption_incident_action = 'none';
                    $incident_persisted = false;
                }

                return $marker_persisted && $incident_persisted;
            }
        );
        $result_name = (string) ($outcome['result'] ?? 'error');
        $reason_code = (string) ($outcome['reason_code'] ?? 'health_check_failed');

        $incident_action = $result_name === 'corruption_detected'
            ? $corruption_incident_action
            : 'none';
        if (in_array($result_name, ['ok', 'corruption_detected'], true)) {
            if ($result_name === 'corruption_detected'
                && empty($outcome['quarantine_transition_success'])
            ) {
                return $this->result(
                    'error',
                    'failed',
                    2,
                    'integrity',
                    'annual',
                    $archive_name,
                    'corruption_state_persist_failed',
                    $started_at,
                    ['incident_action' => $incident_action]
                );
            }

            $state['annual']['completed'][] = $archive_name;
            $state['annual']['completed'] = array_values(array_unique($state['annual']['completed']));
            $state['annual']['results'][$archive_name] = $result_name;
            $remaining = array_diff($state['annual']['snapshot'], $state['annual']['completed']);
            $state['annual']['status'] = empty($remaining) ? 'completed' : 'running';
            if (!$this->write_state($state)) {
                return $this->state_write_failure('integrity', 'annual', $archive_name, $started_at);
            }
            if ($result_name === 'corruption_detected'
                && !$this->archive_service->mark_quarantine_reconciled(
                    (string) $archive['path'],
                    $this->now()
                )
            ) {
                return $this->result(
                    'error',
                    'failed',
                    2,
                    'integrity',
                    'annual',
                    $archive_name,
                    'quarantine_marker_reconciliation_failed',
                    $started_at,
                    ['incident_action' => $incident_action]
                );
            }
            $recovery_action = $this->record_incomplete_recovery($archive_name, $result_name);
            if ($incident_action === 'none' && $recovery_action === 'resolved') {
                $incident_action = 'resolved';
            }
        }

        return $this->result_from_check(
            $outcome,
            'integrity',
            'annual',
            $archive_name,
            $started_at,
            $incident_action
        );
    }

    private function run_locked_check(
        string $archive_path,
        string $check,
        ?callable $corruption_transition = null
    ): array
    {
        $lock = $this->lock_service->acquire_shared_for_archive($archive_path);
        if (empty($lock['success'])) {
            return [
                'result' => 'error',
                'reason_code' => (string) ($lock['error_code'] ?? 'archive_lock_failed'),
                'duration_seconds' => 0.0,
                'child_running' => false,
                'check_started' => false,
            ];
        }
        if (empty($lock['acquired'])) {
            return [
                'result' => 'deferred',
                'reason_code' => 'archive_lock_active',
                'duration_seconds' => 0.0,
                'child_running' => false,
                'check_started' => false,
            ];
        }

        try {
            $outcome = call_user_func($this->check_runner, $archive_path, $check);
            if (!array_key_exists('check_started', $outcome)) {
                $outcome['check_started'] = (string) ($outcome['result'] ?? '') !== 'deferred';
            }
            if ((string) ($outcome['result'] ?? '') === 'corruption_detected'
                && is_callable($corruption_transition)
            ) {
                $outcome['archive_write_block_persisted'] = ($lock['handle'] ?? null)
                    instanceof Kiwi_Retention_Archive_Lock_Handle
                    && $lock['handle']->persist_write_blocked();
                try {
                    $transition_persisted = (bool) call_user_func($corruption_transition, $outcome);
                } catch (Throwable $error) {
                    $transition_persisted = false;
                }
                $outcome['quarantine_transition_success'] = !empty(
                    $outcome['archive_write_block_persisted']
                ) && $transition_persisted;
            }

            return $outcome;
        } catch (Throwable $error) {
            return [
                'result' => 'error',
                'reason_code' => 'health_child_exception',
                'duration_seconds' => 0.0,
                'child_running' => false,
                'check_started' => false,
            ];
        } finally {
            $this->lock_service->release($lock['handle'] ?? null);
        }
    }

    private function supervise_check(string $archive_path, string $check): array
    {
        $started = microtime(true);
        if (!function_exists('proc_open')
            || !function_exists('proc_get_status')
            || !function_exists('proc_terminate')
            || !is_file($this->child_script_path)
        ) {
            return [
                'result' => 'error',
                'reason_code' => 'health_child_api_unavailable',
                'duration_seconds' => 0.0,
                'child_running' => false,
                'check_started' => false,
            ];
        }

        try {
            $readiness_token = bin2hex(random_bytes(16));
        } catch (Throwable $error) {
            $readiness_token = substr(hash('sha256', uniqid('', true)), 0, 32);
        }
        $readiness_path = dirname($archive_path)
            . DIRECTORY_SEPARATOR
            . '.kiwi_retention_health_child_'
            . $readiness_token
            . '.ready';
        @unlink($readiness_path);
        $payload = json_encode([
            'archive_path' => $archive_path,
            'check' => $check,
            'readiness_path' => $readiness_path,
        ]);
        if (!is_string($payload)) {
            return [
                'result' => 'error',
                'reason_code' => 'health_child_payload_invalid',
                'duration_seconds' => 0.0,
                'child_running' => false,
                'check_started' => false,
            ];
        }

        $command = [
            PHP_BINARY,
            $this->child_script_path,
            '--kiwi-retention-health-child',
            base64_encode($payload),
        ];
        $pipes = [];
        $process = @proc_open(
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
            @unlink($readiness_path);

            return [
                'result' => 'error',
                'reason_code' => 'health_child_start_failed',
                'duration_seconds' => microtime(true) - $started,
                'child_running' => false,
                'check_started' => false,
            ];
        }

        @fclose($pipes[0]);
        @stream_set_blocking($pipes[1], false);
        @stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $child_lock_acquired = false;
        $timed_out = false;
        $timeout_seconds = $this->config->get_retention_archive_health_timeout_seconds();
        $last_status = ['running' => true, 'exitcode' => -1];

        while (true) {
            $child_lock_acquired = $child_lock_acquired
                || @file_get_contents($readiness_path) === 'locked';
            $last_status = proc_get_status($process);

            if (empty($last_status['running'])) {
                break;
            }

            if ((microtime(true) - $started) >= $timeout_seconds) {
                $timed_out = true;
                @proc_terminate($process);
                $terminate_deadline = microtime(true) + 0.5;
                do {
                    usleep(20000);
                    $last_status = proc_get_status($process);
                } while (!empty($last_status['running']) && microtime(true) < $terminate_deadline);
                if (!empty($last_status['running'])) {
                    @proc_terminate($process, 9);
                }
                $reap_deadline = microtime(true) + 2.0;
                do {
                    usleep(20000);
                    $last_status = proc_get_status($process);
                } while (!empty($last_status['running']) && microtime(true) < $reap_deadline);
                break;
            }

            usleep(20000);
        }

        $status_after = proc_get_status($process);
        $status_after = is_array($status_after) ? $status_after : ['running' => true];
        $child_running = !empty($status_after['running']);
        $child_lock_acquired = $child_lock_acquired
            || @file_get_contents($readiness_path) === 'locked';
        if (!$child_running) {
            $stdout .= (string) @stream_get_contents($pipes[1]);
            $stderr .= (string) @stream_get_contents($pipes[2]);
            @unlink($readiness_path);
        }
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $close_exit_code = $this->close_process_if_stopped($process, $status_after);
        $child_exit_code = isset($last_status['exitcode'])
            ? (int) $last_status['exitcode']
            : -1;
        if ($child_exit_code < 0 && is_int($close_exit_code)) {
            $child_exit_code = $close_exit_code;
        }
        $duration = microtime(true) - $started;

        if ($timed_out) {
            return [
                'result' => 'inconclusive',
                'reason_code' => 'health_child_timeout',
                'duration_seconds' => $duration,
                'child_running' => $child_running,
                'check_started' => $child_lock_acquired,
            ];
        }

        $stdout = trim($stdout);
        if ($stdout === ''
            || strpos($stdout, "\n") !== false
            || trim($stderr) !== ''
        ) {
            return [
                'result' => 'error',
                'reason_code' => 'health_child_output_invalid',
                'duration_seconds' => $duration,
                'child_running' => $child_running,
                'check_started' => $child_lock_acquired,
            ];
        }

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)
            || !in_array((string) ($decoded['result'] ?? ''), ['ok', 'corruption_detected', 'error'], true)
            || !array_key_exists('check_started', $decoded)
            || !is_bool($decoded['check_started'])
        ) {
            return [
                'result' => 'error',
                'reason_code' => 'health_child_result_invalid',
                'duration_seconds' => $duration,
                'child_running' => $child_running,
                'check_started' => $child_lock_acquired,
            ];
        }

        $decoded_check_started = !empty($decoded['check_started']);
        $expected_exit_code = (string) $decoded['result'] === 'error' ? 2 : 0;
        if ($child_exit_code !== $expected_exit_code) {
            return [
                'result' => 'error',
                'reason_code' => 'health_child_exit_invalid',
                'duration_seconds' => $duration,
                'child_running' => $child_running,
                'check_started' => $decoded_check_started,
            ];
        }

        return [
            'result' => (string) $decoded['result'],
            'reason_code' => $this->normalize_reason_code(
                (string) ($decoded['reason_code'] ?? ''),
                (string) $decoded['result'] === 'ok' ? 'sqlite_check_ok' : 'sqlite_check_failed'
            ),
            'duration_seconds' => $duration,
            'child_running' => $child_running,
            'check_started' => $decoded_check_started,
        ];
    }

    private function run_preflight_process(
        array $command,
        float $timeout_seconds,
        string $readiness_path = ''
    ): array {
        $started = microtime(true);
        $pipes = [];
        $process = @proc_open(
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
            return [
                'started' => false,
                'ready' => false,
                'timed_out' => false,
                'child_running' => false,
                'stdout' => '',
                'stderr' => '',
            ];
        }

        @fclose($pipes[0]);
        @stream_set_blocking($pipes[1], false);
        @stream_set_blocking($pipes[2], false);
        $ready = $readiness_path === '';
        $ready_at = $ready ? $started : 0.0;
        $timed_out = false;
        $last_status = ['running' => true, 'exitcode' => -1];

        while (true) {
            if (!$ready && is_file($readiness_path)) {
                $ready = true;
                $ready_at = microtime(true);
            }
            $last_status = proc_get_status($process);
            if (empty($last_status['running'])) {
                break;
            }
            $now = microtime(true);
            $deadline_reached = $ready
                ? ($now - $ready_at) >= max(0.05, $timeout_seconds)
                : ($now - $started) >= 2.0;
            if ($deadline_reached) {
                $timed_out = true;
                @proc_terminate($process);
                $terminate_deadline = microtime(true) + 0.5;
                do {
                    usleep(20000);
                    $last_status = proc_get_status($process);
                } while (!empty($last_status['running']) && microtime(true) < $terminate_deadline);
                if (!empty($last_status['running'])) {
                    @proc_terminate($process, 9);
                }
                $reap_deadline = microtime(true) + 2.0;
                do {
                    usleep(20000);
                    $last_status = proc_get_status($process);
                } while (!empty($last_status['running']) && microtime(true) < $reap_deadline);
                break;
            }

            usleep(20000);
        }

        $stdout = (string) @stream_get_contents($pipes[1]);
        $stderr = (string) @stream_get_contents($pipes[2]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $status_after = proc_get_status($process);
        $status_after = is_array($status_after) ? $status_after : ['running' => true];
        $this->close_process_if_stopped($process, $status_after);

        return [
            'started' => true,
            'ready' => $ready,
            'timed_out' => $timed_out,
            'child_running' => !empty($status_after['running']),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private function close_process_if_stopped($process, array $status): int
    {
        if (!is_resource($process) || !empty($status['running'])) {
            return -1;
        }

        $exit_code = @proc_close($process);

        return is_int($exit_code) ? $exit_code : -1;
    }

    private function read_state(): array
    {
        $path = $this->get_state_path();
        if (!is_file($path)) {
            return [
                'exists' => false,
                'valid' => true,
                'state' => $this->default_state(),
                'error_code' => '',
            ];
        }

        $raw = @file_get_contents($path);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($state) || !$this->is_valid_state($state)) {
            return [
                'exists' => true,
                'valid' => false,
                'state' => null,
                'error_code' => 'health_state_invalid',
            ];
        }

        return [
            'exists' => true,
            'valid' => true,
            'state' => $state,
            'error_code' => '',
        ];
    }

    private function is_valid_state(array $state): bool
    {
        if ((int) ($state['schema_version'] ?? 0) !== self::STATE_SCHEMA_VERSION
            || !isset($state['daily'], $state['annual'])
            || !is_array($state['daily'])
            || !is_array($state['annual'])
        ) {
            return false;
        }

        $daily = $state['daily'];
        $annual = $state['annual'];
        foreach (['date', 'archive', 'check', 'status', 'result', 'reason_code', 'completed_at'] as $field) {
            if (!array_key_exists($field, $daily) || !is_string($daily[$field])) {
                return false;
            }
        }
        $daily_status = $daily['status'];
        $daily_result = $daily['result'];
        $daily_attempts = $daily['attempts'] ?? null;
        if (!in_array($daily_status, ['pending', 'incomplete', 'completed'], true)
            || !is_int($daily_attempts)
            || $daily_attempts < 0
            || $daily_attempts > self::DAILY_ATTEMPT_LIMIT
            || !in_array($daily_result, ['', 'ok', 'corruption_detected', 'deferred', 'inconclusive', 'error', 'no_work'], true)
        ) {
            return false;
        }
        $daily_date = $daily['date'];
        $daily_attempt_date = array_key_exists('attempt_date', $daily)
            ? $daily['attempt_date']
            : $daily_date;
        $daily_archive = $daily['archive'];
        $daily_check = $daily['check'];
        $daily_reason = $daily['reason_code'];
        $daily_completed_at = $daily['completed_at'];
        $controller_deferral_receipts = $daily['controller_deferral_receipts'] ?? [];
        $daily_archive_valid = $daily_archive === '' || $this->normalize_archive_name($daily_archive) !== '';
        if (!$daily_archive_valid
            || !is_string($daily_attempt_date)
            || ($daily_date !== '' && !$this->is_valid_calendar_date($daily_date))
            || ($daily_attempt_date !== '' && !$this->is_valid_calendar_date($daily_attempt_date))
            || (($daily_date === '') !== ($daily_attempt_date === ''))
            || ($daily_date !== '' && $daily_attempt_date < $daily_date)
            || ($daily_check !== '' && !in_array($daily_check, ['quick', 'integrity'], true))
            || ($daily_completed_at !== '' && !$this->is_valid_timestamp($daily_completed_at))
            || !is_array($controller_deferral_receipts)
            || count($controller_deferral_receipts) > self::CONTROLLER_DEFERRAL_RECEIPT_LIMIT
            || array_values($controller_deferral_receipts) !== $controller_deferral_receipts
            || count($controller_deferral_receipts)
                !== count(array_unique($controller_deferral_receipts))
        ) {
            return false;
        }
        foreach ($controller_deferral_receipts as $receipt_id) {
            if (!is_string($receipt_id)
                || preg_match('/^[a-f0-9]{32}$/', $receipt_id) !== 1
            ) {
                return false;
            }
        }
        if ($daily_status === 'pending'
            && ($daily_result !== ''
                || $daily_attempts !== 0
                || $daily_archive !== ''
                || $daily_reason !== ''
                || $daily_completed_at !== ''
                || (($daily_date === '') !== ($daily_check === '')))
        ) {
            return false;
        }
        $incomplete_archive_valid = $daily_archive !== ''
            || in_array($daily_reason, self::ACTIVE_ARCHIVE_RESOLUTION_FAILURES, true);
        if ($daily_status === 'incomplete'
            && (!$this->is_valid_calendar_date($daily_date)
                || !in_array($daily_check, ['quick', 'integrity'], true)
                || !$incomplete_archive_valid
                || $daily_attempts < 1
                || !in_array($daily_result, ['deferred', 'inconclusive', 'error'], true)
                || $daily_reason === ''
                || $daily_completed_at !== '')
        ) {
            return false;
        }
        if ($daily_status === 'completed'
            && (!$this->is_valid_calendar_date($daily_date)
                || !in_array($daily_check, ['quick', 'integrity'], true)
                || !in_array($daily_result, ['ok', 'corruption_detected', 'no_work'], true)
                || $daily_reason === ''
                || !$this->is_valid_timestamp($daily_completed_at)
                || ($daily_result === 'no_work' && ($daily_archive !== '' || $daily_attempts !== 0))
                || ($daily_result !== 'no_work' && ($daily_archive === '' || $daily_attempts < 1)))
        ) {
            return false;
        }

        if (!isset($annual['cycle_year'], $annual['status'])
            || !is_string($annual['cycle_year'])
            || !is_string($annual['status'])
        ) {
            return false;
        }
        $annual_cycle_year = $annual['cycle_year'];
        $annual_status = $annual['status'];
        $snapshot = $annual['snapshot'] ?? null;
        $completed = $annual['completed'] ?? null;
        $results = $annual['results'] ?? null;
        if (!in_array($annual_status, ['pending', 'running', 'completed'], true)
            || !is_array($snapshot)
            || !is_array($completed)
            || !is_array($results)
        ) {
            return false;
        }
        foreach (array_merge($snapshot, $completed) as $archive_name) {
            if (!is_string($archive_name) || $this->normalize_archive_name($archive_name) === '') {
                return false;
            }
        }
        foreach ($results as $archive_name => $annual_result) {
            if (!is_string($archive_name)
                || $this->normalize_archive_name($archive_name) === ''
                || !is_string($annual_result)
                || !in_array($annual_result, ['ok', 'corruption_detected', 'skipped'], true)
            ) {
                return false;
            }
        }
        if (
            count($snapshot) !== count(array_unique($snapshot))
            || count($completed) !== count(array_unique($completed))
            || array_diff($completed, $snapshot) !== []
            || array_diff(array_keys($results), $snapshot) !== []
            || array_diff($completed, array_keys($results)) !== []
            || array_diff(array_keys($results), $completed) !== []
        ) {
            return false;
        }
        if (($annual_cycle_year !== '' && preg_match('/^[0-9]{4}$/', $annual_cycle_year) !== 1)
            || ($annual_status === 'pending'
                && ($annual_cycle_year !== '' || $snapshot !== [] || $completed !== [] || $results !== []))
            || ($annual_status !== 'pending' && $annual_cycle_year === '')
            || ($annual_status === 'running' && count($completed) >= count($snapshot))
            || ($annual_status === 'completed' && count($completed) !== count($snapshot))
        ) {
            return false;
        }

        return true;
    }

    private function is_valid_calendar_date(string $date): bool
    {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date) !== 1) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Berlin'));

        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }

    private function is_valid_timestamp(string $timestamp): bool
    {
        if ($timestamp === '') {
            return false;
        }

        try {
            $parsed = new DateTimeImmutable($timestamp);

            return $parsed->format(DATE_ATOM) === $timestamp;
        } catch (Throwable $error) {
            return false;
        }
    }

    private function write_state(array $state): bool
    {
        $state['schema_version'] = self::STATE_SCHEMA_VERSION;
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($state)
            : json_encode($state);
        if (!is_string($json)) {
            return false;
        }

        $path = $this->get_state_path();
        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (Throwable $error) {
            $suffix = substr(md5(uniqid('', true)), 0, 16);
        }
        $temporary_path = $path . '.tmp.' . $suffix;
        $written = @file_put_contents($temporary_path, $json . "\n", LOCK_EX);
        if ($written === false || $written !== strlen($json) + 1) {
            @unlink($temporary_path);

            return false;
        }

        if (!@rename($temporary_path, $path)) {
            @unlink($temporary_path);

            return false;
        }

        return true;
    }

    private function default_state(): array
    {
        return [
            'schema_version' => self::STATE_SCHEMA_VERSION,
            'daily' => [
                'date' => '',
                'attempt_date' => '',
                'archive' => '',
                'check' => '',
                'attempts' => 0,
                'status' => 'pending',
                'result' => '',
                'reason_code' => '',
                'completed_at' => '',
                'controller_deferral_receipts' => [],
            ],
            'annual' => [
                'cycle_year' => '',
                'snapshot' => [],
                'completed' => [],
                'results' => [],
                'status' => 'pending',
            ],
        ];
    }

    private function get_state_path(): string
    {
        return $this->archive_service->get_archive_directory()
            . DIRECTORY_SEPARATOR
            . self::STATE_FILENAME;
    }

    private function find_latest_current_year_archive(): ?array
    {
        $year = $this->current_datetime()->format('Y');
        $matches = array_values(array_filter(
            $this->archive_service->list_archive_files(),
            static function (array $archive) use ($year): bool {
                return (string) ($archive['year'] ?? '') === $year;
            }
        ));
        if (empty($matches)) {
            return null;
        }

        return $matches[count($matches) - 1];
    }

    private function resolve_active_archive(): array
    {
        try {
            $open_archive_state = $this->run_repository->find_open_archive_state();
        } catch (Throwable $error) {
            $open_archive_state = null;
        }
        if ($open_archive_state === null) {
            return [
                'success' => false,
                'archive' => null,
                'error_code' => 'active_archive_lookup_failed',
            ];
        }

        if (!empty($open_archive_state)) {
            $open_archive_path = trim((string) ($open_archive_state['archive_db_path'] ?? ''));
            try {
                $safe_path = $this->archive_service->resolve_archive_db_path($open_archive_path);
            } catch (Throwable $error) {
                return [
                    'success' => false,
                    'archive' => null,
                    'error_code' => 'active_archive_path_invalid',
                ];
            }

            $archive = $this->find_archive(basename($safe_path));
            if (!is_array($archive) && $this->has_archive_progress($open_archive_state)) {
                return [
                    'success' => false,
                    'archive' => null,
                    'error_code' => 'active_archive_missing',
                ];
            }

            return [
                'success' => true,
                'archive' => $archive,
                'error_code' => '',
            ];
        }

        return [
            'success' => true,
            'archive' => $this->find_latest_current_year_archive(),
            'error_code' => '',
        ];
    }

    private function has_archive_progress(array $open_archive_state): bool
    {
        foreach ([
            'archived_rows',
            'deleted_rows',
            'archive_last_primary_key',
            'delete_last_primary_key',
        ] as $field) {
            if ((int) ($open_archive_state[$field] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    private function read_quarantine_marker_details(string $archive_path): array
    {
        if ($archive_path === '' || !$this->archive_service->is_quarantined($archive_path)) {
            return [];
        }

        try {
            $marker_path = $this->archive_service->get_quarantine_marker_path($archive_path);
        } catch (Throwable $error) {
            return [];
        }

        $raw = @file_get_contents($marker_path);
        $details = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($details) ? $details : [];
    }

    private function reconcile_unrecorded_quarantine_markers(
        array $state,
        string $date,
        string $daily_check,
        string $started_at
    ): ?array {
        foreach ($this->archive_service->list_archive_files() as $archive) {
            if (empty($archive['quarantined'])) {
                continue;
            }

            $archive_name = (string) ($archive['name'] ?? '');
            $archive_path = (string) ($archive['path'] ?? '');
            $marker = $this->read_quarantine_marker_details($archive_path);
            if ($this->is_valid_timestamp((string) ($marker['controller_recorded_at'] ?? ''))) {
                continue;
            }

            $annual_pending = in_array(
                $archive_name,
                (array) ($state['annual']['snapshot'] ?? []),
                true
            ) && !in_array(
                $archive_name,
                (array) ($state['annual']['completed'] ?? []),
                true
            );
            if ($annual_pending
                && (string) ($state['daily']['date'] ?? '') === $date
                && (string) ($state['daily']['status'] ?? '') === 'completed'
            ) {
                continue;
            }

            $already_recorded = (
                (string) ($state['daily']['archive'] ?? '') === $archive_name
                && (string) ($state['daily']['result'] ?? '') === 'corruption_detected'
            ) || (string) ($state['annual']['results'][$archive_name] ?? '') === 'corruption_detected';
            $incident_action = 'none';
            if (!$already_recorded) {
                $marker_check = $this->normalize_check((string) ($marker['check'] ?? ''));
                $reason_code = trim((string) ($marker['reason_code'] ?? ''));
                $completed_at = (string) ($marker['detected_at'] ?? '');
                $incident_action = $this->record_corruption_incident(
                    $archive_name,
                    !empty($marker['active_generation']),
                    $marker_check !== '' ? $marker_check : $daily_check,
                    $reason_code !== '' ? $reason_code : 'sqlite_quarantine_marker_present'
                );
                if ($incident_action === '') {
                    return $this->result(
                        'error',
                        'failed',
                        2,
                        '',
                        'daily',
                        $archive_name,
                        'corruption_incident_reconciliation_failed',
                        $started_at
                    );
                }
                $state['daily'] = [
                    'date' => $date,
                    'attempt_date' => $date,
                    'archive' => $archive_name,
                    'check' => $marker_check !== '' ? $marker_check : $daily_check,
                    'attempts' => 1,
                    'status' => 'completed',
                    'result' => 'corruption_detected',
                    'reason_code' => $reason_code !== ''
                        ? $reason_code
                        : 'sqlite_quarantine_marker_present',
                    'completed_at' => $this->is_valid_timestamp($completed_at)
                        ? $completed_at
                        : $this->now(),
                ];
                if (!$this->write_state($state)) {
                    return $this->state_write_failure(
                        (string) $state['daily']['check'],
                        'daily',
                        $archive_name,
                        $started_at
                    );
                }
                $this->record_incomplete_recovery($archive_name, 'corruption_detected');
            }

            if (!$this->archive_service->mark_quarantine_reconciled($archive_path, $this->now())) {
                return $this->result(
                    'error',
                    'failed',
                    2,
                    '',
                    'daily',
                    $archive_name,
                    'quarantine_marker_reconciliation_failed',
                    $started_at,
                    ['incident_action' => $incident_action]
                );
            }

            if (!$already_recorded) {
                return $this->result(
                    'corruption_detected',
                    'completed',
                    0,
                    '',
                    'daily',
                    $archive_name,
                    (string) $state['daily']['reason_code'],
                    $started_at,
                    ['incident_action' => $incident_action]
                );
            }
        }

        return null;
    }

    private function find_archive(string $archive_name): ?array
    {
        $archive_name = $this->normalize_archive_name($archive_name);
        if ($archive_name === '') {
            return null;
        }

        foreach ($this->archive_service->list_archive_files() as $archive) {
            if ((string) ($archive['name'] ?? '') === $archive_name) {
                return $archive;
            }
        }

        return null;
    }

    private function record_incomplete_incident(string $archive, string $check, string $reason_code): string
    {
        $subject = $archive !== '' ? $archive : 'active_archive_lookup';

        return $this->operational_event_service->record_failure_action([
            'area' => 'retention',
            'severity' => 'error',
            'event_type' => 'retention_archive_health_check_incomplete',
            'correlation_key' => 'retention_archive_health_incomplete_' . hash('sha256', $subject),
            'idempotency_key' => 'retention_archive_health_incomplete_' . hash(
                'sha256',
                $subject . ':' . $this->current_datetime()->format('Y-m-d')
            ),
            'reference_type' => $archive !== '' ? 'retention_archive' : 'retention_archive_lookup',
            'reference_id' => $subject,
            'message' => 'Retention archive health check remained incomplete after all daily attempts.',
            'raw_error_text' => $reason_code,
            'context' => [
                'archive' => $archive,
                'check' => $check,
                'reason_code' => $reason_code,
                'attempts' => self::DAILY_ATTEMPT_LIMIT,
                'operator_review_within_workdays' => 1,
            ],
        ]);
    }

    private function record_incomplete_recovery(string $archive, string $result): string
    {
        $subject = $archive !== '' ? $archive : 'active_archive_lookup';

        return $this->operational_event_service->record_recovery_action([
            'area' => 'retention',
            'severity' => 'info',
            'event_type' => 'retention_archive_health_check_incomplete',
            'correlation_key' => 'retention_archive_health_incomplete_' . hash('sha256', $subject),
            'reference_type' => $archive !== '' ? 'retention_archive' : 'retention_archive_lookup',
            'reference_id' => $subject,
            'message' => $archive !== ''
                ? 'Retention archive health check completed after an earlier incomplete daily cycle.'
                : 'Retention archive lookup recovered after an earlier incomplete daily cycle.',
            'context' => [
                'archive' => $archive,
                'result' => $result,
            ],
        ]);
    }

    private function record_corruption_incident(
        string $archive,
        bool $active_generation,
        string $check,
        string $reason_code
    ): string {
        return $this->operational_event_service->record_failure_action([
            'area' => 'retention',
            'severity' => 'critical',
            'event_type' => 'retention_archive_corruption_detected',
            'correlation_key' => 'retention_archive_corruption_' . hash('sha256', $archive),
            'idempotency_key' => 'retention_archive_corruption_' . hash(
                'sha256',
                $archive . ':' . $check . ':' . $this->current_datetime()->format('Y-m-d')
            ),
            'reference_type' => 'retention_archive',
            'reference_id' => $archive,
            'message' => 'A complete SQLite archive health check confirmed corruption.',
            'raw_error_text' => $reason_code,
            'context' => [
                'archive' => $archive,
                'check' => $check,
                'reason_code' => $reason_code,
                'active_generation' => $active_generation,
                'operator_review_within_workdays' => 3,
            ],
        ]);
    }

    private function result_from_check(
        array $outcome,
        string $check,
        string $scope,
        string $archive,
        string $started_at,
        string $incident_action = 'none'
    ): array {
        $result = (string) ($outcome['result'] ?? 'error');
        $mapping = [
            'ok' => ['completed', 0],
            'corruption_detected' => ['completed', 0],
            'deferred' => ['incomplete', 1],
            'inconclusive' => ['incomplete', 1],
            'error' => ['failed', 2],
        ];
        $status_and_exit = $mapping[$result] ?? $mapping['error'];

        return $this->result(
            isset($mapping[$result]) ? $result : 'error',
            $status_and_exit[0],
            $status_and_exit[1],
            !empty($outcome['check_started']) ? $check : '',
            $scope,
            $archive,
            (string) ($outcome['reason_code'] ?? 'health_check_failed'),
            $started_at,
            [
                'duration_seconds' => round((float) ($outcome['duration_seconds'] ?? 0.0), 6),
                'child_running' => !empty($outcome['child_running']),
                'incident_action' => $incident_action,
            ]
        );
    }

    private function result(
        string $result,
        string $status,
        int $exit_code,
        string $check,
        string $scope,
        string $archive,
        string $reason_code,
        string $started_at,
        array $extra = []
    ): array {
        $finished_at = $this->now();
        $duration_seconds = isset($extra['duration_seconds'])
            ? (float) $extra['duration_seconds']
            : max(0.0, microtime(true) - $this->operation_started_microtime);

        $payload = array_merge([
            'schema_version' => 1,
            'status' => $status,
            'exit_code' => $exit_code,
            'check' => null,
            'scope' => $scope,
            'archive' => null,
            'result' => $result,
            'reason_code' => $this->normalize_reason_code($reason_code, 'health_check_failed'),
            'started_at' => $started_at,
            'finished_at' => $finished_at,
            'duration_seconds' => round($duration_seconds, 6),
            'incident_action' => null,
        ], $extra);

        $normalized_check = $this->normalize_check($check);
        $payload['check'] = $normalized_check === 'quick'
            ? 'quick_check'
            : ($normalized_check === 'integrity' ? 'integrity_check' : null);
        $normalized_archive = $this->normalize_archive_name($archive);
        $payload['archive'] = $normalized_archive !== '' ? $normalized_archive : null;
        $incident_action = strtolower(trim((string) ($payload['incident_action'] ?? '')));
        $payload['incident_action'] = in_array(
            $incident_action,
            ['raised', 'repeated', 'resolved'],
            true
        ) ? $incident_action : null;

        return $payload;
    }

    private function state_write_failure(
        string $check,
        string $scope,
        string $archive,
        string $started_at
    ): array {
        return $this->result(
            'error',
            'failed',
            2,
            $check,
            $scope,
            $archive,
            'health_state_write_failed',
            $started_at
        );
    }

    private function normalize_archive_name(string $archive_name): string
    {
        $archive_name = trim($archive_name);

        return preg_match(
            '/^kiwi_retention_archive_[0-9]{4}(?:_part_(?:[2-9]|[1-9][0-9]+))?\.sqlite$/',
            $archive_name
        ) === 1 ? $archive_name : '';
    }

    private function normalize_check(string $check): string
    {
        $check = strtolower(trim($check));

        return in_array($check, ['quick', 'integrity'], true) ? $check : '';
    }

    private function normalize_reason_code(string $reason_code, string $fallback): string
    {
        $reason_code = strtolower(trim($reason_code));
        $reason_code = preg_replace('/[^a-z0-9_]+/', '_', $reason_code);
        $reason_code = is_string($reason_code) ? trim($reason_code, '_') : '';

        return substr($reason_code !== '' ? $reason_code : $fallback, 0, 100);
    }

    private function current_datetime(): DateTimeImmutable
    {
        $value = call_user_func($this->clock);

        return $value instanceof DateTimeImmutable
            ? $value->setTimezone(new DateTimeZone('Europe/Berlin'))
            : new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    }

    private function now(): string
    {
        return $this->current_datetime()->format(DATE_ATOM);
    }

    private function start_operation(): string
    {
        $this->operation_started_microtime = microtime(true);

        return $this->now();
    }
}
