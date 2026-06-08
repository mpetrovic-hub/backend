<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Premium_Sms_Completed_Sale_Cooldown_Service
{
    private $sales_repository;

    public function __construct(Kiwi_Sales_Repository $sales_repository)
    {
        $this->sales_repository = $sales_repository;
    }

    public function find_blocking_sale(array $context, int $cooldown_days): ?array
    {
        $cooldown_days = max(0, $cooldown_days);

        if ($cooldown_days === 0) {
            return null;
        }

        return $this->sales_repository->find_recent_completed_one_off_sale_by_subscriber_context(
            (string) ($context['service_key'] ?? ''),
            (string) ($context['subscriber_reference'] ?? ''),
            (string) ($context['shortcode'] ?? ''),
            (string) ($context['keyword'] ?? ''),
            $cooldown_days
        );
    }
}
