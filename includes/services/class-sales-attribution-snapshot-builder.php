<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Sales_Attribution_Snapshot_Builder
{
    private $landing_page_session_repository;
    private $landing_engagement_repository;
    private $device_context_normalizer;
    private $client_ip_resolver;

    public function __construct(
        ?Kiwi_Landing_Page_Session_Repository $landing_page_session_repository = null,
        ?Kiwi_Premium_Sms_Landing_Engagement_Repository $landing_engagement_repository = null,
        ?Kiwi_Device_Context_Normalizer $device_context_normalizer = null,
        ?Kiwi_Client_Ip_Resolver $client_ip_resolver = null
    ) {
        $this->landing_page_session_repository = $landing_page_session_repository;
        $this->landing_engagement_repository = $landing_engagement_repository;
        $this->device_context_normalizer = $device_context_normalizer instanceof Kiwi_Device_Context_Normalizer
            ? $device_context_normalizer
            : new Kiwi_Device_Context_Normalizer();
        $this->client_ip_resolver = $client_ip_resolver instanceof Kiwi_Client_Ip_Resolver
            ? $client_ip_resolver
            : new Kiwi_Client_Ip_Resolver();
    }

    public function build(array $attribution_row, array $sale = [], array $conversion = []): array
    {
        $landing_key = $this->first_non_empty([
            $attribution_row['landing_page_key'] ?? '',
            $sale['landing_key'] ?? '',
            $conversion['landing_key'] ?? '',
        ]);
        $session_ref = $this->first_non_empty([
            $attribution_row['session_ref'] ?? '',
            $sale['session_ref'] ?? '',
            $conversion['session_ref'] ?? '',
        ]);
        $service_key = $this->first_non_empty([
            $attribution_row['service_key'] ?? '',
            $sale['service_key'] ?? '',
            $conversion['service_key'] ?? '',
        ]);

        $landing_session = $this->find_landing_session($landing_key, $session_ref, $service_key);
        $engagement = $this->find_landing_engagement($landing_key, $session_ref);
        $device = $this->normalize_device_dimensions($engagement, $landing_session);
        $ip_snapshot = $this->build_client_ip_snapshot($landing_session);
        $metric_date = $this->resolve_metric_date($attribution_row, $engagement, $landing_session, $sale, $conversion);

        $snapshot = [
            'service_key' => $this->sanitize_key($service_key, 100),
            'landing_key' => $this->sanitize_key($landing_key, 100),
            'session_ref' => $this->sanitize_text_dimension($session_ref, 150),
            'click_id' => $this->sanitize_source_value($this->first_non_empty([
                $attribution_row['click_id'] ?? '',
                $engagement['click_id'] ?? '',
                $sale['click_id'] ?? '',
            ])),
            'tksource' => $this->sanitize_source_value($this->first_non_empty([
                $attribution_row['tksource'] ?? '',
                $engagement['tksource'] ?? '',
                $sale['tksource'] ?? '',
            ])),
            'tkzone' => $this->sanitize_source_value($this->first_non_empty([
                $attribution_row['tkzone'] ?? '',
                $engagement['tkzone'] ?? '',
                $sale['tkzone'] ?? '',
            ])),
            'device_brand' => $device['device_brand'],
            'os' => $device['os'],
            'os_version' => $device['os_version'],
            'browser' => $device['browser'],
            'attribution_metric_date' => $metric_date,
            'client_ip' => $ip_snapshot['client_ip'],
            'client_ip_version' => $ip_snapshot['client_ip_version'],
            'client_ip_prefix' => $ip_snapshot['client_ip_prefix'],
            'client_ip_hash' => $ip_snapshot['client_ip_hash'],
        ];

        $snapshot['attribution_snapshot'] = [
            'source' => 'conversion_attribution_resolver',
            'attribution' => $this->compact_snapshot_row($attribution_row, [
                'id',
                'created_at',
                'updated_at',
                'tracking_token',
                'transaction_id',
                'click_id',
                'provider_key',
                'service_key',
                'landing_page_key',
                'flow_key',
                'pid',
                'tksource',
                'tkzone',
                'session_ref',
                'transaction_ref',
                'message_ref',
                'external_ref',
                'sale_reference',
                'conversion_confirmed_at',
            ]),
            'landing_session' => $this->compact_snapshot_row($landing_session, [
                'id',
                'created_at',
                'landing_key',
                'service_key',
                'request_host',
                'request_path',
                'session_token',
                'remote_ip',
                'client_ip_version',
                'client_ip_prefix',
                'user_agent',
                'device_brand',
                'os',
                'os_version',
                'browser',
            ]),
            'engagement' => $this->compact_snapshot_row($engagement, [
                'id',
                'created_at',
                'updated_at',
                'landing_key',
                'service_key',
                'provider_key',
                'flow_key',
                'pid',
                'click_id',
                'tksource',
                'tkzone',
                'session_token',
                'page_loaded_at',
                'first_cta_click_at',
                'last_cta_click_at',
                'cta_click_count',
                'ua_ch_supported',
                'ua_ch_mobile',
                'ua_ch_platform',
                'ua_ch_platform_version',
                'ua_ch_model',
                'ua_ch_brands',
                'ua_ch_full_version_list',
                'user_agent',
            ]),
            'normalized' => $snapshot,
            'debug' => [
                'ip_source' => $ip_snapshot['snapshot_source'] ?? '',
                'metric_date_source' => $this->resolve_metric_date_source($attribution_row, $engagement, $landing_session, $sale, $conversion),
                'device_source' => $this->resolve_device_source($engagement, $landing_session),
            ],
        ];

        return $snapshot;
    }

    private function find_landing_session(string $landing_key, string $session_ref, string $service_key): ?array
    {
        if (!$this->landing_page_session_repository instanceof Kiwi_Landing_Page_Session_Repository || $session_ref === '') {
            return null;
        }

        if ($landing_key !== '' && method_exists($this->landing_page_session_repository, 'find_by_landing_session')) {
            $row = $this->landing_page_session_repository->find_by_landing_session($landing_key, $session_ref);

            if (is_array($row)) {
                return $row;
            }
        }

        if (method_exists($this->landing_page_session_repository, 'find_by_session_token')) {
            $row = $this->landing_page_session_repository->find_by_session_token($session_ref, $service_key);

            if (is_array($row)) {
                return $row;
            }
        }

        return null;
    }

    private function find_landing_engagement(string $landing_key, string $session_ref): ?array
    {
        if (!$this->landing_engagement_repository instanceof Kiwi_Premium_Sms_Landing_Engagement_Repository) {
            return null;
        }

        if ($landing_key === '' || $session_ref === '') {
            return null;
        }

        $row = $this->landing_engagement_repository->get_by_landing_session($landing_key, $session_ref);

        return is_array($row) ? $row : null;
    }

    private function build_client_ip_snapshot(?array $landing_session): array
    {
        if (!is_array($landing_session)) {
            return $this->empty_ip_snapshot();
        }

        $remote_ip_snapshot = $this->client_ip_resolver->normalize_ip((string) ($landing_session['remote_ip'] ?? ''));
        $client_ip_version = $this->normalize_ip_version((string) ($landing_session['client_ip_version'] ?? ''));
        $client_ip_prefix = $this->sanitize_text_dimension((string) ($landing_session['client_ip_prefix'] ?? ''), 120);

        if ($client_ip_version !== '' && $client_ip_version !== '(unknown)'
            && $client_ip_prefix !== '' && $client_ip_prefix !== '(unknown)'
        ) {
            return [
                'client_ip' => (string) ($remote_ip_snapshot['client_ip'] ?? ''),
                'client_ip_version' => $client_ip_version,
                'client_ip_prefix' => $client_ip_prefix,
                'client_ip_hash' => (string) ($remote_ip_snapshot['client_ip_hash'] ?? ''),
                'snapshot_source' => 'landing_page_session.client_ip_buckets',
            ];
        }

        $remote_ip_snapshot['snapshot_source'] = trim((string) ($landing_session['remote_ip'] ?? '')) !== ''
            ? 'landing_page_session.remote_ip'
            : '';

        return $remote_ip_snapshot;
    }

    private function empty_ip_snapshot(): array
    {
        return [
            'client_ip' => '',
            'client_ip_version' => '(unknown)',
            'client_ip_prefix' => '(unknown)',
            'client_ip_hash' => '',
            'snapshot_source' => '',
        ];
    }

    private function normalize_device_dimensions(?array $engagement, ?array $landing_session): array
    {
        $landing_device = $this->extract_normalized_device_dimensions($landing_session);
        $source = is_array($engagement) ? $engagement : [];
        $fallback_user_agent = is_array($landing_session) ? (string) ($landing_session['user_agent'] ?? '') : '';
        $user_agent = trim((string) ($source['user_agent'] ?? ''));

        if ($user_agent === '') {
            $user_agent = $fallback_user_agent;
        }

        $ua_ch_model = trim((string) ($source['ua_ch_model'] ?? ''));
        $ua_ch_platform = trim((string) ($source['ua_ch_platform'] ?? ''));
        $ua_ch_platform_version = trim((string) ($source['ua_ch_platform_version'] ?? ''));
        $ua_ch_brands = trim((string) ($source['ua_ch_brands'] ?? ''));
        $ua_ch_full_version_list = trim((string) ($source['ua_ch_full_version_list'] ?? ''));
        $normalized = $this->device_context_normalizer->normalize([
            'user_agent' => $user_agent,
            'ua_ch_platform' => $ua_ch_platform,
            'ua_ch_platform_version' => $ua_ch_platform_version,
            'ua_ch_model' => $ua_ch_model,
            'ua_ch_brands' => $ua_ch_brands,
            'ua_ch_full_version_list' => $ua_ch_full_version_list,
        ]);

        return $this->device_context_normalizer->merge($landing_device, $normalized);
    }

    private function extract_normalized_device_dimensions(?array $landing_session): array
    {
        if (!is_array($landing_session)) {
            return [];
        }

        return [
            'device_brand' => (string) ($landing_session['device_brand'] ?? ''),
            'os' => (string) ($landing_session['os'] ?? ''),
            'os_version' => (string) ($landing_session['os_version'] ?? ''),
            'browser' => (string) ($landing_session['browser'] ?? ''),
        ];
    }

    private function resolve_device_source(?array $engagement, ?array $landing_session): string
    {
        $landing_device = $this->extract_normalized_device_dimensions($landing_session);

        foreach ($landing_device as $value) {
            if (trim((string) $value) !== '' && trim((string) $value) !== '(unknown)') {
                return 'landing_page_session';
            }
        }

        if (is_array($engagement)) {
            return 'landing_engagement';
        }

        return is_array($landing_session) ? 'landing_page_session' : '';
    }

    private function resolve_metric_date(array $attribution_row, ?array $engagement, ?array $landing_session, array $sale, array $conversion): string
    {
        foreach ([
            $attribution_row['created_at'] ?? '',
            is_array($engagement) ? ($engagement['created_at'] ?? '') : '',
            is_array($landing_session) ? ($landing_session['created_at'] ?? '') : '',
            $conversion['occurred_at'] ?? '',
            $sale['completed_at'] ?? '',
        ] as $candidate) {
            $date = $this->normalize_date((string) $candidate);

            if ($date !== '') {
                return $date;
            }
        }

        return '';
    }

    private function resolve_metric_date_source(array $attribution_row, ?array $engagement, ?array $landing_session, array $sale, array $conversion): string
    {
        $sources = [
            'attribution.created_at' => $attribution_row['created_at'] ?? '',
            'engagement.created_at' => is_array($engagement) ? ($engagement['created_at'] ?? '') : '',
            'landing_session.created_at' => is_array($landing_session) ? ($landing_session['created_at'] ?? '') : '',
            'conversion.occurred_at' => $conversion['occurred_at'] ?? '',
            'sale.completed_at' => $sale['completed_at'] ?? '',
        ];

        foreach ($sources as $source => $value) {
            if ($this->normalize_date((string) $value) !== '') {
                return $source;
            }
        }

        return '';
    }

    private function normalize_date(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $matches) === 1) {
            $year = (int) ($matches[1] ?? 0);
            $month = (int) ($matches[2] ?? 0);
            $day = (int) ($matches[3] ?? 0);

            return checkdate($month, $day, $year)
                ? sprintf('%04d-%02d-%02d', $year, $month, $day)
                : '';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? '' : gmdate('Y-m-d', $timestamp);
    }

    private function compact_snapshot_row(?array $row, array $fields): array
    {
        if (!is_array($row)) {
            return [];
        }

        $snapshot = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }

            $value = $row[$field];

            if (is_scalar($value) || $value === null) {
                $snapshot[$field] = $value;
            }
        }

        return $snapshot;
    }

    private function first_non_empty(array $values): string
    {
        foreach ($values as $value) {
            $candidate = trim((string) $value);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
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

    private function normalize_ip_version(string $value): string
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
}
