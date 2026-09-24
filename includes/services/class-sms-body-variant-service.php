<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Sms_Body_Variant_Service
{
    private $config;
    private $repository;

    public function __construct(Kiwi_Config $config, Kiwi_Sms_Body_Variant_Repository $repository)
    {
        $this->config = $config;
        $this->repository = $repository;
    }

    public function build_variant_body(
        string $keyword,
        string $shortcode,
        array $landing_page,
        array $service,
        ?array $attribution,
        ?array $allocation = null
    ): ?array {
        $transaction_id = $this->sanitize_token((string) (($attribution['transaction_id'] ?? '')), 120);

        if ($transaction_id === '' || !$this->is_enabled_for_landing($landing_page, $service)) {
            return null;
        }

        $keyword = $this->normalize_keyword_seed($keyword);
        $shortcode = $this->sanitize_token($shortcode, 50);

        if ($keyword === '' || $shortcode === '') {
            return null;
        }

        $existing = $this->repository->find_by_transaction_id($transaction_id);

        if (is_array($existing)) {
            $body = trim((string) ($existing['sms_body'] ?? ''));

            if ($body !== '') {
                return [
                    'body' => $body,
                    'assignment' => $existing,
                ];
            }
        }

        $entry = $this->resolve_allocation($transaction_id, $allocation);

        if ($entry === null) {
            return null;
        }

        $variant_key = $entry['variant_key'];
        $seed = $entry['seed'];
        $visible_token = $this->build_visible_token($transaction_id, $variant_key, $seed);
        $body = $keyword . ' ' . $visible_token;
        $result = $this->repository->insert_if_new([
            'landing_key' => (string) ($landing_page['key'] ?? ''),
            'service_key' => (string) ($landing_page['service_key'] ?? ($service['service_key'] ?? '')),
            'provider_key' => (string) ($landing_page['provider'] ?? ($service['provider'] ?? '')),
            'flow_key' => (string) ($landing_page['flow'] ?? ($service['flow'] ?? '')),
            'country' => (string) ($landing_page['country'] ?? ($service['country'] ?? '')),
            'keyword' => $keyword,
            'shortcode' => $shortcode,
            'pid' => (string) ($attribution['pid'] ?? ''),
            'click_id' => (string) ($attribution['click_id'] ?? ''),
            'session_token' => (string) ($attribution['session_ref'] ?? ''),
            'transaction_id' => $transaction_id,
            'visible_token' => $visible_token,
            'variant_key' => $variant_key,
            'seed' => $seed,
            'allocation_version' => $entry['allocation_version'],
            'sms_body' => $body,
            'raw_context' => [
                'source' => 'primary_cta',
            ],
        ]);

        if (!is_array($result['row'] ?? null)) {
            return null;
        }

        return [
            'body' => trim((string) ($result['row']['sms_body'] ?? $body)),
            'assignment' => $result['row'],
        ];
    }

    public function resolve_transaction_id_from_visible_token(string $visible_token): string
    {
        $visible_token = $this->sanitize_token($visible_token, 140);

        if ($visible_token === '') {
            return '';
        }

        $assignment = $this->repository->find_by_visible_token($visible_token);

        if (!is_array($assignment)) {
            return '';
        }

        return $this->sanitize_token((string) ($assignment['transaction_id'] ?? ''), 120);
    }

    /** Select a complete entry using the supplied generation as the stable hash salt. */
    private function resolve_allocation(string $transaction_id, ?array $allocation): ?array
    {
        $version = $allocation['allocation_version'] ?? null;
        $entries = $allocation['entries'] ?? null;

        if (!is_string($version) || $version === '' || $this->sanitize_token($version, 50) !== $version
            || !is_array($entries) || $entries === []) {
            return null;
        }

        $total = 0;
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_int($entry['weight'] ?? null) || $entry['weight'] <= 0
                || $entry['weight'] > 100
                || !in_array($entry['variant_key'] ?? '', ['as_is_txn_prefix', 'bare_id', 'game_word', 'cta_phrase', 'download_phrase'], true)
                || !is_string($entry['seed'] ?? null) || $this->sanitize_token($entry['seed'], 50) !== $entry['seed']) {
                return null;
            }
            $plain = in_array($entry['variant_key'], ['as_is_txn_prefix', 'bare_id'], true);
            if ($plain !== ($entry['seed'] === '')) {
                return null;
            }
            $total += $entry['weight'];
        }
        if ($total !== 100) {
            return null;
        }

        $bucket = $this->stable_index($transaction_id, 100, $version);
        foreach ($entries as $entry) {
            if ($bucket < $entry['weight']) {
                return ['variant_key' => $entry['variant_key'], 'seed' => $entry['seed'], 'allocation_version' => $version];
            }
            $bucket -= $entry['weight'];
        }

        return null;
    }

    public function build_visible_token(string $transaction_id, string $variant_key, string $seed = ''): string
    {
        $transaction_id = $this->sanitize_token($transaction_id, 120);
        $variant_key = trim($variant_key);
        $bare_id = $this->bare_transaction_id($transaction_id);

        if ($variant_key === 'as_is_txn_prefix') {
            return $transaction_id;
        }

        if ($variant_key === 'bare_id') {
            return $bare_id;
        }

        if (in_array($variant_key, ['game_word', 'cta_phrase', 'download_phrase'], true)) {
            $seed = $this->sanitize_token($seed, 50);

            return $seed . $bare_id;
        }

        return $transaction_id;
    }

    private function is_enabled_for_landing(array $landing_page, array $service): bool
    {
        if (!$this->config->is_sms_body_variant_experiment_enabled()) {
            return false;
        }

        $country = strtoupper(trim((string) ($landing_page['country'] ?? ($service['country'] ?? ''))));

        if ($country === '') {
            return false;
        }

        return in_array($country, $this->config->get_sms_body_variant_experiment_countries(), true);
    }

    private function stable_index(string $transaction_id, int $bucket_count, string $salt): int
    {
        if ($bucket_count <= 1) {
            return 0;
        }

        $hash = hash('sha256', $salt . '|' . $transaction_id);
        $slice = substr($hash, 0, 8);
        $number = hexdec($slice);

        return (int) ($number % $bucket_count);
    }

    private function bare_transaction_id(string $transaction_id): string
    {
        $transaction_id = $this->sanitize_token($transaction_id, 120);

        if (stripos($transaction_id, 'txn_') === 0) {
            return substr($transaction_id, 4);
        }

        return $transaction_id;
    }

    private function normalize_keyword_seed(string $keyword): string
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $keyword);
        $keyword = is_array($parts) && !empty($parts)
            ? (string) $parts[0]
            : $keyword;
        $keyword = rtrim($keyword, '*');
        $keyword = preg_replace('/[^A-Za-z0-9]/', '', $keyword);
        $keyword = is_string($keyword) ? $keyword : '';

        return strtoupper($keyword);
    }

    private function sanitize_token(string $value, int $max_length): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9_-]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, $max_length);
    }
}
