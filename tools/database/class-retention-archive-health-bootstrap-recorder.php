<?php

final class Kiwi_Retention_Archive_Health_Bootstrap_Recorder
{
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static function (): DateTimeImmutable {
            return new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
        };
    }

    public function record(string $reason_code, string $command = 'check'): array
    {
        $now = call_user_func($this->clock);
        $timestamp = $now instanceof DateTimeInterface
            ? $now->format(DATE_ATOM)
            : (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format(DATE_ATOM);
        $reason_code = strtolower(trim($reason_code));
        if (preg_match('/^[a-z0-9_]{1,100}$/', $reason_code) !== 1) {
            $reason_code = 'health_bootstrap_failed';
        }
        if (!in_array($command, ['check', 'diagnose', 'unblock'], true)) {
            $command = 'check';
        }

        return [
            'schema_version' => 1,
            'command' => $command,
            'result' => 'error',
            'reason_code' => $reason_code,
            'archive' => null,
            'check' => null,
            'started_at' => $timestamp,
            'finished_at' => $timestamp,
            'duration_seconds' => 0.0,
            '_exit_code' => 2,
        ];
    }
}
