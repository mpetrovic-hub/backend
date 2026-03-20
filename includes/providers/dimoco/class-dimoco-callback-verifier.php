<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Dimoco_Callback_Verifier
{
    /**
     * Verify callback digest against raw XML payload
     */
    public function verify(string $xml, string $received_digest, string $secret): bool
    {
        if ($xml === '' || $received_digest === '' || $secret === '') {
            return false;
        }

        $calculated_digest = hash_hmac('sha256', $xml, $secret);

        return hash_equals($calculated_digest, $received_digest);
    }
}