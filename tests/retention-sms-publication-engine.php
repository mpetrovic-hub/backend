<?php

// Same public-worker race as the independent Spec repro; the publisher now uses the approved shared-lock path.
require_once ABSPATH . 'tools/database/class-retention-sms-archive-publication-service.php';
$prepared = $oldPath . '.restore-ready';
copy($oldPath, $prepared);
$sqlite = new PDO('sqlite:' . $prepared);
$sqlite->exec("UPDATE {$source['source_table']} SET allocation_version='reassigned'");
$sqlite->prepare('UPDATE archive_batches SET archive_db_path=?')->execute([$newPath]);
$sqlite = null;
et_check(!in_array($prepared, array_column($archive->list_archive_files(), 'path'), true),
    'Prepared restoration is outside canonical archive discovery');
if ($case === 'publication-guards') {
    require __DIR__ . '/retention-sms-publication-guards.php';
    return;
}
$spawnPublisher = static function (bool $pause = false) use ($database, $root, $prepared, $newPath): array {
    $environment = getenv();
    $environment['KIWI_RETENTION_TEST_DATABASE'] = $database;
    $process = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __DIR__ . '/retention-engagement-tests.php', '--sms-publication-peer'],
        [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, null, $environment);
    if (!is_resource($process)) { throw new RuntimeException('Publication peer spawn failed'); }
    fwrite($pipes[0], json_encode(['root'=>$root, 'prepared'=>$prepared, 'destination'=>$newPath, 'pause'=>$pause]) . "\n");
    return [$process, $pipes];
};
$joinPublisher = static function (array $peer) use ($wpdb): array {
    [$process, $pipes] = $peer;
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || $error !== '') { throw new RuntimeException('Publication peer failed: ' . $output . $error); }
    $result = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
    et_check($result['pid'] !== getmypid() && $result['connection'] !== (int)$wpdb->pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
        'Publisher runs in a separate PHP process and MariaDB connection');
    return $result;
};
if ($case === 'publication-held') {
    $peer = $spawnPublisher(true);
    et_check(trim(fgets($peer[1][1])) === 'source_locked', 'Publisher acquired the existing SMS source lock before file locks');
    $before = $run;
    $result = $worker->run_worker($key);
    et_check(($result['error_code'] ?? '') === 'worker_lock_active'
        && (int)$wpdb->get_var('SELECT assignments FROM ' . $summary) === 0 && !file_exists($newPath),
        'An active publisher excludes worker correction before it publishes');
    fwrite($peer[1][0], "continue\n");
    $publication = $joinPublisher($peer);
    et_check($publication['success'] && $publication['published'] && file_exists($oldPath) && file_exists($newPath),
        'Publisher completes under its lease and preserves the previous generation');
    $run = $step($run);
    et_check($run['worker_phase'] === 'sms_recheck', 'Worker remeasures the newly published history after publisher releases the source lock');
    $run = $until($run, 'completed');
    et_check((int)$wpdb->get_var('SELECT assignments FROM ' . $summary) === 1 && (int)$run['deleted_rows'] === 1,
        'After publication, correction and receipt-backed deletion use the fresh one-original result');
    return;
}
$attempt = null;
$lists = 0;
$archive->after_inventory = static function () use (&$lists, &$attempt, $deleting, $archive, $generationLock, $oldPath, $spawnPublisher, $joinPublisher): void {
    if (++$lists !== ($deleting ? 3 : 2)) { return; }
    $archive->after_inventory = null;
    et_check(empty($generationLock->acquire_for_archive($oldPath)['acquired']),
        'The real worker retains the historical generation lock after its final directory snapshot');
    $attempt = $joinPublisher($spawnPublisher());
};
$run = $step($run);
et_check($attempt !== null && !$attempt['success'] && !$attempt['published'] && $attempt['reason_code'] === 'sms_source_lock_active'
    && !file_exists($newPath), 'Shared SMS lock rejects publication immediately after the final ' . ($deleting ? 'DELETE' : 'correction') . ' inventory snapshot');
et_check((int)$wpdb->get_var('SELECT assignments FROM ' . $summary) === 5 && (int)$run['deleted_rows'] === ($deleting ? 1 : 0),
    'The worker action remains valid because the competing new history was not published');
$published = $joinPublisher($spawnPublisher());
et_check($published['success'] && $published['published'] && is_file($oldPath) && is_file($newPath),
    'The same prepared restoration publishes after the worker releases its lease, preserving the old file');
$run = $step($run);
et_check($run['worker_phase'] === 'sms_recheck', 'The subsequent worker observes publication and invalidates the saved group basis');
$run = $until($run, 'completed');
$state = json_decode($run['gate_results_json'], true, 512, JSON_THROW_ON_ERROR);
et_check($state['groups'][$groupId]['after']['counters']['assignments']['expected'] === 1
    && $state['groups'][$groupId]['after']['status'] === 'outside_tolerance' && (int)$run['deleted_rows'] === ($deleting ? 1 : 0),
    'New history is remeasured before any further action; only the deletion completed before publication remains counted');
