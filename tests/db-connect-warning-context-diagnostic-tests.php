<?php

require_once __DIR__ . '/../tools/database/diagnostics/class-db-connect-warning-context-diagnostic.php';

kiwi_run_test('DB connect warning diagnostic matches only the intended PHP warning', function (): void {
    kiwi_assert_same(
        true,
        Kiwi_Db_Connect_Warning_Context_Diagnostic::matches_warning(
            E_WARNING,
            'mysqli_real_connect(): (HY000/2002): Operation not permitted'
        ),
        'Expected the exact mysqli connection warning to match.'
    );
    kiwi_assert_same(
        false,
        Kiwi_Db_Connect_Warning_Context_Diagnostic::matches_warning(
            E_WARNING,
            'mysqli_real_connect(): (HY000/1045): Access denied'
        ),
        'Expected a different MySQL warning not to match.'
    );
    kiwi_assert_same(
        false,
        Kiwi_Db_Connect_Warning_Context_Diagnostic::matches_warning(
            E_NOTICE,
            'mysqli_real_connect(): (HY000/2002): Operation not permitted'
        ),
        'Expected the target text at another PHP error level not to match.'
    );
});

kiwi_run_test('DB connect warning diagnostic requires an enabled future UTC expiry', function (): void {
    $now = new DateTimeImmutable('2026-09-26T10:00:00Z');

    kiwi_assert_same(
        true,
        Kiwi_Db_Connect_Warning_Context_Diagnostic::is_configuration_active(
            true,
            '2026-10-03T10:00:00Z',
            $now
        ),
        'Expected enabled diagnostic with a future UTC expiry to be active.'
    );
    kiwi_assert_same(
        false,
        Kiwi_Db_Connect_Warning_Context_Diagnostic::is_configuration_active(
            false,
            '2026-10-03T10:00:00Z',
            $now
        ),
        'Expected a disabled diagnostic not to be active.'
    );
    kiwi_assert_same(
        false,
        Kiwi_Db_Connect_Warning_Context_Diagnostic::is_configuration_active(
            true,
            '2026-09-26T10:00:00Z',
            $now
        ),
        'Expected the diagnostic to stop exactly at its expiry.'
    );
    kiwi_assert_same(
        false,
        Kiwi_Db_Connect_Warning_Context_Diagnostic::is_configuration_active(
            true,
            '2026-10-03 10:00:00',
            $now
        ),
        'Expected a non-UTC expiry format not to activate the diagnostic.'
    );
});

kiwi_run_test('DB connect warning diagnostic records only the allowed web context', function (): void {
    $messages = [];
    $recorded = Kiwi_Db_Connect_Warning_Context_Diagnostic::record_if_target(
        E_WARNING,
        'mysqli_real_connect(): (HY000/2002): Operation not permitted',
        [
            'SCRIPT_FILENAME' => '/home/example/public_html/index.php',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/reset/very-secret-token/person@example.test/203.0.113.10?email=person@example.test&token=secret',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
            'HTTP_COOKIE' => 'session=private',
        ],
        'fpm-fcgi',
        4321,
        new DateTimeImmutable('2026-09-26T10:00:00.123456Z'),
        static function (string $message) use (&$messages): void {
            $messages[] = $message;
        }
    );

    kiwi_assert_same(true, $recorded, 'Expected the target warning to create one diagnostic entry.');
    kiwi_assert_same(1, count($messages), 'Expected exactly one diagnostic log entry.');
    kiwi_assert_contains('[kiwi-db-connect-diagnostic] ', $messages[0], 'Expected the diagnostic prefix.');
    kiwi_assert_contains('"execution_context":"web"', $messages[0], 'Expected web context.');
    kiwi_assert_contains('"entry_script":"index.php"', $messages[0], 'Expected only the entry script basename.');
    kiwi_assert_contains('"observed_at_utc":"2026-09-26T10:00:00.123456Z"', $messages[0], 'Expected microseconds to remain in the UTC timestamp.');
    kiwi_assert_contains('"web_route":"other_web_route"', $messages[0], 'Expected only the safe web-route classification.');
    kiwi_assert_same(false, strpos($messages[0], '/reset/') !== false, 'Must not log raw URL paths.');
    kiwi_assert_same(false, strpos($messages[0], 'very-secret-token') !== false, 'Must not log sensitive path segments.');
    kiwi_assert_same(false, strpos($messages[0], 'person@example.test') !== false, 'Must not log query values.');
    kiwi_assert_same(false, strpos($messages[0], 'secret') !== false, 'Must not log query secrets.');
    kiwi_assert_same(false, strpos($messages[0], '203.0.113.10') !== false, 'Must not log client or proxy IP addresses.');
    kiwi_assert_same(false, strpos($messages[0], 'session=private') !== false, 'Must not log cookies.');

    $php_self_messages = [];
    Kiwi_Db_Connect_Warning_Context_Diagnostic::record_if_target(
        E_WARNING,
        'mysqli_real_connect(): (HY000/2002): Operation not permitted',
        [
            'SCRIPT_NAME' => '/index.php/reset/script-name-secret',
            'PHP_SELF' => '/index.php/reset/very-secret-token',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/reset/very-secret-token',
        ],
        'fpm-fcgi',
        4321,
        new DateTimeImmutable('2026-09-26T10:00:00Z'),
        static function (string $message) use (&$php_self_messages): void {
            $php_self_messages[] = $message;
        }
    );
    kiwi_assert_same(1, count($php_self_messages), 'Expected one diagnostic entry with PHP_SELF present.');
    kiwi_assert_contains('"entry_script":null', $php_self_messages[0], 'Must omit an entry script when SCRIPT_FILENAME is unavailable.');
    kiwi_assert_same(false, strpos($php_self_messages[0], 'script-name-secret') !== false, 'Must not log SCRIPT_NAME path info.');
    kiwi_assert_same(false, strpos($php_self_messages[0], 'very-secret-token') !== false, 'Must not log request-derived PHP_SELF path info.');

    kiwi_assert_same(
        false,
        Kiwi_Db_Connect_Warning_Context_Diagnostic::record_if_target(
            E_WARNING,
            'mysqli_real_connect(): (HY000/1045): Access denied',
            [],
            'fpm-fcgi',
            4321,
            new DateTimeImmutable('2026-09-26T10:00:00Z'),
            static function (string $message) use (&$messages): void {
                $messages[] = $message;
            }
        ),
        'Expected a non-target warning not to be logged.'
    );
    kiwi_assert_same(1, count($messages), 'Expected a non-target warning not to add a diagnostic log entry.');
});

kiwi_run_test('DB connect warning diagnostic classifies WP-Cron and CLI without request data', function (): void {
    $now = new DateTimeImmutable('2026-09-26T10:00:00Z');
    $cron_context = Kiwi_Db_Connect_Warning_Context_Diagnostic::build_context(
        [
            'SCRIPT_FILENAME' => '/home/example/public_html/wp-cron.php',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/wp-cron.php?doing_wp_cron=private-value',
        ],
        'fpm-fcgi',
        77,
        $now
    );
    $cli_context = Kiwi_Db_Connect_Warning_Context_Diagnostic::build_context(
        ['SCRIPT_FILENAME' => '/usr/local/bin/wp'],
        'cli',
        88,
        $now
    );
    $windows_cron_context = Kiwi_Db_Connect_Warning_Context_Diagnostic::build_context(
        ['SCRIPT_FILENAME' => 'C:\\inetpub\\wwwroot\\wp-cron.php'],
        'fpm-fcgi',
        99,
        $now
    );

    kiwi_assert_same('wp-cron', $cron_context['execution_context'], 'Expected wp-cron context.');
    kiwi_assert_same('wp-cron.php', $cron_context['entry_script'], 'Expected the wp-cron entry script.');
    kiwi_assert_same(false, array_key_exists('web_route', $cron_context), 'Must not log WP-Cron request data.');
    kiwi_assert_same('cli', $cli_context['execution_context'], 'Expected CLI context.');
    kiwi_assert_same('wp', $cli_context['entry_script'], 'Expected only the CLI entry-script basename.');
    kiwi_assert_same(false, array_key_exists('web_route', $cli_context), 'Must not log CLI arguments or paths.');
    kiwi_assert_same('wp-cron.php', $windows_cron_context['entry_script'], 'Expected Windows-style paths to keep only the entry-script basename.');
});

kiwi_run_test('DB connect warning diagnostic leaves an existing PHP handler untouched', function (): void {
    if (!defined('KIWI_DB_CONNECT_DIAGNOSTICS_ENABLED')) {
        define('KIWI_DB_CONNECT_DIAGNOSTICS_ENABLED', true);
    }
    if (!defined('KIWI_DB_CONNECT_DIAGNOSTICS_EXPIRES_AT_UTC')) {
        define('KIWI_DB_CONNECT_DIAGNOSTICS_EXPIRES_AT_UTC', '2099-01-01T00:00:00Z');
    }

    $previous_handler_calls = 0;
    $messages = [];
    set_error_handler(static function (
        int $errno,
        string $errstr,
        string $errfile = '',
        int $errline = 0
    ) use (&$previous_handler_calls): bool {
        $previous_handler_calls++;

        return true;
    }, E_USER_WARNING);
    try {
        kiwi_assert_same(
            false,
            Kiwi_Db_Connect_Warning_Context_Diagnostic::register(
                static function (string $message) use (&$messages): void {
                    $messages[] = $message;
                }
            ),
            'Expected the diagnostic not to replace an existing error handler with an unknown mask.'
        );
        kiwi_assert_same(0, $previous_handler_calls, 'Must not invoke the existing handler while checking it.');
        kiwi_assert_same(0, count($messages), 'Must not emit diagnostics when an existing handler is present.');
        trigger_error('Existing error-handler mask check.', E_USER_WARNING);
        kiwi_assert_same(1, $previous_handler_calls, 'Must preserve the existing handler and its error-level mask.');
        @trigger_error('Existing error-handler non-matching mask check.', E_USER_NOTICE);
        kiwi_assert_same(1, $previous_handler_calls, 'Must not broaden the existing handler error-level mask.');
    } finally {
        restore_error_handler();
    }

    kiwi_assert_same(
        true,
        Kiwi_Db_Connect_Warning_Context_Diagnostic::register(
            static function (string $message) use (&$messages): void {
                $messages[] = $message;
            }
        ),
        'Expected an enabled diagnostic to register when no earlier handler exists.'
    );
    try {
        kiwi_assert_same(
            false,
            Kiwi_Db_Connect_Warning_Context_Diagnostic::handle_error(
                E_WARNING,
                'mysqli_real_connect(): (HY000/2002): Operation not permitted'
            ),
            'Expected the diagnostic to preserve the normal PHP warning when no previous handler exists.'
        );
        kiwi_assert_same(1, count($messages), 'Expected the handler to emit one diagnostic entry for the target warning.');
    } finally {
        restore_error_handler();
    }
});
