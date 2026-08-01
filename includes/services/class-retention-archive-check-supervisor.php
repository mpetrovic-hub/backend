<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Kiwi_Retention_Archive_Check_Supervisor
{
    private $config;
    private $runner;
    private $child_script_path;

    public function __construct(
        ?Kiwi_Config $config = null,
        ?callable $runner = null,
        string $child_script_path = ''
    ) {
        $this->config = $config instanceof Kiwi_Config ? $config : new Kiwi_Config();
        $this->runner = $runner;
        $this->child_script_path = $child_script_path !== ''
            ? $child_script_path
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'database'
                . DIRECTORY_SEPARATOR . 'kiwi-retention-archive-health.php';
    }

    public function run(
        string $archive_path,
        string $check,
        bool $persist_write_block_on_corruption = false
    ): array
    {
        $check = strtolower(trim($check));
        if (!in_array($check, ['quick', 'integrity'], true)) {
            return $this->failure('health_check_invalid');
        }

        if (is_callable($this->runner)) {
            try {
                return $this->normalize_outcome(
                    (array) call_user_func(
                        $this->runner,
                        $archive_path,
                        $check,
                        $persist_write_block_on_corruption
                    )
                );
            } catch (Throwable $error) {
                return $this->failure('health_child_exception');
            }
        }

        return $this->run_process(
            $archive_path,
            $check,
            $persist_write_block_on_corruption
        );
    }

    private function run_process(
        string $archive_path,
        string $check,
        bool $persist_write_block_on_corruption
    ): array
    {
        $started = microtime(true);
        if (!function_exists('proc_open')
            || !function_exists('proc_get_status')
            || !function_exists('proc_terminate')
            || !is_file($this->child_script_path)
        ) {
            return $this->failure('health_child_api_unavailable');
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
            'persist_write_block_on_corruption' => $persist_write_block_on_corruption,
        ]);
        if (!is_string($payload)) {
            return $this->failure('health_child_payload_invalid');
        }

        $pipes = [];
        $process = @proc_open(
            [PHP_BINARY, $this->child_script_path, '--kiwi-retention-health-child', base64_encode($payload)],
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

            return $this->failure('health_child_start_failed', microtime(true) - $started);
        }

        @fclose($pipes[0]);
        @stream_set_blocking($pipes[1], false);
        @stream_set_blocking($pipes[2], false);
        $timed_out = false;
        $last_status = ['running' => true, 'exitcode' => -1];
        $lock_acquired = false;
        $timeout_seconds = $this->config->get_retention_archive_health_timeout_seconds();

        while (true) {
            $lock_acquired = $lock_acquired || @file_get_contents($readiness_path) === 'locked';
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
        $lock_acquired = $lock_acquired || @file_get_contents($readiness_path) === 'locked';
        $stdout = '';
        $stderr = '';
        if (!$child_running) {
            $stdout = (string) @stream_get_contents($pipes[1]);
            $stderr = (string) @stream_get_contents($pipes[2]);
            @unlink($readiness_path);
        }
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $close_exit_code = $this->close_process_if_stopped($process, $status_after);
        $child_exit_code = isset($last_status['exitcode']) ? (int) $last_status['exitcode'] : -1;
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
                'check_completed' => false,
                'lock_acquired' => $lock_acquired,
            ];
        }

        $stdout = trim($stdout);
        if ($stdout === '' || strpos($stdout, "\n") !== false || trim($stderr) !== '') {
            return $this->failure('health_child_output_invalid', $duration, $child_running);
        }
        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)
            || !in_array((string) ($decoded['result'] ?? ''), [
                'ok',
                'corruption_detected',
                'deferred',
                'error',
            ], true)
            || !array_key_exists('check_completed', $decoded)
            || !is_bool($decoded['check_completed'])
        ) {
            return $this->failure('health_child_result_invalid', $duration, $child_running);
        }

        $result = (string) $decoded['result'];
        $expected_exit_code = in_array($result, ['ok', 'corruption_detected'], true)
            ? 0
            : ($result === 'deferred' ? 1 : 2);
        if ($child_exit_code !== $expected_exit_code
            || ($result === 'corruption_detected' && empty($decoded['check_completed']))
        ) {
            return $this->failure('health_child_exit_invalid', $duration, $child_running);
        }

        return $this->normalize_outcome(array_merge($decoded, [
            'duration_seconds' => $duration,
            'child_running' => $child_running,
        ]));
    }

    private function normalize_outcome(array $outcome): array
    {
        $result = strtolower(trim((string) ($outcome['result'] ?? 'error')));
        if (!in_array($result, ['ok', 'corruption_detected', 'deferred', 'inconclusive', 'error'], true)) {
            $result = 'error';
        }
        $check_completed = !empty($outcome['check_completed']);
        if ($result === 'corruption_detected' && !$check_completed) {
            return $this->failure('health_child_result_invalid');
        }

        $reason_code = strtolower(trim((string) ($outcome['reason_code'] ?? '')));
        if (preg_match('/^[a-z0-9_]{1,100}$/', $reason_code) !== 1) {
            $reason_code = $result === 'ok' ? 'sqlite_check_ok' : 'sqlite_check_failed';
        }

        return [
            'result' => $result,
            'reason_code' => $reason_code,
            'duration_seconds' => max(0.0, (float) ($outcome['duration_seconds'] ?? 0.0)),
            'child_running' => !empty($outcome['child_running']),
            'check_completed' => $check_completed,
            'lock_acquired' => !empty($outcome['lock_acquired']),
            'write_blocked' => !empty($outcome['write_blocked']),
        ];
    }

    private function close_process_if_stopped($process, array $status): ?int
    {
        if (!is_resource($process) || !empty($status['running'])) {
            return null;
        }

        return @proc_close($process);
    }

    private function failure(
        string $reason_code,
        float $duration_seconds = 0.0,
        bool $child_running = false
    ): array {
        return [
            'result' => 'error',
            'reason_code' => $reason_code,
            'duration_seconds' => max(0.0, $duration_seconds),
            'child_running' => $child_running,
            'check_completed' => false,
            'lock_acquired' => false,
        ];
    }
}
