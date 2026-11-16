<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniNewsFields
{
    /**
     * Universe settings, category `news` (News).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'news' => [
                'overview_news_frame' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => true,
                ],
                'overview_news_text' => [
                    'type' => ConfigFieldSchema::TYPE_TEXTAREA,
                    'cols' => 80,
                    'rows' => 5,
                    'transform' => 'string',
                    'default' => $LNG['sql_welcome'],
                ],
            ],
        ];
    }
}
