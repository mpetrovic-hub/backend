<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Confirmed sales and their original SMS assignment proof for the requested services. */
interface Kiwi_Sms_Confirmed_Sale_History
{
    public function max_sale_id(): int;

    /** Each entry contains id, service_key, created_at and the assignment lookup result under proof. */
    public function confirmed_sales_page(array $services, int $after, int $max, int $limit): array;
}
