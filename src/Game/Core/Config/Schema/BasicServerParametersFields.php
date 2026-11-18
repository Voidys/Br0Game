<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class BasicServerParametersFields
{
    /**
     * Basic server settings, category `server_parameters` (Basic Server Settings).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        // split into parts only to keep each method short; `+` keeps the field order
        return ['server_parameters' => $this->part1($LNG) + $this->part2($LNG)];
    }

    /**
     * Fields of category `server_parameters`, part 1 of 2 (see fields()).
     *
     * @return array<string, array<string, mixed>> field => definition
     */
    private function part1(Language $LNG): array
    {
        return [
            'game_name' => [
                'type' => ConfigFieldSchema::TYPE_TEXT,
                'maxlength' => 255,
                'size' => 60,
                'transform' => 'string',
                'sanitize' => true,
                'default' => 'V0Game',
            ],
            'default_theme' => [
                'type' => ConfigFieldSchema::TYPE_SELECT,
                'transform' => 'string',
                'options' => \App\Game\Core\Theme::getAvailableCssThemes(),
                'default' => 'v0game',
            ],
            // AGPL: players of a (modified) server can get its source code here (footer link)
            'source_url' => [
                'type' => ConfigFieldSchema::TYPE_TEXT,
                'maxlength' => 255,
                'size' => 60,
                'transform' => 'string',
                'sanitize' => true,
                'default' => 'https://github.com/Voidys/V0Game',
            ],
            'version' => [
                'type' => ConfigFieldSchema::TYPE_NONE,
                'default' => '',
            ],
            'timezone' => [
                'type' => ConfigFieldSchema::TYPE_SELECT,
                'transform' => 'string',
                'options' => get_timezone_selector(),
                'default' => date_default_timezone_get() ?: 'Europe/London',
            ],
            'dst' => [ // Daylight Saving Time
                'type' => ConfigFieldSchema::TYPE_SELECT,
                'transform' => 'int',
                'options' => [
                    0 => $LNG['se_dst_sel'][0], //No
                    1 => $LNG['se_dst_sel'][1], //Yes
                    2 => $LNG['se_dst_sel'][2], //Auto
                ],
                'default' => 2,
            ],
            'forum_url' => [
                'type' => ConfigFieldSchema::TYPE_TEXT,
                'maxlength' => 255,
                'size' => 60,
                'transform' => 'string',
                'sanitize' => true,
                'default' => '',
            ],
            'git_issues_link' => [
                'type' => ConfigFieldSchema::TYPE_TEXT,
                'maxlength' => 255,
                'size' => 60,
                'transform' => 'string',
                'sanitize' => true,
                'default' => 'https://github.com/Voidys/V0Game/issues',
            ],
        ];
    }

    /**
     * Fields of category `server_parameters`, part 2 of 2 (see fields()).
     *
     * @return array<string, array<string, mixed>> field => definition
     */
    private function part2(Language $LNG): array
    {
        return [
            // fonts found in public/styles/resource/fonts/; read through App\Game\Core\Fonts::resolveTtf() (safe fallback)
            'ttf_file' => [
                'type' => ConfigFieldSchema::TYPE_SELECT,
                'transform' => 'string',
                'options' => \App\Game\Core\Fonts::ttfOptions() ?: [\App\Game\Core\Fonts::DEFAULT_TTF => basename(\App\Game\Core\Fonts::DEFAULT_TTF)],
                'default' => \App\Game\Core\Fonts::DEFAULT_TTF,
            ],
            // admin dashboard asks GitHub for the latest release (outbound request, see App\Runtime\UpdateCheck)
            'update_check' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => true,
            ],
            'session_handler' => [
                'type' => ConfigFieldSchema::TYPE_SELECT,
                'transform' => 'string',
                'options' => [
                    'file' => 'File',
                    'redis' => 'Redis',
                ],
                'default' => 'file',
            ],
            'redis_connection_url' => [
                'type' => ConfigFieldSchema::TYPE_TEXT,
                'maxlength' => 255,
                'size' => 60,
                'transform' => 'string',
                'sanitize' => true,
                'default' => '',
            ],
        ];
    }
}
