<?php

if (!defined('ABSPATH')) {
    exit;
}

return [
    'allocation_version' => 'fr_sms_v2',
    'entries' => [
        ['variant_key' => 'as_is_txn_prefix', 'seed' => '', 'weight' => 10],
        ['variant_key' => 'cta_phrase', 'seed' => 'BonusJeux', 'weight' => 20],
        ['variant_key' => 'game_word', 'seed' => 'TopJeux', 'weight' => 20],
        ['variant_key' => 'cta_phrase', 'seed' => 'JouerPlus', 'weight' => 20],
        ['variant_key' => 'cta_phrase', 'seed' => 'AccederJeux', 'weight' => 8],
        ['variant_key' => 'game_word', 'seed' => 'JeuxMax', 'weight' => 8],
        ['variant_key' => 'download_phrase', 'seed' => 'AccederMaintenant', 'weight' => 8],
        ['variant_key' => 'game_word', 'seed' => 'GameQuest', 'weight' => 6],
    ],
];
