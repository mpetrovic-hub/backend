<?php

if (!defined('ABSPATH')) {
    exit;
}

interface Kiwi_Landing_Session_Engagement_Evaluator_Interface
{
    public function evaluate(array $row): array;
}
