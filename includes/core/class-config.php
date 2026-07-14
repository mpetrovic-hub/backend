<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Config
{
    private $landing_pages_loaded = false;
    private $landing_pages_cache = [];
    private $landing_page_registry_errors = [];

    // L I L Y  S E R V I C E S

    /**
     * Lily API Base URL
     */
    public function get_lily_base_url(): string
    {
        return defined('KIWI_LILY_BASE_URL')
            ? rtrim(KIWI_LILY_BASE_URL, '/')
            : 'https://api.lilymobile.gr';
    }

    /**
     * Lily API Username
     */
    public function get_lily_username(): string
    {
        return defined('KIWI_LILY_USERNAME')
            ? KIWI_LILY_USERNAME
            : '';
    }

    /**
     * Lily API Password
     */
    public function get_lily_password(): string
    {
        return defined('KIWI_LILY_PASSWORD')
            ? KIWI_LILY_PASSWORD
            : '';
    }

    /**
     * Default Country
     */
    public function get_default_country(): string
    {
        return defined('KIWI_DEFAULT_COUNTRY')
            ? KIWI_DEFAULT_COUNTRY
            : 'GR';
    }

    /**
     * Batch Limit for HLR lookups
     */
    public function get_hlr_batch_limit(): int
    {
        return defined('KIWI_HLR_BATCH_LIMIT')
            ? (int) KIWI_HLR_BATCH_LIMIT
            : 100;
    }

    /**
     * HTTP Timeout for provider requests
     */
    public function get_http_timeout(): int
    {
        return defined('KIWI_HTTP_TIMEOUT')
            ? (int) KIWI_HTTP_TIMEOUT
            : 20;
    }

    /**
     * Set request delay = 250ms by default to avoid hitting rate limits. This is the delay between consecutive HLR requests in a batch.
     */
    public function get_hlr_request_delay_ms(): int
    {
        return defined('KIWI_HLR_REQUEST_DELAY_MS')
            ? (int) KIWI_HLR_REQUEST_DELAY_MS
            : 250;
    }

     /**
     * Set retry delay for failed HLR lookups = 2 seconds by default. This is the delay before retrying a failed HLR lookup.
     */
    public function get_hlr_retry_delay_seconds(): int
    {
        return defined('KIWI_HLR_RETRY_DELAY_SECONDS')
            ? (int) KIWI_HLR_RETRY_DELAY_SECONDS
            : 2;
    }

    /**
     * Debug mode
     */
    public function is_debug(): bool
    {
        return defined('KIWI_DEBUG')
            ? (bool) KIWI_DEBUG
            : false;
    }

    // D I M O C O  S E R V I C E S

    /**
     * All configured DIMOCO services
     * Returns the full mapping from wp-config.php
     */
    public function get_dimoco_services(): array
    {
        return defined('KIWI_DIMOCO_SERVICES') && is_array(KIWI_DIMOCO_SERVICES)
            ? KIWI_DIMOCO_SERVICES
            : [];
    }

    /**
     * Single DIMOCO service configuration by key
     * Example: at_service_getstronger
     */
    public function get_dimoco_service(string $key): ?array
    {
        $services = $this->get_dimoco_services();

        return $services[$key] ?? null;
    }

    /**
     * DIMOCO API base URL
     * Example: https://services.dimoco.at/smart/payment
     */
    public function get_dimoco_base_url(): string
    {
        return defined('KIWI_DIMOCO_BASE_URL')
            ? KIWI_DIMOCO_BASE_URL
            : '';
    }

    /**
     * Callback URL for DIMOCO responses
     * Used for async responses (payment/refund status)
     */
    public function get_dimoco_callback_url(): string
    {
        return defined('KIWI_DIMOCO_CALLBACK_URL')
            ? KIWI_DIMOCO_CALLBACK_URL
            : '';
    }

    /**
     * Get all DIMOCO services formatted for dropdowns
     * Returns: [key => label]
     */
    public function get_dimoco_service_options(): array
    {
        $options = [];

        foreach ($this->get_dimoco_services() as $key => $service) {
            $options[$key] = $service['label'] ?? $key;
        }

        return $options;
    }

    /**
     * DIMOCO debug mode
     * Enables logging of requests/responses if true
     */
    public function is_dimoco_debug(): bool
    {
        return defined('KIWI_DIMOCO_DEBUG') && KIWI_DIMOCO_DEBUG === true;
    }

    // N T H  S E R V I C E S

    /**
     * All configured NTH services
     */
    public function get_nth_services(): array
    {
        return defined('KIWI_NTH_SERVICES') && is_array(KIWI_NTH_SERVICES)
            ? KIWI_NTH_SERVICES
            : [];
    }

    /**
     * Single NTH service configuration by key
     */
    public function get_nth_service(string $key): ?array
    {
        $services = $this->get_nth_services();

        return $services[$key] ?? null;
    }

    /**
     * Dedicated landing page configuration.
     *
     * Filesystem entries are the only source of truth.
     */
    public function get_landing_pages(): array
    {
        if ($this->landing_pages_loaded) {
            return $this->landing_pages_cache;
        }

        $this->landing_pages_loaded = true;
        $this->landing_page_registry_errors = [];
        $registry = new Kiwi_Landing_Page_Registry(
            $this->get_landing_pages_root_path(),
            dirname(__DIR__, 2)
        );

        $this->landing_pages_cache = $registry->get_registry();
        $this->landing_page_registry_errors = $registry->get_errors();
        $this->handle_landing_page_registry_errors($this->landing_page_registry_errors);

        return $this->landing_pages_cache;
    }

    /**
     * Single landing page configuration by key
     */
    public function get_landing_page(string $key): ?array
    {
        $landing_pages = $this->get_landing_pages();

        return $landing_pages[$key] ?? null;
    }

    public function is_sms_body_variant_experiment_enabled(): bool
    {
        return !defined('KIWI_SMS_BODY_VARIANT_EXPERIMENT_ENABLED')
            || (bool) KIWI_SMS_BODY_VARIANT_EXPERIMENT_ENABLED;
    }

    public function get_landing_ua_tracking_mode(): string
    {
        if (defined('KIWI_LANDING_UA_TRACKING_MODE')) {
            return $this->normalize_landing_ua_tracking_mode((string) KIWI_LANDING_UA_TRACKING_MODE);
        }

        if (defined('KIWI_LANDING_HANDOFF_UA_CLIENT_HINTS_ENABLED')
            && !(bool) KIWI_LANDING_HANDOFF_UA_CLIENT_HINTS_ENABLED
        ) {
            return 'disabled';
        }

        return 'onload';
    }

    public function get_landing_ua_tracking_mode_options(): array
    {
        return [
            'disabled' => 'Disabled',
            'onclick' => 'CTA / handoff interaction',
            'onload' => 'Page load',
        ];
    }

    public function get_landing_funnel_summary_refresh_days(): int
    {
        return defined('KIWI_LANDING_FUNNEL_SUMMARY_REFRESH_DAYS')
            ? max(0, (int) KIWI_LANDING_FUNNEL_SUMMARY_REFRESH_DAYS)
            : 7;
    }

    public function get_landing_funnel_tkzone_summary_pids(): array
    {
        $pids = defined('KIWI_LANDING_FUNNEL_TKZONE_SUMMARY_PIDS')
            ? KIWI_LANDING_FUNNEL_TKZONE_SUMMARY_PIDS
            : ['106'];

        if (is_string($pids)) {
            $pids = preg_split('/[\s,]+/', $pids);
        }

        if (!is_array($pids)) {
            return [];
        }

        $normalized = [];

        foreach ($pids as $pid) {
            $pid = trim((string) $pid);

            if ($pid === '') {
                continue;
            }

            $pid = preg_replace('/[^A-Za-z0-9._~:-]/', '', $pid);
            $pid = is_string($pid) ? substr($pid, 0, 191) : '';

            if ($pid !== '') {
                $normalized[] = $pid;
            }
        }

        return array_values(array_unique($normalized));
    }

    public function get_landing_funnel_tkzone_summary_pid_set_hash(): string
    {
        $pids = $this->get_landing_funnel_tkzone_summary_pids();
        sort($pids, SORT_STRING);

        return hash('sha256', implode('|', $pids));
    }

    public function get_device_model_brand_harvest_min_daily_sessions(): int
    {
        return defined('KIWI_DEVICE_MODEL_BRAND_HARVEST_MIN_DAILY_SESSIONS')
            ? max(1, (int) KIWI_DEVICE_MODEL_BRAND_HARVEST_MIN_DAILY_SESSIONS)
            : 5;
    }

    public function get_trusted_proxy_cidrs(): array
    {
        if (!defined('KIWI_TRUSTED_PROXY_CIDRS')) {
            return [];
        }

        $trusted_proxies = KIWI_TRUSTED_PROXY_CIDRS;

        if (is_string($trusted_proxies)) {
            $trusted_proxies = preg_split('/[\s,]+/', $trusted_proxies);
        }

        if (!is_array($trusted_proxies)) {
            return [];
        }

        $normalized = [];

        foreach ($trusted_proxies as $trusted_proxy) {
            $trusted_proxy = trim((string) $trusted_proxy);

            if ($trusted_proxy !== '') {
                $normalized[] = $trusted_proxy;
            }
        }

        return array_values(array_unique($normalized));
    }

    public function is_client_ip_resolution_debug_enabled(): bool
    {
        return defined('KIWI_CLIENT_IP_RESOLUTION_DEBUG')
            ? (bool) KIWI_CLIENT_IP_RESOLUTION_DEBUG
            : true;
    }

    public function is_landing_handoff_ua_client_hints_enabled(): bool
    {
        return $this->get_landing_ua_tracking_mode() !== 'disabled';
    }

    private function normalize_landing_ua_tracking_mode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return in_array($mode, ['disabled', 'onclick', 'onload'], true) ? $mode : 'onload';
    }

    public function get_sms_body_variant_experiment_countries(): array
    {
        $countries = defined('KIWI_SMS_BODY_VARIANT_EXPERIMENT_COUNTRIES')
            ? KIWI_SMS_BODY_VARIANT_EXPERIMENT_COUNTRIES
            : ['FR'];

        if (is_string($countries)) {
            $countries = preg_split('/[\s,]+/', $countries);
        }

        if (!is_array($countries)) {
            $countries = ['FR'];
        }

        $normalized = [];

        foreach ($countries as $country) {
            $country = strtoupper(trim((string) $country));
            $country = preg_replace('/[^A-Z0-9]/', '', $country);
            $country = is_string($country) ? $country : '';

            if ($country !== '') {
                $normalized[] = $country;
            }
        }

        $normalized = array_values(array_unique($normalized));

        return !empty($normalized) ? $normalized : ['FR'];
    }

    public function get_landing_page_registry_errors(): array
    {
        $this->get_landing_pages();

        return $this->landing_page_registry_errors;
    }

    protected function get_landing_pages_root_path(): string
    {
        if (defined('KIWI_LANDING_PAGES_ROOT')) {
            return rtrim((string) KIWI_LANDING_PAGES_ROOT, '/\\');
        }

        return dirname(__DIR__, 2) . '/landing-pages';
    }

    protected function handle_landing_page_registry_errors(array $errors): void
    {
        if (empty($errors)) {
            return;
        }

        if ($this->is_debug()) {
            throw new RuntimeException(
                "Landing page registry validation failed:\n- "
                . implode("\n- ", array_map('strval', $errors))
            );
        }

        foreach ($errors as $error) {
            error_log('[kiwi-landing-pages] ' . (string) $error);
        }
    }

    /**
     * NTH submitMessage timeout
     * NTH recommends at least 180 seconds for submitMessage.
     */
    public function get_nth_submit_timeout(): int
    {
        return defined('KIWI_NTH_SUBMIT_TIMEOUT')
            ? (int) KIWI_NTH_SUBMIT_TIMEOUT
            : 180;
    }

    public function is_nth_callback_logging_enabled(): bool
    {
        if (defined('KIWI_NTH_CALLBACK_LOGGING_ENABLED')) {
            return (bool) KIWI_NTH_CALLBACK_LOGGING_ENABLED;
        }

        return $this->is_debug();
    }

    public function is_nth_callback_payload_logging_enabled(): bool
    {
        if (defined('KIWI_NTH_CALLBACK_PAYLOAD_LOGGING_ENABLED')) {
            return (bool) KIWI_NTH_CALLBACK_PAYLOAD_LOGGING_ENABLED;
        }

        return $this->is_nth_callback_logging_enabled();
    }

    public function get_click_attribution_cookie_name(): string
    {
        return defined('KIWI_CLICK_ATTRIBUTION_COOKIE_NAME')
            ? (string) KIWI_CLICK_ATTRIBUTION_COOKIE_NAME
            : 'kiwi_tracking_token';
    }

    public function get_click_attribution_click_id_keys(): array
    {
        if (defined('KIWI_CLICK_ATTRIBUTION_CLICK_ID_KEYS') && is_array(KIWI_CLICK_ATTRIBUTION_CLICK_ID_KEYS)) {
            return array_values(array_filter(array_map('strval', KIWI_CLICK_ATTRIBUTION_CLICK_ID_KEYS)));
        }

        return ['clickid', 'click_id'];
    }

    public function get_click_attribution_ttl_seconds(): int
    {
        return defined('KIWI_CLICK_ATTRIBUTION_TTL_SECONDS')
            ? max(60, (int) KIWI_CLICK_ATTRIBUTION_TTL_SECONDS)
            : 172800;
    }

    public function get_click_attribution_cleanup_limit(): int
    {
        return defined('KIWI_CLICK_ATTRIBUTION_CLEANUP_LIMIT')
            ? max(1, (int) KIWI_CLICK_ATTRIBUTION_CLEANUP_LIMIT)
            : 500;
    }

    public function get_premium_sms_fraud_threshold_1h(): int
    {
        return defined('KIWI_PREMIUM_SMS_FRAUD_THRESHOLD_1H')
            ? max(1, (int) KIWI_PREMIUM_SMS_FRAUD_THRESHOLD_1H)
            : 3;
    }

    public function get_premium_sms_fraud_threshold_24h(): int
    {
        return defined('KIWI_PREMIUM_SMS_FRAUD_THRESHOLD_24H')
            ? max(1, (int) KIWI_PREMIUM_SMS_FRAUD_THRESHOLD_24H)
            : 6;
    }

    public function get_premium_sms_fraud_mo_engagement_mode(): string
    {
        $mode = defined('KIWI_PREMIUM_SMS_FRAUD_MO_ENGAGEMENT_MODE')
            ? strtolower(trim((string) KIWI_PREMIUM_SMS_FRAUD_MO_ENGAGEMENT_MODE))
            : 'observe';

        return in_array($mode, ['observe', 'block'], true) ? $mode : 'observe';
    }

    public function get_premium_sms_fraud_mo_require_page_loaded(): bool
    {
        if (!defined('KIWI_PREMIUM_SMS_FRAUD_MO_REQUIRE_PAGE_LOADED')) {
            return true;
        }

        return (bool) KIWI_PREMIUM_SMS_FRAUD_MO_REQUIRE_PAGE_LOADED;
    }

    public function get_premium_sms_fraud_mo_require_cta_click(): bool
    {
        if (!defined('KIWI_PREMIUM_SMS_FRAUD_MO_REQUIRE_CTA_CLICK')) {
            return true;
        }

        return (bool) KIWI_PREMIUM_SMS_FRAUD_MO_REQUIRE_CTA_CLICK;
    }

    public function get_premium_sms_fraud_mo_min_seconds_after_load(): int
    {
        return defined('KIWI_PREMIUM_SMS_FRAUD_MO_MIN_SECONDS_AFTER_LOAD')
            ? max(0, (int) KIWI_PREMIUM_SMS_FRAUD_MO_MIN_SECONDS_AFTER_LOAD)
            : 1;
    }

    public function get_affiliate_postback_url_template(): string
    {
        return defined('KIWI_AFFILIATE_POSTBACK_URL_TEMPLATE')
            ? (string) KIWI_AFFILIATE_POSTBACK_URL_TEMPLATE
            : '';
    }

    public function get_affiliate_postback_secret(): string
    {
        return defined('KIWI_AFFILIATE_POSTBACK_SECRET')
            ? (string) KIWI_AFFILIATE_POSTBACK_SECRET
            : '';
    }

    public function get_affiliate_postback_signature_parameter(): string
    {
        return defined('KIWI_AFFILIATE_POSTBACK_SIGNATURE_PARAMETER')
            ? (string) KIWI_AFFILIATE_POSTBACK_SIGNATURE_PARAMETER
            : 'secure';
    }

    public function get_affiliate_postback_signature_algorithm(): string
    {
        return defined('KIWI_AFFILIATE_POSTBACK_SIGNATURE_ALGORITHM')
            ? (string) KIWI_AFFILIATE_POSTBACK_SIGNATURE_ALGORITHM
            : 'sha256';
    }

    public function get_affiliate_postback_signature_base(): string
    {
        return defined('KIWI_AFFILIATE_POSTBACK_SIGNATURE_BASE')
            ? (string) KIWI_AFFILIATE_POSTBACK_SIGNATURE_BASE
            : '{clickid}:{secret}';
    }

    public function get_affiliate_postback_timeout_seconds(): int
    {
        return defined('KIWI_AFFILIATE_POSTBACK_TIMEOUT_SECONDS')
            ? max(1, (int) KIWI_AFFILIATE_POSTBACK_TIMEOUT_SECONDS)
            : $this->get_http_timeout();
    }

    public function get_affiliate_postback_response_body_limit(): int
    {
        return defined('KIWI_AFFILIATE_POSTBACK_RESPONSE_BODY_LIMIT')
            ? max(100, (int) KIWI_AFFILIATE_POSTBACK_RESPONSE_BODY_LIMIT)
            : 1000;
    }

    public function get_retention_settings(): array
    {
        $settings = function_exists('get_option')
            ? get_option('kiwi_retention_settings', [])
            : [];

        if (!is_array($settings)) {
            $settings = [];
        }

        $normalized = [];

        foreach ($this->get_default_retention_settings() as $source_key => $defaults) {
            $source_settings = isset($settings[$source_key]) && is_array($settings[$source_key])
                ? $settings[$source_key]
                : [];

            $normalized[$source_key] = [
                'enabled' => (bool) ($source_settings['enabled'] ?? $defaults['enabled']),
                'dry_run' => (bool) ($source_settings['dry_run'] ?? $defaults['dry_run']),
                'retention_days' => max(1, (int) ($source_settings['retention_days'] ?? $defaults['retention_days'])),
            ];
        }

        return $normalized;
    }

    public function get_retention_source_settings(string $source_key): array
    {
        $settings = $this->get_retention_settings();

        return $settings[$source_key] ?? [
            'enabled' => false,
            'dry_run' => true,
            'retention_days' => 14,
        ];
    }

    public function get_landing_session_raw_context_compaction_settings(): array
    {
        $settings = function_exists('get_option')
            ? get_option('kiwi_landing_session_raw_context_compaction_settings', [])
            : [];

        if (!is_array($settings)) {
            $settings = [];
        }

        $retention_settings = $this->get_retention_source_settings('landing_page_sessions');
        $retention_days = max(3, (int) ($retention_settings['retention_days'] ?? 14));
        $min_age_days = max(3, (int) ($settings['min_age_days'] ?? 3));
        $age_days = (int) ($settings['age_days'] ?? 7);

        $min_age_days = min($min_age_days, $retention_days);
        $age_days = max($min_age_days, min($retention_days, $age_days));

        return [
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'dry_run' => (bool) ($settings['dry_run'] ?? true),
            'age_days' => $age_days,
            'min_age_days' => $min_age_days,
            'row_limit' => max(1, (int) ($settings['row_limit'] ?? 20000)),
            'time_limit_seconds' => max(1, (int) ($settings['time_limit_seconds'] ?? 60)),
            'reschedule_delay_seconds' => max(1, (int) ($settings['reschedule_delay_seconds'] ?? 60)),
            'lock_ttl_seconds' => max(60, (int) ($settings['lock_ttl_seconds'] ?? 300)),
        ];
    }

    public function get_default_retention_settings(): array
    {
        return [
            'landing_page_sessions' => [
                'enabled' => false,
                'dry_run' => true,
                'retention_days' => 14,
            ],
            'premium_sms_landing_engagements' => [
                'enabled' => false,
                'dry_run' => true,
                'retention_days' => 14,
            ],
            'landing_handoff_events' => [
                'enabled' => false,
                'dry_run' => true,
                'retention_days' => 14,
            ],
            'sms_body_variant_assignments' => [
                'enabled' => false,
                'dry_run' => true,
                'retention_days' => 90,
            ],
            'premium_sms_fraud_signals' => [
                'enabled' => false,
                'dry_run' => true,
                'retention_days' => 120,
            ],
            'nth_events' => [
                'enabled' => false,
                'dry_run' => true,
                'retention_days' => 120,
            ],
            'nth_flow_transactions' => [
                'enabled' => false,
                'dry_run' => true,
                'retention_days' => 120,
            ],
        ];
    }

    public function get_retention_archive_root(): string
    {
        return defined('KIWI_RETENTION_ARCHIVE_ROOT')
            ? rtrim((string) KIWI_RETENTION_ARCHIVE_ROOT, '/\\')
            : '/home/u367252972/kiwi-backend-archives/db-retention';
    }

    public function get_retention_default_batch_limit(): int
    {
        return defined('KIWI_RETENTION_DEFAULT_BATCH_LIMIT')
            ? max(1, (int) KIWI_RETENTION_DEFAULT_BATCH_LIMIT)
            : 500;
    }

    public function get_retention_lock_ttl_seconds(): int
    {
        return defined('KIWI_RETENTION_LOCK_TTL_SECONDS')
            ? max(60, (int) KIWI_RETENTION_LOCK_TTL_SECONDS)
            : 1800;
    }

    public function get_retention_worker_row_limit(): int
    {
        return defined('KIWI_RETENTION_WORKER_ROW_LIMIT')
            ? max(1, (int) KIWI_RETENTION_WORKER_ROW_LIMIT)
            : 50000;
    }

    public function get_retention_worker_time_limit_seconds(): int
    {
        return defined('KIWI_RETENTION_WORKER_TIME_LIMIT_SECONDS')
            ? max(1, (int) KIWI_RETENTION_WORKER_TIME_LIMIT_SECONDS)
            : 60;
    }

    public function get_retention_worker_reschedule_delay_seconds(): int
    {
        return defined('KIWI_RETENTION_WORKER_RESCHEDULE_DELAY_SECONDS')
            ? max(1, (int) KIWI_RETENTION_WORKER_RESCHEDULE_DELAY_SECONDS)
            : 60;
    }

    public function get_retention_worker_lock_ttl_seconds(): int
    {
        return defined('KIWI_RETENTION_WORKER_LOCK_TTL_SECONDS')
            ? max(60, (int) KIWI_RETENTION_WORKER_LOCK_TTL_SECONDS)
            : 300;
    }

    /**
     * Operator lookup routes configuration
     * Returns a mapping of MSISDN prefixes to provider and country, used for routing operator lookup requests to the correct provider based on the phone number's prefix. Configured in wp-config.php as KIWI_OPERATOR_LOOKUP_ROUTES.
     */

    public function get_operator_lookup_routes(): array
    {
        return defined('KIWI_OPERATOR_LOOKUP_ROUTES') && is_array(KIWI_OPERATOR_LOOKUP_ROUTES)
            ? KIWI_OPERATOR_LOOKUP_ROUTES
            : [
                '30' => [
                    'provider' => 'lily',
                    'country'  => 'GR',
                ],
                '43' => [
                    'provider' => 'dimoco',
                    'country'  => 'AT',
                    'service_key' => 'at_service_getstronger', //mandatory if provider is dimoco, must match a service key in KIWI_DIMOCO_SERVICES
                ],
            ];
    }
}
