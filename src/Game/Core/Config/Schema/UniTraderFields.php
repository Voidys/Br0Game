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
                    'min' => 0,
                    'max' => 90,
                    'default' => 30,
                ],
                'market_push_tolerance' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'unit' => '%',
                    'min' => 0,
                    'max' => 90,
                    'default' => 30,
                ],
                'transport_push_check' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'transport_push_limit' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 0,
                ],
            ],
        ];
    }
}
