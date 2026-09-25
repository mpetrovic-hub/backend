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
            'REQUEST_URI' => '/landing/offer?email=person@example.test&token=secret',
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
    kiwi_assert_contains('"request_path":"/landing/offer"', $messages[0], 'Expected the path without query data.');
    kiwi_assert_same(false, strpos($messages[0], 'person@example.test') !== false, 'Must not log query values.');
    kiwi_assert_same(false, strpos($messages[0], 'secret') !== false, 'Must not log query secrets.');
    kiwi_assert_same(false, strpos($messages[0], '203.0.113.10') !== false, 'Must not log client or proxy IP addresses.');
    kiwi_assert_same(false, strpos($messages[0], 'session=private') !== false, 'Must not log cookies.');
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
    kiwi_assert_same(false, array_key_exists('request_path', $cron_context), 'Must not log WP-Cron query data.');
    kiwi_assert_same('cli', $cli_context['execution_context'], 'Expected CLI context.');
    kiwi_assert_same('wp', $cli_context['entry_script'], 'Expected only the CLI entry-script basename.');
    kiwi_assert_same(false, array_key_exists('request_path', $cli_context), 'Must not log CLI arguments or paths.');
    kiwi_assert_same('wp-cron.php', $windows_cron_context['entry_script'], 'Expected Windows-style paths to keep only the entry-script basename.');
});

kiwi_run_test('DB connect warning diagnostic preserves the previous PHP handler and normal warning', function (): void {
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

        return false;
    });
    try {
        kiwi_assert_same(
            true,
            Kiwi_Db_Connect_Warning_Context_Diagnostic::register(
                static function (string $message) use (&$messages): void {
                    $messages[] = $message;
                }
            ),
            'Expected an enabled, unexpired diagnostic to register.'
        );
        $result = Kiwi_Db_Connect_Warning_Context_Diagnostic::handle_error(
            E_WARNING,
            'mysqli_real_connect(): (HY000/2002): Operation not permitted'
        );

        kiwi_assert_same(false, $result, 'Expected the previous handler result to preserve the normal PHP warning.');
        kiwi_assert_same(1, $previous_handler_calls, 'Expected the previous handler to remain in the chain.');
        kiwi_assert_same(1, count($messages), 'Expected the handler to emit one diagnostic entry.');
    } finally {
        restore_error_handler();
        restore_error_handler();
    }
});
