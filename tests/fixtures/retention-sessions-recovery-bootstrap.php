<?php

declare(strict_types=1);

// CLI-only fixture bootstrap. Never load a WordPress installation or wp-config.
if (PHP_SAPI !== 'cli' || defined('ABSPATH')) {
    throw new RuntimeException('Integration tests require a standalone sandbox CLI.');
}

define('ABSPATH', dirname(__DIR__, 2) . '/');
define('ARRAY_A', 'ARRAY_A');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);

function current_time($type)
{
    return $type === 'mysql' ? '2026-09-21 12:00:00' : strtotime('2026-09-21 12:00:00 UTC');
}

function get_option($key, $default = false)
{
    return $key === 'kiwi_retention_settings'
        ? ['landing_page_sessions' => ['enabled' => true, 'dry_run' => false, 'retention_days' => 14]]
        : $default;
}

function get_transient($key) { return $GLOBALS['sandbox_transients'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0): bool { $GLOBALS['sandbox_transients'][$key] = $value; return true; }
function delete_transient($key): bool { unset($GLOBALS['sandbox_transients'][$key]); return true; }
function wp_json_encode($value) { return json_encode($value, JSON_THROW_ON_ERROR); }
function wp_generate_uuid4(): string { return bin2hex(random_bytes(16)); }

function dbDelta($sql): void
{
    global $wpdb;
    if ($wpdb->query($sql) === false) {
        throw new RuntimeException($wpdb->last_error);
    }
}

foreach ([
    'core/class-config.php',
    'core/class-database-table-names.php',
    'repositories/interface-statistics-read-repository.php',
    'repositories/class-landing-page-session-repository.php',
    'repositories/class-landing-session-engagement-repository.php',
    'repositories/class-landing-handoff-event-repository.php',
    'repositories/class-sales-repository.php',
    'repositories/class-landing-funnel-daily-summary-repository.php',
    'repositories/class-landing-funnel-daily-tkzone-summary-repository.php',
    'repositories/class-retention-cleanup-run-repository.php',
    'repositories/class-retention-table-growth-snapshot-repository.php',
    'repositories/class-operational-event-repository.php',
    'services/class-landing-funnel-read-context.php',
    'services/class-landing-funnel-daily-summary-aggregation-service.php',
    'services/class-landing-funnel-daily-tkzone-summary-aggregation-service.php',
    'services/class-retention-source-registry.php',
    'services/class-retention-coverage-gate.php',
    'services/class-retention-archive-name.php',
    'services/class-retention-archive-lock.php',
    'services/class-retention-archive-write-block.php',
    'services/class-retention-corruption-safety-gate-coordinator.php',
    'services/class-retention-sqlite-archive-service.php',
    'services/class-operational-event-service.php',
    'services/class-retention-cleanup-service.php',
    'services/class-retention-archive-check-supervisor.php',
] as $file) {
    require_once ABSPATH . 'includes/' . $file;
}

final class Sandbox_Config extends Kiwi_Config
{
    public function __construct(private string $archive_root) {}
    public function get_retention_archive_root(): string { return $this->archive_root; }
    public function get_retention_worker_row_limit(): int { return 7; }
    public function get_retention_default_batch_limit(): int { return 3; }
}

/** A small wpdb adapter that executes the repository's real SQL in local MariaDB. */
final class Sandbox_Wpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public int $insert_id = 0;
    public array $statements = [];
    public bool $fail_tkzone_insert = false;
    public bool $lose_commit_ack = false;

    public function __construct(public PDO $pdo) {}

    public function get_charset_collate(): string { return 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'; }

    public function prepare($sql, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $index = 0;
        $prepared = preg_replace_callback('/%[sd]/', function ($match) use ($args, &$index): string {
            if (!array_key_exists($index, $args)) { throw new RuntimeException('Missing SQL argument.'); }
            $value = $args[$index++];
            return $match[0] === '%d' ? (string) (int) $value : $this->pdo->quote((string) $value);
        }, $sql);
        if ($index !== count($args)) { throw new RuntimeException('Unused SQL argument.'); }
        return $prepared;
    }

    private function statement(string $sql): ?PDOStatement
    {
        $this->last_error = '';
        $this->statements[] = $sql;
        try {
            if ($this->fail_tkzone_insert && str_starts_with(ltrim($sql), 'UPDATE wp_kiwi_landing_funnel_daily_tkzone_summary')) {
                throw new RuntimeException('Injected TK-zone insert failure.');
            }
            return $this->pdo->query($sql);
        } catch (Throwable $error) {
            $this->last_error = $error->getMessage();
            return null;
        }
    }

    public function query($sql)
    {
        $s = $this->statement($sql);
        if ($s && $sql === 'COMMIT' && $this->lose_commit_ack) {
            $this->lose_commit_ack = false;
            return false; // The database committed; the caller did not receive an acknowledgement.
        }
        return $s === null ? false : $s->rowCount();
    }
    public function get_results($sql, $output = null) { $s = $this->statement($sql); return $s?->fetchAll(PDO::FETCH_ASSOC); }
    public function get_row($sql, $output = null) { $s = $this->statement($sql); return $s === null ? null : ($s->fetch(PDO::FETCH_ASSOC) ?: null); }
    public function get_col($sql) { $s = $this->statement($sql); return $s?->fetchAll(PDO::FETCH_COLUMN); }
    public function get_var($sql) { $s = $this->statement($sql); return $s === null ? null : (($v = $s->fetchColumn()) === false ? null : $v); }

    public function insert($table, $row, $formats = []): int|false
    {
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', array_keys($row)) . '`) VALUES ('
            . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $this->pdo->quote((string) $v), array_values($row))) . ')';
        $result = $this->query($sql);
        $this->insert_id = $result === false ? 0 : (int) $this->pdo->lastInsertId();
        return $result;
    }

    public function update($table, $row, $where, $formats = [], $where_formats = []): int|false
    {
        $value = fn($v) => $v === null ? 'NULL' : $this->pdo->quote((string) $v);
        $set = [];
        $conditions = [];
        foreach ($row as $k => $v) { $set[] = '`' . $k . '` = ' . $value($v); }
        foreach ($where as $k => $v) { $conditions[] = '`' . $k . '`' . ($v === null ? ' IS NULL' : ' = ' . $value($v)); }
        return $this->query('UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . implode(' AND ', $conditions));
    }
}

function sandbox_assert(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}

function sandbox_sql(string $sql): void
{
    global $wpdb;
    sandbox_assert($wpdb->query($sql) !== false, $wpdb->last_error ?: 'Sandbox query failed.');
}

function sandbox_insert(string $table, array $row): void
{
    global $wpdb;
    sandbox_assert($wpdb->insert($table, $row) !== false, $wpdb->last_error ?: 'Sandbox insert failed.');
}
