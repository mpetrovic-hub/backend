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
            provider_key VARCHAR(50) NOT NULL DEFAULT '',
            flow_key VARCHAR(50) NOT NULL DEFAULT '',
            country VARCHAR(10) NOT NULL DEFAULT '',
            pid VARCHAR(191) NOT NULL DEFAULT '',
            tksource VARCHAR(191) NOT NULL DEFAULT '',
            tkzone VARCHAR(191) NOT NULL DEFAULT '',
            browser_language VARCHAR(20) NOT NULL DEFAULT '(unknown)',
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
            KEY provider_key (provider_key),
            KEY flow_key (flow_key),
            KEY country (country),
            KEY pid (pid),
            KEY tksource (tksource),
            KEY tkzone (tkzone),
            KEY browser_language (browser_language),
            KEY session_token (session_token),
            KEY created_at (created_at),
            KEY created_landing_session (created_at, landing_key, session_token)
        ) {$charset_collate};";

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
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
                'provider_key' => $data['provider_key'] ?? '',
                'flow_key' => $data['flow_key'] ?? '',
                'country' => $data['country'] ?? '',
                'pid' => $data['pid'] ?? '',
                'tksource' => $data['tksource'] ?? '',
                'tkzone' => $data['tkzone'] ?? '',
                'browser_language' => $data['browser_language'] ?? '(unknown)',
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

    public function find_by_landing_session(string $landing_key, string $session_token): ?array
    {
        global $wpdb;

        $landing_key = trim($landing_key);
        $session_token = trim($session_token);

        if ($landing_key === '' || $session_token === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$this->get_table_name()}
                 WHERE landing_key = %s
                   AND session_token = %s
                 ORDER BY id DESC
                 LIMIT 1",
                $landing_key,
                $session_token
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function find_by_session_token(string $session_token, string $service_key = ''): ?array
    {
        global $wpdb;

        $session_token = trim($session_token);
        $service_key = trim($service_key);

        if ($session_token === '') {
            return null;
        }

        $where = 'session_token = %s';
        $params = [$session_token];

        if ($service_key !== '') {
            $where .= ' AND service_key = %s';
            $params[] = $service_key;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$this->get_table_name()}
                 WHERE {$where}
                 ORDER BY id DESC
                 LIMIT 1",
                ...$params
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
