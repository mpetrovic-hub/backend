<?php

if (!defined('ABSPATH')) { exit; }

/** Direct raw-JSON storage. Provisioning is deliberately not a runtime operation. */
class Kiwi_Retention_Archive_Health_Store
{
    public const STATE_OPTION = 'kiwi_retention_archive_health_state';
    public const ATTEMPT_PREFIX = 'kiwi_retention_archive_health_attempt_';
    public const MAX_BYTES = 16384;

    private $database;

    public function __construct($database = null) { $this->database = $database; }

    public function read(?string $attempt_id = null): array
    {
        $state_row = $this->row(self::STATE_OPTION);
        $state = $this->decode($state_row);
        $this->validate_state($state);
        $attempts = [];
        foreach (array_unique(array_filter([$state['active_attempt_id'], $state['last_confirmed_attempt_id'], $attempt_id])) as $id) {
            if (!self::valid_attempt_id($id)) { throw new RuntimeException('health_attempt_invalid'); }
            $row = $this->row(self::ATTEMPT_PREFIX . $id, true);
            $attempts[$id] = $row === null ? null : $this->decode($row);
            if ($attempts[$id] !== null) { $this->validate_attempt($attempts[$id], $id); }
        }
        $active = $state['active_attempt_id'] === null ? null : $attempts[$state['active_attempt_id']];
        $confirmed = $state['last_confirmed_attempt_id'] === null ? null : $attempts[$state['last_confirmed_attempt_id']];
        if (($state['active_attempt_id'] !== null && (!is_array($active) || $active['status'] !== 'open'))
            || ($state['last_confirmed_attempt_id'] !== null && (!is_array($confirmed)
                || $confirmed['status'] !== 'closed' || !in_array($confirmed['outcome'], ['ok', 'corruption'], true)
                || $confirmed['accepted_at'] === null))
            || ($active !== null && $active['attempt_sequence'] !== $state['last_attempt_sequence'])
            || ($confirmed !== null && $confirmed['attempt_sequence'] > $state['last_attempt_sequence'])
            || ($active !== null && $confirmed !== null && $confirmed['attempt_sequence'] >= $active['attempt_sequence'])
        ) { throw new RuntimeException('health_state_inconsistent'); }
        foreach ($attempts as $candidate) {
            if ($candidate !== null && ($candidate['attempt_sequence'] > $state['last_attempt_sequence']
                || ($candidate['status'] === 'open' && $candidate['attempt_id'] !== $state['active_attempt_id']))) {
                throw new RuntimeException('health_state_inconsistent');
            }
        }
        // Status and worker gates must not combine references from different committed revisions.
        if ($this->row(self::STATE_OPTION) !== $state_row) { throw new RuntimeException('health_state_changed'); }
        return ['state' => $state, 'active_attempt' => $active, 'last_confirmed_attempt' => $confirmed,
            'attempt' => $attempt_id === null ? null : ($attempts[$attempt_id] ?? null)];
    }

    public function pending_gate(string $generation = ''): array
    {
        try {
            $view = $this->read();
            $active = $view['active_attempt'];
            $pending = $active !== null && ($generation === '' || $active['generation'] === $generation);
            return ['allowed' => !$pending, 'pending' => $pending,
                'reason_code' => $pending ? 'health_attempt_pending' : 'health_pending_clear',
                'attempt_id' => $active['attempt_id'] ?? null];
        } catch (Throwable $error) {
            return ['allowed' => false, 'pending' => null, 'reason_code' => 'health_state_unavailable', 'attempt_id' => null];
        }
    }

    /**
     * Both callbacks hold the short health write lock; neither may transfer or scan an archive.
     * The optional preparation confirms external effects in autocommit before the state transaction
     * (notably Operational Events, whose lifecycle lock must not precede their actual commit).
     * It must not mutate health state. The same view, raw state and connection are required afterward.
     */
    public function update(?string $attempt_id, callable $change, ?int $expected_revision = null, ?callable $before_transaction = null): array
    {
        if ($attempt_id !== null && !self::valid_attempt_id($attempt_id)) { throw new RuntimeException('health_attempt_invalid'); }
        $db = $this->db();
        $database = $db->get_var('SELECT DATABASE()');
        $this->assert_database_ok();
        if (!is_string($database) || $database === '') { throw new RuntimeException('health_database_unavailable'); }
        $lock_name = 'kiwi_health_' . substr(hash('sha256', $database . ':' . $this->table()), 0, 48);
        $acquired = $db->get_var($db->prepare('SELECT GET_LOCK(%s, 5)', $lock_name));
        $this->assert_database_ok();
        if ((string) $acquired !== '1') { throw new RuntimeException('health_write_lock_unavailable'); }
        $connection = null;
        $transaction = false;
        try {
            $connection = $db->get_var('SELECT CONNECTION_ID()');
            $this->assert_database_ok();
            if (!is_numeric($connection) || (int) $connection < 1) { throw new RuntimeException('health_connection_invalid'); }
            $connection = (int) $connection;
            $this->assert_owner($lock_name, $connection);
            $engine = $db->get_var($db->prepare('SELECT ENGINE FROM information_schema.TABLES'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->table()));
            $this->assert_database_ok();
            if (strtolower((string) $engine) !== 'innodb') { throw new RuntimeException('health_options_not_transactional'); }
            $prepared_view = null;
            if ($before_transaction !== null) {
                $prepared_view = $this->read($attempt_id);
                $prepared_state = $this->row(self::STATE_OPTION);
                if ($this->decode($prepared_state) !== $prepared_view['state']
                    || ($expected_revision !== null && $prepared_view['state']['revision'] !== $expected_revision)) {
                    throw new RuntimeException('health_revision_conflict');
                }
                $this->assert_owner($lock_name, $connection);
                $before_transaction($prepared_view);
                $this->assert_owner($lock_name, $connection);
                if ($this->row(self::STATE_OPTION) !== $prepared_state || $this->read($attempt_id) !== $prepared_view) {
                    throw new RuntimeException('health_revision_conflict');
                }
            }
            if ($db->query('START TRANSACTION') === false) { throw new RuntimeException('health_transaction_failed'); }
            $transaction = true;
            $this->assert_owner($lock_name, $connection);
            $view = $this->read($attempt_id);
            if ($prepared_view !== null && $view !== $prepared_view) { throw new RuntimeException('health_revision_conflict'); }
            $previous_state = $this->row(self::STATE_OPTION);
            $previous_attempt = $attempt_id === null ? null : $this->row(self::ATTEMPT_PREFIX . $attempt_id, true);
            if ($this->decode($previous_state) !== $view['state']
                || ($previous_attempt !== null && $this->decode($previous_attempt) !== $view['attempt'])) {
                throw new RuntimeException('health_revision_conflict');
            }
            if ($expected_revision !== null && $view['state']['revision'] !== $expected_revision) {
                throw new RuntimeException('health_revision_conflict');
            }
            $next = $change($view);
            if (!is_array($next) || !isset($next['state'])) { throw new RuntimeException('health_change_invalid'); }
            $state = $next['state'];
            $attempt = $next['attempt'] ?? null;
            if (!is_array($state) || $state['revision'] !== $view['state']['revision']) {
                throw new RuntimeException('health_revision_conflict');
            }
            $state['revision']++;
            $this->validate_state($state);
            if ($attempt_id !== null) {
                if (!is_array($attempt)) { throw new RuntimeException('health_change_invalid'); }
                $this->validate_attempt($attempt, $attempt_id);
                if (($state['active_attempt_id'] === $attempt_id) !== ($attempt['status'] === 'open')) {
                    throw new RuntimeException('health_state_inconsistent');
                }
            }
            $state_json = $this->encode($state);
            $attempt_json = $attempt_id === null ? null : $this->encode($attempt);
            $this->assert_owner($lock_name, $connection);
            if ($attempt_id !== null) {
                $this->write(self::ATTEMPT_PREFIX . $attempt_id, $attempt_json, $previous_attempt, $lock_name, $connection);
            }
            $this->write(self::STATE_OPTION, $state_json, $previous_state, $lock_name, $connection);
            $this->assert_owner($lock_name, $connection);
            if ($db->query('COMMIT') === false) { throw new RuntimeException('health_commit_unconfirmed'); }
            $transaction = false;
            $this->assert_owner($lock_name, $connection);
            $readback = $this->row(self::STATE_OPTION);
            if ($readback !== ['option_value' => $state_json, 'autoload' => 'no']) {
                throw new RuntimeException('health_readback_unconfirmed');
            }
            if ($attempt_id !== null && $this->row(self::ATTEMPT_PREFIX . $attempt_id)
                !== ['option_value' => $attempt_json, 'autoload' => 'no']) {
                throw new RuntimeException('health_readback_unconfirmed');
            }
            $confirmed = $this->read($attempt_id);
            $this->assert_owner($lock_name, $connection);
            return $confirmed;
        } finally {
            if ($transaction) { $db->query('ROLLBACK'); }
            // A reconnected session cannot release another connection's named lock.
            $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    private function write(string $name, string $json, ?array $old, string $lock, int $connection): void
    {
        $db = $this->db();
        if ($old === ['option_value' => $json, 'autoload' => 'no']) {
            $this->assert_owner($lock, $connection);
            if ($this->row($name) !== $old) { throw new RuntimeException('health_write_unconfirmed'); }
            return;
        }
        $guard = $db->prepare('IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d', $lock, $connection);
        if ($old === null) {
            $sql = $db->prepare('INSERT INTO ' . $this->table()
                . ' (option_name, option_value, autoload) SELECT %s, %s, %s WHERE ' . $guard, $name, $json, 'no');
        } else {
            $sql = $db->prepare('UPDATE ' . $this->table() . ' SET option_value = %s, autoload = %s'
                . ' WHERE option_name = %s AND BINARY option_value = BINARY %s AND BINARY autoload = BINARY %s AND ' . $guard,
                $json, 'no', $name, $old['option_value'], $old['autoload']);
        }
        if ($db->query($sql) !== 1) { throw new RuntimeException('health_write_unconfirmed'); }
        $this->assert_database_ok();
        $this->assert_owner($lock, $connection);
    }

    private function assert_owner(string $lock, int $connection): void
    {
        $db = $this->db();
        $owned = $db->get_var($db->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d', $lock, $connection));
        $this->assert_database_ok();
        if ((string) $owned !== '1') { throw new RuntimeException('health_write_lock_lost'); }
    }

    private function assert_database_ok(): void
    {
        if ((string) ($this->db()->last_error ?? '') !== '') { throw new RuntimeException('health_database_failed'); }
    }

    private function encode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) > self::MAX_BYTES) { throw new RuntimeException('health_storage_invalid'); }
        return $json;
    }

    public static function valid_attempt_id($id): bool
    {
        return is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id) === 1;
    }

    private function validate_state(array $state): void
    {
        $fields = ['format_version', 'revision', 'activated_at', 'active_attempt_id',
            'last_confirmed_attempt_id', 'last_freshness_completed_at', 'last_attempt_sequence'];
        if (count($state) !== count($fields) || array_diff($fields, array_keys($state))
            || $state['format_version'] !== 1 || !is_int($state['revision']) || $state['revision'] < 0
            || !is_int($state['last_attempt_sequence']) || $state['last_attempt_sequence'] < 0
            || $state['revision'] < $state['last_attempt_sequence']
            || ($state['active_attempt_id'] !== null && $state['active_attempt_id'] === $state['last_confirmed_attempt_id'])
        ) { throw new RuntimeException('health_state_invalid'); }
        foreach (['active_attempt_id', 'last_confirmed_attempt_id'] as $key) {
            if ($state[$key] !== null && !self::valid_attempt_id($state[$key])) { throw new RuntimeException('health_state_invalid'); }
        }
        foreach (['activated_at', 'last_freshness_completed_at'] as $key) { $this->validate_time($state[$key]); }
    }

    private function validate_attempt(array $attempt, string $id): void
    {
        $fields = ['format_version', 'attempt_id', 'attempt_sequence', 'start_kind', 'started_at', 'generation',
            'expected_bytes', 'received_bytes', 'sha256', 'transfer_complete', 'transfer_completed_at', 'status', 'outcome',
            'result_fingerprint', 'received_at', 'accepted_at', 'invalidated_at', 'closed_at', 'reason_code', 'phase', 'message', 'operation_order'];
        if (count($attempt) !== count($fields) || array_diff($fields, array_keys($attempt))) { throw new RuntimeException('health_attempt_invalid'); }
        if (($attempt['format_version'] ?? null) !== 1 || ($attempt['attempt_id'] ?? null) !== $id
            || !is_int($attempt['attempt_sequence'] ?? null) || $attempt['attempt_sequence'] < 1
            || !in_array($attempt['status'] ?? null, ['open', 'closed'], true)
            || !in_array($attempt['outcome'] ?? null, [null, 'ok', 'corruption', 'technical_failure', 'manual_abort'], true)
            || !is_string($attempt['generation']) || $attempt['generation'] === ''
            || Kiwi_Retention_Archive_Name::normalize($attempt['generation']) !== $attempt['generation']
            || !in_array($attempt['start_kind'], ['manual', 'scheduled'], true)
            || !is_int($attempt['expected_bytes']) || $attempt['expected_bytes'] < 1
            || ($attempt['received_bytes'] !== null && (!is_int($attempt['received_bytes'])
                || $attempt['received_bytes'] < 0 || $attempt['received_bytes'] > $attempt['expected_bytes']))
            || !is_bool($attempt['transfer_complete'])
            || !is_string($attempt['operation_order']) || preg_match('/^[0-9]{1,20}$/D', $attempt['operation_order']) !== 1
        ) { throw new RuntimeException('health_attempt_invalid'); }
        foreach (['sha256', 'result_fingerprint'] as $key) {
            if ($attempt[$key] !== null && (!is_string($attempt[$key]) || preg_match('/^[a-f0-9]{64}$/D', $attempt[$key]) !== 1)) {
                throw new RuntimeException('health_attempt_invalid');
            }
        }
        foreach (['started_at', 'transfer_completed_at', 'received_at', 'accepted_at', 'invalidated_at', 'closed_at'] as $key) {
            $this->validate_time($attempt[$key]);
        }
        if ($attempt['started_at'] === null
            || ($attempt['transfer_complete'] && ($attempt['received_bytes'] !== $attempt['expected_bytes']
                || $attempt['sha256'] === null || $attempt['transfer_completed_at'] === null))
            || (!$attempt['transfer_complete'] && ($attempt['sha256'] !== null || $attempt['transfer_completed_at'] !== null))
            || (($attempt['status'] === 'closed') !== ($attempt['closed_at'] !== null))
            || ($attempt['status'] === 'closed' && $attempt['outcome'] === null)
            || ($attempt['accepted_at'] !== null && (!in_array($attempt['outcome'], ['ok', 'corruption'], true)
                || $attempt['status'] !== 'closed'))
            || ($attempt['invalidated_at'] !== null && !in_array($attempt['outcome'], ['technical_failure', 'manual_abort'], true))
            || (($attempt['result_fingerprint'] === null) !== ($attempt['received_at'] === null))
            || ($attempt['outcome'] === null && ($attempt['result_fingerprint'] !== null
                || $attempt['reason_code'] !== null || $attempt['phase'] !== null || $attempt['message'] !== null))
        ) { throw new RuntimeException('health_attempt_inconsistent'); }
        if (in_array($attempt['outcome'], ['ok', 'corruption'], true)
            && (!$attempt['transfer_complete'] || $attempt['result_fingerprint'] === null || $attempt['received_at'] === null
                || ($attempt['status'] === 'closed' && $attempt['accepted_at'] === null))) {
            throw new RuntimeException('health_attempt_inconsistent');
        }
        if (in_array($attempt['outcome'], ['technical_failure', 'manual_abort'], true)
            && ($attempt['invalidated_at'] === null || !in_array($attempt['reason_code'], ['health_run_failed', 'health_child_timeout'], true)
                || !in_array($attempt['phase'], ['start', 'copy', 'verification', 'check', 'result', 'abort', 'activation'], true)
                || !is_string($attempt['message']))) {
            throw new RuntimeException('health_attempt_inconsistent');
        }
        foreach (['reason_code' => 100, 'phase' => 32, 'message' => 300] as $key => $limit) {
            if ($attempt[$key] !== null && (!is_string($attempt[$key]) || strlen($attempt[$key]) > $limit
                || preg_match('/[\x00-\x1f\x7f]/', $attempt[$key]))) { throw new RuntimeException('health_attempt_invalid'); }
        }
    }

    private function validate_time($value): void
    {
        if ($value === null) { return; }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{6})?Z$/D', $value) !== 1) {
            throw new RuntimeException('health_time_invalid');
        }
        $format = strpos($value, '.') === false ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:s.u\Z';
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
        if (!$date || $date->format($format) !== $value) { throw new RuntimeException('health_time_invalid'); }
    }

    private function decode(?array $row): array
    {
        if ($row === null || !is_string($row['option_value'] ?? null)
            || strlen($row['option_value']) > self::MAX_BYTES
            || !in_array($row['autoload'] ?? null, ['no', 'off', 'auto-off'], true)
        ) { throw new RuntimeException('health_storage_invalid'); }
        $value = json_decode($row['option_value'], true);
        if (!is_array($value)) { throw new RuntimeException('health_storage_invalid'); }
        return $value;
    }

    private function row(string $name, bool $optional = false): ?array
    {
        $db = $this->db();
        $row = $db->get_row($db->prepare('SELECT option_value, autoload FROM ' . $this->table()
            . ' WHERE option_name = %s', $name), ARRAY_A);
        if ((string) ($db->last_error ?? '') !== '' || (!$optional && !is_array($row))) {
            throw new RuntimeException('health_storage_read_failed');
        }
        return is_array($row) ? $row : null;
    }

    private function table(): string
    {
        $db = $this->db();
        $table = $db->options ?? (($db->prefix ?? '') . 'options');
        if (!is_string($table) || preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) { throw new RuntimeException('health_options_table_invalid'); }
        return $table;
    }

    private function db()
    {
        global $wpdb;
        $db = $this->database ?? $wpdb;
        if (!is_object($db) || !method_exists($db, 'prepare') || !method_exists($db, 'get_row')) {
            throw new RuntimeException('health_database_unavailable');
        }
        return $db;
    }
}
