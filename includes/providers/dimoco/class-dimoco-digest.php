<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Dimoco_Digest
{
    public function create(array $params, string $password): string
    {
        unset($params['digest']);

        ksort($params, SORT_STRING);

        $payload = '';

        foreach ($params as $value) {
            $payload .= (string) $value;
        }

        return hash_hmac('sha256', $payload, $password);
    }
}