<?php

$publisher = new Kiwi_Retention_Sms_Archive_Publication_Service($archive, $runs, $generationLock);
$lockName = $runs->acquire_source_creation_lock($key);
$runs->release_source_creation_lock($lockName);
$assertReleased = static function () use ($other, $lockName, $generationLock, $oldPath, $newPath): void {
    $query = $other->prepare('SELECT GET_LOCK(?,0)'); $query->execute([$lockName]);
    et_check((int)$query->fetchColumn() === 1, 'A separate connection can acquire the released SMS source lock');
    $query = $other->prepare('SELECT RELEASE_LOCK(?)'); $query->execute([$lockName]);
    foreach ([$oldPath, $newPath] as $path) {
        $lock = $generationLock->acquire_for_archive($path);
        et_check(!empty($lock['acquired']), 'The publication attempt released generation ' . basename($path));
        $generationLock->release($lock['handle']);
    }
};
$expectRefused = static function (array $result, string $reason) use ($newPath, $oldPath, $prepared): void {
    et_check(!$result['success'] && !$result['published'] && $result['reason_code'] === $reason
        && !file_exists($newPath) && is_file($oldPath) && is_file($prepared),
        'Publication refuses ' . $reason . ' without changing either archive evidence or staging');
};
$query = $other->prepare('SELECT GET_LOCK(?,0)'); $query->execute([$lockName]);
$expectRefused($publisher->publish($prepared, $newPath), 'sms_source_lock_active');
$query = $other->prepare('SELECT RELEASE_LOCK(?)'); $query->execute([$lockName]);
$assertReleased();

$wpdb->fail_creation_locks = 1;
$expectRefused($publisher->publish($prepared, $newPath), 'sms_publication_failed');
$assertReleased();

$held = $generationLock->acquire_for_archive($oldPath)['handle'];
$expectRefused($publisher->publish($prepared, $newPath), 'sms_publication_generation_lock_active');
$generationLock->release($held);
$assertReleased();

$generationLock->before_acquire = static function (string $path) use ($newPath): void {
    if ($path === $newPath) { throw new RuntimeException('fixture_lock_exception'); }
};
$expectRefused($publisher->publish($prepared, $newPath), 'sms_publication_failed');
$generationLock->before_acquire = null;
$assertReleased();

$generationLock->before_acquire = static function (string $path) use ($newPath, $wpdb, $lockName): void {
    if ($path === $newPath) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName)); }
};
$expectRefused($publisher->publish($prepared, $newPath), 'sms_publication_source_lock_lost');
$generationLock->before_acquire = null;
$assertReleased();

$originalConnection = $wpdb->pdo;
$reconnected = et_connection($settings, $database);
$generationLock->before_acquire = static function (string $path) use ($newPath, $wpdb, $lockName, $originalConnection, $reconnected): void {
    if ($path !== $newPath) { return; }
    $query = $originalConnection->prepare('SELECT RELEASE_LOCK(?)'); $query->execute([$lockName]);
    $query = $reconnected->prepare('SELECT GET_LOCK(?,0)'); $query->execute([$lockName]);
    $wpdb->pdo = $reconnected;
};
$expectRefused($publisher->publish($prepared, $newPath), 'sms_publication_source_lock_lost');
$generationLock->before_acquire = null;
$wpdb->pdo = $originalConnection;
$assertReleased();

$savedPrepared = file_get_contents($prepared);
$generationLock->before_acquire = static function (string $path) use ($newPath, $prepared): void {
    if ($path !== $newPath) { return; }
    $sqlite = new PDO('sqlite:' . $prepared);
    $sqlite->exec("UPDATE archive_batches SET status='failed'");
};
$expectRefused($publisher->publish($prepared, $newPath), 'sms_publication_staging_changed');
$generationLock->before_acquire = null;
file_put_contents($prepared, $savedPrepared);
$assertReleased();

$savedDestination = file_get_contents($oldPath);
$destinationStat = stat($oldPath);
$generationLock->before_acquire = static function (string $path) use ($oldPath, $source, $destinationStat): void {
    if ($path !== $oldPath) { return; }
    $sqlite = new PDO('sqlite:' . $oldPath);
    $sqlite->exec("UPDATE {$source['source_table']} SET allocation_version='moved1'");
    $sqlite = null;
    touch($oldPath, $destinationStat['mtime']);
    clearstatcache(true, $oldPath);
    et_check(filesize($oldPath) === $destinationStat['size'] && filemtime($oldPath) === $destinationStat['mtime'],
        'The concurrent destination commit keeps its size and modification timestamp');
    echo '[INFO] Destination ctime unchanged: ', filectime($oldPath) === $destinationStat['ctime'] ? 'yes' : 'no', "\n";
};
$expectRefused($publisher->publish($prepared, $oldPath, true), 'sms_publication_destination_changed');
$generationLock->before_acquire = null;
file_put_contents($oldPath, $savedDestination);
$assertReleased();

foreach (['', 'damaged SQLite fixture'] as $index=>$priorBytes) {
    $damaged = dirname($oldPath) . DIRECTORY_SEPARATOR . 'kiwi_retention_archive_2024_part_' . ($index + 2) . '.sqlite';
    $ready = $damaged . '.restore-ready';
    file_put_contents($damaged, $priorBytes);
    copy($oldPath, $ready);
    $sqlite = new PDO('sqlite:' . $ready);
    $sqlite->prepare('UPDATE archive_batches SET archive_db_path=?')->execute([$damaged]);
    $sqlite = null;
    $held = $generationLock->acquire_for_archive($damaged)['handle'];
    $held->persist_write_blocked();
    $generationLock->release($held);
    $restored = $publisher->publish($ready, $damaged, true);
    et_check($restored['success'] && $restored['published'] && file_get_contents($restored['backup_archive_path']) === $priorBytes
        && is_file($damaged . '.lock.write-blocked'), 'An explicitly restored corrupt or empty destination retains its original evidence and protection');
    $held = $generationLock->acquire_for_archive($damaged);
    et_check(!empty($held['acquired']), 'The restored corrupt-file generation lock is released');
    $held['handle']->clear_write_blocked();
    $generationLock->release($held['handle']);
}
$assertReleased();

foreach ([$prepared . '-wal', $newPath . '-journal', $prepared . '-shm'] as $sidecar) {
    file_put_contents($sidecar, 'fixture');
    $expectRefused($publisher->publish($prepared, $newPath), 'sms_publication_sidecar_present');
    unlink($sidecar);
}
$invalid = $prepared . '.invalid';
file_put_contents($invalid, 'not sqlite');
et_check($publisher->publish($invalid, $newPath)['reason_code'] === 'sms_publication_staging_invalid', 'A non-SQLite staging file is rejected');
unlink($invalid);
et_check($publisher->publish($oldPath, $newPath)['reason_code'] === 'sms_publication_staging_invalid', 'An existing canonical file cannot be used as a mutable staging alias');
et_check($publisher->publish($prepared, dirname($oldPath) . '/../' . basename($newPath))['success'] === false,
    'A destination outside the configured archive directory is rejected');
et_check($publisher->publish($prepared, $oldPath)['reason_code'] === 'sms_publication_destination_exists',
    'Ordinary publication without explicit replacement never overwrites an existing canonical generation');

$held = $generationLock->acquire_for_archive($newPath)['handle'];
$held->persist_write_blocked();
$generationLock->release($held);
$expectRefused($publisher->publish($prepared, $newPath), 'sms_publication_destination_protected');
et_check(is_file($newPath . '.lock.write-blocked'), 'A refused publication preserves the destination protection marker');
$held = $generationLock->acquire_for_archive($newPath)['handle'];
$held->clear_write_blocked();
$generationLock->release($held);
$assertReleased();

$replacement = $oldPath . '.replacement-ready';
copy($oldPath, $replacement);
$sqlite = new PDO('sqlite:' . $replacement);
$sqlite->exec("UPDATE {$source['source_table']} SET raw_context='restored fixture' WHERE id=1");
$sqlite = null;
$held = $generationLock->acquire_for_archive($oldPath)['handle'];
$held->persist_write_blocked();
$generationLock->release($held);
$replaced = $publisher->publish($replacement, $oldPath, true);
et_check($replaced['success'] && $replaced['published'] && is_file($replaced['backup_archive_path'])
    && is_file($oldPath . '.lock.write-blocked') && empty($gateService->inspect($oldPath, false)['allowed']),
    'Explicit same-path restoration succeeds while retaining the old archive and its corruption protection');
$restoredDb = new PDO('sqlite:' . $oldPath);
$backupDb = new PDO('sqlite:' . $replaced['backup_archive_path']);
et_check($restoredDb->query('SELECT raw_context FROM ' . $source['source_table'] . ' WHERE id=1')->fetchColumn() === 'restored fixture'
    && $backupDb->query('SELECT raw_context FROM ' . $source['source_table'] . ' WHERE id=1')->fetchColumn() !== 'restored fixture'
    && !in_array($replaced['backup_archive_path'], array_column($archive->list_archive_files(), 'path'), true),
    'The restored bytes occupy the canonical path and the untouched prior evidence remains outside discovery');
$restoredDb = null; $backupDb = null;
$held = $generationLock->acquire_for_archive($oldPath)['handle'];
$held->clear_write_blocked();
$generationLock->release($held);
$assertReleased();

$health = new Kiwi_Retention_Archive_Health_Service($config, $archive, $generationLock, $events, run_repository: $runs);
$run = $until($run, 'sms_archive');
$run = $step($run);
$attempt = bin2hex(random_bytes(16));
$opened = $health->with_new_attempt($attempt, 'manual', basename($run['archive_db_path']), filesize($run['archive_db_path']));
et_check($opened['_exit_code'] === 0, 'The fixture establishes a genuine pending archive-health attempt');
$expectRefused($publisher->publish($prepared, $newPath), 'sms_publication_health_pending');
$health->abort($attempt, true);
$assertReleased();

$result = $publisher->publish($prepared, $newPath);
et_check($result['success'] && $result['published'] && !$result['staging_retained'] && !file_exists($prepared)
    && file_exists($oldPath) && file_exists($newPath), 'Successful publication consumes only its staging name and preserves the prior archive');
$assertReleased();
