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

    public function acquire_controller(string $archive_directory): array
    {
        $archive_directory = rtrim(trim($archive_directory), '/\\');
        if ($archive_directory === '' || !is_dir($archive_directory)) {
            return $this->failure(
                'archive_controller_lock_directory_invalid',
                'Archive controller lock directory is unavailable.'
            );
        }

        return $this->acquire_lock_file(
            $archive_directory . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_health_controller.lock'
        );
    }

    public function release(?Kiwi_Retention_Archive_Lock_Handle $handle): void
    {
        if ($handle instanceof Kiwi_Retention_Archive_Lock_Handle) {
            $handle->release();
        }
    }

    private function acquire_lock_file(string $lock_path): array
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

        $locked = @flock($resource, LOCK_EX | LOCK_NB);
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
        return preg_match(
            '/^kiwi_retention_archive_[0-9]{4}(?:_part_(?:[2-9]|[1-9][0-9]+))?\.sqlite$/',
            $filename
        ) === 1;
    }
}
