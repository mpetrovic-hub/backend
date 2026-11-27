<?php

if (!defined('ABSPATH')) { exit; }

/** The file evidence on which a saved SMS group release depends. */
abstract class Kiwi_Retention_Sms_Worker_Support extends Kiwi_Retention_Worker_Support
{
    protected function archive_scope(array $run): array
    {
        return [
            'run_id' => (string) $run['run_id'],
            'cutoff_value' => (string) $run['cutoff_value'],
            'target_max_primary_key' => (int) $run['target_max_primary_key'],
            'archive_db_path' => (string) $run['archive_db_path'],
        ];
    }

    protected function required_archive_paths(array $run, array $state): array
    {
        $paths = $state['required_archive_paths'] ?? [];
        if (!is_array($paths)) { throw new RuntimeException('sms_archive_inventory_invalid'); }
        if (!empty($state['archive_target_existed']) || (int) $run['archived_rows'] > 0 || (int) $run['deleted_rows'] > 0) {
            $paths[] = $run['archive_db_path'];
        }
        return array_values(array_unique($paths));
    }

    protected function historical_block(array $paths): ?string
    {
        foreach ($paths as $path) {
            $this->archive_service->resolve_existing_archive_db_path_read_only($path);
            clearstatcache(true, $path);
            if (!is_file($path) || is_link($path)) { return 'sms_archive_confirmed_file_missing'; }
            if (!is_readable($path)) { return 'sms_archive_confirmed_file_unreadable'; }
            $gate = $this->inspect_archive_write_gate($path);
            if (empty($gate['allowed'])) { return $gate['reason_code'] ?? 'archive_gate_active'; }
        }
        return null;
    }

    protected function archive_basis_changed(array $run, array $state, array $handles): bool
    {
        $current = array_column($this->archive_service->list_archive_files(), 'path');
        $required = $this->required_archive_paths($run, $state);
        if (array_diff($required, $current)) { throw new Kiwi_Retention_Sms_History_Blocked('sms_archive_history_missing'); }
        $basis = $this->current_archive_basis($current, $handles);
        return $basis === null || ($state['archive_basis'] ?? null) !== $basis;
    }

    protected function current_archive_basis(array $current, array $handles): ?array
    {
        $basis = [];
        foreach ($current as $path) {
            if (!isset($handles[$path])) { return null; }
            $basis[$path] = Kiwi_Retention_Archive_Reader::file_basis($path, $handles[$path]);
        }
        return $basis;
    }

    /** Acquire in discovery order without waiting. The caller releases partial acquisitions in finally. */
    protected function lock_archives(array $paths, array &$handles): ?string
    {
        $files = [];
        foreach (array_unique($paths) as $path) {
            $this->archive_service->resolve_existing_archive_db_path_read_only($path);
            $name = Kiwi_Retention_Archive_Name::parse(basename($path));
            if ($name === null) { throw new RuntimeException('sms_archive_inventory_invalid'); }
            $files[$path] = $name;
        }
        uasort($files, [Kiwi_Retention_Archive_Name::class, 'compare_generations']);
        foreach (array_keys($files) as $path) {
            $lock = $this->archive_lock->acquire_for_archive($path);
            if (empty($lock['acquired'])) { return $lock['error_code'] ?? 'archive_lock_active'; }
            $handles[$path] = $lock['handle'];
        }
        return null;
    }

    protected function release_archives(array $handles): void
    {
        foreach (array_reverse($handles) as $handle) { $this->archive_lock->release($handle); }
    }
}

final class Kiwi_Retention_Sms_History_Blocked extends RuntimeException {}
final class Kiwi_Retention_Sms_Basis_Changed extends RuntimeException {}
