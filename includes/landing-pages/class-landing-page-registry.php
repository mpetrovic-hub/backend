<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Landing_Page_Registry
{
    private $landing_pages_root;
    private $project_root;
    private $registry = [];
    private $errors = [];
    private $loaded = false;

    public function __construct(string $landing_pages_root, string $project_root)
    {
        $this->landing_pages_root = rtrim($landing_pages_root, '/\\');
        $this->project_root = rtrim($project_root, '/\\');
    }

    public function get_registry(): array
    {
        $this->load_if_needed();

        return $this->registry;
    }

    public function get_errors(): array
    {
        $this->load_if_needed();

        return $this->errors;
    }

    public function get_by_key(string $key): ?array
    {
        $this->load_if_needed();

        return $this->registry[$key] ?? null;
    }

    public function get_by_flow(string $flow): array
    {
        $this->load_if_needed();
        $flow = trim($flow);
        $matches = [];

        foreach ($this->registry as $landing_page) {
            if ((string) ($landing_page['flow'] ?? '') === $flow) {
                $matches[] = $landing_page;
            }
        }

        return $matches;
    }

    public function get_by_country(string $country): array
    {
        $this->load_if_needed();
        $country = strtoupper(trim($country));
        $matches = [];

        foreach ($this->registry as $landing_page) {
            if ((string) ($landing_page['country'] ?? '') === $country) {
                $matches[] = $landing_page;
            }
        }

        return $matches;
    }

    private function load_if_needed(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (!is_dir($this->landing_pages_root)) {
            $this->errors[] = sprintf(
                'Landing pages root "%s" does not exist.',
                $this->landing_pages_root
            );

            return;
        }

        $entries = scandir($this->landing_pages_root);

        if (!is_array($entries)) {
            $this->errors[] = sprintf(
                'Landing pages root "%s" could not be scanned.',
                $this->landing_pages_root
            );

            return;
        }

        sort($entries, SORT_STRING);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entry_path = $this->landing_pages_root . DIRECTORY_SEPARATOR . $entry;

            if (!is_dir($entry_path)) {
                continue;
            }

            if (!$this->is_valid_folder_name($entry)) {
                $this->errors[] = sprintf(
                    'Landing page folder "%s" does not match naming format "lp<version>-<country>".',
                    $entry
                );
                continue;
            }

            $this->load_landing_page($entry, $entry_path);
        }
    }

    private function load_landing_page(string $folder_name, string $folder_path): void
    {
        $required_files = [
            'index.html',
            'styles.css',
            'integration.php',
        ];

        foreach ($required_files as $required_file) {
            $required_file_path = $folder_path . DIRECTORY_SEPARATOR . $required_file;

            if (!is_file($required_file_path)) {
                $this->errors[] = sprintf(
                    'Landing page "%s" is missing %s.',
                    $folder_name,
                    $required_file
                );
                return;
            }
        }

        $integration_path = $folder_path . DIRECTORY_SEPARATOR . 'integration.php';
        $metadata = include $integration_path;

        if (!is_array($metadata)) {
            $this->errors[] = sprintf(
                'Landing page "%s" integration.php must return an array.',
                $folder_name
            );
            return;
        }

        foreach (['key', 'country', 'flow', 'provider', 'documentation'] as $required_field) {
            if (!array_key_exists($required_field, $metadata)) {
                $this->errors[] = sprintf(
                    'Landing page "%s" is missing required field "%s".',
                    $folder_name,
                    $required_field
                );
                return;
            }
        }

        $key = trim((string) $metadata['key']);
        if ($key !== $folder_name) {
            $this->errors[] = sprintf(
                'Landing page "%s" has key mismatch: expected "%s", got "%s".',
                $folder_name,
                $folder_name,
                $key
            );
            return;
        }

        $country = trim((string) $metadata['country']);
        if (!preg_match('/^[A-Z]{2,3}$/', $country)) {
            $this->errors[] = sprintf(
                'Landing page "%s" has invalid country "%s". Country must be uppercase (for example "FR").',
                $folder_name,
                $country
            );
            return;
        }

        $flow = trim((string) $metadata['flow']);
        if ($flow === '') {
            $this->errors[] = sprintf(
                'Landing page "%s" has empty flow field.',
                $folder_name
            );
            return;
        }

        $provider = trim((string) $metadata['provider']);
        if ($provider === '') {
            $this->errors[] = sprintf(
                'Landing page "%s" has empty provider field.',
                $folder_name
            );
            return;
        }

        if (array_key_exists('active', $metadata) && !is_bool($metadata['active'])) {
            $this->errors[] = sprintf(
                'Landing page "%s" has invalid "active" value. Expected boolean.',
                $folder_name
            );
            return;
        }

        $documentation_path = $this->normalize_documentation_path(
            (string) $metadata['documentation'],
            $folder_name
        );

        if ($documentation_path === null) {
            return;
        }

        $normalized = $metadata;
        $normalized['key'] = $folder_name;
        $normalized['country'] = $country;
        $normalized['flow'] = $flow;
        $normalized['provider'] = $provider;
        $normalized['documentation'] = $documentation_path['public'];
        $normalized['documentation_resolved_path'] = $documentation_path['absolute'];
        $normalized['active'] = array_key_exists('active', $metadata)
            ? (bool) $metadata['active']
            : true;

        $normalized['hostnames'] = $this->normalize_hostnames($metadata['hostnames'] ?? []);
        $normalized['backend_path'] = trim((string) ($metadata['backend_path'] ?? ''));
        $normalized['dedicated_path'] = trim((string) ($metadata['dedicated_path'] ?? '/'));

        $normalized['render_mode'] = 'filesystem';
        $normalized['folder_name'] = $folder_name;
        $normalized['folder_path'] = $folder_path;
        $normalized['index_path'] = $folder_path . DIRECTORY_SEPARATOR . 'index.html';
        $normalized['styles_path'] = $folder_path . DIRECTORY_SEPARATOR . 'styles.css';

        $this->registry[$folder_name] = $normalized;
    }

    private function normalize_documentation_path(string $documentation, string $folder_name): ?array
    {
        $documentation = trim($documentation);

        if ($documentation === '') {
            $this->errors[] = sprintf(
                'Landing page "%s" has empty documentation field.',
                $folder_name
            );
            return null;
        }

        if (strpos($documentation, '..') !== false) {
            $this->errors[] = sprintf(
                'Landing page "%s" has invalid documentation path "%s". Relative traversal is not allowed.',
                $folder_name,
                $documentation
            );
            return null;
        }

        $normalized_public_path = $documentation;

        if (strpos($documentation, '/docs/integrations/') === 0) {
            $normalized_public_path = '/integrations/' . ltrim(substr($documentation, strlen('/docs/integrations/')), '/');
        } elseif (strpos($documentation, '/integrations/') !== 0) {
            $this->errors[] = sprintf(
                'Landing page "%s" has invalid documentation path "%s". It must point under /integrations.',
                $folder_name,
                $documentation
            );
            return null;
        }

        $relative_doc_path = ltrim(substr($normalized_public_path, strlen('/integrations/')), '/');
        $absolute_doc_path = $this->project_root
            . DIRECTORY_SEPARATOR
            . 'docs'
            . DIRECTORY_SEPARATOR
            . 'integrations'
            . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative_doc_path);

        if (!is_file($absolute_doc_path)) {
            $this->errors[] = sprintf(
                'Landing page "%s" documentation file was not found at "%s".',
                $folder_name,
                $absolute_doc_path
            );
            return null;
        }

        return [
            'public' => $normalized_public_path,
            'absolute' => $absolute_doc_path,
        ];
    }

    private function normalize_hostnames($hostnames): array
    {
        if (!is_array($hostnames)) {
            return [];
        }

        $normalized = [];

        foreach ($hostnames as $hostname) {
            $hostname = strtolower(trim((string) $hostname));

            if ($hostname === '') {
                continue;
            }

            $normalized[] = $hostname;
        }

        return array_values(array_unique($normalized));
    }

    private function is_valid_folder_name(string $folder_name): bool
    {
        return preg_match('/^lp[0-9]+-[a-z]{2,3}$/', $folder_name) === 1;
    }
}
