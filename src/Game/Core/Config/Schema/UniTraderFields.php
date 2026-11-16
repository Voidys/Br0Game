<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniTraderFields
{
    /**
     * Universe settings, category `trader` (Trade).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'trader' => [
                'trade_allowed_ships' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'transform' => 'string',
                    'default' => '202,203,204,205,206,207,208,209,210,211,212,213,214,215',
                ],
                'trade_charge' => [
                    'type' => ConfigFieldSchema::TYPE_FLOAT,
                    'transform' => 'float',
                    'unit' => '%',
                    'default' => 30,
                ],
            ],
        ];
    }
}
