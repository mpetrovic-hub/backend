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

kiwi_run_test('DB connect warning diagnostic records the approved web request context only', function (): void {
    $messages = [];
    $recorded = Kiwi_Db_Connect_Warning_Context_Diagnostic::record_if_target(
        E_WARNING,
        'mysqli_real_connect(): (HY000/2002): Operation not permitted',
        [
            'SCRIPT_FILENAME' => '/home/example/public_html/index.php',
            'REQUEST_METHOD' => 'post',
            'REQUEST_URI' => '/wp-json/kiwi-backend/v1/landing-kpi/event?email=person@example.test&token=secret&rest_route=%2Fkiwi-backend%2Fv1%2Flanding-kpi%2Fevent',
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
    kiwi_assert_contains('"schema_version":2', $messages[0], 'Expected the updated schema version.');
    kiwi_assert_contains('"web_route":"wp-json"', $messages[0], 'Expected the fixed web-route classification.');
    kiwi_assert_contains('"request_method":"POST"', $messages[0], 'Expected the canonical HTTP method.');
    kiwi_assert_contains('"request_path":"/wp-json/kiwi-backend/v1/landing-kpi/event"', $messages[0], 'Expected the raw path without query data.');
    kiwi_assert_contains('"rest_route":"/kiwi-backend/v1/landing-kpi/event"', $messages[0], 'Expected only the targeted REST route.');
    kiwi_assert_same(false, strpos($messages[0], '?email=') !== false, 'Must not log the raw query string.');
    kiwi_assert_same(false, strpos($messages[0], 'person@example.test') !== false, 'Must not log unrelated query values.');
    kiwi_assert_same(false, strpos($messages[0], 'token=secret') !== false, 'Must not log unrelated query secrets.');
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
            'REQUEST_URI' => '/wp-json/kiwi-backend/v1/landing-kpi/event',
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

    $sensitive_path_messages = [];
    Kiwi_Db_Connect_Warning_Context_Diagnostic::record_if_target(
        E_WARNING,
        'mysqli_real_connect(): (HY000/2002): Operation not permitted',
        [
            'SCRIPT_FILENAME' => '/home/example/public_html/index.php',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/reset/very-secret-token/person@example.test/203.0.113.10',
        ],
        'fpm-fcgi',
        4321,
        new DateTimeImmutable('2026-09-26T10:00:00Z'),
        static function (string $message) use (&$sensitive_path_messages): void {
            $sensitive_path_messages[] = $message;
        }
    );
    kiwi_assert_same(1, count($sensitive_path_messages), 'Expected one diagnostic entry for an unknown web path.');
    kiwi_assert_same(false, strpos($sensitive_path_messages[0], '"request_path"') !== false, 'Must omit an unknown request path.');
    kiwi_assert_same(false, strpos($sensitive_path_messages[0], 'very-secret-token') !== false, 'Must not log sensitive path segments.');
    kiwi_assert_same(false, strpos($sensitive_path_messages[0], 'person@example.test') !== false, 'Must not log an email-like path segment.');
    kiwi_assert_same(false, strpos($sensitive_path_messages[0], '203.0.113.10') !== false, 'Must not log an IP-like path segment.');

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

kiwi_run_test('DB connect warning diagnostic extracts only one static scalar rest route', function (): void {
    $now = new DateTimeImmutable('2026-09-26T10:00:00Z');
    $query_style_context = Kiwi_Db_Connect_Warning_Context_Diagnostic::build_context(
        [
            'SCRIPT_FILENAME' => '/home/example/public_html/index.php',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/index.php?rest_route=%2Fkiwi-backend%2Fv1%2Fnth-callback&email=person@example.test&token=secret',
        ],
        'fpm-fcgi',
        4321,
        $now
    );
    $invalid_method_context = Kiwi_Db_Connect_Warning_Context_Diagnostic::build_context(
        [
            'SCRIPT_FILENAME' => '/home/example/public_html/index.php',
            'REQUEST_METHOD' => 'POST invalid',
            'REQUEST_URI' => '/?rest_route=/kiwi-backend/v1/dimoco-callback',
        ],
        'fpm-fcgi',
        4321,
        $now
    );
    $duplicate_route_context = Kiwi_Db_Connect_Warning_Context_Diagnostic::build_context(
        [
            'SCRIPT_FILENAME' => '/home/example/public_html/index.php',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/?rest_route=/first&rest_route=/second',
        ],
        'fpm-fcgi',
        4321,
        $now
    );
    $array_route_context = Kiwi_Db_Connect_Warning_Context_Diagnostic::build_context(
        [
            'SCRIPT_FILENAME' => '/home/example/public_html/index.php',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/?rest_route%5B%5D=/array-route',
        ],
        'fpm-fcgi',
        4321,
        $now
    );
    $mixed_route_context = Kiwi_Db_Connect_Warning_Context_Diagnostic::build_context(
        [
            'SCRIPT_FILENAME' => '/home/example/public_html/index.php',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/?rest_route=/kiwi-backend/v1/nth-callback&rest_route%5B%5D=/second',
        ],
        'fpm-fcgi',
        4321,
        $now
    );
    $mixed_array_first_route_context = Kiwi_Db_Connect_Warning_Context_Diagnostic::build_context(
        [
            'SCRIPT_FILENAME' => '/home/example/public_html/index.php',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/?rest_route%5B%5D=/first&rest_route=/kiwi-backend/v1/nth-callback',
        ],
        'fpm-fcgi',
        4321,
        $now
    );
    $unallowed_route_context = Kiwi_Db_Connect_Warning_Context_Diagnostic::build_context(
        [
            'SCRIPT_FILENAME' => '/home/example/public_html/index.php',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/wp-json/wp/v2/users/1?rest_route=/wp/v2/users/1',
        ],
        'fpm-fcgi',
        4321,
        $now
    );
    $encoded_query_suffix_context = Kiwi_Db_Connect_Warning_Context_Diagnostic::build_context(
        [
            'SCRIPT_FILENAME' => '/home/example/public_html/index.php',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/index.php?rest_route=%2Fkiwi-backend%2Fv1%2Fnth-callback%3Fsecret%3Dx',
        ],
        'fpm-fcgi',
        4321,
        $now
    );
    $encoded_fragment_suffix_context = Kiwi_Db_Connect_Warning_Context_Diagnostic::build_context(
        [
            'SCRIPT_FILENAME' => '/home/example/public_html/index.php',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/index.php?rest_route=%2Fkiwi-backend%2Fv1%2Fnth-callback%23fragment',
        ],
        'fpm-fcgi',
        4321,
        $now
    );
    $nul_truncated_selector_context = Kiwi_Db_Connect_Warning_Context_Diagnostic::build_context(
        [
            'SCRIPT_FILENAME' => '/home/example/public_html/index.php',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/index.php?rest_route=%2Fkiwi-backend%2Fv1%2Fnth-callback&rest_route%00=%2Fevil',
        ],
        'fpm-fcgi',
        4321,
        $now
    );
    $dotted_alias_selector_context = Kiwi_Db_Connect_Warning_Context_Diagnostic::build_context(
        [
            'SCRIPT_FILENAME' => '/home/example/public_html/index.php',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/index.php?rest_route=%2Fkiwi-backend%2Fv1%2Fnth-callback&rest.route=%2Fevil',
        ],
        'fpm-fcgi',
        4321,
        $now
    );
    $plus_alias_selector_context = Kiwi_Db_Connect_Warning_Context_Diagnostic::build_context(
        [
            'SCRIPT_FILENAME' => '/home/example/public_html/index.php',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/index.php?rest_route=%2Fkiwi-backend%2Fv1%2Fnth-callback&rest+route=%2Fevil',
        ],
        'fpm-fcgi',
        4321,
        $now
    );
    $absolute_request_target_context = Kiwi_Db_Connect_Warning_Context_Diagnostic::build_context(
        [
            'SCRIPT_FILENAME' => '/home/example/public_html/index.php',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'https://user:private@example.test/wp-json/kiwi-backend/v1/landing-kpi/report?unused=private',
        ],
        'fpm-fcgi',
        4321,
        $now
    );

    kiwi_assert_same('/index.php', $query_style_context['request_path'], 'Expected the raw query-style request path.');
    kiwi_assert_same('/kiwi-backend/v1/nth-callback', $query_style_context['rest_route'], 'Expected the only scalar rest_route value.');
    kiwi_assert_same('POST', $query_style_context['request_method'], 'Expected the query-style HTTP method.');
    kiwi_assert_same(false, array_key_exists('email', $query_style_context), 'Must not retain unrelated query keys.');
    kiwi_assert_same(false, array_key_exists('token', $query_style_context), 'Must not retain unrelated query keys.');
    kiwi_assert_same(false, array_key_exists('request_method', $invalid_method_context), 'Must omit an invalid HTTP method.');
    kiwi_assert_same(false, array_key_exists('rest_route', $duplicate_route_context), 'Must omit ambiguous duplicate rest_route values.');
    kiwi_assert_same(false, array_key_exists('rest_route', $array_route_context), 'Must omit rest_route array values.');
    kiwi_assert_same(false, array_key_exists('rest_route', $mixed_route_context), 'Must omit mixed scalar and array rest_route values.');
    kiwi_assert_same(false, array_key_exists('rest_route', $mixed_array_first_route_context), 'Must omit mixed array and scalar rest_route values in either order.');
    kiwi_assert_same(false, array_key_exists('rest_route', $unallowed_route_context), 'Must omit a non-allowlisted rest route.');
    kiwi_assert_same(false, array_key_exists('rest_route', $encoded_query_suffix_context), 'Must omit an encoded query suffix on a rest route selector.');
    kiwi_assert_same(false, array_key_exists('rest_route', $encoded_fragment_suffix_context), 'Must omit an encoded fragment suffix on a rest route selector.');
    kiwi_assert_same(false, array_key_exists('rest_route', $nul_truncated_selector_context), 'Must omit a selector when PHP can truncate a NUL-containing parameter name.');
    kiwi_assert_same(false, array_key_exists('rest_route', $dotted_alias_selector_context), 'Must omit a selector when PHP normalizes a dotted parameter-name alias.');
    kiwi_assert_same(false, array_key_exists('rest_route', $plus_alias_selector_context), 'Must omit a selector when PHP normalizes a plus-sign parameter-name alias.');
    kiwi_assert_same(false, array_key_exists('request_path', $unallowed_route_context), 'Must omit a non-allowlisted request path.');
    kiwi_assert_same('wp-json', $unallowed_route_context['web_route'], 'Must preserve the coarse route class for an unallowlisted REST path.');
    kiwi_assert_same('/wp-json/kiwi-backend/v1/landing-kpi/report', $absolute_request_target_context['request_path'], 'Must keep only the path from an absolute request target.');
    kiwi_assert_same('GET', $absolute_request_target_context['request_method'], 'Expected the absolute request target HTTP method.');
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
    $unknown_context = Kiwi_Db_Connect_Warning_Context_Diagnostic::build_context(
        [],
        'fpm-fcgi',
        100,
        $now
    );

    kiwi_assert_same('wp-cron', $cron_context['execution_context'], 'Expected wp-cron context.');
    kiwi_assert_same('wp-cron.php', $cron_context['entry_script'], 'Expected the wp-cron entry script.');
    kiwi_assert_same(false, array_key_exists('web_route', $cron_context), 'Must not log WP-Cron request data.');
    kiwi_assert_same(false, array_key_exists('request_method', $cron_context), 'Must not log WP-Cron request method.');
    kiwi_assert_same(false, array_key_exists('request_path', $cron_context), 'Must not log WP-Cron request path.');
    kiwi_assert_same(false, array_key_exists('rest_route', $cron_context), 'Must not log WP-Cron request route.');
    kiwi_assert_same('cli', $cli_context['execution_context'], 'Expected CLI context.');
    kiwi_assert_same('wp', $cli_context['entry_script'], 'Expected only the CLI entry-script basename.');
    kiwi_assert_same(false, array_key_exists('web_route', $cli_context), 'Must not log CLI arguments or paths.');
    kiwi_assert_same(false, array_key_exists('request_method', $cli_context), 'Must not log CLI request method.');
    kiwi_assert_same(false, array_key_exists('request_path', $cli_context), 'Must not log CLI request path.');
    kiwi_assert_same(false, array_key_exists('rest_route', $cli_context), 'Must not log CLI request route.');
    kiwi_assert_same('wp-cron.php', $windows_cron_context['entry_script'], 'Expected Windows-style paths to keep only the entry-script basename.');
    kiwi_assert_same('unknown', $unknown_context['execution_context'], 'Expected the unknown context without request details.');
    kiwi_assert_same(false, array_key_exists('request_method', $unknown_context), 'Must not log unknown-context request method.');
    kiwi_assert_same(false, array_key_exists('request_path', $unknown_context), 'Must not log unknown-context request path.');
    kiwi_assert_same(false, array_key_exists('rest_route', $unknown_context), 'Must not log unknown-context request route.');
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
