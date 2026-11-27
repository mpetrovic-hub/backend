<?php

if (!defined('ABSPATH')) { exit; }

/** Issue and resolve operational incidents from already persisted SMS group findings. */
final class Kiwi_Retention_Sms_Group_Incidents
{
    private const CORRELATION_PREFIX = 'retention_sms_group_';
    private $events;
    private $runs;
    private $guard;

    public function __construct(Kiwi_Operational_Event_Service $events, Kiwi_Retention_Cleanup_Run_Repository $runs,
        Kiwi_Database_Lock_Guard $guard)
    {
        $this->events = $events;
        $this->runs = $runs;
        $this->guard = $guard;
    }

    public function report(string $group_id, array $group, string $run_id, ?int $open_id = null): void
    {
        $finding = $group['after'];
        if (!is_array($finding['reasons'] ?? null) || !in_array($finding['status'] ?? '', ['passed','incomplete','outside_tolerance'], true)) {
            return;
        }
        $counters = $this->counter_details($finding);
        $event = [
            'area' => 'retention',
            'severity' => 'error',
            'event_type' => 'retention_cleanup_group_blocked',
            'correlation_key' => self::CORRELATION_PREFIX . $group_id,
            'reference_type' => 'retention_cleanup_run',
            'reference_id' => $run_id,
            'message' => $this->describe($group['key'], $finding, $counters),
            'context' => [
                'group' => $group['key'],
                'run_id' => $run_id,
                'reason_code' => $finding['reasons'][0]['code'] ?? $finding['status'],
                'reason_count' => count($finding['reasons']),
                'reasons' => array_slice($finding['reasons'], 0, 12),
                'counters' => $counters,
            ],
        ];
        if ($finding['status'] === 'passed') {
            $passed = array_fill(0, count(Kiwi_Retention_Sms_Statistics::MARKERS), 'passed');
            if ($finding['reasons'] !== [] || array_column($counters, 'status') !== $passed) { return; }
            if ($open_id === null) {
                $open = $this->events->get_latest_event_for_correlation($event['correlation_key']);
                if (!in_array($open['lifecycle_action'] ?? null, ['raised', 'repeated'], true)) { return; }
                $open_id = (int) $open['id'];
            }
            $this->events->record_recovery_action_if($event,
                static fn($latest): bool => ($latest['event_type'] ?? null) === $event['event_type']
                    && (int) $latest['id'] === $open_id, $this->guard);
            return;
        }
        $event['idempotency_key'] = 'sms_group_' . hash('sha256', $run_id . ':' . $group_id . ':' . count($group['reviews'] ?? []));
        $this->events->record_failure_action_if($event, static function ($latest) use ($run_id): bool {
            return ($latest['reference_id'] ?? null) !== $run_id || ($latest['lifecycle_action'] ?? null) === 'resolved';
        }, $this->guard);
    }

    /** A passed group may have no candidates left when its earlier recovery write becomes possible. */
    public function retry_open(array $run, array $state, int $cursor, int $limit, float $deadline): ?int
    {
        $open = $this->events->get_open_incidents([
            'event_type' => 'retention_cleanup_group_blocked', 'area' => 'retention',
            'reference_type' => 'retention_cleanup_run', 'after_id' => $cursor,
        ], $limit);
        foreach ($open ?? [] as $index => $event) {
            if ($index > 0 && microtime(true) >= $deadline) { return $cursor; }
            $cursor = (int) $event['id'];
            if (!preg_match('/^' . self::CORRELATION_PREFIX . '([a-f0-9]{64})$/D', $event['correlation_key'], $match)) { continue; }
            $id = $match[1];
            if (isset($state['groups'][$id])) { continue; }
            try {
                $decision = $this->last_assessment($event['reference_id'], (int) $run['id'], $id);
                if ($decision === null || $decision['group_schema'] !== $state['group_schema']) { continue; }
                $key = json_decode($decision['group_key'], true, 512, JSON_THROW_ON_ERROR);
                $after = json_decode($decision['group_finding'], true, 512, JSON_THROW_ON_ERROR);
                if (($after['status'] ?? '') === 'passed' && Kiwi_Retention_Sms_Statistics::group_id($key) === $id) {
                    $this->report($id, ['key' => $key, 'after' => $after], $decision['run_id'], (int) $event['id']);
                }
            } catch (Throwable $error) {
                // Leave the incident open. A later real run can repeat this read-only evidence lookup.
            }
        }
        return $open !== null && count($open) === $limit ? $cursor : null;
    }

    /** Read the newest relevant decision, including incomplete/failed runs which invalidate an older pass. */
    private function last_assessment(string $from_run, int $before_id, string $group_id): ?array
    {
        global $wpdb;
        $table = $this->runs->get_table_name();
        $json = "IF(JSON_VALID(r.gate_results_json),r.gate_results_json,'{}')";
        $path = '$.groups."' . $group_id . '"';
        $row = $wpdb->get_row($wpdb->prepare("SELECT r.run_id, r.status, r.finished_at, r.worker_phase,
                JSON_UNQUOTE(JSON_EXTRACT({$json},'$.group_schema')) AS group_schema,
                JSON_EXTRACT({$json},%s) AS group_key, JSON_EXTRACT({$json},%s) AS group_finding
            FROM {$table} r JOIN {$table} first_block ON first_block.run_id=%s AND first_block.source_key=r.source_key
            WHERE r.source_key=%s AND r.enabled=1 AND r.dry_run=0 AND r.id>=first_block.id AND r.id<%d
                AND (JSON_EXTRACT({$json},'$.groups') IS NULL OR JSON_CONTAINS_PATH({$json},'one',%s))
            ORDER BY r.id DESC LIMIT 1",
            $path . '.key', $path . '.after', $from_run, Kiwi_Retention_Source_Registry::SOURCE_SMS_BODY_VARIANT_ASSIGNMENTS,
            $before_id, $path), ARRAY_A);
        if ((string) ($wpdb->last_error ?? '') !== '' || !$row || $row['status'] !== 'completed'
            || $row['worker_phase'] !== 'completed' || empty($row['finished_at'])) { return null; }
        return $row;
    }

    /** Keep even large sets of possible sale totals inside the shared event context limit. */
    private function counter_details(array $finding): array
    {
        $counters = [];
        foreach (array_keys(Kiwi_Retention_Sms_Statistics::MARKERS) as $name) {
            $measure = $finding['counters'][$name];
            $detail = array_intersect_key($measure, array_flip(['expected', 'actual', 'distance', 'limit', 'status']));
            if (!empty($measure['possible_expected'])) {
                $possible = $measure['possible_expected'];
                $detail['possible_expected_range'] = [reset($possible), end($possible)];
                foreach ($measure['comparisons'] as $comparison) {
                    if ($comparison['status'] === 'outside_tolerance') {
                        $detail['failing_comparison'] = $comparison;
                        break;
                    }
                }
            }
            $counters[$name] = $detail;
        }
        return $counters;
    }

    private function describe(array $group, array $finding, array $counters): string
    {
        $name = implode(' / ', array_map(static fn($value) => $value === '' ? '(leer)' : $value,
            Kiwi_Retention_Sms_Statistics::group_key($group)));
        if ($finding['status'] === 'passed') { return "SMS-Gruppe {$name} hat die Statistikprüfung bestanden."; }
        $prefix = "SMS-Gruppe {$name} gesperrt. ";
        if ($finding['status'] === 'incomplete') {
            $reason = $finding['reasons'][0] ?? [];
            switch ($reason['code'] ?? '') {
                case 'summary_row_missing': return $prefix . 'Die Statistikzeile dieser Gruppe fehlt.';
                case 'live_statistics_read_failed': return $prefix . 'Die aktuellen Originaldaten oder Zähler konnten nicht gelesen werden.';
                case 'confirmed_sales_read_failed': return $prefix . 'Die bestätigten Sales konnten nicht geprüft werden.';
                case 'archive_inventory_changed': return $prefix . 'Der Bestand der Archivdateien hat sich während der Prüfung geändert.';
            }
            $subject = isset($reason['counter']) ? ' für ' . $reason['counter'] : '';
            $id = $reason['example_ids'][0] ?? null;
            return $prefix . 'Ein erforderlicher Nachweis' . $subject . ' fehlt oder ist widersprüchlich'
                . ($id === null ? '.' : ' (Beispiel-ID ' . $id . ').');
        }
        foreach ($counters as $counter => $detail) {
            if ($detail['status'] !== 'outside_tolerance') { continue; }
            $comparison = $detail['failing_comparison'] ?? $detail;
            $expectation = isset($detail['failing_comparison']) ? 'möglicher Sollwert' : 'Sollwert';
            return $prefix . sprintf('%s steht bei %s, %s %s, Abstand %s, erlaubt %s.',
                $counter, $detail['actual'], $expectation, $comparison['expected'], $comparison['distance'], $comparison['limit']);
        }
        return $prefix . 'Die gemessenen Zähler überschreiten die Toleranz.';
    }
}
