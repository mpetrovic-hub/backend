<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Client_Ip_Resolver
{
    private const UNKNOWN = '(unknown)';

    public function resolve(array $server, array $trusted_proxy_cidrs = []): array
    {
        $peer_ip = $this->normalize_ip_value((string) ($server['REMOTE_ADDR'] ?? ''));

        if ($peer_ip === '') {
            return $this->empty_ip_snapshot('none', false);
        }

        $trusted_proxy_cidrs = $this->normalize_trusted_proxy_cidrs($trusted_proxy_cidrs);
        $peer_is_trusted = $this->is_trusted_proxy($peer_ip, $trusted_proxy_cidrs);

        if ($peer_is_trusted) {
            $forwarded = $this->resolve_forwarded_candidate($server, $trusted_proxy_cidrs);

            if (is_array($forwarded)) {
                $forwarded['peer_trusted'] = true;

                return $forwarded;
            }

            return $this->empty_ip_snapshot('trusted_proxy_missing_forwarded_client', true);
        }

        $snapshot = $this->normalize_ip($peer_ip);
        $snapshot['source'] = 'remote_addr';
        $snapshot['peer_trusted'] = $peer_is_trusted;

        return $snapshot;
    }

    public function normalize_ip(string $ip): array
    {
        $ip = $this->normalize_ip_value($ip);

        if ($ip === '') {
            return $this->empty_ip_snapshot('invalid', false);
        }

        $packed = @inet_pton($ip);

        if ($packed === false) {
            return $this->empty_ip_snapshot('invalid', false);
        }

        $normalized_ip = strtolower((string) inet_ntop($packed));

        if (filter_var($normalized_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $normalized_ip);
            $prefix = count($parts) === 4
                ? implode('.', [$parts[0], $parts[1], $parts[2], '0']) . '/24'
                : self::UNKNOWN;

            return [
                'client_ip' => $normalized_ip,
                'client_ip_version' => 'ipv4',
                'client_ip_prefix' => $prefix,
                'client_ip_hash' => hash('sha256', $normalized_ip),
                'source' => 'normalized',
                'peer_trusted' => false,
            ];
        }

        if (filter_var($normalized_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bytes = array_values(unpack('C*', $packed) ?: []);
            $groups = [];

            for ($index = 0; $index < 6; $index += 2) {
                $groups[] = sprintf('%x', (($bytes[$index] ?? 0) << 8) + ($bytes[$index + 1] ?? 0));
            }

            return [
                'client_ip' => $normalized_ip,
                'client_ip_version' => 'ipv6',
                'client_ip_prefix' => implode(':', $groups) . '::/48',
                'client_ip_hash' => hash('sha256', $normalized_ip),
                'source' => 'normalized',
                'peer_trusted' => false,
            ];
        }

        return $this->empty_ip_snapshot('invalid', false);
    }

    public function is_trusted_proxy(string $ip, array $trusted_proxy_cidrs): bool
    {
        $ip = $this->normalize_ip_value($ip);

        if ($ip === '') {
            return false;
        }

        $candidate = $this->parse_ip_for_match($ip);

        if ($candidate === null) {
            return false;
        }

        foreach ($this->normalize_trusted_proxy_cidrs($trusted_proxy_cidrs) as $trusted_proxy) {
            $network = $this->parse_trusted_proxy_rule($trusted_proxy);

            if ($network === null || $network['version'] !== $candidate['version']) {
                continue;
            }

            if ($this->binary_prefix_matches($candidate['bytes'], $network['bytes'], $network['prefix_length'])) {
                return true;
            }
        }

        return false;
    }

    private function resolve_forwarded_candidate(array $server, array $trusted_proxy_cidrs): ?array
    {
        foreach ($this->extract_forwarded_chains($server) as $chain) {
            for ($index = count($chain['values']) - 1; $index >= 0; $index--) {
                $ip = $this->normalize_ip_value((string) ($chain['values'][$index] ?? ''));

                if ($ip === '') {
                    continue;
                }

                if ($this->is_trusted_proxy($ip, $trusted_proxy_cidrs)) {
                    continue;
                }

                $snapshot = $this->normalize_ip($ip);
                $snapshot['source'] = $chain['source'];

                return $snapshot;
            }
        }

        return null;
    }

    private function extract_forwarded_chains(array $server): array
    {
        $chains = [];
        $xff = trim((string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''));

        if ($xff !== '') {
            $chains[] = [
                'source' => 'x_forwarded_for',
                'values' => preg_split('/\s*,\s*/', $xff) ?: [],
            ];
        }

        $forwarded = trim((string) ($server['HTTP_FORWARDED'] ?? ''));

        if ($forwarded !== '') {
            $values = [];
            $entries = preg_split('/\s*,\s*/', $forwarded) ?: [];

            foreach ($entries as $entry) {
                if (preg_match('/(?:^|;)\s*for=(?:"([^"]+)"|([^;,]+))/i', (string) $entry, $matches) !== 1) {
                    continue;
                }

                $values[] = (string) ($matches[1] !== '' ? $matches[1] : $matches[2]);
            }

            if (!empty($values)) {
                $chains[] = [
                    'source' => 'forwarded',
                    'values' => $values,
                ];
            }
        }

        $x_real_ip = trim((string) ($server['HTTP_X_REAL_IP'] ?? ''));

        if ($x_real_ip !== '') {
            $chains[] = [
                'source' => 'x_real_ip',
                'values' => [$x_real_ip],
            ];
        }

        return $chains;
    }

    private function normalize_ip_value(string $value): string
    {
        $value = trim($value);
        $value = trim($value, "\"' \t\n\r\0\x0B");

        if ($value === '' || strtolower($value) === 'unknown') {
            return '';
        }

        if (stripos($value, 'for=') === 0) {
            $value = substr($value, 4);
            $value = trim($value, "\"' \t\n\r\0\x0B");
        }

        if (preg_match('/^\[([0-9A-Fa-f:.%]+)\](?::[0-9]+)?$/', $value, $matches) === 1) {
            $value = (string) ($matches[1] ?? '');
        } elseif (preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3})(?::[0-9]+)?$/', $value, $matches) === 1) {
            $value = (string) ($matches[1] ?? '');
        }

        $percent_position = strpos($value, '%');

        if ($percent_position !== false) {
            $value = substr($value, 0, $percent_position);
        }

        $value = trim($value);

        return filter_var($value, FILTER_VALIDATE_IP) ? $value : '';
    }

    private function normalize_trusted_proxy_cidrs(array $trusted_proxy_cidrs): array
    {
        $normalized = [];

        foreach ($trusted_proxy_cidrs as $trusted_proxy) {
            $trusted_proxy = trim((string) $trusted_proxy);

            if ($trusted_proxy !== '') {
                $normalized[] = $trusted_proxy;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function parse_trusted_proxy_rule(string $trusted_proxy): ?array
    {
        $trusted_proxy = trim($trusted_proxy);
        $prefix_length = null;

        if (strpos($trusted_proxy, '/') !== false) {
            [$ip, $prefix] = array_pad(explode('/', $trusted_proxy, 2), 2, '');
            $trusted_proxy = trim($ip);
            $prefix = trim($prefix);

            if ($prefix === '' || preg_match('/^[0-9]+$/', $prefix) !== 1) {
                return null;
            }

            $prefix_length = (int) $prefix;
        }

        $parsed = $this->parse_ip_for_match($trusted_proxy);

        if ($parsed === null) {
            return null;
        }

        $max_prefix_length = $parsed['version'] === 4 ? 32 : 128;

        if ($prefix_length === null) {
            $prefix_length = $max_prefix_length;
        }

        if ($prefix_length < 0 || $prefix_length > $max_prefix_length) {
            return null;
        }

        $parsed['prefix_length'] = $prefix_length;

        return $parsed;
    }

    private function parse_ip_for_match(string $ip): ?array
    {
        $ip = $this->normalize_ip_value($ip);

        if ($ip === '') {
            return null;
        }

        $packed = @inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [
                'bytes' => $packed,
                'version' => 4,
            ];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return [
                'bytes' => $packed,
                'version' => 6,
            ];
        }

        return null;
    }

    private function binary_prefix_matches(string $candidate_bytes, string $network_bytes, int $prefix_length): bool
    {
        $full_bytes = intdiv($prefix_length, 8);
        $remaining_bits = $prefix_length % 8;

        if ($full_bytes > 0 && substr($candidate_bytes, 0, $full_bytes) !== substr($network_bytes, 0, $full_bytes)) {
            return false;
        }

        if ($remaining_bits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remaining_bits)) & 0xff;
        $candidate_byte = ord($candidate_bytes[$full_bytes] ?? "\0");
        $network_byte = ord($network_bytes[$full_bytes] ?? "\0");

        return ($candidate_byte & $mask) === ($network_byte & $mask);
    }

    private function empty_ip_snapshot(string $source, bool $peer_trusted): array
    {
        return [
            'client_ip' => '',
            'client_ip_version' => self::UNKNOWN,
            'client_ip_prefix' => self::UNKNOWN,
            'client_ip_hash' => '',
            'source' => $source,
            'peer_trusted' => $peer_trusted,
        ];
    }
}
