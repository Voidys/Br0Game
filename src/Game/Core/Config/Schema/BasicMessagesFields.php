<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class BasicMessagesFields
{
    /**
     * Basic server settings, category `messages` (Message Settings).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'messages' => [
                'message_delete_days' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 7,
                ],
            ],
        ];
    }
}
