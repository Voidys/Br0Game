<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class BasicRecaptchaFields
{
    /**
     * Basic server settings, category `recaptcha`.
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'recaptcha' => [
                'recaptcha_priv_key' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'secret' => true,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'recaptcha_pub_key' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
            ],
        ];
    }
}
