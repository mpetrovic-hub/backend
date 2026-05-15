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
     * Filesystem entries are the primary source of truth.
     * Legacy wp-config landing pages are an explicit rollback/migration fallback.
     */
    public function get_landing_pages(): array
    {
        if ($this->landing_pages_loaded) {
            return $this->landing_pages_cache;
        }

        $this->landing_pages_loaded = true;
        $filesystem_landing_pages = [];
        $this->landing_page_registry_errors = [];

        if ($this->is_landing_pages_filesystem_enabled()) {
            $registry = new Kiwi_Landing_Page_Registry(
                $this->get_landing_pages_root_path(),
                dirname(__DIR__, 2)
            );

            $filesystem_landing_pages = $registry->get_registry();
            $this->landing_page_registry_errors = $registry->get_errors();
            $this->handle_landing_page_registry_errors($this->landing_page_registry_errors);
        }

        $landing_pages = $filesystem_landing_pages;

        if ($this->is_landing_pages_legacy_fallback_enabled()) {
            foreach ($this->get_legacy_landing_pages() as $legacy_key => $legacy_landing_page) {
                if (!is_array($legacy_landing_page)) {
                    continue;
                }

                if (!isset($landing_pages[$legacy_key])) {
                    $landing_pages[$legacy_key] = $legacy_landing_page;
                }
            }
        }

        $this->landing_pages_cache = $landing_pages;

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

    public function is_landing_handoff_ua_client_hints_enabled(): bool
    {
        return !defined('KIWI_LANDING_HANDOFF_UA_CLIENT_HINTS_ENABLED')
            || (bool) KIWI_LANDING_HANDOFF_UA_CLIENT_HINTS_ENABLED;
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

    protected function get_legacy_landing_pages(): array
    {
        return defined('KIWI_LANDING_PAGES') && is_array(KIWI_LANDING_PAGES)
            ? KIWI_LANDING_PAGES
            : [];
    }

    protected function is_landing_pages_filesystem_enabled(): bool
    {
        return !defined('KIWI_LANDING_PAGES_FILESYSTEM_ENABLED')
            || (bool) KIWI_LANDING_PAGES_FILESYSTEM_ENABLED;
    }

    protected function is_landing_pages_legacy_fallback_enabled(): bool
    {
        return defined('KIWI_LANDING_PAGES_LEGACY_FALLBACK_ENABLED')
            && (bool) KIWI_LANDING_PAGES_LEGACY_FALLBACK_ENABLED;
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
