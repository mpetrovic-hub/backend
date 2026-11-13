<?php

/** Local SQL boundary: real SQLite storage, simulated MySQL connection/lock functions. */
class Kiwi_Test_Health_State_Wpdb
{
    public $prefix = 'fixture_';
    public $options = 'fixture_options';
    public $last_error = '';
    public $pdo;
    public $connection = 7;
    public $owner = null;
    public $before_query = null;
    public $fail_matching = '';

    public function __construct(bool $prepared = true)
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE fixture_options (option_name TEXT PRIMARY KEY, option_value TEXT NOT NULL, autoload TEXT NOT NULL)');
        $this->pdo->sqliteCreateFunction('CONNECTION_ID', fn() => $this->connection);
        $this->pdo->sqliteCreateFunction('IS_USED_LOCK', fn($name) => $this->owner);
        if ($prepared) {
            $state = ['format_version' => 1, 'revision' => 0, 'activated_at' => null,
                'active_attempt_id' => null, 'last_confirmed_attempt_id' => null,
                'last_freshness_completed_at' => null, 'last_attempt_sequence' => 0];
            $insert = $this->pdo->prepare('INSERT INTO fixture_options VALUES (?, ?, ?)');
            $insert->execute(['kiwi_retention_archive_health_state', json_encode($state), 'no']);
        }
    }

    public function prepare(string $sql, ...$args): string
    {
        return preg_replace_callback('/%[sd]/', function ($match) use (&$args) {
            $value = array_shift($args);
            return $match[0] === '%d' ? (string) (int) $value : $this->pdo->quote((string) $value);
        }, $sql);
    }

    public function query(string $sql)
    {
        $this->last_error = '';
        if (is_callable($this->before_query)) { ($this->before_query)($sql, $this); }
        if ($this->fail_matching !== '' && strpos($sql, $this->fail_matching) !== false) {
            $this->last_error = 'fixture_database_failure';
            return false;
        }
        try {
            if ($sql === 'START TRANSACTION') { return $this->pdo->exec('BEGIN'); }
            $sql = preg_replace('/\bBINARY\s+/', '', $sql);
            return $this->pdo->exec($sql);
        } catch (Throwable $error) { $this->last_error = 'fixture_database_failure'; return false; }
    }

    public function get_results(string $sql, $format = ARRAY_A): array
    {
        $this->last_error = '';
        if (is_callable($this->before_query)) { ($this->before_query)($sql, $this); }
        if ($this->fail_matching !== '' && strpos($sql, $this->fail_matching) !== false) {
            $this->last_error = 'fixture_database_failure'; return [];
        }
        if (strpos($sql, 'information_schema.TABLES') !== false) { return [['ENGINE' => 'InnoDB']]; }
        if ($sql === 'SELECT DATABASE()') { return [['database' => 'fixture']]; }
        if (strpos($sql, 'SELECT GET_LOCK(') === 0) {
            if ($this->owner !== null) { return [['acquired' => 0]]; }
            $this->owner = $this->connection; return [['acquired' => 1]];
        }
        if (strpos($sql, 'SELECT RELEASE_LOCK(') === 0) {
            if ($this->owner === $this->connection) { $this->owner = null; return [['released' => 1]]; }
            return [['released' => 0]];
        }
        try { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $error) { $this->last_error = 'fixture_database_failure'; return []; }
    }

    public function get_row(string $sql, $format = ARRAY_A): ?array { return $this->get_results($sql, $format)[0] ?? null; }
    public function get_var(string $sql) { $row = $this->get_row($sql); return $row === null ? null : reset($row); }
}

function kiwi_test_health_controller(...$args): Kiwi_Retention_Archive_Health_Controller
{
    return new Kiwi_Retention_Archive_Health_Controller(...$args,
        health_store: new Kiwi_Retention_Archive_Health_Store(new Kiwi_Test_Health_State_Wpdb()));
}

function kiwi_test_corruption_gate(...$args): Kiwi_Retention_Corruption_Safety_Gate_Coordinator
{
    return new Kiwi_Retention_Corruption_Safety_Gate_Coordinator(...$args,
        health_store: new Kiwi_Retention_Archive_Health_Store(new Kiwi_Test_Health_State_Wpdb()));
}
