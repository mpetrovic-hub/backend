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

    private static $previous_error_handler = null;
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

        self::$logger = $logger ?? static function (string $message): void {
            error_log($message);
        };
        self::$previous_error_handler = set_error_handler(
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

        if (is_callable(self::$previous_error_handler)) {
            return (bool) call_user_func(
                self::$previous_error_handler,
                $errno,
                $errstr,
                $errfile,
                $errline
            );
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
        $timestamp = (new DateTimeImmutable($now->format('c')))
            ->setTimezone(new DateTimeZone('UTC'));

        $context = [
            'schema_version' => 1,
            'observed_at_utc' => $timestamp->format('Y-m-d\\TH:i:s.u\\Z'),
            'process_id' => $process_id,
            'php_sapi' => $php_sapi,
            'execution_context' => $execution_context,
            'entry_script' => $entry_script,
        ];

        if ($execution_context === 'web') {
            $request_path = self::request_path($server);
            if ($request_path !== null) {
                $context['request_path'] = $request_path;
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
        foreach (['SCRIPT_FILENAME', 'SCRIPT_NAME', 'PHP_SELF'] as $key) {
            if (!isset($server[$key]) || !is_string($server[$key])) {
                continue;
            }

            $value = trim($server[$key]);
            if ($value === '') {
                continue;
            }

            return basename(str_replace(chr(92), '/', $value));
        }

        return null;
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

    private static function request_path(array $server): ?string
    {
        if (!isset($server['REQUEST_URI']) || !is_string($server['REQUEST_URI'])) {
            return null;
        }

        $request_uri = trim($server['REQUEST_URI']);
        if ($request_uri === '') {
            return null;
        }

        $separator_position = strcspn($request_uri, '?#');
        $path = substr($request_uri, 0, $separator_position);

        return $path === '' ? '/' : $path;
    }
}
