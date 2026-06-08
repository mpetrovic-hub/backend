<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Sales_Repository
{
    private function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kiwi_sales';
    }

    public function get_table_name_for_schema(): string
    {
        return $this->get_table_name();
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
            sale_reference VARCHAR(100) NOT NULL DEFAULT '',
            transaction_id VARCHAR(120) NOT NULL DEFAULT '',
            pid VARCHAR(191) NOT NULL DEFAULT '',
            provider_key VARCHAR(50) NOT NULL DEFAULT '',
            service_key VARCHAR(100) NOT NULL DEFAULT '',
            country VARCHAR(10) NOT NULL DEFAULT '',
            flow_key VARCHAR(50) NOT NULL DEFAULT '',
            landing_key VARCHAR(100) NOT NULL DEFAULT '',
            session_ref VARCHAR(150) NOT NULL DEFAULT '',
            click_id VARCHAR(191) NOT NULL DEFAULT '',
            tksource VARCHAR(191) NOT NULL DEFAULT '',
            tkzone VARCHAR(191) NOT NULL DEFAULT '',
            device_brand VARCHAR(100) NOT NULL DEFAULT '',
            os VARCHAR(50) NOT NULL DEFAULT '',
            os_version VARCHAR(50) NOT NULL DEFAULT '',
            browser VARCHAR(100) NOT NULL DEFAULT '',
            attribution_metric_date DATE NULL,
            client_ip VARCHAR(100) NOT NULL DEFAULT '',
            client_ip_version VARCHAR(10) NOT NULL DEFAULT '',
            client_ip_prefix VARCHAR(120) NOT NULL DEFAULT '',
            client_ip_hash CHAR(64) NOT NULL DEFAULT '',
            sale_type VARCHAR(50) NOT NULL DEFAULT '',
            status VARCHAR(50) NOT NULL DEFAULT '',
            amount_minor INT NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT '',
            subscriber_reference VARCHAR(150) NOT NULL DEFAULT '',
            operator_code VARCHAR(100) NOT NULL DEFAULT '',
            operator_name VARCHAR(191) NOT NULL DEFAULT '',
            shortcode VARCHAR(50) NOT NULL DEFAULT '',
            keyword VARCHAR(100) NOT NULL DEFAULT '',
            external_sale_id VARCHAR(100) NOT NULL DEFAULT '',
            external_transaction_id VARCHAR(100) NOT NULL DEFAULT '',
            completed_at DATETIME NULL,
            context_json LONGTEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY sale_reference (sale_reference),
            KEY provider_key (provider_key),
            KEY service_key (service_key),
            KEY country (country),
            KEY flow_key (flow_key),
            KEY landing_key (landing_key),
            KEY session_ref (session_ref),
            KEY transaction_id (transaction_id),
            KEY pid (pid),
            KEY click_id (click_id),
            KEY tksource (tksource),
            KEY tkzone (tkzone),
            KEY device_brand (device_brand),
            KEY os (os),
            KEY os_version (os_version),
            KEY browser (browser),
            KEY attribution_metric_date (attribution_metric_date),
            KEY client_ip_prefix (client_ip_prefix),
            KEY client_ip_hash (client_ip_hash),
            KEY external_sale_id (external_sale_id),
            KEY created_at (created_at),
            KEY status_attribution_metric_date (status, attribution_metric_date),
            KEY status_completed_at (status, completed_at),
            KEY completed_subscriber_context (status, service_key, subscriber_reference, shortcode, keyword, completed_at)
        ) {$charset_collate};";

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta($sql);
    }

    public function upsert(array $data): array
    {
        $existing = $this->find_by_sale_reference((string) ($data['sale_reference'] ?? ''));

        if (is_array($existing)) {
            $this->update((int) $existing['id'], $data, $existing);

            return $this->find_by_sale_reference((string) ($data['sale_reference'] ?? '')) ?? $existing;
        }

        $id = $this->insert($data);

        return $this->get_by_id($id) ?? [];
    }

    public function find_by_sale_reference(string $sale_reference): ?array
    {
        global $wpdb;

        $sale_reference = trim($sale_reference);

        if ($sale_reference === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->get_table_name()} WHERE sale_reference = %s LIMIT 1",
                $sale_reference
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function find_recent_completed_one_off_sale_by_subscriber_context(
        string $service_key,
        string $subscriber_reference,
        string $shortcode,
        string $keyword,
        int $days
    ): ?array {
        global $wpdb;

        $service_key = trim($service_key);
        $subscriber_reference = trim($subscriber_reference);
        $shortcode = trim($shortcode);
        $keyword = trim($keyword);
        $days = max(0, $days);

        if ($service_key === '' || $subscriber_reference === '' || $shortcode === '' || $keyword === '' || $days === 0) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$this->get_table_name()}
                 WHERE status = 'completed'
                   AND sale_type = 'premium_sms_one_off'
                   AND service_key = %s
                   AND subscriber_reference = %s
                   AND shortcode = %s
                   AND keyword = %s
                   AND completed_at IS NOT NULL
                   AND completed_at >= DATE_SUB(%s, INTERVAL %d DAY)
                 ORDER BY completed_at DESC, id DESC
                 LIMIT 1",
                $service_key,
                $subscriber_reference,
                $shortcode,
                $keyword,
                $this->current_time_mysql(),
                $days
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function insert(array $data): int
    {
        global $wpdb;

        $now = $this->current_time_mysql();

        $wpdb->insert(
            $this->get_table_name(),
            [
                'created_at' => $now,
                'updated_at' => $now,
                'sale_reference' => $data['sale_reference'] ?? '',
                'transaction_id' => $data['transaction_id'] ?? '',
                'pid' => $data['pid'] ?? '',
                'provider_key' => $data['provider_key'] ?? '',
                'service_key' => $this->sanitize_key((string) ($data['service_key'] ?? ''), 100),
                'country' => $data['country'] ?? '',
                'flow_key' => $data['flow_key'] ?? '',
                'landing_key' => $this->sanitize_key((string) ($data['landing_key'] ?? ''), 100),
                'session_ref' => $this->sanitize_text_dimension((string) ($data['session_ref'] ?? ''), 150),
                'click_id' => $this->sanitize_source_value((string) ($data['click_id'] ?? '')),
                'tksource' => $this->sanitize_source_value((string) ($data['tksource'] ?? '')),
                'tkzone' => $this->sanitize_source_value((string) ($data['tkzone'] ?? '')),
                'device_brand' => $this->sanitize_text_dimension((string) ($data['device_brand'] ?? ''), 100),
                'os' => $this->sanitize_text_dimension((string) ($data['os'] ?? ''), 50),
                'os_version' => $this->sanitize_text_dimension((string) ($data['os_version'] ?? ''), 50),
                'browser' => $this->sanitize_text_dimension((string) ($data['browser'] ?? ''), 100),
                'attribution_metric_date' => $this->normalize_nullable_date((string) ($data['attribution_metric_date'] ?? '')),
                'client_ip' => $this->sanitize_client_ip((string) ($data['client_ip'] ?? '')),
                'client_ip_version' => $this->sanitize_ip_version((string) ($data['client_ip_version'] ?? '')),
                'client_ip_prefix' => $this->sanitize_text_dimension((string) ($data['client_ip_prefix'] ?? ''), 120),
                'client_ip_hash' => $this->sanitize_hash((string) ($data['client_ip_hash'] ?? '')),
                'sale_type' => $data['sale_type'] ?? '',
                'status' => $data['status'] ?? '',
                'amount_minor' => isset($data['amount_minor']) ? (int) $data['amount_minor'] : 0,
                'currency' => $data['currency'] ?? '',
                'subscriber_reference' => $data['subscriber_reference'] ?? '',
                'operator_code' => $data['operator_code'] ?? '',
                'operator_name' => $data['operator_name'] ?? '',
                'shortcode' => $data['shortcode'] ?? '',
                'keyword' => $data['keyword'] ?? '',
                'external_sale_id' => $data['external_sale_id'] ?? '',
                'external_transaction_id' => $data['external_transaction_id'] ?? '',
                'completed_at' => $data['completed_at'] ?? null,
                'context_json' => isset($data['context_json']) ? wp_json_encode($data['context_json']) : '',
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
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            ]
        );

        return (int) $wpdb->insert_id;
    }

    private function update(int $id, array $data, array $existing = []): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            $this->get_table_name(),
            [
                'updated_at' => $this->current_time_mysql(),
                'transaction_id' => array_key_exists('transaction_id', $data) ? (string) $data['transaction_id'] : (string) ($existing['transaction_id'] ?? ''),
                'pid' => array_key_exists('pid', $data) ? (string) $data['pid'] : (string) ($existing['pid'] ?? ''),
                'provider_key' => array_key_exists('provider_key', $data) ? (string) $data['provider_key'] : (string) ($existing['provider_key'] ?? ''),
                'service_key' => array_key_exists('service_key', $data)
                    ? $this->sanitize_key((string) $data['service_key'], 100)
                    : (string) ($existing['service_key'] ?? ''),
                'country' => array_key_exists('country', $data) ? (string) $data['country'] : (string) ($existing['country'] ?? ''),
                'flow_key' => array_key_exists('flow_key', $data) ? (string) $data['flow_key'] : (string) ($existing['flow_key'] ?? ''),
                'landing_key' => array_key_exists('landing_key', $data)
                    ? $this->sanitize_key((string) $data['landing_key'], 100)
                    : (string) ($existing['landing_key'] ?? ''),
                'session_ref' => array_key_exists('session_ref', $data)
                    ? $this->sanitize_text_dimension((string) $data['session_ref'], 150)
                    : (string) ($existing['session_ref'] ?? ''),
                'click_id' => array_key_exists('click_id', $data)
                    ? $this->sanitize_source_value((string) $data['click_id'])
                    : (string) ($existing['click_id'] ?? ''),
                'tksource' => array_key_exists('tksource', $data)
                    ? $this->sanitize_source_value((string) $data['tksource'])
                    : (string) ($existing['tksource'] ?? ''),
                'tkzone' => array_key_exists('tkzone', $data)
                    ? $this->sanitize_source_value((string) $data['tkzone'])
                    : (string) ($existing['tkzone'] ?? ''),
                'device_brand' => array_key_exists('device_brand', $data)
                    ? $this->sanitize_text_dimension((string) $data['device_brand'], 100)
                    : (string) ($existing['device_brand'] ?? ''),
                'os' => array_key_exists('os', $data)
                    ? $this->sanitize_text_dimension((string) $data['os'], 50)
                    : (string) ($existing['os'] ?? ''),
                'os_version' => array_key_exists('os_version', $data)
                    ? $this->sanitize_text_dimension((string) $data['os_version'], 50)
                    : (string) ($existing['os_version'] ?? ''),
                'browser' => array_key_exists('browser', $data)
                    ? $this->sanitize_text_dimension((string) $data['browser'], 100)
                    : (string) ($existing['browser'] ?? ''),
                'attribution_metric_date' => array_key_exists('attribution_metric_date', $data)
                    ? $this->normalize_nullable_date((string) $data['attribution_metric_date'])
                    : ($existing['attribution_metric_date'] ?? null),
                'client_ip' => array_key_exists('client_ip', $data)
                    ? $this->sanitize_client_ip((string) $data['client_ip'])
                    : (string) ($existing['client_ip'] ?? ''),
                'client_ip_version' => array_key_exists('client_ip_version', $data)
                    ? $this->sanitize_ip_version((string) $data['client_ip_version'])
                    : (string) ($existing['client_ip_version'] ?? ''),
                'client_ip_prefix' => array_key_exists('client_ip_prefix', $data)
                    ? $this->sanitize_text_dimension((string) $data['client_ip_prefix'], 120)
                    : (string) ($existing['client_ip_prefix'] ?? ''),
                'client_ip_hash' => array_key_exists('client_ip_hash', $data)
                    ? $this->sanitize_hash((string) $data['client_ip_hash'])
                    : (string) ($existing['client_ip_hash'] ?? ''),
                'sale_type' => array_key_exists('sale_type', $data) ? (string) $data['sale_type'] : (string) ($existing['sale_type'] ?? ''),
                'status' => array_key_exists('status', $data) ? (string) $data['status'] : (string) ($existing['status'] ?? ''),
                'amount_minor' => array_key_exists('amount_minor', $data) ? (int) $data['amount_minor'] : (int) ($existing['amount_minor'] ?? 0),
                'currency' => array_key_exists('currency', $data) ? (string) $data['currency'] : (string) ($existing['currency'] ?? ''),
                'subscriber_reference' => array_key_exists('subscriber_reference', $data) ? (string) $data['subscriber_reference'] : (string) ($existing['subscriber_reference'] ?? ''),
                'operator_code' => array_key_exists('operator_code', $data) ? (string) $data['operator_code'] : (string) ($existing['operator_code'] ?? ''),
                'operator_name' => array_key_exists('operator_name', $data) ? (string) $data['operator_name'] : (string) ($existing['operator_name'] ?? ''),
                'shortcode' => array_key_exists('shortcode', $data) ? (string) $data['shortcode'] : (string) ($existing['shortcode'] ?? ''),
                'keyword' => array_key_exists('keyword', $data) ? (string) $data['keyword'] : (string) ($existing['keyword'] ?? ''),
                'external_sale_id' => array_key_exists('external_sale_id', $data) ? (string) $data['external_sale_id'] : (string) ($existing['external_sale_id'] ?? ''),
                'external_transaction_id' => array_key_exists('external_transaction_id', $data) ? (string) $data['external_transaction_id'] : (string) ($existing['external_transaction_id'] ?? ''),
                'completed_at' => array_key_exists('completed_at', $data) ? $data['completed_at'] : ($existing['completed_at'] ?? null),
                'context_json' => array_key_exists('context_json', $data)
                    ? wp_json_encode($data['context_json'])
                    : (string) ($existing['context_json'] ?? ''),
            ],
            ['id' => $id],
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
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
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
            ],
            ['%d']
        );

        return $result !== false;
    }

    public function update_pid_by_sale_reference(string $sale_reference, string $pid): bool
    {
        global $wpdb;

        $sale_reference = trim($sale_reference);
        $pid = $this->sanitize_pid($pid);

        if ($sale_reference === '' || $pid === '') {
            return false;
        }

        $result = $wpdb->update(
            $this->get_table_name(),
            [
                'updated_at' => $this->current_time_mysql(),
                'pid' => $pid,
            ],
            ['sale_reference' => $sale_reference],
            ['%s', '%s'],
            ['%s']
        );

        return $result !== false;
    }

    public function update_attribution_snapshot_by_sale_reference(string $sale_reference, array $snapshot): bool
    {
        global $wpdb;

        $sale_reference = trim($sale_reference);

        if ($sale_reference === '') {
            return false;
        }

        $existing = $this->find_by_sale_reference($sale_reference);

        if (!is_array($existing)) {
            return false;
        }

        $fields = ['updated_at' => $this->current_time_mysql()];
        $formats = ['%s'];
        $allowed_fields = [
            'service_key' => '%s',
            'landing_key' => '%s',
            'session_ref' => '%s',
            'click_id' => '%s',
            'tksource' => '%s',
            'tkzone' => '%s',
            'device_brand' => '%s',
            'os' => '%s',
            'os_version' => '%s',
            'browser' => '%s',
            'attribution_metric_date' => '%s',
            'client_ip' => '%s',
            'client_ip_version' => '%s',
            'client_ip_prefix' => '%s',
            'client_ip_hash' => '%s',
        ];

        foreach ($allowed_fields as $field => $format) {
            if (!array_key_exists($field, $snapshot)) {
                continue;
            }

            $fields[$field] = $this->normalize_snapshot_field($field, $snapshot[$field]);
            $formats[] = $format;
        }

        if (isset($snapshot['attribution_snapshot']) && is_array($snapshot['attribution_snapshot'])) {
            $context = $this->decode_context_json($existing['context_json'] ?? null);
            $context['attribution_snapshot'] = $snapshot['attribution_snapshot'];
            $fields['context_json'] = wp_json_encode($context);
            $formats[] = '%s';
        }

        if (count($fields) === 1) {
            return true;
        }

        $result = $wpdb->update(
            $this->get_table_name(),
            $fields,
            ['sale_reference' => $sale_reference],
            $formats,
            ['%s']
        );

        return $result !== false;
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

    private function current_time_mysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
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

    private function normalize_snapshot_field(string $field, $value)
    {
        switch ($field) {
            case 'service_key':
            case 'landing_key':
                return $this->sanitize_key((string) $value, 100);

            case 'session_ref':
                return $this->sanitize_text_dimension((string) $value, 150);

            case 'click_id':
            case 'tksource':
            case 'tkzone':
                return $this->sanitize_source_value((string) $value);

            case 'device_brand':
            case 'browser':
                return $this->sanitize_text_dimension((string) $value, 100);

            case 'os':
            case 'os_version':
                return $this->sanitize_text_dimension((string) $value, 50);

            case 'attribution_metric_date':
                return $this->normalize_nullable_date((string) $value);

            case 'client_ip':
                return $this->sanitize_client_ip((string) $value);

            case 'client_ip_version':
                return $this->sanitize_ip_version((string) $value);

            case 'client_ip_prefix':
                return $this->sanitize_text_dimension((string) $value, 120);

            case 'client_ip_hash':
                return $this->sanitize_hash((string) $value);
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function decode_context_json($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function sanitize_key(string $value, int $max_length): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9._~:-]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, max(1, $max_length));
    }

    private function sanitize_source_value(string $value): string
    {
        return $this->sanitize_key($value, 191);
    }

    private function sanitize_text_dimension(string $value, int $max_length): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^\P{C}\r\n\t]/u', '', $value);
        $value = is_string($value) ? $value : '';
        $value = preg_replace('/\s+/', ' ', $value);
        $value = is_string($value) ? trim($value) : '';

        return substr($value, 0, max(1, $max_length));
    }

    private function sanitize_client_ip(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return filter_var($value, FILTER_VALIDATE_IP) ? substr($value, 0, 100) : '';
    }

    private function sanitize_ip_version(string $value): string
    {
        $value = strtolower(trim($value));

        if ($value === '4') {
            return 'ipv4';
        }

        if ($value === '6') {
            return 'ipv6';
        }

        return in_array($value, ['ipv4', 'ipv6', '(unknown)'], true) ? $value : '';
    }

    private function sanitize_hash(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : '';
    }

    private function normalize_nullable_date(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) === 1) {
            $year = (int) ($matches[1] ?? 0);
            $month = (int) ($matches[2] ?? 0);
            $day = (int) ($matches[3] ?? 0);

            return checkdate($month, $day, $year) ? $value : null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : gmdate('Y-m-d', $timestamp);
    }
}
