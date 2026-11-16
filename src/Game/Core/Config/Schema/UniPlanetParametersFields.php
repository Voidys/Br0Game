<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniPlanetParametersFields
{
    /**
     * Universe settings, category `planet_parameters` (Planet Parameters).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        // split into parts only to keep each method short; `+` keeps the field order
        return ['planet_parameters' => $this->part1($LNG) + $this->part2($LNG)];
    }

    /**
     * Fields of category `planet_parameters`, part 1 of 2 (see fields()).
     *
     * @return array<string, array<string, mixed>> field => definition
     */
    private function part1(Language $LNG): array
    {
        return [
            'metal_start' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 11,
                'size' => 11,
                'transform' => 'int',
                'min' => 0,
                'default' => 500,
            ],
            'crystal_start' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 11,
                'size' => 11,
                'transform' => 'int',
                'min' => 0,
                'default' => 500,
            ],
            'deuterium_start' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 11,
                'size' => 11,
                'transform' => 'int',
                'min' => 0,
                'default' => 0,
            ],
            'initial_fields' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 10,
                'size' => 10,
                'transform' => 'int',
                'min' => 0,
                'default' => 163,
            ],
            'initial_temp' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 10,
                'size' => 10,
                'transform' => 'int',
                'min' => -273,
                'default' => 50,
            ],
            'metal_basic_income' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 10,
                'size' => 10,
                'transform' => 'int',
                'unit' => $LNG['se_per_hour'],
                'default' => 20,
            ],
            'crystal_basic_income' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 10,
                'size' => 10,
                'transform' => 'int',
                'unit' => $LNG['se_per_hour'],
                'default' => 10,
            ],
        ];
    }

    /**
     * Fields of category `planet_parameters`, part 2 of 2 (see fields()).
     *
     * @return array<string, array<string, mixed>> field => definition
     */
    private function part2(Language $LNG): array
    {
        return [
            'deuterium_basic_income' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 10,
                'size' => 10,
                'transform' => 'int',
                'unit' => $LNG['se_per_hour'],
                'default' => 0,
            ],
            'energy_basic_income' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 10,
                'size' => 10,
                'transform' => 'int',
                'unit' => $LNG['se_per_hour'],
                'default' => 0,
            ],
            'planet_ressource_bonus' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => false,
            ],
            'moon_factor' => [
                'type' => ConfigFieldSchema::TYPE_FLOAT,
                'maxlength' => 3,
                'size' => 3,
                'transform' => 'float',
                'default' => 1.0,
            ],
            'moon_chance' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 3,
                'size' => 3,
                'transform' => 'int',
                'min' => 0,
                'default' => 20,
            ],
            'debris_moon' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => false,
            ],
            'moon_size_factor' => [
                'type' => ConfigFieldSchema::TYPE_FLOAT,
                'maxlength' => 11,
                'size' => 11,
                'transform' => 'float',
                'default' => 1.0,
            ],
            'cascading_moon_chance' => [
                'type' => ConfigFieldSchema::TYPE_FLOAT,
                'maxlength' => 11,
                'size' => 11,
                'transform' => 'float',
                'unit' => '%',
                'default' => 0.0,
            ],
        ];
    }
}
