<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Read the MySQL deletion acknowledgements associated with verified SMS archive rows. */
final class Kiwi_Retention_Sms_Deletion_Evidence
{
    private const GROUP_COLUMNS = Kiwi_Sms_Body_Variant_Repository::SUMMARY_GROUP_COLUMNS;
    private const AUDIT_FIELDS = 'id,run_id,source_key,source_table,dry_run,status,started_at,finished_at,archive_db_path,deleted_rows';
    private const MANIFEST = "JSON_EXTRACT(IF(JSON_VALID(gate_results_json),gate_results_json,'{}'),'$.deleted_batches')";
    private const PAGE_SIZE = 8;
    private $runs;

    public function __construct(?Kiwi_Retention_Cleanup_Run_Repository $runs = null)
    {
        $this->runs = $runs ?? new Kiwi_Retention_Cleanup_Run_Repository();
    }

    public function visit(callable $visitor, ?callable $incomplete = null): void
    {
        $cursor = [];
        while (!$this->visit_page($cursor, self::PAGE_SIZE, $visitor, $incomplete)) {
            // Read the next bounded set of historical cleanup runs.
        }
    }

    /** A persisted cursor bounds visits to individual batches, including inside a large run. */
    public function visit_page(array &$cursor, int $limit, callable $visitor, ?callable $incomplete = null): bool
    {
        global $wpdb;

        $table = $this->runs->get_table_name();
        $source = Kiwi_Retention_Source_Registry::SOURCE_SMS_BODY_VARIANT_ASSIGNMENTS;
        if ($cursor === []) {
            $max = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(MAX(id),0) FROM {$table} WHERE source_key=%s AND dry_run=0", $source
            ));
            if (!ctype_digit((string) $max) || (string) ($wpdb->last_error ?? '') !== '') {
                throw new RuntimeException('sms_deletion_audit_read_failed');
            }
            $cursor = ['last_id' => 0, 'max_id' => (int) $max];
        }
        if (!is_int($cursor['last_id'] ?? null) || !is_int($cursor['max_id'] ?? null)
            || $cursor['last_id'] < 0 || $cursor['max_id'] < $cursor['last_id'] || $limit < 1) {
            throw new RuntimeException('sms_deletion_audit_cursor_invalid');
        }
        if ($cursor['last_id'] === $cursor['max_id']) { return true; }
        $row_limit = min($limit, self::PAGE_SIZE);
        $page = $this->read_rows($wpdb->prepare('SELECT ' . self::AUDIT_FIELDS . ',' . self::MANIFEST . " AS manifest FROM {$table}
            WHERE source_key=%s AND dry_run=0 AND id>%d AND id<=%d
                AND (deleted_rows>0 OR JSON_LENGTH(IF(JSON_VALID(gate_results_json),gate_results_json,'{}'),'$.deleted_batches')>0)
            ORDER BY id LIMIT %d",
            $source, $cursor['last_id'], $cursor['max_id'], $row_limit
        ));
        $resume = $cursor['active'] ?? null;
        if ($resume !== null && (!is_array($resume) || (int) ($page[0]['id'] ?? 0) !== ($resume['id'] ?? null))) {
            throw new RuntimeException('sms_deletion_audit_changed');
        }
        foreach ($page as $run) {
            if ($limit === 0) { return false; }
            $manifest = (string) $run['manifest'];
            unset($run['manifest']);
            $offset = $resume['offset'] ?? 0;
            $signature = hash('sha256', json_encode($run, JSON_THROW_ON_ERROR) . hash('sha256', $manifest));
            if ($resume !== null && (($resume['signature'] ?? null) !== $signature || !is_int($offset) || $offset < 1)) {
                throw new RuntimeException('sms_deletion_audit_changed');
            }
            $entries = $this->entries($run, $manifest);
            if ($offset >= count($entries)) { throw new RuntimeException('sms_deletion_audit_cursor_invalid'); }
            foreach (array_slice($entries, $offset, $limit) as $entry) {
                $this->deliver($run, $entry, $visitor, $incomplete);
                $offset++;
                $limit--;
            }
            if ($offset < count($entries)) {
                $cursor['active'] = ['id' => (int) $run['id'], 'offset' => $offset, 'signature' => $signature];
                return false;
            }
            unset($cursor['active']);
            $resume = null;
            $cursor['last_id'] = (int) $run['id'];
        }
        if (count($page) < $row_limit) { $cursor['last_id'] = $cursor['max_id']; }
        return $cursor['last_id'] === $cursor['max_id'];
    }

    private function read_rows(string $sql): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new RuntimeException('sms_deletion_audit_read_failed');
        }
        return $rows;
    }

    private function entries(array $run, string $manifest): array
    {
        $batches = json_decode($manifest, true);
        if (!is_array($batches) || array_values($batches) !== $batches || !$batches) {
            return [['group' => null, 'error' => 'sms_deletion_manifest_missing']];
        }
        $valid = [];
        $issues = [];
        $owner = [];
        $slots = 0;
        $unreadable_list = false;
        $source = Kiwi_Retention_Source_Registry::sms_body_variant_assignments();
        foreach ($batches as $batch) {
            $group = $this->group($batch['group'] ?? null);
            $key = $this->group_key($group);
            $ids = $batch['ids'] ?? null;
            if (!is_array($ids) || array_values($ids) !== $ids) {
                $unreadable_list = true;
                $issues[$key] = [$group, 'sms_deletion_ids_invalid'];
                continue;
            }
            $slots += count($ids);
            if ($group === null || !is_string($batch['batch_id'] ?? null) || trim($batch['batch_id']) === ''
                || $run['source_table'] !== $source['source_table'] || trim((string) $run['archive_db_path']) === ''
                || trim((string) $run['run_id']) === '') {
                $issues[$key] = [$group, 'sms_deletion_manifest_invalid'];
                continue;
            }
            $normalized = [];
            foreach ($ids as $raw_id) {
                $id = $this->positive_id($raw_id);
                if ($id === null) {
                    $issues[$key] = [$group, 'sms_deletion_ids_invalid'];
                    continue;
                }
                if (isset($owner[$id])) {
                    $old_group = $owner[$id];
                    $issues[$this->group_key($old_group)] = [$old_group, 'sms_deletion_id_repeated'];
                    $issues[$key] = [$group, 'sms_deletion_id_repeated'];
                    continue;
                }
                $owner[$id] = $group;
                $normalized[] = $id;
            }
            if (!$ids) { $issues[$key] = [$group, 'sms_deletion_ids_invalid']; }
            $valid[] = ['batch_id' => $batch['batch_id'], 'group' => $group, 'ids' => $normalized];
        }
        if (!$unreadable_list && $slots < (int) $run['deleted_rows']) {
            // The missing receipt might belong to a group not named anywhere in this run.
            $issues[''] = [null, 'sms_deletion_count_mismatch'];
        } elseif ($slots > (int) $run['deleted_rows']) {
            foreach ($valid as $batch) {
                $issues[$this->group_key($batch['group'])] = [$batch['group'], 'sms_deletion_count_mismatch'];
            }
        }
        if (!$issues) { return $valid; }
        $entries = [];
        foreach ($issues as [$group, $reason]) { $entries[] = ['group' => $group, 'error' => $reason]; }
        if (isset($issues[''])) { return $entries; }
        foreach ($valid as $batch) {
            if (!isset($issues[$this->group_key($batch['group'])])) { $entries[] = $batch; }
        }
        return $entries;
    }

    private function group($row): ?array
    {
        $group = [];
        foreach (self::GROUP_COLUMNS as $name) {
            if (!is_string($row[$name] ?? null)) { return null; }
            $group[$name] = $row[$name];
        }
        return $group;
    }

    private function group_key(?array $group): string
    {
        return $group === null ? '' : json_encode(array_map('strtolower', $group), JSON_THROW_ON_ERROR);
    }

    private function positive_id($value): ?int
    {
        if ((!is_int($value) && !is_string($value)) || (int) $value < 1 || (string) (int) $value !== (string) $value) {
            return null;
        }
        return (int) $value;
    }

    private function deliver(array $run, array $entry, callable $visitor, ?callable $report): void
    {
        if (!isset($entry['error'])) { $visitor($run, $entry); return; }
        if ($report === null) { throw new RuntimeException($entry['error']); }
        $report($run, $entry['group'], $entry['error']);
    }

    /** The caller supplies the newest row already verified by the archive reader. */
    public function finished_after_deletion(array $archived_row, string $archive_path, array $group): ?string
    {
        global $wpdb;

        $batch_id = $archived_row['_archive_batch_id'] ?? null;
        $id = $this->positive_id($archived_row['id'] ?? null);
        if (!is_string($batch_id) || $batch_id === '' || $id === null || $archive_path === '') { return null; }
        $key = $this->group_key($this->group($group));
        $found = null;
        $claimed = false;
        $match = function (array $run, array $batch) use ($batch_id, $id, $key, $archived_row, $archive_path, $group, &$found, &$claimed): void {
            if ($batch['batch_id'] !== $batch_id || $this->group_key($batch['group']) !== $key
                || !in_array($id, $batch['ids'], true)) { return; }
            if ($claimed) { throw new RuntimeException('sms_deletion_conflicting_runs'); }
            $claimed = true;
            $found = $this->completed_bound($run, $batch, $archived_row, $archive_path, $group);
        };
        $incomplete = function (array $run, ?array $affected, string $reason) use ($key): void {
            if ($affected === null || $this->group_key($affected) === $key) { throw new RuntimeException($reason); }
        };
        $last_id = 0;
        do {
            // Interactive lookup selects only the requested file and batch. The worker can join all rows in a single visit.
            $rows = $this->read_rows($wpdb->prepare('SELECT ' . self::AUDIT_FIELDS . ',' . self::MANIFEST . " AS manifest FROM {$this->runs->get_table_name()}
                WHERE source_key=%s AND dry_run=0 AND BINARY archive_db_path=BINARY %s AND id>%d
                    AND JSON_CONTAINS(IF(JSON_VALID(gate_results_json),gate_results_json,'{}'),
                        JSON_OBJECT('batch_id',%s),'$.deleted_batches')=1 ORDER BY id LIMIT %d",
                Kiwi_Retention_Source_Registry::SOURCE_SMS_BODY_VARIANT_ASSIGNMENTS, $archive_path, $last_id, $batch_id, self::PAGE_SIZE
            ));
            foreach ($rows as $run) {
                $manifest = (string) $run['manifest'];
                unset($run['manifest']);
                foreach ($this->entries($run, $manifest) as $entry) {
                    $this->deliver($run, $entry, $match, $incomplete);
                }
                $last_id = (int) $run['id'];
            }
        } while (count($rows) === self::PAGE_SIZE);
        return $found;
    }

    public function completed_bound(array $run, array $batch, array $archived_row, string $archive_path, array $group): ?string
    {
        $id = $this->positive_id($archived_row['id'] ?? null);
        $key = $this->group_key($this->group($group));
        $finish = (string) ($run['finished_at'] ?? '');
        $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $finish, new DateTimeZone('UTC'));
        $source = Kiwi_Retention_Source_Registry::sms_body_variant_assignments();
        if ($id === null || $id !== $this->positive_id($archived_row['_source_pk'] ?? null)
            || $key === '' || $key !== $this->group_key($this->group($archived_row))
            || $key !== $this->group_key($this->group($batch['group'] ?? null))
            || ($run['status'] ?? '') !== 'completed' || (string) ($run['dry_run'] ?? '') !== '0'
            || ($run['source_key'] ?? '') !== $source['source_key'] || ($run['source_table'] ?? '') !== $source['source_table']
            || $archive_path === '' || ($run['archive_db_path'] ?? '') !== $archive_path
            || trim((string) ($run['run_id'] ?? '')) === '' || !$timestamp || $timestamp->format('Y-m-d H:i:s') !== $finish
            || empty($run['started_at']) || strcmp($finish, $run['started_at']) < 0
            || empty($batch['batch_id']) || $batch['batch_id'] !== ($archived_row['_archive_batch_id'] ?? null)
            || !in_array($id, $batch['ids'] ?? [], true)) {
            return null;
        }
        return $finish;
    }
}
