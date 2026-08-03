<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Retention_Corruption_Safety_Gate_Coordinator
{
    private const EVENT_TYPE = 'retention_archive_corruption_detected';

    private $lock_service;
    private $operational_event_service;
    private $run_repository;

    public function __construct(
        ?Kiwi_Retention_Archive_Lock $lock_service = null,
        ?Kiwi_Operational_Event_Service $operational_event_service = null,
        ?Kiwi_Retention_Cleanup_Run_Repository $run_repository = null
    ) {
        $this->lock_service = $lock_service instanceof Kiwi_Retention_Archive_Lock
            ? $lock_service
            : new Kiwi_Retention_Archive_Lock();
        $this->operational_event_service = $operational_event_service instanceof Kiwi_Operational_Event_Service
            ? $operational_event_service
            : new Kiwi_Operational_Event_Service();
        $this->run_repository = $run_repository instanceof Kiwi_Retention_Cleanup_Run_Repository
            ? $run_repository
            : new Kiwi_Retention_Cleanup_Run_Repository();
    }

    public function inspect(string $archive_path, bool $reconcile = false): array
    {
        $archive = Kiwi_Retention_Archive_Name::normalize(basename($archive_path));
        $write_blocked = $this->lock_service->is_write_blocked_for_archive($archive_path);
        if ($archive === '' || $write_blocked === null) {
            return $this->blocked('archive_gate_path_invalid', false, false);
        }
        $transition_source = $this->lock_service
            ->get_replacement_transition_source_for_archive($archive_path);
        if ($transition_source === null) {
            return $this->blocked(
                'replacement_transition_state_invalid',
                (bool) $write_blocked,
                false
            );
        }
        $transition_blocked = $transition_source !== '';

        $incidents = $this->operational_event_service->get_open_incidents([
            'event_type' => self::EVENT_TYPE,
            'reference_type' => 'retention_archive',
            'reference_id' => $archive,
        ], 10);
        if ($incidents === null) {
            return $this->blocked('corruption_incident_lookup_failed', (bool) $write_blocked, false);
        }
        $incident_open = !empty($incidents);
        if (!$write_blocked && !$transition_blocked && !$incident_open) {
            return [
                'allowed' => true,
                'reason_code' => 'corruption_gate_clear',
                'write_blocked' => false,
                'corruption_write_blocked' => false,
                'replacement_transition_blocked' => false,
                'incident_open' => false,
                'incident_action' => 'none',
            ];
        }

        $incident_action = 'none';
        if ($reconcile && $write_blocked !== $incident_open) {
            $lock = $this->lock_service->acquire_for_archive($archive_path);
            if (empty($lock['success'])
                || empty($lock['acquired'])
                || !(($lock['handle'] ?? null) instanceof Kiwi_Retention_Archive_Lock_Handle)
            ) {
                return [
                    'allowed' => false,
                    'reason_code' => (string) ($lock['error_code'] ?? 'archive_lock_active'),
                    'write_blocked' => (bool) ($write_blocked || $transition_blocked),
                    'corruption_write_blocked' => (bool) $write_blocked,
                    'replacement_transition_blocked' => (bool) $transition_blocked,
                    'incident_open' => $incident_open,
                    'incident_action' => 'none',
                ];
            }

            try {
                $write_blocked = $this->lock_service->is_write_blocked_for_archive($archive_path);
                if ($write_blocked === null) {
                    return $this->blocked('archive_gate_path_invalid', false, false);
                }
                $locked_incidents = $this->operational_event_service->get_open_incidents([
                    'event_type' => self::EVENT_TYPE,
                    'reference_type' => 'retention_archive',
                    'reference_id' => $archive,
                ], 10);
                if ($locked_incidents === null) {
                    return $this->blocked(
                        'corruption_incident_lookup_failed',
                        (bool) $write_blocked,
                        false
                    );
                }
                $incident_open = !empty($locked_incidents);
                if ($write_blocked && !$incident_open) {
                    $incident_action = $this->record_corruption(
                        $archive,
                        'integrity',
                        'corruption_write_block_present'
                    );
                    $incident_open = $incident_action !== '';
                } elseif (!$write_blocked && $incident_open) {
                    $write_blocked = $lock['handle']->persist_write_blocked();
                }
            } finally {
                $this->lock_service->release($lock['handle']);
            }
        }

        if (!$write_blocked && !$transition_blocked && !$incident_open) {
            return [
                'allowed' => true,
                'reason_code' => 'corruption_gate_clear',
                'write_blocked' => false,
                'corruption_write_blocked' => false,
                'replacement_transition_blocked' => false,
                'incident_open' => false,
                'incident_action' => 'none',
            ];
        }

        return [
            'allowed' => false,
            'reason_code' => $write_blocked
                ? 'archive_corruption_write_blocked'
                : ($incident_open
                    ? 'archive_corruption_incident_open'
                    : 'replacement_transition_write_blocked'),
            'write_blocked' => (bool) ($write_blocked || $transition_blocked),
            'corruption_write_blocked' => (bool) $write_blocked,
            'replacement_transition_blocked' => (bool) $transition_blocked,
            'incident_open' => $incident_open,
            'incident_action' => in_array($incident_action, ['raised', 'repeated'], true)
                ? $incident_action
                : 'none',
        ];
    }

    public function block_after_corruption(
        string $archive_path,
        string $check,
        string $reason_code
    ): array {
        $archive = Kiwi_Retention_Archive_Name::normalize(basename($archive_path));
        if ($archive === '') {
            return $this->blocked('archive_gate_path_invalid', false, false);
        }

        $write_blocked = $this->lock_service->is_write_blocked_for_archive($archive_path);
        if ($write_blocked === null) {
            return $this->blocked('archive_gate_path_invalid', false, false);
        }
        $sentinel_observed_before_lock = $write_blocked;

        $lock = $this->lock_service->acquire_for_archive($archive_path);
        if (empty($lock['success']) || empty($lock['acquired'])) {
            return $this->blocked(
                (string) ($lock['error_code'] ?? 'archive_lock_active'),
                false,
                false
            );
        }

        try {
            $handle = $lock['handle'] ?? null;
            if (!$handle instanceof Kiwi_Retention_Archive_Lock_Handle) {
                return $this->blocked('archive_lock_failed', false, false);
            }
            $write_blocked = $this->lock_service->is_write_blocked_for_archive($archive_path);
            if ($write_blocked === null) {
                return $this->blocked('archive_gate_path_invalid', false, false);
            }
            if ($sentinel_observed_before_lock && !$write_blocked) {
                $recovered_gate = $this->inspect($archive_path, false);
                if (empty($recovered_gate['allowed'])) {
                    return $recovered_gate;
                }

                return $this->blocked('corruption_gate_recovered_concurrently', false, false);
            }
            if (!$write_blocked) {
                $write_blocked = $handle->persist_write_blocked();
            }
            $incident_action = $this->record_corruption($archive, $check, $reason_code);
            $incident_open = $incident_action !== '';

            return [
                'allowed' => false,
                'reason_code' => ($write_blocked || $incident_open)
                    ? 'sqlite_check_reported_corruption'
                    : 'corruption_gate_persist_failed',
                'write_blocked' => $write_blocked,
                'incident_open' => $incident_open,
                'incident_action' => in_array($incident_action, ['raised', 'repeated'], true)
                    ? $incident_action
                    : 'none',
            ];
        } finally {
            $this->lock_service->release($lock['handle'] ?? null);
        }
    }

    public function record_corruption_incident_while_generation_locked(
        string $archive_path,
        string $check,
        string $reason_code
    ): array {
        $archive = Kiwi_Retention_Archive_Name::normalize(basename($archive_path));
        if ($archive === '') {
            return $this->blocked('archive_gate_path_invalid', false, false);
        }

        $incident_action = $this->record_corruption($archive, $check, $reason_code);
        $incident_open = $incident_action !== '';

        return [
            'allowed' => false,
            'reason_code' => $incident_open
                ? 'sqlite_check_reported_corruption'
                : 'corruption_incident_persist_failed',
            'write_blocked' => false,
            'incident_open' => $incident_open,
            'incident_action' => in_array($incident_action, ['raised', 'repeated'], true)
                ? $incident_action
                : 'none',
        ];
    }

    public function unblock(
        string $archive_path,
        string $replacement_archive_path = ''
    ): array {
        $archive = Kiwi_Retention_Archive_Name::normalize(basename($archive_path));
        $replacement = $replacement_archive_path !== ''
            ? Kiwi_Retention_Archive_Name::normalize(basename($replacement_archive_path))
            : '';
        if ($archive === ''
            || ($replacement_archive_path !== '' && $replacement === '')
            || ($replacement !== '' && $replacement === $archive)
        ) {
            return $this->blocked('unblock_archive_invalid', false, false);
        }

        $replacement_transition_source = '';
        if ($replacement !== '') {
            $replacement_transition_source = $this->lock_service
                ->get_replacement_transition_source_for_archive($replacement_archive_path);
            if ($replacement_transition_source === null) {
                return $this->blocked('replacement_transition_state_invalid', true, true);
            }
        }

        $source_gate = $this->inspect($archive_path, false);
        if (in_array((string) ($source_gate['reason_code'] ?? ''), [
            'archive_gate_path_invalid',
            'corruption_incident_lookup_failed',
            'replacement_transition_state_invalid',
        ], true)) {
            return $this->blocked(
                (string) $source_gate['reason_code'],
                !empty($source_gate['write_blocked']),
                !empty($source_gate['incident_open'])
            );
        }
        if (empty($source_gate['corruption_write_blocked'])
            && empty($source_gate['incident_open'])
            && $replacement_transition_source !== $archive
        ) {
            return $this->blocked(
                'unblock_corruption_gate_required',
                !empty($source_gate['write_blocked']),
                false
            );
        }

        $lock = $this->lock_service->acquire_for_archive($archive_path);
        if (empty($lock['success']) || empty($lock['acquired'])) {
            return $this->blocked(
                (string) ($lock['error_code'] ?? 'archive_lock_active'),
                false,
                true
            );
        }

        $replacement_handle = null;
        try {
            $handle = $lock['handle'] ?? null;
            if (!$handle instanceof Kiwi_Retention_Archive_Lock_Handle) {
                return $this->blocked('archive_lock_failed', false, false);
            }

            $locked_transition_source = $replacement !== ''
                ? $this->lock_service->get_replacement_transition_source_for_archive(
                    $replacement_archive_path
                )
                : '';
            if ($locked_transition_source === null) {
                return $this->blocked('replacement_transition_state_invalid', true, true);
            }
            $locked_source_gate = $this->inspect($archive_path, false);
            if (in_array((string) ($locked_source_gate['reason_code'] ?? ''), [
                'archive_gate_path_invalid',
                'corruption_incident_lookup_failed',
                'replacement_transition_state_invalid',
            ], true)) {
                return $this->blocked(
                    (string) $locked_source_gate['reason_code'],
                    !empty($locked_source_gate['write_blocked']),
                    !empty($locked_source_gate['incident_open'])
                );
            }
            if (empty($locked_source_gate['corruption_write_blocked'])
                && empty($locked_source_gate['incident_open'])
                && $locked_transition_source !== $archive
            ) {
                return $this->blocked(
                    'unblock_corruption_gate_required',
                    !empty($locked_source_gate['write_blocked']),
                    false
                );
            }

            if ($replacement !== '') {
                $replacement_lock = $this->lock_service->acquire_for_archive(
                    $replacement_archive_path
                );
                if (empty($replacement_lock['success']) || empty($replacement_lock['acquired'])) {
                    return $this->blocked(
                        (string) ($replacement_lock['error_code'] ?? 'archive_lock_active'),
                        true,
                        true
                    );
                }
                $replacement_handle = $replacement_lock['handle'] ?? null;
                if (!$replacement_handle instanceof Kiwi_Retention_Archive_Lock_Handle) {
                    return $this->blocked('replacement_archive_lock_failed', true, true);
                }
                $replacement_gate = $this->inspect($replacement_archive_path, false);
                if (in_array((string) ($replacement_gate['reason_code'] ?? ''), [
                    'archive_gate_path_invalid',
                    'corruption_incident_lookup_failed',
                ], true)) {
                    return $this->blocked(
                        (string) $replacement_gate['reason_code'],
                        !empty($replacement_gate['write_blocked']),
                        !empty($replacement_gate['incident_open'])
                    );
                }
                if (!empty($replacement_gate['corruption_write_blocked'])
                    || !empty($replacement_gate['incident_open'])
                ) {
                    return $this->blocked(
                        'replacement_corruption_gate_open',
                        !empty($replacement_gate['write_blocked']),
                        !empty($replacement_gate['incident_open'])
                    );
                }
                $locked_transition_source = $this->lock_service
                    ->get_replacement_transition_source_for_archive($replacement_archive_path);
                if ($locked_transition_source === null
                    || ($locked_transition_source !== ''
                        && $locked_transition_source !== $archive)
                ) {
                    return $this->blocked('replacement_transition_state_invalid', true, true);
                }
                if (!$replacement_handle->persist_replacement_transition_blocked($archive)) {
                    return $this->blocked(
                        'replacement_transition_block_persist_failed',
                        true,
                        true
                    );
                }
            }

            if ($replacement !== ''
                && !$this->run_repository->terminalize_open_run_for_manual_replacement(
                    $archive_path,
                    $replacement_archive_path
                )
            ) {
                return $this->blocked('replacement_run_terminalize_failed', true, true);
            }
            if (!$handle->clear_write_blocked()) {
                return $this->blocked('corruption_write_block_clear_failed', true, true);
            }

            $incident_action = $this->operational_event_service->record_recovery_action([
                'area' => 'retention',
                'severity' => 'info',
                'event_type' => self::EVENT_TYPE,
                'correlation_key' => $this->correlation_key($archive),
                'reference_type' => 'retention_archive',
                'reference_id' => $archive,
                'message' => $replacement !== ''
                    ? 'A manually selected replacement archive was fully verified before cleanup resumed.'
                    : 'The manually repaired archive passed a complete integrity check before cleanup resumed.',
                'context' => [
                    'resolution_reason' => $replacement !== ''
                        ? 'manual_replacement_verified'
                        : 'manual_repair_verified',
                    'archive' => $archive,
                    'replacement_archive' => $replacement !== '' ? $replacement : null,
                ],
            ]);
            if ($incident_action === '') {
                return $this->blocked(
                    'corruption_incident_resolution_failed',
                    $replacement !== '',
                    true
                );
            }
            if ($replacement_handle instanceof Kiwi_Retention_Archive_Lock_Handle
                && !$replacement_handle->clear_replacement_transition_blocked()
            ) {
                return $this->blocked('replacement_transition_block_clear_failed', true, false);
            }

            return [
                'allowed' => true,
                'reason_code' => $replacement !== ''
                    ? 'manual_replacement_unblocked'
                    : 'manual_repair_unblocked',
                'write_blocked' => false,
                'incident_open' => false,
                'incident_action' => $incident_action,
            ];
        } finally {
            $this->lock_service->release($replacement_handle);
            $this->lock_service->release($lock['handle'] ?? null);
        }
    }

    private function record_corruption(string $archive, string $check, string $reason_code): string
    {
        return $this->operational_event_service->record_failure_action([
            'area' => 'retention',
            'severity' => 'critical',
            'event_type' => self::EVENT_TYPE,
            'correlation_key' => $this->correlation_key($archive),
            'idempotency_key' => 'retention_archive_corruption_' . hash(
                'sha256',
                $archive . ':' . $check . ':' . $reason_code
            ),
            'scope_idempotency_to_lifecycle' => true,
            'reference_type' => 'retention_archive',
            'reference_id' => $archive,
            'message' => 'A complete read-only SQLite PRAGMA check confirmed archive corruption.',
            'context' => [
                'archive' => $archive,
                'check' => $check,
                'reason_code' => $reason_code,
            ],
        ]);
    }

    private function correlation_key(string $archive): string
    {
        return 'retention_archive_corruption_' . hash('sha256', $archive);
    }

    private function blocked(
        string $reason_code,
        bool $write_blocked,
        bool $incident_open
    ): array {
        return [
            'allowed' => false,
            'reason_code' => $reason_code,
            'write_blocked' => $write_blocked,
            'incident_open' => $incident_open,
            'incident_action' => 'none',
        ];
    }
}
