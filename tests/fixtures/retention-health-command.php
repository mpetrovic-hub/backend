<?php

// Exercise the actual early WP-CLI entrypoint with process exits, using the existing synthetic boundary.
putenv('KIWI_TEST_FILTER=__command_fixture_load_only__');
ob_start();
require dirname(__DIR__) . '/run-tests.php';
ob_end_clean();
WP_CLI::reset_runtime();
WP_CLI::$exit_process = true;
$f = kiwi_test_attempt_service();
$id = str_repeat('7', 32);
$f['service']->with_new_attempt($id, 'manual', basename($f['path']), filesize($f['path']));
$command = new Kiwi_Retention_Archive_Health_Command(service_factory: static fn() => $f['service']);
$case = $argv[1] ?? '';
if ($case === 'status') { $command->status([], []); }
if ($case === 'attempt') { $command->status([], ['attempt' => $id]); }
if ($case === 'abort') { $command->abort([], ['attempt' => $id]); }
if ($case === 'confirmed') { $command->abort([], ['attempt' => $id, 'confirm' => true]); }
if ($case === 'unknown_argument') { $command->status([], ['extra' => true]); }
throw new RuntimeException('Unknown command fixture.');
