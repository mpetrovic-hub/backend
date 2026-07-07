<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Session_Raw_Context_Compaction_Service
{
    private const COMPACT_SCHEMA = 'landing_session_raw_context_compact_v1';
    private const LOCK_KEY = 'kiwi_landing_session_raw_context_compaction_lock';
    private const LAST_RESULT_OPTION = 'kiwi_landing_session_raw_context_compaction_last_result';
    private const TEMP_TABLE = 'tmp_landing_raw_context_compaction';

    private $config;

    public function __construct(?Kiwi_Config $config = null)
    {
        $this->config = $config instanceof Kiwi_Config ? $config : new Kiwi_Config();
    }

    public function run(string $triggered_by = 'cron'): array
    {
        $settings = $this->config->get_landing_session_raw_context_compaction_settings();
        $started_at = $this->current_time_mysql();
        $result = $this->build_base_result($settings, $triggered_by, $started_at);

        if (!$this->has_table_access()) {
            $result['success'] = false;
            $result['error_code'] = 'database_unavailable';
            $result['error_message'] = 'Landing-session raw-context compaction could not access the database.';

            return $this->persist_result($this->finish_result($result));
        }

        if ($this->has_lock()) {
            $result['success'] = true;
            $result['skipped_due_to_lock'] = true;
            $result['error_code'] = 'lock_active';
            $result['error_message'] = 'Landing-session raw-context compaction skipped because lock is active.';

            return $this->persist_result($this->finish_result($result));
        }

        $this->set_lock((int) $settings['lock_ttl_seconds']);
        $started_monotonic = microtime(true);

        try {
            if (empty($settings['enabled'])) {
                $result['success'] = true;
                $result['error_code'] = 'compaction_disabled';
                $result['error_message'] = 'Landing-session raw-context compaction is disabled.';

                return $this->persist_result($this->finish_result($result));
            }

            $this->create_temp_table();
            $processed_rows = $this->populate_candidates($result['cutoff_value'], (int) $settings['row_limit']);
            $totals = $this->read_candidate_totals();

            $result['processed_rows'] = $processed_rows;
            $result['compacted_rows'] = 0;
            $result['bytes_before'] = (int) ($totals['bytes_before'] ?? 0);
            $result['bytes_after'] = (int) ($totals['bytes_after'] ?? 0);
            $result['saving_bytes'] = max(0, $result['bytes_before'] - $result['bytes_after']);
            $this->add_diagnostics($result);

            if (empty($settings['dry_run']) && $processed_rows > 0) {
                if ((microtime(true) - $started_monotonic) < (int) $settings['time_limit_seconds']) {
                    $result['compacted_rows'] = $this->update_candidates($result['cutoff_value']);
                } else {
                    $result['has_more'] = true;
                    $result['error_code'] = 'time_limit_reached_before_update';
                    $result['error_message'] = 'Landing-session raw-context compaction reached its time limit before the update phase.';
                }
            }

            if (!empty($settings['dry_run'])) {
                $result['has_more'] = $result['eligible_rows'] > $processed_rows;
            } elseif (!$result['has_more']) {
                $result['has_more'] = $this->count_eligible_rows($result['cutoff_value']) > 0;
            }
            $result['success'] = true;

            return $this->persist_result($this->finish_result($result));
        } catch (Throwable $error) {
            $result['success'] = false;
            $result['error_code'] = 'compaction_failed';
            $result['error_message'] = $error->getMessage();

            return $this->persist_result($this->finish_result($result));
        } finally {
            $this->drop_temp_table();
            $this->clear_lock();
        }
    }

    protected function build_base_result(array $settings, string $triggered_by, string $started_at): array
    {
        return [
            'success' => false,
            'triggered_by' => $this->normalize_triggered_by($triggered_by),
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'dry_run' => (bool) ($settings['dry_run'] ?? true),
            'cutoff_value' => $this->build_cutoff_value((int) ($settings['age_days'] ?? 7)),
            'age_days' => (int) ($settings['age_days'] ?? 7),
            'row_limit' => (int) ($settings['row_limit'] ?? 20000),
            'time_limit_seconds' => (int) ($settings['time_limit_seconds'] ?? 60),
            'eligible_rows' => 0,
            'processed_rows' => 0,
            'compacted_rows' => 0,
            'empty_raw_context_rows' => 0,
            'invalid_json_rows' => 0,
            'already_compact_rows' => 0,
            'bytes_before' => 0,
            'bytes_after' => 0,
            'saving_bytes' => 0,
            'started_at' => $started_at,
            'finished_at' => '',
            'skipped_due_to_lock' => false,
            'has_more' => false,
            'error_code' => '',
            'error_message' => '',
        ];
    }

    protected function add_diagnostics(array &$result): void
    {
        $cutoff_value = (string) ($result['cutoff_value'] ?? '');

        $result['eligible_rows'] = $this->count_eligible_rows($cutoff_value);
        $result['empty_raw_context_rows'] = $this->count_empty_raw_context_rows($cutoff_value);
        $result['invalid_json_rows'] = $this->count_invalid_json_rows($cutoff_value);
        $result['already_compact_rows'] = $this->count_already_compact_rows($cutoff_value);
    }

    protected function create_temp_table(): void
    {
        global $wpdb;

        $created = $wpdb->query(
            'CREATE TEMPORARY TABLE ' . self::TEMP_TABLE . ' (
                id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                bytes_before BIGINT UNSIGNED NOT NULL,
                compact_raw_context LONGTEXT NOT NULL
            ) ENGINE=InnoDB'
        );

        if ($created === false) {
            throw new RuntimeException($this->db_error_message('Could not create landing-session raw-context compaction temp table.'));
        }
    }

    protected function populate_candidates(string $cutoff_value, int $row_limit): int
    {
        global $wpdb;

        $table = $this->get_table_name();
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO " . self::TEMP_TABLE . " (id, bytes_before, compact_raw_context)
                 SELECT
                    id,
                    CHAR_LENGTH(raw_context),
                    JSON_OBJECT(
                        'schema', %s,
                        'landing_page', JSON_OBJECT(
                            'key', COALESCE(NULLIF(landing_key, ''), JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.landing_page.key'))),
                            'country', COALESCE(NULLIF(country, ''), JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.landing_page.country'))),
                            'flow', COALESCE(NULLIF(flow_key, ''), JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.landing_page.flow'))),
                            'provider', COALESCE(NULLIF(provider_key, ''), JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.landing_page.provider'))),
                            'locale', JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.landing_page.locale')),
                            'service_type', JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.landing_page.service_type')),
                            'business_number', JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.landing_page.business_number')),
                            'keyword', JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.landing_page.keyword')),
                            'service_key', COALESCE(NULLIF(service_key, ''), JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.landing_page.service_key'))),
                            'shortcode', JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.landing_page.shortcode')),
                            'price_label', JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.landing_page.price_label')),
                            'kpi_cta_steps', JSON_EXTRACT(raw_context, '$.landing_page.kpi_cta_steps'),
                            'render_mode', JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.landing_page.render_mode')),
                            'folder_name', JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.landing_page.folder_name')),
                            'cta_href', JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.landing_page.cta_href'))
                        ),
                        'client_ip_resolution', JSON_OBJECT(
                            'source', JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.client_ip_resolution.source')),
                            'peer_trusted', JSON_EXTRACT(raw_context, '$.client_ip_resolution.peer_trusted'),
                            'trusted_proxy_configured', JSON_EXTRACT(raw_context, '$.client_ip_resolution.trusted_proxy_configured'),
                            'forwarded_headers_present', JSON_EXTRACT(raw_context, '$.client_ip_resolution.forwarded_headers_present'),
                            'other_client_ip_headers_present', JSON_EXTRACT(raw_context, '$.client_ip_resolution.other_client_ip_headers_present'),
                            'forwarded_candidate_count', JSON_EXTRACT(raw_context, '$.client_ip_resolution.forwarded_candidate_count'),
                            'resolution_reason', JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.client_ip_resolution.resolution_reason'))
                        )
                    )
                 FROM {$table}
                 WHERE created_at < %s
                   AND raw_context IS NOT NULL
                   AND raw_context <> ''
                   AND JSON_VALID(raw_context)
                   AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.schema')), '') <> %s
                 ORDER BY id ASC
                 LIMIT %d",
                self::COMPACT_SCHEMA,
                $cutoff_value,
                self::COMPACT_SCHEMA,
                max(1, $row_limit)
            )
        );

        if ($inserted === false) {
            throw new RuntimeException($this->db_error_message('Could not populate landing-session raw-context compaction candidates.'));
        }

        return (int) $inserted;
    }

    protected function read_candidate_totals(): array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            'SELECT COUNT(*) AS processed_rows,
                    COALESCE(SUM(bytes_before), 0) AS bytes_before,
                    COALESCE(SUM(CHAR_LENGTH(compact_raw_context)), 0) AS bytes_after
             FROM ' . self::TEMP_TABLE,
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    protected function update_candidates(string $cutoff_value): int
    {
        global $wpdb;

        $table = $this->get_table_name();
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} s
                 JOIN " . self::TEMP_TABLE . " t ON t.id = s.id
                 SET s.raw_context = t.compact_raw_context
                 WHERE s.created_at < %s
                   AND s.raw_context IS NOT NULL
                   AND s.raw_context <> ''
                   AND JSON_VALID(s.raw_context)
                   AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.raw_context, '$.schema')), '') <> %s",
                $cutoff_value,
                self::COMPACT_SCHEMA
            )
        );

        if ($updated === false) {
            throw new RuntimeException($this->db_error_message('Could not update landing-session raw_context compact candidates.'));
        }

        return (int) $updated;
    }

    protected function count_eligible_rows(string $cutoff_value): int
    {
        return $this->count_rows(
            "created_at < %s
             AND raw_context IS NOT NULL
             AND raw_context <> ''
             AND JSON_VALID(raw_context)
             AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.schema')), '') <> %s",
            [$cutoff_value, self::COMPACT_SCHEMA]
        );
    }

    protected function count_empty_raw_context_rows(string $cutoff_value): int
    {
        return $this->count_rows(
            "created_at < %s AND (raw_context IS NULL OR raw_context = '')",
            [$cutoff_value]
        );
    }

    protected function count_invalid_json_rows(string $cutoff_value): int
    {
        return $this->count_rows(
            "created_at < %s
             AND raw_context IS NOT NULL
             AND raw_context <> ''
             AND NOT JSON_VALID(raw_context)",
            [$cutoff_value]
        );
    }

    protected function count_already_compact_rows(string $cutoff_value): int
    {
        return $this->count_rows(
            "created_at < %s
             AND raw_context IS NOT NULL
             AND raw_context <> ''
             AND JSON_VALID(raw_context)
             AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw_context, '$.schema')), '') = %s",
            [$cutoff_value, self::COMPACT_SCHEMA]
        );
    }

    private function count_rows(string $where_sql, array $args): int
    {
        global $wpdb;

        $table = $this->get_table_name();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$table}
                 WHERE {$where_sql}",
                ...$args
            )
        );
    }

    protected function drop_temp_table(): void
    {
        global $wpdb;

        if (is_object($wpdb) && method_exists($wpdb, 'query')) {
            $wpdb->query('DROP TEMPORARY TABLE IF EXISTS ' . self::TEMP_TABLE);
        }
    }

    protected function has_table_access(): bool
    {
        global $wpdb;

        return is_object($wpdb)
            && method_exists($wpdb, 'prepare')
            && method_exists($wpdb, 'query')
            && method_exists($wpdb, 'get_var')
            && method_exists($wpdb, 'get_row')
            && $this->is_identifier($this->get_table_name());
    }

    protected function build_cutoff_value(int $age_days): string
    {
        $current_date = substr($this->current_time_mysql(), 0, 10);
        $timestamp = strtotime($current_date . ' -' . max(1, $age_days) . ' days');

        return ($timestamp === false ? $current_date : gmdate('Y-m-d', $timestamp)) . ' 00:00:00';
    }

    protected function persist_result(array $result): array
    {
        if (function_exists('update_option')) {
            update_option(self::LAST_RESULT_OPTION, $result, false);
        }

        return $result;
    }

    protected function finish_result(array $result): array
    {
        $result['finished_at'] = $this->current_time_mysql();

        return $result;
    }

    protected function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            $time = current_time('mysql');

            if (is_string($time) && $time !== '') {
                return $time;
            }
        }

        return gmdate('Y-m-d H:i:s');
    }

    private function get_table_name(): string
    {
        global $wpdb;

        return (string) ($wpdb->prefix ?? '') . 'kiwi_landing_page_sessions';
    }

    private function has_lock(): bool
    {
        return function_exists('get_transient')
            && get_transient(self::LOCK_KEY) !== false;
    }

    private function set_lock(int $ttl_seconds): void
    {
        if (function_exists('set_transient')) {
            set_transient(self::LOCK_KEY, '1', max(60, $ttl_seconds));
        }
    }

    private function clear_lock(): void
    {
        if (function_exists('delete_transient')) {
            delete_transient(self::LOCK_KEY);
        }
    }

    private function normalize_triggered_by(string $triggered_by): string
    {
        $triggered_by = strtolower(trim($triggered_by));

        return in_array($triggered_by, ['cron', 'manual', 'wp_cli'], true) ? $triggered_by : 'manual';
    }

    private function db_error_message(string $fallback): string
    {
        global $wpdb;

        $error = is_object($wpdb) ? trim((string) ($wpdb->last_error ?? '')) : '';

        return $error !== '' ? $fallback . ' ' . $error : $fallback;
    }

    private function is_identifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1;
    }
}
