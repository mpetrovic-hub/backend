<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canonical names for database objects shared across multiple capabilities.
 */
final class Kiwi_Database_Table_Names
{
    public const LANDING_SESSION_ENGAGEMENTS = 'kiwi_landing_session_engagements';

    public static function landing_session_engagements(): string
    {
        global $wpdb;

        return (string) $wpdb->prefix . self::LANDING_SESSION_ENGAGEMENTS;
    }
}
