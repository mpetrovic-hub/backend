<?php

if (PHP_SAPI === 'cli'
    && isset($argv[1], $argv[2])
    && $argv[1] === '--kiwi-retention-health-child'
) {
    $payload_raw = base64_decode((string) $argv[2], true);
    $payload = is_string($payload_raw) ? json_decode($payload_raw, true) : null;
    $archive_path = is_array($payload) ? (string) ($payload['archive_path'] ?? '') : '';
    $check = is_array($payload) ? strtolower((string) ($payload['check'] ?? '')) : '';
    $result = [
        'result' => 'error',
        'reason_code' => 'health_child_input_invalid',
    ];

    if (in_array($check, ['quick', 'integrity'], true)
        && $archive_path !== ''
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
                ? ['result' => 'ok', 'reason_code' => 'sqlite_check_ok']
                : ['result' => 'corruption_detected', 'reason_code' => 'sqlite_check_reported_corruption'];
        } catch (Throwable $error) {
            $result = [
                'result' => 'error',
                'reason_code' => 'sqlite_readonly_check_failed',
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

function kiwi_retention_archive_health_bootstrap_failure(string $reason_code): void
{
    $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format(DATE_ATOM);
    $result = [
        'schema_version' => 1,
        'status' => 'failed',
        'exit_code' => 2,
        'check' => '',
        'scope' => 'bootstrap',
        'archive' => '',
        'result' => 'error',
        'reason_code' => $reason_code,
        'started_at' => $now,
        'finished_at' => $now,
        'duration_seconds' => 0,
        'incident_action' => 'none',
    ];
    $json = json_encode($result, JSON_UNESCAPED_SLASHES);
    $line = is_string($json)
        ? $json
        : '{"schema_version":1,"status":"failed","exit_code":2,"check":"","scope":"bootstrap","archive":"","result":"error","reason_code":"json_encode_failed","started_at":"","finished_at":"","duration_seconds":0,"incident_action":"none"}';

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

    public function __construct(
        ?array $required_classes = null,
        ?callable $service_factory = null,
        ?callable $json_encoder = null
    ) {
        $this->required_classes = is_array($required_classes)
            ? $required_classes
            : [
                'Kiwi_Config',
                'Kiwi_Retention_Archive_Lock',
                'Kiwi_Retention_Sqlite_Archive_Service',
                'Kiwi_Retention_Archive_Health_Service',
                'Kiwi_Operational_Event_Service',
            ];
        $this->service_factory = $service_factory ?? static function () {
            return new Kiwi_Retention_Archive_Health_Service();
        };
        $this->json_encoder = $json_encoder ?? static function (array $result) {
            return function_exists('wp_json_encode')
                ? wp_json_encode($result, JSON_UNESCAPED_SLASHES)
                : json_encode($result, JSON_UNESCAPED_SLASHES);
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
            kiwi_retention_archive_health_bootstrap_failure('wp_cli_loader_unavailable');
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
            kiwi_retention_archive_health_bootstrap_failure('plugins_loaded_hook_failed');
        }

        $runner->load_wordpress();
        if (!$executed) {
            kiwi_retention_archive_health_bootstrap_failure('plugins_loaded_not_reached');
        }

        kiwi_retention_archive_health_bootstrap_failure('runner_returned_before_halt');
    }

    private function execute(string $mode, array $assoc_args): void
    {
        if (!function_exists('did_action')
            || did_action('plugins_loaded') < 1
            || did_action('init') > 0
        ) {
            kiwi_retention_archive_health_bootstrap_failure('wordpress_lifecycle_invalid');
        }

        foreach ($this->required_classes as $required_class) {
            if (!is_string($required_class) || !class_exists($required_class)) {
                kiwi_retention_archive_health_bootstrap_failure('required_class_missing');
            }
        }

        try {
            $service = call_user_func($this->service_factory);
            if (!$service instanceof Kiwi_Retention_Archive_Health_Service) {
                kiwi_retention_archive_health_bootstrap_failure('health_service_unavailable');
            }

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
