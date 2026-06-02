<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Premium_Sms_Landing_Engagement_Repository
{
    private const CTA_STEPS = ['cta1', 'cta2', 'cta3'];

    private $soft_flag_service;

    public function __construct(?Kiwi_Premium_Sms_Landing_Engagement_Soft_Flag_Service $soft_flag_service = null)
    {
        $this->soft_flag_service = $soft_flag_service instanceof Kiwi_Premium_Sms_Landing_Engagement_Soft_Flag_Service
            ? $soft_flag_service
            : new Kiwi_Premium_Sms_Landing_Engagement_Soft_Flag_Service();
    }

    private function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_premium_sms_landing_engagements';
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
            provider_key VARCHAR(50) NOT NULL DEFAULT '',
            service_key VARCHAR(100) NOT NULL DEFAULT '',
            flow_key VARCHAR(50) NOT NULL DEFAULT '',
            pid VARCHAR(191) NOT NULL DEFAULT '',
            click_id VARCHAR(191) NOT NULL DEFAULT '',
            tksource VARCHAR(191) NOT NULL DEFAULT '',
            tkzone VARCHAR(191) NOT NULL DEFAULT '',
            landing_key VARCHAR(100) NOT NULL DEFAULT '',
            session_token VARCHAR(150) NOT NULL DEFAULT '',
            page_loaded_at DATETIME NULL,
            first_cta_click_at DATETIME NULL,
            last_cta_click_at DATETIME NULL,
            cta_click_count INT UNSIGNED NOT NULL DEFAULT 0,
            first_cta1_click_at DATETIME NULL,
            last_cta1_click_at DATETIME NULL,
            cta1_click_count INT UNSIGNED NOT NULL DEFAULT 0,
            first_cta2_click_at DATETIME NULL,
            last_cta2_click_at DATETIME NULL,
            cta2_click_count INT UNSIGNED NOT NULL DEFAULT 0,
            first_cta3_click_at DATETIME NULL,
            last_cta3_click_at DATETIME NULL,
            cta3_click_count INT UNSIGNED NOT NULL DEFAULT 0,
            ua_ch_supported TINYINT(1) NOT NULL DEFAULT 0,
            ua_ch_mobile TINYINT(1) NOT NULL DEFAULT 0,
            ua_ch_platform VARCHAR(50) NOT NULL DEFAULT '',
            ua_ch_platform_version VARCHAR(50) NOT NULL DEFAULT '',
            ua_ch_model VARCHAR(191) NOT NULL DEFAULT '',
            ua_ch_brands TEXT NULL,
            ua_ch_full_version_list TEXT NULL,
            user_agent TEXT NULL,
            last_event_at DATETIME NOT NULL,
            is_soft_flag TINYINT(1) NOT NULL DEFAULT 0,
            soft_flag_reason VARCHAR(191) NOT NULL DEFAULT '',
            soft_flag_rule_key VARCHAR(100) NOT NULL DEFAULT '',
            soft_flag_evaluated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY landing_session (landing_key, session_token),
            KEY service_key (service_key),
            KEY provider_key (provider_key),
            KEY flow_key (flow_key),
            KEY pid (pid),
            KEY click_id (click_id),
            KEY tksource (tksource),
            KEY tkzone (tkzone),
            KEY updated_at (updated_at),
            KEY is_soft_flag_updated (is_soft_flag, updated_at, id),
            KEY created_landing_session (created_at, landing_key, session_token)
        ) {$charset_collate};";

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta($sql);
    }

    public function upsert_event(array $context, string $event_type, string $occurred_at = ''): array
    {
        $landing_key = trim((string) ($context['landing_key'] ?? ''));
        $session_token = trim((string) ($context['session_token'] ?? ''));
        $event_type = strtolower(trim($event_type));
        $occurred_at = $this->normalize_mysql_datetime($occurred_at);
        $cta_step = $event_type === 'cta_click'
            ? $this->normalize_cta_step((string) ($context['cta_step'] ?? ''))
            : '';

        if ($landing_key === '' || $session_token === '' || !$this->is_supported_event_type($event_type)) {
            return [];
        }

        $row = $this->get_by_landing_session($landing_key, $session_token);
        $now = $this->current_time_mysql();

        if (!is_array($row)) {
            $initial_row = [
                'created_at' => $now,
                'updated_at' => $now,
                'provider_key' => trim((string) ($context['provider_key'] ?? '')),
                'service_key' => trim((string) ($context['service_key'] ?? '')),
                'flow_key' => trim((string) ($context['flow_key'] ?? '')),
                'pid' => $this->sanitize_pid((string) ($context['pid'] ?? '')),
                'click_id' => $this->sanitize_click_id((string) ($context['click_id'] ?? '')),
                'tksource' => $this->sanitize_source_value((string) ($context['tksource'] ?? '')),
                'tkzone' => $this->sanitize_source_value((string) ($context['tkzone'] ?? '')),
                'landing_key' => $landing_key,
                'session_token' => $session_token,
                'page_loaded_at' => null,
                'first_cta_click_at' => null,
                'last_cta_click_at' => null,
                'cta_click_count' => 0,
                'first_cta1_click_at' => null,
                'last_cta1_click_at' => null,
                'cta1_click_count' => 0,
                'first_cta2_click_at' => null,
                'last_cta2_click_at' => null,
                'cta2_click_count' => 0,
                'first_cta3_click_at' => null,
                'last_cta3_click_at' => null,
                'cta3_click_count' => 0,
                'last_event_at' => $occurred_at,
            ];
            $initial_row = array_merge($initial_row, $this->normalize_ua_context($context));

            if ($event_type === 'page_loaded') {
                $initial_row['page_loaded_at'] = $occurred_at;
            } elseif ($event_type === 'cta_click') {
                $initial_row['first_cta_click_at'] = $occurred_at;
                $initial_row['last_cta_click_at'] = $occurred_at;
                $initial_row['cta_click_count'] = 1;
                $this->apply_cta_step_initial_values($initial_row, $cta_step, $occurred_at);
            }

            $initial_row = array_merge($initial_row, $this->build_soft_flag_data($initial_row, $now));

            $this->insert_row($initial_row);

            return $this->get_by_landing_session($landing_key, $session_token) ?? [];
        }

        $update_data = [
            'updated_at' => $now,
            'last_event_at' => $occurred_at,
            'provider_key' => $this->prefer_non_empty(
                trim((string) ($row['provider_key'] ?? '')),
                trim((string) ($context['provider_key'] ?? ''))
            ),
            'service_key' => $this->prefer_non_empty(
                trim((string) ($row['service_key'] ?? '')),
                trim((string) ($context['service_key'] ?? ''))
            ),
            'flow_key' => $this->prefer_non_empty(
                trim((string) ($row['flow_key'] ?? '')),
                trim((string) ($context['flow_key'] ?? ''))
            ),
            'pid' => $this->prefer_non_empty(
                trim((string) ($row['pid'] ?? '')),
                $this->sanitize_pid((string) ($context['pid'] ?? ''))
            ),
            'click_id' => $this->prefer_non_empty(
                trim((string) ($row['click_id'] ?? '')),
                $this->sanitize_click_id((string) ($context['click_id'] ?? ''))
            ),
            'tksource' => $this->prefer_non_empty(
                trim((string) ($row['tksource'] ?? '')),
                $this->sanitize_source_value((string) ($context['tksource'] ?? ''))
            ),
            'tkzone' => $this->prefer_non_empty(
                trim((string) ($row['tkzone'] ?? '')),
                $this->sanitize_source_value((string) ($context['tkzone'] ?? ''))
            ),
        ];
        $update_data = array_merge($update_data, $this->build_ua_update_data($row, $context));

        if ($event_type === 'page_loaded') {
            $existing_loaded_at = trim((string) ($row['page_loaded_at'] ?? ''));

            if ($existing_loaded_at === '') {
                $update_data['page_loaded_at'] = $occurred_at;
            }
        } elseif ($event_type === 'cta_click') {
            $existing_first_click = trim((string) ($row['first_cta_click_at'] ?? ''));

            if ($existing_first_click === '') {
                $update_data['first_cta_click_at'] = $occurred_at;
            }

            $update_data['last_cta_click_at'] = $occurred_at;
            $update_data['cta_click_count'] = max(0, (int) ($row['cta_click_count'] ?? 0)) + 1;
            $this->apply_cta_step_update_values($update_data, $row, $cta_step, $occurred_at);
        }

        $candidate_row = array_merge($row, $update_data);
        $update_data = array_merge($update_data, $this->build_soft_flag_data($candidate_row, $now));

        $this->update_by_id((int) ($row['id'] ?? 0), $update_data);

        return $this->get_by_landing_session($landing_key, $session_token) ?? [];
    }

    public function get_by_landing_session(string $landing_key, string $session_token): ?array
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
                 LIMIT 1",
                $landing_key,
                $session_token
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function get_recent(array $filters = [], int $limit = 100): array
    {
        global $wpdb;

        $limit = max(1, min(500, $limit));
        $where_sql = ['1 = 1'];
        $params = [];

        $service_key = trim((string) ($filters['service_key'] ?? ''));
        if ($service_key !== '') {
            $where_sql[] = 'service_key = %s';
            $params[] = $service_key;
        }

        $provider_key = trim((string) ($filters['provider_key'] ?? ''));
        if ($provider_key !== '') {
            $where_sql[] = 'provider_key = %s';
            $params[] = $provider_key;
        }

        $flow_key = trim((string) ($filters['flow_key'] ?? ''));
        if ($flow_key !== '') {
            $where_sql[] = 'flow_key = %s';
            $params[] = $flow_key;
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

        $tksource = $this->sanitize_source_value((string) ($filters['tksource'] ?? ''));
        if ($tksource !== '') {
            $where_sql[] = 'tksource = %s';
            $params[] = $tksource;
        }

        $tkzone = $this->sanitize_source_value((string) ($filters['tkzone'] ?? ''));
        if ($tkzone !== '') {
            $where_sql[] = 'tkzone = %s';
            $params[] = $tkzone;
        }

        $landing_key = trim((string) ($filters['landing_key'] ?? ''));
        if ($landing_key !== '') {
            $where_sql[] = 'landing_key = %s';
            $params[] = $landing_key;
        }

        $session_token = trim((string) ($filters['session_token'] ?? ''));
        if ($session_token !== '') {
            $where_sql[] = 'session_token = %s';
            $params[] = $session_token;
        }

        if (!empty($filters['flagged_only'])) {
            $where_sql[] = 'is_soft_flag = 1';
        }

        $params[] = $limit;

        $sql = "SELECT *
                FROM {$this->get_table_name()}
                WHERE " . implode(' AND ', $where_sql) . '
                ORDER BY updated_at DESC, id DESC
                LIMIT %d';

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, ...$params),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    protected function insert_row(array $data): bool
    {
        global $wpdb;

        $result = $wpdb->insert(
            $this->get_table_name(),
            [
                'created_at' => (string) ($data['created_at'] ?? $this->current_time_mysql()),
                'updated_at' => (string) ($data['updated_at'] ?? $this->current_time_mysql()),
                'provider_key' => (string) ($data['provider_key'] ?? ''),
                'service_key' => (string) ($data['service_key'] ?? ''),
                'flow_key' => (string) ($data['flow_key'] ?? ''),
                'pid' => $this->sanitize_pid((string) ($data['pid'] ?? '')),
                'click_id' => $this->sanitize_click_id((string) ($data['click_id'] ?? '')),
                'tksource' => $this->sanitize_source_value((string) ($data['tksource'] ?? '')),
                'tkzone' => $this->sanitize_source_value((string) ($data['tkzone'] ?? '')),
                'landing_key' => (string) ($data['landing_key'] ?? ''),
                'session_token' => (string) ($data['session_token'] ?? ''),
                'page_loaded_at' => $this->normalize_nullable_datetime($data['page_loaded_at'] ?? null),
                'first_cta_click_at' => $this->normalize_nullable_datetime($data['first_cta_click_at'] ?? null),
                'last_cta_click_at' => $this->normalize_nullable_datetime($data['last_cta_click_at'] ?? null),
                'cta_click_count' => max(0, (int) ($data['cta_click_count'] ?? 0)),
                'first_cta1_click_at' => $this->normalize_nullable_datetime($data['first_cta1_click_at'] ?? null),
                'last_cta1_click_at' => $this->normalize_nullable_datetime($data['last_cta1_click_at'] ?? null),
                'cta1_click_count' => max(0, (int) ($data['cta1_click_count'] ?? 0)),
                'first_cta2_click_at' => $this->normalize_nullable_datetime($data['first_cta2_click_at'] ?? null),
                'last_cta2_click_at' => $this->normalize_nullable_datetime($data['last_cta2_click_at'] ?? null),
                'cta2_click_count' => max(0, (int) ($data['cta2_click_count'] ?? 0)),
                'first_cta3_click_at' => $this->normalize_nullable_datetime($data['first_cta3_click_at'] ?? null),
                'last_cta3_click_at' => $this->normalize_nullable_datetime($data['last_cta3_click_at'] ?? null),
                'cta3_click_count' => max(0, (int) ($data['cta3_click_count'] ?? 0)),
                'ua_ch_supported' => !empty($data['ua_ch_supported']) ? 1 : 0,
                'ua_ch_mobile' => !empty($data['ua_ch_mobile']) ? 1 : 0,
                'ua_ch_platform' => $this->sanitize_client_hint_token((string) ($data['ua_ch_platform'] ?? ''), 50),
                'ua_ch_platform_version' => $this->sanitize_client_hint_token((string) ($data['ua_ch_platform_version'] ?? ''), 50),
                'ua_ch_model' => $this->sanitize_client_hint_text((string) ($data['ua_ch_model'] ?? ''), 191),
                'ua_ch_brands' => $this->sanitize_client_hint_text((string) ($data['ua_ch_brands'] ?? ''), 1000),
                'ua_ch_full_version_list' => $this->sanitize_client_hint_text((string) ($data['ua_ch_full_version_list'] ?? ''), 1000),
                'user_agent' => $this->sanitize_client_hint_text((string) ($data['user_agent'] ?? ''), 1000),
                'last_event_at' => (string) ($data['last_event_at'] ?? $this->current_time_mysql()),
                'is_soft_flag' => !empty($data['is_soft_flag']) ? 1 : 0,
                'soft_flag_reason' => $this->sanitize_soft_flag_reason((string) ($data['soft_flag_reason'] ?? '')),
                'soft_flag_rule_key' => $this->sanitize_soft_flag_rule_key((string) ($data['soft_flag_rule_key'] ?? '')),
                'soft_flag_evaluated_at' => $this->normalize_nullable_datetime($data['soft_flag_evaluated_at'] ?? null),
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
                '%d',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
                '%d',
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
                '%s',
            ]
        );

        return $result !== false;
    }

    protected function update_by_id(int $id, array $data): bool
    {
        global $wpdb;

        if ($id <= 0) {
            return false;
        }

        $fields = [];
        $formats = [];
        $allowed_fields = [
            'updated_at' => '%s',
            'provider_key' => '%s',
            'service_key' => '%s',
            'flow_key' => '%s',
            'pid' => '%s',
            'click_id' => '%s',
            'tksource' => '%s',
            'tkzone' => '%s',
            'page_loaded_at' => '%s',
            'first_cta_click_at' => '%s',
            'last_cta_click_at' => '%s',
            'cta_click_count' => '%d',
            'first_cta1_click_at' => '%s',
            'last_cta1_click_at' => '%s',
            'cta1_click_count' => '%d',
            'first_cta2_click_at' => '%s',
            'last_cta2_click_at' => '%s',
            'cta2_click_count' => '%d',
            'first_cta3_click_at' => '%s',
            'last_cta3_click_at' => '%s',
            'cta3_click_count' => '%d',
            'ua_ch_supported' => '%d',
            'ua_ch_mobile' => '%d',
            'ua_ch_platform' => '%s',
            'ua_ch_platform_version' => '%s',
            'ua_ch_model' => '%s',
            'ua_ch_brands' => '%s',
            'ua_ch_full_version_list' => '%s',
            'user_agent' => '%s',
            'last_event_at' => '%s',
            'is_soft_flag' => '%d',
            'soft_flag_reason' => '%s',
            'soft_flag_rule_key' => '%s',
            'soft_flag_evaluated_at' => '%s',
        ];

        foreach ($allowed_fields as $field => $format) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            if (in_array($field, $this->datetime_fields(), true)) {
                $value = $this->normalize_nullable_datetime($value);
            }

            if (in_array($field, $this->count_fields(), true)) {
                $value = max(0, (int) $value);
            }

            if (in_array($field, ['ua_ch_supported', 'ua_ch_mobile'], true)) {
                $value = !empty($value) ? 1 : 0;
            }

            if ($field === 'pid') {
                $value = $this->sanitize_pid((string) $value);
            }

            if ($field === 'click_id') {
                $value = $this->sanitize_click_id((string) $value);
            }

            if (in_array($field, ['tksource', 'tkzone'], true)) {
                $value = $this->sanitize_source_value((string) $value);
            }

            if (in_array($field, ['ua_ch_platform', 'ua_ch_platform_version'], true)) {
                $value = $this->sanitize_client_hint_token((string) $value, 50);
            }

            if ($field === 'ua_ch_model') {
                $value = $this->sanitize_client_hint_text((string) $value, 191);
            }

            if (in_array($field, ['ua_ch_brands', 'ua_ch_full_version_list', 'user_agent'], true)) {
                $value = $this->sanitize_client_hint_text((string) $value, 1000);
            }

            if ($field === 'is_soft_flag') {
                $value = !empty($value) ? 1 : 0;
            }

            if ($field === 'soft_flag_reason') {
                $value = $this->sanitize_soft_flag_reason((string) $value);
            }

            if ($field === 'soft_flag_rule_key') {
                $value = $this->sanitize_soft_flag_rule_key((string) $value);
            }

            $fields[$field] = $value;
            $formats[] = $format;
        }

        if (empty($fields)) {
            return true;
        }

        $result = $wpdb->update(
            $this->get_table_name(),
            $fields,
            ['id' => $id],
            $formats,
            ['%d']
        );

        return $result !== false;
    }

    protected function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }

    protected function normalize_mysql_datetime(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return $this->current_time_mysql();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return $this->current_time_mysql();
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function is_supported_event_type(string $event_type): bool
    {
        return in_array($event_type, ['page_loaded', 'cta_click'], true);
    }

    private function normalize_cta_step(string $cta_step): string
    {
        $cta_step = strtolower(trim($cta_step));

        return in_array($cta_step, self::CTA_STEPS, true) ? $cta_step : '';
    }

    private function apply_cta_step_initial_values(array &$row, string $cta_step, string $occurred_at): void
    {
        if ($cta_step === '') {
            return;
        }

        $row['first_' . $cta_step . '_click_at'] = $occurred_at;
        $row['last_' . $cta_step . '_click_at'] = $occurred_at;
        $row[$cta_step . '_click_count'] = 1;
    }

    private function apply_cta_step_update_values(array &$update_data, array $row, string $cta_step, string $occurred_at): void
    {
        if ($cta_step === '') {
            return;
        }

        $first_field = 'first_' . $cta_step . '_click_at';
        $last_field = 'last_' . $cta_step . '_click_at';
        $count_field = $cta_step . '_click_count';

        if (trim((string) ($row[$first_field] ?? '')) === '') {
            $update_data[$first_field] = $occurred_at;
        }

        $update_data[$last_field] = $occurred_at;
        $update_data[$count_field] = max(0, (int) ($row[$count_field] ?? 0)) + 1;
    }

    private function datetime_fields(): array
    {
        return [
            'page_loaded_at',
            'first_cta_click_at',
            'last_cta_click_at',
            'first_cta1_click_at',
            'last_cta1_click_at',
            'first_cta2_click_at',
            'last_cta2_click_at',
            'first_cta3_click_at',
            'last_cta3_click_at',
            'soft_flag_evaluated_at',
        ];
    }

    private function count_fields(): array
    {
        return [
            'cta_click_count',
            'cta1_click_count',
            'cta2_click_count',
            'cta3_click_count',
        ];
    }

    private function normalize_nullable_datetime($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return $this->normalize_mysql_datetime($value);
    }

    private function prefer_non_empty(string $existing, string $candidate): string
    {
        return $existing !== '' ? $existing : $candidate;
    }

    private function normalize_ua_context(array $context): array
    {
        return [
            'ua_ch_supported' => !empty($context['ua_ch_supported']) ? 1 : 0,
            'ua_ch_mobile' => !empty($context['ua_ch_mobile']) ? 1 : 0,
            'ua_ch_platform' => $this->sanitize_client_hint_token((string) ($context['ua_ch_platform'] ?? ''), 50),
            'ua_ch_platform_version' => $this->sanitize_client_hint_token((string) ($context['ua_ch_platform_version'] ?? ''), 50),
            'ua_ch_model' => $this->sanitize_client_hint_text((string) ($context['ua_ch_model'] ?? ''), 191),
            'ua_ch_brands' => $this->sanitize_client_hint_text((string) ($context['ua_ch_brands'] ?? ''), 1000),
            'ua_ch_full_version_list' => $this->sanitize_client_hint_text((string) ($context['ua_ch_full_version_list'] ?? ''), 1000),
            'user_agent' => $this->sanitize_client_hint_text((string) ($context['user_agent'] ?? ''), 1000),
        ];
    }

    private function build_ua_update_data(array $row, array $context): array
    {
        $normalized = $this->normalize_ua_context($context);
        $updates = [];

        foreach (['ua_ch_supported', 'ua_ch_mobile'] as $field) {
            if ((int) ($normalized[$field] ?? 0) > (int) ($row[$field] ?? 0)) {
                $updates[$field] = (int) $normalized[$field];
            }
        }

        foreach ([
            'ua_ch_platform',
            'ua_ch_platform_version',
            'ua_ch_model',
            'ua_ch_brands',
            'ua_ch_full_version_list',
            'user_agent',
        ] as $field) {
            $existing = trim((string) ($row[$field] ?? ''));
            $candidate = trim((string) ($normalized[$field] ?? ''));

            if ($existing === '' && $candidate !== '') {
                $updates[$field] = $candidate;
            }
        }

        return $updates;
    }

    private function build_soft_flag_data(array $row, string $evaluated_at): array
    {
        $result = $this->soft_flag_service->evaluate($row);

        return [
            'is_soft_flag' => !empty($result['is_soft_flag']) ? 1 : 0,
            'soft_flag_reason' => $this->sanitize_soft_flag_reason((string) ($result['soft_flag_reason'] ?? '')),
            'soft_flag_rule_key' => $this->sanitize_soft_flag_rule_key((string) ($result['soft_flag_rule_key'] ?? '')),
            'soft_flag_evaluated_at' => $this->normalize_mysql_datetime($evaluated_at),
        ];
    }

    private function sanitize_client_hint_token(string $value, int $max_length): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9._~:+ -]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, max(1, $max_length));
    }

    private function sanitize_client_hint_text(string $value, int $max_length): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^\P{C}\r\n\t]/u', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, max(1, $max_length));
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

    private function sanitize_soft_flag_reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            return '';
        }

        $reason = preg_replace('/[^A-Za-z0-9_<>:=| .-]/', '', $reason);
        $reason = is_string($reason) ? $reason : '';

        return substr($reason, 0, 191);
    }

    private function sanitize_soft_flag_rule_key(string $rule_key): string
    {
        $rule_key = trim($rule_key);

        if ($rule_key === '') {
            return '';
        }

        $rule_key = preg_replace('/[^A-Za-z0-9_.:-]/', '', $rule_key);
        $rule_key = is_string($rule_key) ? $rule_key : '';

        return substr($rule_key, 0, 100);
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
}
