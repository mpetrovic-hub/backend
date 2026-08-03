<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Kiwi_Retention_Archive_Health_Controller
{
    private const AVAILABILITY_EVENT_TYPE = 'retention_archive_health_unavailable';
    private const AVAILABILITY_CORRELATION = 'retention_archive_health_availability';

    private $archive_service;
    private $supervisor;
    private $safety_gate;
    private $operational_event_service;
    private $run_repository;
    private $clock;
    private $archive_lock;

    public function __construct(
        ?Kiwi_Retention_Sqlite_Archive_Service $archive_service = null,
        ?Kiwi_Retention_Archive_Check_Supervisor $supervisor = null,
        ?Kiwi_Retention_Corruption_Safety_Gate_Coordinator $safety_gate = null,
        ?Kiwi_Operational_Event_Service $operational_event_service = null,
        ?Kiwi_Retention_Cleanup_Run_Repository $run_repository = null,
        ?callable $clock = null,
        ?Kiwi_Retention_Archive_Lock $archive_lock = null
    ) {
        $this->archive_service = $archive_service instanceof Kiwi_Retention_Sqlite_Archive_Service
            ? $archive_service
            : new Kiwi_Retention_Sqlite_Archive_Service();
        $this->supervisor = $supervisor instanceof Kiwi_Retention_Archive_Check_Supervisor
            ? $supervisor
            : new Kiwi_Retention_Archive_Check_Supervisor();
        $this->operational_event_service = $operational_event_service instanceof Kiwi_Operational_Event_Service
            ? $operational_event_service
            : new Kiwi_Operational_Event_Service();
        $this->run_repository = $run_repository instanceof Kiwi_Retention_Cleanup_Run_Repository
            ? $run_repository
            : new Kiwi_Retention_Cleanup_Run_Repository();
        $this->safety_gate = $safety_gate instanceof Kiwi_Retention_Corruption_Safety_Gate_Coordinator
            ? $safety_gate
            : new Kiwi_Retention_Corruption_Safety_Gate_Coordinator(
                null,
                $this->operational_event_service,
                $this->run_repository
            );
        $this->clock = $clock ?? static function (): DateTimeImmutable {
            return new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
        };
        $this->archive_lock = $archive_lock instanceof Kiwi_Retention_Archive_Lock
            ? $archive_lock
            : new Kiwi_Retention_Archive_Lock();
    }

    public function check(string $check): array
    {
        $started = microtime(true);
        $started_at = $this->now();
        $check = $this->normalize_check($check);
        if ($check === '') {
            return $this->result('check', 'error', 'check_input_invalid', null, null, $started_at, $started, 2);
        }

        $active = $this->resolve_active_archive();
        if (empty($active['success'])) {
            $reason_code = (string) ($active['reason_code'] ?? 'active_archive_lookup_failed');
            $incident_action = $this->record_availability_failure($reason_code, null, $check, $started_at);

            return $this->result(
                'check',
                'error',
                $incident_action === '' ? 'availability_incident_persist_failed' : $reason_code,
                null,
                $check,
                $started_at,
                $started,
                2,
                ['incident_action' => $incident_action]
            );
        }

        $archive_path = (string) $active['path'];
        $archive = (string) $active['name'];
        $gate = $this->safety_gate->inspect($archive_path, true);
        if (empty($gate['allowed'])) {
            $gate_reason = (string) ($gate['reason_code'] ?? 'archive_corruption_blocked');
            if (in_array($gate_reason, [
                'archive_gate_path_invalid',
                'corruption_incident_lookup_failed',
                'replacement_transition_state_invalid',
            ], true)) {
                $incident_action = $this->record_availability_failure(
                    $gate_reason,
                    $archive,
                    $check,
                    $started_at
                );

                return $this->result(
                    'check',
                    'error',
                    $incident_action === ''
                        ? 'availability_incident_persist_failed'
                        : $gate_reason,
                    $archive,
                    $check,
                    $started_at,
                    $started,
                    2,
                    array_merge($gate, ['incident_action' => $incident_action])
                );
            }

            $availability_action = '';
            if (!empty($gate['corruption_write_blocked']) || !empty($gate['incident_open'])) {
                $availability_action = $this->record_availability_recovery($archive, $check);
                if ($availability_action === '') {
                    return $this->result(
                        'check',
                        'error',
                        'availability_incident_resolution_failed',
                        $archive,
                        $check,
                        $started_at,
                        $started,
                        2,
                        $gate
                    );
                }
            }
            $gate_action = (string) ($gate['incident_action'] ?? '');
            $reported_action = in_array($gate_action, ['raised', 'repeated', 'resolved'], true)
                ? $gate_action
                : $availability_action;

            return $this->result(
                'check',
                'blocked',
                $gate_reason,
                $archive,
                $check,
                $started_at,
                $started,
                1,
                array_merge($gate, ['incident_action' => $reported_action])
            );
        }

        $outcome = $this->supervisor->run(
            $archive_path,
            $check,
            true,
            function (string $path, string $mode, string $reason_code): array {
                return $this->safety_gate->record_corruption_incident_while_generation_locked(
                    $path,
                    $mode,
                    $reason_code
                );
            }
        );
        $outcome_result = (string) ($outcome['result'] ?? 'error');
        $check_completed = !empty($outcome['check_completed']);
        if ($outcome_result === 'ok' && $check_completed) {
            $post_check_gate = $this->safety_gate->inspect($archive_path, false);
            if (empty($post_check_gate['allowed'])) {
                $post_check_reason = (string) (
                    $post_check_gate['reason_code'] ?? 'corruption_gate_state_invalid'
                );
                if (in_array($post_check_reason, [
                    'archive_gate_path_invalid',
                    'corruption_incident_lookup_failed',
                    'replacement_transition_state_invalid',
                ], true)) {
                    $incident_action = $this->record_availability_failure(
                        $post_check_reason,
                        $archive,
                        $check,
                        $started_at
                    );

                    return $this->result(
                        'check',
                        'error',
                        $incident_action === ''
                            ? 'availability_incident_persist_failed'
                            : $post_check_reason,
                        $archive,
                        $check,
                        $started_at,
                        $started,
                        2,
                        array_merge($outcome, $post_check_gate, [
                            'incident_action' => $incident_action,
                        ])
                    );
                }

                $availability_action = '';
                if (!empty($post_check_gate['corruption_write_blocked'])
                    || !empty($post_check_gate['incident_open'])
                ) {
                    $availability_action = $this->record_availability_recovery($archive, $check);
                    if ($availability_action === '') {
                        return $this->result(
                            'check',
                            'error',
                            'availability_incident_resolution_failed',
                            $archive,
                            $check,
                            $started_at,
                            $started,
                            2,
                            array_merge($outcome, $post_check_gate)
                        );
                    }
                }
                $gate_action = (string) ($post_check_gate['incident_action'] ?? '');
                $reported_action = in_array($gate_action, ['raised', 'repeated', 'resolved'], true)
                    ? $gate_action
                    : $availability_action;

                return $this->result(
                    'check',
                    'blocked',
                    $post_check_reason,
                    $archive,
                    $check,
                    $started_at,
                    $started,
                    1,
                    array_merge($outcome, $post_check_gate, [
                        'incident_action' => $reported_action,
                    ])
                );
            }
            $incident_action = $this->record_availability_recovery($archive, $check);

            return $this->result(
                'check',
                $incident_action === '' ? 'error' : 'ok',
                $incident_action === '' ? 'availability_incident_resolution_failed' : 'sqlite_check_ok',
                $archive,
                $check,
                $started_at,
                $started,
                $incident_action === '' ? 2 : 0,
                array_merge($outcome, ['incident_action' => $incident_action])
            );
        }

        if ($outcome_result === 'corruption_detected' && $check_completed) {
            $gate = !empty($outcome['incident_open'])
                ? $this->safety_gate->inspect($archive_path, true)
                : $this->safety_gate->block_after_corruption(
                    $archive_path,
                    $check,
                    (string) ($outcome['reason_code'] ?? 'sqlite_check_reported_corruption')
                );
            $availability_action = $this->record_availability_recovery($archive, $check);
            $gate_persisted = !empty($gate['write_blocked']) || !empty($gate['incident_open']);
            $corruption_action = (string) ($gate['incident_action'] ?? '');
            $handoff_action = (string) ($outcome['incident_action'] ?? '');
            $reported_action = in_array($corruption_action, ['raised', 'repeated', 'resolved'], true)
                ? $corruption_action
                : (in_array($handoff_action, ['raised', 'repeated', 'resolved'], true)
                    ? $handoff_action
                    : $availability_action);

            return $this->result(
                'check',
                $gate_persisted && $availability_action !== '' ? 'corruption_detected' : 'error',
                !$gate_persisted
                    ? 'corruption_gate_persist_failed'
                    : ($availability_action === ''
                        ? 'availability_incident_resolution_failed'
                        : (string) ($gate['reason_code'] ?? 'sqlite_check_reported_corruption')),
                $archive,
                $check,
                $started_at,
                $started,
                $gate_persisted && $availability_action !== '' ? 1 : 2,
                array_merge($outcome, $gate, [
                    'incident_action' => $reported_action,
                ])
            );
        }

        $reason_code = (string) ($outcome['reason_code'] ?? 'sqlite_readonly_check_failed');
        $incident_action = $this->record_availability_failure(
            $reason_code,
            $archive,
            $check,
            $started_at,
            $archive_path
        );
        $result = $outcome_result === 'deferred'
            ? 'deferred'
            : ($outcome_result === 'inconclusive' ? 'inconclusive' : 'error');
        $exit_code = in_array($result, ['deferred', 'inconclusive'], true) ? 1 : 2;
        if ($incident_action === '') {
            $result = 'error';
            $reason_code = 'availability_incident_persist_failed';
            $exit_code = 2;
        }

        return $this->result(
            'check',
            $result,
            $reason_code,
            $archive,
            $check,
            $started_at,
            $started,
            $exit_code,
            array_merge($outcome, ['incident_action' => $incident_action])
        );
    }

    public function diagnose(string $archive_name, string $check): array
    {
        $started = microtime(true);
        $started_at = $this->now();
        $check = $this->normalize_check($check);
        $archive = $this->find_archive($archive_name);
        if ($check === '' || !is_array($archive)) {
            return $this->result(
                'diagnose',
                'error',
                'diagnose_input_invalid',
                Kiwi_Retention_Archive_Name::normalize($archive_name) ?: null,
                $check !== '' ? $check : null,
                $started_at,
                $started,
                2
            );
        }

        $outcome = $this->supervisor->run((string) $archive['path'], $check);
        $outcome_result = (string) ($outcome['result'] ?? 'error');
        $exit_code = $outcome_result === 'ok'
            ? 0
            : (in_array($outcome_result, ['corruption_detected', 'deferred', 'inconclusive'], true) ? 1 : 2);

        return $this->result(
            'diagnose',
            $outcome_result,
            (string) ($outcome['reason_code'] ?? 'sqlite_readonly_check_failed'),
            (string) $archive['name'],
            $check,
            $started_at,
            $started,
            $exit_code,
            $outcome
        );
    }

    public function unblock(
        string $archive_name,
        string $replacement_archive_name,
        bool $confirmed
    ): array {
        $started = microtime(true);
        $started_at = $this->now();
        $archive = $this->find_archive($archive_name);
        $replacement = trim($replacement_archive_name) !== ''
            ? $this->find_archive($replacement_archive_name)
            : null;
        if (!$confirmed
            || !is_array($archive)
            || (trim($replacement_archive_name) !== '' && !is_array($replacement))
        ) {
            return $this->result(
                'unblock',
                'error',
                $confirmed ? 'unblock_input_invalid' : 'unblock_confirmation_required',
                Kiwi_Retention_Archive_Name::normalize($archive_name) ?: null,
                'integrity',
                $started_at,
                $started,
                2
            );
        }

        if (is_array($replacement)) {
            $old_identity = Kiwi_Retention_Archive_Name::parse((string) $archive['name']);
            $replacement_identity = Kiwi_Retention_Archive_Name::parse((string) $replacement['name']);
            if (!is_array($old_identity)
                || !is_array($replacement_identity)
                || (string) $old_identity['name'] === (string) $replacement_identity['name']
            ) {
                return $this->result(
                    'unblock',
                    'error',
                    'replacement_generation_invalid',
                    (string) $archive['name'],
                    'integrity',
                    $started_at,
                    $started,
                    2
                );
            }

            if (!$this->is_active_replacement($replacement)) {
                return $this->result(
                    'unblock',
                    'error',
                    'replacement_generation_not_active',
                    (string) $archive['name'],
                    'integrity',
                    $started_at,
                    $started,
                    2
                );
            }
        }

        $verification_target = is_array($replacement) ? $replacement : $archive;
        $verification = $this->supervisor->run(
            (string) $verification_target['path'],
            'integrity',
            true,
            function (string $path, string $mode, string $reason_code): array {
                return $this->safety_gate->record_corruption_incident_while_generation_locked(
                    $path,
                    $mode,
                    $reason_code
                );
            },
            true
        );
        if ((string) ($verification['result'] ?? '') === 'corruption_detected'
            && !empty($verification['check_completed'])
        ) {
            $verification = array_merge(
                $verification,
                $this->safety_gate->block_after_corruption(
                    (string) $verification_target['path'],
                    'integrity',
                    (string) ($verification['reason_code'] ?? 'sqlite_check_reported_corruption')
                )
            );
        }
        if ((string) ($verification['result'] ?? '') !== 'ok'
            || empty($verification['check_completed'])
        ) {
            $verification_result = (string) ($verification['result'] ?? 'error');
            $verification_reason = (string) ($verification['reason_code'] ?? '');
            $is_expected_block = in_array($verification_result, ['deferred', 'inconclusive'], true)
                || ($verification_result === 'corruption_detected'
                    && in_array($verification_reason, [
                        'sqlite_check_reported_corruption',
                        'archive_lock_active',
                    ], true));

            return $this->result(
                'unblock',
                $is_expected_block ? 'blocked' : 'error',
                $verification_reason !== ''
                    ? $verification_reason
                    : 'unblock_integrity_verification_failed',
                (string) $archive['name'],
                'integrity',
                $started_at,
                $started,
                $is_expected_block ? 1 : 2,
                $verification
            );
        }

        if (is_array($replacement) && !$this->is_active_replacement($replacement)) {
            return $this->result(
                'unblock',
                'error',
                'replacement_generation_not_active',
                (string) $archive['name'],
                'integrity',
                $started_at,
                $started,
                2
            );
        }

        $unblocked = $this->safety_gate->unblock(
            (string) $archive['path'],
            is_array($replacement) ? (string) $replacement['path'] : ''
        );
        $allowed = !empty($unblocked['allowed']);
        $reason_code = (string) ($unblocked['reason_code'] ?? 'unblock_failed');
        $is_expected_block = in_array($reason_code, [
            'archive_lock_active',
            'unblock_corruption_gate_required',
            'replacement_corruption_gate_open',
        ], true);

        return $this->result(
            'unblock',
            $allowed ? 'ok' : ($is_expected_block ? 'blocked' : 'error'),
            $reason_code,
            (string) $archive['name'],
            'integrity',
            $started_at,
            $started,
            $allowed ? 0 : ($is_expected_block ? 1 : 2),
            $unblocked
        );
    }

    public function bootstrap_failure(string $reason_code): array
    {
        $started_at = $this->now();
        $reason_code = $this->normalize_reason($reason_code, 'health_bootstrap_failed');
        $incident_action = $this->record_availability_failure(
            $reason_code,
            null,
            '',
            $started_at
        );

        return $this->result(
            'check',
            'error',
            $incident_action === '' ? 'availability_incident_persist_failed' : $reason_code,
            null,
            null,
            $started_at,
            microtime(true),
            2,
            ['incident_action' => $incident_action]
        );
    }

    private function resolve_active_archive(): array
    {
        try {
            $open = $this->run_repository->find_open_archive_state();
        } catch (Throwable $error) {
            return ['success' => false, 'reason_code' => 'active_archive_lookup_failed'];
        }
        if ($open === null) {
            return ['success' => false, 'reason_code' => 'active_archive_lookup_failed'];
        }
        if (!empty($open)) {
            try {
                $path = $this->archive_service->resolve_existing_archive_db_path_read_only(
                    (string) ($open['archive_db_path'] ?? '')
                );
            } catch (Throwable $error) {
                return ['success' => false, 'reason_code' => 'active_archive_path_invalid'];
            }
            $name = Kiwi_Retention_Archive_Name::normalize(basename($path));
            if ($name === '' || !is_file($path) || is_link($path)) {
                return ['success' => false, 'reason_code' => 'active_archive_missing'];
            }

            return ['success' => true, 'name' => $name, 'path' => $path];
        }

        try {
            $archives = $this->archive_service->list_archive_files();
        } catch (Throwable $error) {
            return ['success' => false, 'reason_code' => 'archive_discovery_failed'];
        }
        $archive = !empty($archives) ? $archives[count($archives) - 1] : null;
        if (!is_array($archive)) {
            return ['success' => false, 'reason_code' => 'archive_not_found'];
        }

        return [
            'success' => true,
            'name' => (string) ($archive['name'] ?? ''),
            'path' => (string) ($archive['path'] ?? ''),
        ];
    }

    private function find_archive(string $archive_name): ?array
    {
        $archive_name = Kiwi_Retention_Archive_Name::normalize($archive_name);
        if ($archive_name === '') {
            return null;
        }

        try {
            foreach ($this->archive_service->list_archive_files() as $archive) {
                if ((string) ($archive['name'] ?? '') === $archive_name) {
                    return $archive;
                }
            }
        } catch (Throwable $error) {
            return null;
        }

        return null;
    }

    private function is_active_replacement(array $replacement): bool
    {
        try {
            $active_archive_path = $this->archive_service->resolve_archive_db_path();
        } catch (Throwable $error) {
            return false;
        }

        return $active_archive_path !== ''
            && $active_archive_path === (string) ($replacement['path'] ?? '');
    }

    private function record_availability_failure(
        string $reason_code,
        ?string $archive,
        string $check,
        string $started_at,
        ?string $archive_path = null
    ): string {
        $event = [
            'area' => 'retention',
            'severity' => 'error',
            'event_type' => self::AVAILABILITY_EVENT_TYPE,
            'correlation_key' => self::AVAILABILITY_CORRELATION,
            'idempotency_key' => 'retention_archive_health_availability_' . hash(
                'sha256',
                $started_at . ':' . $reason_code . ':' . (string) $archive
            ),
            'reference_type' => 'retention_archive_health',
            'reference_id' => $archive ?? 'current',
            'message' => 'The external read-only retention archive health check did not complete definitively.',
            'context' => [
                'archive' => $archive,
                'check' => $check,
                'reason_code' => $reason_code,
            ],
        ];
        if ($reason_code !== 'archive_lock_active' || trim((string) $archive_path) === '') {
            return $this->operational_event_service->record_failure_action($event);
        }

        return $this->operational_event_service->record_failure_action_if(
            $event,
            function () use ($archive_path): bool {
                $lock = $this->archive_lock->acquire_for_archive((string) $archive_path);
                if (!empty($lock['success']) && !empty($lock['acquired'])) {
                    try {
                        return false;
                    } finally {
                        $this->archive_lock->release($lock['handle'] ?? null);
                    }
                }
                if (!empty($lock['success'])
                    && (string) ($lock['error_code'] ?? '') === 'archive_lock_active'
                ) {
                    return true;
                }

                throw new RuntimeException('Archive lock availability probe failed.');
            }
        );
    }

    private function record_availability_recovery(string $archive, string $check): string
    {
        return $this->operational_event_service->record_recovery_action([
            'area' => 'retention',
            'severity' => 'info',
            'event_type' => self::AVAILABILITY_EVENT_TYPE,
            'correlation_key' => self::AVAILABILITY_CORRELATION,
            'reference_type' => 'retention_archive_health',
            'reference_id' => $archive,
            'message' => 'The external read-only retention archive health check completed definitively.',
            'context' => [
                'archive' => $archive,
                'check' => $check,
            ],
        ]);
    }

    private function result(
        string $command,
        string $result,
        string $reason_code,
        ?string $archive,
        ?string $check,
        string $started_at,
        float $started,
        int $exit_code,
        array $details = []
    ): array {
        $payload = [
            'schema_version' => 1,
            'command' => $command,
            'result' => $result,
            'reason_code' => $this->normalize_reason($reason_code, 'health_result_invalid'),
            'archive' => $archive,
            'check' => $check,
            'started_at' => $started_at,
            'finished_at' => $this->now(),
            'duration_seconds' => round(max(0.0, microtime(true) - $started), 6),
            '_exit_code' => min(2, max(0, $exit_code)),
        ];
        $incident_action = (string) ($details['incident_action'] ?? '');
        if (in_array($incident_action, ['raised', 'repeated', 'resolved'], true)) {
            $payload['incident_action'] = $incident_action;
        }
        if (array_key_exists('write_blocked', $details)) {
            $payload['write_blocked'] = !empty($details['write_blocked']);
        }
        if ((string) ($details['reason_code'] ?? '') === 'health_child_timeout'
            || !empty($details['child_running'])
        ) {
            $payload['child_running'] = !empty($details['child_running']);
        }

        return $payload;
    }

    private function normalize_check(string $check): string
    {
        $check = strtolower(trim($check));

        return in_array($check, ['quick', 'integrity'], true) ? $check : '';
    }

    private function normalize_reason(string $reason_code, string $fallback): string
    {
        $reason_code = strtolower(trim($reason_code));

        return preg_match('/^[a-z0-9_]{1,100}$/', $reason_code) === 1
            ? $reason_code
            : $fallback;
    }

    private function now(): string
    {
        $now = call_user_func($this->clock);

        return $now instanceof DateTimeInterface
            ? $now->format(DATE_ATOM)
            : (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format(DATE_ATOM);
    }
}
