<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Msisdn_Normalizer
{
    public function normalize(string $msisdn): string
    {
        $msisdn = trim($msisdn);

        if ($msisdn === '') {
            return '';
        }

        // Remove common separators and whitespace
        $msisdn = str_replace(
            [' ', '-', '/', '(', ')', "\t", "\r", "\n"],
            '',
            $msisdn
        );

        // Remove leading +
        if (strpos($msisdn, '+') === 0) {
            $msisdn = substr($msisdn, 1);
        }

        // Convert leading 00 to international format without +
        if (strpos($msisdn, '00') === 0) {
            $msisdn = substr($msisdn, 2);
        }

        // GR mobile shorthand: 69xxxxxxxx -> 3069xxxxxxxx
        if (strpos($msisdn, '69') === 0) {
            $msisdn = '30' . $msisdn;
        }

        return $msisdn;
    }
}