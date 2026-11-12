<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Retention_Archive_Health_Service
{
    private $controller;
    private $health_store;
    private $operational_event_service;
    private $archive_lock;
    private $safety_gate;
    private $clock;

    public function __construct(
        ?Kiwi_Config $config = null,
        ?Kiwi_Retention_Sqlite_Archive_Service $archive_service = null,
        ?Kiwi_Retention_Archive_Lock $lock_service = null,
        ?Kiwi_Operational_Event_Service $operational_event_service = null,
        ?callable $clock = null,
        ?callable $check_runner = null,
        string $child_script_path = '',
        ?Kiwi_Retention_Cleanup_Run_Repository $run_repository = null,
        ?Kiwi_Retention_Archive_Health_Controller $controller = null,
        ?Kiwi_Retention_Archive_Health_Store $health_store = null
    ) {
        $this->health_store = $health_store ?? new Kiwi_Retention_Archive_Health_Store();
        $this->operational_event_service = $operational_event_service ?? new Kiwi_Operational_Event_Service();
        $this->archive_lock = $lock_service ?? new Kiwi_Retention_Archive_Lock();
        $this->clock = $clock ?? static function (): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('UTC')); };
        $this->safety_gate = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator(
            $this->archive_lock, $this->operational_event_service, $run_repository, $this->health_store
        );
        if ($controller instanceof Kiwi_Retention_Archive_Health_Controller) {
            $this->controller = $controller;

            return;
        }

        $config = $config instanceof Kiwi_Config ? $config : new Kiwi_Config();
        $archive_service = $archive_service instanceof Kiwi_Retention_Sqlite_Archive_Service
            ? $archive_service
            : new Kiwi_Retention_Sqlite_Archive_Service($config);
        $lock_service = $lock_service instanceof Kiwi_Retention_Archive_Lock
            ? $lock_service
            : new Kiwi_Retention_Archive_Lock();
        $operational_event_service = $operational_event_service instanceof Kiwi_Operational_Event_Service
            ? $operational_event_service
            : new Kiwi_Operational_Event_Service();
        $run_repository = $run_repository instanceof Kiwi_Retention_Cleanup_Run_Repository
            ? $run_repository
            : new Kiwi_Retention_Cleanup_Run_Repository();
        $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
            $config,
            $check_runner,
            $child_script_path
        );
        $safety_gate = $this->safety_gate;
        $this->controller = new Kiwi_Retention_Archive_Health_Controller(
            $archive_service,
            $supervisor,
            $safety_gate,
            $operational_event_service,
            $run_repository,
            $clock,
            $this->archive_lock,
            $this->health_store
        );
    }

    public function check(string $check): array
    {
        return $this->controller->check($check);
    }

    public function status(?string $attempt_id = null): array
    {
        try {
            $view = $this->health_store->read($attempt_id);
            if ($attempt_id !== null && $view['attempt'] === null) { throw new RuntimeException('health_attempt_unknown'); }
            $incidents = $this->operational_event_service->get_open_incidents(['area' => 'retention'], 100);
            if ($incidents === null) { throw new RuntimeException('health_incident_lookup_failed'); }
            $target = $this->controller->active_target();
            $target_status = ['available' => false];
            if (!empty($target['success'])) {
                clearstatcache(true, $target['path']);
                $target_status = ['available' => true, 'generation' => $target['name'],
                    'bytes' => is_file($target['path']) && !is_link($target['path']) ? filesize($target['path']) : null,
                    'generation_locked' => $this->read_generation_lock($target['path']),
                    'pending' => $this->health_store->pending_gate($target['name']),
                    'protection' => $this->safety_gate->inspect($target['path'], false)];
            }
            $incidents = array_map(static function (array $event): array {
                $context = $event['context_json'] ?? [];
                if (is_string($context)) { $context = json_decode($context, true); }
                return array_merge(array_intersect_key($event, array_flip(['id', 'event_type', 'severity', 'lifecycle_action',
                    'correlation_key', 'reference_type', 'reference_id', 'occurred_at'])),
                    ['reason_code' => is_array($context) ? ($context['reason_code'] ?? null) : null]);
            }, $incidents);
            return array_merge(['schema_version' => 1, 'command' => 'status', 'result' => 'ok',
                '_exit_code' => 0, 'target' => $target_status, 'open_incidents' => $incidents], $view);
        } catch (Throwable $error) {
            return ['schema_version' => 1, 'command' => 'status', 'result' => 'error',
                'reason_code' => 'health_run_failed', 'message' => 'Archive health state could not be read safely.', '_exit_code' => 2];
        }
    }

    /** Internal export boundary; no public start command. The callback keeps the real generation lock. */
    public function with_new_attempt(string $attempt_id, string $start_kind, string $generation, int $expected_bytes, ?callable $operation = null): array
    {
        $handle = null;
        try {
            if (!Kiwi_Retention_Archive_Health_Store::valid_attempt_id($attempt_id)
                || !in_array($start_kind, ['manual', 'scheduled'], true) || $expected_bytes < 1) {
                throw new RuntimeException('health_start_invalid');
            }
            $target = $this->controller->active_target();
            if (empty($target['success']) || $target['name'] !== $generation) { throw new RuntimeException('health_target_changed'); }
            $lock = $this->archive_lock->acquire_for_archive($target['path']);
            $handle = $lock['handle'] ?? null;
            if (empty($lock['acquired']) || !$handle instanceof Kiwi_Retention_Archive_Lock_Handle) { throw new RuntimeException('health_generation_lock_unavailable'); }
            $locked_target = $this->controller->active_target();
            clearstatcache(true, $target['path']);
            if ($locked_target !== $target || !is_file($target['path']) || is_link($target['path'])
                || filesize($target['path']) !== $expected_bytes) { throw new RuntimeException('health_target_changed'); }
            if (empty($this->safety_gate->inspect($target['path'], false)['allowed'])) { throw new RuntimeException('health_archive_protected'); }
            $now = $this->now_utc();
            $view = $this->health_store->update($attempt_id, function (array $view) use ($attempt_id, $start_kind, $generation, $expected_bytes, $now): array {
                if ($view['active_attempt'] !== null || $view['attempt'] !== null) { throw new RuntimeException('health_attempt_exists'); }
                $state = $view['state'];
                $state['active_attempt_id'] = $attempt_id;
                $state['last_attempt_sequence']++;
                $attempt = ['format_version' => 1, 'attempt_id' => $attempt_id,
                    'attempt_sequence' => $state['last_attempt_sequence'], 'start_kind' => $start_kind,
                    'started_at' => $now, 'generation' => $generation, 'expected_bytes' => $expected_bytes,
                    'received_bytes' => null, 'sha256' => null, 'transfer_complete' => false, 'transfer_completed_at' => null,
                    'status' => 'open', 'outcome' => null, 'result_fingerprint' => null,
                    'received_at' => null, 'accepted_at' => null, 'invalidated_at' => null, 'closed_at' => null,
                    'reason_code' => null, 'phase' => null, 'message' => null,
                    'operation_order' => sprintf('%.0F', microtime(true) * 1000000)];
                return ['state' => $state, 'attempt' => $attempt];
            });
            return $operation !== null ? $operation($view['attempt'], $target['path'], $handle)
                : ['schema_version' => 1, 'result' => 'started', 'attempt' => $view['attempt'], '_exit_code' => 0];
        } catch (Throwable $error) {
            return $this->attempt_error('start', $error->getMessage());
        } finally {
            $this->archive_lock->release($handle);
        }
    }

    private function now_utc(): string
    {
        $now = call_user_func($this->clock);
        if (!$now instanceof DateTimeInterface) { throw new RuntimeException('health_clock_invalid'); }
        return DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function read_generation_lock(string $path): ?bool
    {
        $lock_path = $path . '.lock';
        clearstatcache(true, $lock_path);
        if (!file_exists($lock_path)) { return false; }
        if (!is_file($lock_path) || is_link($lock_path)) { return null; }
        $stream = @fopen($lock_path, 'rb');
        if (!is_resource($stream)) { return null; }
        try {
            $blocked = 0;
            if (@flock($stream, LOCK_EX | LOCK_NB, $blocked)) { @flock($stream, LOCK_UN); return false; }
            return $blocked ? true : null;
        } finally { fclose($stream); }
    }

    public function abort(string $attempt_id, bool $confirmed = false): array
    {
        if (!$confirmed) { return $this->attempt_error('abort', 'health_abort_confirmation_required'); }
        return $this->fail_attempt($attempt_id, 'abort', 'Manual abort confirmed.', 'health_run_failed', true);
    }

    public function fail_attempt(?string $attempt_id, string $phase, string $message,
        string $reason_code = 'health_run_failed', bool $manual = false): array
    {
        try {
            if (!in_array($phase, ['start', 'copy', 'verification', 'check', 'result', 'abort', 'activation'], true)
                || !in_array($reason_code, ['health_run_failed', 'health_child_timeout'], true)) {
                throw new RuntimeException('health_failure_input_invalid');
            }
            $message = preg_replace('~(?:[A-Za-z]:)?[/\\\\][^\s]+|(?:https?://)\S+~', '[redacted]', $message);
            $message = preg_replace('/(?:password|secret|token|authorization|key)\s*[:=]\s*\S+/i', '[redacted]', $message);
            $message = substr(preg_replace('/[\x00-\x1f\x7f]/', ' ', $message), 0, 300);
            if ($attempt_id === null) {
                $this->health_store->read();
                $action = $this->controller->record_external_failure(null, $phase, $message, $reason_code);
                if ($action === '') { throw new RuntimeException('health_availability_unconfirmed'); }
                return ['schema_version' => 1, 'result' => 'failed', 'incident_action' => $action, '_exit_code' => 0];
            }
            $view = $this->health_store->read($attempt_id);
            $attempt = $view['attempt'];
            $outcome = $manual ? 'manual_abort' : 'technical_failure';
            if (!is_array($attempt) || $attempt['outcome'] === 'corruption') { throw new RuntimeException('health_attempt_not_abortable'); }
            if ($attempt['status'] === 'closed') {
                if ($attempt['outcome'] !== $outcome) { throw new RuntimeException('health_attempt_already_closed'); }
                return ['schema_version' => 1, 'result' => 'closed', 'attempt' => $attempt, '_exit_code' => 0];
            }
            $now = $this->now_utc();
            $invalidated = $this->health_store->update($attempt_id, function (array $current) use ($attempt_id, $outcome, $now, $phase, $message, $reason_code): array {
                $attempt = $current['attempt'];
                if ($current['state']['active_attempt_id'] !== $attempt_id || !is_array($attempt)
                    || $attempt['status'] !== 'open' || $attempt['outcome'] === 'corruption'
                    || ($attempt['invalidated_at'] !== null && $attempt['outcome'] !== $outcome)) {
                    throw new RuntimeException('health_attempt_not_abortable');
                }
                if ($attempt['invalidated_at'] === null) {
                    $attempt['outcome'] = $outcome;
                    $attempt['invalidated_at'] = $now;
                    $attempt['reason_code'] = $reason_code;
                    $attempt['phase'] = $phase;
                    $attempt['message'] = $message;
                }
                return ['state' => $current['state'], 'attempt' => $attempt];
            });
            $attempt = $invalidated['attempt'];
            $action = $this->controller->record_external_failure($attempt, $attempt['phase'], $attempt['message'], $attempt['reason_code']);
            if ($action === '') { throw new RuntimeException('health_availability_unconfirmed'); }
            $closed = $this->health_store->update($attempt_id, function (array $current) use ($attempt_id, $attempt): array {
                if ($current['state']['active_attempt_id'] !== $attempt_id || $current['attempt'] !== $attempt) {
                    throw new RuntimeException('health_attempt_changed');
                }
                $state = $current['state'];
                $state['active_attempt_id'] = null;
                $attempt['status'] = 'closed';
                $attempt['closed_at'] = $this->now_utc();
                return ['state' => $state, 'attempt' => $attempt];
            });
            return ['schema_version' => 1, 'result' => 'closed', 'attempt' => $closed['attempt'], 'incident_action' => $action, '_exit_code' => 0];
        } catch (Throwable $error) {
            return $this->attempt_error($manual ? 'abort' : 'fail', $error->getMessage());
        }
    }

    private function attempt_error(string $command, string $detail): array
    {
        return ['schema_version' => 1, 'command' => $command, 'result' => 'error', 'reason_code' => 'health_run_failed',
            'message' => preg_match('/^health_[a-z_]+$/D', $detail) === 1 ? $detail : 'health_operation_failed', '_exit_code' => 2];
    }

    public function diagnose(string $archive_name, string $check): array
    {
        return $this->controller->diagnose($archive_name, $check);
    }

    public function unblock(
        string $archive_name,
        string $replacement_archive_name = '',
        bool $confirmed = false
    ): array {
        return $this->controller->unblock(
            $archive_name,
            $replacement_archive_name,
            $confirmed
        );
    }

    public function record_scheduled_bootstrap_failure(string $reason_code): array
    {
        return $this->controller->bootstrap_failure($reason_code);
    }
}
