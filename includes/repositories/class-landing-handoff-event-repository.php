<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Handoff_Event_Repository
{
    private function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_landing_handoff_events';
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
            pid VARCHAR(191) NOT NULL DEFAULT '',
            click_id VARCHAR(191) NOT NULL DEFAULT '',
            session_token VARCHAR(150) NOT NULL DEFAULT '',
            handoff_id VARCHAR(100) NOT NULL DEFAULT '',
            event_type VARCHAR(50) NOT NULL DEFAULT '',
            href_scheme VARCHAR(20) NOT NULL DEFAULT '',
            sms_recipient VARCHAR(100) NOT NULL DEFAULT '',
            sms_body_present TINYINT(1) NOT NULL DEFAULT 0,
            sms_body_has_transaction TINYINT(1) NOT NULL DEFAULT 0,
            elapsed_ms INT UNSIGNED NOT NULL DEFAULT 0,
            visibility_state VARCHAR(50) NOT NULL DEFAULT '',
            user_agent TEXT NULL,
            raw_context LONGTEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY landing_handoff_event (landing_key, session_token, handoff_id, event_type),
            KEY landing_key (landing_key),
            KEY service_key (service_key),
            KEY provider_key (provider_key),
            KEY flow_key (flow_key),
            KEY pid (pid),
            KEY click_id (click_id),
            KEY handoff_id (handoff_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function insert_if_new(array $event): array
    {
        $landing_key = trim((string) ($event['landing_key'] ?? ''));
        $session_token = trim((string) ($event['session_token'] ?? ''));
        $handoff_id = $this->sanitize_handoff_id((string) ($event['handoff_id'] ?? ''));
        $event_type = strtolower(trim((string) ($event['event_type'] ?? '')));

        if ($landing_key === '' || $session_token === '' || $handoff_id === '' || !$this->is_supported_event_type($event_type)) {
            return [
                'inserted' => false,
                'row' => null,
            ];
        }

        $existing = $this->find_existing($landing_key, $session_token, $handoff_id, $event_type);

        if (is_array($existing)) {
            return [
                'inserted' => false,
                'row' => $existing,
            ];
        }

        global $wpdb;

        $result = $wpdb->insert(
            $this->get_table_name(),
            [
                'created_at' => $this->current_time_mysql(),
                'landing_key' => $landing_key,
                'service_key' => trim((string) ($event['service_key'] ?? '')),
                'provider_key' => trim((string) ($event['provider_key'] ?? '')),
                'flow_key' => trim((string) ($event['flow_key'] ?? '')),
                'pid' => $this->sanitize_pid((string) ($event['pid'] ?? '')),
                'click_id' => $this->sanitize_click_id((string) ($event['click_id'] ?? '')),
                'session_token' => $session_token,
                'handoff_id' => $handoff_id,
                'event_type' => $event_type,
                'href_scheme' => $this->sanitize_scheme((string) ($event['href_scheme'] ?? '')),
                'sms_recipient' => $this->sanitize_sms_recipient((string) ($event['sms_recipient'] ?? '')),
                'sms_body_present' => !empty($event['sms_body_present']) ? 1 : 0,
                'sms_body_has_transaction' => !empty($event['sms_body_has_transaction']) ? 1 : 0,
                'elapsed_ms' => max(0, (int) ($event['elapsed_ms'] ?? 0)),
                'visibility_state' => $this->sanitize_visibility_state((string) ($event['visibility_state'] ?? '')),
                'user_agent' => substr(trim((string) ($event['user_agent'] ?? '')), 0, 1000),
                'raw_context' => isset($event['raw_context']) ? wp_json_encode($event['raw_context']) : '',
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
                '%d',
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
            ]
        );

        if ($result === false) {
            $existing_after_failure = $this->find_existing($landing_key, $session_token, $handoff_id, $event_type);

            return [
                'inserted' => false,
                'row' => $existing_after_failure,
            ];
        }

        return [
            'inserted' => true,
            'row' => $this->get_by_id((int) $wpdb->insert_id),
        ];
    }

    public function get_recent(array $filters = [], int $limit = 100): array
    {
        global $wpdb;

        $limit = max(1, min(500, $limit));
        $where_sql = ['1 = 1'];
        $params = [];

        foreach (['landing_key', 'service_key', 'provider_key', 'flow_key', 'event_type', 'handoff_id'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));

            if ($value === '') {
                continue;
            }

            $where_sql[] = "{$field} = %s";
            $params[] = $value;
        }

        $pid = $this->sanitize_pid((string) ($filters['pid'] ?? ''));
        if ($pid !== '') {
            $where_sql[] = 'pid = %s';
            $params[] = $pid;
        }

        $click_id = $this->sanitize_click_id((string) ($filters['click_id'] ?? ''));
        if ($click_id !== '') {
            $where_sql[] = 'click_id = %s';
            $params[] = $click_id;
        }

        $params[] = $limit;

        $sql = "SELECT *
                FROM {$this->get_table_name()}
                WHERE " . implode(' AND ', $where_sql) . '
                ORDER BY created_at DESC, id DESC
                LIMIT %d';

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, ...$params),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    private function find_existing(
        string $landing_key,
        string $session_token,
        string $handoff_id,
        string $event_type
    ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$this->get_table_name()}
                 WHERE landing_key = %s
                   AND session_token = %s
                   AND handoff_id = %s
                   AND event_type = %s
                 LIMIT 1",
                $landing_key,
                $session_token,
                $handoff_id,
                $event_type
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function get_by_id(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->get_table_name()} WHERE id = %d LIMIT 1",
                $id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function is_supported_event_type(string $event_type): bool
    {
        return in_array($event_type, [
            'sms_handoff_attempted',
            'sms_handoff_hidden',
            'sms_handoff_returned',
            'sms_handoff_no_hide',
        ], true);
    }

    private function sanitize_handoff_id(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9._:-]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, 100);
    }

    private function sanitize_scheme(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9+.-]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, 20);
    }

    private function sanitize_sms_recipient(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[^0-9A-Za-z+._:-]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, 100);
    }

    private function sanitize_visibility_state(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, 50);
    }

    private function sanitize_pid(string $pid): string
    {
        $pid = trim($pid);

        if ($pid === '') {
            return '';
        }

        $pid = preg_replace('/[^A-Za-z0-9._~:-]/', '', $pid);
        $pid = is_string($pid) ? $pid : '';

        return substr($pid, 0, 191);
    }

    private function sanitize_click_id(string $click_id): string
    {
        $click_id = trim($click_id);

        if ($click_id === '') {
            return '';
        }

        $click_id = preg_replace('/[^A-Za-z0-9._~:-]/', '', $click_id);
        $click_id = is_string($click_id) ? $click_id : '';

        return substr($click_id, 0, 191);
    }

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}

