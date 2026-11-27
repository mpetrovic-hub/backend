<?php

/** Explicit external SMS-history publication; never loaded by web/cron bootstrap. */
final class Kiwi_Retention_Sms_Archive_Publication_Service
{
    private $archives;
    private $runs;
    private $locks;
    private $health;
    private $gate;

    public function __construct(
        ?Kiwi_Retention_Sqlite_Archive_Service $archives = null,
        ?Kiwi_Retention_Cleanup_Run_Repository $runs = null,
        ?Kiwi_Retention_Archive_Lock $locks = null,
        ?Kiwi_Retention_Archive_Health_Store $health = null,
        ?Kiwi_Retention_Corruption_Safety_Gate_Coordinator $gate = null
    ) {
        $this->archives = $archives ?? new Kiwi_Retention_Sqlite_Archive_Service();
        $this->runs = $runs ?? new Kiwi_Retention_Cleanup_Run_Repository();
        $this->locks = $locks ?? new Kiwi_Retention_Archive_Lock();
        $this->health = $health ?? new Kiwi_Retention_Archive_Health_Store();
        $this->gate = $gate ?? new Kiwi_Retention_Corruption_Safety_Gate_Coordinator();
    }

    /** Publish a closed, independently verified staging file; explicit replacement preserves the old file and every gate. */
    public function publish(string $prepared_path, string $destination_path, bool $replace = false): array
    {
        global $wpdb;
        $source = Kiwi_Retention_Source_Registry::SOURCE_SMS_BODY_VARIANT_ASSIGNMENTS;
        $source_lock = null;
        $handles = [];
        $published = false;
        $backup = null;
        try {
            $destination_path = $this->archives->resolve_existing_archive_db_path_read_only($destination_path);
            $prepared_basis = $this->check_prepared_file($prepared_path, $destination_path, $replace);
            $destination_basis = is_file($destination_path) ? $this->destination_basis($destination_path) : null;
            $connection = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
            if ($connection < 1 || (string) $wpdb->last_error !== '') { throw new RuntimeException('sms_publication_connection_failed'); }
            $source_lock = $this->runs->acquire_source_creation_lock($source);
            if ($source_lock === null) { throw new RuntimeException('sms_source_lock_active'); }
            $paths = array_column($this->archives->list_archive_files(), 'path');
            $paths[] = $destination_path;
            $paths = array_values(array_unique($paths));
            usort($paths, [Kiwi_Retention_Archive_Name::class, 'compare_paths']);
            foreach ($paths as $path) {
                $lock = $this->locks->acquire_for_archive($path);
                if (empty($lock['acquired'])) { throw new RuntimeException('sms_publication_generation_lock_active'); }
                $handles[] = $lock['handle'];
            }
            $pending = $this->health->pending_gate();
            if (empty($pending['allowed'])) { throw new RuntimeException('sms_publication_health_pending'); }
            $gate = $this->gate->inspect($destination_path, false);
            if (empty($gate['allowed']) && (!$replace || !in_array($gate['reason_code'] ?? '',
                ['archive_corruption_write_blocked', 'archive_corruption_incident_open', 'replacement_transition_write_blocked'], true))) {
                throw new RuntimeException('sms_publication_destination_protected');
            }
            // All preparation is outside this short publication boundary. Recheck paths, sidecars and the original lease now.
            if ($this->check_prepared_file($prepared_path, $destination_path, $replace) !== $prepared_basis) {
                throw new RuntimeException('sms_publication_staging_changed');
            }
            $current = is_file($destination_path) ? $this->destination_basis($destination_path) : null;
            if ($current !== $destination_basis) { throw new RuntimeException('sms_publication_destination_changed'); }
            $this->assert_owner($connection, $source, $source_lock);
            if ($destination_basis !== null) {
                $backup = $destination_path . '.before-restore-' . bin2hex(random_bytes(12));
                if (!@link($destination_path, $backup)) { throw new RuntimeException('sms_publication_backup_failed'); }
                $this->assert_owner($connection, $source, $source_lock);
                if (!@rename($prepared_path, $destination_path)) { throw new RuntimeException('sms_publication_replace_failed'); }
            } elseif (!@link($prepared_path, $destination_path)) {
                throw new RuntimeException('sms_publication_destination_conflict');
            }
            $published = true;
            $this->assert_owner($connection, $source, $source_lock);
            $staging_retained = $destination_basis === null && !@unlink($prepared_path);
            return ['success'=>true, 'published'=>true, 'staging_retained'=>$staging_retained,
                'backup_archive_path'=>$backup, 'archive_db_path'=>$destination_path, 'reason_code'=>''];
        } catch (Throwable $error) {
            $reason = $error->getMessage();
            return ['success'=>false, 'published'=>$published, 'archive_db_path'=>$destination_path,
                'backup_archive_path'=>$backup,
                'reason_code'=>str_starts_with($reason, 'sms_') ? $reason : 'sms_publication_failed'];
        } finally {
            foreach (array_reverse($handles) as $handle) { $this->locks->release($handle); }
            if (is_string($source_lock)) { $this->runs->release_source_creation_lock($source_lock); }
        }
    }

    private function assert_owner(int $connection, string $source, string $lock): void
    {
        global $wpdb;
        $owned = $wpdb->get_var($wpdb->prepare('SELECT CONNECTION_ID()=%d AND IS_USED_LOCK(%s)=CONNECTION_ID()', $connection, $lock));
        if ((string) $wpdb->last_error !== '' || (int) $owned !== 1 || !$this->runs->owns_source_creation_lock($source, $lock)) {
            throw new RuntimeException('sms_publication_source_lock_lost');
        }
    }

    private function check_prepared_file(string $prepared, string $destination, bool $replace): array
    {
        clearstatcache(true, $prepared);
        $directory = realpath($this->archives->get_archive_directory());
        if ($directory === false || realpath(dirname($prepared)) !== $directory
            || realpath(dirname($destination)) !== $directory || Kiwi_Retention_Archive_Name::parse(basename($prepared)) !== null
            || is_link($prepared) || !is_file($prepared) || !is_readable($prepared)) {
            throw new RuntimeException('sms_publication_staging_invalid');
        }
        clearstatcache(true, $destination);
        if (is_link($destination) || (file_exists($destination) && (!$replace || !is_file($destination)))) {
            throw new RuntimeException('sms_publication_destination_exists');
        }
        foreach ([$prepared, $destination] as $path) {
            foreach (['-wal', '-journal', '-shm'] as $suffix) {
                clearstatcache(true, $path . $suffix);
                if (file_exists($path . $suffix) || is_link($path . $suffix)) { throw new RuntimeException('sms_publication_sidecar_present'); }
            }
        }
        $stream = @fopen($prepared, 'rb');
        if ($stream === false) { throw new RuntimeException('sms_publication_staging_unreadable'); }
        try {
            $header = fread($stream, 100);
            if (!is_string($header) || strlen($header) !== 100 || substr($header, 0, 16) !== "SQLite format 3\0") {
                throw new RuntimeException('sms_publication_staging_invalid');
            }
            $opened = fstat($stream);
            $identity = $this->file_identity($prepared);
            if ($opened === false || array_intersect_key($opened, $identity) !== $identity) {
                throw new RuntimeException('sms_publication_staging_changed');
            }
            return $identity + ['header'=>bin2hex($header)];
        } finally { fclose($stream); }
    }

    private function file_identity(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false) { throw new RuntimeException('sms_publication_file_unreadable'); }
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
    }

    /** Raw bounded bytes also detect same-size SQLite commits; an old corrupt or empty file remains replaceable. */
    private function destination_basis(string $path): array
    {
        $header = @file_get_contents($path, false, null, 0, 100);
        if ($header === false) { throw new RuntimeException('sms_publication_file_unreadable'); }
        return $this->file_identity($path) + ['header'=>bin2hex($header)];
    }
}
