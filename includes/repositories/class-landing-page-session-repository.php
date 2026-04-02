<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Page_Session_Repository
{
    private function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_landing_page_sessions';
    }

    public function create_table(): void
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            landing_key VARCHAR(100) NOT NULL DEFAULT '',
            service_key VARCHAR(100) NOT NULL DEFAULT '',
            request_host VARCHAR(191) NOT NULL DEFAULT '',
            request_path VARCHAR(191) NOT NULL DEFAULT '',
            session_token VARCHAR(100) NOT NULL DEFAULT '',
            click_to_sms_uri VARCHAR(255) NOT NULL DEFAULT '',
            referer TEXT NULL,
            user_agent TEXT NULL,
            remote_ip VARCHAR(100) NOT NULL DEFAULT '',
            query_params LONGTEXT NULL,
            raw_context LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY landing_key (landing_key),
            KEY service_key (service_key),
            KEY session_token (session_token),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function insert(array $data): bool
    {
        global $wpdb;

        $result = $wpdb->insert(
            $this->get_table_name(),
            [
                'created_at' => $this->current_time_mysql(),
                'landing_key' => $data['landing_key'] ?? '',
                'service_key' => $data['service_key'] ?? '',
                'request_host' => $data['request_host'] ?? '',
                'request_path' => $data['request_path'] ?? '',
                'session_token' => $data['session_token'] ?? '',
                'click_to_sms_uri' => $data['click_to_sms_uri'] ?? '',
                'referer' => $data['referer'] ?? '',
                'user_agent' => $data['user_agent'] ?? '',
                'remote_ip' => $data['remote_ip'] ?? '',
                'query_params' => isset($data['query_params']) ? wp_json_encode($data['query_params']) : '',
                'raw_context' => isset($data['raw_context']) ? wp_json_encode($data['raw_context']) : '',
            ],
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            ]
        );

        return $result !== false;
    }

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
