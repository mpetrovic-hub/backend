<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Retention_Cleanup_Service
{
    private const LOCK_PREFIX = 'kiwi_retention_cleanup_lock_';
    private const WORKER_LOCK_PREFIX = 'kiwi_retention_cleanup_worker_lock_';
    private const STALE_RUN_AFTER_MINUTES = 30;

    private $config;
    private $source_registry;
    private $run_repository;
    private $snapshot_repository;
    private $archive_service;
    private $coverage_gate;
    private $operational_event_service;

    public function __construct(
        ?Kiwi_Config $config = null,
        ?Kiwi_Retention_Source_Registry $source_registry = null,
        ?Kiwi_Retention_Cleanup_Run_Repository $run_repository = null,
        ?Kiwi_Retention_Table_Growth_Snapshot_Repository $snapshot_repository = null,
        ?Kiwi_Retention_Sqlite_Archive_Service $archive_service = null,
        ?Kiwi_Retention_Coverage_Gate $coverage_gate = null,
        ?Kiwi_Operational_Event_Service $operational_event_service = null
    ) {
        $this->config = $config instanceof Kiwi_Config ? $config : new Kiwi_Config();
        $this->source_registry = $source_registry instanceof Kiwi_Retention_Source_Registry
            ? $source_registry
            : new Kiwi_Retention_Source_Registry();
        $this->run_repository = $run_repository instanceof Kiwi_Retention_Cleanup_Run_Repository
            ? $run_repository
            : new Kiwi_Retention_Cleanup_Run_Repository();
        $this->snapshot_repository = $snapshot_repository instanceof Kiwi_Retention_Table_Growth_Snapshot_Repository
            ? $snapshot_repository
            : new Kiwi_Retention_Table_Growth_Snapshot_Repository();
        $this->archive_service = $archive_service instanceof Kiwi_Retention_Sqlite_Archive_Service
            ? $archive_service
            : new Kiwi_Retention_Sqlite_Archive_Service($this->config);
        $this->coverage_gate = $coverage_gate instanceof Kiwi_Retention_Coverage_Gate
            ? $coverage_gate
            : new Kiwi_Retention_Coverage_Gate($this->config);
        $this->operational_event_service = $operational_event_service instanceof Kiwi_Operational_Event_Service
            ? $operational_event_service
            : new Kiwi_Operational_Event_Service();
    }

    public function run_source(string $source_key, string $triggered_by = 'cron'): array
    {
        $source = $this->source_registry->get($source_key);

        if (!is_array($source)) {
            return [
                'success' => false,
                'status' => 'failed',
                'error_code' => 'unknown_source',
                'error_message' => 'Unknown retention source: ' . $source_key,
            ];
        }

        if (!$this->mark_stale_runs($source_key)) {
            return [
                'success' => false,
                'status' => 'failed',
                'error_code' => 'stale_run_detection_failed',
                'error_message' => 'Retention cleanup could not verify stale unfinished audit runs.',
            ];
        }

        $existing_run = $this->run_repository->find_open_run_for_source($source_key);

        if (is_array($existing_run)) {
            $this->update_run_progress((int) ($existing_run['id'] ?? 0), [
                'worker_phase' => 'active_run_rescheduled',
                'worker_last_finished_at' => $this->current_time_mysql(),
            ]);

            return [
                'success' => true,
                'run_id' => (string) ($existing_run['run_id'] ?? ''),
                'status' => (string) ($existing_run['status'] ?? 'partial'),
                'worker_phase' => 'active_run_rescheduled',
                'schedule_worker' => true,
                'reschedule_worker' => true,
                'reschedule_delay_seconds' => $this->config->get_retention_worker_reschedule_delay_seconds(),
                'error_code' => 'cleanup_run_already_open',
                'error_message' => 'Retention cleanup has an open worker run; scheduler rescheduled the worker instead of creating a second run.',
            ];
        }

        $settings = $this->config->get_retention_source_settings($source_key);
        $enabled = !empty($settings['enabled']);
        $dry_run = !empty($settings['dry_run']);
        $retention_days = $this->resolve_retention_days($settings, $source);
        $cutoff_value = $this->build_cutoff_value($retention_days);
        $run_id = $this->generate_run_id();
        $archive_batch_id = $this->generate_archive_batch_id($source_key);
        $started_at = $this->current_time_mysql();
        $eligible_rows = $this->count_eligible_rows($source, $cutoff_value);
        $run_db_id = $this->run_repository->create_run([
            'run_id' => $run_id,
            'source_key' => $source_key,
            'source_table' => (string) ($source['source_table'] ?? ''),
            'status' => 'skipped',
            'triggered_by' => $this->normalize_triggered_by($triggered_by),
            'enabled' => $enabled,
            'dry_run' => $dry_run,
            'started_at' => $started_at,
            'retention_days_effective' => $retention_days,
            'cutoff_column' => (string) ($source['cutoff_column'] ?? ''),
            'cutoff_value' => $cutoff_value,
            'eligible_rows' => $eligible_rows,
            'archive_batch_id' => $archive_batch_id,
        ]);

        if ($run_db_id <= 0) {
            return [
                'success' => false,
                'run_id' => $run_id,
                'status' => 'failed',
                'error_code' => 'run_audit_create_failed',
                'error_message' => 'Retention cleanup aborted because the audit run row could not be created.',
                'eligible_rows' => $eligible_rows,
            ];
        }

        if ($this->has_lock($source_key)) {
            return $this->finish_run($run_db_id, [
                'success' => true,
                'run_id' => $run_id,
                'status' => 'skipped',
                'error_code' => 'lock_active',
                'error_message' => 'Retention cleanup skipped because lock is active.',
            ]);
        }

        $this->set_lock($source_key);

        try {
            if (!$enabled) {
                if (!$this->capture_snapshot(
                    $source,
                    'before_cleanup',
                    $retention_days,
                    $cutoff_value,
                    $eligible_rows,
                    $run_id,
                    '',
                    0,
                    0
                )) {
                    return $this->finish_run($run_db_id, $this->snapshot_before_failure($run_id));
                }
                if (!$this->capture_snapshot(
                    $source,
                    'after_cleanup',
                    $retention_days,
                    $cutoff_value,
                    $eligible_rows,
                    $run_id,
                    '',
                    0,
                    0
                )) {
                    return $this->finish_run($run_db_id, $this->snapshot_after_failure($run_id));
                }

                return $this->finish_run($run_db_id, [
                    'success' => true,
                    'run_id' => $run_id,
                    'status' => 'skipped',
                    'gate_status' => 'skipped',
                    'error_code' => 'cleanup_disabled',
                    'error_message' => 'Retention cleanup is disabled for this source.',
                ]);
            }

            if (!$this->update_run_progress($run_db_id, ['worker_phase' => 'coverage_gate_running'])) {
                return $this->finish_run($run_db_id, [
                    'success' => false,
                    'run_id' => $run_id,
                    'status' => 'failed',
                    'error_code' => 'run_audit_update_failed',
                    'error_message' => 'Retention cleanup could not persist the coverage-gate heartbeat.',
                ]);
            }

            $gate_results = $this->coverage_gate->check_landing_page_sessions($source, $cutoff_value);
            $gate_status = (string) ($gate_results['status'] ?? 'failed');
            $effective_cutoff_value = $this->resolve_effective_cutoff_value($gate_results, $cutoff_value);
            $effective_cutoff_for_readout = $effective_cutoff_value !== '' ? $effective_cutoff_value : $cutoff_value;
            $effective_eligible_rows = $effective_cutoff_for_readout === $cutoff_value
                ? $eligible_rows
                : $this->count_eligible_rows($source, $effective_cutoff_for_readout);

            if ($dry_run) {
                if (!$this->update_run_progress($run_db_id, ['worker_phase' => 'snapshot_before_running'])) {
                    return $this->finish_run($run_db_id, [
                        'success' => false,
                        'run_id' => $run_id,
                        'status' => 'failed',
                        'error_code' => 'run_audit_update_failed',
                        'error_message' => 'Retention cleanup could not persist the before-snapshot heartbeat.',
                    ]);
                }
                if (!$this->capture_snapshot(
                    $source,
                    'before_cleanup',
                    $retention_days,
                    $effective_cutoff_for_readout,
                    $effective_eligible_rows,
                    $run_id,
                    '',
                    0,
                    0
                )) {
                    return $this->finish_run($run_db_id, $this->snapshot_before_failure($run_id));
                }
                if (!$this->capture_snapshot(
                    $source,
                    'after_cleanup',
                    $retention_days,
                    $effective_cutoff_for_readout,
                    $effective_eligible_rows,
                    $run_id,
                    '',
                    0,
                    0
                )) {
                    return $this->finish_run($run_db_id, $this->snapshot_after_failure($run_id));
                }

                return $this->finish_run($run_db_id, [
                    'success' => true,
                    'run_id' => $run_id,
                    'status' => 'success',
                    'gate_status' => $gate_status,
                    'gate_results_json' => $gate_results,
                    'cutoff_value' => $effective_cutoff_for_readout,
                    'eligible_rows' => $effective_eligible_rows,
                ]);
            }

            if (!$this->gate_status_allows_cleanup($gate_status) || $effective_cutoff_value === '') {
                $effective_cutoff_value = $cutoff_value;
                $effective_eligible_rows = $eligible_rows;
                if (!$this->update_run_progress($run_db_id, ['worker_phase' => 'snapshot_before_running'])) {
                    return $this->finish_run($run_db_id, [
                        'success' => false,
                        'run_id' => $run_id,
                        'status' => 'failed',
                        'error_code' => 'run_audit_update_failed',
                        'error_message' => 'Retention cleanup could not persist the before-snapshot heartbeat.',
                    ]);
                }
                if (!$this->capture_snapshot(
                    $source,
                    'before_cleanup',
                    $retention_days,
                    $effective_cutoff_value,
                    $effective_eligible_rows,
                    $run_id,
                    '',
                    0,
                    0
                )) {
                    return $this->finish_run($run_db_id, $this->snapshot_before_failure($run_id));
                }
                if (!$this->capture_snapshot(
                    $source,
                    'after_cleanup',
                    $retention_days,
                    $effective_cutoff_value,
                    $effective_eligible_rows,
                    $run_id,
                    '',
                    0,
                    0
                )) {
                    return $this->finish_run($run_db_id, $this->snapshot_after_failure($run_id));
                }

                return $this->finish_run($run_db_id, [
                    'success' => true,
                    'run_id' => $run_id,
                    'status' => 'skipped',
                    'gate_status' => $gate_status,
                    'gate_results_json' => $gate_results,
                    'cutoff_value' => $effective_cutoff_value,
                    'eligible_rows' => $effective_eligible_rows,
                    'error_code' => 'coverage_gate_failed',
                    'error_message' => 'Retention cleanup skipped because summary coverage gate failed.',
                ]);
            }

            if (!$this->update_run_progress($run_db_id, ['worker_phase' => 'snapshot_before_running'])) {
                return $this->finish_run($run_db_id, [
                    'success' => false,
                    'run_id' => $run_id,
                    'status' => 'failed',
                    'error_code' => 'run_audit_update_failed',
                    'error_message' => 'Retention cleanup could not persist the before-snapshot heartbeat.',
                ]);
            }
            if (!$this->capture_snapshot(
                $source,
                'before_cleanup',
                $retention_days,
                $effective_cutoff_value,
                $effective_eligible_rows,
                $run_id,
                '',
                0,
                0
            )) {
                return $this->finish_run($run_db_id, $this->snapshot_before_failure($run_id));
            }

            if (!$this->update_run_progress($run_db_id, ['worker_phase' => 'target_key_freezing'])) {
                return $this->finish_run($run_db_id, [
                    'success' => false,
                    'run_id' => $run_id,
                    'status' => 'failed',
                    'error_code' => 'run_audit_update_failed',
                    'error_message' => 'Retention cleanup could not persist the target-key heartbeat.',
                ]);
            }

            $target_max_primary_key = $this->determine_target_max_primary_key($source, $effective_cutoff_value);

            if ($effective_eligible_rows <= 0) {
                if (!$this->capture_snapshot(
                    $source,
                    'after_cleanup',
                    $retention_days,
                    $effective_cutoff_value,
                    $effective_eligible_rows,
                    $run_id,
                    '',
                    0
                )) {
                    return $this->finish_run($run_db_id, $this->snapshot_after_failure($run_id));
                }

                return $this->finish_successful_run($run_db_id, [
                    'success' => true,
                    'run_id' => $run_id,
                    'status' => 'completed',
                    'gate_status' => $gate_status,
                    'gate_results_json' => $gate_results,
                    'cutoff_value' => $effective_cutoff_value,
                    'eligible_rows' => $effective_eligible_rows,
                    'worker_phase' => 'completed_noop',
                    'target_max_primary_key' => $target_max_primary_key,
                    'archive_batch_id' => $archive_batch_id,
                ], $source_key, $dry_run);
            }

            if ($target_max_primary_key <= 0) {
                if (!$this->capture_snapshot(
                    $source,
                    'after_cleanup',
                    $retention_days,
                    $effective_cutoff_value,
                    $effective_eligible_rows,
                    $run_id,
                    '',
                    0,
                    0
                )) {
                    return $this->finish_run($run_db_id, $this->snapshot_after_failure($run_id));
                }

                return $this->finish_run($run_db_id, [
                    'success' => false,
                    'run_id' => $run_id,
                    'status' => 'failed',
                    'gate_status' => $gate_status,
                    'gate_results_json' => $gate_results,
                    'cutoff_value' => $effective_cutoff_value,
                    'eligible_rows' => $effective_eligible_rows,
                    'worker_phase' => 'target_key_unavailable',
                    'target_max_primary_key' => 0,
                    'archive_batch_id' => $archive_batch_id,
                    'error_code' => 'target_primary_key_unavailable',
                    'error_message' => 'Retention cleanup could not freeze a target primary key for eligible rows.',
                ]);
            }

            $pending_result = [
                'success' => true,
                'run_id' => $run_id,
                'status' => 'pending',
                'gate_status' => $gate_status,
                'gate_results_json' => $gate_results,
                'cutoff_value' => $effective_cutoff_value,
                'eligible_rows' => $effective_eligible_rows,
                'worker_phase' => 'archive_pending',
                'target_max_primary_key' => $target_max_primary_key,
                'archive_last_primary_key' => 0,
                'delete_last_primary_key' => 0,
                'archive_batch_id' => $archive_batch_id,
                'schedule_worker' => true,
                'reschedule_worker' => true,
                'reschedule_delay_seconds' => 0,
            ];

            if (!$this->update_run_progress($run_db_id, $pending_result)) {
                return [
                    'success' => false,
                    'run_id' => $run_id,
                    'status' => 'failed',
                    'error_code' => 'run_audit_update_failed',
                    'error_message' => 'Retention cleanup planned a worker run but the audit row could not be updated.',
                ];
            }

            return $pending_result;
        } catch (Throwable $error) {
            return $this->finish_run($run_db_id, [
                'success' => false,
                'run_id' => $run_id,
                'status' => 'failed',
                'error_code' => 'cleanup_exception',
                'error_message' => $error->getMessage(),
            ]);
        } finally {
            $this->clear_lock($source_key);
        }
    }

    public function run_worker(string $source_key): array
    {
        $source = $this->source_registry->get($source_key);

        if (!is_array($source)) {
            return [
                'success' => false,
                'status' => 'failed',
                'error_code' => 'unknown_source',
                'error_message' => 'Unknown retention source: ' . $source_key,
            ];
        }

        if (!$this->mark_stale_runs($source_key)) {
            return [
                'success' => false,
                'status' => 'failed',
                'error_code' => 'stale_run_detection_failed',
                'error_message' => 'Retention worker could not verify stale unfinished audit runs.',
            ];
        }

        $run = $this->run_repository->find_open_run_for_source($source_key);

        if (!is_array($run)) {
            return [
                'success' => true,
                'status' => 'skipped',
                'worker_phase' => 'no_open_run',
                'error_code' => 'no_open_cleanup_run',
                'error_message' => 'Retention worker skipped because no open cleanup run exists.',
            ];
        }

        $run_db_id = (int) ($run['id'] ?? 0);
        $run_id = (string) ($run['run_id'] ?? '');

        if ($this->has_worker_lock($source_key)) {
            $this->update_run_progress($run_db_id, [
                'worker_phase' => 'lock_skipped',
                'worker_last_finished_at' => $this->current_time_mysql(),
            ]);

            return [
                'success' => true,
                'run_id' => $run_id,
                'status' => (string) ($run['status'] ?? 'partial'),
                'worker_phase' => 'lock_skipped',
                'schedule_worker' => true,
                'reschedule_worker' => true,
                'reschedule_delay_seconds' => $this->config->get_retention_worker_reschedule_delay_seconds(),
                'error_code' => 'worker_lock_active',
                'error_message' => 'Retention worker skipped because lock is active.',
            ];
        }

        $this->set_worker_lock($source_key);

        try {
            $worker_runs = (int) ($run['worker_runs'] ?? 0) + 1;
            if (!$this->update_run_progress($run_db_id, [
                'status' => 'running',
                'worker_phase' => 'archive_running',
                'worker_runs' => $worker_runs,
                'worker_last_started_at' => $this->current_time_mysql(),
            ])) {
                return $this->fail_worker_run($run_db_id, $run, [
                    'error_code' => 'run_audit_update_failed',
                    'error_message' => 'Retention worker could not persist running state before archive/delete work.',
                ]);
            }

            $cutoff_value = (string) ($run['cutoff_value'] ?? '');
            $archive_batch_id = (string) ($run['archive_batch_id'] ?? '');
            $target_max_primary_key = (int) ($run['target_max_primary_key'] ?? 0);
            $existing_archive_db_path = (string) ($run['archive_db_path'] ?? '');

            if (!$this->is_valid_cutoff_value($cutoff_value)
                || $archive_batch_id === ''
                || $target_max_primary_key <= 0
            ) {
                return $this->fail_worker_run($run_db_id, $run, [
                    'error_code' => 'invalid_worker_run_state',
                    'error_message' => 'Retention worker run state is missing cutoff, archive batch, or target primary key.',
                ]);
            }

            $chunk = $this->archive_service->archive_primary_key_chunk(
                $source,
                $cutoff_value,
                $archive_batch_id,
                (int) ($run['archive_last_primary_key'] ?? 0),
                $target_max_primary_key,
                $this->config->get_retention_worker_row_limit(),
                $this->config->get_retention_worker_time_limit_seconds(),
                $existing_archive_db_path
            );

            if (empty($chunk['success'])) {
                return $this->fail_worker_run($run_db_id, $run, [
                    'archive_db_path' => (string) ($chunk['archive_db_path'] ?? ($run['archive_db_path'] ?? '')),
                    'archive_integrity_check' => (string) ($chunk['quick_check'] ?? ''),
                    'error_code' => $this->archive_failure_code($chunk),
                    'error_message' => $this->archive_failure_message($chunk),
                ]);
            }

            $quick_check = (string) ($chunk['quick_check'] ?? '');

            if (strtolower($quick_check) !== 'ok') {
                return $this->fail_worker_run($run_db_id, $run, [
                    'archive_db_path' => (string) ($chunk['archive_db_path'] ?? ($run['archive_db_path'] ?? '')),
                    'archive_integrity_check' => $quick_check,
                    'error_code' => 'sqlite_quick_check_failed',
                    'error_message' => 'SQLite archive quick_check returned: ' . $quick_check,
                ]);
            }

            $archived_primary_keys = $this->normalize_primary_keys((array) ($chunk['archived_primary_keys'] ?? []));
            $archived_rows = (int) ($chunk['archived_rows'] ?? count($archived_primary_keys));

            if ($archived_rows !== count($archived_primary_keys)) {
                return $this->fail_worker_run($run_db_id, $run, [
                    'archive_db_path' => (string) ($chunk['archive_db_path'] ?? ($run['archive_db_path'] ?? '')),
                    'archive_integrity_check' => $quick_check,
                    'error_code' => 'archive_primary_key_count_mismatch',
                    'error_message' => 'Archived row count did not match archived primary-key evidence count.',
                ]);
            }

            $deleted_rows = 0;
            $delete_batches = 0;

            if (!empty($archived_primary_keys)) {
                if (!$this->update_run_progress($run_db_id, ['worker_phase' => 'delete_running'])) {
                    return $this->fail_worker_run($run_db_id, $run, [
                        'error_code' => 'run_audit_update_failed',
                        'error_message' => 'Retention worker could not persist the delete heartbeat.',
                    ]);
                }

                $deleted_rows = $this->delete_source_primary_keys($source, $archived_primary_keys);
                $delete_batches = 1;

                if ($deleted_rows !== count($archived_primary_keys)) {
                    return $this->fail_worker_run($run_db_id, $run, [
                        'archive_db_path' => (string) ($chunk['archive_db_path'] ?? ($run['archive_db_path'] ?? '')),
                        'archive_integrity_check' => $quick_check,
                        'archived_rows' => (int) ($run['archived_rows'] ?? 0) + $archived_rows,
                        'archive_inserted_rows' => (int) ($run['archive_inserted_rows'] ?? 0) + (int) ($chunk['archive_inserted_rows'] ?? 0),
                        'archive_duplicate_rows' => (int) ($run['archive_duplicate_rows'] ?? 0) + (int) ($chunk['archive_duplicate_rows'] ?? 0),
                        'deleted_rows' => (int) ($run['deleted_rows'] ?? 0) + $deleted_rows,
                        'delete_batches' => (int) ($run['delete_batches'] ?? 0) + $delete_batches,
                        'archive_last_primary_key' => max((int) ($run['archive_last_primary_key'] ?? 0), (int) ($chunk['last_primary_key'] ?? 0)),
                        'delete_last_primary_key' => max((int) ($run['delete_last_primary_key'] ?? 0), max($archived_primary_keys)),
                        'error_code' => 'delete_count_mismatch',
                        'error_message' => 'Deleted row count did not match archived primary-key evidence count.',
                    ]);
                }
            }

            $new_archived_rows = (int) ($run['archived_rows'] ?? 0) + $archived_rows;
            $new_archive_inserted_rows = (int) ($run['archive_inserted_rows'] ?? 0) + (int) ($chunk['archive_inserted_rows'] ?? 0);
            $new_archive_duplicate_rows = (int) ($run['archive_duplicate_rows'] ?? 0) + (int) ($chunk['archive_duplicate_rows'] ?? 0);
            $new_deleted_rows = (int) ($run['deleted_rows'] ?? 0) + $deleted_rows;
            $new_delete_batches = (int) ($run['delete_batches'] ?? 0) + $delete_batches;
            $last_primary_key = max((int) ($run['archive_last_primary_key'] ?? 0), (int) ($chunk['last_primary_key'] ?? 0));
            $delete_last_primary_key = empty($archived_primary_keys)
                ? (int) ($run['delete_last_primary_key'] ?? 0)
                : max((int) ($run['delete_last_primary_key'] ?? 0), max($archived_primary_keys));
            $archive_db_path = $existing_archive_db_path !== ''
                ? $existing_archive_db_path
                : (string) ($chunk['archive_db_path'] ?? '');
            $has_more = !empty($chunk['has_more']);

            if ($has_more && empty($archived_primary_keys)) {
                return $this->fail_worker_run($run_db_id, $run, [
                    'archive_db_path' => $archive_db_path,
                    'archive_integrity_check' => $quick_check,
                    'error_code' => 'worker_no_progress',
                    'error_message' => 'Retention worker detected remaining rows but archived no primary-key evidence.',
                ]);
            }

            if ($has_more) {
                $progress = [
                    'status' => 'partial',
                    'worker_phase' => 'archive_partial',
                    'archive_db_path' => $archive_db_path,
                    'archive_integrity_check' => $quick_check,
                    'archived_rows' => $new_archived_rows,
                    'archive_inserted_rows' => $new_archive_inserted_rows,
                    'archive_duplicate_rows' => $new_archive_duplicate_rows,
                    'deleted_rows' => $new_deleted_rows,
                    'delete_batches' => $new_delete_batches,
                    'archive_last_primary_key' => $last_primary_key,
                    'delete_last_primary_key' => $delete_last_primary_key,
                    'worker_last_finished_at' => $this->current_time_mysql(),
                ];

                if (!$this->update_run_progress($run_db_id, $progress)) {
                    return $this->fail_worker_run($run_db_id, $run, [
                        'error_code' => 'run_audit_update_failed',
                        'error_message' => 'Retention worker chunk finished but progress could not be persisted.',
                    ]);
                }

                return array_merge($progress, [
                    'success' => true,
                    'run_id' => $run_id,
                    'schedule_worker' => true,
                    'reschedule_worker' => true,
                    'reschedule_delay_seconds' => $this->config->get_retention_worker_reschedule_delay_seconds(),
                ]);
            }

            $expected_rows = (int) ($run['eligible_rows'] ?? 0);

            if ($expected_rows > 0
                && ($new_archived_rows !== $expected_rows || $new_deleted_rows !== $expected_rows)
            ) {
                return $this->fail_worker_run($run_db_id, $run, [
                    'archive_db_path' => $archive_db_path,
                    'archive_integrity_check' => $quick_check,
                    'archived_rows' => $new_archived_rows,
                    'archive_inserted_rows' => $new_archive_inserted_rows,
                    'archive_duplicate_rows' => $new_archive_duplicate_rows,
                    'deleted_rows' => $new_deleted_rows,
                    'delete_batches' => $new_delete_batches,
                    'archive_last_primary_key' => $last_primary_key,
                    'delete_last_primary_key' => $delete_last_primary_key,
                    'error_code' => 'worker_incomplete_counts',
                    'error_message' => 'Retention worker finished before archived/deleted counts matched the frozen eligible row count.',
                ]);
            }

            $integrity_check = $this->archive_service->run_integrity_check($archive_db_path);

            if (strtolower($integrity_check) !== 'ok') {
                return $this->fail_worker_run($run_db_id, $run, [
                    'archive_db_path' => $archive_db_path,
                    'archive_integrity_check' => $integrity_check,
                    'error_code' => 'sqlite_integrity_check_failed',
                    'error_message' => 'SQLite archive integrity_check returned: ' . $integrity_check,
                ]);
            }

            if (!$this->update_run_progress($run_db_id, ['worker_phase' => 'snapshot_after_running'])) {
                return $this->fail_worker_run($run_db_id, $run, [
                    'error_code' => 'run_audit_update_failed',
                    'error_message' => 'Retention worker could not persist the after-snapshot heartbeat.',
                ]);
            }

            if (!$this->capture_snapshot(
                $source,
                'after_cleanup',
                (int) ($run['retention_days_effective'] ?? 0),
                $cutoff_value,
                (int) ($run['eligible_rows'] ?? 0),
                $run_id,
                $archive_batch_id,
                $new_archived_rows,
                $new_deleted_rows
            )) {
                return $this->finish_successful_run($run_db_id, [
                    'success' => true,
                    'run_id' => $run_id,
                    'status' => 'completed',
                    'worker_phase' => 'completed',
                    'archive_db_path' => $archive_db_path,
                    'archive_integrity_check' => $integrity_check,
                    'archived_rows' => $new_archived_rows,
                    'archive_inserted_rows' => $new_archive_inserted_rows,
                    'archive_duplicate_rows' => $new_archive_duplicate_rows,
                    'deleted_rows' => $new_deleted_rows,
                    'delete_batches' => $new_delete_batches,
                    'archive_last_primary_key' => $last_primary_key,
                    'delete_last_primary_key' => $delete_last_primary_key,
                    'worker_last_finished_at' => $this->current_time_mysql(),
                    'error_code' => 'snapshot_after_failed',
                    'error_message' => 'Retention cleanup completed, but the after-cleanup growth snapshot could not be persisted.',
                ], $source_key, !empty($run['dry_run']));
            }

            if (!$this->update_run_progress($run_db_id, ['worker_phase' => 'finalizing'])) {
                return $this->fail_worker_run($run_db_id, $run, [
                    'error_code' => 'run_audit_update_failed',
                    'error_message' => 'Retention worker could not persist the finalization heartbeat.',
                ]);
            }

            return $this->finish_successful_run($run_db_id, [
                'success' => true,
                'run_id' => $run_id,
                'status' => 'completed',
                'worker_phase' => 'completed',
                'archive_db_path' => $archive_db_path,
                'archive_integrity_check' => $integrity_check,
                'archived_rows' => $new_archived_rows,
                'archive_inserted_rows' => $new_archive_inserted_rows,
                'archive_duplicate_rows' => $new_archive_duplicate_rows,
                'deleted_rows' => $new_deleted_rows,
                'delete_batches' => $new_delete_batches,
                'archive_last_primary_key' => $last_primary_key,
                'delete_last_primary_key' => $delete_last_primary_key,
                'worker_last_finished_at' => $this->current_time_mysql(),
            ], $source_key, !empty($run['dry_run']));
        } catch (Throwable $error) {
            return $this->fail_worker_run($run_db_id, $run, [
                'error_code' => 'worker_exception',
                'error_message' => $error->getMessage(),
            ]);
        } finally {
            $this->clear_worker_lock($source_key);
        }
    }

    private function gate_status_allows_cleanup(string $gate_status): bool
    {
        return in_array($gate_status, ['passed', 'partial'], true);
    }

    private function mark_stale_runs(string $source_key): bool
    {
        try {
            $stale_runs = $this->run_repository->mark_stale_unfinished_runs(
                $source_key,
                self::STALE_RUN_AFTER_MINUTES
            );
            if ($stale_runs === null) {
                return false;
            }

            foreach ($stale_runs as $stale_run) {
                $run_id = (string) ($stale_run['run_id'] ?? '');
                if ($run_id === '') {
                    continue;
                }

                $this->operational_event_service->record_failure([
                    'area' => 'retention',
                    'severity' => 'error',
                    'event_type' => 'retention_cleanup_timeout',
                    'correlation_key' => 'retention_' . $source_key,
                    'idempotency_key' => 'retention_stale_' . $run_id,
                    'reference_type' => 'retention_cleanup_run',
                    'reference_id' => $run_id,
                    'message' => 'Retention cleanup run was marked failed after its heartbeat became stale.',
                    'raw_error_text' => 'cron_timeout_suspected',
                    'context' => [
                        'source_key' => $source_key,
                        'worker_phase' => (string) ($stale_run['worker_phase'] ?? ''),
                        'audit_id' => (int) ($stale_run['id'] ?? 0),
                        'error_code' => 'cron_timeout_suspected',
                    ],
                ]);
            }

            return true;
        } catch (Throwable $error) {
            return false;
        }
    }

    private function finish_successful_run(
        int $run_db_id,
        array $result,
        string $source_key,
        bool $dry_run
    ): array {
        $result = $this->finish_run($run_db_id, $result);

        if ($dry_run
            || empty($result['success'])
            || ($result['audit_persisted'] ?? true) === false
            || !in_array((string) ($result['status'] ?? ''), ['completed', 'completed_noop'], true)
        ) {
            return $result;
        }

        $run_id = (string) ($result['run_id'] ?? '');
        $this->operational_event_service->record_recovery([
            'area' => 'retention',
            'severity' => 'info',
            'event_type' => 'retention_cleanup_timeout',
            'correlation_key' => 'retention_' . $source_key,
            'idempotency_key' => $run_id === '' ? '' : 'retention_recovered_' . $run_id,
            'reference_type' => 'retention_cleanup_run',
            'reference_id' => $run_id,
            'message' => 'Retention cleanup completed successfully after an earlier stale run.',
            'context' => [
                'source_key' => $source_key,
                'worker_phase' => (string) ($result['worker_phase'] ?? ''),
                'audit_id' => $run_db_id,
            ],
        ]);

        return $result;
    }

    private function capture_snapshot(
        array $source,
        string $snapshot_phase,
        int $retention_days,
        string $cutoff_value,
        int $eligible_rows,
        string $run_id,
        string $archive_batch_id = '',
        int $archived_rows = 0,
        int $deleted_rows = 0
    ): bool {
        try {
            return $this->snapshot_repository->capture_snapshot(
                $source,
                $snapshot_phase,
                $retention_days,
                $cutoff_value,
                $eligible_rows,
                $run_id,
                $archive_batch_id,
                $archived_rows,
                $deleted_rows
            ) > 0;
        } catch (Throwable $error) {
            return false;
        }
    }

    private function snapshot_before_failure(string $run_id): array
    {
        return [
            'success' => false,
            'run_id' => $run_id,
            'status' => 'failed',
            'worker_phase' => 'failed',
            'error_code' => 'snapshot_before_failed',
            'error_message' => 'Retention cleanup aborted because the before-cleanup growth snapshot could not be persisted.',
        ];
    }

    private function snapshot_after_failure(string $run_id): array
    {
        return [
            'success' => false,
            'run_id' => $run_id,
            'status' => 'failed',
            'worker_phase' => 'failed',
            'error_code' => 'snapshot_after_failed',
            'error_message' => 'Retention cleanup could not persist the after-cleanup growth snapshot.',
        ];
    }

    private function resolve_effective_cutoff_value(array $gate_results, string $requested_cutoff_value): string
    {
        $gate_status = (string) ($gate_results['status'] ?? 'failed');
        $effective_cutoff_value = (string) ($gate_results['effective_cutoff_value'] ?? '');

        if ($gate_status === 'passed' && $effective_cutoff_value === '') {
            return $requested_cutoff_value;
        }

        if (!$this->gate_status_allows_cleanup($gate_status)) {
            return '';
        }

        if (!$this->is_valid_cutoff_value($effective_cutoff_value)) {
            return '';
        }

        if (strcmp($effective_cutoff_value, $requested_cutoff_value) > 0) {
            return '';
        }

        if ($gate_status === 'partial' && strcmp($effective_cutoff_value, $requested_cutoff_value) >= 0) {
            return '';
        }

        return $effective_cutoff_value;
    }

    private function is_valid_cutoff_value(string $cutoff_value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/', $cutoff_value) === 1;
    }

    protected function count_eligible_rows(array $source, string $cutoff_value): int
    {
        global $wpdb;

        $source_table = (string) ($source['source_table'] ?? '');
        $cutoff_column = (string) ($source['cutoff_column'] ?? '');

        if (!$this->is_identifier($source_table) || !$this->is_identifier($cutoff_column)) {
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$source_table} WHERE {$cutoff_column} < %s",
                $cutoff_value
            )
        );
    }

    protected function determine_target_max_primary_key(array $source, string $cutoff_value): int
    {
        global $wpdb;

        $source_table = (string) ($source['source_table'] ?? '');
        $primary_key = (string) ($source['primary_key'] ?? '');
        $cutoff_column = (string) ($source['cutoff_column'] ?? '');

        if (!$this->is_identifier($source_table)
            || !$this->is_identifier($primary_key)
            || !$this->is_identifier($cutoff_column)
        ) {
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX({$primary_key}) FROM {$source_table} WHERE {$cutoff_column} < %s",
                $cutoff_value
            )
        );
    }

    protected function delete_archived_rows(
        array $source,
        string $archive_db_path,
        string $archive_batch_id,
        int $batch_limit
    ): array
    {
        $batch_limit = max(1, $batch_limit);
        $last_primary_key = 0;
        $deleted_rows = 0;
        $delete_batches = 0;

        while (true) {
            $ids = $this->archive_service->fetch_archived_primary_key_batch(
                $source,
                $archive_db_path,
                $archive_batch_id,
                $last_primary_key,
                $batch_limit
            );
            $ids = $this->normalize_primary_keys($ids);

            if (empty($ids)) {
                break;
            }

            $deleted_rows += $this->delete_source_primary_keys($source, $ids);
            $delete_batches++;
            $last_primary_key = max($ids);

            if (count($ids) < $batch_limit) {
                break;
            }
        }

        return [
            'deleted_rows' => $deleted_rows,
            'delete_batches' => $delete_batches,
        ];
    }

    protected function delete_source_primary_keys(array $source, array $primary_keys): int
    {
        global $wpdb;

        $source_table = (string) ($source['source_table'] ?? '');
        $primary_key = (string) ($source['primary_key'] ?? '');
        $primary_keys = $this->normalize_primary_keys($primary_keys);

        if (!$this->is_identifier($source_table) || !$this->is_identifier($primary_key) || empty($primary_keys)) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($primary_keys), '%d'));
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$source_table} WHERE {$primary_key} IN ({$placeholders})",
                ...$primary_keys
            )
        );

        if ($deleted === false) {
            throw new RuntimeException('Retention delete query failed.');
        }

        return (int) $deleted;
    }

    private function normalize_primary_keys(array $primary_keys): array
    {
        $ids = array_filter(array_map(static function ($value): int {
            return (int) $value;
        }, $primary_keys), static function (int $value): bool {
            return $value > 0;
        });

        return array_values(array_unique($ids));
    }

    private function archive_failure_code(array $archive_result): string
    {
        $error_code = (string) ($archive_result['error_code'] ?? '');

        return $error_code !== '' ? $error_code : 'archive_count_mismatch';
    }

    private function archive_failure_message(array $archive_result): string
    {
        $error_message = (string) ($archive_result['error_message'] ?? '');

        return $error_message !== '' ? $error_message : 'Archived row count did not match eligible row count.';
    }

    protected function build_cutoff_value(int $retention_days): string
    {
        $current_date = substr($this->current_time_mysql(), 0, 10);
        $timestamp = strtotime($current_date . ' -' . max(1, $retention_days) . ' days');

        return ($timestamp === false ? $current_date : gmdate('Y-m-d', $timestamp)) . ' 00:00:00';
    }

    private function finish_run(int $run_db_id, array $result): array
    {
        $update = array_merge($result, [
            'finished_at' => $this->current_time_mysql(),
        ]);
        unset($update['success'], $update['run_id'], $update['schedule_worker'], $update['reschedule_worker'], $update['reschedule_delay_seconds']);

        if ($run_db_id > 0) {
            try {
                $updated = $this->run_repository->update_run($run_db_id, $update);
            } catch (Throwable $error) {
                $updated = false;
            }

            if (!$updated) {
                $audit_failure = $result;
                $audit_failure['success'] = false;
                $audit_failure['status'] = 'failed';
                $audit_failure['audit_persisted'] = false;
                $audit_failure['cleanup_status_before_audit_failure'] = (string) ($result['status'] ?? '');
                $audit_failure['cleanup_error_code'] = (string) ($result['error_code'] ?? '');
                $audit_failure['cleanup_error_message'] = (string) ($result['error_message'] ?? '');
                $audit_failure['error_code'] = 'run_audit_update_failed';
                $audit_failure['error_message'] = 'Retention cleanup finished but the audit run update could not be persisted.';

                return $audit_failure;
            }
        }

        return $result;
    }

    private function update_run_progress(int $run_db_id, array $data): bool
    {
        if ($run_db_id <= 0) {
            return false;
        }

        unset($data['success'], $data['run_id'], $data['schedule_worker'], $data['reschedule_worker'], $data['reschedule_delay_seconds']);

        try {
            return $this->run_repository->update_run($run_db_id, $data);
        } catch (Throwable $error) {
            return false;
        }
    }

    private function fail_worker_run(int $run_db_id, array $run, array $result): array
    {
        $failure = array_merge([
            'success' => false,
            'run_id' => (string) ($run['run_id'] ?? ''),
            'status' => 'failed',
            'worker_phase' => 'failed',
            'worker_last_finished_at' => $this->current_time_mysql(),
        ], $result);

        return $this->finish_run($run_db_id, $failure);
    }

    private function resolve_retention_days(array $settings, array $source): int
    {
        $minimum = max(1, (int) ($source['retention_days_min'] ?? 1));
        $default = max($minimum, (int) ($source['retention_days_default'] ?? $minimum));

        return max($minimum, (int) ($settings['retention_days'] ?? $default));
    }

    private function normalize_triggered_by(string $triggered_by): string
    {
        $triggered_by = strtolower(trim($triggered_by));

        return in_array($triggered_by, ['cron', 'manual', 'wp_cli'], true) ? $triggered_by : 'manual';
    }

    private function generate_run_id(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return 'retention_' . str_replace('-', '', wp_generate_uuid4());
        }

        return 'retention_' . gmdate('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 12);
    }

    private function generate_archive_batch_id(string $source_key): string
    {
        return $source_key . '_' . gmdate('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 8);
    }

    private function has_lock(string $source_key): bool
    {
        return function_exists('get_transient')
            && get_transient(self::LOCK_PREFIX . $source_key) !== false;
    }

    private function set_lock(string $source_key): void
    {
        if (!function_exists('set_transient')) {
            return;
        }

        set_transient(
            self::LOCK_PREFIX . $source_key,
            '1',
            $this->config->get_retention_lock_ttl_seconds()
        );
    }

    private function clear_lock(string $source_key): void
    {
        if (function_exists('delete_transient')) {
            delete_transient(self::LOCK_PREFIX . $source_key);
        }
    }

    private function has_worker_lock(string $source_key): bool
    {
        return function_exists('get_transient')
            && get_transient(self::WORKER_LOCK_PREFIX . $source_key) !== false;
    }

    private function set_worker_lock(string $source_key): void
    {
        if (!function_exists('set_transient')) {
            return;
        }

        set_transient(
            self::WORKER_LOCK_PREFIX . $source_key,
            '1',
            $this->config->get_retention_worker_lock_ttl_seconds()
        );
    }

    private function clear_worker_lock(string $source_key): void
    {
        if (function_exists('delete_transient')) {
            delete_transient(self::WORKER_LOCK_PREFIX . $source_key);
        }
    }

    private function is_identifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1;
    }

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
