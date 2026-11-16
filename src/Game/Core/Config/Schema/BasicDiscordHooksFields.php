<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class BasicDiscordHooksFields
{
    /**
     * Basic server settings, category `discord_hooks` (Discord Webhooks).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'discord_hooks' => [
                'discord_logs_hook' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'secret' => true,
                    'maxlength' => 500,
                    'size' => 60,
                    'transform' => 'string',
                    'default' => '',
                ],
                'discord_exceptions_hook' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'secret' => true,
                    'maxlength' => 500,
                    'size' => 60,
                    'transform' => 'string',
                    'default' => '',
                ],
                'discord_tickets_hook' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'secret' => true,
                    'maxlength' => 500,
                    'size' => 60,
                    'transform' => 'string',
                    'default' => '',
                ],
            ],
        ];
    }
}
