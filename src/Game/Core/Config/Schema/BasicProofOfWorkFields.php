<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class BasicProofOfWorkFields
{
    /**
     * Basic server settings, category `proofofwork` (bot protection of the registration and lost-password forms).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'proofofwork' => [
                'pow_adaptive' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => true,
                ],
                'pow_max_number' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 7,
                    'size' => 7,
                    'transform' => 'int',
                    'min' => 1000,
                    'default' => 25000,
                ],
                'pow_elevated_factor' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 4,
                    'size' => 4,
                    'transform' => 'int',
                    'min' => 1,
                    'default' => 20,
                ],
                'pow_high_factor' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 4,
                    'size' => 4,
                    'transform' => 'int',
                    'min' => 1,
                    'default' => 100,
                ],
                'pow_max_cap' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 8,
                    'size' => 8,
                    'transform' => 'int',
                    'min' => 1000,
                    'default' => 1500000,
                ],
                'pow_sensitivity' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 4,
                    'size' => 4,
                    'transform' => 'int',
                    'min' => 10,
                    'unit' => '%',
                    'default' => 100,
                ],
                'pow_min_seconds' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 2,
                    'size' => 2,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 3,
                ],
            ],
        ];
    }
}
