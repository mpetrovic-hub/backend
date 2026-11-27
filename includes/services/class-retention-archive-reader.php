<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Read-only pages under the existing generation and corruption gates. */
class Kiwi_Retention_Archive_Reader
{
    private const SOURCE_RECEIPT = 'SELECT 1 FROM archive_batch_rows r JOIN archive_batches b
        ON b.archive_batch_id=r.archive_batch_id WHERE b.source_key=? AND b.source_table=?';
    private const COMPLETED_FILE = " AND b.status='success' AND b.finished_at IS NOT NULL
        AND b.finished_at<>'' AND b.archive_db_path=?";

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
        $indexes = [];
        foreach ($source['archive_read_indexes'] ?? [] as $suffix => $columns) {
            $indexes[$table . '_' . self::identifier($suffix)] = $columns;
        }
        return $indexes;
    }

    /** Check the complete index definition, including order and collation. */
    public static function index_matches(PDO $pdo, array $source, string $name, array $columns): bool
    {
        $table = self::identifier($source['source_table']);
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
                || $key['coll'] !== self::read_index_collation($source, $columns[$i])) {
                return false;
            }
        }
        return true;
    }

    private static function read_index_collation(array $source, string $column): string
    {
        return in_array($column, $source['archive_nocase_columns'] ?? [], true) ? 'NOCASE' : 'BINARY';
    }

    public static function read_index_columns_sql(array $source, array $columns): string
    {
        return implode(',', array_map(static function (string $column) use ($source): string {
            return '"' . self::identifier($column) . '" COLLATE ' . self::read_index_collation($source, $column) . ' ASC';
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

    /** A bounded change token, read under the generation lock; never a substitute for row receipts. */
    public static function file_basis(string $path, Kiwi_Retention_Archive_Lock_Handle $handle): array
    {
        if ($handle->get_lock_path() !== $path . '.lock') { throw new RuntimeException('archive_reader_lock_identity_mismatch'); }
        self::assert_checkpointed($path);
        clearstatcache(true, $path);
        $stream = !is_link($path) && is_file($path) ? @fopen($path, 'rb') : false;
        if ($stream === false) { throw new RuntimeException('archive_reader_path_unreadable'); }
        try {
            $stat = fstat($stream);
            $header = fread($stream, 100);
            if ($stat === false || strlen($header) !== 100 || substr($header, 0, 16) !== "SQLite format 3\0") {
                throw new RuntimeException('archive_reader_header_invalid');
            }
            // SQLite updates its header change counter even for same-size commits within one timestamp tick.
            return ['dev'=>$stat['dev'], 'ino'=>$stat['ino'], 'size'=>$stat['size'],
                'mtime'=>$stat['mtime'], 'ctime'=>$stat['ctime'], 'header'=>bin2hex($header)];
        } finally { fclose($stream); }
    }

    /** Retain every discovered generation lock until close(), including through the caller's read page. */
    public function basis(): array
    {
        $paths = array_column($this->files(true), 'path');
        $basis = [];
        foreach ($paths as $path) {
            $this->open($path);
            $basis[$path] = self::file_basis($path, $this->handles[$path]);
        }
        if (array_column($this->files(true), 'path') !== $paths) { throw new RuntimeException('archive_inventory_changed'); }
        return $basis;
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
        self::assert_checkpointed($path);
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

    /** Immutable reads and basis tokens must not ignore a WAL commit or an unfinished journal. */
    private static function assert_checkpointed(string $path): void
    {
        foreach (['-wal', '-journal'] as $suffix) {
            $sidecar = $path . $suffix;
            clearstatcache(true, $sidecar);
            if (is_link($sidecar) || (file_exists($sidecar) && (!is_readable($sidecar) || filesize($sidecar) !== 0))) {
                throw new RuntimeException('archive_reader_uncheckpointed_state');
            }
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
                if (!self::index_matches($pdo, $source, $name, $definition)) {
                    throw new RuntimeException('archive_reader_indexes_missing');
                }
            }
        }
        $this->source_checks[$cache_key] = true;
        return true;
    }

    /** Candidate IDs only. The caller must check the authoritative copy and live ownership. */
    public function matching_source_ids(array $source, string $column, string $value, ?callable $accept_value = null): array
    {
        $ids = [];
        $table = self::identifier($source['source_table']);
        $column = self::identifier($column);
        $collation = self::read_index_collation($source, $column);
        foreach ($this->files(true) as $file) {
            $pdo = $this->open($file['path']);
            if (!$this->has_source($pdo, $source)) { continue; }
            $statement = $pdo->prepare('SELECT _source_pk,"' . $column . '" FROM "' . $table . '" WHERE "' . $column . '"=? COLLATE ' . $collation);
            $statement->execute([$value]);
            while ($candidate = $statement->fetch(PDO::FETCH_NUM)) {
                if ($accept_value === null || $accept_value((string) $candidate[1])) {
                    $ids[(int) $candidate[0]] = (int) $candidate[0];
                }
            }
        }
        $this->assert_inventory_unchanged();
        return array_values($ids);
    }

    /** Verify Source-ID evidence; mutable snapshots may have older versions. */
    public function copies(
        array $source, int $source_pk, ?Kiwi_Retention_Archive_Lock_Handle $held_lock = null,
        bool $require_complete_receipts = false
    ): array
    {
        $this->assert_inventory_unchanged();
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
                if ($require_complete_receipts) {
                    $receipt = $pdo->prepare('SELECT 1 FROM archive_batches b CROSS JOIN archive_batch_rows r
                        WHERE b.source_key=? AND b.source_table=? AND r.archive_batch_id=b.archive_batch_id AND r.source_pk=? LIMIT 1');
                    $receipt->execute([$source['source_key'], $source['source_table'], $source_pk]);
                    if ($receipt->fetchColumn()) { throw new RuntimeException('archive_reader_receipted_row_missing'); }
                }
                continue;
            }
            $this->verify_row_receipt($pdo, $source, $row, $require_complete_receipts ? $path : null);
            $snapshot = self::snapshot($source, $row);
            if ($reference !== null && $snapshot !== $reference
                && empty($source['mutable_snapshot'])) {
                throw new RuntimeException('archive_reader_snapshot_conflict');
            }
            $reference = $snapshot;
            $copies[$path] = $row;
        }
        return $copies;
    }

    /** Keyset pages for a complete scan across yearly archives, without retaining all original IDs. */
    public function original_ids_after(array $source, int $after, int $limit): array
    {
        $ids = [];
        $table = self::identifier($source['source_table']);
        $limit = min(500, max(1, $limit));
        foreach ($this->files() as $file) {
            $pdo = $this->open($file['path']);
            if (!$this->has_source($pdo, $source)) { continue; }
            $select = $pdo->prepare('SELECT _source_pk FROM "' . $table . '" WHERE _source_pk>? ORDER BY _source_pk LIMIT ' . $limit);
            $select->execute([$after]);
            foreach ($select->fetchAll(PDO::FETCH_COLUMN) as $id) { $ids[(int) $id] = (int) $id; }
        }
        sort($ids, SORT_NUMERIC);
        return array_slice($ids, 0, $limit);
    }

    /** Read a bounded set with the same row/receipt/batch identity used for individual lookups. */
    public function receipted_rows(array $source, string $path, array $ids): array
    {
        if (!$ids || count($ids) > 500) { throw new InvalidArgumentException('archive_receipt_page_size'); }
        $pdo = $this->open($path);
        if (!$this->has_source($pdo, $source)) { return []; }
        $table = self::identifier($source['source_table']);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT s.*, EXISTS (' . self::SOURCE_RECEIPT . self::COMPLETED_FILE
            . ' AND r.source_pk=s._source_pk AND r.archive_batch_id=s._archive_batch_id) AS verified
            FROM "' . $table . '" s WHERE s._source_pk IN (' . $marks . ')';
        $query = $pdo->prepare($sql);
        $query->execute(array_merge([$source['source_key'], $table, $path], $ids));
        $rows = [];
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $row['_source_pk'];
            $valid = (int) $row['verified'] === 1 && $id > 0 && $id === (int) $row[$source['primary_key']];
            unset($row['verified']);
            try { self::snapshot($source, $row); }
            catch (RuntimeException $error) { $valid = false; }
            $rows[$id] = ['row' => $row, 'verified' => $valid, 'path' => $path];
        }
        return $rows;
    }

    /** Newest available row per ID. A bad newer receipt must remain visible to the caller. */
    public function latest_receipted_rows(array $source, array $paths, array $ids): array
    {
        $latest = [];
        foreach (array_reverse($paths) as $path) {
            $remaining = array_values(array_diff($ids, array_keys($latest)));
            if (!$remaining) { break; }
            $latest += $this->receipted_rows($source, $path, $remaining);
        }
        return $latest;
    }

    /** Check external deletion IDs even when the restored generation has lost the entire batch. */
    public function missing_batch_payloads(array $source, string $path, string $batch, array $ids): array
    {
        if (!$ids || count($ids) > 500) { throw new InvalidArgumentException('archive_receipt_page_size'); }
        $pdo = $this->open($path);
        if (!$this->has_source($pdo, $source)) { return $ids; }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $query = $pdo->prepare('SELECT _source_pk FROM "' . self::identifier($source['source_table'])
            . '" WHERE _archive_batch_id=? AND "' . self::identifier($source['primary_key']) . '"=_source_pk AND _source_pk IN (' . $marks . ')');
        $query->execute(array_merge([$batch], $ids));
        return array_values(array_diff($ids, array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN))));
    }

    /** Inspect only receipt keys and the unique source-ID index. Limit the keys visited before testing for absent rows. */
    public function missing_snapshot_batches(array $source, array $paths, float $deadline): iterable
    {
        $table = self::identifier($source['source_table']);
        foreach ($paths as $path) {
            $pdo = $this->open($path);
            if (!$this->has_source($pdo, $source)) { continue; }
            $batches = $pdo->prepare('SELECT archive_batch_id FROM archive_batches WHERE source_key=? AND source_table=?');
            $batches->execute([$source['source_key'], $table]);
            $page = $pdo->prepare('SELECT MAX(r.source_pk) AS last_id, COUNT(*) AS visited,
                GROUP_CONCAT(CASE WHEN s._source_pk IS NULL THEN r.source_pk END) AS missing
                FROM (SELECT source_pk FROM archive_batch_rows WHERE archive_batch_id=? AND source_pk>?
                    ORDER BY source_pk LIMIT 500) r
                LEFT JOIN "' . $table . '" s ON s._source_pk=r.source_pk');
            while (($batch = $batches->fetchColumn()) !== false) {
                $after = 0;
                do {
                    if (microtime(true) >= $deadline) { throw new RuntimeException('archive_code_search_timeout'); }
                    $page->execute([$batch, $after]);
                    $result = $page->fetch(PDO::FETCH_ASSOC);
                    if ($result['missing'] !== null) { yield [$path, array_map('intval', explode(',', $result['missing']))]; }
                    $after = (int)$result['last_id'];
                } while ((int)$result['visited'] === 500);
            }
        }
    }

    /** An orphaned source receipt can prove absence even when no readable row remains to enumerate. */
    public function missing_original_ids(array $source, string $path, int $after, int $limit): array
    {
        $pdo = $this->open($path);
        if (!$this->has_source($pdo, $source)) { return []; }
        $table = self::identifier($source['source_table']);
        $query = $pdo->prepare('SELECT DISTINCT r.source_pk FROM archive_batches b CROSS JOIN archive_batch_rows r
            ON b.archive_batch_id=r.archive_batch_id LEFT JOIN "' . $table . '" s ON s._source_pk=r.source_pk
            WHERE b.source_key=? AND b.source_table=? AND r.source_pk>? AND s._source_pk IS NULL ORDER BY r.source_pk LIMIT '
            . min(500, max(1, $limit)));
        $query->execute([$source['source_key'], $table, $after]);
        return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
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
            // has_source rejects missing tables with Session batch evidence;
            // a verified generation containing only other sources is empty.
            return false;
        }
        $table = self::identifier($source['source_table']);
        $index = self::identifier($table . '_retention_pair');
        $next = (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        // All predicate fields are in the existing covering index. Do not scan
        // wide original payloads or create a runtime index for empty days.
        $statement = $pdo->prepare('SELECT 1 FROM "' . $table . '" INDEXED BY "' . $index . '"
            WHERE created_at>=? AND created_at<? AND pid COLLATE NOCASE IN (' . implode(',', array_fill(0, count($pids), '?')) . ')
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
            return 0;
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

    public function assert_inventory_unchanged(): void
    {
        if ($this->files !== null && array_column($this->archive_service->list_archive_files(), 'path') !== array_column($this->files, 'path')) {
            throw new RuntimeException('archive_inventory_changed');
        }
    }

    private function verify_row_receipt(PDO $pdo, array $source, array $row, ?string $file = null): void
    {
        $query = self::SOURCE_RECEIPT;
        $args = [$source['source_key'], $source['source_table']];
        if ($file !== null) {
            $query .= self::COMPLETED_FILE;
            $args[] = $file;
        }
        $statement = $pdo->prepare($query . ' AND r.source_pk=? AND r.archive_batch_id=? LIMIT 1');
        $statement->execute(array_merge($args, [$row['_source_pk'], $row['_archive_batch_id']]));
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
