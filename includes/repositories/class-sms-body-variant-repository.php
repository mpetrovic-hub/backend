<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Sms_Body_Variant_Repository
{
    public const SUMMARY_GROUP_COLUMNS = ['landing_key', 'service_key', 'variant_key', 'seed', 'allocation_version'];
    private const SUMMARY_COUNTERS = ['assignments', 'cta1', 'handoff_attempted', 'handoff_hidden',
        'handoff_no_hide', 'handoff_returned', 'conv'];
    private const MAX_SUMMARY_COUNT = 4294967295; // Summary columns are INT UNSIGNED.
    private const MAX_SUMMARY_RATE = '99999.99'; // The five stored rates are DECIMAL(7,2).

    public static function group_comparison(bool $refresh = false): Kiwi_Mysql_Text_Comparison
    {
        global $wpdb;
        return Kiwi_Mysql_Text_Comparison::for_columns($wpdb->prefix . 'kiwi_sms_body_variant_summary',
            self::SUMMARY_GROUP_COLUMNS, $refresh, true);
    }

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
            allocation_version VARCHAR(50) NOT NULL DEFAULT 'legacy',
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
            allocation_version VARCHAR(50) NOT NULL DEFAULT 'legacy',
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
            UNIQUE KEY variant_summary (landing_key, service_key, variant_key, seed, allocation_version),
            KEY landing_key (landing_key),
            KEY service_key (service_key),
            KEY provider_key (provider_key),
            KEY flow_key (flow_key),
            KEY variant_key (variant_key),
            KEY seed (seed),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
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
                'allocation_version' => $this->allocation_version($assignment),
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

    public function find_visible_token_matches(string $token): array
    {
        return $this->find_code_matches('visible_token', $token);
    }

    public function find_transaction_id_matches(string $transaction_id): array
    {
        return $this->find_code_matches('transaction_id', $transaction_id);
    }

    /** Called after an INSERT and before its COMMIT, on the same connection. */
    public function lock_original_for_sale(string $column, string $code): array
    {
        global $wpdb;
        if (!in_array($column, ['transaction_id', 'visible_token'], true) || preg_match('/^[A-Za-z0-9_-]{1,140}$/D', $code) !== 1) {
            return [];
        }
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->get_assignments_table_name()} WHERE {$column} = %s LIMIT 2 FOR UPDATE NOWAIT", $code
        ));
        if (!is_array($rows) || !empty($wpdb->last_error) || count($rows) > 1) {
            return [];
        }
        return ['code' => [$column => $code], 'live_id' => $rows ? (int) $rows[0] : null];
    }

    private function find_code_matches(string $column, string $value): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->get_assignments_table_name()} WHERE {$column} = %s LIMIT 2", $value
        ), ARRAY_A);
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new RuntimeException('sms_assignment_mysql_read_failed');
        }
        return $rows;
    }

    public function find_assignment_by_id(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->get_assignments_table_name()} WHERE id = %d LIMIT 1", $id
        ), ARRAY_A);
        if (($row !== null && !is_array($row)) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new RuntimeException('sms_assignment_mysql_read_failed');
        }
        return $row;
    }

    public function find_latest_by_landing_session(string $landing_key, string $session_token): ?array
    {
        return $this->landing_session_matches($landing_key, $session_token, 1)[0] ?? null;
    }

    private function landing_session_matches(string $landing_key, string $session_token, int $limit): array
    {
        global $wpdb;

        $landing_key = $this->sanitize_key($landing_key, 100);
        $session_token = $this->sanitize_session_token($session_token);

        if ($landing_key === '' || $session_token === '') {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$this->get_assignments_table_name()}
                 WHERE landing_key = %s
                   AND session_token = %s
                 ORDER BY id DESC
                 LIMIT %d",
                $landing_key,
                $session_token,
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) && empty($wpdb->last_error) ? $rows : [];
    }

    public function mark_event_by_landing_session(string $landing_key, string $session_token, string $event_key): bool
    {
        $matches = $this->landing_session_matches($landing_key, $session_token, 2);

        if (count($matches) !== 1) {
            return false;
        }

        return $this->mark_event_for_original($matches[0], $event_key);
    }

    public function mark_event_for_original(array $assignment, string $event_key): bool
    {
        $id = (int) ($assignment['id'] ?? 0);
        return $id > 0 && $this->mark_event((string) $assignment['transaction_id'], $event_key, $id);
    }

    public function mark_event_by_transaction_id(string $transaction_id, string $event_key): bool
    {
        return $this->mark_event($transaction_id, $event_key);
    }

    private function mark_event(string $transaction_id, string $event_key, ?int $original_id = null): bool
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
                   AND {$field} IS NULL" . ($original_id === null ? '' : ' AND id = ' . $original_id),
                $now,
                $now,
                $transaction_id
            )
        );

        if ($result !== 1) {
            return false;
        }

        $row = $this->find_by_transaction_id($transaction_id);

        if (!is_array($row) || ($original_id !== null && (int) $row['id'] !== $original_id)) {
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

        foreach (['landing_key', 'service_key', 'variant_key', 'seed', 'allocation_version'] as $field) {
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

        $sql .= ' ORDER BY landing_key ASC, service_key ASC, variant_key ASC, seed ASC, allocation_version ASC';

        $rows = !empty($params)
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function increment_archived_conversion(array $assignment): bool
    {
        return $this->increment_summary_counter($assignment, 'conv', false);
    }

    /** Correct only deficits from the recorded group assessment, preserving intervening increments. */
    public function correct_summary_counters(array $group, array $before, ?string $source_lock = null): array
    {
        $values = array_map(static fn ($field) => $group[$field] ?? null, self::SUMMARY_GROUP_COLUMNS);
        $summary_id = filter_var($before['summary_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $skip = null;
        if (count(array_filter($values, 'is_string')) !== count(self::SUMMARY_GROUP_COLUMNS)) {
            $skip = 'group_key_incomplete';
        } elseif ($summary_id === false) {
            $skip = 'summary_row_missing';
        } elseif (!in_array($before['status'] ?? '', ['passed', 'outside_tolerance'], true) || ($before['reasons'] ?? null) !== []) {
            $skip = 'group_incomplete';
        }
        if ($skip !== null) {
            return ['status' => 'skipped', 'counters' => array_fill_keys(self::SUMMARY_COUNTERS,
                ['status' => 'skipped', 'reason' => $skip])];
        }

        $outcomes = [];
        foreach (self::SUMMARY_COUNTERS as $counter) {
            $measure = $before['counters'][$counter] ?? [];
            $outcomes[$counter] = $this->correct_summary_counter($summary_id, $values, $counter,
                is_array($measure) ? $measure : [], $source_lock);
        }
        $statuses = array_column($outcomes, 'status');
        $status = in_array('corrected', $statuses, true) ? 'corrected' : 'skipped';
        if (in_array('failed', $statuses, true)) { $status = 'failed'; }
        return ['status' => $status, 'counters' => $outcomes];
    }

    private function correct_summary_counter(int $summary_id, array $group_values, string $counter, array $measure, ?string $source_lock): array
    {
        global $wpdb;
        if ($counter === 'conv' && isset($measure['possible_expected'])) {
            return ['status' => 'skipped', 'reason' => 'sale_time_uncertain'];
        }
        $actual = $measure['actual'] ?? null;
        $expected = $measure['expected'] ?? null;
        if (!in_array($measure['status'] ?? '', ['passed', 'outside_tolerance'], true)
            || !is_int($actual) || !is_int($expected) || $actual < 0 || $expected < 0) {
            return ['status' => 'skipped', 'reason' => 'counter_unproven'];
        }
        if ($expected <= $actual) { return ['status' => 'skipped', 'reason' => 'no_deficit']; }
        if ($expected > self::MAX_SUMMARY_COUNT) { return ['status' => 'failed', 'reason' => 'counter_out_of_range']; }

        $table = $this->get_summary_table_name();
        $where = implode(' AND ', array_map(static fn ($field) => $field . ' = %s', self::SUMMARY_GROUP_COLUMNS));
        // Substitute the target in the rates so evaluation order inside SET cannot change them.
        $rate_values = $this->summary_rate_values([$counter => (string) $expected]);
        $rates = $this->summary_rate_assignments($rate_values);
        $fits = 'GREATEST(' . implode(', ', $rate_values) . ') <= ' . self::MAX_SUMMARY_RATE;
        $match = "id = %d AND {$where} AND {$counter} = %d";
        $match_args = array_merge([$summary_id], $group_values, [$actual]);
        $sql = "UPDATE {$table} SET {$counter} = %d, updated_at = %s, {$rates} WHERE {$match} AND {$fits}";
        $args = array_merge([$expected, $this->current_time_mysql()], $match_args);
        if ($source_lock !== null) {
            $sql .= ' AND IS_USED_LOCK(%s) = CONNECTION_ID()';
            $args[] = $source_lock;
        }
        try {
            $written = $wpdb->query($wpdb->prepare($sql, ...$args));
            if ($written === 1) { return ['status' => 'corrected']; }
            if ($written === 0) {
                if ($source_lock !== null && (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $source_lock
                )) !== 1) {
                    return ['status' => 'failed', 'reason' => 'source_lock_lost'];
                }
                $overflow = $wpdb->get_var($wpdb->prepare(
                    "SELECT 1 FROM {$table} WHERE {$match} AND NOT ({$fits})", ...$match_args
                ));
                if ((string) ($wpdb->last_error ?? '') !== '') { return ['status' => 'failed', 'reason' => 'summary_write_failed']; }
                if ((int) $overflow === 1) { return ['status' => 'failed', 'reason' => 'rate_out_of_range']; }
                return ['status' => 'skipped', 'reason' => 'summary_changed'];
            }
        } catch (Throwable $error) {
            // The worker records a failed attempt and obtains a fresh assessment.
        }
        return ['status' => 'failed', 'reason' => 'summary_write_failed'];
    }

    private function summary_rate_values(array $values = []): array
    {
        $ratios = [
            'cta1_cr' => ['cta1', 'assignments'],
            'handoff_hidden_cr' => ['handoff_hidden', 'handoff_attempted'],
            'conv_cr' => ['conv', 'assignments'],
            'conv_per_cta1_cr' => ['conv', 'cta1'],
            'conv_per_hidden_cr' => ['conv', 'handoff_hidden'],
        ];
        $expressions = [];
        foreach ($ratios as $rate => [$numerator, $denominator]) {
            $top = $values[$numerator] ?? $numerator;
            $bottom = $values[$denominator] ?? $denominator;
            $expressions[$rate] = "CASE WHEN {$bottom} > 0 THEN ROUND(({$top} / {$bottom}) * 100, 2) ELSE 0 END";
        }
        return $expressions;
    }

    private function summary_rate_assignments(array $expressions): string
    {
        $assignments = [];
        foreach ($expressions as $rate => $value) { $assignments[] = "{$rate} = {$value}"; }
        return implode(', ', $assignments);
    }

    private function increment_summary_counter(array $assignment, string $counter, bool $create_missing = true): bool
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
        $allocation_version = $this->allocation_version($assignment);

        if ($landing_key === '' || $service_key === '' || !$this->is_supported_variant_key($variant_key)) {
            return false;
        }

        $provider_key = $this->sanitize_key((string) ($assignment['provider_key'] ?? ''), 50);
        $flow_key = $this->sanitize_key((string) ($assignment['flow_key'] ?? ''), 50);
        $now = $this->current_time_mysql();
        $table_name = $this->get_summary_table_name();

        if ($create_missing) {
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
                        allocation_version,
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
                    $seed,
                    $allocation_version
                )
            );

            if ($upsert_result === false) {
                return false;
            }
        }

        $increment_result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table_name}
                 SET updated_at = %s,
                     {$counter} = {$counter} + 1
                 WHERE landing_key = %s
                   AND service_key = %s
                   AND variant_key = %s
                   AND seed = %s
                   AND allocation_version = %s",
                $now,
                $landing_key,
                $service_key,
                $variant_key,
                $seed,
                $allocation_version
            )
        );

        if ($increment_result !== 1) {
            return false;
        }

        $rates = $this->summary_rate_assignments($this->summary_rate_values());
        $rate_result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table_name}
                 SET updated_at = %s, {$rates}
                 WHERE landing_key = %s
                   AND service_key = %s
                   AND variant_key = %s
                   AND seed = %s
                   AND allocation_version = %s",
                $now,
                $landing_key,
                $service_key,
                $variant_key,
                $seed,
                $allocation_version
            )
        );

        return $rate_result !== false;
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

        return in_array($counter, self::SUMMARY_COUNTERS, true) ? $counter : '';
    }

    private function is_supported_variant_key(string $variant_key): bool
    {
        return in_array($variant_key, [
            'as_is_txn_prefix',
            'bare_id',
            'game_word',
            'cta_phrase',
            'download_phrase',
        ], true);
    }

    private function allocation_version(array $assignment): string
    {
        $version = $this->sanitize_key((string) ($assignment['allocation_version'] ?? 'legacy'), 50);
        return $version !== '' ? $version : 'legacy';
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
