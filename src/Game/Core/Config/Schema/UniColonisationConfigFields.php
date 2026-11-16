<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniColonisationConfigFields
{
    /**
     * Universe settings, category `colonisation_config` (Colonisation).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'colonisation_config' => [
                'max_initial_planets' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 1,
                ],
                'max_additional_planets' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 11,
                ],
                'planets_per_tech' => [
                    'type' => ConfigFieldSchema::TYPE_FLOAT,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'float',
                    'default' => 0.5,
                ],
                'planet_size_factor' => [
                    'type' => ConfigFieldSchema::TYPE_FLOAT,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'float',
                    'default' => 1.0,
                ],
                'all_planet_pictures' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
            ],
        ];
    }
}
