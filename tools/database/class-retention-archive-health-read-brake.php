<?php

/** Internal guard for the one existing external archive-health child. */
final class Kiwi_Retention_Archive_Health_Read_Brake
{
    private $bundle;
    private $archive_path;
    private $target_rate;
    private $control_pdo;
    private $probe_evidence = [];
    private $fixture_vfs = '';

    public static function bundle(): array
    {
        $directory = __DIR__ . '/archive-health-read-brake';
        $manifest_path = $directory . '/manifest.json';
        $library_path = $directory . '/limit-archive-reads.so';
        $source_path = $directory . '/limit-archive-reads.c';
        $fixture_path = $directory . '/fixtures/kiwi_retention_archive_2000.sqlite';
        if (PHP_OS_FAMILY !== 'Linux' || php_uname('m') !== 'x86_64') {
            throw new RuntimeException('health_brake_unavailable');
        }
        foreach ([$manifest_path, $library_path, $source_path, $fixture_path] as $path) {
            if (is_link($path) || !is_file($path) || !is_readable($path)) {
                throw new RuntimeException('health_brake_unavailable');
            }
        }
        $manifest_size = @filesize($manifest_path);
        if (!is_int($manifest_size) || $manifest_size < 1 || $manifest_size > 65536) {
            throw new RuntimeException('health_brake_unavailable');
        }
        $manifest = json_decode((string) @file_get_contents($manifest_path), true);
        if (!is_array($manifest)
            || ($manifest['schema_version'] ?? null) !== 1
            || ($manifest['contract_version'] ?? null) !== 1
            || preg_match('/^[a-f0-9]{64}$/', (string) ($manifest['build_id'] ?? '')) !== 1
            || ($manifest['fixture']['bytes'] ?? null) !== 4112384
            || @filesize($fixture_path) !== 4112384
        ) {
            throw new RuntimeException('health_brake_unavailable');
        }
        foreach (['library' => $library_path, 'source' => $source_path] as $key => $path) {
            $expected = $manifest[$key . '_sha256'] ?? null;
            $actual = @hash_file('sha256', $path);
            if (!is_string($expected) || !is_string($actual) || !hash_equals($expected, $actual)) {
                throw new RuntimeException('health_brake_unavailable');
            }
        }
        return [
            'manifest' => $manifest,
            'library' => realpath($library_path),
            'fixture' => realpath($fixture_path),
        ];
    }

    /** Build a child-only snapshot. Never alter the caller's environment. */
    public static function child_environment(string $archive_path, int $target_rate = 700): array
    {
        if (!in_array($target_rate, [700, 350], true)) {
            throw new RuntimeException('health_brake_configuration_invalid');
        }
        $environment = getenv();
        if (!is_array($environment)
            || !empty($environment['LD_PRELOAD'])
            || (!empty($_SERVER['LD_PRELOAD']))
        ) {
            throw new RuntimeException('health_brake_configuration_invalid');
        }
        $archive_real = realpath($archive_path);
        if (!is_string($archive_real) || is_link($archive_path) || !is_file($archive_real)) {
            throw new RuntimeException('health_brake_target_unverified');
        }
        $bundle = self::bundle();
        foreach (array_keys($environment) as $name) {
            if (strpos($name, 'KIWI_HEALTH_BRAKE_') === 0
                || in_array($name, ['KIWI_HEALTH_READS_PER_SECOND', 'KIWI_HEALTH_RANDOM_ADVICE', 'KIWI_HEALTH_LAB_DIAGNOSTIC'], true)
            ) {
                unset($environment[$name]);
            }
        }
        return array_merge($environment, [
            'LD_PRELOAD' => $bundle['library'],
            'LD_BIND_NOW' => '1',
            'KIWI_HEALTH_BRAKE_REQUIRED' => '1',
            'KIWI_HEALTH_READS_PER_SECOND' => (string) $target_rate,
            'KIWI_HEALTH_RANDOM_ADVICE' => '1',
            'KIWI_HEALTH_BRAKE_BUILD_ID' => $bundle['manifest']['build_id'],
            'KIWI_HEALTH_BRAKE_FIXTURE' => $bundle['fixture'],
            'KIWI_HEALTH_BRAKE_TARGET' => $archive_real,
        ]);
    }

    public function __construct(string $archive_path, $target_rate)
    {
        $this->bundle = self::bundle();
        if (!is_int($target_rate) || !in_array($target_rate, [700, 350], true)
            || getenv('KIWI_HEALTH_BRAKE_REQUIRED') !== '1'
            || getenv('KIWI_HEALTH_READS_PER_SECOND') !== (string) $target_rate
            || getenv('KIWI_HEALTH_RANDOM_ADVICE') !== '1'
            || getenv('KIWI_HEALTH_BRAKE_BUILD_ID') !== $this->bundle['manifest']['build_id']
            || getenv('KIWI_HEALTH_BRAKE_FIXTURE') !== $this->bundle['fixture']
            || getenv('KIWI_HEALTH_BRAKE_TARGET') !== $archive_path
            || getenv('LD_PRELOAD') !== $this->bundle['library']
            || getenv('LD_BIND_NOW') !== '1'
        ) {
            throw new RuntimeException('health_brake_configuration_invalid');
        }
        $this->archive_path = $archive_path;
        $this->target_rate = $target_rate;
    }

    public function open_verified_archive(): PDO
    {
        $deadline = hrtime(true) + 30000000000;
        try {
            $this->control_pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->control('fixture_700', 700);
            $phases = [];
            foreach ([700, 350] as $index => $rate) {
                if ($index === 1) {
                    $this->control('fixture_350', 350);
                }
                $pdo = kiwi_retention_archive_health_open_readonly($this->bundle['fixture']);
                $this->configure_readonly($pdo);
                $before = $this->status($pdo, $this->bundle['fixture'], $index + 1, $rate);
                $phase_started = hrtime(true);
                $rows = $pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
                $physical_duration = hrtime(true) - $phase_started;
                $after = $this->status($pdo, $this->bundle['fixture'], $index + 1, $rate);
                if ($rows !== ['ok'] || hrtime(true) >= $deadline) {
                    throw new RuntimeException('health_brake_probe_failed');
                }
                $calls = $after['calls'] - $before['calls'];
                $units = $after['units'] - $before['units'];
                $duration = $after['now_ns'] - $before['now_ns'];
                if ($calls < 1003 || $units < $calls
                    || $after['waits'] <= $before['waits']
                    || $after['wait_ns'] <= $before['wait_ns']
                    || $duration * $rate < 0.98 * (($units - 1) * 1000000000)
                    || $physical_duration * $rate < 0.98 * (($units - 1) * 1000000000)
                    || $after['first_read_ns'] <= 0
                    || $after['last_read_ns'] < $after['first_read_ns']
                    || $after['next_slot_ns'] < $after['last_read_ns']
                ) {
                    throw new RuntimeException('health_brake_probe_failed');
                }
                $phases[] = [
                    'before' => $before,
                    'after' => $after,
                    'units' => $units,
                    'duration_ns' => $physical_duration,
                ];
                $pdo = null;
            }
            if ($phases[0]['units'] !== $phases[1]['units']
                || $phases[1]['duration_ns'] < 1.35 * $phases[0]['duration_ns']
            ) {
                throw new RuntimeException('health_brake_probe_failed');
            }
            $this->probe_evidence = $phases;
            $this->control('target', $this->target_rate);
        } catch (Throwable $error) {
            throw new RuntimeException('health_brake_probe_failed');
        }
        try {
            $pdo = kiwi_retention_archive_health_open_readonly($this->archive_path);
            $this->configure_readonly($pdo);
            $status = $this->status($pdo, $this->archive_path, 3, $this->target_rate);
            if (!$status['target_locked'] || $status['calls'] < 1 || $status['units'] < $status['calls']) {
                throw new RuntimeException('health_brake_target_unverified');
            }
            return $pdo;
        } catch (Throwable $error) {
            if (in_array($error->getMessage(), [
                'sqlite_wal_not_empty', 'sqlite_wal_state_invalid',
                'sqlite_rollback_journal_not_empty', 'sqlite_rollback_journal_state_invalid',
            ], true)) {
                throw $error;
            }
            throw new RuntimeException('health_brake_target_unverified');
        }
    }

    public function verify_after_check(PDO $pdo): void
    {
        $status = $this->status($pdo, $this->archive_path, 3, $this->target_rate);
        if (!$status['target_locked'] || $status['calls'] < 1 || $status['units'] < $status['calls']) {
            throw new RuntimeException('health_brake_target_unverified');
        }
    }

    /** Internal test evidence only; never added to the controller JSON or logs. */
    public function probe_evidence(): array
    {
        return $this->probe_evidence;
    }

    private function control(string $action, int $rate): void
    {
        $statement = $this->control_pdo->prepare('SELECT kiwi_health_brake_control_v1(?, ?)');
        $statement->execute([$action, $rate]);
        if ($statement->fetchColumn() !== 1) {
            throw new RuntimeException('health_brake_configuration_invalid');
        }
    }

    private function configure_readonly(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA query_only = ON');
        $pdo->exec('PRAGMA mmap_size = 0');
        if ((int) $pdo->query('PRAGMA query_only')->fetchColumn() !== 1
            || (int) $pdo->query('PRAGMA mmap_size')->fetchColumn() !== 0
        ) {
            throw new RuntimeException('health_brake_probe_failed');
        }
    }

    private function status(PDO $pdo, string $path, int $phase, int $rate): array
    {
        try {
            $status_started = hrtime(true);
            $raw = $pdo->query('SELECT kiwi_health_brake_status_v1()')->fetchColumn();
            $status_finished = hrtime(true);
            $status = is_string($raw) && strlen($raw) <= 4096 ? json_decode($raw, true) : null;
            clearstatcache(true, $path);
            $identity = @stat($path);
            if (!is_array($status) || !is_array($identity) || is_link($path)
                || ($status['version'] ?? null) !== 1
                || ($status['build_id'] ?? null) !== $this->bundle['manifest']['build_id']
                || ($status['pid'] ?? null) !== getmypid()
                || ($status['phase'] ?? null) !== $phase || ($status['rate'] ?? null) !== $rate
                || ($status['device'] ?? null) !== (string) $identity['dev']
                || ($status['inode'] ?? null) !== (string) $identity['ino']
                || ($status['connection_bound'] ?? null) !== true
                || ($status['random_advice'] ?? null) !== true
                || ($status['mapping_denied'] ?? null) !== true
                || ($status['error'] ?? null) !== false
                || !in_array($status['vfs'] ?? null, ['unix', 'unix-excl'], true)
            ) {
                throw new RuntimeException('health_brake_probe_failed');
            }
            foreach (['calls', 'units', 'waits', 'wait_ns', 'first_read_ns', 'last_read_ns', 'now_ns', 'phase_started_ns', 'next_slot_ns'] as $key) {
                if (!isset($status[$key]) || !is_int($status[$key]) || $status[$key] < 0) {
                    throw new RuntimeException('health_brake_probe_failed');
                }
            }
            if ($status['now_ns'] < $status_started || $status['now_ns'] > $status_finished
                || $status['last_read_ns'] > $status['now_ns']
                || $status['phase_started_ns'] > $status['now_ns']
            ) {
                throw new RuntimeException('health_brake_probe_failed');
            }
            if ($this->fixture_vfs === '' && $phase === 1) {
                $this->fixture_vfs = $status['vfs'];
            }
            if ($this->fixture_vfs !== $status['vfs']) {
                throw new RuntimeException('health_brake_probe_failed');
            }
            return $status;
        } catch (Throwable $error) {
            throw new RuntimeException($phase === 3 ? 'health_brake_target_unverified' : 'health_brake_probe_failed');
        }
    }
}
