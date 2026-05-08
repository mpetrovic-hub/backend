<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Sms_Body_Variant_Repository
{
    private function get_assignments_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_sms_body_variant_assignments';
    }

    private function get_summary_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_sms_body_variant_summary';
    }

    public function create_table(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $assignments_table = $this->get_assignments_table_name();
        $summary_table = $this->get_summary_table_name();

        $assignments_sql = "CREATE TABLE {$assignments_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            landing_key VARCHAR(100) NOT NULL DEFAULT '',
            service_key VARCHAR(100) NOT NULL DEFAULT '',
            provider_key VARCHAR(50) NOT NULL DEFAULT '',
            flow_key VARCHAR(50) NOT NULL DEFAULT '',
            country VARCHAR(10) NOT NULL DEFAULT '',
            keyword VARCHAR(50) NOT NULL DEFAULT '',
            shortcode VARCHAR(50) NOT NULL DEFAULT '',
            pid VARCHAR(191) NOT NULL DEFAULT '',
            click_id VARCHAR(191) NOT NULL DEFAULT '',
            session_token VARCHAR(150) NOT NULL DEFAULT '',
            transaction_id VARCHAR(120) NOT NULL DEFAULT '',
            visible_token VARCHAR(140) NOT NULL DEFAULT '',
            variant_key VARCHAR(50) NOT NULL DEFAULT '',
            seed VARCHAR(50) NOT NULL DEFAULT '',
            sms_body VARCHAR(255) NOT NULL DEFAULT '',
            cta1_recorded_at DATETIME NULL,
            handoff_attempted_at DATETIME NULL,
            handoff_hidden_at DATETIME NULL,
            handoff_no_hide_at DATETIME NULL,
            handoff_returned_at DATETIME NULL,
            conv_recorded_at DATETIME NULL,
            raw_context LONGTEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY transaction_id (transaction_id),
            UNIQUE KEY visible_token (visible_token),
            KEY landing_session (landing_key, session_token),
            KEY landing_key (landing_key),
            KEY service_key (service_key),
            KEY provider_key (provider_key),
            KEY flow_key (flow_key),
            KEY country (country),
            KEY pid (pid),
            KEY click_id (click_id),
            KEY variant_key (variant_key),
            KEY seed (seed),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $summary_sql = "CREATE TABLE {$summary_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            landing_key VARCHAR(100) NOT NULL DEFAULT '',
            service_key VARCHAR(100) NOT NULL DEFAULT '',
            provider_key VARCHAR(50) NOT NULL DEFAULT '',
            flow_key VARCHAR(50) NOT NULL DEFAULT '',
            variant_key VARCHAR(50) NOT NULL DEFAULT '',
            seed VARCHAR(50) NOT NULL DEFAULT '',
            assignments INT UNSIGNED NOT NULL DEFAULT 0,
            cta1 INT UNSIGNED NOT NULL DEFAULT 0,
            handoff_attempted INT UNSIGNED NOT NULL DEFAULT 0,
            handoff_hidden INT UNSIGNED NOT NULL DEFAULT 0,
            handoff_no_hide INT UNSIGNED NOT NULL DEFAULT 0,
            handoff_returned INT UNSIGNED NOT NULL DEFAULT 0,
            conv INT UNSIGNED NOT NULL DEFAULT 0,
            cta1_cr DECIMAL(7,2) NOT NULL DEFAULT 0,
            handoff_hidden_cr DECIMAL(7,2) NOT NULL DEFAULT 0,
            conv_cr DECIMAL(7,2) NOT NULL DEFAULT 0,
            conv_per_cta1_cr DECIMAL(7,2) NOT NULL DEFAULT 0,
            conv_per_hidden_cr DECIMAL(7,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY variant_summary (landing_key, service_key, variant_key, seed),
            KEY landing_key (landing_key),
            KEY service_key (service_key),
            KEY provider_key (provider_key),
            KEY flow_key (flow_key),
            KEY variant_key (variant_key),
            KEY seed (seed),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($assignments_sql);
        dbDelta($summary_sql);
    }

    public function insert_if_new(array $assignment): array
    {
        $transaction_id = $this->sanitize_token((string) ($assignment['transaction_id'] ?? ''), 120);
        $visible_token = $this->sanitize_token((string) ($assignment['visible_token'] ?? ''), 140);
        $variant_key = $this->sanitize_key((string) ($assignment['variant_key'] ?? ''), 50);

        if ($transaction_id === '' || $visible_token === '' || !$this->is_supported_variant_key($variant_key)) {
            return [
                'inserted' => false,
                'row' => null,
            ];
        }

        $existing = $this->find_by_transaction_id($transaction_id);

        if (is_array($existing)) {
            return [
                'inserted' => false,
                'row' => $existing,
            ];
        }

        global $wpdb;

        $now = $this->current_time_mysql();
        $result = $wpdb->insert(
            $this->get_assignments_table_name(),
            [
                'created_at' => $now,
                'updated_at' => $now,
                'landing_key' => $this->sanitize_key((string) ($assignment['landing_key'] ?? ''), 100),
                'service_key' => $this->sanitize_key((string) ($assignment['service_key'] ?? ''), 100),
                'provider_key' => $this->sanitize_key((string) ($assignment['provider_key'] ?? ''), 50),
                'flow_key' => $this->sanitize_key((string) ($assignment['flow_key'] ?? ''), 50),
                'country' => $this->sanitize_country((string) ($assignment['country'] ?? '')),
                'keyword' => $this->sanitize_token((string) ($assignment['keyword'] ?? ''), 50),
                'shortcode' => $this->sanitize_token((string) ($assignment['shortcode'] ?? ''), 50),
                'pid' => $this->sanitize_source_value((string) ($assignment['pid'] ?? '')),
                'click_id' => $this->sanitize_source_value((string) ($assignment['click_id'] ?? '')),
                'session_token' => $this->sanitize_session_token((string) ($assignment['session_token'] ?? '')),
                'transaction_id' => $transaction_id,
                'visible_token' => $visible_token,
                'variant_key' => $variant_key,
                'seed' => $this->sanitize_token((string) ($assignment['seed'] ?? ''), 50),
                'sms_body' => $this->sanitize_sms_body((string) ($assignment['sms_body'] ?? '')),
                'raw_context' => isset($assignment['raw_context']) ? wp_json_encode($assignment['raw_context']) : '',
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

        if ($result === false) {
            return [
                'inserted' => false,
                'row' => $this->find_by_transaction_id($transaction_id),
            ];
        }

        $row = $this->find_by_transaction_id($transaction_id);

        if (is_array($row)) {
            $this->increment_summary_counter($row, 'assignments');
        }

        return [
            'inserted' => true,
            'row' => $row,
        ];
    }

    public function find_by_transaction_id(string $transaction_id): ?array
    {
        global $wpdb;

        $transaction_id = $this->sanitize_token($transaction_id, 120);

        if ($transaction_id === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->get_assignments_table_name()} WHERE transaction_id = %s LIMIT 1",
                $transaction_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function find_by_visible_token(string $visible_token): ?array
    {
        global $wpdb;

        $visible_token = $this->sanitize_token($visible_token, 140);

        if ($visible_token === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->get_assignments_table_name()} WHERE visible_token = %s LIMIT 1",
                $visible_token
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function find_latest_by_landing_session(string $landing_key, string $session_token): ?array
    {
        global $wpdb;

        $landing_key = $this->sanitize_key($landing_key, 100);
        $session_token = $this->sanitize_session_token($session_token);

        if ($landing_key === '' || $session_token === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$this->get_assignments_table_name()}
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

    public function mark_event_by_landing_session(string $landing_key, string $session_token, string $event_key): bool
    {
        $assignment = $this->find_latest_by_landing_session($landing_key, $session_token);

        if (!is_array($assignment)) {
            return false;
        }

        return $this->mark_event_by_transaction_id((string) ($assignment['transaction_id'] ?? ''), $event_key);
    }

    public function mark_event_by_transaction_id(string $transaction_id, string $event_key): bool
    {
        global $wpdb;

        $transaction_id = $this->sanitize_token($transaction_id, 120);
        $field = $this->field_for_event_key($event_key);
        $counter = $this->counter_for_event_key($event_key);

        if ($transaction_id === '' || $field === '' || $counter === '') {
            return false;
        }

        $now = $this->current_time_mysql();
        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->get_assignments_table_name()}
                 SET updated_at = %s,
                     {$field} = %s
                 WHERE transaction_id = %s
                   AND {$field} IS NULL",
                $now,
                $now,
                $transaction_id
            )
        );

        if ($result !== 1) {
            return false;
        }

        $row = $this->find_by_transaction_id($transaction_id);

        if (!is_array($row)) {
            return false;
        }

        return $this->increment_summary_counter($row, $counter);
    }

    public function get_summary_rows(array $filters = []): array
    {
        global $wpdb;

        $table_name = $this->get_summary_table_name();
        $where = [];
        $params = [];

        foreach (['landing_key', 'service_key', 'variant_key', 'seed'] as $field) {
            $value = $this->sanitize_key((string) ($filters[$field] ?? ''), 100);

            if ($value === '') {
                continue;
            }

            $where[] = "{$field} = %s";
            $params[] = $value;
        }

        $sql = "SELECT * FROM {$table_name}";

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY landing_key ASC, service_key ASC, variant_key ASC, seed ASC';

        $rows = !empty($params)
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    private function increment_summary_counter(array $assignment, string $counter): bool
    {
        global $wpdb;

        $counter = $this->sanitize_counter($counter);

        if ($counter === '') {
            return false;
        }

        $landing_key = $this->sanitize_key((string) ($assignment['landing_key'] ?? ''), 100);
        $service_key = $this->sanitize_key((string) ($assignment['service_key'] ?? ''), 100);
        $variant_key = $this->sanitize_key((string) ($assignment['variant_key'] ?? ''), 50);
        $seed = $this->sanitize_token((string) ($assignment['seed'] ?? ''), 50);

        if ($landing_key === '' || $service_key === '' || !$this->is_supported_variant_key($variant_key)) {
            return false;
        }

        $provider_key = $this->sanitize_key((string) ($assignment['provider_key'] ?? ''), 50);
        $flow_key = $this->sanitize_key((string) ($assignment['flow_key'] ?? ''), 50);
        $now = $this->current_time_mysql();
        $table_name = $this->get_summary_table_name();

        $upsert_result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table_name} (
                    created_at,
                    updated_at,
                    landing_key,
                    service_key,
                    provider_key,
                    flow_key,
                    variant_key,
                    seed,
                    assignments,
                    cta1,
                    handoff_attempted,
                    handoff_hidden,
                    handoff_no_hide,
                    handoff_returned,
                    conv,
                    cta1_cr,
                    handoff_hidden_cr,
                    conv_cr,
                    conv_per_cta1_cr,
                    conv_per_hidden_cr
                ) VALUES (
                    %s,
                    %s,
                    %s,
                    %s,
                    %s,
                    %s,
                    %s,
                    %s,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0
                )
                ON DUPLICATE KEY UPDATE
                    updated_at = VALUES(updated_at),
                    provider_key = IF(provider_key = '', VALUES(provider_key), provider_key),
                    flow_key = IF(flow_key = '', VALUES(flow_key), flow_key)",
                $now,
                $now,
                $landing_key,
                $service_key,
                $provider_key,
                $flow_key,
                $variant_key,
                $seed
            )
        );

        if ($upsert_result === false) {
            return false;
        }

        $expressions = $this->counter_expressions($counter);

        $update_result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table_name}
                 SET updated_at = %s,
                     assignments = {$expressions['assignments']},
                     cta1 = {$expressions['cta1']},
                     handoff_attempted = {$expressions['handoff_attempted']},
                     handoff_hidden = {$expressions['handoff_hidden']},
                     handoff_no_hide = {$expressions['handoff_no_hide']},
                     handoff_returned = {$expressions['handoff_returned']},
                     conv = {$expressions['conv']},
                     cta1_cr = CASE WHEN {$expressions['assignments']} > 0 THEN ROUND(({$expressions['cta1']} / {$expressions['assignments']}) * 100, 2) ELSE 0 END,
                     handoff_hidden_cr = CASE WHEN {$expressions['handoff_attempted']} > 0 THEN ROUND(({$expressions['handoff_hidden']} / {$expressions['handoff_attempted']}) * 100, 2) ELSE 0 END,
                     conv_cr = CASE WHEN {$expressions['assignments']} > 0 THEN ROUND(({$expressions['conv']} / {$expressions['assignments']}) * 100, 2) ELSE 0 END,
                     conv_per_cta1_cr = CASE WHEN {$expressions['cta1']} > 0 THEN ROUND(({$expressions['conv']} / {$expressions['cta1']}) * 100, 2) ELSE 0 END,
                     conv_per_hidden_cr = CASE WHEN {$expressions['handoff_hidden']} > 0 THEN ROUND(({$expressions['conv']} / {$expressions['handoff_hidden']}) * 100, 2) ELSE 0 END
                 WHERE landing_key = %s
                   AND service_key = %s
                   AND variant_key = %s
                   AND seed = %s",
                $now,
                $landing_key,
                $service_key,
                $variant_key,
                $seed
            )
        );

        return $update_result !== false;
    }

    private function counter_expressions(string $counter): array
    {
        $counters = [
            'assignments',
            'cta1',
            'handoff_attempted',
            'handoff_hidden',
            'handoff_no_hide',
            'handoff_returned',
            'conv',
        ];
        $expressions = [];

        foreach ($counters as $candidate) {
            $expressions[$candidate] = $counter === $candidate
                ? '(' . $candidate . ' + 1)'
                : $candidate;
        }

        return $expressions;
    }

    private function field_for_event_key(string $event_key): string
    {
        $map = [
            'cta1' => 'cta1_recorded_at',
            'sms_handoff_attempted' => 'handoff_attempted_at',
            'sms_handoff_hidden' => 'handoff_hidden_at',
            'sms_handoff_no_hide' => 'handoff_no_hide_at',
            'sms_handoff_returned' => 'handoff_returned_at',
            'conv' => 'conv_recorded_at',
        ];

        return $map[$event_key] ?? '';
    }

    private function counter_for_event_key(string $event_key): string
    {
        $map = [
            'cta1' => 'cta1',
            'sms_handoff_attempted' => 'handoff_attempted',
            'sms_handoff_hidden' => 'handoff_hidden',
            'sms_handoff_no_hide' => 'handoff_no_hide',
            'sms_handoff_returned' => 'handoff_returned',
            'conv' => 'conv',
        ];

        return $map[$event_key] ?? '';
    }

    private function sanitize_counter(string $counter): string
    {
        $counter = strtolower(trim($counter));

        return in_array($counter, [
            'assignments',
            'cta1',
            'handoff_attempted',
            'handoff_hidden',
            'handoff_no_hide',
            'handoff_returned',
            'conv',
        ], true) ? $counter : '';
    }

    private function is_supported_variant_key(string $variant_key): bool
    {
        return in_array($variant_key, [
            'as_is_txn_prefix',
            'bare_id',
            'game_word',
            'cta_phrase',
        ], true);
    }

    private function sanitize_key(string $value, int $max_length): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9._:-]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, $max_length);
    }

    private function sanitize_token(string $value, int $max_length): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9_-]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, $max_length);
    }

    private function sanitize_country(string $country): string
    {
        $country = strtoupper(trim($country));
        $country = preg_replace('/[^A-Z0-9]/', '', $country);
        $country = is_string($country) ? $country : '';

        return substr($country, 0, 10);
    }

    private function sanitize_source_value(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9._~:-]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, 191);
    }

    private function sanitize_session_token(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9._:-]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, 150);
    }

    private function sanitize_sms_body(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9 _-]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, 255);
    }

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
