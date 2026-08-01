<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Retention_Archive_Discovery_Exception extends RuntimeException
{
}

class Kiwi_Retention_Sqlite_Archive_Service
{
    private $config;

    public function __construct(?Kiwi_Config $config = null)
    {
        $this->config = $config instanceof Kiwi_Config ? $config : new Kiwi_Config();
    }

    public function get_archive_directory(): string
    {
        return rtrim($this->config->get_retention_archive_root(), '/\\')
            . DIRECTORY_SEPARATOR
            . 'sqlite';
    }

    public function resolve_archive_db_path(string $existing_archive_db_path = ''): string
    {
        $this->ensure_archive_directory($this->get_archive_directory());

        if ($existing_archive_db_path !== '') {
            if (!$this->is_safe_archive_path($existing_archive_db_path)) {
                throw new RuntimeException('Persisted retention archive path is outside the configured archive directory.');
            }

            return $existing_archive_db_path;
        }

        $year = substr($this->current_time_mysql(), 0, 4);
        $archives = $this->list_archive_files();
        $highest_generation = 0;
        $highest_path = '';

        foreach ($archives as $archive) {
            if ((string) ($archive['year'] ?? '') !== $year) {
                continue;
            }

            $generation = (int) ($archive['generation'] ?? 0);
            if ($generation > $highest_generation) {
                $highest_generation = $generation;
                $highest_path = (string) ($archive['path'] ?? '');
            }
        }

        if ($highest_generation <= 0) {
            return $this->build_generation_path($year, 1);
        }

        if ($highest_path !== '') {
            if (!$this->is_quarantined($highest_path)) {
                return $highest_path;
            }
            if (!$this->is_quarantine_reconciled($highest_path)) {
                return $highest_path;
            }
        }

        return $this->build_generation_path($year, $highest_generation + 1);
    }

    public function resolve_existing_archive_db_path_read_only(string $archive_db_path): string
    {
        if (!$this->is_safe_archive_path($archive_db_path)) {
            throw new RuntimeException('Persisted retention archive path is outside the configured archive directory.');
        }

        return $archive_db_path;
    }

    public function resolve_quarantine_successor_path(string $quarantined_archive_db_path): string
    {
        $this->ensure_archive_directory($this->get_archive_directory());
        if (!$this->is_quarantined($quarantined_archive_db_path)) {
            throw new RuntimeException('Retention archive quarantine successor requires a quarantined archive.');
        }

        $identity = $this->parse_generation_filename(basename($quarantined_archive_db_path));
        if ($identity === null) {
            throw new RuntimeException('Retention archive quarantine generation is invalid.');
        }

        return $this->build_generation_path(
            (string) $identity['year'],
            (int) $identity['generation'] + 1
        );
    }

    public function list_archive_files(): array
    {
        $directory = $this->get_archive_directory();
        if (!is_dir($directory)) {
            return [];
        }

        $paths = @glob($directory . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_*.sqlite');
        if ($paths === false) {
            throw new Kiwi_Retention_Archive_Discovery_Exception('archive_discovery_failed');
        }
        $archives = [];

        foreach ($paths as $path) {
            if (!is_file($path) || is_link($path) || !$this->is_safe_archive_path($path)) {
                continue;
            }

            $identity = $this->parse_generation_filename(basename($path));
            if ($identity === null) {
                continue;
            }

            $archives[] = array_merge($identity, [
                'name' => basename($path),
                'path' => $path,
                'quarantined' => $this->is_quarantined($path),
                'size_bytes' => (int) (@filesize($path) ?: 0),
            ]);
        }

        usort($archives, static function (array $left, array $right): int {
            $year_compare = strcmp((string) ($left['year'] ?? ''), (string) ($right['year'] ?? ''));
            if ($year_compare !== 0) {
                return $year_compare;
            }

            return (int) ($left['generation'] ?? 0) <=> (int) ($right['generation'] ?? 0);
        });

        return $archives;
    }

    public function find_quarantined_predecessor(string $successor_archive_db_path): ?array
    {
        if (!$this->is_safe_archive_path($successor_archive_db_path)) {
            throw new InvalidArgumentException('Retention successor archive path is invalid.');
        }

        $successor = $this->parse_generation_filename(basename($successor_archive_db_path));
        if (!is_array($successor) || (int) ($successor['generation'] ?? 0) <= 1) {
            return null;
        }

        $predecessor_generation = (int) $successor['generation'] - 1;
        foreach ($this->list_archive_files() as $archive) {
            if ((string) ($archive['year'] ?? '') === (string) ($successor['year'] ?? '')
                && (int) ($archive['generation'] ?? 0) === $predecessor_generation
                && !empty($archive['quarantined'])
            ) {
                return $archive;
            }
        }

        return null;
    }

    public function is_quarantined(string $archive_db_path): bool
    {
        return $this->is_safe_archive_path($archive_db_path)
            && is_file($this->get_quarantine_marker_path($archive_db_path));
    }

    public function get_quarantine_marker_path(string $archive_db_path): string
    {
        if (!$this->is_safe_archive_path($archive_db_path)) {
            throw new RuntimeException('Retention archive quarantine path is invalid.');
        }

        return $archive_db_path . '.quarantine.json';
    }

    public function mark_quarantined(string $archive_db_path, array $details): bool
    {
        if (!is_file($archive_db_path) || !$this->is_safe_archive_path($archive_db_path)) {
            return false;
        }

        $marker_path = $this->get_quarantine_marker_path($archive_db_path);
        if (is_file($marker_path)) {
            return true;
        }

        $payload = [
            'schema_version' => 1,
            'archive' => basename($archive_db_path),
            'detected_at' => (string) ($details['detected_at'] ?? $this->current_time_mysql()),
            'check' => (string) ($details['check'] ?? ''),
            'reason_code' => (string) ($details['reason_code'] ?? 'sqlite_corruption_confirmed'),
            'active_generation' => !empty($details['active_generation']),
        ];
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($payload)
            : json_encode($payload);
        if (!is_string($json)) {
            return false;
        }

        return $this->write_atomic_file($marker_path, $json . "\n");
    }

    public function mark_quarantine_reconciled(string $archive_db_path, string $recorded_at): bool
    {
        if (!$this->is_quarantined($archive_db_path) || trim($recorded_at) === '') {
            return false;
        }

        try {
            $timestamp = new DateTimeImmutable($recorded_at);
        } catch (Throwable $error) {
            return false;
        }
        if ($timestamp->format(DATE_ATOM) !== $recorded_at) {
            return false;
        }

        $marker_path = $this->get_quarantine_marker_path($archive_db_path);
        $raw = @file_get_contents($marker_path);
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($payload)
            || (int) ($payload['schema_version'] ?? 0) !== 1
            || (string) ($payload['archive'] ?? '') !== basename($archive_db_path)
        ) {
            return false;
        }
        $existing_recorded_at = trim((string) ($payload['controller_recorded_at'] ?? ''));
        if ($existing_recorded_at !== '') {
            try {
                $existing_timestamp = new DateTimeImmutable($existing_recorded_at);
            } catch (Throwable $error) {
                return false;
            }

            return $existing_timestamp->format(DATE_ATOM) === $existing_recorded_at;
        }

        $payload['controller_recorded_at'] = $recorded_at;
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($payload)
            : json_encode($payload);

        return is_string($json) && $this->write_atomic_file($marker_path, $json . "\n");
    }

    public function is_quarantine_reconciled(string $archive_db_path): bool
    {
        if (!$this->is_quarantined($archive_db_path)) {
            return false;
        }
        $raw = @file_get_contents($this->get_quarantine_marker_path($archive_db_path));
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        $recorded_at = is_array($payload)
            ? trim((string) ($payload['controller_recorded_at'] ?? ''))
            : '';
        if ($recorded_at === '') {
            return false;
        }
        try {
            $timestamp = new DateTimeImmutable($recorded_at);
        } catch (Throwable $error) {
            return false;
        }

        return $timestamp->format(DATE_ATOM) === $recorded_at;
    }

    public function get_relative_archive_name(string $archive_db_path): string
    {
        return $this->is_safe_archive_path($archive_db_path) ? basename($archive_db_path) : '';
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

            $result['archive_integrity_check'] = 'deferred_to_external_health_runner';
            $result['success'] = true;

            $this->finish_archive_batch($pdo, $source, $archive_batch_id, $result);
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

    public function verify_batch_receipt(
        array $source,
        string $archive_db_path,
        string $archive_batch_id,
        array $expected_primary_keys
    ): array {
        $expected_primary_keys = $this->normalize_primary_keys($expected_primary_keys);
        $result = [
            'success' => false,
            'archive_batch_id' => $archive_batch_id,
            'primary_keys' => [],
            'expected_count' => count($expected_primary_keys),
            'receipt_count' => 0,
            'archive_row_count' => 0,
            'archive_inserted_count' => 0,
            'archive_duplicate_count' => 0,
            'last_primary_key' => empty($expected_primary_keys) ? 0 : max($expected_primary_keys),
            'error_code' => '',
            'error_message' => '',
        ];

        if (!class_exists('PDO')
            || $archive_batch_id === ''
            || empty($expected_primary_keys)
            || !is_file($archive_db_path)
            || !$this->is_safe_archive_path($archive_db_path)
        ) {
            $result['error_code'] = 'archive_receipt_input_invalid';
            $result['error_message'] = 'Archive receipt verification input is invalid.';

            return $result;
        }

        $source_table = (string) ($source['source_table'] ?? '');
        if (!$this->is_identifier($source_table)) {
            $result['error_code'] = 'archive_receipt_source_invalid';
            $result['error_message'] = 'Archive receipt source definition is invalid.';

            return $result;
        }

        try {
            $pdo = new PDO('sqlite:' . $archive_db_path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $batch_statement = $pdo->prepare(
                'SELECT source_key, source_table
                 FROM archive_batches
                 WHERE archive_batch_id = :archive_batch_id
                 LIMIT 1'
            );
            $batch_statement->execute([':archive_batch_id' => $archive_batch_id]);
            $batch = $batch_statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($batch)
                || (string) ($batch['source_key'] ?? '') !== (string) ($source['source_key'] ?? '')
                || (string) ($batch['source_table'] ?? '') !== $source_table
            ) {
                $result['error_code'] = 'archive_receipt_batch_identity_mismatch';
                $result['error_message'] = 'Archive receipt batch identity does not match the cleanup source.';

                return $result;
            }

            $receipt_primary_keys = [];
            $archive_primary_keys = [];
            $archive_inserted_count = 0;
            $archive_table = $this->quote_identifier($source_table);

            foreach (array_chunk($expected_primary_keys, 500) as $chunk) {
                $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
                $receipt_statement = $pdo->prepare(
                    'SELECT source_pk
                     FROM archive_batch_rows
                     WHERE archive_batch_id = ?
                       AND source_pk IN (' . $placeholders . ')
                     ORDER BY source_pk ASC'
                );
                $receipt_statement->execute(array_merge([$archive_batch_id], $chunk));
                $receipt_primary_keys = array_merge(
                    $receipt_primary_keys,
                    array_map('intval', $receipt_statement->fetchAll(PDO::FETCH_COLUMN))
                );

                $archive_statement = $pdo->prepare(
                    'SELECT _source_pk, _archive_batch_id
                     FROM ' . $archive_table . '
                     WHERE _source_pk IN (' . $placeholders . ')
                     ORDER BY _source_pk ASC'
                );
                $archive_statement->execute($chunk);
                foreach ($archive_statement->fetchAll(PDO::FETCH_ASSOC) as $archive_row) {
                    $archive_primary_keys[] = (int) ($archive_row['_source_pk'] ?? 0);

                    if ((string) ($archive_row['_archive_batch_id'] ?? '') === $archive_batch_id) {
                        $archive_inserted_count++;
                    }
                }
            }

            $receipt_primary_keys = $this->normalize_primary_keys($receipt_primary_keys);
            $archive_primary_keys = $this->normalize_primary_keys($archive_primary_keys);
            $result['primary_keys'] = $receipt_primary_keys;
            $result['receipt_count'] = count($receipt_primary_keys);
            $result['archive_row_count'] = count($archive_primary_keys);
            $result['archive_inserted_count'] = $archive_inserted_count;
            $result['archive_duplicate_count'] = count($archive_primary_keys) - $archive_inserted_count;

            if ($receipt_primary_keys !== $expected_primary_keys
                || $archive_primary_keys !== $expected_primary_keys
            ) {
                $result['error_code'] = 'archive_receipt_mismatch';
                $result['error_message'] = 'Persisted archive receipt IDs or archive rows do not match the expected chunk.';

                return $result;
            }

            $result['success'] = true;

            return $result;
        } catch (Throwable $error) {
            $result['error_code'] = 'archive_receipt_read_failed';
            $result['error_message'] = 'Persisted archive receipt could not be read safely.';

            return $result;
        }
    }

    public function fetch_verified_receipt_batch(
        array $source,
        string $archive_db_path,
        string $archive_batch_id,
        int $last_primary_key,
        int $through_primary_key,
        int $batch_limit
    ): array {
        $result = [
            'success' => false,
            'primary_keys' => [],
            'last_primary_key' => max(0, $last_primary_key),
            'has_more' => false,
            'archive_inserted_count' => -1,
            'archive_duplicate_count' => -1,
            'error_code' => '',
            'error_message' => '',
        ];

        if ($through_primary_key <= $last_primary_key) {
            $result['success'] = true;

            return $result;
        }

        try {
            $primary_keys = $this->fetch_archived_primary_key_batch(
                $source,
                $archive_db_path,
                $archive_batch_id,
                $last_primary_key,
                $batch_limit
            );
            $primary_keys = array_values(array_filter(
                $primary_keys,
                static function (int $primary_key) use ($through_primary_key): bool {
                    return $primary_key <= $through_primary_key;
                }
            ));

            if (empty($primary_keys)) {
                $result['error_code'] = 'archive_receipt_progress_missing';
                $result['error_message'] = 'Persisted archive receipt does not cover audited archive progress.';

                return $result;
            }

            $verified = $this->verify_batch_receipt(
                $source,
                $archive_db_path,
                $archive_batch_id,
                $primary_keys
            );
            if (empty($verified['success'])) {
                return array_merge($result, [
                    'error_code' => (string) ($verified['error_code'] ?? 'archive_receipt_mismatch'),
                    'error_message' => (string) ($verified['error_message'] ?? 'Archive receipt verification failed.'),
                ]);
            }

            $result['success'] = true;
            $result['primary_keys'] = $primary_keys;
            $result['last_primary_key'] = max($primary_keys);
            $result['has_more'] = $result['last_primary_key'] < $through_primary_key;
            $result['archive_inserted_count'] = (int) (
                $verified['archive_inserted_count'] ?? -1
            );
            $result['archive_duplicate_count'] = (int) (
                $verified['archive_duplicate_count'] ?? -1
            );

            return $result;
        } catch (Throwable $error) {
            $result['error_code'] = 'archive_receipt_read_failed';
            $result['error_message'] = 'Persisted archive receipt could not be read safely.';

            return $result;
        }
    }

    public function archive_primary_key_chunk(
        array $source,
        string $cutoff_value,
        string $archive_batch_id,
        int $last_primary_key,
        int $target_max_primary_key,
        int $batch_limit,
        int $time_limit_seconds,
        string $archive_db_path = ''
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
            'receipt_status' => '',
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
            $archive_db_path = $archive_db_path !== '' ? $archive_db_path : $this->build_archive_db_path();
            $result['archive_db_path'] = $archive_db_path;
            $this->ensure_archive_directory(dirname($archive_db_path));
            if (!$this->is_safe_archive_path($archive_db_path)) {
                throw new RuntimeException('Retention archive path is outside the configured archive directory.');
            }

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

            $result['receipt_status'] = 'pending_verification';
            $result['success'] = true;

            $result['has_more'] = $this->has_more_source_rows(
                $source,
                $cutoff_value,
                (int) $result['last_primary_key'],
                $target_max_primary_key
            );

            $this->finish_archive_batch($pdo, $source, $archive_batch_id, $result);
        } catch (Throwable $error) {
            if ($transaction_started && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $result = $this->apply_archive_failure($result, $error);
        }

        return $result;
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
        return $this->resolve_archive_db_path();
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

    private function finish_archive_batch(PDO $pdo, array $source, string $archive_batch_id, array $result): void
    {
        $evidence_counts = $this->read_batch_evidence_counts($pdo, $source, $archive_batch_id);
        $statement = $pdo->prepare(
            'UPDATE archive_batches
             SET finished_at = :finished_at,
                 archived_rows = :archived_rows,
                 archive_inserted_rows = :archive_inserted_rows,
                 archive_duplicate_rows = :archive_duplicate_rows,
                 status = :status,
                 error_message = :error_message
             WHERE archive_batch_id = :archive_batch_id'
        );
        $statement->execute([
            ':finished_at' => $this->current_time_mysql(),
            ':archived_rows' => $evidence_counts['archived_rows'],
            ':archive_inserted_rows' => $evidence_counts['archive_inserted_rows'],
            ':archive_duplicate_rows' => $evidence_counts['archive_duplicate_rows'],
            ':status' => !empty($result['success']) ? 'success' : 'failed',
            ':error_message' => (string) ($result['error_message'] ?? ''),
            ':archive_batch_id' => $archive_batch_id,
        ]);
    }

    private function read_batch_evidence_counts(PDO $pdo, array $source, string $archive_batch_id): array
    {
        $archive_table = $this->quote_identifier((string) ($source['source_table'] ?? ''));
        $statement = $pdo->prepare(
            'SELECT COUNT(*) AS archived_rows,
                    SUM(CASE WHEN archive_row._archive_batch_id = :inserted_batch_id THEN 1 ELSE 0 END) AS archive_inserted_rows
             FROM archive_batch_rows AS batch_row
             INNER JOIN ' . $archive_table . ' AS archive_row
                     ON archive_row._source_pk = batch_row.source_pk
             WHERE batch_row.archive_batch_id = :receipt_batch_id'
        );
        $statement->execute([
            ':inserted_batch_id' => $archive_batch_id,
            ':receipt_batch_id' => $archive_batch_id,
        ]);
        $counts = $statement->fetch(PDO::FETCH_ASSOC);
        $archived_rows = (int) ($counts['archived_rows'] ?? 0);
        $archive_inserted_rows = (int) ($counts['archive_inserted_rows'] ?? 0);

        return [
            'archived_rows' => $archived_rows,
            'archive_inserted_rows' => $archive_inserted_rows,
            'archive_duplicate_rows' => max(0, $archived_rows - $archive_inserted_rows),
        ];
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

    private function build_generation_path(string $year, int $generation): string
    {
        if (preg_match('/^[0-9]{4}$/', $year) !== 1) {
            throw new InvalidArgumentException('Retention archive year is invalid.');
        }

        $generation = max(1, $generation);
        $suffix = $generation === 1 ? '' : '_part_' . $generation;

        return $this->get_archive_directory()
            . DIRECTORY_SEPARATOR
            . 'kiwi_retention_archive_'
            . $year
            . $suffix
            . '.sqlite';
    }

    private function parse_generation_filename(string $filename): ?array
    {
        if (preg_match(
            '/^kiwi_retention_archive_([0-9]{4})(?:_part_([2-9]|[1-9][0-9]+))?\.sqlite$/',
            $filename,
            $matches
        ) !== 1) {
            return null;
        }

        return [
            'year' => (string) $matches[1],
            'generation' => isset($matches[2]) ? (int) $matches[2] : 1,
        ];
    }

    private function is_safe_archive_path(string $archive_db_path): bool
    {
        $archive_db_path = trim($archive_db_path);
        $identity = $this->parse_generation_filename(basename($archive_db_path));
        if ($archive_db_path === '' || $identity === null) {
            return false;
        }

        $archive_directory = $this->get_archive_directory();
        $archive_directory_real = realpath($archive_directory);
        $path_directory_real = realpath(dirname($archive_db_path));

        if (!is_string($archive_directory_real)
            || !is_string($path_directory_real)
            || rtrim($archive_directory_real, '/\\') !== rtrim($path_directory_real, '/\\')
        ) {
            return false;
        }

        return !is_link($archive_db_path);
    }

    private function write_atomic_file(string $target_path, string $contents): bool
    {
        $directory = dirname($target_path);
        if (!is_dir($directory) || !is_writable($directory)) {
            return false;
        }

        try {
            $suffix = function_exists('random_bytes')
                ? bin2hex(random_bytes(8))
                : substr(md5(uniqid('', true)), 0, 16);
        } catch (Throwable $error) {
            $suffix = substr(md5(uniqid('', true)), 0, 16);
        }

        $temporary_path = $target_path . '.tmp.' . $suffix;
        $written = @file_put_contents($temporary_path, $contents, LOCK_EX);
        if ($written === false || $written !== strlen($contents)) {
            @unlink($temporary_path);

            return false;
        }

        if (!@rename($temporary_path, $target_path)) {
            @unlink($temporary_path);

            return false;
        }

        return true;
    }

    private function normalize_primary_keys(array $primary_keys): array
    {
        $primary_keys = array_values(array_unique(array_filter(
            array_map('intval', $primary_keys),
            static function (int $primary_key): bool {
                return $primary_key > 0;
            }
        )));
        sort($primary_keys, SORT_NUMERIC);

        return $primary_keys;
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
