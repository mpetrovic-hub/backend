<?php

require_once dirname(__DIR__, 2) . '/includes/services/class-retention-archive-name.php';
require_once dirname(__DIR__, 2) . '/includes/services/class-retention-archive-write-block.php';
require_once __DIR__ . '/class-retention-archive-health-bootstrap-recorder.php';
require_once __DIR__ . '/class-retention-archive-health-read-brake.php';

function kiwi_retention_archive_health_assert_sidecars_clear(string $real_path): void
{
    foreach ([
        [
            'path' => $real_path . '-wal',
            'invalid_reason' => 'sqlite_wal_state_invalid',
            'nonempty_reason' => 'sqlite_wal_not_empty',
        ],
        [
            'path' => $real_path . '-journal',
            'invalid_reason' => 'sqlite_rollback_journal_state_invalid',
            'nonempty_reason' => 'sqlite_rollback_journal_not_empty',
        ],
    ] as $sidecar) {
        $sidecar_path = $sidecar['path'];
        clearstatcache(true, $sidecar_path);
        if (is_link($sidecar_path)
            || (file_exists($sidecar_path) && !is_file($sidecar_path))
        ) {
            throw new RuntimeException($sidecar['invalid_reason']);
        }
        if (is_file($sidecar_path)) {
            $sidecar_size = @filesize($sidecar_path);
            if (!is_int($sidecar_size)) {
                throw new RuntimeException($sidecar['invalid_reason']);
            }
            if ($sidecar_size > 0) {
                throw new RuntimeException($sidecar['nonempty_reason']);
            }
        }
    }

}

function kiwi_retention_archive_health_open_readonly(string $real_path): PDO
{
    kiwi_retention_archive_health_assert_sidecars_clear($real_path);

    $uri_path = implode('/', array_map(
        'rawurlencode',
        explode('/', str_replace('\\', '/', $real_path))
    ));

    return new PDO('sqlite:file:' . $uri_path . '?mode=ro&immutable=1');
}

function kiwi_retention_archive_health_read_proc_io(): ?array
{
    $contents = @file_get_contents('/proc/self/io');
    if (!is_string($contents)) {
        return null;
    }

    $values = [];
    foreach (preg_split('/\r?\n/', trim($contents)) ?: [] as $line) {
        if (preg_match('/^(read_bytes|write_bytes|syscw):\s*(\d+)$/', $line, $matches) === 1) {
            $values[$matches[1]] = (int) $matches[2];
        }
    }

    return count($values) === 3 ? $values : null;
}

function kiwi_retention_archive_health_io_snapshot(): array
{
    $usage = function_exists('getrusage') ? @getrusage() : false;

    return [
        'proc_self_io' => kiwi_retention_archive_health_read_proc_io(),
        'getrusage' => is_array($usage) ? $usage : null,
    ];
}

function kiwi_retention_archive_health_io_delta(?array $before, ?array $after, array $fields): array
{
    if (!is_array($before) || !is_array($after)) {
        return ['available' => false];
    }

    $delta = ['available' => true];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $before)
            || !array_key_exists($field, $after)
            || !is_numeric($before[$field])
            || !is_numeric($after[$field])
            || (float) $after[$field] < (float) $before[$field]
        ) {
            return ['available' => false];
        }
        $delta[$field] = (int) $after[$field] - (int) $before[$field];
    }

    return $delta;
}

function kiwi_retention_archive_health_public_root(): ?string
{
    $current = realpath(__DIR__);
    while (is_string($current) && $current !== '') {
        if (is_file($current . DIRECTORY_SEPARATOR . 'wp-load.php')) {
            return $current;
        }

        $parent = dirname($current);
        if ($parent === $current) {
            break;
        }
        $current = $parent;
    }

    $document_roots = [];
    $server_document_root = $_SERVER['DOCUMENT_ROOT'] ?? null;
    if (is_string($server_document_root) && trim($server_document_root) !== '') {
        $document_roots[] = $server_document_root;
    }
    $environment_document_root = getenv('DOCUMENT_ROOT');
    if (is_string($environment_document_root) && trim($environment_document_root) !== '') {
        $document_roots[] = $environment_document_root;
    }
    foreach ($document_roots as $document_root) {
        $document_root = realpath($document_root);
        if (is_string($document_root)
            && is_file($document_root . DIRECTORY_SEPARATOR . 'wp-load.php')
        ) {
            return $document_root;
        }
    }

    return null;
}

function kiwi_retention_archive_health_write_io_record(array $record): void
{
    $home = getenv('HOME');
    $absolute_home = is_string($home)
        && ($home !== '' && $home[0] === DIRECTORY_SEPARATOR
            || DIRECTORY_SEPARATOR === '\\' && preg_match('/^[A-Za-z]:\\\\/', $home) === 1);
    if (!$absolute_home) {
        return;
    }

    $home_real = realpath($home);
    $directory = $home . DIRECTORY_SEPARATOR . 'codex-deploy';
    $directory_real = realpath($directory);
    if (!is_string($home_real)
        || !is_string($directory_real)
        || is_link($directory)
        || !is_dir($directory_real)
        || strpos($directory_real . DIRECTORY_SEPARATOR, $home_real . DIRECTORY_SEPARATOR) !== 0
    ) {
        return;
    }

    $directory_stat = @stat($directory_real);
    $home_stat = @stat($home_real);
    $public_root = kiwi_retention_archive_health_public_root();
    if (!is_array($directory_stat)
        || !is_array($home_stat)
        || !isset($directory_stat['mode'])
        || !isset($directory_stat['uid'], $home_stat['uid'])
        || (int) $directory_stat['uid'] !== (int) $home_stat['uid']
        || (((int) $directory_stat['mode']) & 0077) !== 0
        || !is_string($public_root)
        || kiwi_retention_archive_health_path_is_within($directory_real, $public_root)
    ) {
        return;
    }

    $path = $directory_real . DIRECTORY_SEPARATOR . 'issue-124-archive-health-io.json';
    if (file_exists($path) || is_link($path)) {
        return;
    }

    $json = json_encode($record, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || strpos($json, "\n") !== false) {
        return;
    }

    $old_umask = umask(0077);
    $handle = @fopen($path, 'x');
    umask($old_umask);
    if (!is_resource($handle)) {
        return;
    }

    $complete = false;
    try {
        if (!@chmod($path, 0600)) {
            return;
        }
        $written = @fwrite($handle, $json . "\n");
        $complete = $written === strlen($json) + 1
            && @fflush($handle)
            && (!function_exists('fsync') || @fsync($handle));
    } finally {
        @fclose($handle);
        if (!$complete) {
            @unlink($path);
        }
    }
}

function kiwi_retention_archive_health_path_is_within(string $path, string $directory): bool
{
    $directory = rtrim($directory, DIRECTORY_SEPARATOR);
    $prefix = $directory . DIRECTORY_SEPARATOR;
    $path_with_separator = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (DIRECTORY_SEPARATOR === '\\') {
        return strcasecmp($path, $directory) === 0
            || strncasecmp($path_with_separator, $prefix, strlen($prefix)) === 0;
    }

    return $path === $directory
        || strncmp($path_with_separator, $prefix, strlen($prefix)) === 0;
}

function kiwi_retention_archive_health_io_record(array $before, array $after, float $started, float $finished): array
{
    return [
        'schema' => 'kiwi_retention_archive_health_io_v1',
        'scope' => 'sqlite_pragma_quick_check',
        'started_at_utc' => gmdate('Y-m-d\TH:i:s\Z', (int) $started),
        'finished_at_utc' => gmdate('Y-m-d\TH:i:s\Z', (int) $finished),
        'duration_seconds' => max(0, $finished - $started),
        'proc_self_io' => kiwi_retention_archive_health_io_delta(
            $before['proc_self_io'] ?? null,
            $after['proc_self_io'] ?? null,
            ['read_bytes', 'write_bytes', 'syscw']
        ),
        'getrusage' => kiwi_retention_archive_health_io_delta(
            $before['getrusage'] ?? null,
            $after['getrusage'] ?? null,
            ['ru_inblock', 'ru_oublock']
        ),
    ];
}

if (PHP_SAPI === 'cli'
    && isset($argv[1], $argv[2])
    && $argv[1] === '--kiwi-retention-health-child'
) {
    $payload_raw = base64_decode((string) $argv[2], true);
    $payload = is_string($payload_raw) ? json_decode($payload_raw, true) : null;
    $archive_path = is_array($payload) ? trim((string) ($payload['archive_path'] ?? '')) : '';
    $readiness_path = is_array($payload) ? trim((string) ($payload['readiness_path'] ?? '')) : '';
    $check = is_array($payload) ? strtolower(trim((string) ($payload['check'] ?? ''))) : '';
    $persist_write_block_on_corruption = is_array($payload)
        && !empty($payload['persist_write_block_on_corruption']);
    $io_diagnostic = $check === 'quick';
    $allow_blocked_recovery_verification = is_array($payload)
        && !empty($payload['allow_blocked_recovery_verification']);
    $corruption_handoff_timeout_seconds = is_array($payload)
        ? min(7200, max(30, (int) ($payload['corruption_handoff_timeout_seconds'] ?? 7200)))
        : 7200;
    $write_readiness_state = static function (string $path, string $state): bool {
        $resource = @fopen($path, 'c+b');
        if (!is_resource($resource)) {
            return false;
        }

        try {
            return @rewind($resource)
                && @ftruncate($resource, 0)
                && @fwrite($resource, $state) === strlen($state)
                && @fflush($resource)
                && (!function_exists('fsync') || @fsync($resource));
        } finally {
            @fclose($resource);
        }
    };
    $result = [
        'result' => 'error',
        'reason_code' => 'health_child_input_invalid',
        'check_completed' => false,
    ];
    $io_record = null;

    if (in_array($check, ['quick', 'integrity'], true)
        && Kiwi_Retention_Archive_Name::parse(basename($archive_path)) !== null
        && $readiness_path !== ''
        && is_file($archive_path)
        && !is_link($archive_path)
        && class_exists('PDO')
        && in_array('sqlite', PDO::getAvailableDrivers(), true)
    ) {
        $lock_resource = null;
        try {
            $real_path = realpath($archive_path);
            $archive_directory = is_string($real_path) ? realpath(dirname($real_path)) : false;
            $readiness_directory = realpath(dirname($readiness_path));
            if (!is_string($real_path)
                || $real_path === ''
                || !is_string($archive_directory)
                || !is_string($readiness_directory)
                || $archive_directory !== $readiness_directory
                || preg_match(
                    '/^\.kiwi_retention_health_child_[a-f0-9]{32}\.ready$/',
                    basename($readiness_path)
                ) !== 1
            ) {
                throw new RuntimeException('health_child_path_invalid');
            }

            $lock_resource = @fopen($real_path . '.lock', 'c+');
            if (!is_resource($lock_resource)) {
                throw new RuntimeException('health_child_lock_open_failed');
            }
            if (!@flock($lock_resource, LOCK_EX | LOCK_NB)) {
                $result = [
                    'result' => 'deferred',
                    'reason_code' => 'archive_lock_active',
                    'check_completed' => false,
                ];
            } else {
                register_shutdown_function(static function (string $path): void {
                    @unlink($path);
                }, $readiness_path);
                $readiness_resource = @fopen($readiness_path, 'x');
                if (!is_resource($readiness_resource)
                    || @fwrite($readiness_resource, 'locked') !== 6
                    || !@fflush($readiness_resource)
                ) {
                    throw new RuntimeException('health_child_lock_handshake_failed');
                }
                @fclose($readiness_resource);

                $write_blocked = Kiwi_Retention_Archive_Write_Block::exists(
                    $real_path . '.lock'
                );
                $transition_source = Kiwi_Retention_Archive_Write_Block
                    ::get_replacement_transition_source($real_path . '.lock');
                if ($transition_source === null) {
                    $result = [
                        'result' => 'error',
                        'reason_code' => 'replacement_transition_state_invalid',
                        'check_completed' => false,
                    ];
                } elseif ($write_blocked && !$allow_blocked_recovery_verification) {
                    $result = [
                        'result' => 'deferred',
                        'reason_code' => 'archive_corruption_write_blocked',
                        'check_completed' => false,
                    ];
                } elseif ($transition_source !== '' && !$allow_blocked_recovery_verification) {
                    $result = [
                        'result' => 'deferred',
                        'reason_code' => 'replacement_transition_write_blocked',
                        'check_completed' => false,
                    ];
                } else {
                    kiwi_retention_archive_health_assert_sidecars_clear($real_path);
                    $brake = new Kiwi_Retention_Archive_Health_Read_Brake(
                        $real_path,
                        $payload['health_read_units_per_second'] ?? null
                    );
                    $pdo = $brake->open_verified_archive();
                    $io_before = [];
                    $io_started = null;
                    if ($io_diagnostic) {
                        try {
                            $io_before = kiwi_retention_archive_health_io_snapshot();
                        } catch (Throwable $error) {
                            $io_before = [];
                        }
                        $io_started = microtime(true);
                    }
                    try {
                        $rows = $pdo->query('PRAGMA ' . $check . '_check')->fetchAll(PDO::FETCH_COLUMN);
                    } catch (Throwable $error) {
                        $brake->verify_after_check($pdo);
                        throw $error;
                    } finally {
                        if ($io_diagnostic && is_float($io_started)) {
                            try {
                                $io_finished = microtime(true);
                                $io_after = kiwi_retention_archive_health_io_snapshot();
                                $io_record = kiwi_retention_archive_health_io_record(
                                    $io_before,
                                    $io_after,
                                    $io_started,
                                    $io_finished
                                );
                            } catch (Throwable $error) {
                                $io_record = null;
                            }
                        }
                    }
                    $brake->verify_after_check($pdo);
                    $rows = is_array($rows) ? array_values(array_map('strval', $rows)) : [];
                    if (count($rows) === 1 && strtolower(trim($rows[0])) === 'ok') {
                        $result = [
                            'result' => 'ok',
                            'reason_code' => 'sqlite_check_ok',
                            'check_completed' => true,
                        ];
                    } else {
                        $write_blocked = false;
                        if ($persist_write_block_on_corruption) {
                            $write_blocked = Kiwi_Retention_Archive_Write_Block::persist(
                                $real_path . '.lock'
                            );
                        }
                        if ($persist_write_block_on_corruption && !$write_blocked) {
                            $handoff_state = '';
                            if ($write_readiness_state($readiness_path, 'corruption_gate_required')) {
                                $handoff_deadline = microtime(true) + $corruption_handoff_timeout_seconds;
                                do {
                                    usleep(20000);
                                    $handoff_state = (string) @file_get_contents($readiness_path);
                                } while (!in_array($handoff_state, [
                                    'corruption_gate_persisted',
                                    'corruption_gate_failed',
                                ], true) && microtime(true) < $handoff_deadline);
                            }

                            if ($handoff_state === 'corruption_gate_persisted') {
                                $result = [
                                    'result' => 'corruption_detected',
                                    'reason_code' => 'sqlite_check_reported_corruption',
                                    'check_completed' => true,
                                    'write_blocked' => false,
                                    'incident_open' => true,
                                ];
                            } else {
                                do {
                                    sleep(5);
                                    $write_blocked = Kiwi_Retention_Archive_Write_Block::persist(
                                        $real_path . '.lock'
                                    );
                                } while (!$write_blocked);

                                $result = [
                                    'result' => 'corruption_detected',
                                    'reason_code' => 'sqlite_check_reported_corruption',
                                    'check_completed' => true,
                                    'write_blocked' => true,
                                ];
                            }
                        } else {
                            $result = [
                                'result' => 'corruption_detected',
                                'reason_code' => 'sqlite_check_reported_corruption',
                                'check_completed' => true,
                                'write_blocked' => $write_blocked,
                            ];
                        }
                    }
                }
            }
        } catch (Throwable $error) {
            $reason_code = in_array($error->getMessage(), [
                'sqlite_wal_not_empty',
                'sqlite_wal_state_invalid',
                'sqlite_rollback_journal_not_empty',
                'sqlite_rollback_journal_state_invalid',
                'health_brake_unavailable',
                'health_brake_configuration_invalid',
                'health_brake_probe_failed',
                'health_brake_target_unverified',
            ], true)
                ? $error->getMessage()
                : 'sqlite_readonly_check_failed';
            $result = [
                'result' => 'error',
                'reason_code' => $reason_code,
                'check_completed' => false,
            ];
        } finally {
            if (is_resource($lock_resource)) {
                @flock($lock_resource, LOCK_UN);
                @fclose($lock_resource);
            }
        }
    }

    if (is_array($io_record)) {
        try {
            kiwi_retention_archive_health_write_io_record($io_record);
        } catch (Throwable $error) {
        }
    }

    $json = json_encode($result, JSON_UNESCAPED_SLASHES);
    echo is_string($json)
        ? $json
        : '{"result":"error","reason_code":"health_child_json_failed","check_completed":false}';
    $child_result = (string) ($result['result'] ?? 'error');
    exit(in_array($child_result, ['ok', 'corruption_detected'], true)
        ? 0
        : ($child_result === 'deferred' ? 1 : 2));
}

function kiwi_retention_archive_health_cli_has_required_api(string $class_name): bool
{
    if (!class_exists($class_name)) {
        return false;
    }

    foreach (['add_command', 'add_wp_hook', 'get_runner', 'halt', 'line'] as $method) {
        if (!method_exists($class_name, $method)) {
            return false;
        }
    }

    return true;
}

function kiwi_retention_archive_health_bootstrap_failure(
    string $command,
    string $reason_code,
    ?array $durable_result = null
): void {
    $result = is_array($durable_result)
        ? $durable_result
        : (new Kiwi_Retention_Archive_Health_Bootstrap_Recorder())->record($reason_code, $command);
    $exit_code = (int) ($result['_exit_code'] ?? 2);
    unset($result['_exit_code']);
    $json = json_encode($result, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || strpos($json, "\n") !== false) {
        $fallback = (new Kiwi_Retention_Archive_Health_Bootstrap_Recorder())
            ->record('json_encode_failed', $command);
        unset($fallback['_exit_code']);
        $json = json_encode($fallback, JSON_UNESCAPED_SLASHES);
    }
    $line = is_string($json)
        ? $json
        : '{"schema_version":1,"command":"check","result":"error","reason_code":"json_encode_failed","archive":null,"check":null,"started_at":"","finished_at":"","duration_seconds":0}';

    if (class_exists('WP_CLI') && method_exists('WP_CLI', 'line') && method_exists('WP_CLI', 'halt')) {
        WP_CLI::line($line);
        WP_CLI::halt(min(2, max(1, $exit_code)));
    }

    echo $line;
    exit(min(2, max(1, $exit_code)));
}

if (!defined('WP_CLI')
    || !WP_CLI
    || !kiwi_retention_archive_health_cli_has_required_api('WP_CLI')
) {
    kiwi_retention_archive_health_bootstrap_failure('check', 'wp_cli_api_unavailable');
}

if (!class_exists('Kiwi_WP_CLI_Command_Namespace')) {
    final class Kiwi_WP_CLI_Command_Namespace
    {
    }
}

final class Kiwi_Retention_Archive_Health_Command
{
    private $required_classes;
    private $service_factory;
    private $json_encoder;
    private $bootstrap_failure_recorder;

    public function __construct(
        ?array $required_classes = null,
        ?callable $service_factory = null,
        ?callable $json_encoder = null,
        ?callable $bootstrap_failure_recorder = null
    ) {
        $this->required_classes = is_array($required_classes)
            ? $required_classes
            : [
                'Kiwi_Config',
                'Kiwi_Retention_Archive_Name',
                'Kiwi_Retention_Archive_Write_Block',
                'Kiwi_Retention_Archive_Lock',
                'Kiwi_Retention_Sqlite_Archive_Service',
                'Kiwi_Retention_Archive_Check_Supervisor',
                'Kiwi_Retention_Corruption_Safety_Gate_Coordinator',
                'Kiwi_Retention_Archive_Health_Controller',
                'Kiwi_Retention_Archive_Health_Service',
                'Kiwi_Operational_Event_Service',
                'Kiwi_Retention_Cleanup_Run_Repository',
            ];
        $this->service_factory = $service_factory ?? static function () {
            return new Kiwi_Retention_Archive_Health_Service();
        };
        $this->json_encoder = $json_encoder ?? static function (array $result) {
            return function_exists('wp_json_encode')
                ? wp_json_encode($result, JSON_UNESCAPED_SLASHES)
                : json_encode($result, JSON_UNESCAPED_SLASHES);
        };
        $this->bootstrap_failure_recorder = $bootstrap_failure_recorder ?? static function (
            string $reason_code,
            string $command
        ): ?array {
            if ($command === 'check'
                && class_exists('Kiwi_Retention_Archive_Health_Service')
                && method_exists(
                    'Kiwi_Retention_Archive_Health_Service',
                    'record_scheduled_bootstrap_failure'
                )
            ) {
                try {
                    return (new Kiwi_Retention_Archive_Health_Service())
                        ->record_scheduled_bootstrap_failure($reason_code);
                } catch (Throwable $error) {
                }
            }

            try {
                return (new Kiwi_Retention_Archive_Health_Bootstrap_Recorder())
                    ->record($reason_code, $command);
            } catch (Throwable $error) {
                return null;
            }
        };
    }

    public function check(array $args, array $assoc_args): void
    {
        $this->run('check', $assoc_args);
    }

    public function diagnose(array $args, array $assoc_args): void
    {
        $this->run('diagnose', $assoc_args);
    }

    public function unblock(array $args, array $assoc_args): void
    {
        $this->run('unblock', $assoc_args);
    }

    private function run(string $mode, array $assoc_args): void
    {
        $runner = WP_CLI::get_runner();
        if (!is_object($runner) || !method_exists($runner, 'load_wordpress')) {
            $this->fail_before_service($mode, 'wp_cli_loader_unavailable');
        }

        $executed = false;
        $hook_added = WP_CLI::add_wp_hook(
            'plugins_loaded',
            function () use ($mode, $assoc_args, &$executed): void {
                $executed = true;
                $this->execute($mode, $assoc_args);
            }
        );
        if (!$hook_added) {
            $this->fail_before_service($mode, 'plugins_loaded_hook_failed');
        }

        try {
            $runner->load_wordpress();
        } catch (Throwable $error) {
            $this->fail_before_service($mode, 'wordpress_load_failed');
        }
        if (!$executed) {
            $this->fail_before_service($mode, 'plugins_loaded_not_reached');
        }

        $this->fail_before_service($mode, 'runner_returned_before_halt');
    }

    private function execute(string $mode, array $assoc_args): void
    {
        if (!function_exists('did_action')
            || did_action('plugins_loaded') < 1
            || did_action('init') > 0
        ) {
            $this->fail_before_service($mode, 'wordpress_lifecycle_invalid');
        }
        foreach ($this->required_classes as $required_class) {
            if (!is_string($required_class) || !class_exists($required_class)) {
                $this->fail_before_service($mode, 'required_class_missing');
            }
        }

        try {
            $service = call_user_func($this->service_factory);
        } catch (Throwable $error) {
            $this->fail_before_service($mode, 'health_service_exception');
        }
        if (!$service instanceof Kiwi_Retention_Archive_Health_Service) {
            $this->fail_before_service($mode, 'health_service_unavailable');
        }

        try {
            if ($mode === 'check') {
                $result = $service->check((string) ($assoc_args['check'] ?? ''));
            } elseif ($mode === 'diagnose') {
                $result = $service->diagnose(
                    (string) ($assoc_args['archive'] ?? ''),
                    (string) ($assoc_args['check'] ?? '')
                );
            } elseif ($mode === 'unblock') {
                $confirm = $assoc_args['confirm'] ?? null;
                $confirmed = $confirm === true
                    || in_array(strtolower(trim((string) $confirm)), ['1', 'true', 'yes'], true);
                $result = $service->unblock(
                    (string) ($assoc_args['archive'] ?? ''),
                    (string) ($assoc_args['replacement'] ?? ''),
                    $confirmed
                );
            } else {
                $this->fail_before_service($mode, 'command_mode_invalid');
            }
        } catch (Throwable $error) {
            $this->fail_before_service($mode, 'health_service_exception');
        }
        if (!is_array($result)) {
            $this->fail_before_service($mode, 'health_result_invalid');
        }

        $exit_code = min(2, max(0, (int) ($result['_exit_code'] ?? 2)));
        unset($result['_exit_code']);
        $json = call_user_func($this->json_encoder, $result);
        if (!is_string($json) || strpos($json, "\n") !== false) {
            $this->fail_before_service($mode, 'json_encode_failed');
        }

        WP_CLI::line($json);
        WP_CLI::halt($exit_code);
    }

    private function fail_before_service(string $mode, string $reason_code): void
    {
        $durable_result = null;
        try {
            $candidate = call_user_func(
                $this->bootstrap_failure_recorder,
                $reason_code,
                $mode
            );
            $durable_result = is_array($candidate) ? $candidate : null;
        } catch (Throwable $error) {
            $durable_result = null;
        }

        kiwi_retention_archive_health_bootstrap_failure($mode, $reason_code, $durable_result);
    }
}

WP_CLI::add_command('kiwi', new Kiwi_WP_CLI_Command_Namespace());
WP_CLI::add_command('kiwi retention', new Kiwi_WP_CLI_Command_Namespace());
$registered = WP_CLI::add_command(
    'kiwi retention archive-health',
    new Kiwi_Retention_Archive_Health_Command(),
    [
        'when' => 'before_wp_load',
        'shortdesc' => 'Run, diagnose, or manually unblock retention archive health.',
    ]
);
if (!$registered) {
    kiwi_retention_archive_health_bootstrap_failure('check', 'command_registration_failed');
}
