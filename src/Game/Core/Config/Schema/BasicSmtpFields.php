<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class BasicSmtpFields
{
    /**
     * Basic server settings, category `smtp` (Mail Settings).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'smtp' => [
                'mail_active' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'smtp_sendmail' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'mail_use' => [
                    'type' => ConfigFieldSchema::TYPE_SELECT,
                    'transform' => 'int',
                    'options' => [
                        0 => $LNG['se_mail_sel'][0], //PHP mail() Function
                        2 => $LNG['se_mail_sel'][2], //SMTP
                    ],
                    'default' => 0,
                ],
                'smail_path' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'default' => '/usr/sbin/sendmail',
                ],
                'smtp_ssl' => [
                    'type' => ConfigFieldSchema::TYPE_SELECT,
                    'transform' => 'string',
                    'options' => [
                        '' => $LNG['se_smtp_ssl_sel'][0], // None
                        'ssl' => $LNG['se_smtp_ssl_sel'][1], // SSL
                        'tls' => $LNG['se_smtp_ssl_sel'][2], // TLS
                    ],
                    'default' => '',
                ],
                'smtp_host' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'smtp_port' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 5,
                    'size' => 5,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 0,
                ],
                'smtp_user' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'smtp_pass' => [
                    'type' => ConfigFieldSchema::TYPE_TEXT,
                    'secret' => true,
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
