<?php

if (!defined('ABSPATH')) {
    exit;
}

interface Kiwi_Landing_Primary_Cta_Adapter_Interface
{
    public function supports(array $landing_page, array $service): bool;

    public function build_primary_cta_href(
        array $landing_page,
        array $service,
        ?array $attribution
    ): ?string;
}
