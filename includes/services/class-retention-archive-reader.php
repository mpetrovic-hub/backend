<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Read-only pages under the existing generation and corruption gates. */
class Kiwi_Retention_Archive_Reader
{
    private $archive_service;
    private $lock_service;
    private $safety_gate;
    private $connections = [];
    private $handles = [];
    private $files;
    private $source_checks = [];

    public function __construct(
        ?Kiwi_Retention_Sqlite_Archive_Service $archive_service = null,
        ?Kiwi_Retention_Archive_Lock $lock_service = null,
        ?Kiwi_Retention_Corruption_Safety_Gate_Coordinator $safety_gate = null
    ) {
        $this->archive_service = $archive_service ?? new Kiwi_Retention_Sqlite_Archive_Service();
        $this->lock_service = $lock_service ?? new Kiwi_Retention_Archive_Lock();
        $this->safety_gate = $safety_gate ?? new Kiwi_Retention_Corruption_Safety_Gate_Coordinator();
    }

    public static function read_indexes(array $source): array
    {
        $table = self::identifier((string) ($source['source_table'] ?? ''));
        if (($source['source_key'] ?? '') === Kiwi_Retention_Source_Registry::SOURCE_LANDING_PAGE_SESSIONS) {
            return [$table . '_retention_pair' => ['landing_key', 'session_token', 'created_at', '_source_pk', 'pid']];
        }
        if (($source['source_key'] ?? '') === Kiwi_Retention_Source_Registry::SOURCE_LANDING_SESSION_ENGAGEMENTS) {
            return [$table . '_retention_day' => ['created_at', '_source_pk']];
        }
        return [];
    }

    /** Check the complete index definition, including order and collation. */
    public static function index_matches(PDO $pdo, string $table, string $name, array $columns): bool
    {
        $table = self::identifier($table);
        $name = self::identifier($name);
        $found = false;
        foreach ($pdo->query('PRAGMA index_list("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if ($index['name'] === $name) {
                $found = (int) $index['unique'] === 0 && (int) ($index['partial'] ?? 0) === 0;
                break;
            }
        }
        if (!$found) {
            return false;
        }
        $keys = array_values(array_filter(
            $pdo->query('PRAGMA index_xinfo("' . $name . '")')->fetchAll(PDO::FETCH_ASSOC),
            static function (array $column): bool { return (int) $column['key'] === 1; }
        ));
        if (count($keys) !== count($columns)) {
            return false;
        }
        foreach ($keys as $i => $key) {
            if ($key['name'] !== $columns[$i] || (int) $key['desc'] !== 0
                || $key['coll'] !== self::read_index_collation($columns[$i])) {
                return false;
            }
        }
        return true;
    }

    /** Only pair membership folds ASCII case; snapshots keep original bytes. */
    private static function read_index_collation(string $column): string
    {
        return in_array($column, ['landing_key', 'session_token'], true) ? 'NOCASE' : 'BINARY';
    }

    public static function read_index_columns_sql(array $columns): string
    {
        return implode(',', array_map(static function (string $column): string {
            return '"' . self::identifier($column) . '" COLLATE ' . self::read_index_collation($column) . ' ASC';
        }, $columns));
    }

    public static function snapshot(array $source, array $row): array
    {
        if (!empty($source['mutable_snapshot'])) {
            $extras = array_diff(array_keys($row), array_keys($source['archive_columns']),
                ['_archive_batch_id', '_archived_at', '_source_pk', '_retention_live_session']);
            if ($extras) { throw new RuntimeException('archive_snapshot_unmapped_fields'); }
        }
        $snapshot = [];
        foreach ($source['archive_columns'] as $column => $type) {
            if (!array_key_exists($column, $row)) {
                throw new RuntimeException('archive_snapshot_incomplete');
            }
            if ($type === 'INTEGER' && $row[$column] !== null
                && (!is_int($row[$column]) && (!is_string($row[$column])
                    || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $row[$column]) !== 1
                    || (string) (int) $row[$column] !== $row[$column]))
            ) { throw new RuntimeException('archive_snapshot_integer_invalid'); }
            $snapshot[$column] = $row[$column] === null
                ? null : ($type === 'INTEGER' ? (int) $row[$column] : (string) $row[$column]);
        }
        return $snapshot;
    }

    public function files(bool $refresh = false): array
    {
        if ($refresh || $this->files === null) {
            $this->files = $this->archive_service->list_archive_files();
        }
        return $this->files;
    }

    public function open(string $path, ?Kiwi_Retention_Archive_Lock_Handle $held_lock = null): PDO
    {
        $path = $this->archive_service->resolve_existing_archive_db_path_read_only($path);
        if (isset($this->connections[$path])) {
            return $this->connections[$path];
        }
        if ($held_lock !== null && $held_lock->get_lock_path() !== $path . '.lock') {
            throw new RuntimeException('archive_reader_lock_identity_mismatch');
        }
        if ($held_lock === null) {
            $lock = $this->lock_service->acquire_for_archive($path);
            if (empty($lock['success']) || empty($lock['acquired'])) {
                throw new RuntimeException((string) ($lock['error_code'] ?? 'archive_reader_lock_failed'));
            }
            $this->handles[$path] = $lock['handle'];
        }
        if (empty($this->safety_gate->inspect($path, false)['allowed'])) {
            throw new RuntimeException('archive_reader_corruption_gate');
        }
        // immutable must never ignore a committed WAL or an unfinished journal.
        foreach (['-wal', '-journal'] as $suffix) {
            $sidecar = $path . $suffix;
            clearstatcache(true, $sidecar);
            if (is_link($sidecar) || (file_exists($sidecar) && (!is_readable($sidecar) || filesize($sidecar) !== 0))) {
                throw new RuntimeException('archive_reader_uncheckpointed_state');
            }
        }
        $real = realpath($path);
        if ($real === false || is_link($path) || !is_file($real) || !is_readable($real)) {
            throw new RuntimeException('archive_reader_path_unreadable');
        }
        $uri_path = str_replace('%2F', '/', rawurlencode(str_replace('\\', '/', $real)));
        $uri_path = str_replace('%3A', ':', $uri_path);
        try {
            $pdo = new PDO('sqlite:file:' . $uri_path . '?mode=ro&immutable=1');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA query_only = ON');
            $pdo->exec('PRAGMA cache_size = -8192');
            $pdo->exec('PRAGMA mmap_size = 0');
            $this->connections[$path] = $pdo;
            return $pdo;
        } catch (Throwable $error) {
            throw new RuntimeException('archive_reader_open_failed');
        }
    }

    public function has_source(PDO $pdo, array $source, bool $require_indexes = true): bool
    {
        $cache_key = spl_object_id($pdo) . ':' . $source['source_key'] . ':' . (int) $require_indexes;
        if (array_key_exists($cache_key, $this->source_checks)) { return $this->source_checks[$cache_key]; }
        $table = self::identifier((string) $source['source_table']);
        $exists = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $exists->execute([$table]);
        if (!$exists->fetchColumn()) {
            $evidence = $pdo->prepare('SELECT 1 FROM archive_batches WHERE source_key=? OR source_table=? LIMIT 1');
            $evidence->execute([$source['source_key'], $table]);
            if ($evidence->fetchColumn()) { throw new RuntimeException('archive_reader_source_table_missing'); }
            $this->source_checks[$cache_key] = false;
            return false;
        }
        $columns = $pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC);
        $expected = array_merge(['_archive_batch_id', '_archived_at', '_source_pk'], array_keys($source['archive_columns']));
        if (array_column($columns, 'name') !== $expected) {
            throw new RuntimeException('archive_reader_schema_mismatch');
        }
        $types = array_merge(['TEXT', 'TEXT', 'INTEGER'], array_values($source['archive_columns']));
        foreach ($columns as $i => $column) {
            if ($column['type'] !== $types[$i]) { throw new RuntimeException('archive_reader_column_type_mismatch'); }
        }
        $unique_source_id = false;
        foreach ($pdo->query('PRAGMA index_list("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if ((int) $index['unique'] !== 1 || (int) ($index['partial'] ?? 0) !== 0) { continue; }
            $info = $pdo->query('PRAGMA index_info("' . self::identifier($index['name']) . '")')->fetchAll(PDO::FETCH_ASSOC);
            if (array_column($info, 'name') === ['_source_pk']) { $unique_source_id = true; }
        }
        if (!$unique_source_id) { throw new RuntimeException('archive_reader_source_id_index_missing'); }
        $identity = $pdo->prepare('SELECT 1 FROM archive_batches WHERE source_key=? AND source_table=? LIMIT 1');
        $identity->execute([$source['source_key'], $table]);
        if (!$identity->fetchColumn()) {
            throw new RuntimeException('archive_reader_source_identity_missing');
        }
        if ($require_indexes) {
            foreach (self::read_indexes($source) as $name => $definition) {
                if (!self::index_matches($pdo, $table, $name, $definition)) {
                    throw new RuntimeException('archive_reader_indexes_missing');
                }
            }
        }
        $this->source_checks[$cache_key] = true;
        return true;
    }

    /** Verify Source-ID evidence; mutable engagements may have older versions. */
    public function copies(array $source, int $source_pk, ?Kiwi_Retention_Archive_Lock_Handle $held_lock = null): array
    {
        if ($this->files !== null && array_column($this->archive_service->list_archive_files(), 'path') !== array_column($this->files, 'path')) {
            throw new RuntimeException('archive_inventory_changed');
        }
        $copies = [];
        $reference = null;
        foreach ($this->files() as $file) {
            $path = $file['path'];
            $handle = $held_lock !== null && $held_lock->get_lock_path() === $path . '.lock' ? $held_lock : null;
            $pdo = $this->open($path, $handle);
            if (!$this->has_source($pdo, $source)) {
                continue;
            }
            $table = self::identifier($source['source_table']);
            $statement = $pdo->prepare('SELECT * FROM "' . $table . '" WHERE _source_pk=? LIMIT 1');
            $statement->execute([$source_pk]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                continue;
            }
            $this->verify_row_receipt($pdo, $source, $row);
            $snapshot = self::snapshot($source, $row);
            if ($reference !== null && $snapshot !== $reference
                && ($source['source_key'] ?? '') !== Kiwi_Retention_Source_Registry::SOURCE_LANDING_SESSION_ENGAGEMENTS) {
                throw new RuntimeException('archive_reader_snapshot_conflict');
            }
            $reference = $snapshot;
            $copies[$path] = $row;
        }
        return $copies;
    }

    public function day_page(array $source, string $path, string $date, array $cursor, int $limit): array
    {
        $pdo = $this->open($path);
        if (!$this->has_source($pdo, $source)) {
            return [];
        }
        $table = self::identifier($source['source_table']);
        $next = (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        $statement = $pdo->prepare(
            'SELECT * FROM "' . $table . '" WHERE created_at>=? AND created_at<?
             AND (created_at, _source_pk) > (?, ?) ORDER BY created_at, _source_pk LIMIT ?'
        );
        $statement->bindValue(1, $date . ' 00:00:00');
        $statement->bindValue(2, $next . ' 00:00:00');
        $statement->bindValue(3, (string) ($cursor['created_at'] ?? $date . ' 00:00:00'));
        $statement->bindValue(4, (int) ($cursor['id'] ?? 0), PDO::PARAM_INT);
        $statement->bindValue(5, min(500, max(1, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** A zero-day proof must inspect Sessions even without a matching Engagement. */
    public function session_day_has_pid(array $source, string $path, string $date, array $pids): bool
    {
        if ($source['source_key'] !== Kiwi_Retention_Source_Registry::SOURCE_LANDING_PAGE_SESSIONS || !$pids) {
            throw new RuntimeException('archive_reader_session_day_policy_invalid');
        }
        $pdo = $this->open($path);
        if (!$this->has_source($pdo, $source)) {
            throw new RuntimeException('archive_reader_session_day_source_missing');
        }
        $table = self::identifier($source['source_table']);
        $index = self::identifier($table . '_retention_pair');
        $next = (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        // All predicate fields are in the existing covering index. Do not scan
        // wide original payloads or create a runtime index for empty days.
        $statement = $pdo->prepare('SELECT 1 FROM "' . $table . '" INDEXED BY "' . $index . '"
            WHERE created_at>=? AND created_at<? AND pid IN (' . implode(',', array_fill(0, count($pids), '?')) . ')
            AND landing_key<>\'\' AND session_token<>\'\' LIMIT 1');
        $statement->execute(array_merge([$date . ' 00:00:00', $next . ' 00:00:00'], $pids));
        return $statement->fetchColumn() !== false;
    }

    /** Immutable Session rows only grow through the guarded archive writer. */
    public function session_row_count(array $source, string $path): int
    {
        if ($source['source_key'] !== Kiwi_Retention_Source_Registry::SOURCE_LANDING_PAGE_SESSIONS) {
            throw new RuntimeException('archive_reader_session_day_policy_invalid');
        }
        $pdo = $this->open($path);
        if (!$this->has_source($pdo, $source)) {
            throw new RuntimeException('archive_reader_session_day_source_missing');
        }
        $count = $pdo->query('SELECT COUNT(*) FROM "' . self::identifier($source['source_table']) . '"')->fetchColumn();
        if ($count === false) { throw new RuntimeException('archive_reader_session_count_failed'); }
        return (int) $count;
    }

    public function pair_page(array $source, string $path, array $engagement, array $cursor, int $limit): array
    {
        $pdo = $this->open($path);
        if (!$this->has_source($pdo, $source)) {
            return [];
        }
        $table = self::identifier($source['source_table']);
        $date = substr((string) $engagement['created_at'], 0, 10);
        $next = (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        $statement = $pdo->prepare(
            'SELECT landing_key,session_token,created_at,_source_pk,_source_pk AS id,pid FROM "' . $table . '" WHERE landing_key=? COLLATE NOCASE AND session_token=? COLLATE NOCASE
             AND created_at>=? AND created_at<? AND (created_at, _source_pk) > (?, ?)
             ORDER BY created_at, _source_pk LIMIT ?'
        );
        foreach ([$engagement['landing_key'], $engagement['session_token'], $date . ' 00:00:00',
            $next . ' 00:00:00', $cursor['created_at'] ?? $date . ' 00:00:00'] as $i => $value) {
            $statement->bindValue($i + 1, $value);
        }
        $statement->bindValue(6, (int) ($cursor['id'] ?? 0), PDO::PARAM_INT);
        $statement->bindValue(7, min(500, max(1, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Pair reads stay covered; live/archive duplicates require full proof. */
    public function session_copies(array $source, int $id, ?array $live = null): array
    {
        if ($this->files !== null && array_column($this->archive_service->list_archive_files(), 'path') !== array_column($this->files, 'path')) {
            throw new RuntimeException('archive_inventory_changed');
        }
        $copies = [];
        $table = self::identifier($source['source_table']);
        foreach ($this->files() as $file) {
            $pdo = $this->open($file['path']);
            if (!$this->has_source($pdo, $source)) { continue; }
            $statement = $pdo->prepare('SELECT _source_pk,id,_archive_batch_id FROM "' . $table . '" WHERE _source_pk=?');
            $statement->execute([$id]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row) { continue; }
            $this->verify_row_receipt($pdo, $source, $row);
            $copies[$file['path']] = $row;
        }
        if (count($copies) > 1 || ($copies && $live !== null)) {
            $reference = $live === null ? null : self::snapshot($source, $live);
            foreach ($copies as $path => $metadata) {
                $statement = $this->connections[$path]->prepare('SELECT * FROM "' . $table . '" WHERE _source_pk=?');
                $statement->execute([$id]);
                $snapshot = self::snapshot($source, $statement->fetch(PDO::FETCH_ASSOC));
                if ($reference !== null && $reference !== $snapshot) { throw new RuntimeException('archive_reader_snapshot_conflict'); }
                $reference = $snapshot;
            }
        }
        return $copies;
    }

    private function verify_row_receipt(PDO $pdo, array $source, array $row): void
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM archive_batch_rows r INNER JOIN archive_batches b
             ON b.archive_batch_id=r.archive_batch_id WHERE r.source_pk=?
             AND r.archive_batch_id=? AND b.source_key=? AND b.source_table=? LIMIT 1'
        );
        $statement->execute([$row['_source_pk'], $row['_archive_batch_id'], $source['source_key'], $source['source_table']]);
        if (!$statement->fetchColumn() || (int) $row['_source_pk'] !== (int) $row[$source['primary_key']]) {
            throw new RuntimeException('archive_reader_receipt_missing');
        }
    }

    public function close(): void
    {
        $this->connections = [];
        $this->source_checks = [];
        foreach ($this->handles as $handle) {
            $this->lock_service->release($handle);
        }
        $this->handles = [];
    }

    public function __destruct() { $this->close(); }

    public static function identifier(string $value): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value) !== 1) {
            throw new RuntimeException('archive_reader_identifier_invalid');
        }
        return $value;
    }
}
