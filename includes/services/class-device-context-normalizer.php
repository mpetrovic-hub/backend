<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Device_Context_Normalizer
{
    private const UNKNOWN = '(unknown)';

    private $model_brand_map_repository;

    public function __construct(?Kiwi_Device_Model_Brand_Map_Repository $model_brand_map_repository = null)
    {
        $this->model_brand_map_repository = $model_brand_map_repository;
    }

    public function normalize(array $context): array
    {
        $user_agent = $this->sanitize_text((string) ($context['user_agent'] ?? ''), 1000);
        $ua_ch_platform = $this->sanitize_text((string) ($context['ua_ch_platform'] ?? ''), 50);
        $ua_ch_platform_version = $this->sanitize_text((string) ($context['ua_ch_platform_version'] ?? ''), 50);
        $ua_ch_model = $this->sanitize_text((string) ($context['ua_ch_model'] ?? ''), 191);
        $ua_ch_brands = $this->sanitize_text((string) ($context['ua_ch_brands'] ?? ''), 1000);
        $ua_ch_full_version_list = $this->sanitize_text((string) ($context['ua_ch_full_version_list'] ?? ''), 1000);
        $model = $this->normalize_model_candidate($ua_ch_model);

        if ($model === '') {
            $model = $this->extract_model_from_user_agent($user_agent);
        }

        $os = $this->resolve_os($ua_ch_platform, $user_agent);

        return [
            'device_brand' => $this->resolve_device_brand($model, $user_agent),
            'os' => $os,
            'os_version' => $this->resolve_os_version($os, $ua_ch_platform_version, $user_agent),
            'browser' => $this->resolve_browser($ua_ch_brands, $ua_ch_full_version_list, $user_agent),
        ];
    }

    public function merge(array $existing, array $incoming): array
    {
        $merged = [
            'device_brand' => $this->sanitize_bucket((string) ($existing['device_brand'] ?? ''), 100),
            'os' => $this->sanitize_bucket((string) ($existing['os'] ?? ''), 50),
            'os_version' => $this->sanitize_bucket((string) ($existing['os_version'] ?? ''), 50),
            'browser' => $this->sanitize_bucket((string) ($existing['browser'] ?? ''), 100),
        ];

        foreach ($merged as $field => $value) {
            $candidate = $this->sanitize_bucket((string) ($incoming[$field] ?? ''), $field === 'browser' || $field === 'device_brand' ? 100 : 50);

            if (!$this->is_known($candidate)) {
                continue;
            }

            if (!$this->is_known($value) || $this->is_more_specific($field, $value, $candidate)) {
                $merged[$field] = $candidate;
            }
        }

        return $merged;
    }

    private function resolve_device_brand(string $model, string $user_agent): string
    {
        if ($model !== '' && $this->model_brand_map_repository instanceof Kiwi_Device_Model_Brand_Map_Repository) {
            $brand = $this->model_brand_map_repository->find_brand_for_model($model);

            if ($brand !== '') {
                return $this->normalize_brand($brand);
            }
        }

        if ($model !== '' && preg_match('/^SM[-\s]?[A-Z0-9]/i', $model) === 1) {
            return 'Samsung';
        }

        if ($this->contains($model, 'Samsung') || $this->contains($user_agent, 'Samsung')) {
            return 'Samsung';
        }

        if ($this->contains($model, 'Pixel') || $this->contains($user_agent, 'Pixel')) {
            return 'Google';
        }

        if ($this->contains($model, 'Huawei') || $this->contains($user_agent, 'Huawei')) {
            return 'Huawei';
        }

        if ($this->contains($model, 'Honor') || $this->contains($user_agent, 'Honor')) {
            return 'Honor';
        }

        if ($this->is_xiaomi_family_model($model) || $this->contains($user_agent, 'Xiaomi') || $this->contains($user_agent, 'Redmi') || $this->contains($user_agent, 'POCO')) {
            return 'Xiaomi';
        }

        if (preg_match('/\b(?:iPhone|iPad|iPod)\b/i', $user_agent) === 1) {
            return 'Apple';
        }

        return self::UNKNOWN;
    }

    private function resolve_os(string $ua_ch_platform, string $user_agent): string
    {
        if (strcasecmp($ua_ch_platform, 'Android') === 0 || stripos($user_agent, 'Android') !== false) {
            return 'Android';
        }

        if (preg_match('/\b(?:iPhone|iPad|iPod)\b/i', $user_agent) === 1
            || preg_match('/\bCPU (?:iPhone )?OS \d+[_\d]*/i', $user_agent) === 1
            || strcasecmp($ua_ch_platform, 'iOS') === 0
        ) {
            return 'iOS';
        }

        if (strcasecmp($ua_ch_platform, 'Windows') === 0 || stripos($user_agent, 'Windows NT') !== false) {
            return 'Windows';
        }

        if (strcasecmp($ua_ch_platform, 'macOS') === 0 || stripos($user_agent, 'Mac OS X') !== false) {
            return 'macOS';
        }

        if (strcasecmp($ua_ch_platform, 'Linux') === 0 || stripos($user_agent, 'Linux') !== false) {
            return 'Linux';
        }

        return self::UNKNOWN;
    }

    private function resolve_os_version(string $os, string $ua_ch_platform_version, string $user_agent): string
    {
        if ($os === 'Android') {
            foreach ([$ua_ch_platform_version, $this->extract_android_version_from_user_agent($user_agent)] as $candidate) {
                $major = $this->normalize_android_major_version($candidate);

                if ($major !== '') {
                    return $major;
                }
            }

            return self::UNKNOWN;
        }

        if ($os === 'iOS') {
            if (preg_match('/CPU (?:iPhone )?OS ([0-9_]+)/i', $user_agent, $matches) === 1) {
                $version = $this->normalize_dotted_version((string) ($matches[1] ?? ''));

                if ($version !== '') {
                    return $version;
                }
            }

            return self::UNKNOWN;
        }

        if ($os === 'Windows' && preg_match('/Windows NT ([0-9.]+)/i', $user_agent, $matches) === 1) {
            return $this->normalize_dotted_version((string) ($matches[1] ?? '')) ?: self::UNKNOWN;
        }

        if ($os === 'macOS' && preg_match('/Mac OS X ([0-9_\.]+)/i', $user_agent, $matches) === 1) {
            return $this->normalize_dotted_version((string) ($matches[1] ?? '')) ?: self::UNKNOWN;
        }

        return self::UNKNOWN;
    }

    private function resolve_browser(string $ua_ch_brands, string $ua_ch_full_version_list, string $user_agent): string
    {
        $browser_source = trim($ua_ch_full_version_list . ' ' . $ua_ch_brands . ' ' . $user_agent);

        if ($this->contains($browser_source, 'Instagram')) {
            return 'Instagram';
        }

        if ($this->contains($browser_source, 'FBAN') || $this->contains($browser_source, 'FBAV') || $this->contains($browser_source, 'FB_IAB') || $this->contains($browser_source, 'Facebook')) {
            return 'Facebook';
        }

        if ($this->contains($browser_source, 'TikTok') || $this->contains($browser_source, 'BytedanceWebview')) {
            return 'TikTok';
        }

        if ($this->contains($browser_source, 'Samsung Internet') || stripos($user_agent, 'SamsungBrowser/') !== false) {
            return 'Samsung Internet';
        }

        if ($this->contains($browser_source, 'Android WebView') || stripos($user_agent, '; wv)') !== false || stripos($user_agent, '; wv;') !== false) {
            return 'Android WebView';
        }

        if ($this->contains($browser_source, 'Microsoft Edge') || stripos($user_agent, 'Edg/') !== false || stripos($user_agent, 'EdgiOS/') !== false || stripos($user_agent, 'EdgA/') !== false) {
            return 'Edge';
        }

        if (stripos($user_agent, 'OPR/') !== false || stripos($user_agent, 'OPT/') !== false || $this->contains($browser_source, 'Opera')) {
            return 'Opera';
        }

        if (stripos($user_agent, 'Firefox/') !== false || stripos($user_agent, 'FxiOS/') !== false) {
            return 'Firefox';
        }

        if ($this->contains($browser_source, 'Google Chrome') || stripos($user_agent, 'Chrome/') !== false || stripos($user_agent, 'CriOS/') !== false) {
            return 'Chrome';
        }

        if (stripos($user_agent, 'Safari/') !== false) {
            return 'Safari';
        }

        return self::UNKNOWN;
    }

    private function normalize_model_candidate(string $model): string
    {
        $model = $this->sanitize_text($model, 191);

        if ($model === '' || preg_match('/^(?:unknown|android|mobile|phone|generic|k)$/i', $model) === 1) {
            return '';
        }

        return $model;
    }

    private function extract_model_from_user_agent(string $user_agent): string
    {
        if (preg_match('/Android\s+[^;\)]*[;]\s*([^;\)]*?)(?:\s+Build\/|[;\)])/i', $user_agent, $matches) !== 1) {
            return '';
        }

        return $this->normalize_model_candidate((string) ($matches[1] ?? ''));
    }

    private function extract_android_version_from_user_agent(string $user_agent): string
    {
        if (preg_match('/Android\s+([0-9][0-9._]*)/i', $user_agent, $matches) !== 1) {
            return '';
        }

        return (string) ($matches[1] ?? '');
    }

    private function normalize_android_major_version(string $version): string
    {
        $version = trim($version);

        if ($version === '') {
            return '';
        }

        if (preg_match('/^([0-9]{1,2})(?:[._][0-9]+)*$/', $version, $matches) !== 1) {
            return '';
        }

        $major = (int) ($matches[1] ?? 0);

        return $major > 0 && $major < 100 ? (string) $major : '';
    }

    private function normalize_dotted_version(string $version): string
    {
        $version = str_replace('_', '.', trim($version));

        if (preg_match('/^([0-9]{1,3})(?:\.([0-9]{1,3})){0,3}$/', $version) !== 1) {
            return '';
        }

        return $version;
    }

    private function is_xiaomi_family_model(string $model): bool
    {
        if ($model === '') {
            return false;
        }

        if (preg_match('/^(?:Xiaomi|Redmi|POCO)(?:\b|\s|-)/i', $model) === 1) {
            return true;
        }

        if (preg_match('/^(?:Mi|MIX)\s*[A-Z0-9]/i', $model) === 1) {
            return true;
        }

        return preg_match('/^M[0-9]{4}[A-Z0-9]{3,}$/i', $model) === 1;
    }

    private function is_more_specific(string $field, string $existing, string $incoming): bool
    {
        if ($field === 'browser') {
            $generic = ['Chrome' => true, 'Safari' => true];

            return isset($generic[$existing]) && $incoming !== $existing;
        }

        if ($field === 'os_version') {
            return strlen($incoming) > strlen($existing) && strpos($incoming, $existing . '.') === 0;
        }

        return false;
    }

    private function normalize_brand(string $brand): string
    {
        if ($this->contains($brand, 'Xiaomi') || $this->contains($brand, 'Redmi') || $this->contains($brand, 'POCO')) {
            return 'Xiaomi';
        }

        return $this->sanitize_bucket($brand, 100) ?: self::UNKNOWN;
    }

    private function is_known(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && $value !== self::UNKNOWN;
    }

    private function sanitize_bucket(string $value, int $max_length): string
    {
        $value = $this->sanitize_text($value, $max_length);

        return $value === '' ? self::UNKNOWN : $value;
    }

    private function sanitize_text(string $value, int $max_length): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^\P{C}\r\n\t]/u', '', $value);
        $value = is_string($value) ? $value : '';
        $value = preg_replace('/\s+/', ' ', $value);
        $value = is_string($value) ? trim($value) : '';

        return substr($value, 0, max(1, $max_length));
    }

    private function contains(string $haystack, string $needle): bool
    {
        return stripos($haystack, $needle) !== false;
    }
}
