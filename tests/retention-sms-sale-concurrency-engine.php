<?php

// Let both callers read the absent sale, then observe their actual INSERTs waiting together.
$readLock = et_connection($settings, $database);
$readLock->exec('LOCK TABLES t72_kiwi_sales READ');
$children = [];
$identities = [];
$environment = getenv();
$environment['KIWI_RETENTION_TEST_DATABASE'] = $database;
try {
    for ($i = 0; $i < 2; $i++) {
        $child = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __DIR__ . '/retention-engagement-tests.php', '--sms-sale-writer'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        if (!is_resource($child)) { throw new RuntimeException('Unable to start sale writer'); }
        fclose($pipes[0]);
        $children[] = [$child, $pipes[1], $pipes[2]];
        $identities[] = (int) fgets($pipes[1]);
    }
    $pending = $admin->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST
        WHERE ID IN (?,?) AND INFO LIKE 'INSERT INTO t72_kiwi_sales %'");
    $deadline = microtime(true) + 8;
    do {
        $pending->execute($identities);
        $waiting = (int) $pending->fetchColumn();
        if ($waiting === 2) { break; }
        usleep(20000);
    } while (microtime(true) < $deadline);
    et_check($waiting === 2, 'Two distinct connections concurrently reached the INSERT for the same absent sale reference');
} finally {
    $readLock->exec('UNLOCK TABLES');
    $outcomes = [];
    foreach ($children as [$child, $stdout, $stderr]) {
        $output = stream_get_contents($stdout);
        $diagnosis = stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);
        if (proc_close($child) !== 0) { throw new RuntimeException('Synthetic sale writer failed: ' . $diagnosis); }
        $outcomes[] = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
    }
}
et_check(count($outcomes) === 2 && $outcomes[0]['id'] > 0 && $outcomes[0]['id'] === $outcomes[1]['id']
    && $outcomes[0]['inserted'] !== $outcomes[1]['inserted'],
    'Only the actual successful first insert can award a conversion and the other writer returns the same durable sale');
