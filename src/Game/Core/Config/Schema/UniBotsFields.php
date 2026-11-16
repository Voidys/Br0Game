<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniBotsFields
{
    /**
     * Universe settings, category `bots` (Bots).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'bots' => [
                'bots_enabled' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'bots_max_count' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 50,
                ],
                'bots_ticks_per_run' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 1,
                    'default' => 25,
                ],
                'bots_raids_enabled' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'bots_alliances_enabled' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => true,
                ],
                'bots_join_human_alliances' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'bots_llm_enabled' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'bots_llm_daily_limit' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 4,
                    'size' => 4,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 20,
                ],
                'bot_reply_delay_hours' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 168,
                    'default' => 3,
                ],
                'bot_chatter' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => true,
                ],
            ],
        ];
    }
}
