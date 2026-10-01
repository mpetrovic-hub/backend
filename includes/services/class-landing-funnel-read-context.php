<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Explicit SQL read sources. Defaults continue to use the ordinary live tables. */
final class Kiwi_Landing_Funnel_Read_Context
{
    private $tables;
    private $audit;
    private $deep_dates;

    public function __construct(array $tables = [], array $audit = [], array $deep_dates = [])
    {
        foreach ($tables as $key => $table) {
            if (!in_array($key, ['handoffs', 'main_summary', 'tkzone_summary'], true)
                || !is_string($table) || preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1
            ) {
                throw new InvalidArgumentException('Invalid landing funnel read table.');
            }
        }
        foreach ($deep_dates as $date) {
            $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Invalid explicit deep-compare date.');
            }
        }
        $this->tables = $tables;
        $this->audit = $audit;
        $this->deep_dates = array_values(array_unique($deep_dates));
    }

    public function table(string $key, string $default): string
    {
        return $this->tables[$key] ?? $default;
    }

    public function deep_dates(): array { return $this->deep_dates; }
    public function audit(): array { return $this->audit; }
}
