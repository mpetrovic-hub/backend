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

    public function freshness(bool $activate = false, bool $confirmed = false): array
    {
        try {
            if ($activate && !$confirmed) { throw new RuntimeException('health_activation_confirmation_required'); }
            $overdue = false;
            $incident_action = 'none';
            $activated_at = null;
            $view = $this->health_store->update(null, function (array $current) use (&$activated_at): array {
                $state = $current['state'];
                $state['activated_at'] = $activated_at;
                $state['last_freshness_completed_at'] = $this->now_utc();
                return ['state' => $state];
            }, null, function (array $current) use ($activate, &$activated_at, &$overdue, &$incident_action): void {
                $state = $current['state'];
                $now = $this->now_utc();
                $activated_at = $state['activated_at'];
                if ($activated_at === null) {
                    if (!$activate) { throw new RuntimeException('health_freshness_not_activated'); }
                    $activated_at = $now;
                }
                $berlin = new DateTimeZone('Europe/Berlin');
                $anchor = new DateTimeImmutable($current['last_confirmed_attempt']['accepted_at'] ?? $activated_at);
                $due_date = $anchor->setTimezone($berlin)->setTime(0, 0)->modify('+8 days')->format('Y-m-d');
                $overdue = (new DateTimeImmutable($now))->setTimezone($berlin)->format('Y-m-d') >= $due_date;
                if ($overdue) {
                    $incident_action = $this->controller->record_overdue($current['last_confirmed_attempt'], $now,
                        sprintf('%.0F', microtime(true) * 1000000));
                    if ($incident_action === '') { throw new RuntimeException('health_availability_unconfirmed'); }
                }
            });
            return ['schema_version' => 1, 'command' => 'freshness', 'result' => $overdue ? 'overdue' : 'ok',
                'state' => $view['state'], 'incident_action' => $incident_action, '_exit_code' => 0];
        } catch (Throwable $error) {
            $result = $this->attempt_error('freshness', $error->getMessage());
            error_log('Archive health freshness did not complete: ' . $result['message']);
            return $result;
        }
    }

    public function status(?string $attempt_id = null): array
    {
        try {
            $view = $this->health_store->read($attempt_id);
            if ($attempt_id !== null && $view['attempt'] === null) { throw new RuntimeException('health_attempt_unknown'); }
            $incidents = [];
            foreach (['error', 'critical'] as $severity) {
                $open = $this->operational_event_service->get_open_incidents(['area' => 'retention', 'severity' => $severity], 100);
                if ($open === null) { throw new RuntimeException('health_incident_lookup_failed'); }
                $incidents = array_merge($incidents, $open);
            }
            usort($incidents, static function (array $left, array $right): int {
                return strcmp((string) ($right['occurred_at'] ?? ''), (string) ($left['occurred_at'] ?? ''))
                    ?: (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
            });
            $incidents = array_slice($incidents, 0, 100);
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
                    'correlation_key', 'reference_type', 'reference_id', 'occurred_at', 'message'])),
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
        $creation_attempted = false;
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
            // Once storage is invoked, even a lost commit acknowledgement must remain uncertain.
            $creation_attempted = true;
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
            $failure = $this->attempt_error('start', $error->getMessage());
            if (!$creation_attempted) { $failure['attempt_started'] = false; }
            return $failure;
        } finally {
            $this->archive_lock->release($handle);
        }
    }

    /** Source-side proof only; never a health result and never releases Pending. */
    public function record_transfer(string $attempt_id, int $bytes, string $sha256): array
    {
        try {
            if ($bytes < 1 || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
                throw new RuntimeException('health_transfer_invalid');
            }
            $view = $this->health_store->update($attempt_id, function (array $current) use ($attempt_id, $bytes, $sha256): array {
                $attempt = $current['attempt'];
                if ($current['state']['active_attempt_id'] !== $attempt_id || !is_array($attempt)
                    || $attempt['status'] !== 'open' || $attempt['outcome'] !== null
                    || $attempt['transfer_complete'] || $attempt['expected_bytes'] !== $bytes) {
                    throw new RuntimeException('health_transfer_attempt_changed');
                }
                $attempt['received_bytes'] = $bytes;
                $attempt['sha256'] = $sha256;
                $attempt['transfer_complete'] = true;
                $attempt['transfer_completed_at'] = $this->now_utc();
                return ['state' => $current['state'], 'attempt' => $attempt];
            });
            return ['schema_version' => 1, 'result' => 'transferred', 'attempt' => $view['attempt'], '_exit_code' => 0];
        } catch (Throwable $error) {
            return $this->attempt_error('export', $error->getMessage());
        }
    }

    /** Accepts the normalized proof delivered through the authenticated restricted transport. */
    public function accept_result(array $result): array
    {
        $handle = null;
        try {
            $result = $this->normalize_result($result);
            $id = $result['attempt_id'];
            $fingerprint = hash('sha256', json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $view = $this->health_store->read($id);
            $this->assert_result_matches($view, $result, $fingerprint, true);
            $target = $this->controller->generation_target($result['generation']);
            if ($target === null) { throw new RuntimeException('health_result_generation_unavailable'); }
            if ($view['attempt']['status'] === 'closed') {
                return $this->result_acknowledgement($view['attempt'], $target['path']);
            }
            $lock = $this->archive_lock->acquire_for_archive($target['path']);
            $handle = $lock['handle'] ?? null;
            if (empty($lock['acquired']) || !$handle instanceof Kiwi_Retention_Archive_Lock_Handle) {
                throw new RuntimeException('health_generation_lock_unavailable');
            }
            $locked = $this->health_store->read($id);
            $this->assert_result_matches($locked, $result, $fingerprint, true);
            if ($locked['attempt']['status'] === 'closed') {
                return $this->result_acknowledgement($locked['attempt'], $target['path']);
            }
            $this->health_store->update($id, function (array $current) use ($result, $fingerprint): array {
                $this->assert_result_matches($current, $result, $fingerprint);
                $attempt = $current['attempt'];
                if ($attempt['outcome'] === null) {
                    $attempt['outcome'] = $result['outcome'];
                    $attempt['result_fingerprint'] = $fingerprint;
                    $attempt['received_at'] = $this->now_utc();
                }
                return ['state' => $current['state'], 'attempt' => $attempt];
            });
            $confirmed = $this->health_store->update($id,
                function (array $current) use ($result, $fingerprint, $id): array {
                    $this->assert_result_matches($current, $result, $fingerprint);
                    $state = $current['state'];
                    $attempt = $current['attempt'];
                    $attempt['status'] = 'closed';
                    $attempt['accepted_at'] = $this->now_utc();
                    $attempt['closed_at'] = $attempt['accepted_at'];
                    $state['last_confirmed_attempt_id'] = $id;
                    $state['active_attempt_id'] = null;
                    return ['state' => $state, 'attempt' => $attempt];
                }, null, function (array $current) use ($result, $fingerprint, $handle, $target): void {
                    $this->assert_result_matches($current, $result, $fingerprint);
                    if ($result['outcome'] === 'corruption') {
                        $write_blocked = $handle->persist_write_blocked();
                        $incident = $this->safety_gate->record_corruption_incident_while_generation_locked(
                            $target['path'], 'quick', 'sqlite_check_reported_corruption'
                        );
                        if (!$write_blocked && empty($incident['incident_open'])) {
                            throw new RuntimeException('health_corruption_protection_unconfirmed');
                        }
                    }
                    if ($this->controller->record_external_recovery($current['attempt']) === '') {
                        throw new RuntimeException('health_availability_unconfirmed');
                    }
                });
            return $this->result_acknowledgement($confirmed['attempt'], $target['path']);
        } catch (Throwable $error) {
            return $this->attempt_error('result', $error->getMessage());
        } finally {
            $this->archive_lock->release($handle);
        }
    }

    private function normalize_result(array $result): array
    {
        $fields = ['schema_version', 'attempt_id', 'generation', 'received_bytes', 'sha256',
            'transfer_complete', 'check', 'check_completed', 'outcome'];
        if (count($result) !== count($fields) || array_diff($fields, array_keys($result))
            || $result['schema_version'] !== 1 || !Kiwi_Retention_Archive_Health_Store::valid_attempt_id($result['attempt_id'])
            || !is_string($result['generation']) || $result['generation'] === ''
            || Kiwi_Retention_Archive_Name::normalize($result['generation']) !== $result['generation']
            || !is_int($result['received_bytes']) || $result['received_bytes'] < 1
            || !is_string($result['sha256']) || preg_match('/^[a-f0-9]{64}$/D', $result['sha256']) !== 1
            || $result['transfer_complete'] !== true || $result['check'] !== 'quick_check'
            || $result['check_completed'] !== true || !in_array($result['outcome'], ['ok', 'corruption'], true)) {
            throw new RuntimeException('health_result_invalid');
        }
        return array_replace(array_fill_keys($fields, null), $result);
    }

    private function assert_result_matches(array $view, array $result, string $fingerprint, bool $allow_closed = false): void
    {
        $attempt = $view['attempt'];
        if (!is_array($attempt) || (!$allow_closed && $attempt['status'] !== 'open')
            || ($attempt['status'] === 'open' && $view['state']['active_attempt_id'] !== $result['attempt_id'])
            || $attempt['generation'] !== $result['generation'] || $attempt['invalidated_at'] !== null
            || !$attempt['transfer_complete'] || $attempt['received_bytes'] !== $result['received_bytes']
            || $attempt['sha256'] !== $result['sha256']
            || ($attempt['outcome'] !== null && $attempt['outcome'] !== $result['outcome'])
            || ($attempt['result_fingerprint'] !== null && $attempt['result_fingerprint'] !== $fingerprint)
            || ($attempt['status'] === 'closed' && ($attempt['result_fingerprint'] !== $fingerprint || $attempt['accepted_at'] === null))) {
            throw new RuntimeException('health_result_conflicts_with_attempt');
        }
    }

    private function result_acknowledgement(array $attempt, string $path): array
    {
        $gate = $this->safety_gate->inspect($path, false);
        $pending = $this->health_store->pending_gate($attempt['generation']);
        if (!isset($gate['corruption_write_blocked'], $gate['replacement_transition_blocked'])
            || $pending['pending'] === null) { throw new RuntimeException('health_result_protection_unavailable'); }
        return ['schema_version' => 1, 'command' => 'result', 'result' => 'accepted',
            'attempt_id' => $attempt['attempt_id'], 'generation' => $attempt['generation'],
            'result_fingerprint' => $attempt['result_fingerprint'], 'outcome' => $attempt['outcome'],
            'accepted_at' => $attempt['accepted_at'], 'protection' => [
                'pending' => $pending['pending'], 'corruption_write_blocked' => $gate['corruption_write_blocked'],
                'corruption_incident_open' => $gate['incident_open'],
                'replacement_transition_blocked' => $gate['replacement_transition_blocked']], '_exit_code' => 0];
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
            $message = $this->operational_event_service->mask_credentials($message);
            $message = preg_replace('~(?:[A-Za-z]:)?[/\\\\][^\s]+|(?:https?://)\S+~', '[redacted]', $message);
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
}
