<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniGalaxyParametersFields
{
    /**
     * Universe settings, category `galaxy_parameters` (Galaxy Parameters).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        // split into parts only to keep each method short; `+` keeps the field order
        return ['galaxy_parameters' => $this->part1($LNG) + $this->part2($LNG)];
    }

    /**
     * Fields of category `galaxy_parameters`, part 1 of 2 (see fields()).
     *
     * @return array<string, array<string, mixed>> field => definition
     */
    private function part1(Language $LNG): array
    {
        return [
            'deuterium_cost_galaxy' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 11,
                'size' => 11,
                'transform' => 'int',
                'min' => 0,
                'default' => 10,
            ],
            'max_galaxy' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 3,
                'size' => 3,
                'transform' => 'int',
                'min' => 0,
                'default' => 9,
            ],
            'max_system' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 5,
                'size' => 5,
                'transform' => 'int',
                'min' => 0,
                'default' => 400,
            ],
            'max_planets' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 3,
                'size' => 3,
                'transform' => 'int',
                'min' => 0,
                'default' => 15,
            ],
            'uni_type' => [
                'type' => ConfigFieldSchema::TYPE_SELECT,
                'options' => [
                    '0' => $LNG['se_line_uni'],
                    '1' => $LNG['se_ring_uni'],
                    '2' => $LNG['se_sphere_uni'],
                ],
                'default' => 0,
            ],
            'galaxy_type' => [
                'type' => ConfigFieldSchema::TYPE_SELECT,
                'options' => [
                    '0' => $LNG['se_line_galaxy'],
                    '1' => $LNG['se_ring_galaxy'],
                    '2' => $LNG['se_sphere_galaxy'],
                ],
                'default' => 0,
            ],
            'planet_creation' => [
                'type' => ConfigFieldSchema::TYPE_SELECT,
                'options' => [
                    '0' => $LNG['se_iter_planet_creation'],
                    '1' => $LNG['se_random_planet_creation'],
                    '2' => $LNG['se_block_planet_creation'],
                ],
                'default' => 0,
            ],
        ];
    }

    /**
     * Fields of category `galaxy_parameters`, part 2 of 2 (see fields()).
     *
     * @return array<string, array<string, mixed>> field => definition
     */
    private function part2(Language $LNG): array
    {
        return [
            'galaxy_distance_factor' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 11,
                'size' => 11,
                'transform' => 'int',
                'default' => 20000,
            ],
            'system_distance_factor' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 11,
                'size' => 11,
                'transform' => 'int',
                'default' => 95,
            ],
            'system_distance' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 11,
                'size' => 11,
                'transform' => 'int',
                'default' => 2700,
            ],
            'planet_distance_factor' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 11,
                'size' => 11,
                'transform' => 'int',
                'default' => 5,
            ],
            'planet_distance' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 11,
                'size' => 11,
                'transform' => 'int',
                'default' => 1000,
            ],
        ];
    }
}
