<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Resolve a persisted confirmed sale using its own NTH flow and original inbound SMS. */
class Kiwi_Nth_Sms_Sale_Evidence
{
    private $flows;
    private $events;
    private $assignments;

    public function __construct(
        Kiwi_Nth_Flow_Transaction_Repository $flows,
        Kiwi_Nth_Event_Repository $events,
        Kiwi_Sms_Body_Variant_Service $assignments
    ) {
        $this->flows = $flows;
        $this->events = $events;
        $this->assignments = $assignments;
    }

    /** Optional current MySQL read inside the new sale's transaction, without archive I/O. */
    public function observe_insert(array $flow): array
    {
        try {
            $meta = $this->decode($flow['meta_json'] ?? null);
            $mo = $this->decode($meta['initial_event'] ?? null);
            if (!$mo && (int) ($flow['mo_event_id'] ?? 0) > 0) {
                $event = $this->events->get_for_sale_evidence((int) $flow['mo_event_id']);
                $mo = $this->decode($event['normalized_payload'] ?? $event);
            }
            $code = $this->mo_code($mo, $flow);
            return $this->assignments->observe_sale_insert($code);
        } catch (Throwable $error) {
            // A failed observation leaves the completed sale eligible for commit.
        }
        return [];
    }

    public function resolve(array $sale): array
    {
        if (($sale['provider_key'] ?? '') !== 'nth' || ($sale['sale_type'] ?? '') !== 'premium_sms_one_off'
            || ($sale['status'] ?? '') !== 'completed' || (int) ($sale['id'] ?? 0) < 1
            || empty($sale['sale_reference']) || empty($sale['service_key'])) {
            return ['status' => 'not_found'];
        }
        try {
            $flows = $this->flows->find_for_sale_evidence($sale['sale_reference']);
            if (count($flows) > 1) {
                return ['status' => 'ambiguous'];
            }
            $flow = $flows[0] ?? null;
            $context = $this->decode($sale['context_json'] ?? null);
            $saved = $this->decode($context['transaction'] ?? null);
            $report = $this->decode($context['report_event'] ?? null);
            $snapshot_code = [];
            if ($this->matches_flow($saved, $sale) && ($report['event_type'] ?? '') === 'notification_callback'
                && ($report['provider'] ?? '') === 'nth' && ($report['direction'] ?? '') === 'inbound'
                && ($report['service_key'] ?? '') === $sale['service_key']
                && (empty($sale['external_sale_id']) || ($report['external_message_id'] ?? '') === $sale['external_sale_id'])
                && in_array($report['is_terminal'] ?? false, [true, 1, '1'], true)
                && in_array($report['is_success'] ?? false, [true, 1, '1'], true)) {
                $meta = $this->decode($saved['meta_json'] ?? null);
                $snapshot_code = $this->mo_code($this->decode($meta['initial_event'] ?? null), $saved);
            }
            $live_code = [];
            if ($flow !== null) {
                if (!$this->matches_flow($flow, $sale)
                    || ($snapshot_code && ((int) $flow['id'] !== (int) $saved['id']
                        || (int) $flow['mo_event_id'] !== (int) $saved['mo_event_id']))) {
                    return ['status' => 'ambiguous'];
                }
                if ((int) $flow['sale_id'] === (int) $sale['id']) {
                    $event = $this->events->get_for_sale_evidence((int) $flow['mo_event_id']);
                    if ($event !== null) {
                        $mo = $this->decode($event['normalized_payload'] ?? $event);
                        if (($event['event_type'] ?? '') !== 'mo_callback' || ($event['direction'] ?? '') !== 'inbound'
                            || ($event['service_key'] ?? '') !== $flow['service_key']
                            || (!empty($mo['dedupe_key']) && $mo['dedupe_key'] !== ($event['dedupe_key'] ?? ''))) {
                            return ['status' => 'ambiguous'];
                        }
                        $live_code = $this->mo_code($mo, $flow);
                        if (!$live_code && $snapshot_code && isset($mo['message_text'])) {
                            return ['status' => 'ambiguous'];
                        }
                    }
                }
            }
            if ($live_code && $snapshot_code && $live_code !== $snapshot_code) {
                return ['status' => 'ambiguous'];
            }
            $code = $live_code ?: $snapshot_code;
            if (!$code) {
                return ['status' => 'not_found'];
            }
            $result = isset($code['transaction_id'])
                ? $this->assignments->resolve_assignment_from_transaction_id($code['transaction_id'])
                : $this->assignments->resolve_assignment_from_visible_token($code['visible_token'] ?? '');
            if ($result['status'] === 'found' && $result['assignment']['service_key'] !== $sale['service_key']) {
                return ['status' => 'not_found'];
            }
            if ($result['status'] === 'found') {
                $result['original_code'] = $code;
            }
            return $result;
        } catch (Throwable $error) {
            return ['status' => 'read_error'];
        }
    }

    private function matches_flow(array $flow, array $sale): bool
    {
        return (int) ($flow['id'] ?? 0) > 0 && (int) ($flow['mo_event_id'] ?? 0) > 0
            && ($flow['service_key'] ?? '') === $sale['service_key']
            && ($flow['sale_reference'] ?? '') === $sale['sale_reference']
            && !empty($flow['flow_reference'])
            && (empty($sale['external_transaction_id']) || $flow['flow_reference'] === $sale['external_transaction_id'])
            && in_array((int) ($flow['sale_id'] ?? 0), [0, (int) $sale['id']], true);
    }

    private function mo_code(array $mo, array $flow): array
    {
        if (($mo['provider'] ?? '') !== 'nth' || ($mo['event_type'] ?? '') !== 'mo_callback'
            || ($mo['direction'] ?? '') !== 'inbound' || ($mo['service_key'] ?? '') !== $flow['service_key']
            || empty($mo['dedupe_key'])) {
            return [];
        }
        return Kiwi_Nth_Premium_Sms_Normalizer::sms_assignment_code(
            (string) ($mo['message_text'] ?? ''), (string) ($flow['keyword'] ?? ''), true
        );
    }

    private function decode($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }
}
