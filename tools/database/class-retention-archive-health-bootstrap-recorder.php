<?php

final class Kiwi_Retention_Archive_Health_Bootstrap_Recorder
{
    private const STATE_SCHEMA_VERSION = 1;
    private const STATE_FILENAME = 'kiwi_retention_archive_health_state.json';
    private const CONTROLLER_LOCK_FILENAME = 'kiwi_retention_archive_health_controller.lock';
    private const CONTROLLER_DEFERRAL_RECEIPT_PREFIX = 'kiwi_retention_archive_health_deferral_';
    private const CONTROLLER_DEFERRAL_RECEIPT_SCHEMA_VERSION = 1;
    private const CONTROLLER_DEFERRAL_RECEIPT_LIMIT = 64;
    private const DAILY_ATTEMPT_LIMIT = 3;
    private const ALLOWED_REASONS = [
        'controller_lock_active',
        'controller_deferral_receipt_invalid',
        'wp_cli_loader_unavailable',
        'plugins_loaded_hook_failed',
        'plugins_loaded_not_reached',
        'wordpress_load_failed',
        'wordpress_lifecycle_invalid',
        'required_class_missing',
        'health_service_exception',
        'health_service_unavailable',
        'health_bootstrap_failed',
    ];

    private $archive_directory;
    private $clock;
    private $incident_recorder;
    private $operation_started_microtime = 0.0;

    public function __construct(
        string $archive_directory = '',
        ?callable $clock = null,
        ?callable $incident_recorder = null
    ) {
        $this->archive_directory = $archive_directory !== ''
            ? rtrim($archive_directory, '/\\')
            : $this->default_archive_directory();
        $this->clock = $clock ?? static function (): DateTimeImmutable {
            return new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
        };
        $this->incident_recorder = $incident_recorder;
    }

    public function record(string $reason_code): array
    {
        $this->operation_started_microtime = microtime(true);
        $started_at = $this->now();
        $reason_code = $this->normalize_reason($reason_code);
        if (!is_dir($this->archive_directory)
            && !@mkdir($this->archive_directory, 0770, true)
            && !is_dir($this->archive_directory)
        ) {
            return $this->result(
                '',
                'bootstrap',
                '',
                'archive_directory_unavailable',
                $started_at
            );
        }

        $lock = @fopen(
            $this->archive_directory . DIRECTORY_SEPARATOR . self::CONTROLLER_LOCK_FILENAME,
            'c+'
        );
        if (!is_resource($lock)) {
            return $this->result('', 'bootstrap', '', 'archive_lock_open_failed', $started_at);
        }
        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            @fclose($lock);
            if (!$this->persist_controller_deferral_receipt()) {
                return $this->result(
                    '',
                    'bootstrap',
                    '',
                    'controller_deferral_persist_failed',
                    $started_at
                );
            }

            return $this->result('', 'bootstrap', '', 'archive_lock_active', $started_at);
        }

        try {
            $state = $this->read_state();
            if (!is_array($state)) {
                return $this->result('', 'bootstrap', '', 'health_state_invalid', $started_at);
            }

            $reconciliation = $this->reconcile_controller_deferral_receipts($state);
            if (empty($reconciliation['success'])) {
                return $this->result(
                    '',
                    'bootstrap',
                    '',
                    (string) (
                        $reconciliation['reason_code']
                            ?? 'controller_deferral_receipt_invalid'
                    ),
                    $started_at
                );
            }
            $state = (array) ($reconciliation['state'] ?? $state);
            $incident_action = (string) ($reconciliation['incident_action'] ?? '');
            $now = $this->current_datetime();
            $date = $now->format('Y-m-d');
            $check = $now->format('N') === '7' ? 'integrity' : 'quick';
            [$state, $check] = $this->align_daily_state($state, $date, $check);
            if ((string) ($state['daily']['status'] ?? '') === 'completed') {
                return $this->result(
                    '',
                    'bootstrap',
                    '',
                    $reason_code,
                    $started_at,
                    $incident_action
                );
            }
            if ((int) ($state['daily']['attempts'] ?? 0) >= self::DAILY_ATTEMPT_LIMIT) {
                return $this->result(
                    $check,
                    'daily',
                    (string) ($state['daily']['archive'] ?? ''),
                    $reason_code,
                    $started_at,
                    $incident_action
                );
            }

            $archive = $this->normalize_archive_name((string) ($state['daily']['archive'] ?? ''));
            $state['daily']['archive'] = $archive;
            $state['daily']['check'] = $check;
            $state['daily']['attempts'] = (int) ($state['daily']['attempts'] ?? 0) + 1;
            $state['daily']['status'] = 'incomplete';
            $state['daily']['result'] = 'error';
            $state['daily']['reason_code'] = $reason_code;
            $state['daily']['completed_at'] = '';
            if ((int) $state['daily']['attempts'] >= self::DAILY_ATTEMPT_LIMIT) {
                $current_incident_action = $this->record_incomplete_incident(
                    $archive,
                    $check,
                    $reason_code,
                    $date
                );
                if ($current_incident_action === '') {
                    return $this->result(
                        $check,
                        'daily',
                        $archive,
                        'incomplete_incident_persist_failed',
                        $started_at
                    );
                }
                $incident_action = $current_incident_action;
            }
            if (!$this->write_state($state)) {
                return $this->result(
                    $check,
                    'daily',
                    $archive,
                    'health_state_write_failed',
                    $started_at
                );
            }

            return $this->result(
                $check,
                'daily',
                $archive,
                $reason_code,
                $started_at,
                $incident_action
            );
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private function align_daily_state(array $state, string $date, string $daily_check): array
    {
        $stored_date = (string) ($state['daily']['date'] ?? '');
        $stored_status = (string) ($state['daily']['status'] ?? '');
        $attempt_date = (string) ($state['daily']['attempt_date'] ?? $stored_date);
        $overdue = $stored_date !== ''
            && $stored_date !== $date
            && $stored_status === 'incomplete';
        if ($overdue) {
            $daily_check = $this->normalize_check((string) ($state['daily']['check'] ?? ''));
            if ($attempt_date !== $date) {
                $state['daily']['attempt_date'] = $date;
                $state['daily']['attempts'] = 0;
                $state['daily']['controller_deferral_receipts'] = [];
            }
        } elseif ($stored_date !== $date) {
            $state['daily'] = [
                'date' => $date,
                'attempt_date' => $date,
                'archive' => '',
                'check' => $daily_check,
                'attempts' => 0,
                'status' => 'pending',
                'result' => '',
                'reason_code' => '',
                'completed_at' => '',
                'controller_deferral_receipts' => [],
            ];
        }

        return [$state, $daily_check];
    }

    private function read_state(): ?array
    {
        $path = $this->state_path();
        if (!is_file($path)) {
            return $this->default_state();
        }

        $raw = @file_get_contents($path);
        $state = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($state) && $this->is_valid_state($state) ? $state : null;
    }

    private function write_state(array $state): bool
    {
        $state['schema_version'] = self::STATE_SCHEMA_VERSION;
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($state)
            : json_encode($state);
        if (!is_string($json)) {
            return false;
        }

        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (Throwable $error) {
            $suffix = substr(md5(uniqid('', true)), 0, 16);
        }
        $temporary_path = $this->state_path() . '.tmp.' . $suffix;
        $written = @file_put_contents($temporary_path, $json . "\n", LOCK_EX);
        if ($written === false || $written !== strlen($json) + 1) {
            @unlink($temporary_path);

            return false;
        }
        if (!@rename($temporary_path, $this->state_path())) {
            @unlink($temporary_path);

            return false;
        }

        return true;
    }

    private function is_valid_state(array $state): bool
    {
        if ((int) ($state['schema_version'] ?? 0) !== self::STATE_SCHEMA_VERSION
            || !isset($state['daily'], $state['annual'])
            || !is_array($state['daily'])
            || !is_array($state['annual'])
        ) {
            return false;
        }

        $daily = $state['daily'];
        foreach (['date', 'archive', 'check', 'status', 'result', 'reason_code', 'completed_at'] as $field) {
            if (!array_key_exists($field, $daily) || !is_string($daily[$field])) {
                return false;
            }
        }
        $daily_date = $daily['date'];
        $attempt_date = $daily['attempt_date'] ?? $daily_date;
        $archive = $daily['archive'];
        $check = $daily['check'];
        $attempts = $daily['attempts'] ?? null;
        $status = $daily['status'];
        $result = $daily['result'];
        $reason = $daily['reason_code'];
        $completed_at = $daily['completed_at'];
        $controller_deferral_receipts = $daily['controller_deferral_receipts'] ?? [];
        if (!is_string($attempt_date)
            || !is_int($attempts)
            || $attempts < 0
            || $attempts > self::DAILY_ATTEMPT_LIMIT
            || !in_array($status, ['pending', 'incomplete', 'completed'], true)
            || !in_array($result, ['', 'ok', 'corruption_detected', 'deferred', 'inconclusive', 'error', 'no_work'], true)
            || ($archive !== '' && $this->normalize_archive_name($archive) === '')
            || ($daily_date !== '' && !$this->is_valid_date($daily_date))
            || ($attempt_date !== '' && !$this->is_valid_date($attempt_date))
            || (($daily_date === '') !== ($attempt_date === ''))
            || ($daily_date !== '' && $attempt_date < $daily_date)
            || ($check !== '' && $this->normalize_check($check) === '')
            || ($completed_at !== '' && !$this->is_valid_timestamp($completed_at))
            || !is_array($controller_deferral_receipts)
            || count($controller_deferral_receipts) > self::CONTROLLER_DEFERRAL_RECEIPT_LIMIT
            || array_values($controller_deferral_receipts) !== $controller_deferral_receipts
            || count($controller_deferral_receipts)
                !== count(array_unique($controller_deferral_receipts))
        ) {
            return false;
        }
        foreach ($controller_deferral_receipts as $receipt_id) {
            if (!is_string($receipt_id)
                || preg_match('/^[a-f0-9]{32}$/', $receipt_id) !== 1
            ) {
                return false;
            }
        }
        if ($status === 'pending'
            && ($result !== ''
                || $attempts !== 0
                || $archive !== ''
                || $reason !== ''
                || $completed_at !== ''
                || (($daily_date === '') !== ($check === '')))
        ) {
            return false;
        }
        $resolution_failure = in_array(
            $reason,
            array_merge(
                self::ALLOWED_REASONS,
                ['active_archive_lookup_failed', 'active_archive_path_invalid', 'active_archive_missing']
            ),
            true
        );
        if ($status === 'incomplete'
            && (!$this->is_valid_date($daily_date)
                || $this->normalize_check($check) === ''
                || ($archive === '' && !$resolution_failure)
                || $attempts < 1
                || !in_array($result, ['deferred', 'inconclusive', 'error'], true)
                || $reason === ''
                || $completed_at !== '')
        ) {
            return false;
        }
        if ($status === 'completed'
            && (!$this->is_valid_date($daily_date)
                || $this->normalize_check($check) === ''
                || !in_array($result, ['ok', 'corruption_detected', 'no_work'], true)
                || $reason === ''
                || !$this->is_valid_timestamp($completed_at)
                || ($result === 'no_work' && ($archive !== '' || $attempts !== 0))
                || ($result !== 'no_work' && ($archive === '' || $attempts < 1)))
        ) {
            return false;
        }

        $annual = $state['annual'];
        $cycle_year = $annual['cycle_year'] ?? null;
        $annual_status = $annual['status'] ?? null;
        $snapshot = $annual['snapshot'] ?? null;
        $completed = $annual['completed'] ?? null;
        $results = $annual['results'] ?? null;
        if (!is_string($cycle_year)
            || !is_string($annual_status)
            || !in_array($annual_status, ['pending', 'running', 'completed'], true)
            || !is_array($snapshot)
            || !is_array($completed)
            || !is_array($results)
        ) {
            return false;
        }
        foreach (array_merge($snapshot, $completed) as $archive_name) {
            if (!is_string($archive_name) || $this->normalize_archive_name($archive_name) === '') {
                return false;
            }
        }
        foreach ($results as $archive_name => $annual_result) {
            if (!is_string($archive_name)
                || $this->normalize_archive_name($archive_name) === ''
                || !is_string($annual_result)
                || !in_array($annual_result, ['ok', 'corruption_detected', 'skipped'], true)
            ) {
                return false;
            }
        }
        if (count($snapshot) !== count(array_unique($snapshot))
            || count($completed) !== count(array_unique($completed))
            || array_diff($completed, $snapshot) !== []
            || array_diff(array_keys($results), $snapshot) !== []
            || array_diff($completed, array_keys($results)) !== []
            || array_diff(array_keys($results), $completed) !== []
            || ($cycle_year !== '' && preg_match('/^[0-9]{4}$/', $cycle_year) !== 1)
            || ($annual_status === 'pending'
                && ($cycle_year !== '' || $snapshot !== [] || $completed !== [] || $results !== []))
            || ($annual_status !== 'pending' && $cycle_year === '')
            || ($annual_status === 'running' && count($completed) >= count($snapshot))
            || ($annual_status === 'completed' && count($completed) !== count($snapshot))
        ) {
            return false;
        }

        return true;
    }

    private function record_incomplete_incident(
        string $archive,
        string $check,
        string $reason_code,
        string $date
    ): string {
        $subject = $archive !== '' ? $archive : 'active_archive_lookup';
        $event = [
            'area' => 'retention',
            'severity' => 'error',
            'event_type' => 'retention_archive_health_check_incomplete',
            'correlation_key' => 'retention_archive_health_incomplete_' . hash('sha256', $subject),
            'idempotency_key' => 'retention_archive_health_incomplete_' . hash(
                'sha256',
                $subject . ':' . $date
            ),
            'reference_type' => $archive !== '' ? 'retention_archive' : 'retention_archive_lookup',
            'reference_id' => $subject,
            'message' => 'Retention archive health check remained incomplete after all daily attempts.',
            'raw_error_text' => $reason_code,
            'context' => [
                'archive' => $archive,
                'check' => $check,
                'reason_code' => $reason_code,
                'attempts' => self::DAILY_ATTEMPT_LIMIT,
                'operator_review_within_workdays' => 1,
            ],
        ];
        if (is_callable($this->incident_recorder)) {
            $action = (string) call_user_func($this->incident_recorder, $event);

            return in_array($action, ['raised', 'repeated'], true) ? $action : '';
        }
        if (class_exists('Kiwi_Operational_Event_Service')
            && method_exists('Kiwi_Operational_Event_Service', 'record_failure_action')
        ) {
            try {
                $action = (string) (new Kiwi_Operational_Event_Service())->record_failure_action($event);
                if (in_array($action, ['raised', 'repeated'], true)) {
                    return $action;
                }
            } catch (Throwable $error) {
                // Fall through to the dependency-independent repository write.
            }
        }

        try {
            return $this->record_incomplete_incident_with_wpdb($event);
        } catch (Throwable $error) {
            return '';
        }
    }

    private function record_incomplete_incident_with_wpdb(array $event): string
    {
        global $wpdb;
        if (!is_object($wpdb)
            || !isset($wpdb->prefix)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'get_row')
            || !method_exists($wpdb, 'insert')
        ) {
            return '';
        }

        $table = (string) $wpdb->prefix . 'kiwi_operational_events';
        $correlation_key = (string) $event['correlation_key'];
        $idempotency_key = (string) $event['idempotency_key'];
        $output_type = defined('ARRAY_A') ? constant('ARRAY_A') : 'ARRAY_A';
        $wpdb->last_error = '';
        $latest = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT lifecycle_action FROM {$table}
                 WHERE correlation_key = %s
                 ORDER BY occurred_at DESC, id DESC
                 LIMIT 1",
                $correlation_key
            ),
            $output_type
        );
        if ((string) ($wpdb->last_error ?? '') !== '') {
            return '';
        }
        $lifecycle_action = is_array($latest)
            && in_array((string) ($latest['lifecycle_action'] ?? ''), ['raised', 'repeated'], true)
                ? 'repeated'
                : 'raised';
        $now = $this->current_datetime()->format('Y-m-d H:i:s');
        $context_json = json_encode($event['context'], JSON_UNESCAPED_SLASHES);
        if (!is_string($context_json)) {
            return '';
        }
        $row = [
            'occurred_at' => $now,
            'created_at' => $now,
            'area' => (string) $event['area'],
            'severity' => (string) $event['severity'],
            'event_type' => (string) $event['event_type'],
            'lifecycle_action' => $lifecycle_action,
            'idempotency_key' => $idempotency_key,
            'correlation_key' => $correlation_key,
            'reference_type' => (string) $event['reference_type'],
            'reference_id' => (string) $event['reference_id'],
            'message' => (string) $event['message'],
            'raw_error_text' => (string) $event['raw_error_text'],
            'context_json' => $context_json,
        ];
        $wpdb->last_error = '';
        $inserted = $wpdb->insert($table, $row, array_fill(0, count($row), '%s'));
        if ($inserted === false && (string) ($wpdb->last_error ?? '') !== '') {
            $wpdb->last_error = '';
        }
        $persisted = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT lifecycle_action FROM {$table}
                 WHERE idempotency_key = %s
                 LIMIT 1",
                $idempotency_key
            ),
            $output_type
        );
        if ((string) ($wpdb->last_error ?? '') !== ''
            || !is_array($persisted)
            || !in_array((string) ($persisted['lifecycle_action'] ?? ''), ['raised', 'repeated'], true)
        ) {
            return '';
        }

        return (string) $persisted['lifecycle_action'];
    }

    private function result(
        string $check,
        string $scope,
        string $archive,
        string $reason_code,
        string $started_at,
        string $incident_action = ''
    ): array {
        $normalized_check = $this->normalize_check($check);
        $normalized_archive = $this->normalize_archive_name($archive);

        return [
            'schema_version' => 1,
            'status' => 'failed',
            'exit_code' => 2,
            'check' => $normalized_check === 'quick'
                ? 'quick_check'
                : ($normalized_check === 'integrity' ? 'integrity_check' : null),
            'scope' => $scope,
            'archive' => $normalized_archive !== '' ? $normalized_archive : null,
            'result' => 'error',
            'reason_code' => $this->normalize_reason($reason_code),
            'started_at' => $started_at,
            'finished_at' => $this->now(),
            'duration_seconds' => round(
                max(0.0, microtime(true) - $this->operation_started_microtime),
                6
            ),
            'incident_action' => in_array($incident_action, ['raised', 'repeated'], true)
                ? $incident_action
                : null,
        ];
    }

    private function default_archive_directory(): string
    {
        $root = defined('KIWI_RETENTION_ARCHIVE_ROOT')
            ? rtrim((string) KIWI_RETENTION_ARCHIVE_ROOT, '/\\')
            : '/home/u367252972/kiwi-backend-archives/db-retention';

        return $root . DIRECTORY_SEPARATOR . 'sqlite';
    }

    private function state_path(): string
    {
        return $this->archive_directory . DIRECTORY_SEPARATOR . self::STATE_FILENAME;
    }

    private function persist_controller_deferral_receipt(): bool
    {
        $occurred_at = $this->current_datetime();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $receipt_id = bin2hex(random_bytes(16));
            } catch (Throwable $error) {
                $receipt_id = md5(uniqid('', true) . ':' . microtime(true));
            }
            $path = $this->archive_directory
                . DIRECTORY_SEPARATOR
                . self::CONTROLLER_DEFERRAL_RECEIPT_PREFIX
                . $receipt_id
                . '.json';
            if (is_file($path)) {
                continue;
            }
            $payload = [
                'schema_version' => self::CONTROLLER_DEFERRAL_RECEIPT_SCHEMA_VERSION,
                'receipt_id' => $receipt_id,
                'occurred_at' => $occurred_at->format(DATE_ATOM),
                'attempt_date' => $occurred_at->format('Y-m-d'),
                'check' => $occurred_at->format('N') === '7' ? 'integrity' : 'quick',
                'reason_code' => 'controller_lock_active',
            ];
            $json = json_encode($payload);
            if (!is_string($json)) {
                return false;
            }
            $temporary_path = $path . '.tmp';
            $written = @file_put_contents($temporary_path, $json . "\n", LOCK_EX);
            if ($written === false || $written !== strlen($json) + 1) {
                @unlink($temporary_path);

                return false;
            }
            if (@rename($temporary_path, $path)) {
                return true;
            }
            @unlink($temporary_path);
            if (!is_file($path)) {
                return false;
            }
        }

        return false;
    }

    private function reconcile_controller_deferral_receipts(array $state): array
    {
        $receipt_read = $this->read_controller_deferral_receipts();
        if (empty($receipt_read['success'])) {
            return [
                'success' => false,
                'reason_code' => (string) (
                    $receipt_read['reason_code'] ?? 'controller_deferral_receipt_invalid'
                ),
                'state' => $state,
                'incident_action' => '',
            ];
        }

        $receipts = (array) ($receipt_read['receipts'] ?? []);
        $stored_receipt_ids = (array) (
            $state['daily']['controller_deferral_receipts'] ?? []
        );
        $available_receipt_ids = array_column($receipts, 'receipt_id');
        $accounted_receipt_ids = array_values(array_intersect(
            $stored_receipt_ids,
            $available_receipt_ids
        ));
        $state_changed = $accounted_receipt_ids !== $stored_receipt_ids;
        $incident_action = '';
        $current_date = $this->current_datetime()->format('Y-m-d');

        foreach ($receipts as $receipt) {
            $receipt_id = (string) ($receipt['receipt_id'] ?? '');
            if (in_array($receipt_id, $accounted_receipt_ids, true)) {
                continue;
            }
            $receipt_date = (string) ($receipt['attempt_date'] ?? '');
            $stored_attempt_date = (string) (
                $state['daily']['attempt_date'] ?? $state['daily']['date'] ?? ''
            );
            if ($receipt_date <= $current_date
                && ($stored_attempt_date === '' || $receipt_date >= $stored_attempt_date)
            ) {
                [$state, $receipt_check] = $this->align_daily_state(
                    $state,
                    $receipt_date,
                    (string) ($receipt['check'] ?? '')
                );
                if ((string) ($state['daily']['status'] ?? '') !== 'completed'
                    && (int) ($state['daily']['attempts'] ?? 0) < self::DAILY_ATTEMPT_LIMIT
                ) {
                    $state['daily']['check'] = $receipt_check;
                    $state['daily']['attempts'] = (int) (
                        $state['daily']['attempts'] ?? 0
                    ) + 1;
                    $state['daily']['status'] = 'incomplete';
                    $state['daily']['result'] = 'deferred';
                    $state['daily']['reason_code'] = 'controller_lock_active';
                    $state['daily']['completed_at'] = '';
                    if ((int) $state['daily']['attempts'] >= self::DAILY_ATTEMPT_LIMIT) {
                        $incident_action = $this->record_incomplete_incident(
                            $this->normalize_archive_name(
                                (string) ($state['daily']['archive'] ?? '')
                            ),
                            $receipt_check,
                            'controller_lock_active',
                            $receipt_date
                        );
                        if ($incident_action === '') {
                            return [
                                'success' => false,
                                'reason_code' => 'incomplete_incident_persist_failed',
                                'state' => $state,
                                'incident_action' => '',
                            ];
                        }
                    }
                }
            }
            $accounted_receipt_ids[] = $receipt_id;
            $accounted_receipt_ids = array_values(array_unique($accounted_receipt_ids));
            $state['daily']['controller_deferral_receipts'] = $accounted_receipt_ids;
            $state_changed = true;
        }

        if ($state_changed) {
            $state['daily']['controller_deferral_receipts'] = $accounted_receipt_ids;
            if (!$this->write_state($state)) {
                return [
                    'success' => false,
                    'reason_code' => 'health_state_write_failed',
                    'state' => $state,
                    'incident_action' => $incident_action,
                ];
            }
        }

        foreach ($receipts as $receipt) {
            if (in_array(
                (string) ($receipt['receipt_id'] ?? ''),
                $accounted_receipt_ids,
                true
            )) {
                @unlink((string) ($receipt['path'] ?? ''));
            }
        }
        $remaining_receipt_ids = array_values(array_filter(
            $accounted_receipt_ids,
            function (string $receipt_id): bool {
                return is_file(
                    $this->archive_directory
                    . DIRECTORY_SEPARATOR
                    . self::CONTROLLER_DEFERRAL_RECEIPT_PREFIX
                    . $receipt_id
                    . '.json'
                );
            }
        ));
        if ($remaining_receipt_ids !== $accounted_receipt_ids) {
            $state['daily']['controller_deferral_receipts'] = $remaining_receipt_ids;
            if (!$this->write_state($state)) {
                return [
                    'success' => false,
                    'reason_code' => 'health_state_write_failed',
                    'state' => $state,
                    'incident_action' => $incident_action,
                ];
            }
        }

        return [
            'success' => true,
            'reason_code' => '',
            'state' => $state,
            'incident_action' => $incident_action,
        ];
    }

    private function read_controller_deferral_receipts(): array
    {
        $paths = @glob(
            $this->archive_directory
            . DIRECTORY_SEPARATOR
            . self::CONTROLLER_DEFERRAL_RECEIPT_PREFIX
            . '*.json'
        );
        if ($paths === false || count($paths) > self::CONTROLLER_DEFERRAL_RECEIPT_LIMIT) {
            return [
                'success' => false,
                'reason_code' => 'controller_deferral_receipt_invalid',
                'receipts' => [],
            ];
        }

        $receipts = [];
        foreach ($paths as $path) {
            $filename = basename($path);
            if (preg_match(
                '/^' . self::CONTROLLER_DEFERRAL_RECEIPT_PREFIX
                . '([a-f0-9]{32})\.json$/',
                $filename,
                $matches
            ) !== 1 || !is_file($path) || is_link($path)) {
                return [
                    'success' => false,
                    'reason_code' => 'controller_deferral_receipt_invalid',
                    'receipts' => [],
                ];
            }
            $raw = @file_get_contents($path);
            $payload = is_string($raw) && strlen($raw) <= 2048
                ? json_decode($raw, true)
                : null;
            $receipt_id = (string) ($matches[1] ?? '');
            $occurred_at = is_array($payload)
                ? (string) ($payload['occurred_at'] ?? '')
                : '';
            try {
                $timestamp = new DateTimeImmutable($occurred_at);
            } catch (Throwable $error) {
                $timestamp = null;
            }
            $attempt_date = is_array($payload)
                ? (string) ($payload['attempt_date'] ?? '')
                : '';
            $check = is_array($payload)
                ? $this->normalize_check((string) ($payload['check'] ?? ''))
                : '';
            if (!is_array($payload)
                || (int) ($payload['schema_version'] ?? 0)
                    !== self::CONTROLLER_DEFERRAL_RECEIPT_SCHEMA_VERSION
                || (string) ($payload['receipt_id'] ?? '') !== $receipt_id
                || !$timestamp instanceof DateTimeImmutable
                || $timestamp->format(DATE_ATOM) !== $occurred_at
                || $timestamp->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d')
                    !== $attempt_date
                || $check === ''
                || (string) ($payload['reason_code'] ?? '') !== 'controller_lock_active'
            ) {
                return [
                    'success' => false,
                    'reason_code' => 'controller_deferral_receipt_invalid',
                    'receipts' => [],
                ];
            }
            $receipts[] = [
                'receipt_id' => $receipt_id,
                'attempt_date' => $attempt_date,
                'check' => $check,
                'occurred_at' => $occurred_at,
                'path' => $path,
            ];
        }
        usort($receipts, static function (array $left, array $right): int {
            $time_compare = strcmp(
                (string) ($left['occurred_at'] ?? ''),
                (string) ($right['occurred_at'] ?? '')
            );

            return $time_compare !== 0
                ? $time_compare
                : strcmp(
                    (string) ($left['receipt_id'] ?? ''),
                    (string) ($right['receipt_id'] ?? '')
                );
        });

        return [
            'success' => true,
            'reason_code' => '',
            'receipts' => $receipts,
        ];
    }

    private function default_state(): array
    {
        return [
            'schema_version' => self::STATE_SCHEMA_VERSION,
            'daily' => [
                'date' => '',
                'attempt_date' => '',
                'archive' => '',
                'check' => '',
                'attempts' => 0,
                'status' => 'pending',
                'result' => '',
                'reason_code' => '',
                'completed_at' => '',
                'controller_deferral_receipts' => [],
            ],
            'annual' => [
                'cycle_year' => '',
                'snapshot' => [],
                'completed' => [],
                'results' => [],
                'status' => 'pending',
            ],
        ];
    }

    private function normalize_archive_name(string $archive_name): string
    {
        $archive_name = trim($archive_name);

        return preg_match(
            '/^kiwi_retention_archive_[0-9]{4}(?:_part_(?:[2-9]|[1-9][0-9]+))?\.sqlite$/',
            $archive_name
        ) === 1 ? $archive_name : '';
    }

    private function normalize_check(string $check): string
    {
        $check = strtolower(trim($check));

        return in_array($check, ['quick', 'integrity'], true) ? $check : '';
    }

    private function normalize_reason(string $reason_code): string
    {
        $reason_code = strtolower(trim($reason_code));
        $reason_code = preg_replace('/[^a-z0-9_]+/', '_', $reason_code);
        $reason_code = is_string($reason_code) ? trim($reason_code, '_') : '';

        return in_array($reason_code, self::ALLOWED_REASONS, true)
            || in_array(
                $reason_code,
                [
                    'archive_directory_unavailable',
                    'archive_lock_open_failed',
                    'archive_lock_active',
                    'health_state_invalid',
                    'health_state_write_failed',
                    'incomplete_incident_persist_failed',
                ],
                true
            )
                ? $reason_code
                : 'health_bootstrap_failed';
    }

    private function is_valid_date(string $date): bool
    {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date) !== 1) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            new DateTimeZone('Europe/Berlin')
        );

        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }

    private function is_valid_timestamp(string $timestamp): bool
    {
        if ($timestamp === '') {
            return false;
        }
        try {
            $parsed = new DateTimeImmutable($timestamp);

            return $parsed->format(DATE_ATOM) === $timestamp;
        } catch (Throwable $error) {
            return false;
        }
    }

    private function current_datetime(): DateTimeImmutable
    {
        $value = call_user_func($this->clock);

        return $value instanceof DateTimeImmutable
            ? $value->setTimezone(new DateTimeZone('Europe/Berlin'))
            : new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    }

    private function now(): string
    {
        return $this->current_datetime()->format(DATE_ATOM);
    }
}
