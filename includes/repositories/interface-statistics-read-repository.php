<?php

if (!defined('ABSPATH')) {
    exit;
}

interface Kiwi_Statistics_Read_Repository_Interface
{
    public function get_default_from(): string;

    public function normalize_filters(array $filters): array;

    public function get_rows(array $filters = [], int $limit = 100): array;

    public function get_filter_options(array $filters = []): array;

    public function get_last_error(): string;

    public function get_source_name(): string;
}
