<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Kiwi_Retention_Archive_Lock_Handle
{
    private $resource;
    private $lock_path;

    public function __construct($resource, string $lock_path)
    {
        $this->resource = $resource;
        $this->lock_path = $lock_path;
    }

    public function get_lock_path(): string
    {
        return $this->lock_path;
    }

    public function persist_write_blocked(): bool
    {
        if (!is_resource($this->resource)) {
            return false;
        }

        return Kiwi_Retention_Archive_Write_Block::persist($this->lock_path);
    }

    public function is_write_blocked(): bool
    {
        if (!is_resource($this->resource)) {
            return true;
        }

        return Kiwi_Retention_Archive_Write_Block::exists($this->lock_path);
    }

    public function release(): void
    {
        if (!is_resource($this->resource)) {
            return;
        }

        @flock($this->resource, LOCK_UN);
        @fclose($this->resource);
        $this->resource = null;
    }

    public function __destruct()
    {
        $this->release();
    }

    public function clear_write_blocked(): bool
    {
        if (!is_resource($this->resource)) {
            return false;
        }

        return Kiwi_Retention_Archive_Write_Block::clear($this->lock_path);
    }

    public function get_write_block_path(): string
    {
        return Kiwi_Retention_Archive_Write_Block::get_path($this->lock_path);
    }

    public function persist_replacement_transition_blocked(string $source_archive): bool
    {
        if (!is_resource($this->resource)) {
            return false;
        }

        return Kiwi_Retention_Archive_Write_Block::persist_replacement_transition(
            $this->lock_path,
            $source_archive
        );
    }

    public function clear_replacement_transition_blocked(): bool
    {
        if (!is_resource($this->resource)) {
            return false;
        }

        return Kiwi_Retention_Archive_Write_Block::clear_replacement_transition(
            $this->lock_path
        );
    }
}

class Kiwi_Retention_Archive_Lock
{
    public function acquire_for_archive(string $archive_db_path): array
    {
        $archive_db_path = trim($archive_db_path);
        if ($archive_db_path === '' || !$this->is_archive_filename(basename($archive_db_path))) {
            return $this->failure('archive_lock_path_invalid', 'Archive lock path is invalid.');
        }

        return $this->acquire_lock_file($archive_db_path . '.lock');
    }

    public function is_write_blocked_for_archive(string $archive_db_path): ?bool
    {
        $write_block_path = $this->get_write_block_path_for_archive($archive_db_path);
        if ($write_block_path === '') {
            return null;
        }

        clearstatcache(true, $write_block_path);

        return file_exists($write_block_path);
    }

    public function is_replacement_transition_blocked_for_archive(string $archive_db_path): ?bool
    {
        $archive_db_path = trim($archive_db_path);
        if ($archive_db_path === '' || !$this->is_archive_filename(basename($archive_db_path))) {
            return null;
        }

        $path = Kiwi_Retention_Archive_Write_Block::get_replacement_transition_path(
            $archive_db_path . '.lock'
        );
        clearstatcache(true, $path);

        return file_exists($path);
    }

    public function get_replacement_transition_source_for_archive(
        string $archive_db_path
    ): ?string {
        $archive_db_path = trim($archive_db_path);
        if ($archive_db_path === '' || !$this->is_archive_filename(basename($archive_db_path))) {
            return null;
        }

        return Kiwi_Retention_Archive_Write_Block::get_replacement_transition_source(
            $archive_db_path . '.lock'
        );
    }

    public function get_write_block_path_for_archive(string $archive_db_path): string
    {
        $archive_db_path = trim($archive_db_path);
        if ($archive_db_path === '' || !$this->is_archive_filename(basename($archive_db_path))) {
            return '';
        }

        return Kiwi_Retention_Archive_Write_Block::get_path($archive_db_path . '.lock');
    }

    public function release(?Kiwi_Retention_Archive_Lock_Handle $handle): void
    {
        if ($handle instanceof Kiwi_Retention_Archive_Lock_Handle) {
            $handle->release();
        }
    }

    private function acquire_lock_file(
        string $lock_path,
        int $lock_operation = LOCK_EX | LOCK_NB
    ): array
    {
        $directory = dirname($lock_path);
        if (!is_dir($directory) || !is_writable($directory)) {
            return $this->failure(
                'archive_lock_directory_unwritable',
                'Archive lock directory is not writable.'
            );
        }

        $resource = @fopen($lock_path, 'c+');
        if (!is_resource($resource)) {
            return $this->failure('archive_lock_open_failed', 'Archive lock file could not be opened.');
        }

        $locked = @flock($resource, $lock_operation);
        if (!$locked) {
            @fclose($resource);

            return [
                'success' => true,
                'acquired' => false,
                'handle' => null,
                'lock_path' => $lock_path,
                'error_code' => 'archive_lock_active',
                'error_message' => 'Archive generation lock is active.',
            ];
        }

        return [
            'success' => true,
            'acquired' => true,
            'handle' => new Kiwi_Retention_Archive_Lock_Handle($resource, $lock_path),
            'lock_path' => $lock_path,
            'error_code' => '',
            'error_message' => '',
        ];
    }

    private function failure(string $error_code, string $error_message): array
    {
        return [
            'success' => false,
            'acquired' => false,
            'handle' => null,
            'lock_path' => '',
            'error_code' => $error_code,
            'error_message' => $error_message,
        ];
    }

    private function is_archive_filename(string $filename): bool
    {
        return class_exists('Kiwi_Retention_Archive_Name')
            && Kiwi_Retention_Archive_Name::parse($filename) !== null;
    }
}
