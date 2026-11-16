<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniPiratebaseFields
{
    /**
     * Universe settings, category `piratebase`.
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'piratebase' => [
                'piratebase_min_points' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 10000,
                ],
                'piratebase_fleet_percentage_min' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 200,
                    'unit' => '%',
                    'default' => 50,
                ],
                'piratebase_fleet_percentage_max' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 200,
                    'unit' => '%',
                    'default' => 75,
                ],
                'piratebase_mainplanet_only' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'piratebase_ress_hours' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'unit' => $LNG['se_per_hour'],
                    'default' => 24,
                ],
            ],
        ];
    }
}
