<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Retention_Source_Registry
{
    public const SOURCE_LANDING_PAGE_SESSIONS = 'landing_page_sessions';
    public const SOURCE_LANDING_HANDOFF_EVENTS = 'landing_handoff_events';
    public const SOURCE_LANDING_SESSION_ENGAGEMENTS = 'landing_session_engagements';

    public function get_source_keys(): array
    {
        return [
            self::SOURCE_LANDING_PAGE_SESSIONS,
            self::SOURCE_LANDING_HANDOFF_EVENTS,
            self::SOURCE_LANDING_SESSION_ENGAGEMENTS,
        ];
    }

    public function get(string $source_key): ?array
    {
        $source_key = trim($source_key);

        if (!in_array($source_key, $this->get_source_keys(), true)) {
            return null;
        }

        global $wpdb;

        if ($source_key === self::SOURCE_LANDING_SESSION_ENGAGEMENTS) {
            return [
                'source_key' => self::SOURCE_LANDING_SESSION_ENGAGEMENTS,
                'source_table' => Kiwi_Database_Table_Names::landing_session_engagements(),
                'primary_key' => 'id',
                'cutoff_column' => 'updated_at',
                'retention_days_default' => 14,
                'retention_days_min' => 7,
                'coverage_gate_required' => true,
                'mutable_snapshot' => true,
                'archive_columns' => [
                    'id' => 'INTEGER', 'created_at' => 'TEXT', 'updated_at' => 'TEXT',
                    'provider_key' => 'TEXT', 'service_key' => 'TEXT', 'flow_key' => 'TEXT',
                    'pid' => 'TEXT', 'click_id' => 'TEXT', 'tksource' => 'TEXT', 'tkzone' => 'TEXT',
                    'landing_key' => 'TEXT', 'session_token' => 'TEXT',
                    'page_loaded_at' => 'TEXT', 'first_cta_click_at' => 'TEXT', 'last_cta_click_at' => 'TEXT',
                    'cta_click_count' => 'INTEGER',
                    'first_cta1_click_at' => 'TEXT', 'last_cta1_click_at' => 'TEXT', 'cta1_click_count' => 'INTEGER',
                    'first_cta2_click_at' => 'TEXT', 'last_cta2_click_at' => 'TEXT', 'cta2_click_count' => 'INTEGER',
                    'first_cta3_click_at' => 'TEXT', 'last_cta3_click_at' => 'TEXT', 'cta3_click_count' => 'INTEGER',
                    'ua_ch_supported' => 'INTEGER', 'ua_ch_mobile' => 'INTEGER',
                    'ua_ch_platform' => 'TEXT', 'ua_ch_platform_version' => 'TEXT', 'ua_ch_model' => 'TEXT',
                    'ua_ch_brands' => 'TEXT', 'ua_ch_full_version_list' => 'TEXT', 'user_agent' => 'TEXT',
                    'last_event_at' => 'TEXT', 'is_soft_flag' => 'INTEGER',
                    'soft_flag_reason' => 'TEXT', 'soft_flag_rule_key' => 'TEXT', 'soft_flag_evaluated_at' => 'TEXT',
                ],
            ];
        }

        if ($source_key === self::SOURCE_LANDING_HANDOFF_EVENTS) {
            return [
                'source_key' => self::SOURCE_LANDING_HANDOFF_EVENTS,
                'source_table' => $wpdb->prefix . 'kiwi_landing_handoff_events',
                'primary_key' => 'id',
                'cutoff_column' => 'created_at',
                'retention_days_default' => 21,
                'retention_days_min' => 7,
                'coverage_gate_required' => false,
                'archive_columns' => [
                    'id' => 'INTEGER',
                    'created_at' => 'TEXT',
                    'landing_key' => 'TEXT',
                    'service_key' => 'TEXT',
                    'provider_key' => 'TEXT',
                    'flow_key' => 'TEXT',
                    'pid' => 'TEXT',
                    'click_id' => 'TEXT',
                    'tksource' => 'TEXT',
                    'tkzone' => 'TEXT',
                    'session_token' => 'TEXT',
                    'handoff_id' => 'TEXT',
                    'event_type' => 'TEXT',
                    'href_scheme' => 'TEXT',
                    'sms_recipient' => 'TEXT',
                    'sms_body_present' => 'INTEGER',
                    'sms_body_has_transaction' => 'INTEGER',
                    'elapsed_ms' => 'INTEGER',
                    'visibility_state' => 'TEXT',
                    'ua_ch_supported' => 'INTEGER',
                    'ua_ch_mobile' => 'INTEGER',
                    'ua_ch_platform' => 'TEXT',
                    'ua_ch_platform_version' => 'TEXT',
                    'ua_ch_model' => 'TEXT',
                    'ua_ch_brands' => 'TEXT',
                    'ua_ch_full_version_list' => 'TEXT',
                    'user_agent' => 'TEXT',
                    'raw_context' => 'TEXT',
                ],
            ];
        }

        return [
            'source_key' => self::SOURCE_LANDING_PAGE_SESSIONS,
            'source_table' => $wpdb->prefix . 'kiwi_landing_page_sessions',
            'primary_key' => 'id',
            'cutoff_column' => 'created_at',
            'retention_days_default' => 14,
            'retention_days_min' => 7,
            'coverage_gate_required' => true,
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
