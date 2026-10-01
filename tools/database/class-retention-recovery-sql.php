<?php

if (!defined('ABSPATH')) { exit; }

/** Small fail-closed boundary; database errors and raw rows never enter reports. */
final class Kiwi_Retention_Recovery_SQL
{
    public static function execute(string $sql): void
    {
        global $wpdb;
        if ($wpdb->query($sql) === false) { throw new RuntimeException('recovery_query_failed'); }
    }

    public static function rows(string $sql): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || trim((string) $wpdb->last_error) !== '') {
            throw new RuntimeException('recovery_read_failed');
        }
        return $rows;
    }

    public static function identifier(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $value) !== 1) {
            throw new RuntimeException('recovery_identifier_invalid');
        }
        return $value;
    }

    public static function digest($value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public static function open_archive(string $path): PDO
    {
        if (!is_file($path) || is_link($path)) { throw new RuntimeException('archive_missing'); }
        foreach (['-wal', '-journal'] as $suffix) {
            $sidecar = $path . $suffix;
            clearstatcache(true, $sidecar);
            if (is_link($sidecar) || (file_exists($sidecar)
                && (!is_file($sidecar) || filesize($sidecar) !== 0))) {
                throw new RuntimeException('archive_sidecar_not_empty');
            }
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA query_only = ON');
        return $pdo;
    }
}
