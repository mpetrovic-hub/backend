<?php

final class Kiwi_Retention_Archive_Write_Block
{
    private const SENTINEL = "kiwi_retention_archive_write_blocked_v1\n";
    private const SUFFIX = '.write-blocked';

    public static function get_path(string $lock_path): string
    {
        $lock_path = trim($lock_path);

        return $lock_path !== '' ? $lock_path . self::SUFFIX : '';
    }

    public static function persist(string $lock_path): bool
    {
        $path = self::get_path($lock_path);
        if ($path === '') {
            return false;
        }

        $resource = @fopen($path, 'c+b');
        if (!is_resource($resource)) {
            return false;
        }

        try {
            return @rewind($resource)
                && @ftruncate($resource, 0)
                && @fwrite($resource, self::SENTINEL) === strlen(self::SENTINEL)
                && @fflush($resource)
                && (!function_exists('fsync') || @fsync($resource));
        } finally {
            @fclose($resource);
        }
    }

    public static function exists(string $lock_path): bool
    {
        $path = self::get_path($lock_path);
        if ($path === '') {
            return true;
        }

        clearstatcache(true, $path);

        return file_exists($path);
    }

    public static function clear(string $lock_path): bool
    {
        $path = self::get_path($lock_path);
        if ($path === '') {
            return false;
        }

        clearstatcache(true, $path);

        return !file_exists($path) || @unlink($path);
    }
}
