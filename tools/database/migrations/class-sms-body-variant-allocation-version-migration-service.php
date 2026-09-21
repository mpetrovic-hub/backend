<?php

if (!defined('ABSPATH')) {
    exit;
}

/** External-only, resumable preparation. No application runtime loads this file. */
class Kiwi_Sms_Body_Variant_Allocation_Version_Migration_Service extends Kiwi_Database_Deployment_Service
{
    private const SOURCE_VERSION = '2026-07-23-1';
    private const TABLES = ['kiwi_sms_body_variant_assignments', 'kiwi_sms_body_variant_summary'];
    private $steps = [];
    private $changed = false;

    public function check(): array
    {
        global $wpdb;
        $version = $this->get_installed_schema_version();
        if (!in_array($version, [self::SOURCE_VERSION, self::TARGET_SCHEMA_VERSION], true)) {
            return $this->failure('schema_version_not_supported');
        }
        $contract = require dirname(__DIR__) . '/schema-contract.php';
        $migration_contract = [];
        $present = [];
        foreach (self::TABLES as $suffix) {
            $wpdb->last_error = '';
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $wpdb->prefix . $suffix, 'allocation_version'
            ), ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($rows)) {
                return $this->failure('column_inspection_failed');
            }
            $present[$suffix] = count($rows) === 1;
            $definition = $contract[$suffix];
            unset($definition['migration_required_on_drift']);
            if (!$present[$suffix]) {
                $definition['columns'] = array_values(array_diff($definition['columns'], ['allocation_version']));
                $definition['deployment_details']['columns'] = [];
            }
            $migration_contract[$suffix] = $definition;
        }
        $summary = self::TABLES[1];
        $target_index = $this->inspect_deployment_details($wpdb->prefix . $summary, [
            'deployment_details' => ['indexes' => ['variant_summary']],
            'index_metadata' => $contract[$summary]['index_metadata'],
            'deployment_unique_indexes' => $contract[$summary]['deployment_unique_indexes'],
        ]);
        if (in_array('inspection_error', array_column($target_index, 'kind'), true)) {
            return $this->failure('index_inspection_failed');
        }
        $target = $target_index === [];
        if (!$target) {
            $migration_contract[$summary]['index_metadata']['variant_summary']['columns'] = ['landing_key', 'service_key', 'variant_key', 'seed'];
            $migration_contract[$summary]['index_metadata']['variant_summary']['sub_parts'] = [null, null, null, null];
        }
        $inspection = $this->inspect_contract($migration_contract);
        if (!empty($inspection['drift'])) {
            return $this->failure('unexpected_schema', $inspection['drift']);
        }
        $complete = $target && !in_array(false, $present, true);
        if (($target && !$complete)
            || (!$present[self::TABLES[0]] && $present[$summary])
            || ($version === self::TARGET_SCHEMA_VERSION && !$complete)
        ) {
            return $this->failure('unsupported_partial_state');
        }
        foreach (self::TABLES as $suffix) {
            if (!$present[$suffix]) {
                continue;
            }
            $wpdb->last_error = '';
            $predicate = $complete
                ? "allocation_version IS NULL OR allocation_version = ''"
                : "allocation_version IS NULL OR BINARY allocation_version <> BINARY 'legacy'";
            $invalid = $wpdb->get_var('SELECT COUNT(*) FROM ' . $this->identifier($wpdb->prefix . $suffix) . ' WHERE ' . $predicate);
            if ($wpdb->last_error !== '' || $invalid === null || $invalid === false || !is_numeric($invalid)) {
                return $this->failure('history_inspection_failed');
            }
            if ((int) $invalid !== 0) {
                return $this->failure('unexpected_allocation_history');
            }
        }
        return [
            'success' => true, 'mode' => 'check', 'mutated' => false,
            'state' => $complete ? 'complete' : 'pending', 'columns_present' => $present,
            'target_index' => $target, 'installed_version' => $version,
            'target_version' => self::TARGET_SCHEMA_VERSION,
        ];
    }

    public function apply(): array
    {
        global $wpdb;
        $this->steps = [];
        $this->changed = false;
        if (!$this->acquire_lock()) {
            return $this->failure('lock_unavailable');
        }
        try {
            $before = $this->check();
            if (!$before['success']) {
                return $before + ['steps' => $this->steps];
            }
            // This is a dedicated, short-lived CLI connection. Never change GLOBAL limits.
            if (!$this->command('SET SESSION lock_wait_timeout=0', 'session_lock_timeout', false)) {
                return $this->failure('session_lock_timeout_failed');
            }
            foreach (self::TABLES as $suffix) {
                if ($before['columns_present'][$suffix]) {
                    continue;
                }
                if (!$this->owns_lock()) {
                    return $this->failure('lock_lost');
                }
                if (!$this->command('ALTER TABLE ' . $this->identifier($wpdb->prefix . $suffix)
                    . " ADD COLUMN allocation_version VARCHAR(50) NOT NULL DEFAULT 'legacy', ALGORITHM=INSTANT", $suffix . ':add_column')) {
                    return $this->failure('column_add_failed');
                }
                $state = $this->check();
                if (!$state['success']) {
                    return $state;
                }
            }
            if (!$before['target_index']) {
                if (!$this->owns_lock()) {
                    return $this->failure('lock_lost');
                }
                if (!$this->command('ALTER TABLE ' . $this->identifier($wpdb->prefix . self::TABLES[1])
                    . ' DROP INDEX variant_summary, ADD UNIQUE KEY variant_summary (landing_key, service_key, variant_key, seed, allocation_version), ALGORITHM=INPLACE, LOCK=NONE', 'summary:replace_index')) {
                    return $this->failure('index_replace_failed');
                }
            }
            $state = $this->check();
            if (!$state['success'] || $state['state'] !== 'complete') {
                return $this->failure('target_verification_failed', $state['drift'] ?? []);
            }
            $final = $this->inspect_schema();
            if (!empty($final['drift'])) {
                return $this->failure('final_schema_verification_failed', $final['drift']);
            }
            if (!$this->owns_lock()) {
                return $this->failure('lock_lost');
            }
            if ($this->get_installed_schema_version() !== self::TARGET_SCHEMA_VERSION) {
                $this->changed = true;
                if (!$this->persist_schema_version(self::TARGET_SCHEMA_VERSION)) {
                    return $this->failure('version_persistence_failed');
                }
            }
            $status = $this->status();
            if (empty($status['ready'])) {
                return $this->failure('published_version_verification_failed', $status['drift'] ?? []);
            }
            return [
                'success' => true, 'mode' => 'apply', 'state' => 'complete',
                'mutated' => $this->changed, 'no_op' => !$this->changed,
                'installed_version' => $this->get_installed_schema_version(),
                'target_version' => self::TARGET_SCHEMA_VERSION, 'steps' => $this->steps,
            ];
        } catch (Throwable $error) {
            // Database errors may contain row data. Return bounded operation codes only.
            return $this->failure('migration_exception');
        } finally {
            $this->release_lock();
        }
    }

    private function command(string $sql, string $step, bool $mutation = true): bool
    {
        global $wpdb;
        $wpdb->last_error = '';
        $result = $wpdb->query($sql);
        $ok = $result !== false && $wpdb->last_error === '';
        $this->changed = $this->changed || ($ok && $mutation);
        $this->steps[] = ['step' => $step, 'success' => $ok];
        return $ok;
    }

    private function owns_lock(): bool
    {
        global $wpdb;
        $name = 'kiwi_backend_database_apply_' . substr(hash('sha256', (string) $wpdb->prefix), 0, 20);
        $wpdb->last_error = '';
        return (string) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name)) === '1'
            && $wpdb->last_error === '';
    }

    private function failure(string $code, array $drift = []): array
    {
        return [
            'success' => false, 'state' => 'blocked', 'mutated' => $this->changed,
            'error_code' => $code, 'steps' => $this->steps, 'drift' => $drift,
            'installed_version' => $this->get_installed_schema_version(),
            'target_version' => self::TARGET_SCHEMA_VERSION,
            'recovery' => 'Keep compatible application code active. Preserve completed steps; inspect state and obtain explicit authorization before another apply.',
        ];
    }

    private function identifier(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }
}
