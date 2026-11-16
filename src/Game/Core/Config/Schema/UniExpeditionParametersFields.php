<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniExpeditionParametersFields
{
    /**
     * Universe settings, category `expedition_parameters` (Expedition).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'expedition_parameters' => [
                'expo_ress_met_chance' => [
                    'type' => ConfigFieldSchema::TYPE_SLIDER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 100,
                    'label' => $LNG['tech'][901] . ': ',
                    'default' => 50,
                ],
                'expo_ress_crys_chance' => [
                    'type' => ConfigFieldSchema::TYPE_SLIDER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 100,
                    'label' => $LNG['tech'][902] . ': ',
                    'default' => 33,
                ],
                'expo_ress_deut_chance' => [
                    'type' => ConfigFieldSchema::TYPE_SLIDER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 100,
                    'label' => $LNG['tech'][903] . ': ',
                    'default' => 17,
                ],
            ],
        ];
    }
}
