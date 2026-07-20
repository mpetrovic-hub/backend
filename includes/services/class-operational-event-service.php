<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Operational_Event_Service
{
    private const SEVERITIES = ['info', 'warning', 'error', 'critical'];
    private const LIFECYCLE_ACTIONS = ['raised', 'repeated', 'resolved'];
    private const SENSITIVE_KEYS = [
        'authorization',
        'api_key',
        'access_token',
        'token',
        'client_secret',
        'secret',
        'password',
        'passwd',
    ];

    private $repository;

    public function __construct(?Kiwi_Operational_Event_Repository $repository = null)
    {
        $this->repository = $repository instanceof Kiwi_Operational_Event_Repository
            ? $repository
            : new Kiwi_Operational_Event_Repository();
    }

    public function record_failure(array $event): bool
    {
        $correlation_key = $this->normalize_key((string) ($event['correlation_key'] ?? ''), 191);
        if ($correlation_key === '') {
            return false;
        }

        try {
            $latest = $this->repository->find_latest_by_correlation_key($correlation_key);
            $event['lifecycle_action'] = is_array($latest)
                && in_array((string) ($latest['lifecycle_action'] ?? ''), ['raised', 'repeated'], true)
                    ? 'repeated'
                    : 'raised';

            return $this->persist($event, $correlation_key);
        } catch (Throwable $error) {
            return false;
        }
    }

    public function record_recovery(array $event): bool
    {
        $correlation_key = $this->normalize_key((string) ($event['correlation_key'] ?? ''), 191);
        if ($correlation_key === '') {
            return false;
        }

        try {
            $latest = $this->repository->find_latest_by_correlation_key($correlation_key);
            if (!is_array($latest)
                || !in_array((string) ($latest['lifecycle_action'] ?? ''), ['raised', 'repeated'], true)
            ) {
                return true;
            }

            $event['lifecycle_action'] = 'resolved';
            $event['idempotency_key'] = 'operational_recovery_' . hash(
                'sha256',
                $correlation_key . ':' . (string) ($latest['id'] ?? '')
            );

            return $this->persist($event, $correlation_key);
        } catch (Throwable $error) {
            return false;
        }
    }

    public function record(array $event): bool
    {
        $correlation_key = $this->normalize_key((string) ($event['correlation_key'] ?? ''), 191);

        try {
            return $this->persist($event, $correlation_key);
        } catch (Throwable $error) {
            return false;
        }
    }

    private function persist(array $event, string $correlation_key): bool
    {
        $area = $this->normalize_key((string) ($event['area'] ?? ''), 64);
        $event_type = $this->normalize_key((string) ($event['event_type'] ?? ''), 100);
        $severity = strtolower(trim((string) ($event['severity'] ?? 'error')));
        $lifecycle_action = strtolower(trim((string) ($event['lifecycle_action'] ?? 'raised')));
        $message = $this->truncate(
            $this->mask_credentials(trim((string) ($event['message'] ?? ''))),
            500
        );

        if ($area === '' || $event_type === '' || $correlation_key === '' || $message === ''
            || !in_array($severity, self::SEVERITIES, true)
            || !in_array($lifecycle_action, self::LIFECYCLE_ACTIONS, true)
        ) {
            return false;
        }

        $context = isset($event['context']) && is_array($event['context'])
            ? $this->redact_value($event['context'])
            : [];
        $context_json = empty($context) ? null : $this->encode_context($context);
        $raw_error_text = array_key_exists('raw_error_text', $event)
            ? $this->sanitize_raw_error((string) $event['raw_error_text'])
            : null;
        $now = $this->current_time_mysql();

        $row = [
            'occurred_at' => $this->normalize_datetime((string) ($event['occurred_at'] ?? '')) ?: $now,
            'created_at' => $now,
            'area' => $area,
            'severity' => $severity,
            'event_type' => $event_type,
            'lifecycle_action' => $lifecycle_action,
            'idempotency_key' => $this->normalize_optional_key((string) ($event['idempotency_key'] ?? ''), 191),
            'correlation_key' => $correlation_key,
            'reference_type' => $this->normalize_key((string) ($event['reference_type'] ?? ''), 64),
            'reference_id' => $this->truncate(trim((string) ($event['reference_id'] ?? '')), 191),
            'message' => $message,
            'raw_error_text' => $raw_error_text === '' ? null : $raw_error_text,
            'context_json' => $context_json,
        ];

        return $this->repository->insert_event($row) > 0;
    }

    private function redact_value($value, string $key = '')
    {
        if ($this->is_sensitive_key($key)) {
            return '[redacted]';
        }

        if (is_array($value)) {
            $copy = [];
            foreach ($value as $child_key => $child_value) {
                $copy[$child_key] = $this->redact_value($child_value, (string) $child_key);
            }

            return $copy;
        }

        if (is_object($value)) {
            return $this->redact_value((array) $value, $key);
        }

        if (is_string($value)) {
            return $this->mask_credentials($value);
        }

        return $value;
    }

    private function sanitize_raw_error(string $text): string
    {
        return $this->truncate($this->mask_credentials($text), 4000);
    }

    private function mask_credentials(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $sensitive = '(?:(?:[a-z0-9]+[_-])*(?:authorizations?|auths?|authentications?|oauths?|bearers?|api[_-]?keys?|access[_-]?tokens?|client[_-]?secrets?|passwords?|passwds?|secrets?|tokens?|credentials?|digests?|signatures?|hmacs?|nonces?|otps?|pins?|verification[_-]?codes?|private[_-]?keys?|key[_-]?materials?|signing[_-]?keys?|encryption[_-]?keys?|secret[_-]?keys?|cookies?|set[_-]?cookies?|session[_-]?cookies?|session[_-]?ids?|sessionids?|phpsessids?|logged[_-]?in)(?:[_-][a-z0-9]+)*)';
        $masked = preg_replace('/\b(cookie|set-cookie)\s*:[^\r\n]*/i', '$1: [redacted]', $text);
        $masked = is_string($masked) ? $masked : '[credential content removed]';
        $masked = preg_replace('/\b(authorization|proxy-authorization)\s*:[^\r\n]*/i', '$1: [redacted]', $masked);
        $masked = is_string($masked) ? $masked : '[credential content removed]';
        $masked = preg_replace(
            '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?-----END [A-Z0-9 ]*PRIVATE KEY-----/is',
            '[redacted]',
            $masked
        );
        $masked = is_string($masked) ? $masked : '[credential content removed]';
        $masked = preg_replace(
            '/(' . $sensitive . '\s*["\']?\s*[:=]\s*)"(?:\\\\.|[^"\\\\])*"/i',
            '$1[redacted]',
            $masked
        );
        $masked = is_string($masked) ? $masked : '[credential content removed]';
        $masked = preg_replace(
            "/(" . $sensitive . "\\s*[\"']?\\s*[:=]\\s*)'(?:\\\\\\\\.|[^'\\\\\\\\])*'/i",
            '$1[redacted]',
            $masked
        );
        $masked = is_string($masked) ? $masked : '[credential content removed]';
        if (preg_match('/' . $sensitive . '\s*["\']?\s*[:=]\s*["\']/i', $masked) === 1) {
            return '[credential content removed]';
        }
        $masked = preg_replace(
            '/(' . $sensitive . '\s*["\']?\s*[:=]\s*)(?!["\'])(.*?)(?=\s+[a-z][a-z0-9_.-]*\s*[:=]|[,;\r\n]|$)/i',
            '$1[redacted]',
            $masked
        );
        $masked = is_string($masked) ? $masked : '[credential content removed]';

        if (preg_match('/' . $sensitive . '/i', $masked) === 1
            && preg_match('/' . $sensitive . '\s*["\']?\s*[:=]/i', $masked) !== 1
        ) {
            $masked = '[credential content removed]';
        }

        return $masked;
    }

    private function encode_context(array $context): string
    {
        $json = function_exists('wp_json_encode') ? wp_json_encode($context) : json_encode($context);
        $json = is_string($json) ? $json : '{}';

        if (strlen($json) > 16384) {
            return '{"context_omitted":"size_limit_exceeded"}';
        }

        return $json;
    }

    private function is_sensitive_key(string $key): bool
    {
        $key = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', trim($key));
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', (string) $key) ?? '');

        return in_array($key, self::SENSITIVE_KEYS, true)
            || preg_match(
                '/(?:^|_)(?:authorizations?|auths?|authentications?|oauths?|bearers?|api_keys?|access_tokens?|tokens?|client_secrets?|secrets?|passwords?|passwds?|credentials?|digests?|signatures?|hmacs?|nonces?|otps?|pins?|verification_codes?|private_keys?|key_materials?|signing_keys?|encryption_keys?|secret_keys?|cookies?|set_cookies?|session_cookies?|session_ids?|sessionids?|phpsessids?|logged_in)(?:_|$)/',
                $key
            ) === 1;
    }

    private function normalize_key(string $value, int $limit): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value);
        $value = is_string($value) ? trim($value, '_') : '';

        return $this->truncate($value, $limit);
    }

    private function normalize_optional_key(string $value, int $limit): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $this->truncate($value, $limit);
    }

    private function normalize_datetime(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1 ? $value : '';
    }

    private function truncate(string $value, int $limit): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }

    private function current_time_mysql(): string
    {
        return function_exists('current_time') ? (string) current_time('mysql') : gmdate('Y-m-d H:i:s');
    }
}
