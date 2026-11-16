<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class BasicCommunityLinksFields
{
    /**
     * Basic server settings, category `community_links` (Community links (empty = hidden everywhere, see App\Game\Core\CommunityLinks)).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'community_links' => [
                'discord_url' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'validate' => 'http_url',
                    'default' => '',
                ],
                'discord_widget_guild' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'maxlength' => 20,
                    'size' => 24,
                    'transform' => 'string',
                    'validate' => 'discord_guild',
                    'default' => '',
                ],
                'wiki_url' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'validate' => 'http_url',
                    'default' => '',
                ],
                'donate_url' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'validate' => 'http_url',
                    'default' => '',
                ],
                'userscripts_url' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'validate' => 'http_url',
                    'default' => '',
                ],
            ],
        ];
    }
}
