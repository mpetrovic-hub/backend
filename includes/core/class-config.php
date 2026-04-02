<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Config
{

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
     * Dedicated landing page configuration
     */
    public function get_landing_pages(): array
    {
        return defined('KIWI_LANDING_PAGES') && is_array(KIWI_LANDING_PAGES)
            ? KIWI_LANDING_PAGES
            : [];
    }

    /**
     * Single landing page configuration by key
     */
    public function get_landing_page(string $key): ?array
    {
        $landing_pages = $this->get_landing_pages();

        return $landing_pages[$key] ?? null;
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
