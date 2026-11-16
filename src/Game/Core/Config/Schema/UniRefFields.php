<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniRefFields
{
    /**
     * Universe settings, category `ref` (Referral).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'ref' => [
                'ref_active' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'ref_bonus' => [
                    'type' => ConfigFieldSchema::TYPE_FLOAT,
                    'maxlength' => 6,
                    'size' => 8,
                    'transform' => 'float',
                    'default' => 1000,
                ],
                'ref_minpoints' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 20,
                    'size' => 25,
                    'transform' => 'int',
                    'default' => 2000,
                ],
                'ref_max_referals' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 6,
                    'size' => 8,
                    'transform' => 'int',
                    'default' => 5,
                ],
            ],
        ];
    }
}
