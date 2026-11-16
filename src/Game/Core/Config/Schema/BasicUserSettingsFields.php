<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class BasicUserSettingsFields
{
    /**
     * Basic server settings, category `user_settings` (User Settings).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'user_settings' => [
                'del_oldstuff' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'unit' => $LNG['se_days'],
                    'default' => 30,
                ],
                'del_user_manually' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'unit' => $LNG['se_days'],
                    'default' => 7,
                ],
                'user_valid' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'sendmail_inactive' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'del_user_sendmail' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'unit' => $LNG['se_days'],
                    'default' => 21,
                ],
            ],
        ];
    }
}
