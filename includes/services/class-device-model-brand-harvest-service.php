<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Device_Model_Brand_Harvest_Service
{
    private const UNKNOWN = '(unknown)';

    private $config;
    private $repository;
    private $normalizer;
    private $last_error = '';

    public function __construct(
        ?Kiwi_Config $config = null,
        ?Kiwi_Device_Model_Brand_Map_Repository $repository = null,
        ?Kiwi_Device_Context_Normalizer $normalizer = null
    ) {
        $this->config = $config instanceof Kiwi_Config ? $config : new Kiwi_Config();
        $this->repository = $repository instanceof Kiwi_Device_Model_Brand_Map_Repository
            ? $repository
            : new Kiwi_Device_Model_Brand_Map_Repository();
        $this->normalizer = $normalizer instanceof Kiwi_Device_Context_Normalizer
            ? $normalizer
            : new Kiwi_Device_Context_Normalizer($this->repository);
    }

    public function harvest_date(string $date): array
    {
        $this->last_error = '';
        $date = $this->normalize_date($date);

        if ($date === '') {
            $this->last_error = 'Invalid device model brand harvest date.';

            return $this->build_result(false, '', 0, 0, 0, 0);
        }

        $threshold = $this->config->get_device_model_brand_harvest_min_daily_sessions();
        $from_datetime = $date . ' 00:00:00';
        $to_datetime = $this->next_date($date) . ' 00:00:00';
        $models = $this->collect_unknown_models($from_datetime, $to_datetime);
        $inserted = 0;
        $eligible = 0;

        foreach ($models as $model_key => $model) {
            $distinct_sessions = count($model['sessions']);

            if ($distinct_sessions < $threshold) {
                continue;
            }

            $eligible++;

            if ($this->repository->insert_observed_unknown_model_key($model_key, $date, $distinct_sessions, $threshold)) {
                $inserted++;
            }
        }

        return $this->build_result(true, $date, count($models), $eligible, $inserted, $threshold);
    }

    public function get_last_error(): string
    {
        return $this->last_error;
    }

    private function collect_unknown_models(string $from_datetime, string $to_datetime): array
    {
        $models = [];

        foreach ($this->load_source_rows($from_datetime, $to_datetime) as $row) {
            $model_key = $this->normalize_observed_model_key((string) ($row['ua_ch_model'] ?? ''));

            if ($model_key === '') {
                continue;
            }

            $normalized = $this->normalizer->normalize([
                'user_agent' => (string) ($row['user_agent'] ?? ''),
                'ua_ch_platform' => (string) ($row['ua_ch_platform'] ?? ''),
                'ua_ch_platform_version' => (string) ($row['ua_ch_platform_version'] ?? ''),
                'ua_ch_model' => (string) ($row['ua_ch_model'] ?? ''),
                'ua_ch_brands' => (string) ($row['ua_ch_brands'] ?? ''),
                'ua_ch_full_version_list' => (string) ($row['ua_ch_full_version_list'] ?? ''),
            ]);

            if ((string) ($normalized['device_brand'] ?? '') !== self::UNKNOWN) {
                continue;
            }

            if (!isset($models[$model_key])) {
                $models[$model_key] = [
                    'rows' => 0,
                    'sessions' => [],
                ];
            }

            $models[$model_key]['rows']++;
            $session_key = trim((string) ($row['landing_key'] ?? '')) . '|' . trim((string) ($row['session_token'] ?? ''));

            if ($session_key !== '|') {
                $models[$model_key]['sessions'][$session_key] = true;
            }
        }

        return $models;
    }

    private function load_source_rows(string $from_datetime, string $to_datetime): array
    {
        global $wpdb;

        $rows = [];

        foreach ([
            $wpdb->prefix . 'kiwi_premium_sms_landing_engagements',
            $wpdb->prefix . 'kiwi_landing_handoff_events',
        ] as $table_name) {
            $source_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT landing_key, session_token, ua_ch_platform, ua_ch_platform_version, ua_ch_model, ua_ch_brands, ua_ch_full_version_list, user_agent
                     FROM {$table_name}
                     WHERE created_at >= %s
                       AND created_at < %s
                       AND ua_ch_model <> ''",
                    $from_datetime,
                    $to_datetime
                ),
                ARRAY_A
            );

            if (is_array($source_rows)) {
                $rows = array_merge($rows, $source_rows);
            }
        }

        return $rows;
    }

    private function normalize_observed_model_key(string $model): string
    {
        $model = trim($model);

        if ($model === '' || preg_match('/^(?:unknown|android|mobile|phone|generic|k)$/i', $model) === 1) {
            return '';
        }

        return $this->repository->normalize_model_key($model);
    }

    private function build_result(
        bool $success,
        string $date,
        int $unknown_model_keys,
        int $eligible_model_keys,
        int $inserted,
        int $threshold
    ): array {
        return [
            'success' => $success,
            'date' => $date,
            'unknown_model_keys' => $unknown_model_keys,
            'eligible_model_keys' => $eligible_model_keys,
            'inserted' => $inserted,
            'threshold' => $threshold,
            'error' => $success ? '' : $this->last_error,
        ];
    }

    private function normalize_date(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
            return '';
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : '';
    }

    private function next_date(string $date): string
    {
        $timestamp = strtotime($date . ' +1 day');

        return $timestamp === false ? $date : gmdate('Y-m-d', $timestamp);
    }
}
