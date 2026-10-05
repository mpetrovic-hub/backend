<?php

/**
 * Temporary context diagnostic for a single early WordPress database warning.
 *
 * This file is deliberately independent from WordPress because wp-config.php
 * loads it before wp-settings.php creates the database connection.
 */
final class Kiwi_Db_Connect_Warning_Context_Diagnostic
{
    private const LOG_PREFIX = '[kiwi-db-connect-diagnostic]';
    private const TARGET_WARNING = 'mysqli_real_connect(): (HY000/2002): Operation not permitted';

    /**
     * Static backend routes only; unknown or dynamic paths are never logged.
     */
    private const ALLOWED_REST_ROUTES = [
        '/kiwi-backend/v1/dimoco-callback',
        '/kiwi-backend/v1/landing-kpi/event',
        '/kiwi-backend/v1/landing-kpi/report',
        '/kiwi-backend/v1/nth-callback',
    ];

    private static $logger = null;
    private static $registered = false;
    private static $is_writing = false;

    /**
     * Registers the diagnostic only while its explicit production configuration
     * is enabled and has not expired.
     */
    public static function register(?callable $logger = null): bool
    {
        if (self::$registered) {
            return true;
        }

        $enabled = defined('KIWI_DB_CONNECT_DIAGNOSTICS_ENABLED')
            && KIWI_DB_CONNECT_DIAGNOSTICS_ENABLED === true;
        $expires_at_utc = defined('KIWI_DB_CONNECT_DIAGNOSTICS_EXPIRES_AT_UTC')
            ? KIWI_DB_CONNECT_DIAGNOSTICS_EXPIRES_AT_UTC
            : null;

        if (!self::is_configuration_active($enabled, $expires_at_utc)) {
            return false;
        }

        if (self::has_existing_error_handler()) {
            return false;
        }

        self::$logger = $logger ?? static function (string $message): void {
            error_log($message);
        };
        set_error_handler(
            [self::class, 'handle_error'],
            E_WARNING
        );
        self::$registered = true;

        return true;
    }

    /**
     * Tests the explicit configuration without reading production constants.
     */
    public static function is_configuration_active(
        bool $enabled,
        $expires_at_utc,
        ?DateTimeInterface $now = null
    ): bool {
        if ($enabled !== true || !is_string($expires_at_utc)) {
            return false;
        }

        $expires_at = self::parse_utc_expiry($expires_at_utc);
        if (!$expires_at instanceof DateTimeImmutable) {
            return false;
        }

        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $expires_at > $now;
    }

    public static function matches_warning(int $errno, string $errstr): bool
    {
        return $errno === E_WARNING && trim($errstr) === self::TARGET_WARNING;
    }

    /**
     * PHP error-handler callback. Returning false preserves the normal PHP
     * warning when there was no handler before this temporary diagnostic.
     */
    public static function handle_error(
        int $errno,
        string $errstr,
        string $errfile = '',
        int $errline = 0
    ): bool {
        if (!self::$is_writing && self::matches_warning($errno, $errstr)) {
            self::$is_writing = true;
            try {
                self::record_if_target(
                    $errno,
                    $errstr,
                    $_SERVER,
                    PHP_SAPI,
                    self::process_id(),
                    new DateTimeImmutable('now', new DateTimeZone('UTC')),
                    self::$logger
                );
            } finally {
                self::$is_writing = false;
            }
        }

        return false;
    }

    /**
     * Writes the restricted context only for the target warning.
     *
     * The callable makes the formatter testable without generating a PHP error
     * or opening a database connection.
     */
    public static function record_if_target(
        int $errno,
        string $errstr,
        array $server,
        string $php_sapi,
        ?int $process_id,
        DateTimeInterface $now,
        ?callable $logger = null
    ): bool {
        if (!self::matches_warning($errno, $errstr)) {
            return false;
        }

        $payload = self::build_context($server, $php_sapi, $process_id, $now);
        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($encoded)) {
            return false;
        }

        $logger = $logger ?? self::$logger;
        if (!is_callable($logger)) {
            return false;
        }

        try {
            call_user_func($logger, self::LOG_PREFIX . ' ' . $encoded);
        } catch (Throwable $exception) {
            return false;
        }

        return true;
    }

    public static function build_context(
        array $server,
        string $php_sapi,
        ?int $process_id,
        DateTimeInterface $now
    ): array {
        $entry_script = self::entry_script($server);
        $execution_context = self::execution_context($server, $php_sapi, $entry_script);
        $timestamp = (new DateTimeImmutable($now->format('Y-m-d H:i:s.u P')))
            ->setTimezone(new DateTimeZone('UTC'));

        $context = [
            'schema_version' => 2,
            'observed_at_utc' => $timestamp->format('Y-m-d\\TH:i:s.u\\Z'),
            'process_id' => $process_id,
            'php_sapi' => $php_sapi,
            'execution_context' => $execution_context,
            'entry_script' => $entry_script,
        ];

        if ($execution_context === 'web') {
            $web_route = self::web_route_classification($server);
            if ($web_route !== null) {
                $context['web_route'] = $web_route;
            }

            $request_method = self::request_method($server);
            if ($request_method !== null) {
                $context['request_method'] = $request_method;
            }

            $request_path = self::request_path($server);
            if ($request_path !== null) {
                $context['request_path'] = $request_path;
            }

            $rest_route = self::rest_route($server);
            if ($rest_route !== null) {
                $context['rest_route'] = $rest_route;
            }
        }

        return $context;
    }

    private static function parse_utc_expiry(string $expires_at_utc): ?DateTimeImmutable
    {
        $expires_at_utc = trim($expires_at_utc);
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/', $expires_at_utc) !== 1) {
            return null;
        }

        $expires_at = DateTimeImmutable::createFromFormat(
            '!Y-m-d\\TH:i:s\\Z',
            $expires_at_utc,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (!$expires_at instanceof DateTimeImmutable
            || (is_array($errors)
                && ((int) ($errors['warning_count'] ?? 0) > 0
                    || (int) ($errors['error_count'] ?? 0) > 0))
        ) {
            return null;
        }

        return $expires_at;
    }

    private static function process_id(): ?int
    {
        $process_id = getmypid();

        return is_int($process_id) ? $process_id : null;
    }

    private static function entry_script(array $server): ?string
    {
        if (!isset($server['SCRIPT_FILENAME']) || !is_string($server['SCRIPT_FILENAME'])) {
            return null;
        }

        $script_filename = trim($server['SCRIPT_FILENAME']);
        if ($script_filename === '') {
            return null;
        }

        return basename(str_replace(chr(92), '/', $script_filename));
    }

    /**
     * PHP does not expose an existing handler's error-level mask. Do not replace
     * it because forwarding a warning might change its original behavior.
     */
    private static function has_existing_error_handler(): bool
    {
        $existing_handler = set_error_handler(
            static function (
                int $errno,
                string $errstr,
                string $errfile = '',
                int $errline = 0
            ): bool {
                return false;
            }
        );
        restore_error_handler();

        return is_callable($existing_handler);
    }

    private static function execution_context(
        array $server,
        string $php_sapi,
        ?string $entry_script
    ): string {
        if ($php_sapi === 'cli') {
            return 'cli';
        }

        if ($entry_script === 'wp-cron.php') {
            return 'wp-cron';
        }

        if (isset($server['REQUEST_METHOD']) && is_string($server['REQUEST_METHOD'])
            && trim($server['REQUEST_METHOD']) !== '') {
            return 'web';
        }

        return 'unknown';
    }

    /**
     * Returns only a fixed route class. The raw path is logged separately under
     * the explicit, time-limited Issue #130 decision.
     */
    private static function web_route_classification(array $server): ?string
    {
        $path = self::raw_request_path($server);
        if ($path === null) {
            return null;
        }

        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $path = '/' . ltrim($path, '/');

        if ($path === '/') {
            return 'site_root';
        }
        if ($path === '/wp-login.php') {
            return 'wp-login';
        }
        if ($path === '/wp-admin' || strpos($path, '/wp-admin/') === 0) {
            return $path === '/wp-admin/admin-ajax.php'
                ? 'wp-admin-admin-ajax'
                : 'wp-admin';
        }
        if ($path === '/wp-json' || strpos($path, '/wp-json/') === 0) {
            return 'wp-json';
        }

        return 'other_web_route';
    }

    /**
     * Returns a canonical HTTP method only when it is a valid HTTP token.
     */
    private static function request_method(array $server): ?string
    {
        if (!isset($server['REQUEST_METHOD']) || !is_string($server['REQUEST_METHOD'])) {
            return null;
        }

        $request_method = trim($server['REQUEST_METHOD']);
        if ($request_method === ''
            || preg_match('/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/', $request_method) !== 1
        ) {
            return null;
        }

        return strtoupper($request_method);
    }

    /**
     * Returns a static, allowlisted request path only.
     */
    private static function request_path(array $server): ?string
    {
        $path = self::raw_request_path($server);
        if ($path === null) {
            return null;
        }

        $path = rawurldecode($path);
        if ($path === '/' || $path === '/index.php') {
            return $path;
        }

        foreach (self::ALLOWED_REST_ROUTES as $route) {
            if ($path === '/wp-json' . $route) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Returns the request path without its query or fragment portion. This is
     * used internally for classification only and is never logged directly.
     */
    private static function raw_request_path(array $server): ?string
    {
        if (!isset($server['REQUEST_URI']) || !is_string($server['REQUEST_URI'])) {
            return null;
        }

        $request_uri = $server['REQUEST_URI'];
        $separator_position = strcspn($request_uri, '?#');
        $path = substr($request_uri, 0, $separator_position);

        if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $path) === 1) {
            $path = parse_url($path, PHP_URL_PATH);
            if (!is_string($path)) {
                return null;
            }
        }

        return $path === '' ? null : $path;
    }

    /**
     * Extracts one exact, static rest_route selector and never retains other query values.
     */
    private static function rest_route(array $server): ?string
    {
        if (!isset($server['REQUEST_URI']) || !is_string($server['REQUEST_URI'])) {
            return null;
        }

        $query_start = strpos($server['REQUEST_URI'], '?');
        if ($query_start === false) {
            return null;
        }

        $query = substr($server['REQUEST_URI'], $query_start + 1);
        $fragment_start = strpos($query, '#');
        if ($fragment_start !== false) {
            $query = substr($query, 0, $fragment_start);
        }

        $rest_routes = [];
        foreach (explode('&', $query) as $parameter) {
            $parts = explode('=', $parameter, 2);
            $parameter_name = rawurldecode($parts[0]);
            if (strpos($parameter_name, 'rest_route[') === 0) {
                return null;
            }
            if ($parameter_name !== 'rest_route') {
                continue;
            }

            $rest_routes[] = isset($parts[1]) ? rawurldecode($parts[1]) : '';
        }

        if (count($rest_routes) !== 1) {
            return null;
        }

        // The decoded selector itself must be an exact allowlisted route. Do
        // not normalize a suffix such as "?secret=value" into another route.
        $route = $rest_routes[0];

        return in_array($route, self::ALLOWED_REST_ROUTES, true) ? $route : null;
    }
}
