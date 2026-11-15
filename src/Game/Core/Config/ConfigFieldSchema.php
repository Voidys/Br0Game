<?php

namespace App\Game\Core\Config;

use App\Game\Core\Language;
use App\Game\Core\Singleton;

/**
 * Config Field Schema Definition
 * Centralized definition of all config fields for easier maintenance
 */
class ConfigFieldSchema
{
    public const CONFIG_TYPE_BASIC = 'basic';
    public const CONFIG_TYPE_UNI = 'uni';

    // Field types
    public const TYPE_NONE = 'none';
    public const TYPE_TEXT = 'text';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_CHECKBOX = 'checkbox';
    public const TYPE_NUMBER = 'number';
    public const TYPE_SELECT = 'select';
    public const TYPE_FLOAT = 'float';
    public const TYPE_SLIDER = 'slider';

    private array $schema = [];
    private array $fields = [];

    /**
     * @param Singleton $state game state: its language labels the fields (an English fallback is
     *                         loaded into it when none is set yet, as before)
     */
    public function __construct(
        string $config_type,
        private Singleton $state,
        private \App\Game\Core\ConfigRegistry $configs,
    ) {
        if ($config_type === self::CONFIG_TYPE_BASIC) {
            $this->schema = $this->getBasicSchema();
        } elseif ($config_type === self::CONFIG_TYPE_UNI) {
            $this->schema = $this->getUniSchema();
        } else {
            $this->schema = [];
        }

        foreach ($this->schema as $category => $categoryFields) {
            $this->fields = array_merge($this->fields, $categoryFields);
        }
    }

    /**
     * Basic config fields with metadata
     *
     * Each entry in the returned array represents a category having multiple 
     * configuration keys and their options.
     * The category keys and config keys are used for translation keys in the 
     * format: se_{category_key} and se_{category_key}_info for tooltips.
     * Example structure:
     *
     * ```
     *   'config_key' => [                      // Configuration key
     *       'type'      => self::TYPE_TEXT,    // Field type: none, text, textarea, checkbox, number, select, float, slider
     *       'default'   => '',                 // Default value, if not set
     *       'options'   => [...],              // For select: selection options
     *       'maxlength' => 255,                // For text/number: maximum length
     *       'size'      => 60,                 // For text: size of the input field
     *       'transform' => 'string',           // Transformation function: int, float, string, bool, int_multiply_2500 (example for special transformations)
     *       'sanitize'  => true,               // For text: whether the value should be sanitized before saving
     *       'min'       => 0,                  // For slider/number: minimum value
     *       'max'       => 100,                // For slider/number: maximum value
     *       'label'     => '...',              // For slider: label for displaying the slider value
     *       'unit'      => '...',              // Optional unit that is displayed in the template
     *       'tooltip'   => '...',              // Optional tooltip text for the field
     *   ]
     * ```
     *
     * @return array
     */
    private function getBasicSchema(): array
    {
        $LNG = &$this->state->LNG;
        if (is_null($LNG)) {
            $LNG = new Language($this->configs, 'en'); // Fallback to avoid errors if language is not loaded
            $LNG->includeData(['L18N', 'INGAME', 'ADMIN', 'PUBLIC', 'CUSTOM']);
        }
        $LNG->includeData(['ADMIN']);

        return [
            // Basic Server Settings
            'server_parameters' => [
                'game_name' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => 'Br0Game',
                ],
                'default_theme' => [
                    'type' => self::TYPE_SELECT,
                    'transform' => 'string',
                    'options' => \App\Game\Core\Theme::getAvailableCssThemes(),
                    'default' => 'br0game',
                ],
                // AGPL: players of a (modified) server can get its source code here (footer link)
                'source_url' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => 'https://github.com/Voidys/Br0Game',
                ],
                'version' => [
                    'type' => self::TYPE_NONE,
                    'default' => '',
                ],
                'timezone' => [
                    'type' => self::TYPE_SELECT,
                    'transform' => 'string',
                    'options' => get_timezone_selector(),
                    'default' => @date_default_timezone_get() ?: 'Europe/London',
                ],
                'dst' => [ // Daylight Saving Time
                    'type' => self::TYPE_SELECT,
                    'transform' => 'int',
                    'options' => [
                        0 => $LNG['se_dst_sel'][0], //No
                        1 => $LNG['se_dst_sel'][1], //Yes
                        2 => $LNG['se_dst_sel'][2], //Auto
                    ],
                    'default' => 2,
                ],
                'forum_url' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'git_issues_link' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => 'https://github.com/Voidys/Br0Game/issues',
                ],
                'ttf_file' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'default' => 'public/styles/resource/fonts/DroidSansMono.ttf',
                ],
                'session_handler' => [
                    'type' => self::TYPE_SELECT,
                    'transform' => 'string',
                    'options' => [
                        'file' => 'File',
                        'redis' => 'Redis',
                    ],
                    'default' => 'file',
                ],
                'redis_connection_url' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
            ],

            // Discord Webhooks
            'discord_hooks' => [
                'discord_logs_hook' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 500,
                    'size' => 60,
                    'transform' => 'string',
                    'default' => '',
                ],
                'discord_exceptions_hook' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 500,
                    'size' => 60,
                    'transform' => 'string',
                    'default' => '',
                ],
                'discord_tickets_hook' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 500,
                    'size' => 60,
                    'transform' => 'string',
                    'default' => '',
                ],
            ],

            // Mail Settings
            'smtp' => [
                'mail_active' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'smtp_sendmail' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'mail_use' => [
                    'type' => self::TYPE_SELECT,
                    'transform' => 'int',
                    'options' => [
                        0 => $LNG['se_mail_sel'][0], //PHP mail() Function
                        2 => $LNG['se_mail_sel'][2], //SMTP
                    ],
                    'default' => 0,
                ],
                'smail_path' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'default' => '/usr/sbin/sendmail',
                ],
                'smtp_ssl' => [
                    'type' => self::TYPE_SELECT,
                    'transform' => 'string',
                    'options' => [
                        '' => $LNG['se_smtp_ssl_sel'][0], // None
                        'ssl' => $LNG['se_smtp_ssl_sel'][1], // SSL
                        'tls' => $LNG['se_smtp_ssl_sel'][2], // TLS
                    ],
                    'default' => '',
                ],
                'smtp_host' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'smtp_port' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 5,
                    'size' => 5,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 0,
                ],
                'smtp_user' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'smtp_pass' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
            ],

            // User Settings
            'user_settings' => [
                'del_oldstuff' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'unit' => $LNG['se_days'],
                    'default' => 30,
                ],
                'del_user_manually' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'unit' => $LNG['se_days'],
                    'default' => 7,
                ],
                'user_valid' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'sendmail_inactive' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'del_user_sendmail' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'unit' => $LNG['se_days'],
                    'default' => 21,
                ],
            ],

            // Message Settings
            'messages' => [
                'message_delete_days' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 7,
                ],
            ],

            'recaptcha' => [
                'recaptcha_priv_key' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'recaptcha_pub_key' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
            ],

            // Legal/Disclaimer
            'disclaimer' => [
                'disclamer_address' => [
                    'type' => self::TYPE_TEXTAREA,
                    'cols' => 80,
                    'rows' => 3,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'disclamer_phone' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'disclamer_mail' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 255,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'disclamer_notice' => [
                    'type' => self::TYPE_TEXTAREA,
                    'cols' => 80,
                    'rows' => 5,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
            ],

            // Statistics
            'statistics' => [
                'stat_settings' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 7,
                    'size' => 7,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 1000,
                ],
                'stat' => [
                    'type' => self::TYPE_SELECT,
                    'transform' => 'int',
                    'options' => [
                        1 => $LNG['se_stat_sel'][1], //Yes
                        2 => $LNG['se_stat_sel'][2], //Hide admins
                        0 => $LNG['se_stat_sel'][0], //No
                    ],
                    'default' => 0,
                ],
                'stat_level' => [
                    'type' => self::TYPE_SELECT,
                    'transform' => 'int',
                    'options' => [
                        1 => $LNG['se_rank'][1], //Moderator
                        2 => $LNG['se_rank'][2], //Operator
                        3 => $LNG['se_rank'][3], //Administrator
                    ],
                    'default' => 2,
                ],
            ],
        ];
    }

    /**
     * All config fields with metadata (universe-specific)
     *
     * Each entry in the returned array represents a category having multiple 
     * configuration keys and their options.
     * The category keys and config keys are used for translation keys in the 
     * format: se_{category_key} and se_{category_key}_info for tooltips.
     * Example structure:
     *
     * ```
     *   'config_key' => [                      // Configuration key
     *       'type'      => self::TYPE_TEXT,    // Field type: text, textarea, checkbox, number, select, float, slider
     *       'default'   => '',                 // Default value, if not set
     *       'options'   => [...],              // For select: selection options
     *       'maxlength' => 255,                // For text/number: maximum length
     *       'size'      => 60,                 // For text: size of the input field
     *       'transform' => 'string',           // Transformation function: int, float, string, bool, int_multiply_2500 (example for special transformations)
     *       'sanitize'  => true,               // For text: whether the value should be sanitized before saving
     *       'min'       => 0,                  // For slider/number: minimum value
     *       'max'       => 100,                // For slider/number: maximum value
     *       'label'     => '...',              // For slider: label for displaying the slider value
     *       'unit'      => '...',              // Optional unit that is displayed in the template
     *       'tooltip'   => '...',              // Optional tooltip text for the field
     *   ]
     * ```
     *
     * @return array
     */
    private function getUniSchema(): array
    {
        $LNG = &$this->state->LNG;
        if (is_null($LNG)) {
            $LNG = new Language($this->configs, 'en'); // Fallback to avoid errors if language is not loaded
            $LNG->includeData(['L18N', 'INGAME', 'ADMIN', 'PUBLIC', 'CUSTOM']);
        }
        $LNG->includeData(['ADMIN']);

        return [
            // Server Parameter
            'server_parameters' => [
                'last_galaxy_pos' => [
                    'type' => self::TYPE_NONE,
                    'default' => '1',
                ],
                'last_system_pos' => [
                    'type' => self::TYPE_NONE,
                    'default' => '1',
                ],
                'last_planet_pos' => [
                    'type' => self::TYPE_NONE,
                    'default' => '1',
                ],
                'users_amount' => [
                    'type' => self::TYPE_NONE,
                    'default' => '1',
                ],
                'uni_name' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 60,
                    'size' => 60,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => $LNG['fcm_universe'] . ' 1',
                ],
                'start' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 15,
                    'size' => 15,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => date('d.m.Y'),
                ],
                'start_time' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 15,
                    'size' => 15,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => date('H:i:s'),
                ],
                'end' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 15,
                    'size' => 15,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'end_time' => [
                    'type' => self::TYPE_TEXT,
                    'maxlength' => 15,
                    'size' => 15,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'lang' => [
                    'type' => self::TYPE_SELECT,
                    'options' => $LNG->getAllowedLangs(false),
                    'default' => $LNG->getLanguage(),
                ],
                'catch_up' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'building_speed' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 5,
                    'transform' => 'int_multiply_2500',
                    'min' => 0,
                    'tooltip' => $LNG['se_normal_speed'],
                    'default' => 2500,
                ],
                'shipyard_speed' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 5,
                    'transform' => 'int_multiply_2500',
                    'min' => 0,
                    'tooltip' => $LNG['se_normal_speed'],
                    'default' => 2500,
                ],
                'research_speed' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 5,
                    'transform' => 'int_multiply_2500',
                    'min' => 0,
                    'tooltip' => $LNG['se_normal_speed'],
                    'default' => 2500,
                ],
                'fleet_speed' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 5,
                    'transform' => 'int_multiply_2500',
                    'min' => 0,
                    'default' => 2500,
                ],
                'resource_multiplier' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 5,
                    'transform' => 'int_multiply_2500',
                    'min' => 0,
                    'default' => 2500,
                ],
                'storage_multiplier' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'default' => 1,
                ],
                'max_overflow' => [
                    'type' => self::TYPE_FLOAT,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'float',
                    'default' => 1.0,
                ],
                'expo_hold_multiplier' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 1,
                ],
                'energy_multiplier' => [
                    'type' => self::TYPE_NUMBER,
                    'transform' => 'int',
                    'default' => 1,
                ],
                'catchup_percent' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 100,
                    'unit' => '%',
                    'default' => 50,
                ],
                'missile_range_factor' => [
                    'type' => self::TYPE_FLOAT,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'float',
                    'default' => 1.0,
                ],
                'phalanx_range_factor' => [
                    'type' => self::TYPE_FLOAT,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'float',
                    'default' => 1.0,
                ],
                'del_user_automatic' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 2,
                    'transform' => 'int',
                    'min' => 0,
                    'unit' => $LNG['se_days'],
                    'default' => 90,
                ],
                'vmode_min_time' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'int',
                    'default' => 172800,
                ],
                'alliance_create_min_points' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 20,
                    'size' => 25,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 0,
                ],
                'debug' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'uni_status' => [
                    'type' => self::TYPE_SELECT,
                    'options' => [
                        '0' => $LNG['se_uni_status_regopen_gameopen'],
                        '1' => $LNG['se_uni_status_regclosed_gameclosed'],
                        '2' => $LNG['se_uni_status_regopen_gameclosed'],
                        '3' => $LNG['se_uni_status_regclosed_gameopen'],
                    ],
                    'default' => 1,
                ],
                'prepare_text' => [
                    'type' => self::TYPE_TEXTAREA,
                    'cols' => 80,
                    'rows' => 5,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => '',
                ],
                'close_reason' => [
                    'type' => self::TYPE_TEXTAREA,
                    'cols' => 80,
                    'rows' => 5,
                    'transform' => 'string',
                    'sanitize' => true,
                    'default' => $LNG['sql_close_reason'],
                ],
                'statsjson_delay' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 604800,
                ],
            ],

            // Build List
            'buildlist' => [
                'max_elements_build' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 5,
                ],
                'max_elements_tech' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 2,
                ],
                'max_elements_ships' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 10,
                ],
                'max_fleet_per_build' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 20,
                    'size' => 15,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 1000000,
                ],
                'min_build_time' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 2,
                    'size' => 5,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 1,
                ],
            ],

            // Galaxy Parameters
            'galaxy_parameters' => [
                'deuterium_cost_galaxy' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 10,
                ],
                'max_galaxy' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 9,
                ],
                'max_system' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 5,
                    'size' => 5,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 400,
                ],
                'max_planets' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 15,
                ],
                'uni_type' => [
                    'type' => self::TYPE_SELECT,
                    'options' => [
                        '0' => $LNG['se_line_uni'],
                        '1' => $LNG['se_ring_uni'],
                        '2' => $LNG['se_sphere_uni'],
                    ],
                    'default' => 0,
                ],
                'galaxy_type' => [
                    'type' => self::TYPE_SELECT,
                    'options' => [
                        '0' => $LNG['se_line_galaxy'],
                        '1' => $LNG['se_ring_galaxy'],
                        '2' => $LNG['se_sphere_galaxy'],
                    ],
                    'default' => 0,
                ],
                'planet_creation' => [
                    'type' => self::TYPE_SELECT,
                    'options' => [
                        '0' => $LNG['se_iter_planet_creation'],
                        '1' => $LNG['se_random_planet_creation'],
                        '2' => $LNG['se_block_planet_creation'],
                    ],
                    'default' => 0,
                ],
                'galaxy_distance_factor' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'int',
                    'default' => 20000,
                ],
                'system_distance_factor' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'int',
                    'default' => 95,
                ],
                'system_distance' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'int',
                    'default' => 2700,
                ],
                'planet_distance_factor' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'int',
                    'default' => 5,
                ],
                'planet_distance' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'int',
                    'default' => 1000,
                ],
            ],

            // Colonisation
            'colonisation_config' => [
                'max_initial_planets' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 1,
                ],
                'max_additional_planets' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 11,
                ],
                'planets_per_tech' => [
                    'type' => self::TYPE_FLOAT,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'float',
                    'default' => 0.5,
                ],
                'planet_size_factor' => [
                    'type' => self::TYPE_FLOAT,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'float',
                    'default' => 1.0,
                ],
                'all_planet_pictures' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
            ],

            // Planet Parameters
            'planet_parameters' => [
                'metal_start' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 500,
                ],
                'crystal_start' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 500,
                ],
                'deuterium_start' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 0,
                ],
                'initial_fields' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 10,
                    'size' => 10,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 163,
                ],
                'initial_temp' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 10,
                    'size' => 10,
                    'transform' => 'int',
                    'min' => -273,
                    'default' => 50,
                ],
                'metal_basic_income' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 10,
                    'size' => 10,
                    'transform' => 'int',
                    'unit' => $LNG['se_per_hour'],
                    'default' => 20,
                ],
                'crystal_basic_income' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 10,
                    'size' => 10,
                    'transform' => 'int',
                    'unit' => $LNG['se_per_hour'],
                    'default' => 10,
                ],
                'deuterium_basic_income' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 10,
                    'size' => 10,
                    'transform' => 'int',
                    'unit' => $LNG['se_per_hour'],
                    'default' => 0,
                ],
                'energy_basic_income' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 10,
                    'size' => 10,
                    'transform' => 'int',
                    'unit' => $LNG['se_per_hour'],
                    'default' => 0,
                ],
                'planet_ressource_bonus' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'moon_factor' => [
                    'type' => self::TYPE_FLOAT,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'float',
                    'default' => 1.0,
                ],
                'moon_chance' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 20,
                ],
                'debris_moon' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'moon_size_factor' => [
                    'type' => self::TYPE_FLOAT,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'float',
                    'default' => 1.0,
                ],
                'cascading_moon_chance' => [
                    'type' => self::TYPE_FLOAT,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'float',
                    'unit' => '%',
                    'default' => 0.0,
                ],
            ],

            // Buildings
            'buildings' => [
                'factor_university' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'default' => 8,
                ],
                'silo_factor' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 2,
                    'size' => 2,
                    'transform' => 'int',
                    'default' => 1,
                ],
                'jumpgate_factor' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 11,
                    'size' => 11,
                    'transform' => 'int',
                    'default' => 1,
                ],
            ],

            // Expedition
            'expedition_parameters' => [
                'expo_ress_met_chance' => [
                    'type' => self::TYPE_SLIDER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 100,
                    'label' => $LNG['tech'][901] . ': ',
                    'default' => 50,
                ],
                'expo_ress_crys_chance' => [
                    'type' => self::TYPE_SLIDER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 100,
                    'label' => $LNG['tech'][902] . ': ',
                    'default' => 33,
                ],
                'expo_ress_deut_chance' => [
                    'type' => self::TYPE_SLIDER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 100,
                    'label' => $LNG['tech'][903] . ': ',
                    'default' => 17,
                ],
            ],

            // Attacks
            'attacks' => [
                'fleet_debris_percentage' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 30,
                ],
                'def_debris_percentage' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 0,
                ],
                'max_fleets_per_acs' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 16,
                ],
                'max_participants_per_acs' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 5,
                ],
                'noob_protection' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => true,
                ],
                'noob_protection_time' => [
                    'type' => self::TYPE_NUMBER,
                    'transform' => 'int',
                    'default' => 5000,
                ],
                'noob_protection_multi' => [
                    'type' => self::TYPE_FLOAT,
                    'transform' => 'float',
                    'default' => 5,
                ],
                'noob_protection_outlaw' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'noob_inactives' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'rebuild_protection' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'adm_attack' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'bash_per_user' => [
                    'type' => self::TYPE_NUMBER,
                    'transform' => 'int',
                    'default' => 0,
                ],
                'bash_per_planet' => [
                    'type' => self::TYPE_NUMBER,
                    'transform' => 'int',
                    'default' => 6,
                ],
                'wreckfield_min_units' => [
                    'type' => self::TYPE_NUMBER,
                    'transform' => 'int',
                    'default' => 150000,
                    'min' => 0,
                ],
                'wreckfield_min_percent' => [
                    'type' => self::TYPE_NUMBER,
                    'transform' => 'int',
                    'default' => 75,
                    'unit' => '%',
                    'min' => 0,
                    'max' => 100,
                ],
                'wreckfield_cooldown' => [
                    'type' => self::TYPE_NUMBER,
                    'transform' => 'int',
                    'default' => 0,
                    'min' => 0,
                ],
            ],

            'piratebase' => [
                'piratebase_min_points' => [
                    'type' => self::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 10000,
                ],
                'piratebase_fleet_percentage_min' => [
                    'type' => self::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 200,
                    'unit' => '%',
                    'default' => 50,
                ],
                'piratebase_fleet_percentage_max' => [
                    'type' => self::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 200,
                    'unit' => '%',
                    'default' => 75,
                ],
                'piratebase_mainplanet_only' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'piratebase_ress_hours' => [
                    'type' => self::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'unit' => $LNG['se_per_hour'],
                    'default' => 24,
                ],
            ],

            // Trade
            'trader' => [
                'trade_allowed_ships' => [
                    'type' => self::TYPE_TEXT,
                    'transform' => 'string',
                    'default' => '202,203,204,205,206,207,208,209,210,211,212,213,214,215',
                ],
                'trade_charge' => [
                    'type' => self::TYPE_FLOAT,
                    'transform' => 'float',
                    'unit' => '%',
                    'default' => 30,
                ],
            ],

            // News
            'news' => [
                'overview_news_frame' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => true,
                ],
                'overview_news_text' => [
                    'type' => self::TYPE_TEXTAREA,
                    'cols' => 80,
                    'rows' => 5,
                    'transform' => 'string',
                    'default' => $LNG['sql_welcome'],
                ],
            ],

            // Referral
            'ref' => [
                'ref_active' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'ref_bonus' => [
                    'type' => self::TYPE_FLOAT,
                    'maxlength' => 6,
                    'size' => 8,
                    'transform' => 'float',
                    'default' => 1000,
                ],
                'ref_minpoints' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 20,
                    'size' => 25,
                    'transform' => 'int',
                    'default' => 2000,
                ],
                'ref_max_referals' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 6,
                    'size' => 8,
                    'transform' => 'int',
                    'default' => 5,
                ],
            ],

            // Bots
            'bots' => [
                'bots_enabled' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'bots_max_count' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 50,
                ],
                'bots_ticks_per_run' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 3,
                    'size' => 3,
                    'transform' => 'int',
                    'min' => 1,
                    'default' => 25,
                ],
                'bots_llm_enabled' => [
                    'type' => self::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'bots_llm_daily_limit' => [
                    'type' => self::TYPE_NUMBER,
                    'maxlength' => 4,
                    'size' => 4,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 20,
                ],
            ],
        ];
    }

    /**
     * Returns all categories with their respective fields and metadata
     *
     * @return array
     */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /**
     * Returns all fields (without categories)
     *
     * @return array
     */
    public function getAllFields(): array
    {
        return $this->fields;
    }

    /**
     * Returns a specific field
     */
    public function getField(string $fieldName): ?array
    {
        return $this->fields[$fieldName] ?? null;
    }
}
