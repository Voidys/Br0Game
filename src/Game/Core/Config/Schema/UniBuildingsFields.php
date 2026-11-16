<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniBuildingsFields
{
    /**
     * Universe settings, category `buildings` (Buildings).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'buildings' => [
                'factor_university' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'default' => 8,
                ],
                'silo_factor' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 2,
                    'size' => 2,
                    'transform' => 'int',
                    'default' => 1,
                ],
                'jumpgate_factor' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'int',
                    'default' => 1,
                ],
            ],
        ];
    }
}
