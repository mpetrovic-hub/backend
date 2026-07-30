<?php

require_once __DIR__ . '/class-retention-archive-health-bootstrap-recorder.php';

function kiwi_retention_archive_health_is_definitive_corruption(Throwable $error): bool
{
    $error_info = $error instanceof PDOException && is_array($error->errorInfo ?? null)
        ? $error->errorInfo
        : [];
    $sqlite_code = (int) ($error_info[1] ?? 0);
    $sqlite_primary_code = $sqlite_code > 0 ? ($sqlite_code & 0xff) : 0;
    if (in_array($sqlite_primary_code, [11, 24, 26], true)) {
        return true;
    }

    $message = strtolower($error->getMessage());
    foreach ([
        'database disk image is malformed',
        'file is not a database',
        'database corruption',
        'malformed database schema',
        'unsupported file format',
    ] as $corruption_message) {
        if (strpos($message, $corruption_message) !== false) {
            return true;
        }
    }

    return false;
}

if (PHP_SAPI === 'cli'
    && isset($argv[1], $argv[2])
    && $argv[1] === '--kiwi-retention-health-child'
) {
    $payload_raw = base64_decode((string) $argv[2], true);
    $payload = is_string($payload_raw) ? json_decode($payload_raw, true) : null;
    $archive_path = is_array($payload) ? (string) ($payload['archive_path'] ?? '') : '';
    $readiness_path = is_array($payload) ? (string) ($payload['readiness_path'] ?? '') : '';
    $check = is_array($payload) ? strtolower((string) ($payload['check'] ?? '')) : '';
    $result = [
        'result' => 'error',
        'reason_code' => 'health_child_input_invalid',
        'check_started' => false,
    ];

    if (in_array($check, ['quick', 'integrity'], true)
        && $archive_path !== ''
        && $readiness_path !== ''
        && is_file($archive_path)
        && !is_link($archive_path)
        && class_exists('PDO')
        && in_array('sqlite', PDO::getAvailableDrivers(), true)
    ) {
        try {
            $real_path = realpath($archive_path);
            if (!is_string($real_path) || $real_path === '') {
                throw new RuntimeException('archive_path_unresolvable');
            }

            $archive_directory = realpath(dirname($real_path));
            $readiness_directory = realpath(dirname($readiness_path));
            if (!is_string($archive_directory)
                || !is_string($readiness_directory)
                || $archive_directory !== $readiness_directory
                || preg_match(
                    '/^\.kiwi_retention_health_child_[a-f0-9]{32}\.ready$/',
                    basename($readiness_path)
                ) !== 1
            ) {
                throw new RuntimeException('health_child_readiness_path_invalid');
            }

            $lock_resource = @fopen($real_path . '.lock', 'c+');
            if (!is_resource($lock_resource)
                || !@flock($lock_resource, LOCK_SH | LOCK_NB)
            ) {
                throw new RuntimeException('health_child_lock_handshake_failed');
            }
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
            $result['check_started'] = true;

            $uri_path = implode('/', array_map(
                'rawurlencode',
                explode('/', str_replace('\\', '/', $real_path))
            ));
            $pdo = new PDO('sqlite:file:' . $uri_path . '?mode=ro');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA query_only = ON');
            $rows = $pdo->query('PRAGMA ' . $check . '_check')->fetchAll(PDO::FETCH_COLUMN);
            $rows = is_array($rows) ? array_values(array_map('strval', $rows)) : [];
            $result = count($rows) === 1 && strtolower(trim($rows[0])) === 'ok'
                ? [
                    'result' => 'ok',
                    'reason_code' => 'sqlite_check_ok',
                    'check_started' => true,
                ]
                : [
                    'result' => 'corruption_detected',
                    'reason_code' => 'sqlite_check_reported_corruption',
                    'check_started' => true,
                ];
        } catch (Throwable $error) {
            $result = kiwi_retention_archive_health_is_definitive_corruption($error)
                ? [
                    'result' => 'corruption_detected',
                    'reason_code' => 'sqlite_check_reported_corruption',
                    'check_started' => !empty($result['check_started']),
                ]
                : [
                    'result' => 'error',
                    'reason_code' => 'sqlite_readonly_check_failed',
                    'check_started' => !empty($result['check_started']),
                ];
        }
    }

    $json = json_encode($result, JSON_UNESCAPED_SLASHES);
    echo is_string($json)
        ? $json
        : '{"result":"error","reason_code":"health_child_json_failed"}';
    exit((string) ($result['result'] ?? '') === 'error' ? 2 : 0);
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
    string $reason_code,
    ?array $durable_result = null
): void
{
    $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format(DATE_ATOM);
    $result = is_array($durable_result) ? $durable_result : [
        'schema_version' => 1,
        'status' => 'failed',
        'exit_code' => 2,
        'check' => null,
        'scope' => 'bootstrap',
        'archive' => null,
        'result' => 'error',
        'reason_code' => $reason_code,
        'started_at' => $now,
        'finished_at' => $now,
        'duration_seconds' => 0,
        'incident_action' => null,
    ];
    $json = json_encode($result, JSON_UNESCAPED_SLASHES);
    $line = is_string($json)
        ? $json
        : '{"schema_version":1,"status":"failed","exit_code":2,"check":null,"scope":"bootstrap","archive":null,"result":"error","reason_code":"json_encode_failed","started_at":"","finished_at":"","duration_seconds":0,"incident_action":null}';

    if (class_exists('WP_CLI') && method_exists('WP_CLI', 'line') && method_exists('WP_CLI', 'halt')) {
        WP_CLI::line($line);
        WP_CLI::halt(2);
    }

    echo $line;
    exit(2);
}

if (!defined('WP_CLI')
    || !WP_CLI
    || !kiwi_retention_archive_health_cli_has_required_api('WP_CLI')
) {
    kiwi_retention_archive_health_bootstrap_failure('wp_cli_api_unavailable');
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
                'Kiwi_Retention_Archive_Lock',
                'Kiwi_Retention_Sqlite_Archive_Service',
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
            string $reason_code
        ): ?array {
            if (class_exists('Kiwi_Retention_Archive_Health_Service')
                && method_exists(
                    'Kiwi_Retention_Archive_Health_Service',
                    'record_scheduled_bootstrap_failure'
                )
            ) {
                try {
                    return (new Kiwi_Retention_Archive_Health_Service())
                        ->record_scheduled_bootstrap_failure($reason_code);
                } catch (Throwable $error) {
                    // A missing constructor dependency is handled by the standalone recorder.
                }
            }

            try {
                return (new Kiwi_Retention_Archive_Health_Bootstrap_Recorder())->record(
                    $reason_code
                );
            } catch (Throwable $error) {
                return null;
            }
        };
    }

    public function scheduled(array $args, array $assoc_args): void
    {
        $this->run('scheduled', $assoc_args);
    }

    public function status(array $args, array $assoc_args): void
    {
        $this->run('status', $assoc_args);
    }

    public function diagnose(array $args, array $assoc_args): void
    {
        $this->run('diagnose', $assoc_args);
    }

    public function preflight(array $args, array $assoc_args): void
    {
        $this->run('preflight', $assoc_args);
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

        $runner->load_wordpress();
        if (!$executed) {
            $this->fail_before_service($mode, 'plugins_loaded_not_reached');
        }

        kiwi_retention_archive_health_bootstrap_failure('runner_returned_before_halt');
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
            if ($mode === 'diagnose') {
                $result = $service->diagnose(
                    (string) ($assoc_args['archive'] ?? ''),
                    (string) ($assoc_args['check'] ?? '')
                );
            } elseif (in_array($mode, ['scheduled', 'status', 'preflight'], true)) {
                $result = $service->{$mode}();
            } else {
                kiwi_retention_archive_health_bootstrap_failure('command_mode_invalid');
            }
        } catch (Throwable $error) {
            kiwi_retention_archive_health_bootstrap_failure('health_service_exception');
        }

        if (!is_array($result)) {
            kiwi_retention_archive_health_bootstrap_failure('health_result_invalid');
        }

        $json = call_user_func($this->json_encoder, $result);
        if (!is_string($json) || strpos($json, "\n") !== false) {
            kiwi_retention_archive_health_bootstrap_failure('json_encode_failed');
        }

        WP_CLI::line($json);
        WP_CLI::halt((int) ($result['exit_code'] ?? 2));
    }

    private function fail_before_service(string $mode, string $reason_code): void
    {
        $durable_result = null;
        if ($mode === 'scheduled') {
            try {
                $candidate = call_user_func($this->bootstrap_failure_recorder, $reason_code);
                $durable_result = is_array($candidate) ? $candidate : null;
            } catch (Throwable $error) {
                $durable_result = null;
            }
        }

        kiwi_retention_archive_health_bootstrap_failure($reason_code, $durable_result);
    }
}

WP_CLI::add_command('kiwi', new Kiwi_WP_CLI_Command_Namespace());
$registered = WP_CLI::add_command(
    'kiwi retention archive-health',
    new Kiwi_Retention_Archive_Health_Command(),
    [
        'when' => 'before_wp_load',
        'shortdesc' => 'Inspect and schedule retention archive health checks.',
    ]
);

if (!$registered) {
    kiwi_retention_archive_health_bootstrap_failure('command_registration_failed');
}
