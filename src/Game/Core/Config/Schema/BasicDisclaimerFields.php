<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class BasicDisclaimerFields
{
    /**
     * Basic server settings, category `disclaimer` (Legal/Disclaimer).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'disclaimer' => [
                'disclamer_address' => [
                    'type' => ConfigFieldSchema::TYPE_TEXTAREA,
                    'cols' => 80,
                    'rows' => 3,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'disclamer_phone' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'disclamer_mail' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'disclamer_notice' => [
                    'type' => ConfigFieldSchema::TYPE_TEXTAREA,
                    'cols' => 80,
                    'rows' => 5,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
            ],
        ];
    }
}
