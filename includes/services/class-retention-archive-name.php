<?php

final class Kiwi_Retention_Archive_Name
{
    public static function parse(string $archive_name): ?array
    {
        $archive_name = trim($archive_name);
        if ($archive_name === '' || basename($archive_name) !== $archive_name) {
            return null;
        }

        if (preg_match(
            '/^kiwi_retention_archive_([0-9]{4})(?:_part_([2-9]|[1-9][0-9]+))?\.sqlite$/',
            $archive_name,
            $matches
        ) !== 1) {
            return null;
        }

        return [
            'name' => $archive_name,
            'year' => (string) $matches[1],
            'generation' => isset($matches[2]) ? (int) $matches[2] : 1,
        ];
    }

    public static function normalize(string $archive_name): string
    {
        $archive_name = basename(trim($archive_name));

        return self::parse($archive_name) !== null ? $archive_name : '';
    }

    public static function build(string $year, int $generation = 1): string
    {
        if (preg_match('/^[0-9]{4}$/', $year) !== 1 || $generation < 1) {
            return '';
        }

        return 'kiwi_retention_archive_'
            . $year
            . ($generation === 1 ? '' : '_part_' . $generation)
            . '.sqlite';
    }
}
