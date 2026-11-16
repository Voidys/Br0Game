<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniBuildlistFields
{
    /**
     * Universe settings, category `buildlist` (Build List).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'buildlist' => [
                'max_elements_build' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 5,
                ],
                'max_elements_tech' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 2,
                ],
                'max_elements_ships' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 10,
                ],
                'max_fleet_per_build' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 20,
                    'size' => 15,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 1000000,
                ],
                'min_build_time' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 2,
                    'size' => 5,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 1,
                ],
            ],
        ];
    }
}
