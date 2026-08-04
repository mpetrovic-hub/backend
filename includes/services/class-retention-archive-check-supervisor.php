<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Kiwi_Retention_Archive_Check_Supervisor
{
    private const READINESS_LOCKED = 'locked';
    private const READINESS_CORRUPTION_GATE_REQUIRED = 'corruption_gate_required';
    private const READINESS_CORRUPTION_GATE_PERSISTED = 'corruption_gate_persisted';
    private const READINESS_CORRUPTION_GATE_FAILED = 'corruption_gate_failed';

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
        bool $persist_write_block_on_corruption = false,
        ?callable $corruption_gate_fallback = null,
        bool $allow_blocked_recovery_verification = false
    ): array
    {
        $check = strtolower(trim($check));
        if (!in_array($check, ['quick', 'integrity'], true)) {
            return $this->failure('health_check_invalid');
        }

        if (is_callable($this->runner)) {
            try {
                $outcome = $this->normalize_outcome(
                    (array) call_user_func(
                        $this->runner,
                        $archive_path,
                        $check,
                        $persist_write_block_on_corruption,
                        $allow_blocked_recovery_verification
                    )
                );

                return $this->complete_callable_corruption_handoff(
                    $outcome,
                    $archive_path,
                    $check,
                    $persist_write_block_on_corruption,
                    $corruption_gate_fallback
                );
            } catch (Throwable $error) {
                return $this->failure('health_child_exception');
            }
        }

        return $this->run_process(
            $archive_path,
            $check,
            $persist_write_block_on_corruption,
            $corruption_gate_fallback,
            $allow_blocked_recovery_verification
        );
    }

    private function run_process(
        string $archive_path,
        string $check,
        bool $persist_write_block_on_corruption,
        ?callable $corruption_gate_fallback,
        bool $allow_blocked_recovery_verification
    ): array
    {
        $started = microtime(true);
        if (!function_exists('proc_open')
            || !function_exists('proc_get_status')
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
            'allow_blocked_recovery_verification' => $allow_blocked_recovery_verification,
            'corruption_handoff_timeout_seconds' => $this->config->get_retention_archive_health_timeout_seconds(),
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
        $gate_handoff_attempted = false;
        $gate_handoff_failed = false;
        $gate_handoff = [];
        $timeout_seconds = $this->config->get_retention_archive_health_timeout_seconds();

        while (true) {
            $readiness_state = (string) @file_get_contents($readiness_path);
            $lock_acquired = $lock_acquired || in_array($readiness_state, [
                self::READINESS_LOCKED,
                self::READINESS_CORRUPTION_GATE_REQUIRED,
                self::READINESS_CORRUPTION_GATE_PERSISTED,
                self::READINESS_CORRUPTION_GATE_FAILED,
            ], true);
            if ($readiness_state === self::READINESS_CORRUPTION_GATE_REQUIRED
                && !$gate_handoff_attempted
            ) {
                $gate_handoff_attempted = true;
                $gate_handoff = $this->invoke_corruption_gate_fallback(
                    $corruption_gate_fallback,
                    $archive_path,
                    $check,
                    'sqlite_check_reported_corruption'
                );
                $acknowledgement = !empty($gate_handoff['incident_open'])
                    ? self::READINESS_CORRUPTION_GATE_PERSISTED
                    : self::READINESS_CORRUPTION_GATE_FAILED;
                if (!$this->write_readiness_state($readiness_path, $acknowledgement)) {
                    $gate_handoff = [
                        'incident_open' => !empty($gate_handoff['incident_open']),
                        'incident_action' => (string) ($gate_handoff['incident_action'] ?? 'none'),
                        'handoff_acknowledged' => false,
                    ];
                } else {
                    $gate_handoff['handoff_acknowledged'] = true;
                    $gate_handoff_failed = empty($gate_handoff['incident_open']);
                }
            }
            $last_status = proc_get_status($process);
            if (empty($last_status['running'])) {
                break;
            }
            if ($gate_handoff_failed) {
                break;
            }
            if ((microtime(true) - $started) >= $timeout_seconds) {
                $timed_out = true;
                break;
            }
            usleep(20000);
        }

        $status_after = proc_get_status($process);
        $status_after = is_array($status_after) ? $status_after : ['running' => true];
        $child_running = !empty($status_after['running']);
        $readiness_state = (string) @file_get_contents($readiness_path);
        $lock_acquired = $lock_acquired || in_array($readiness_state, [
            self::READINESS_LOCKED,
            self::READINESS_CORRUPTION_GATE_REQUIRED,
            self::READINESS_CORRUPTION_GATE_PERSISTED,
            self::READINESS_CORRUPTION_GATE_FAILED,
        ], true);
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

        if ($gate_handoff_failed) {
            return $this->corruption_gate_failure([
                'duration_seconds' => $duration,
                'child_running' => $child_running,
                'lock_acquired' => $lock_acquired,
            ]);
        }

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

        $outcome = $this->normalize_outcome(array_merge($decoded, $gate_handoff, [
            'duration_seconds' => $duration,
            'child_running' => $child_running,
        ]));
        if ($persist_write_block_on_corruption
            && $outcome['result'] === 'corruption_detected'
            && empty($outcome['write_blocked'])
            && empty($outcome['incident_open'])
        ) {
            return $this->corruption_gate_failure($outcome);
        }

        return $outcome;
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
            'incident_open' => !empty($outcome['incident_open']),
            'incident_action' => in_array(
                (string) ($outcome['incident_action'] ?? ''),
                ['raised', 'repeated', 'resolved'],
                true
            ) ? (string) $outcome['incident_action'] : 'none',
        ];
    }

    private function complete_callable_corruption_handoff(
        array $outcome,
        string $archive_path,
        string $check,
        bool $persist_write_block_on_corruption,
        ?callable $corruption_gate_fallback
    ): array {
        if (!$persist_write_block_on_corruption
            || $outcome['result'] !== 'corruption_detected'
            || !empty($outcome['write_blocked'])
            || !empty($outcome['incident_open'])
        ) {
            return $outcome;
        }

        $gate = $this->invoke_corruption_gate_fallback(
            $corruption_gate_fallback,
            $archive_path,
            $check,
            (string) ($outcome['reason_code'] ?? 'sqlite_check_reported_corruption')
        );
        $outcome = $this->normalize_outcome(array_merge($outcome, $gate));

        return !empty($outcome['incident_open'])
            ? $outcome
            : $this->corruption_gate_failure($outcome);
    }

    private function invoke_corruption_gate_fallback(
        ?callable $fallback,
        string $archive_path,
        string $check,
        string $reason_code
    ): array {
        if (!is_callable($fallback)) {
            return ['incident_open' => false, 'incident_action' => 'none'];
        }

        try {
            $gate = (array) call_user_func($fallback, $archive_path, $check, $reason_code);
        } catch (Throwable $error) {
            return ['incident_open' => false, 'incident_action' => 'none'];
        }

        return [
            'incident_open' => !empty($gate['incident_open']),
            'incident_action' => in_array(
                (string) ($gate['incident_action'] ?? ''),
                ['raised', 'repeated'],
                true
            ) ? (string) $gate['incident_action'] : 'none',
        ];
    }

    private function write_readiness_state(string $path, string $state): bool
    {
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
    }

    private function corruption_gate_failure(array $outcome): array
    {
        $outcome['result'] = 'error';
        $outcome['reason_code'] = 'corruption_gate_persist_failed';
        $outcome['check_completed'] = true;
        $outcome['write_blocked'] = false;
        $outcome['incident_open'] = false;
        $outcome['incident_action'] = 'none';

        return $this->normalize_outcome($outcome);
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
