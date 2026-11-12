<?php

/** Unambiguous flat JSON shared by durable health records and their transport. */
final class Kiwi_Retention_Archive_Health_Json
{
    public static function decode(string $json): array
    {
        $object = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        if (!$object instanceof stdClass) { throw new RuntimeException('A JSON object is required.'); }
        foreach (get_object_vars($object) as $value) {
            if (is_array($value) || is_object($value)) { throw new RuntimeException('Flat health fields are required.'); }
        }
        // Decode escaped names too: JSON's last-key-wins behavior would hide contradictory state.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\],:]|[^\s{}\[\],:]+/s', $json, $matches);
        $seen = [];
        for ($i = 1; $i < count($matches[0]) - 1; $i += 4) {
            $key = json_decode($matches[0][$i], true, 2, JSON_THROW_ON_ERROR);
            if (!is_string($key) || isset($seen[$key])) { throw new RuntimeException('Duplicate health field.'); }
            $seen[$key] = true;
        }
        return get_object_vars($object);
    }
}
