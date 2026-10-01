<?php

if (!defined('ABSPATH')) { exit; }

/** Verified archive/live reader. Locks remain held until the caller ends its transaction. */
final class Kiwi_Retention_Recovery_Handoff_Reader
{
    private $config;
    private $archives = [];
    private $locks = [];
    private $lock_service;
    private $supervisor;

    public function __construct(Kiwi_Config $config, ?Kiwi_Retention_Archive_Check_Supervisor $supervisor = null)
    {
        $this->config = $config;
        $this->lock_service = new Kiwi_Retention_Archive_Lock();
        $this->supervisor = $supervisor ?: new Kiwi_Retention_Archive_Check_Supervisor($config);
    }

    public function acquire(): void
    {
        $service = new Kiwi_Retention_Sqlite_Archive_Service($this->config);
        $this->archives = $service->list_archive_files();
        foreach ($this->archives as &$archive) {
            $path = $archive['path'];
            $before = hash_file('sha256', $path);
            $health = $this->supervisor->run($path, 'integrity', false);
            if (($health['result'] ?? '') !== 'ok') { throw new RuntimeException('archive_health_not_ok'); }
            $lock = $this->lock_service->acquire_for_archive($path);
            if (empty($lock['success']) || empty($lock['acquired'])) {
                throw new RuntimeException('archive_lock_active');
            }
            $this->locks[] = $lock['handle'];
            $safety = (new Kiwi_Retention_Corruption_Safety_Gate_Coordinator())->inspect($path, false);
            if (empty($safety['allowed'])) { throw new RuntimeException($safety['reason_code']); }
            $archive['sha256'] = hash_file('sha256', $path);
            if (!$before || $before !== $archive['sha256']) { throw new RuntimeException('archive_changed_during_health'); }
        }
    }

    public function snapshot(string $from, string $to, string $table): array
    {
        global $wpdb;
        $table = Kiwi_Retention_Recovery_SQL::identifier($table);
        $source = (new Kiwi_Retention_Source_Registry())->get('landing_handoff_events');
        $live_table = Kiwi_Retention_Recovery_SQL::identifier($source['source_table']);
        $audit_table = Kiwi_Retention_Recovery_SQL::identifier((new Kiwi_Retention_Cleanup_Run_Repository())->get_table_name());
        $audits = Kiwi_Retention_Recovery_SQL::rows("SELECT * FROM $audit_table WHERE source_key = 'landing_handoff_events' ORDER BY id");
        $expected = [];
        $paths = array_column($this->archives, 'path');
        foreach ($audits as $audit) {
            if (in_array($audit['status'], ['pending', 'partial', 'blocked'], true)) {
                throw new RuntimeException('handoff_run_incomplete');
            }
            if ((int) $audit['archived_rows'] === 0 && (int) $audit['deleted_rows'] === 0) { continue; }
            $path = realpath((string) $audit['archive_db_path']);
            if ($audit['status'] !== 'completed' || !$audit['finished_at']
                || $audit['source_table'] !== $live_table || $audit['cutoff_column'] !== 'created_at'
                || (int) $audit['archived_rows'] !== (int) $audit['deleted_rows']
                || (int) $audit['archived_rows'] !== (int) $audit['eligible_rows']
                || !in_array($path, $paths, true) || !$audit['archive_batch_id']
                || isset($expected[$audit['archive_batch_id']])) {
                throw new RuntimeException('handoff_audit_incomplete');
            }
            $expected[$audit['archive_batch_id']] = $audit;
        }

        $merged = [];
        $seen = [];
        $evidence = [];
        $archived_count = 0;
        $duplicates = 0;
        foreach ($this->archives as $archive) {
            $pdo = Kiwi_Retention_Recovery_SQL::open_archive($archive['path']);
            $batches = $pdo->prepare('SELECT * FROM archive_batches WHERE source_key = ? ORDER BY archive_batch_id');
            $batches->execute(['landing_handoff_events']);
            foreach ($batches->fetchAll(PDO::FETCH_ASSOC) as $batch) {
                $id = $batch['archive_batch_id'];
                $audit = $expected[$id] ?? null;
                if (!$audit || isset($seen[$id]) || realpath($audit['archive_db_path']) !== realpath($archive['path'])
                    || $batch['source_table'] !== $live_table || $batch['cutoff_column'] !== 'created_at'
                    || $batch['cutoff_value'] !== $audit['cutoff_value'] || $batch['status'] !== 'success'
                    || (int) $batch['archived_rows'] !== (int) $audit['archived_rows']) {
                    throw new RuntimeException('archive_batch_audit_mismatch');
                }
                $receipt = $pdo->prepare("SELECT r.source_pk, a.id, a.created_at
                    FROM archive_batch_rows r LEFT JOIN \"$live_table\" a ON a._source_pk = r.source_pk
                    WHERE r.archive_batch_id = ? ORDER BY r.source_pk");
                $receipt->execute([$id]);
                $ids = [];
                while ($row = $receipt->fetch(PDO::FETCH_ASSOC)) {
                    if (!$row['id'] || (int) $row['source_pk'] !== (int) $row['id']
                        || $row['created_at'] >= $audit['cutoff_value']
                        || (int) $row['id'] > (int) $audit['target_max_primary_key']) {
                        throw new RuntimeException('archive_rows_incomplete');
                    }
                    $ids[] = (int) $row['source_pk'];
                }
                if (count($ids) !== (int) $audit['archived_rows'] || !$ids
                    || max($ids) !== (int) $audit['archive_last_primary_key']
                    || max($ids) !== (int) $audit['delete_last_primary_key']) {
                    throw new RuntimeException('archive_receipt_incomplete');
                }
                $seen[$id] = true;
                $evidence[] = ['run_id' => $audit['run_id'], 'archive' => basename($archive['path']),
                    'batch_id' => $id, 'cutoff' => $audit['cutoff_value'], 'rows' => count($ids),
                    'ids_sha256' => Kiwi_Retention_Recovery_SQL::digest($ids)];
            }
            // Every row used for reconstruction must belong to a verified receipt.
            $has_table = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
            $has_table->execute([$live_table]);
            if ($has_table->fetchColumn() !== false) {
                $rows = $pdo->prepare("SELECT a.* FROM \"$live_table\" a WHERE a.created_at >= ? AND a.created_at < ? ORDER BY a._source_pk");
                $rows->execute([$from, $to]);
                while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                    $owner = $row['_archive_batch_id'];
                    if (!isset($seen[$owner]) || (int) $row['_source_pk'] !== (int) $row['id']) {
                        throw new RuntimeException('archive_row_without_verified_batch');
                    }
                    $proof = $pdo->prepare('SELECT 1 FROM archive_batch_rows WHERE archive_batch_id = ? AND source_pk = ?');
                    $proof->execute([$owner, $row['_source_pk']]);
                    if ($proof->fetchColumn() === false) { throw new RuntimeException('archive_row_without_receipt'); }
                    $this->merge($merged, $this->normalize($row, $source), $duplicates);
                    $archived_count++;
                }
            }
            $pdo = null;
        }
        if (array_diff(array_keys($expected), array_keys($seen))) { throw new RuntimeException('archive_batch_missing'); }
        $live = Kiwi_Retention_Recovery_SQL::rows($wpdb->prepare(
            "SELECT * FROM $live_table WHERE created_at >= %s AND created_at < %s ORDER BY id", $from, $to
        ));
        foreach ($live as $row) { $this->merge($merged, $this->normalize($row, $source), $duplicates); }
        ksort($merged, SORT_NUMERIC);
        $logical = [];
        foreach ($merged as $row) {
            $key = Kiwi_Retention_Recovery_SQL::digest([$row['landing_key'], $row['session_token'], $row['handoff_id'], $row['event_type']]);
            if (isset($logical[$key])) { throw new RuntimeException('handoff_identity_conflict'); }
            $logical[$key] = true;
            if ($wpdb->insert($table, $row) === false) { throw new RuntimeException('handoff_snapshot_insert_failed'); }
        }
        $this->assert_unchanged();
        return ['from_inclusive' => $from, 'to_exclusive' => $to,
            'archives' => array_map(static function ($archive) {
                return ['name' => $archive['name'], 'sha256' => $archive['sha256']];
            }, $this->archives), 'batches' => $evidence, 'archive_rows' => $archived_count,
            'live_rows' => count($live), 'identical_duplicates' => $duplicates,
            'snapshot_rows' => count($merged), 'snapshot_sha256' => Kiwi_Retention_Recovery_SQL::digest(array_values($merged))];
    }

    public function assert_unchanged(): void
    {
        $current = (new Kiwi_Retention_Sqlite_Archive_Service($this->config))->list_archive_files();
        if (array_column($current, 'path') !== array_column($this->archives, 'path')) {
            throw new RuntimeException('archive_generations_changed');
        }
        foreach ($this->archives as $archive) {
            if (is_link($archive['path']) || hash_file('sha256', $archive['path']) !== $archive['sha256']) {
                throw new RuntimeException('archive_changed');
            }
        }
    }

    public function release(): void
    {
        foreach (array_reverse($this->locks) as $lock) { $this->lock_service->release($lock); }
        $this->locks = [];
    }

    private function normalize(array $row, array $source): array
    {
        $result = [];
        foreach ($source['archive_columns'] as $column => $type) {
            if (!array_key_exists($column, $row)) { throw new RuntimeException('handoff_schema_incomplete'); }
            $result[$column] = $row[$column] === null ? null : ($type === 'INTEGER' ? (int) $row[$column] : (string) $row[$column]);
        }
        if ($result['id'] <= 0 || !$result['landing_key'] || !$result['session_token'] || !$result['handoff_id']
            || !in_array($result['event_type'], ['sms_handoff_attempted', 'sms_handoff_hidden', 'sms_handoff_no_hide'], true)) {
            throw new RuntimeException('handoff_row_invalid');
        }
        return $result;
    }

    private function merge(array &$merged, array $row, int &$duplicates): void
    {
        $id = $row['id'];
        if (isset($merged[$id])) {
            if ($merged[$id] !== $row) { throw new RuntimeException('live_archive_conflict'); }
            $duplicates++;
        } else { $merged[$id] = $row; }
    }
}
