<?php

final class Kiwi_Retention_Archive_Write_Block
{
    private const SENTINEL = "kiwi_retention_archive_write_blocked_v1\n";
    private const SUFFIX = '.write-blocked';
    private const REPLACEMENT_TRANSITION_PREFIX = 'kiwi_retention_archive_replacement_transition_blocked_v1:';
    private const REPLACEMENT_TRANSITION_SUFFIX = '.replacement-transition-blocked';

    public static function get_path(string $lock_path): string
    {
        $lock_path = trim($lock_path);

        return $lock_path !== '' ? $lock_path . self::SUFFIX : '';
    }

    public static function persist(string $lock_path): bool
    {
        return self::persist_marker(self::get_path($lock_path), self::SENTINEL);
    }

    public static function get_replacement_transition_path(string $lock_path): string
    {
        $lock_path = trim($lock_path);

        return $lock_path !== '' ? $lock_path . self::REPLACEMENT_TRANSITION_SUFFIX : '';
    }

    public static function persist_replacement_transition(
        string $lock_path,
        string $source_archive
    ): bool
    {
        $source_archive = class_exists('Kiwi_Retention_Archive_Name')
            ? Kiwi_Retention_Archive_Name::normalize($source_archive)
            : '';
        if ($source_archive === '') {
            return false;
        }

        return self::persist_marker(
            self::get_replacement_transition_path($lock_path),
            self::REPLACEMENT_TRANSITION_PREFIX . $source_archive . "\n"
        );
    }

    private static function persist_marker(string $path, string $sentinel): bool
    {
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
                && @fwrite($resource, $sentinel) === strlen($sentinel)
                && @fflush($resource)
                && (!function_exists('fsync') || @fsync($resource));
        } finally {
            @fclose($resource);
        }
    }

    public static function exists(string $lock_path): bool
    {
        return self::marker_exists(self::get_path($lock_path));
    }

    public static function replacement_transition_exists(string $lock_path): bool
    {
        return self::marker_exists(self::get_replacement_transition_path($lock_path));
    }

    public static function get_replacement_transition_source(string $lock_path): ?string
    {
        $path = self::get_replacement_transition_path($lock_path);
        if ($path === '') {
            return null;
        }
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            return '';
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents)
            || strpos($contents, self::REPLACEMENT_TRANSITION_PREFIX) !== 0
        ) {
            return null;
        }
        $source_archive = trim(substr($contents, strlen(self::REPLACEMENT_TRANSITION_PREFIX)));

        return class_exists('Kiwi_Retention_Archive_Name')
            && Kiwi_Retention_Archive_Name::normalize($source_archive) === $source_archive
                ? $source_archive
                : null;
    }

    private static function marker_exists(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        clearstatcache(true, $path);

        return file_exists($path);
    }

    public static function clear(string $lock_path): bool
    {
        return self::clear_marker(self::get_path($lock_path));
    }

    public static function clear_replacement_transition(string $lock_path): bool
    {
        return self::clear_marker(self::get_replacement_transition_path($lock_path));
    }

    private static function clear_marker(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        clearstatcache(true, $path);

        return !file_exists($path) || @unlink($path);
    }
}
