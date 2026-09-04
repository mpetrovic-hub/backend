<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Sms_Body_Variant_Service
{
    private const ALLOCATION_VERSION = 'fr_sms_v2';

    private const ACTIVE_ALLOCATION = [
        ['variant_key' => 'as_is_txn_prefix', 'seed' => '', 'weight' => 10],
        ['variant_key' => 'cta_phrase', 'seed' => 'BonusJeux', 'weight' => 20],
        ['variant_key' => 'game_word', 'seed' => 'TopJeux', 'weight' => 20],
        ['variant_key' => 'cta_phrase', 'seed' => 'JouerPlus', 'weight' => 20],
        ['variant_key' => 'cta_phrase', 'seed' => 'AccederJeux', 'weight' => 8],
        ['variant_key' => 'game_word', 'seed' => 'JeuxMax', 'weight' => 8],
        ['variant_key' => 'download_phrase', 'seed' => 'AccederMaintenant', 'weight' => 8],
        ['variant_key' => 'game_word', 'seed' => 'GameQuest', 'weight' => 6],
    ];

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
        ?array $attribution
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

        $allocation = $this->resolve_allocation($transaction_id);
        $variant_key = (string) ($allocation['variant_key'] ?? '');
        $seed = (string) ($allocation['seed'] ?? '');
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
            'allocation_version' => self::ALLOCATION_VERSION,
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

    public function resolve_variant_key(string $transaction_id): string
    {
        $allocation = $this->resolve_allocation($transaction_id);

        return (string) ($allocation['variant_key'] ?? 'as_is_txn_prefix');
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

    public function get_game_seeds(): array
    {
        return $this->get_seeds_for_variant_key('game_word');
    }

    public function get_cta_seeds(): array
    {
        return $this->get_seeds_for_variant_key('cta_phrase');
    }

    public function get_active_allocation(): array
    {
        return self::ACTIVE_ALLOCATION;
    }

    public function get_allocation_version(): string
    {
        return self::ALLOCATION_VERSION;
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

    private function resolve_allocation(string $transaction_id): array
    {
        $bucket = $this->stable_index($transaction_id, 100, self::ALLOCATION_VERSION);
        $upper_bound = 0;

        foreach (self::ACTIVE_ALLOCATION as $allocation) {
            $upper_bound += max(0, (int) ($allocation['weight'] ?? 0));

            if ($bucket < $upper_bound) {
                return $allocation;
            }
        }

        return self::ACTIVE_ALLOCATION[0];
    }

    private function get_seeds_for_variant_key(string $variant_key): array
    {
        $seeds = [];

        foreach (self::ACTIVE_ALLOCATION as $allocation) {
            if (($allocation['variant_key'] ?? '') !== $variant_key) {
                continue;
            }

            $seed = (string) ($allocation['seed'] ?? '');

            if ($seed !== '') {
                $seeds[] = $seed;
            }
        }

        return $seeds;
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
