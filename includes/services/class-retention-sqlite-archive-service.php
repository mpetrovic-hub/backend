<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Retention_Sqlite_Archive_Service
{
    private $config;

    public function __construct(?Kiwi_Config $config = null)
    {
        $this->config = $config instanceof Kiwi_Config ? $config : new Kiwi_Config();
    }

    public function archive_eligible_rows(
        array $source,
        string $cutoff_value,
        string $archive_batch_id,
        int $batch_limit
    ): array {
        $result = [
            'success' => false,
            'archive_batch_id' => $archive_batch_id,
            'archive_db_path' => '',
            'archived_rows' => 0,
            'archive_inserted_rows' => 0,
            'archive_duplicate_rows' => 0,
            'archive_integrity_check' => '',
            'error_code' => '',
            'error_message' => '',
        ];

        if (!class_exists('PDO')) {
            $result['error_code'] = 'sqlite_pdo_unavailable';
            $result['error_message'] = 'PDO is not available for SQLite retention archive.';

            return $result;
        }

        $source_table = (string) ($source['source_table'] ?? '');
        $primary_key = (string) ($source['primary_key'] ?? '');
        $cutoff_column = (string) ($source['cutoff_column'] ?? '');

        if (!$this->is_identifier($source_table)
            || !$this->is_identifier($primary_key)
            || !$this->is_identifier($cutoff_column)
        ) {
            $result['error_code'] = 'invalid_source_definition';
            $result['error_message'] = 'Retention source definition contains an invalid SQL identifier.';

            return $result;
        }

        $pdo = null;
        $transaction_started = false;

        try {
            $archive_db_path = $this->build_archive_db_path();
            $result['archive_db_path'] = $archive_db_path;
            $this->ensure_archive_directory(dirname($archive_db_path));

            $pdo = new PDO('sqlite:' . $archive_db_path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');

            $this->ensure_archive_schema($pdo, $source);
            $this->start_archive_batch($pdo, $source, $archive_batch_id, $cutoff_value, $archive_db_path, true);
            $archive_row_statement = $this->prepare_archive_row_statement($pdo, $source);
            $archive_batch_row_statement = $this->prepare_archive_batch_row_statement($pdo);
            $archived_at = $this->current_time_mysql();

            $last_id = 0;
            $batch_limit = max(1, $batch_limit);
            $pdo->beginTransaction();
            $transaction_started = true;

            while (true) {
                $rows = $this->fetch_source_rows($source, $cutoff_value, $last_id, $batch_limit);

                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    $source_pk = (int) ($row[$primary_key] ?? 0);
                    $last_id = max($last_id, $source_pk);
                    $inserted = $this->insert_archive_row($archive_row_statement, $source, $archive_batch_id, $archived_at, $row);
                    $this->insert_archive_batch_row($archive_batch_row_statement, $archive_batch_id, $source_pk);
                    $result['archived_rows']++;

                    if ($inserted) {
                        $result['archive_inserted_rows']++;
                    } else {
                        $result['archive_duplicate_rows']++;
                    }
                }
            }

            $pdo->commit();
            $transaction_started = false;

            $integrity_check = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
            $result['archive_integrity_check'] = $integrity_check;
            $result['success'] = strtolower($integrity_check) === 'ok';

            if (!$result['success']) {
                $result['error_code'] = 'sqlite_integrity_check_failed';
                $result['error_message'] = 'SQLite archive integrity check returned: ' . $integrity_check;
            }

            $this->finish_archive_batch($pdo, $archive_batch_id, $result);
        } catch (Throwable $error) {
            if ($transaction_started && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $result = $this->apply_archive_failure($result, $error);
        }

        return $result;
    }

    public function fetch_archived_primary_key_batch(
        array $source,
        string $archive_db_path,
        string $archive_batch_id,
        int $last_primary_key,
        int $batch_limit
    ): array {
        if (!class_exists('PDO') || $archive_db_path === '' || !is_file($archive_db_path)) {
            return [];
        }

        if (!$this->is_identifier((string) ($source['source_table'] ?? ''))) {
            return [];
        }

        $pdo = new PDO('sqlite:' . $archive_db_path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $statement = $pdo->prepare(
            'SELECT source_pk
             FROM archive_batch_rows
             WHERE archive_batch_id = :archive_batch_id
               AND source_pk > :last_primary_key
             ORDER BY source_pk ASC
             LIMIT :batch_limit'
        );
        $statement->bindValue(':archive_batch_id', $archive_batch_id, PDO::PARAM_STR);
        $statement->bindValue(':last_primary_key', max(0, $last_primary_key), PDO::PARAM_INT);
        $statement->bindValue(':batch_limit', max(1, $batch_limit), PDO::PARAM_INT);
        $statement->execute();

        $ids = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $source_pk = (int) ($row['source_pk'] ?? 0);

            if ($source_pk > 0) {
                $ids[] = $source_pk;
            }
        }

        return $ids;
    }

    public function archive_primary_key_chunk(
        array $source,
        string $cutoff_value,
        string $archive_batch_id,
        int $last_primary_key,
        int $target_max_primary_key,
        int $batch_limit,
        int $time_limit_seconds
    ): array {
        $result = [
            'success' => false,
            'archive_batch_id' => $archive_batch_id,
            'archive_db_path' => '',
            'archived_rows' => 0,
            'archive_inserted_rows' => 0,
            'archive_duplicate_rows' => 0,
            'archived_primary_keys' => [],
            'last_primary_key' => max(0, $last_primary_key),
            'has_more' => false,
            'quick_check' => '',
            'error_code' => '',
            'error_message' => '',
        ];

        if (!class_exists('PDO')) {
            $result['error_code'] = 'sqlite_pdo_unavailable';
            $result['error_message'] = 'PDO is not available for SQLite retention archive.';

            return $result;
        }

        $source_table = (string) ($source['source_table'] ?? '');
        $primary_key = (string) ($source['primary_key'] ?? '');
        $cutoff_column = (string) ($source['cutoff_column'] ?? '');

        if (!$this->is_identifier($source_table)
            || !$this->is_identifier($primary_key)
            || !$this->is_identifier($cutoff_column)
            || $target_max_primary_key <= 0
        ) {
            $result['error_code'] = 'invalid_source_definition';
            $result['error_message'] = 'Retention source definition contains an invalid SQL identifier or target primary key.';

            return $result;
        }

        $pdo = null;
        $transaction_started = false;

        try {
            $archive_db_path = $this->build_archive_db_path();
            $result['archive_db_path'] = $archive_db_path;
            $this->ensure_archive_directory(dirname($archive_db_path));

            $pdo = new PDO('sqlite:' . $archive_db_path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');

            $this->ensure_archive_schema($pdo, $source);
            $this->start_archive_batch($pdo, $source, $archive_batch_id, $cutoff_value, $archive_db_path, false);
            $archive_row_statement = $this->prepare_archive_row_statement($pdo, $source);
            $archive_batch_row_statement = $this->prepare_archive_batch_row_statement($pdo);
            $archived_at = $this->current_time_mysql();
            $batch_limit = max(1, $batch_limit);
            $time_limit_seconds = max(1, $time_limit_seconds);
            $started_at = microtime(true);

            $rows = $this->fetch_source_rows(
                $source,
                $cutoff_value,
                max(0, $last_primary_key),
                $batch_limit,
                $target_max_primary_key
            );

            $pdo->beginTransaction();
            $transaction_started = true;

            foreach ($rows as $row) {
                if ($result['archived_rows'] > 0 && (microtime(true) - $started_at) >= $time_limit_seconds) {
                    break;
                }

                $source_pk = (int) ($row[$primary_key] ?? 0);

                if ($source_pk <= 0 || $source_pk > $target_max_primary_key) {
                    continue;
                }

                $inserted = $this->insert_archive_row($archive_row_statement, $source, $archive_batch_id, $archived_at, $row);
                $this->insert_archive_batch_row($archive_batch_row_statement, $archive_batch_id, $source_pk);
                $result['archived_rows']++;
                $result['archived_primary_keys'][] = $source_pk;
                $result['last_primary_key'] = max((int) $result['last_primary_key'], $source_pk);

                if ($inserted) {
                    $result['archive_inserted_rows']++;
                } else {
                    $result['archive_duplicate_rows']++;
                }
            }

            $pdo->commit();
            $transaction_started = false;

            $quick_check = (string) $pdo->query('PRAGMA quick_check')->fetchColumn();
            $result['quick_check'] = $quick_check;
            $result['success'] = strtolower($quick_check) === 'ok';

            if (!$result['success']) {
                $result['error_code'] = 'sqlite_quick_check_failed';
                $result['error_message'] = 'SQLite archive quick_check returned: ' . $quick_check;
            }

            $result['has_more'] = $this->has_more_source_rows(
                $source,
                $cutoff_value,
                (int) $result['last_primary_key'],
                $target_max_primary_key
            );

            $this->finish_archive_batch($pdo, $archive_batch_id, $result);
        } catch (Throwable $error) {
            if ($transaction_started && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $result = $this->apply_archive_failure($result, $error);
        }

        return $result;
    }

    public function run_integrity_check(string $archive_db_path): string
    {
        if (!class_exists('PDO') || $archive_db_path === '' || !is_file($archive_db_path)) {
            return '';
        }

        $pdo = new PDO('sqlite:' . $archive_db_path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
    }

    protected function fetch_source_rows(
        array $source,
        string $cutoff_value,
        int $last_id,
        int $batch_limit,
        int $target_max_primary_key = 0
    ): array
    {
        global $wpdb;

        $source_table = (string) ($source['source_table'] ?? '');
        $primary_key = (string) ($source['primary_key'] ?? '');
        $cutoff_column = (string) ($source['cutoff_column'] ?? '');
        $columns = array_keys((array) ($source['archive_columns'] ?? []));
        $columns = array_values(array_filter($columns, [$this, 'is_identifier']));
        $select_columns = implode(', ', $columns);

        if ($select_columns === '') {
            return [];
        }

        if ($target_max_primary_key > 0) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT {$select_columns}
                     FROM {$source_table}
                     WHERE {$cutoff_column} < %s
                       AND {$primary_key} > %d
                       AND {$primary_key} <= %d
                     ORDER BY {$primary_key} ASC
                     LIMIT %d",
                    $cutoff_value,
                    $last_id,
                    $target_max_primary_key,
                    $batch_limit
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT {$select_columns}
                     FROM {$source_table}
                     WHERE {$cutoff_column} < %s
                       AND {$primary_key} > %d
                     ORDER BY {$primary_key} ASC
                     LIMIT %d",
                    $cutoff_value,
                    $last_id,
                    $batch_limit
                ),
                ARRAY_A
            );
        }

        return is_array($rows) ? $rows : [];
    }

    private function has_more_source_rows(
        array $source,
        string $cutoff_value,
        int $last_id,
        int $target_max_primary_key
    ): bool {
        global $wpdb;

        $source_table = (string) ($source['source_table'] ?? '');
        $primary_key = (string) ($source['primary_key'] ?? '');
        $cutoff_column = (string) ($source['cutoff_column'] ?? '');

        if (!$this->is_identifier($source_table)
            || !$this->is_identifier($primary_key)
            || !$this->is_identifier($cutoff_column)
            || $target_max_primary_key <= 0
        ) {
            return false;
        }

        $next_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT {$primary_key}
                 FROM {$source_table}
                 WHERE {$cutoff_column} < %s
                   AND {$primary_key} > %d
                   AND {$primary_key} <= %d
                 ORDER BY {$primary_key} ASC
                 LIMIT 1",
                $cutoff_value,
                max(0, $last_id),
                $target_max_primary_key
            )
        );

        return (int) $next_id > 0;
    }

    protected function build_archive_db_path(): string
    {
        $archive_root = $this->config->get_retention_archive_root();
        $archive_root = rtrim($archive_root, '/\\');
        $year = substr($this->current_time_mysql(), 0, 4);

        return $archive_root . DIRECTORY_SEPARATOR . 'sqlite' . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_' . $year . '.sqlite';
    }

    private function ensure_archive_directory(string $directory): void
    {
        $normalized = str_replace('\\', '/', $directory);

        if (stripos($normalized, '/public_html') !== false) {
            throw new RuntimeException('Retention archive directory must not be inside public_html.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create retention archive directory: ' . $directory);
        }
    }

    private function ensure_archive_schema(PDO $pdo, array $source): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS archive_batches (
                archive_batch_id TEXT PRIMARY KEY,
                source_key TEXT NOT NULL,
                source_table TEXT NOT NULL,
                cutoff_column TEXT NOT NULL,
                cutoff_value TEXT NOT NULL,
                started_at TEXT NOT NULL,
                finished_at TEXT,
                eligible_rows INTEGER NOT NULL DEFAULT 0,
                archived_rows INTEGER NOT NULL DEFAULT 0,
                archive_inserted_rows INTEGER NOT NULL DEFAULT 0,
                archive_duplicate_rows INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT "",
                archive_db_path TEXT NOT NULL DEFAULT "",
                error_message TEXT NOT NULL DEFAULT ""
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS archive_batch_rows (
                archive_batch_id TEXT NOT NULL,
                source_pk INTEGER NOT NULL,
                PRIMARY KEY (archive_batch_id, source_pk)
            )'
        );

        $archive_table = $this->quote_identifier((string) ($source['source_table'] ?? ''));
        $columns_sql = [
            '_archive_batch_id TEXT NOT NULL',
            '_archived_at TEXT NOT NULL',
            '_source_pk INTEGER NOT NULL',
        ];

        foreach ((array) ($source['archive_columns'] ?? []) as $column => $type) {
            if (!$this->is_identifier((string) $column)) {
                continue;
            }

            $sqlite_type = strtoupper((string) $type) === 'INTEGER' ? 'INTEGER' : 'TEXT';
            $columns_sql[] = $this->quote_identifier((string) $column) . ' ' . $sqlite_type;
        }

        $columns_sql[] = 'UNIQUE(_source_pk)';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . $archive_table . ' ('
            . implode(', ', $columns_sql)
            . ')'
        );
    }

    private function start_archive_batch(
        PDO $pdo,
        array $source,
        string $archive_batch_id,
        string $cutoff_value,
        string $archive_db_path,
        bool $reset_batch_rows = true
    ): void {
        $statement = $pdo->prepare(
            'INSERT OR IGNORE INTO archive_batches (
                archive_batch_id,
                source_key,
                source_table,
                cutoff_column,
                cutoff_value,
                started_at,
                status,
                archive_db_path
            ) VALUES (
                :archive_batch_id,
                :source_key,
                :source_table,
                :cutoff_column,
                :cutoff_value,
                :started_at,
                :status,
                :archive_db_path
            )'
        );
        $statement->execute([
            ':archive_batch_id' => $archive_batch_id,
            ':source_key' => (string) ($source['source_key'] ?? ''),
            ':source_table' => (string) ($source['source_table'] ?? ''),
            ':cutoff_column' => (string) ($source['cutoff_column'] ?? ''),
            ':cutoff_value' => $cutoff_value,
            ':started_at' => $this->current_time_mysql(),
            ':status' => 'running',
            ':archive_db_path' => $archive_db_path,
        ]);

        $update_statement = $pdo->prepare(
            'UPDATE archive_batches
             SET source_key = :source_key,
                 source_table = :source_table,
                 cutoff_column = :cutoff_column,
                 cutoff_value = :cutoff_value,
                 started_at = :started_at,
                 finished_at = NULL,
                 status = :status,
                 archive_db_path = :archive_db_path,
                 error_message = :error_message
             WHERE archive_batch_id = :archive_batch_id'
        );
        $update_statement->execute([
            ':source_key' => (string) ($source['source_key'] ?? ''),
            ':source_table' => (string) ($source['source_table'] ?? ''),
            ':cutoff_column' => (string) ($source['cutoff_column'] ?? ''),
            ':cutoff_value' => $cutoff_value,
            ':started_at' => $this->current_time_mysql(),
            ':status' => 'running',
            ':archive_db_path' => $archive_db_path,
            ':error_message' => '',
            ':archive_batch_id' => $archive_batch_id,
        ]);

        if (!$reset_batch_rows) {
            return;
        }

        $reset_statement = $pdo->prepare(
            'UPDATE archive_batches
             SET archived_rows = 0,
                 archive_inserted_rows = 0,
                 archive_duplicate_rows = 0
             WHERE archive_batch_id = :archive_batch_id'
        );
        $reset_statement->execute([
            ':archive_batch_id' => $archive_batch_id,
        ]);

        $delete_statement = $pdo->prepare(
            'DELETE FROM archive_batch_rows WHERE archive_batch_id = :archive_batch_id'
        );
        $delete_statement->execute([
            ':archive_batch_id' => $archive_batch_id,
        ]);
    }

    private function finish_archive_batch(PDO $pdo, string $archive_batch_id, array $result): void
    {
        $statement = $pdo->prepare(
            'UPDATE archive_batches
             SET finished_at = :finished_at,
                 archived_rows = archived_rows + :archived_rows,
                 archive_inserted_rows = archive_inserted_rows + :archive_inserted_rows,
                 archive_duplicate_rows = archive_duplicate_rows + :archive_duplicate_rows,
                 status = :status,
                 error_message = :error_message
             WHERE archive_batch_id = :archive_batch_id'
        );
        $statement->execute([
            ':finished_at' => $this->current_time_mysql(),
            ':archived_rows' => (int) ($result['archived_rows'] ?? 0),
            ':archive_inserted_rows' => (int) ($result['archive_inserted_rows'] ?? 0),
            ':archive_duplicate_rows' => (int) ($result['archive_duplicate_rows'] ?? 0),
            ':status' => !empty($result['success']) ? 'success' : 'failed',
            ':error_message' => (string) ($result['error_message'] ?? ''),
            ':archive_batch_id' => $archive_batch_id,
        ]);
    }

    protected function apply_archive_failure(array $result, Throwable $error): array
    {
        $result['success'] = false;
        $result['error_code'] = 'archive_failed';
        $result['error_message'] = $error->getMessage();

        return $result;
    }

    private function prepare_archive_row_statement(PDO $pdo, array $source): PDOStatement
    {
        $archive_table = $this->quote_identifier((string) ($source['source_table'] ?? ''));
        $columns = ['_archive_batch_id', '_archived_at', '_source_pk'];

        foreach ((array) ($source['archive_columns'] ?? []) as $column => $type) {
            if (!$this->is_identifier((string) $column)) {
                continue;
            }

            $columns[] = (string) $column;
        }

        $quoted_columns = array_map([$this, 'quote_identifier'], $columns);
        $placeholders = array_map(static function (string $column): string {
            return ':' . $column;
        }, $columns);

        return $pdo->prepare(
            'INSERT OR IGNORE INTO ' . $archive_table
            . ' (' . implode(', ', $quoted_columns) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')'
        );
    }

    private function insert_archive_row(
        PDOStatement $statement,
        array $source,
        string $archive_batch_id,
        string $archived_at,
        array $row
    ): bool
    {
        $values = [
            '_archive_batch_id' => $archive_batch_id,
            '_archived_at' => $archived_at,
            '_source_pk' => (int) ($row[(string) ($source['primary_key'] ?? 'id')] ?? 0),
        ];

        foreach ((array) ($source['archive_columns'] ?? []) as $column => $type) {
            if (!$this->is_identifier((string) $column)) {
                continue;
            }

            $values[(string) $column] = $row[(string) $column] ?? null;
        }

        foreach ($values as $column => $value) {
            $statement->bindValue(':' . $column, $value);
        }

        $statement->execute();

        return $statement->rowCount() > 0;
    }

    private function prepare_archive_batch_row_statement(PDO $pdo): PDOStatement
    {
        return $pdo->prepare(
            'INSERT OR IGNORE INTO archive_batch_rows (archive_batch_id, source_pk)
             VALUES (:archive_batch_id, :source_pk)'
        );
    }

    private function insert_archive_batch_row(PDOStatement $statement, string $archive_batch_id, int $source_pk): void
    {
        if ($source_pk <= 0) {
            return;
        }

        $statement->bindValue(':archive_batch_id', $archive_batch_id, PDO::PARAM_STR);
        $statement->bindValue(':source_pk', $source_pk, PDO::PARAM_INT);
        $statement->execute();
    }

    private function quote_identifier(string $identifier): string
    {
        if (!$this->is_identifier($identifier)) {
            throw new InvalidArgumentException('Invalid SQLite identifier: ' . $identifier);
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
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
