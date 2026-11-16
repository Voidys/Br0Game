<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class BasicLegalFields
{
    /**
     * Basic server settings, category `legal` (hosting region, default rules, community board).
     * Missing keys (existing installations) fall back to these defaults, which keep the former behaviour.
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'legal' => [
                'privacy_region' => [
                    'type' => ConfigFieldSchema::TYPE_SELECT,
                    'transform' => 'string',
                    'options' => [
                        'eu' => $LNG['se_privacy_region_sel']['eu'],
                        'other' => $LNG['se_privacy_region_sel']['other'],
                    ],
                    'default' => 'eu',
                ],
                'rules_default' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => true,
                ],
                'board_enabled' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => true,
                ],
            ],
        ];
    }
}
