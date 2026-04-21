<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Click_Attribution_Repository
{
    private function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_click_attributions';
    }

    public function create_table(): void
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            tracking_token VARCHAR(100) NOT NULL DEFAULT '',
            transaction_id VARCHAR(120) NOT NULL DEFAULT '',
            click_id VARCHAR(191) NOT NULL DEFAULT '',
            provider_key VARCHAR(50) NOT NULL DEFAULT '',
            landing_page_key VARCHAR(100) NOT NULL DEFAULT '',
            flow_key VARCHAR(50) NOT NULL DEFAULT '',
            service_key VARCHAR(100) NOT NULL DEFAULT '',
            pid VARCHAR(191) NOT NULL DEFAULT '',
            session_ref VARCHAR(150) NOT NULL DEFAULT '',
            transaction_ref VARCHAR(150) NOT NULL DEFAULT '',
            message_ref VARCHAR(150) NOT NULL DEFAULT '',
            external_ref VARCHAR(150) NOT NULL DEFAULT '',
            sale_reference VARCHAR(100) NOT NULL DEFAULT '',
            conversion_status VARCHAR(50) NOT NULL DEFAULT 'captured',
            conversion_confirmed_at DATETIME NULL,
            postback_sent_at DATETIME NULL,
            postback_response_code INT NOT NULL DEFAULT 0,
            postback_response_body TEXT NULL,
            postback_last_error TEXT NULL,
            postback_attempts INT NOT NULL DEFAULT 0,
            last_postback_attempt_at DATETIME NULL,
            raw_context LONGTEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY tracking_token (tracking_token),
            KEY transaction_id (transaction_id),
            KEY click_id (click_id),
            KEY provider_key (provider_key),
            KEY service_key (service_key),
            KEY pid (pid),
            KEY session_ref (session_ref),
            KEY transaction_ref (transaction_ref),
            KEY message_ref (message_ref),
            KEY external_ref (external_ref),
            KEY sale_reference (sale_reference),
            KEY expires_at (expires_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function upsert_capture(array $data): array
    {
        $tracking_token = trim((string) ($data['tracking_token'] ?? ''));

        if ($tracking_token === '') {
            return [];
        }

        $existing = $this->find_by_tracking_token($tracking_token);
        $expires_at = (string) ($data['expires_at'] ?? $this->current_time_mysql());
        $transaction_id = $this->resolve_transaction_id(
            (string) ($data['transaction_id'] ?? ''),
            is_array($existing) ? (string) ($existing['transaction_id'] ?? '') : ''
        );

        if (is_array($existing)) {
            $this->update_by_id((int) $existing['id'], [
                'updated_at' => $this->current_time_mysql(),
                'expires_at' => $expires_at,
                'transaction_id' => $transaction_id,
                'click_id' => (string) ($data['click_id'] ?? ($existing['click_id'] ?? '')),
                'provider_key' => (string) ($data['provider_key'] ?? ($existing['provider_key'] ?? '')),
                'landing_page_key' => (string) ($data['landing_page_key'] ?? ($existing['landing_page_key'] ?? '')),
                'flow_key' => (string) ($data['flow_key'] ?? ($existing['flow_key'] ?? '')),
                'service_key' => (string) ($data['service_key'] ?? ($existing['service_key'] ?? '')),
                'pid' => $this->sanitize_pid((string) ($data['pid'] ?? ($existing['pid'] ?? ''))),
                'session_ref' => (string) ($data['session_ref'] ?? ($existing['session_ref'] ?? '')),
                'external_ref' => (string) ($data['external_ref'] ?? ($existing['external_ref'] ?? '')),
                'raw_context' => $data['raw_context'] ?? null,
            ]);

            return $this->find_by_tracking_token($tracking_token) ?? [];
        }

        global $wpdb;

        $now = $this->current_time_mysql();
        $wpdb->insert(
            $this->get_table_name(),
            [
                'created_at' => $now,
                'updated_at' => $now,
                'expires_at' => $expires_at,
                'tracking_token' => $tracking_token,
                'transaction_id' => $transaction_id,
                'click_id' => (string) ($data['click_id'] ?? ''),
                'provider_key' => (string) ($data['provider_key'] ?? ''),
                'landing_page_key' => (string) ($data['landing_page_key'] ?? ''),
                'flow_key' => (string) ($data['flow_key'] ?? ''),
                'service_key' => (string) ($data['service_key'] ?? ''),
                'pid' => $this->sanitize_pid((string) ($data['pid'] ?? '')),
                'session_ref' => (string) ($data['session_ref'] ?? ''),
                'transaction_ref' => (string) ($data['transaction_ref'] ?? ''),
                'message_ref' => (string) ($data['message_ref'] ?? ''),
                'external_ref' => (string) ($data['external_ref'] ?? ''),
                'sale_reference' => (string) ($data['sale_reference'] ?? ''),
                'conversion_status' => (string) ($data['conversion_status'] ?? 'captured'),
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
            ]
        );

        return $this->find_by_tracking_token($tracking_token) ?? [];
    }

    public function find_by_tracking_token(string $tracking_token): ?array
    {
        global $wpdb;

        $tracking_token = trim($tracking_token);

        if ($tracking_token === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->get_table_name()} WHERE tracking_token = %s LIMIT 1",
                $tracking_token
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function find_by_transaction_id(string $transaction_id): ?array
    {
        global $wpdb;

        $transaction_id = $this->sanitize_transaction_id($transaction_id);

        if ($transaction_id === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->get_table_name()} WHERE transaction_id = %s ORDER BY id DESC LIMIT 1",
                $transaction_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function get_by_id(int $id): ?array
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

    public function find_unique_pending_by_service_reference(string $service_key, string $reference): ?array
    {
        global $wpdb;

        $service_key = trim($service_key);
        $reference = trim($reference);

        if ($service_key === '' || $reference === '') {
            return null;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$this->get_table_name()}
                 WHERE service_key = %s
                   AND transaction_ref = ''
                   AND (session_ref = %s OR external_ref = %s)
                 ORDER BY id DESC
                 LIMIT 2",
                $service_key,
                $reference,
                $reference
            ),
            ARRAY_A
        );

        if (!is_array($rows) || count($rows) !== 1) {
            return null;
        }

        return $rows[0];
    }

    public function find_for_conversion(array $references): ?array
    {
        $provider_key = trim((string) ($references['provider_key'] ?? ''));
        $service_key = trim((string) ($references['service_key'] ?? ''));

        $ordered_fields = [
            'transaction_id',
            'sale_reference',
            'transaction_ref',
            'message_ref',
            'external_ref',
            'session_ref',
        ];

        foreach ($ordered_fields as $field) {
            $value = trim((string) ($references[$field] ?? ''));

            if ($value === '') {
                continue;
            }

            $row = $this->find_latest_by_field($field, $value, $provider_key, $service_key);

            if (is_array($row)) {
                return $row;
            }
        }

        return null;
    }

    public function bind_references(int $id, array $references): bool
    {
        $existing = $this->get_by_id($id);

        if (!is_array($existing)) {
            return false;
        }

        $conversion_status = trim((string) ($existing['conversion_status'] ?? ''));
        if ($conversion_status === '') {
            $conversion_status = 'captured';
        }

        if ($conversion_status === 'captured') {
            $conversion_status = 'bound';
        }

        $transaction_id = $this->resolve_transaction_id(
            (string) ($references['transaction_id'] ?? ''),
            (string) ($existing['transaction_id'] ?? '')
        );

        return $this->update_by_id($id, [
            'updated_at' => $this->current_time_mysql(),
            'transaction_id' => $transaction_id,
            'provider_key' => (string) ($references['provider_key'] ?? ($existing['provider_key'] ?? '')),
            'flow_key' => (string) ($references['flow_key'] ?? ($existing['flow_key'] ?? '')),
            'service_key' => (string) ($references['service_key'] ?? ($existing['service_key'] ?? '')),
            'session_ref' => (string) ($references['session_ref'] ?? ($existing['session_ref'] ?? '')),
            'transaction_ref' => (string) ($references['transaction_ref'] ?? ($existing['transaction_ref'] ?? '')),
            'message_ref' => (string) ($references['message_ref'] ?? ($existing['message_ref'] ?? '')),
            'external_ref' => (string) ($references['external_ref'] ?? ($existing['external_ref'] ?? '')),
            'sale_reference' => (string) ($references['sale_reference'] ?? ($existing['sale_reference'] ?? '')),
            'conversion_status' => $conversion_status,
        ]);
    }

    public function mark_conversion_confirmed(int $id, string $occurred_at): bool
    {
        $existing = $this->get_by_id($id);

        if (!is_array($existing)) {
            return false;
        }

        $confirmed_at = trim((string) ($existing['conversion_confirmed_at'] ?? ''));
        if ($confirmed_at === '') {
            $confirmed_at = trim($occurred_at);
        }

        if ($confirmed_at === '') {
            $confirmed_at = $this->current_time_mysql();
        }

        return $this->update_by_id($id, [
            'updated_at' => $this->current_time_mysql(),
            'conversion_status' => 'confirmed',
            'conversion_confirmed_at' => $confirmed_at,
        ]);
    }

    public function record_postback_attempt(int $id, array $result): bool
    {
        $existing = $this->get_by_id($id);

        if (!is_array($existing)) {
            return false;
        }

        $attempts = (int) ($existing['postback_attempts'] ?? 0) + 1;
        $sent_at = trim((string) ($existing['postback_sent_at'] ?? ''));
        $is_success = !empty($result['success']);
        if ($is_success && $sent_at === '') {
            $sent_at = $this->current_time_mysql();
        }

        $status = $is_success ? 'postback_sent' : 'postback_failed';
        if ($is_success === false && trim((string) ($existing['conversion_status'] ?? '')) === 'postback_sent') {
            $status = 'postback_sent';
        }

        return $this->update_by_id($id, [
            'updated_at' => $this->current_time_mysql(),
            'conversion_status' => $status,
            'postback_sent_at' => $sent_at === '' ? null : $sent_at,
            'postback_response_code' => (int) ($result['response_code'] ?? 0),
            'postback_response_body' => (string) ($result['response_body'] ?? ''),
            'postback_last_error' => (string) ($result['error'] ?? ''),
            'postback_attempts' => $attempts,
            'last_postback_attempt_at' => $this->current_time_mysql(),
        ]);
    }

    public function cleanup_expired(int $limit = 500): int
    {
        global $wpdb;

        $limit = max(1, $limit);
        $now = $this->current_time_mysql();
        $table = $this->get_table_name();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id
                 FROM {$table}
                 WHERE expires_at <= %s
                 ORDER BY id ASC
                 LIMIT %d",
                $now,
                $limit
            ),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            return 0;
        }

        $ids = array_map(static function (array $row): int {
            return (int) ($row['id'] ?? 0);
        }, $rows);
        $ids = array_values(array_filter($ids));

        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $sql = "DELETE FROM {$table} WHERE id IN ({$placeholders})";
        $wpdb->query($wpdb->prepare($sql, ...$ids));

        return count($ids);
    }

    protected function update_by_id(int $id, array $fields): bool
    {
        global $wpdb;

        $set_fields = [];
        $formats = [];

        $allowed = [
            'updated_at' => '%s',
            'expires_at' => '%s',
            'transaction_id' => '%s',
            'click_id' => '%s',
            'provider_key' => '%s',
            'landing_page_key' => '%s',
            'flow_key' => '%s',
            'service_key' => '%s',
            'pid' => '%s',
            'session_ref' => '%s',
            'transaction_ref' => '%s',
            'message_ref' => '%s',
            'external_ref' => '%s',
            'sale_reference' => '%s',
            'conversion_status' => '%s',
            'conversion_confirmed_at' => '%s',
            'postback_sent_at' => '%s',
            'postback_response_code' => '%d',
            'postback_response_body' => '%s',
            'postback_last_error' => '%s',
            'postback_attempts' => '%d',
            'last_postback_attempt_at' => '%s',
            'raw_context' => '%s',
        ];

        foreach ($allowed as $key => $format) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }

            $value = $fields[$key];
            if ($key === 'raw_context' && $value !== null && !is_string($value)) {
                $value = wp_json_encode($value);
            }

            $set_fields[$key] = $value;
            $formats[] = $format;
        }

        if (empty($set_fields)) {
            return true;
        }

        $result = $wpdb->update(
            $this->get_table_name(),
            $set_fields,
            ['id' => $id],
            $formats,
            ['%d']
        );

        return $result !== false;
    }

    private function find_latest_by_field(string $field, string $value, string $provider_key, string $service_key): ?array
    {
        global $wpdb;

        $allowed_fields = ['transaction_id', 'sale_reference', 'transaction_ref', 'message_ref', 'external_ref', 'session_ref'];
        if (!in_array($field, $allowed_fields, true)) {
            return null;
        }

        $table = $this->get_table_name();
        $where = "{$field} = %s";
        $params = [$value];

        if ($provider_key !== '') {
            $where .= " AND provider_key = %s";
            $params[] = $provider_key;
        }

        if ($service_key !== '') {
            $where .= " AND service_key = %s";
            $params[] = $service_key;
        }

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT 1";
        $row = $wpdb->get_row(
            $wpdb->prepare($sql, ...$params),
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

    private function resolve_transaction_id(string $incoming_value, string $fallback_value = ''): string
    {
        $incoming = $this->sanitize_transaction_id($incoming_value);

        if ($incoming !== '') {
            return $incoming;
        }

        $fallback = $this->sanitize_transaction_id($fallback_value);

        if ($fallback !== '') {
            return $fallback;
        }

        return $this->generate_transaction_id();
    }

    private function sanitize_transaction_id(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = substr($value, 0, 120);

        if (!preg_match('/^[A-Za-z0-9_-]{12,120}$/', $value)) {
            return '';
        }

        return $value;
    }

    private function generate_transaction_id(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            $uuid = str_replace('-', '', wp_generate_uuid4());

            return 'txn_' . substr($uuid, 0, 16);
        }

        if (function_exists('random_bytes')) {
            try {
                return 'txn_' . bin2hex(random_bytes(8));
            } catch (Throwable $throwable) {
            }
        }

        return 'txn_' . substr(md5(uniqid('', true)), 0, 16);
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
}
