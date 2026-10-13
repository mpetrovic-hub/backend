<?php

/** External archive schema preparation; never loaded by web/cron bootstrap. */
class Kiwi_Retention_Archive_Read_Indexes_Migration_Service
{
    private $archive_service;
    private $registry;
    private $lock;
    private $gate;

    public function __construct(
        ?Kiwi_Retention_Sqlite_Archive_Service $archive_service = null,
        ?Kiwi_Retention_Source_Registry $registry = null,
        ?Kiwi_Retention_Archive_Lock $lock = null,
        ?Kiwi_Retention_Corruption_Safety_Gate_Coordinator $gate = null
    ) {
        $this->archive_service = $archive_service ?? new Kiwi_Retention_Sqlite_Archive_Service();
        $this->registry = $registry ?? new Kiwi_Retention_Source_Registry();
        $this->lock = $lock ?? new Kiwi_Retention_Archive_Lock();
        $this->gate = $gate ?? new Kiwi_Retention_Corruption_Safety_Gate_Coordinator();
    }

    public function status(string $archive): array { return $this->run($archive, false); }
    public function apply(string $archive): array { return $this->run($archive, true); }

    private function run(string $archive, bool $apply): array
    {
        $result = ['operation' => $apply ? 'apply' : 'status', 'archive' => $archive,
            'success' => false, 'ready' => false, 'indexes' => [], 'changed' => 0, 'reason_code' => ''];
        $handle = null;
        $reader = new Kiwi_Retention_Archive_Reader($this->archive_service, $this->lock, $this->gate);
        $pdo = null;
        try {
            // Check the transactional deployment prerequisite here, never as
            // a feature-specific schema readiness check from web/cron runtime.
            global $wpdb;
            $engagement_table = $this->registry->get(Kiwi_Retention_Source_Registry::SOURCE_LANDING_SESSION_ENGAGEMENTS)['source_table'];
            $audit_table = $wpdb->prefix . 'kiwi_retention_cleanup_runs';
            $engines = $wpdb->get_results($wpdb->prepare(
                'SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s)',
                $engagement_table, $audit_table
            ), ARRAY_A);
            if ((string) ($wpdb->last_error ?? '') !== '' || !is_array($engines) || count($engines) !== 2) {
                throw new RuntimeException('engagement_transaction_schema_unavailable');
            }
            foreach ($engines as $engine) {
                if (strcasecmp($engine['ENGINE'], 'InnoDB') !== 0) { throw new RuntimeException('engagement_transaction_engine_required'); }
            }
            $result['transaction_tables'] = array_column($engines, 'TABLE_NAME');
            if (Kiwi_Retention_Archive_Name::normalize($archive) !== $archive) {
                throw new RuntimeException('archive_name_invalid');
            }
            $path = $this->archive_service->get_archive_directory() . DIRECTORY_SEPARATOR . $archive;
            $path = $this->archive_service->resolve_existing_archive_db_path_read_only($path);
            $lock = $this->lock->acquire_for_archive($path);
            if (empty($lock['success']) || empty($lock['acquired'])) {
                throw new RuntimeException((string) ($lock['error_code'] ?? 'archive_lock_failed'));
            }
            $handle = $lock['handle'];
            $read_pdo = $reader->open($path, $handle);
            $missing = [];
            foreach ([Kiwi_Retention_Source_Registry::SOURCE_LANDING_PAGE_SESSIONS,
                Kiwi_Retention_Source_Registry::SOURCE_LANDING_SESSION_ENGAGEMENTS] as $key) {
                $source = $this->registry->get($key);
                if (!$reader->has_source($read_pdo, $source, false)) { continue; }
                $names = array_column($read_pdo->query('PRAGMA index_list("' . $source['source_table'] . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
                foreach (Kiwi_Retention_Archive_Reader::read_indexes($source) as $name => $columns) {
                    $matches = Kiwi_Retention_Archive_Reader::index_matches($read_pdo, $source['source_table'], $name, $columns);
                    $exists = in_array($name, $names, true);
                    $result['indexes'][$name] = ['source_key' => $key, 'columns' => $columns,
                        'definition' => Kiwi_Retention_Archive_Reader::read_index_columns_sql($columns),
                        'ready' => $matches, 'replacement_required' => $exists && !$matches];
                    if (!$matches) {
                        $missing[] = ['table' => $source['source_table'], 'name' => $name, 'columns' => $columns, 'replace' => $exists];
                    }
                }
            }
            $read_pdo = null;
            $reader->close();
            if ($apply && $missing) {
                // Space check before opening for writes. Keep a reserve at least
                // as large as the archive plus 64 MiB; no partial fallback scan.
                $space = disk_free_space(dirname($path));
                if ($space === false || $space < filesize($path) + 64 * 1024 * 1024) {
                    throw new RuntimeException('archive_read_index_space_insufficient');
                }
                if (empty($this->gate->inspect($path, false)['allowed'])) {
                    throw new RuntimeException('archive_read_index_corruption_gate');
                }
                $pdo = new PDO('sqlite:' . $path);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec('PRAGMA cache_size=-8192');
                $pdo->exec('PRAGMA mmap_size=0');
                $pdo->beginTransaction();
                foreach ($missing as $index) {
                    if (empty($this->gate->inspect($path, false)['allowed'])) { throw new RuntimeException('archive_read_index_corruption_gate'); }
                    // Only this approved read index may be replaced, atomically.
                    // Payloads, receipts and the source-PK unique index stay intact.
                    if ($index['replace']) { $pdo->exec('DROP INDEX "' . $index['name'] . '"'); }
                    $pdo->exec('CREATE INDEX "' . $index['name'] . '" ON "' . $index['table'] . '" ('
                        . Kiwi_Retention_Archive_Reader::read_index_columns_sql($index['columns']) . ')');
                    if (!Kiwi_Retention_Archive_Reader::index_matches($pdo, $index['table'], $index['name'], $index['columns'])) {
                        throw new RuntimeException('archive_read_index_postcondition_failed');
                    }
                    $result['indexes'][$index['name']]['ready'] = true;
                    $result['indexes'][$index['name']]['replacement_required'] = false;
                    $result['changed']++;
                }
                $pdo->commit();
            }
            $result['ready'] = $apply || count($missing) === 0;
            $result['success'] = true;
            $replacements = array_filter($missing, static function (array $index): bool { return $index['replace']; });
            $result['reason_code'] = $result['ready'] ? 'archive_read_indexes_ready'
                : ($replacements ? 'archive_read_index_definition_mismatch' : 'archive_read_indexes_missing');
        } catch (Throwable $error) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
            $result['changed'] = 0;
            $result['reason_code'] = preg_match('/^[a-z][a-z0-9_]*$/D', $error->getMessage()) ? $error->getMessage() : 'archive_read_index_preparation_failed';
        } finally {
            $pdo = null;
            $reader->close();
            $this->lock->release($handle);
        }
        return $result;
    }
}
