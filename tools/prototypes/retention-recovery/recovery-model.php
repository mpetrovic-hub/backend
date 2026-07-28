<?php

declare(strict_types=1);

/**
 * PROTOTYPE ONLY — pure in-memory recovery model, not production code.
 *
 * Question: can an interrupted retention run be completed safely when an
 * SQLite archive has committed evidence but the MySQL audit has not advanced?
 */
final class Kiwi_Retention_Recovery_Prototype_Model
{
    public static function initial_state(): array
    {
        return [
            'run_id' => 'retention_3c4c12cf1b9244868d509ecbc2ffc5e4',
            'run_status' => 'running',
            'worker_phase' => 'archive_running',
            'preflight' => 'not_run',
            'archive_health' => 'not_requested',
            'operational_event' => 'none',
            'source_rows_remaining' => 66418,
            'archived_rows_audited' => 0,
            'deleted_rows_audited' => 0,
            'archive_last_primary_key' => 0,
            'delete_last_primary_key' => 0,
            'archive_db_path' => '(not yet recorded in audit)',
            'evidence' => [
                'archive_batch_status' => 'running',
                'expected_rows' => 50000,
                'source_rows_matching_evidence' => 50000,
                'first_primary_key' => 808247,
                'last_primary_key' => 858246,
            ],
            'history' => [
                'Initial state: interrupted after SQLite commit; no MySQL source rows were deleted.',
            ],
        ];
    }

    public static function dispatch(array $state, string $action): array
    {
        switch ($action) {
            case 'preflight':
                return self::preflight($state);

            case 'reconcile':
                return self::reconcile($state);

            case 'delete_evidence':
                return self::delete_evidence($state);

            case 'archive_remaining':
                return self::archive_remaining($state);

            case 'finish':
                return self::finish($state);

            case 'health_passed':
                $state['archive_health'] = 'passed outside WP-Cron';
                return self::remember($state, 'External archive health check passed; it did not control a source delete.');

            case 'health_failed':
                $state['archive_health'] = 'failed outside WP-Cron';
                $state['operational_event'] = 'open: archive health failure';
                return self::remember($state, 'External archive health check failed; cleanup state is retained and an incident is open.');

            case 'simulate_mismatch':
                $state['evidence']['source_rows_matching_evidence'] = 49999;
                $state['preflight'] = 'not_run';
                return self::remember($state, 'Simulation: one of the 50,000 archive evidence rows has no matching source row.');

            default:
                return self::remember($state, 'Unknown action ignored.');
        }
    }

    private static function preflight(array $state): array
    {
        $evidence = $state['evidence'];
        $passed = $state['run_status'] === 'running'
            && $state['worker_phase'] === 'archive_running'
            && $evidence['expected_rows'] === 50000
            && $evidence['source_rows_matching_evidence'] === $evidence['expected_rows'];

        if (!$passed) {
            $state['preflight'] = 'blocked';
            return self::remember($state, 'Preflight blocked: archive evidence, source rows, or the interrupted run state do not match exactly.');
        }

        $state['preflight'] = 'passed';
        return self::remember($state, 'Preflight passed: the 50,000 archived primary keys are a complete, matching deletion receipt.');
    }

    private static function reconcile(array $state): array
    {
        if ($state['preflight'] !== 'passed') {
            return self::remember($state, 'Refused: run preflight first.');
        }

        $evidence = $state['evidence'];
        $state['run_status'] = 'partial';
        $state['worker_phase'] = 'recovery_delete_evidence';
        $state['archive_db_path'] = 'kiwi_retention_archive_2026.sqlite';
        $state['archived_rows_audited'] = $evidence['expected_rows'];
        $state['archive_last_primary_key'] = $evidence['last_primary_key'];
        $state['evidence']['archive_batch_status'] = 'reconciled from evidence';

        return self::remember($state, 'Audit reconciled with the existing SQLite evidence. No source row has been deleted yet.');
    }

    private static function delete_evidence(array $state): array
    {
        if ($state['worker_phase'] !== 'recovery_delete_evidence') {
            return self::remember($state, 'Refused: only a reconciled run may delete the already archived evidence batch.');
        }

        $rows = $state['evidence']['expected_rows'];
        $state['source_rows_remaining'] -= $rows;
        $state['deleted_rows_audited'] += $rows;
        $state['delete_last_primary_key'] = $state['evidence']['last_primary_key'];
        $state['worker_phase'] = 'recovery_archive_remaining';

        return self::remember($state, 'Deleted exactly the 50,000 source rows named by the SQLite evidence. 16,418 rows remain.');
    }

    private static function archive_remaining(array $state): array
    {
        if ($state['worker_phase'] !== 'recovery_archive_remaining') {
            return self::remember($state, 'Refused: first reconcile and delete the existing evidence batch.');
        }

        $remaining = $state['source_rows_remaining'];
        if ($remaining <= 0) {
            return self::remember($state, 'Nothing remains to archive.');
        }

        $state['archived_rows_audited'] += $remaining;
        $state['worker_phase'] = 'recovery_delete_remaining';

        return self::remember($state, 'Archived the remaining ' . $remaining . ' rows with a new, persisted primary-key receipt.');
    }

    private static function finish(array $state): array
    {
        if ($state['worker_phase'] !== 'recovery_delete_remaining') {
            return self::remember($state, 'Refused: archive the remaining rows before finishing.');
        }

        $remaining = $state['source_rows_remaining'];
        if ($state['archived_rows_audited'] !== $state['deleted_rows_audited'] + $remaining) {
            return self::remember($state, 'Refused: archive and delete counts do not form a complete chain of evidence.');
        }

        $state['source_rows_remaining'] = 0;
        $state['deleted_rows_audited'] += $remaining;
        $state['run_status'] = 'completed';
        $state['worker_phase'] = 'completed';

        return self::remember($state, 'Recovery completed: archive evidence, deleted rows, and remaining source rows now agree.');
    }

    private static function remember(array $state, string $message): array
    {
        $state['history'][] = $message;
        $state['history'] = array_slice($state['history'], -6);

        return $state;
    }
}
