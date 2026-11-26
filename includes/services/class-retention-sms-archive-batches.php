<?php

if (!defined('ABSPATH')) { exit; }

/** One bounded archive or delete step, called while the SMS source and worker locks are held. */
final class Kiwi_Retention_Sms_Archive_Batches extends Kiwi_Retention_Worker_Support
{
    public function advance(array $run, int $limit, string $source_lock): array
    {
        $handle = null;
        try {
            $source = Kiwi_Retention_Source_Registry::sms_body_variant_assignments();
            $connection = $this->lock_owner($source_lock);
            $path = $this->archive_service->resolve_existing_archive_db_path_read_only((string) $run['archive_db_path']);
            $lock = $this->archive_lock->acquire_for_archive($path);
            if (empty($lock['acquired'])) {
                return ['status'=>'wait', 'reason'=>$lock['error_code'] ?? 'archive_lock_active'];
            }
            $handle = $lock['handle'];
            $run = $this->confirmed_run($run);
            $state = json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
            foreach ($this->scope($run) as $field=>$value) {
                if (($state['archive_scope'][$field] ?? null) !== $value) { throw new RuntimeException('sms_archive_scope_invalid'); }
            }
            if ($run['source_key'] !== $source['source_key'] || $run['source_table'] !== $source['source_table']
                || $run['cutoff_column'] !== $source['cutoff_column'] || (int) $run['enabled'] !== 1
                || (int) $run['dry_run'] !== 0 || ($state['progress']['phase'] ?? '') !== 'released'
                || !is_array($state['groups'] ?? null)) {
                throw new RuntimeException('sms_archive_scope_invalid');
            }
            if (!empty($state['recheck_groups'])) {
                return $this->recheck(array_values($state['recheck_groups']));
            }
            clearstatcache(true, $path);
            if (!is_file($path) && (!empty($state['archive_target_existed'])
                || (int) $run['archived_rows'] > 0 || (int) $run['deleted_rows'] > 0)) {
                throw new RuntimeException('sms_archive_confirmed_file_missing');
            }
            if (is_file($path) && empty($state['archive_target_existed'])) {
                $state['archive_target_existed'] = true;
                $this->save($run, $state);
            }
            $gate = $this->inspect_archive_write_gate($path);
            if (empty($gate['allowed'])) {
                return ['status'=>'wait', 'reason'=>$gate['reason_code'] ?? 'archive_gate_active'];
            }
            if (!isset($state['pending_archive'])) {
                $limit = min(500, max(1,$limit), $this->config->get_retention_default_batch_limit());
                $start = $this->plan_page($source, $run, $state, $limit);
                if ($start !== null) { return $start; }
            }
            $this->validate_page($run, $state);
            return $state['pending_archive']['stage'] === 'copy'
                ? $this->archive_page($source, $run, $state, $handle, $source_lock, $connection)
                : $this->delete_page($source, $run, $state, $handle, $source_lock, $connection);
        } catch (Throwable $error) {
            $reason = $error->getMessage();
            return ['status'=>'failed', 'reason'=>str_starts_with($reason, 'sms_archive_') ? $reason : 'sms_archive_batch_failed'];
        } finally {
            if ($handle !== null) { $this->archive_lock->release($handle); }
        }
    }

    private function scope(array $run): array
    {
        return ['run_id'=>$run['run_id'], 'cutoff_value'=>$run['cutoff_value'],
            'target_max_primary_key'=>(int) $run['target_max_primary_key'], 'archive_db_path'=>$run['archive_db_path']];
    }

    private function lock_owner(string $source_lock): int
    {
        global $wpdb;
        $owner = $wpdb->get_var($wpdb->prepare(
            'SELECT IF(IS_USED_LOCK(%s)=CONNECTION_ID(),CONNECTION_ID(),0)', $source_lock));
        if ((int) $owner <= 0 || (string) ($wpdb->last_error ?? '') !== '') {
            throw new RuntimeException('sms_archive_source_lock_lost');
        }
        return (int) $owner;
    }

    private function confirmed_run(array $expected, bool $for_update = false): array
    {
        global $wpdb;
        $id = (int) $expected['id'];
        $row = $for_update ? $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->run_repository->get_table_name() . ' WHERE id=%d FOR UPDATE', $id), ARRAY_A)
            : $this->run_repository->find_run_by_id($id);
        if (!is_array($row) || (string) ($wpdb->last_error ?? '') !== '' || $row['finished_at'] !== null
            || !in_array($row['status'], ['running','pending','partial'], true) || $row['worker_phase'] !== 'sms_archive'
            || (int) $row['enabled'] !== 1 || (int) $row['dry_run'] !== 0
            || $row['source_key'] !== $expected['source_key'] || $row['source_table'] !== $expected['source_table']
            || $row['cutoff_column'] !== $expected['cutoff_column']
            || $this->scope($row) !== $this->scope($expected)
            || $this->run_repository->checkpoint_for_stored_run($row) !== $this->run_repository->checkpoint_for_stored_run($expected)) {
            throw new RuntimeException('sms_archive_audit_readback_failed');
        }
        return $row;
    }

    /** Persist and confirm the exact state this invocation wrote, including after a lost acknowledgement. */
    private function save(array &$run, array $state, array $changes = [], ?int $connection = null): void
    {
        $changes['gate_results_json'] = $state;
        $expected = $this->run_repository->checkpoint_for_stored_run($run);
        if ($connection === null) {
            $this->run_repository->update_run_checkpoint((int) $run['id'], $changes, $expected);
        } else {
            $this->run_repository->update_run_transaction_checkpoint((int) $run['id'], $changes, $expected, $connection);
        }
        $next = array_replace($run, $changes);
        $next['gate_results_json'] = wp_json_encode($state);
        $run = $this->confirmed_run($next);
    }

    private function plan_page(array $source, array &$run, array &$state, int $limit): ?array
    {
        global $wpdb;
        $cursor = (int) ($state['archive_scan_cursor'] ?? 0);
        $rows = $this->rows($wpdb->prepare('SELECT id,' . implode(',', Kiwi_Sms_Body_Variant_Repository::SUMMARY_GROUP_COLUMNS)
            . " FROM {$source['source_table']} WHERE id>%d AND id<=%d AND created_at<%s ORDER BY id LIMIT %d",
            $cursor, $run['target_max_primary_key'], $run['cutoff_value'], $limit));
        if (!$rows) { return ['status'=>'done']; }
        $members = [];
        foreach ($rows as $row) {
            $group = Kiwi_Retention_Sms_Statistics::group_key($row);
            $token = Kiwi_Retention_Sms_Statistics::group_id($group);
            if (!isset($state['groups'][$token])) { return $this->recheck([$group]); }
            if ($this->released($state, $token)) { $members[(int) $row['id']] = $token; }
        }
        $through = (int) end($rows)['id'];
        if (!$members) {
            $state['archive_scan_cursor'] = $through;
            $this->save($run,$state);
            return ['status'=>'more'];
        }
        $batch = $this->generate_archive_batch_id($source['source_key']);
        $state['pending_archive'] = ['stage'=>'copy', 'through'=>$through, 'members'=>$members, 'counted'=>[],
            'hashes'=>[], 'batch_id'=>$batch];
        $this->save($run,$state,['archive_batch_id'=>$batch]);
        return null;
    }

    private function rows(string $sql): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new RuntimeException('sms_archive_original_read_failed');
        }
        return $rows;
    }

    private function validate_page(array $run, array $state): void
    {
        $page = $state['pending_archive'];
        $cursor = (int) ($state['archive_scan_cursor'] ?? 0);
        $through = $page['through'] ?? null;
        if (!in_array($page['stage'] ?? null, ['copy','delete'], true)
            || !is_int($through) || $through <= $cursor || $through > (int) $run['target_max_primary_key']
            || empty($page['batch_id']) || $page['batch_id'] !== $run['archive_batch_id']
            || !is_array($page['members'] ?? null) || !$page['members'] || count($page['members']) > 500
            || !is_array($page['hashes'] ?? null) || !is_array($page['counted'] ?? null)) {
            throw new RuntimeException('sms_archive_pending_invalid');
        }
        foreach ($page['members'] as $id=>$group) {
            if (!is_int($id) || $id <= $cursor || $id > $through || !is_string($group)) {
                throw new RuntimeException('sms_archive_pending_id_invalid');
            }
            if ($page['stage'] === 'delete' && ($id > (int) $run['archive_last_primary_key']
                || !in_array($id, $page['counted'], true) || empty($page['hashes'][$id]))) {
                throw new RuntimeException('sms_archive_unconfirmed_progress');
            }
            $cursor = $id;
        }
    }

    private function originals(array $source, array $ids, bool $lock = false): array
    {
        global $wpdb;
        $marks = implode(',', array_fill(0,count($ids),'%d'));
        return array_column($this->rows($wpdb->prepare("SELECT * FROM {$source['source_table']}
            WHERE id IN ({$marks}) ORDER BY id" . ($lock ? ' FOR UPDATE' : ''), ...$ids)), null, 'id');
    }

    private function released(array $state, string $group): bool
    {
        $finding = $state['groups'][$group] ?? null;
        if (!is_array($finding) || !isset($finding['key']) || Kiwi_Retention_Sms_Statistics::group_id($finding['key']) !== $group
            || !in_array($finding['after']['status'] ?? null, ['passed','outside_tolerance','incomplete'], true)) {
            throw new RuntimeException('sms_archive_group_finding_invalid');
        }
        if ($finding['after']['status'] !== 'passed') { return false; }
        if (($finding['after']['reasons'] ?? null) !== []) { throw new RuntimeException('sms_archive_group_finding_invalid'); }
        foreach (array_keys(Kiwi_Retention_Sms_Statistics::MARKERS) as $counter) {
            if (($finding['after']['counters'][$counter]['status'] ?? null) !== 'passed') {
                throw new RuntimeException('sms_archive_group_finding_invalid');
            }
        }
        return true;
    }

    private function recheck(array $groups): array
    {
        return ['status'=>'recheck', 'group'=>$groups[0], 'groups'=>$groups, 'reason'=>'sms_archive_group_changed'];
    }

    private function checked_copies(array $source, array $run, array $ids, Kiwi_Retention_Archive_Lock_Handle $handle): array
    {
        $path = $run['archive_db_path'];
        if (!is_file($path) && (int) $run['archived_rows'] > 0) {
            throw new RuntimeException('sms_archive_confirmed_file_missing');
        }
        if (!is_file($path)) { return []; }
        $reader = new Kiwi_Retention_Archive_Reader($this->archive_service,$this->archive_lock,$this->corruption_safety_gate);
        try {
            $pdo = $reader->open($path,$handle);
            $rows = $reader->receipted_rows($source,$path,$ids);
            $statement = $pdo->prepare('SELECT cutoff_column,cutoff_value FROM archive_batches WHERE archive_batch_id=?');
            $statement->execute([$run['archive_batch_id']]);
            $binding = $statement->fetch(PDO::FETCH_ASSOC);
            if ($binding && ($binding['cutoff_column'] !== $run['cutoff_column'] || $binding['cutoff_value'] !== $run['cutoff_value'])) {
                foreach ($rows as &$row) {
                    if ($row['row']['_archive_batch_id'] === $run['archive_batch_id']) { $row['verified'] = false; }
                }
                unset($row);
            }
            return $rows;
        } finally { $reader->close(); }
    }

    private function same_copy(array $source, array $live, ?array $copy, string $batch): bool
    {
        return !empty($copy['verified']) && $copy['row']['_archive_batch_id'] === $batch
            && Kiwi_Retention_Archive_Reader::snapshot($source,$live) === Kiwi_Retention_Archive_Reader::snapshot($source,$copy['row']);
    }

    private function content_hash(array $source, array $row): string
    {
        return hash('sha256', json_encode(Kiwi_Retention_Archive_Reader::snapshot($source, $row), JSON_THROW_ON_ERROR));
    }

    private function archive_page(array $source, array $run, array $state, Kiwi_Retention_Archive_Lock_Handle $handle,
        string $source_lock, int $connection): array
    {
        $pending = &$state['pending_archive'];
        $batch = $pending['batch_id'];
        $path = $run['archive_db_path'];
        $live = $this->originals($source,array_keys($pending['members']));
        $eligible = [];
        $changed = [];
        foreach ($pending['members'] as $id=>$old_group) {
            if (!isset($live[$id]) || strcmp($live[$id]['created_at'],$run['cutoff_value']) >= 0) { continue; }
            $key = Kiwi_Retention_Sms_Statistics::group_key($live[$id]);
            $group = Kiwi_Retention_Sms_Statistics::group_id($key);
            if ($group !== $old_group) {
                $changed[$old_group] = $state['groups'][$old_group]['key'];
                $changed[$group] = $key;
                $pending['members'][$id] = $group;
            } elseif ($this->released($state,$group)) {
                $eligible[$id] = $live[$id];
            }
        }
        if ($changed) {
            $state['recheck_groups'] = $changed;
            $this->save($run,$state);
            return $this->recheck(array_values($changed));
        }
        if (!$eligible) {
            $this->finish_page($run,$state);
            return ['status'=>'more'];
        }
        $ids = array_keys($eligible);
        $pending['members'] = array_intersect_key($pending['members'],$eligible);
        $copies = $this->checked_copies($source,$run,$ids,$handle);
        $replace = [];
        foreach ($eligible as $id=>$row) {
            if (!$this->same_copy($source,$row,$copies[$id] ?? null,$batch)
                || (isset($pending['hashes'][$id]) && $pending['hashes'][$id] !== $this->content_hash($source,$row))) {
                if (($copies[$id]['row']['_archive_batch_id'] ?? null) === $batch) {
                    return $this->recopy($run,$state);
                }
                $replace[] = $row;
            }
        }
        if ($replace) {
            if ($this->lock_owner($source_lock) !== $connection) { throw new RuntimeException('sms_archive_source_lock_lost'); }
            $gate = $this->inspect_archive_write_gate($path);
            if (empty($gate['allowed'])) { return ['status'=>'wait','reason'=>$gate['reason_code'] ?? 'archive_gate_active']; }
            $this->archive_service->archive_snapshot_batch($source,$replace,$run['cutoff_value'],$batch,$path);
        }
        $copies = $this->checked_copies($source,$run,$ids,$handle);
        $current = $this->originals($source,$ids);
        foreach ($ids as $id) {
            if (!isset($current[$id]) || !$this->same_copy($source,$current[$id],$copies[$id] ?? null,$batch)) {
                return $this->recopy($run,$state);
            }
            $pending['hashes'][$id] = $this->content_hash($source,$current[$id]);
        }
        $new_ids = array_values(array_diff($ids,$pending['counted']));
        $changes = ['archive_last_primary_key'=>max((int)$run['archive_last_primary_key'],max($ids))];
        if ($new_ids) {
            $receipt = $this->archive_service->verify_batch_receipt($source,$path,$batch,$new_ids);
            if (empty($receipt['success'])) { throw new RuntimeException('sms_archive_receipt_invalid'); }
            $changes['archived_rows'] = (int)$run['archived_rows'] + count($new_ids);
            $changes['archive_inserted_rows'] = (int)$run['archive_inserted_rows'] + $receipt['archive_inserted_count'];
            $changes['archive_duplicate_rows'] = (int)$run['archive_duplicate_rows'] + $receipt['archive_duplicate_count'];
            $pending['counted'] = array_merge($pending['counted'],$new_ids);
        }
        $pending['stage'] = 'delete';
        $this->save($run,$state,$changes);
        return ['status'=>'more'];
    }

    private function finish_page(array &$run, array $state, array $changes = [], ?int $connection = null): void
    {
        $state['archive_scan_cursor'] = $state['pending_archive']['through'];
        unset($state['pending_archive']);
        $this->save($run,$state,$changes,$connection);
    }

    /** A replacement version gets a fresh receipt identity, including after a lost archive acknowledgement. */
    private function recopy(array $run, array $state): array
    {
        $batch = $this->generate_archive_batch_id($run['source_key']);
        $state['pending_archive']['stage'] = 'copy';
        $state['pending_archive']['batch_id'] = $batch;
        $state['pending_archive']['hashes'] = [];
        $this->save($run,$state,['archive_batch_id'=>$batch]);
        return ['status'=>'more', 'reason'=>'sms_archive_recopy_required'];
    }

    private function delete_page(array $source, array $run, array $state, Kiwi_Retention_Archive_Lock_Handle $handle,
        string $source_lock, int $connection): array
    {
        global $wpdb;
        $pending = $state['pending_archive'];
        $ids = array_keys($pending['members']);
        $path = $run['archive_db_path'];
        $copies = $this->checked_copies($source,$run,$ids,$handle);
        if ((string)$wpdb->get_var('SELECT @@session.in_transaction') !== '0' || $wpdb->query('START TRANSACTION') === false) {
            throw new RuntimeException('sms_archive_transaction_unavailable');
        }
        try {
            $this->confirmed_run($run,true);
            $live = $this->originals($source,$ids,true);
            $tables = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s) AND ENGINE='InnoDB'",
                $source['source_table'], $this->run_repository->get_table_name()));
            if ((int) $tables !== 2) { throw new RuntimeException('sms_archive_nontransactional_tables'); }
            foreach ($ids as $id) {
                if (!isset($live[$id]) || strcmp($live[$id]['created_at'],$run['cutoff_value']) >= 0
                    || Kiwi_Retention_Sms_Statistics::group_id($live[$id]) !== $pending['members'][$id]
                    || !$this->released($state,$pending['members'][$id])
                    || !$this->same_copy($source,$live[$id],$copies[$id] ?? null,$pending['batch_id'])
                    || $this->content_hash($source,$live[$id]) !== $pending['hashes'][$id]) {
                    $wpdb->query('ROLLBACK');
                    return $this->recopy($run,$state);
                }
            }
            $gate = $this->inspect_archive_write_gate($path);
            if (empty($gate['allowed'])) {
                $wpdb->query('ROLLBACK');
                return ['status'=>'wait','reason'=>$gate['reason_code'] ?? 'archive_gate_active'];
            }
            $marks = implode(',',array_fill(0,count($ids),'%d'));
            $arguments = array_merge($ids,[$run['target_max_primary_key'],$run['cutoff_value'],$connection,$source_lock]);
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$source['source_table']}
                WHERE id IN ({$marks}) AND id<=%d AND created_at<%s AND @@session.in_transaction=1
                    AND CONNECTION_ID()=%d AND IS_USED_LOCK(%s)=CONNECTION_ID()", ...$arguments));
            if ($deleted !== count($ids)) { throw new RuntimeException('sms_archive_delete_count_mismatch'); }
            $by_group = [];
            foreach ($pending['members'] as $id=>$group) { $by_group[$group][] = $id; }
            foreach ($by_group as $group=>$members) {
                $state['deleted_batches'][] = ['batch_id'=>$pending['batch_id'],'group'=>$state['groups'][$group]['key'],'ids'=>$members];
            }
            $this->finish_page($run,$state,['deleted_rows'=>(int)$run['deleted_rows']+$deleted,
                'delete_last_primary_key'=>max((int)$run['delete_last_primary_key'],max($ids)),
                'delete_batches'=>(int)$run['delete_batches']+1], $connection);
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('sms_archive_delete_commit_unknown'); }
            $this->confirmed_run($run);
            return ['status'=>'more'];
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }
}
