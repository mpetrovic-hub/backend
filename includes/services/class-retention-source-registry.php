<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Retention_Source_Registry
{
    public const SOURCE_LANDING_PAGE_SESSIONS = 'landing_page_sessions';

    public function get(string $source_key): ?array
    {
        $source_key = trim($source_key);

        if ($source_key !== self::SOURCE_LANDING_PAGE_SESSIONS) {
            return null;
        }

        global $wpdb;

        return [
            'source_key' => self::SOURCE_LANDING_PAGE_SESSIONS,
            'source_table' => $wpdb->prefix . 'kiwi_landing_page_sessions',
            'primary_key' => 'id',
            'cutoff_column' => 'created_at',
            'retention_days_default' => 14,
            'retention_days_min' => 7,
            'accepted_missing_metric_dates' => $this->build_accepted_landing_page_session_gap_dates(),
            'archive_columns' => [
                'id' => 'INTEGER',
                'created_at' => 'TEXT',
                'landing_key' => 'TEXT',
                'service_key' => 'TEXT',
                'provider_key' => 'TEXT',
                'flow_key' => 'TEXT',
                'country' => 'TEXT',
                'pid' => 'TEXT',
                'tksource' => 'TEXT',
                'tkzone' => 'TEXT',
                'browser_language' => 'TEXT',
                'device_brand' => 'TEXT',
                'os' => 'TEXT',
                'os_version' => 'TEXT',
                'browser' => 'TEXT',
                'request_host' => 'TEXT',
                'request_path' => 'TEXT',
                'session_token' => 'TEXT',
                'click_to_sms_uri' => 'TEXT',
                'referer' => 'TEXT',
                'user_agent' => 'TEXT',
                'remote_ip' => 'TEXT',
                'client_ip_version' => 'TEXT',
                'client_ip_prefix' => 'TEXT',
                'query_params' => 'TEXT',
                'raw_context' => 'TEXT',
            ],
        ];
    }

    private function build_accepted_landing_page_session_gap_dates(): array
    {
        $dates = [];
        $current = '2026-05-15';

        while (strcmp($current, '2026-05-27') <= 0) {
            $dates[] = $current;
            $next = strtotime($current . ' +1 day');

            if ($next === false) {
                break;
            }

            $current = gmdate('Y-m-d', $next);
        }

        return $dates;
    }
}
