<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Game;

use Symfony\Component\HttpFoundation\Response;
use Twig\Markup;
use App\Game\Achievements\MiscAchievement;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HangarFilters;
use App\Game\Core\NumberAbbreviation;
use App\Game\Core\HTTP;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Session;
use App\Game\Core\Singleton;
use App\Game\Core\Theme;
use App\Game\Core\Universe;

/**
 * Player settings: account data, display, fleet and notification options, vacation mode and account deletion.
 */
#[\App\Routing\GamePage('game.php', 'settings')]
#[\App\Routing\GamePage('game.php', 'settings', uniPrefix: true)]
class SettingsController extends AbstractGameController
{
    public static $requireModule = 0;

    public function initialize(): void
    {
        parent::initialize();
    }

    public function show(): Response
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        $THEME = & $this->state->THEME;
        if ($USER['urlaubs_modus'] == 1) {
            $this->assign([
                'vacationUntil'         => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $USER['urlaubs_until'], $USER['timezone']),
                'delete'                => $USER['db_deaktjava'],
                'canVacationDisbaled'   => $USER['urlaubs_until'] < $this->state->runtime->timestamp,
            ]);

            return $this->render('game/page.settings.vacation.html.twig');
        } else {
            $db = $this->db;
            $sql = "SELECT prioMission1 as 'type_mission_1', prioMission2 as 'type_mission_2', 
            prioMission3 as 'type_mission_3',prioMission4 as 'type_mission_4', 
            prioMission5 as 'type_mission_5',prioMission6 as 'type_mission_6',
            prioMission7 as 'type_mission_7',prioMission8 as 'type_mission_8', 
            prioMission9 as 'type_mission_9', prioMission17 as 'type_mission_17'
            FROM %%USERS_SETTINGS%% WHERE user_id = :userID ;";
            $missionprios = $db->selectSingle($sql, [
                ':userID'   => $USER['id'],
            ]);
            $hangarFilters = $this->hangarFilters()->parse($USER['hangar_filters'] ?? null);

            $this->assign([
                'Selectors'         => [
                    'timezones' => get_timezone_selector(),
                    'decimalSeparators' => [
                        0 => "1.234.567,89",
                        1 => "1,234,567.89",
                    ],
                    'Sort' => [
                        0 => $LNG['op_sort_normal'],
                        1 => $LNG['op_sort_koords'],
                        2 => $LNG['op_sort_abc'],
                    ],
                    'SortUpDown' => [
                        0 => $LNG['op_sort_up'],
                        1 => $LNG['op_sort_down'],
                    ],
                    'ImageSkins' => Theme::getAvailableImageThemes(),
                    'CssSkins' => Theme::getAvailableCssThemes(),
                    'lang' => $LNG->getAllowedLangs(false),
                ],
                'adminProtection'               => $USER['authattack'],
                'discord_id'                    => $USER['discord_id'],
                'discord_hook'                  => $USER['discord_hook'],
                'userAuthlevel'                 => $USER['authlevel'],
                'changeNickTime'                => ($USER['uctime'] + USERNAME_CHANGETIME) - $this->state->runtime->timestamp,
                'username'                      => $USER['username'],
                'userLang'                      => $USER['lang'],
                'decimalSeparator'              => $USER['decimal_separator'],
                'theme'                         => $USER['dpath'],
                'imageTheme'                    => $USER['dpath'],
                'cssTheme'                      => $USER['css_dpath'] ?? $THEME->getCssThemeName(),
                'planetSort'                    => $USER['planet_sort'],
                'planetOrder'                   => $USER['planet_sort_order'],
                'spycount'                      => $USER['spio_anz'],
                'fleetActions'                  => $USER['settings_fleetactions'],
                'timezone'                      => $USER['timezone'],
                'delete'                        => $USER['db_deaktjava'],
                'showMessageCategory'           => $USER['showMessageCategory'],
                'enablePlanetStyles'            => $USER['enable_planet_styles'],
                'queueMessages'                 => $USER['hof'],
                'spyMessagesMode'               => $USER['spyMessagesMode'],
                'spyReportsCollapsed'           => $USER['spyReportsCollapsed'],
                'galaxySpy'                     => $USER['settings_esp'],
                'galaxyBuddyList'               => $USER['settings_bud'],
                'galaxyMissle'                  => $USER['settings_mis'],
                'galaxyMessage'                 => $USER['settings_wri'],
                'galaxySpyReport'               => $USER['settings_rep'],
                'spyReportFreshHours'           => $USER['spy_report_fresh_hours'],
                'spyReportRecentHours'          => $USER['spy_report_recent_hours'],
                'blockPM'                       => $USER['settings_blockPM'],
                'userid'                        => $USER['id'],
                'ref_active'                    => $this->configs->get()->ref_active,
                'SELF_URL'                      => $this->state->runtime->protocol . $this->state->runtime->httpHost . $this->state->runtime->httpRoot,
                'colors'                        => $USER['colors'],
                'signalColors'                  => $USER['signalColors'],
                'defaultColors'                 => $this->playerUtil()->player_colors(),
                'defaultSignalColors'           => $this->playerUtil()->player_signal_colors(),
                'stb_small_ress'                => $USER['stb_small_ress'],
                'stb_med_ress'                  => $USER['stb_med_ress'],
                'stb_big_ress'                  => $USER['stb_big_ress'],
                'stb_small_time'                => $USER['stb_small_time'],
                'stb_med_time'                  => $USER['stb_med_time'],
                'stb_big_time'                  => $USER['stb_big_time'],
                'stb_enabled'                   => $USER['stb_enabled'],
                'missionPrios'                  => $missionprios,
                'recordsOptIn'                  => $USER['records_optIn'],
                'messagesPerPage'               => $USER['messages_per_page'],
                'publish_achievement'           => $USER['publish_achievement'],
                'show_all_buildable_elements'   => $USER['show_all_buildable_elements'],
                'missing_requirements_opacity'  => $USER['missing_requirements_opacity'],
                'missing_resources_opacity'     => $USER['missing_resources_opacity'],
                'hide_useless'                  => $USER['hide_useless'],
                'hangarFiltersEnabled'          => $USER['hangar_filters_enabled'] ?? 0,
                'hangarGroupCivil'              => $this->hangarFilters()->hasGroup($hangarFilters, 'civil'),
                'hangarGroupMilitary'           => $this->hangarFilters()->hasGroup($hangarFilters, 'military'),
                'hangarGroupRecycler'           => $this->hangarFilters()->hasGroup($hangarFilters, 'recycler'),
                'hangarGroupAll'                => $this->hangarFilters()->hasGroup($hangarFilters, 'all'),
                'colonyWarn'                    => $USER['colonyWarn'],
                'noobInterface'                 => $USER['noob_interface'],
                'accountId'                     => $this->playerUtil()->get_account_id($USER['id']),
            ]);

            return $this->render('game/page.settings.default.html.twig');
        }
    }

    public function toggleVacation()
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        $PLANET = & $this->state->PLANET;
        if ($USER['urlaubs_modus'] == 1) {
            if ($USER['urlaubs_until'] > $this->state->runtime->timestamp) {
                $this->printMessage($LNG['op_cant_deactivate_vacation_mode'], [
                    [
                        'label' => $LNG['sys_forward'],
                        'url'   => 'game?page=settings',
                    ],
                ]);
            }

            $db = $this->db;
            $db->startTransaction();

            $sql = "SELECT id FROM %%USERS%% WHERE universe = :universe AND id = :userID FOR UPDATE;";
            $db->selectSingle($sql, [
                ':universe' => $this->universe()->current(),
                ':userID'   => $USER['id'],
            ]);

            $sql = "SELECT id FROM %%PLANETS%% WHERE universe = :universe AND id_owner = :userID FOR UPDATE;";
            $db->select($sql, [
                ':universe' => $this->universe()->current(),
                ':userID'   => $USER['id'],
            ]);

            $this->playerUtil()->disable_vmode($USER, $PLANET);
            $db->commit();

            $this->printMessage($LNG['op_options_vacation_deactivated'], [
                [
                    'label' => $LNG['sys_forward'],
                    'url'   => 'game?page=settings',
                ],
            ]);
        } else {
            if (!$this->checkVMode()) {
                $this->printMessage($LNG['op_cant_activate_vacation_mode'], [
                    [
                        'label' => $LNG['sys_back'],
                        'url'   => 'game?page=settings',
                    ],
                ]);
            }

            $this->playerUtil()->enable_vmode($USER, $PLANET);
            $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
            $statsHandler->setTimestamp($USER['id'], AdvancedStatsEnum::FIRST_VACATION, $this->state->runtime->timestamp);

            // translations are trusted HTML
            $text = $LNG['op_options_vacation_activated'];
            $text .= '<br>' . $LNG['op_options_no_other_settings_changed'];
            if ($this->viewHelpers()->isModuleAvailable(MODULE_VMODE_KICK, $USER['universe'])) {
                $text .= $LNG['tn_vacation_mode_kick'];
            }
            $this->printMessage(new Markup($text, 'UTF-8'), [
                [
                    'label' => $LNG['sys_forward'],
                    'url'   => 'game?page=settings',
                ],
            ]);
        }
    }

    public function toggleDelete()
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;

        $db = $this->db;
        $db->startTransaction();

        $sql = "SELECT id FROM %%USERS%% WHERE universe = :universe AND id = :userID FOR UPDATE;";
        $db->selectSingle($sql, [
            ':universe' => $this->universe()->current(),
            ':userID'   => $USER['id'],
        ]);

        if ($USER['db_deaktjava'] === 0) {
            $sql = "UPDATE %%USERS%% SET db_deaktjava = :timestamp WHERE id = :userID;";
            $db->update($sql, [
                ':userID'       => $USER['id'],
                ':timestamp'    => $this->state->runtime->timestamp,
            ]);
            $db->commit();

            $text = $LNG['op_options_deletion_activated'];
            if ($USER['urlaubs_modus'] == 0) { // only in standard settings menu
                $text .= '<br>' . $LNG['op_options_no_other_settings_changed'];
            }
            $this->printMessage(new Markup($text, 'UTF-8'), [
                [
                    'label' => $LNG['sys_forward'],
                    'url'   => 'game?page=settings',
                ],
            ]);
        } else {
            $sql = "UPDATE %%USERS%% SET db_deaktjava = 0 WHERE id = :userID;";
            $db->update($sql, [':userID'   => $USER['id']]);
            $db->commit();

            $text = $LNG['op_options_deletion_deactivated'];
            if ($USER['urlaubs_modus'] == 0) { // only in standard settings menu
                $text .= '<br>' . $LNG['op_options_no_other_settings_changed'];
            }
            $this->printMessage(new Markup($text, 'UTF-8'), [
                [
                    'label' => $LNG['sys_forward'],
                    'url'   => 'game?page=settings',
                ],
            ]);
        }
    }

    private function checkVMode()
    {
        $USER = & $this->state->USER;
        $PLANET = & $this->state->PLANET;
        /*
        if (!empty($USER['b_tech']) || !empty($PLANET['b_building']) || !empty($PLANET['b_hangar'])) {
            return false;
        }
        */
        $db = $this->db;

        $sql = "SELECT COUNT(*) as state FROM %%FLEETS%% WHERE `owner` = :userID FOR UPDATE;";
        $fleets = $db->selectSingle($sql, [':userID' => $USER['id']], 'state');

        if ($fleets != 0) {
            return false;
        }

        $db->startTransaction();
        // Lock the user row first and calc from it. The page-start copy is
        // stale under concurrent requests and SavePlanetToDB writes its b_tech*
        // fields back.
        $sql = "SELECT * FROM %%USERS%% WHERE id = :userID FOR UPDATE;";
        $u = $db->selectSingle($sql, [':userID' => $USER['id']]);
        if (empty($u) || $u['urlaubs_modus'] == 1) {
            $db->commit();
            return false;
        }
        $USER = array_merge($USER, $u);

        $sql = "SELECT * FROM %%PLANETS%% WHERE id_owner = :userID AND id != :planetID AND destruyed = 0 FOR UPDATE;";
        $query = $db->select($sql, [
            ':userID'   => $USER['id'],
            ':planetID' => $PLANET['id'],
        ]);

        foreach ($query as $CPLANET) {
            [$USER, $CPLANET] = $this->ecoObj->CalcResource($USER, $CPLANET, true);
            /*
            if (!empty($CPLANET['b_building']) || !empty($CPLANET['b_hangar'])) {
                $db->commit();
                return false;
            }
            */
            unset($CPLANET);
        }

        $db->commit();
        return true;
    }

    public function send()
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        $THEME = & $this->state->THEME;
        $adminprotection    = $this->http()->_GP('adminprotection', 0);
        $discord_id         = $this->http()->_GP('discord_id', '');
        $discord_hook       = $this->http()->_GP('discord_hook', '');

        $username           = $this->http()->_GP('username', $USER['username'], UTF8_SUPPORT);

        $timezone           = $this->http()->_GP('timezone', '');
        $language           = $this->http()->_GP('language', '');
        $decimalSeparator   = $this->http()->_GP('decimalSeparator', 0);

        $planetSort         = $this->http()->_GP('planetSort', 0);
        $planetOrder        = $this->http()->_GP('planetOrder', 0);

        $imageThemeInput    = $this->http()->_GP('imageTheme', '');
        if ($imageThemeInput === '') {
            $imageThemeInput = $this->http()->_GP('theme', $THEME->getImageThemeName());
        }
        $cssThemeInput      = $this->http()->_GP('cssTheme', $THEME->getCssThemeName());

        $showMessageCategory = $this->http()->_GP('showMessageCategory', 0);
        $enablePlanetStyles  = $this->http()->_GP('enablePlanetStyles', 0);
        $queueMessages       = $this->http()->_GP('queueMessages', 0);
        $spyMessagesMode     = $this->http()->_GP('spyMessagesMode', 0);
        $spyReportsCollapsed = $this->http()->_GP('spyReportsCollapsed', 0);
        $noobInterface       = $this->http()->_GP('noob_interface', 0);

        $show_all_buildable_elements  = $this->http()->_GP('show_all_buildable_elements', 0);
        $missing_requirements_opacity = $this->http()->_GP('missing_requirements_opacity', 0);
        $missing_resources_opacity    = $this->http()->_GP('missing_resources_opacity', 0);
        $hide_useless                 = $this->http()->_GP('hide_useless', 0);
        $hangarFiltersEnabled         = $this->http()->_GP('hangarFiltersEnabled', 0);
        $hangarFilterGroups = [];
        if ($this->http()->_GP('hangarFilterCivil', 0)) {
            $hangarFilterGroups[] = 'civil';
        }
        if ($this->http()->_GP('hangarFilterMilitary', 0)) {
            $hangarFilterGroups[] = 'military';
        }
        if ($this->http()->_GP('hangarFilterRecycler', 0)) {
            $hangarFilterGroups[] = 'recycler';
        }
        if ($this->http()->_GP('hangarFilterAll', 0)) {
            $hangarFilterGroups[] = 'all';
        }
        $hangarFilters = $this->hangarFilters()->encode($this->hangarFilters()->mergeGroups(
            $this->hangarFilters()->parse($USER['hangar_filters'] ?? null),
            $hangarFilterGroups,
        ));

        $spycount           = $this->http()->_GP('spycount', 1.0);
        $fleetactions       = $this->http()->_GP('fleetactions', 5);

        $galaxySpy          = $this->http()->_GP('galaxySpy', 0);
        $galaxyMessage      = $this->http()->_GP('galaxyMessage', 0);
        $galaxyBuddyList    = $this->http()->_GP('galaxyBuddyList', 0);
        $galaxyMissle       = $this->http()->_GP('galaxyMissle', 0);
        $galaxySpyReport    = $this->http()->_GP('galaxySpyReport', 0);
        $spyReportFreshHours  = (int) $this->http()->_GP('spyReportFreshHours', 24);
        $spyReportRecentHours = (int) $this->http()->_GP('spyReportRecentHours', 168);
        $blockPM            = $this->http()->_GP('blockPM', 0);

        $colorMission2friend = $this->http()->_GP('colorMission2friend', '#ff00ff');

        $colorMission1Own  = $this->http()->_GP('colorMission1Own', '#66cc33');
        $colorMission2Own  = $this->http()->_GP('colorMission2Own', '#339966');
        $colorMission3Own  = $this->http()->_GP('colorMission3Own', '#5bf1c2');
        $colorMission4Own  = $this->http()->_GP('colorMission4Own', '#cf79de');
        $colorMission5Own  = $this->http()->_GP('colorMission5Own', '#80a0c0');
        $colorMission6Own  = $this->http()->_GP('colorMission6Own', '#ffcc66');
        $colorMission7Own  = $this->http()->_GP('colorMission7Own', '#c1c1c1');
        $colorMission7ReturnOwn = $this->http()->_GP('colorMission7OwnReturn', '#cf79de');
        $colorMission8Own  = $this->http()->_GP('colorMission8Own', '#ceff68');
        $colorMission9Own  = $this->http()->_GP('colorMission9Own', '#ffff99');
        $colorMission10Own = $this->http()->_GP('colorMission10Own', '#ffcc66');
        $colorMission15Own = $this->http()->_GP('colorMission15Own', '#5bf1c2');
        $colorMission16Own = $this->http()->_GP('colorMission16Own', '#5bf1c2');
        $colorMission17Own = $this->http()->_GP('colorMission17Own', '#5bf1c2');
        $colorMissionReturnOwn = $this->http()->_GP('colorMissionReturnOwn', '#6e8eea');

        $colorMission1Foreign  = $this->http()->_GP('colorMission1Foreign', '#ff0000');
        $colorMission2Foreign  = $this->http()->_GP('colorMission2Foreign', '#aa0000');
        $colorMission3Foreign  = $this->http()->_GP('colorMission3Foreign', '#00ff00');
        $colorMission4Foreign  = $this->http()->_GP('colorMission4Foreign', '#ad57bc');
        $colorMission5Foreign  = $this->http()->_GP('colorMission5Foreign', '#3399cc');
        $colorMission6Foreign  = $this->http()->_GP('colorMission6Foreign', '#ff6600');
        $colorMission7Foreign  = $this->http()->_GP('colorMission7Foreign', '#00ff00');
        $colorMission8Foreign  = $this->http()->_GP('colorMission8Foreign', '#acdd46');
        $colorMission9Foreign  = $this->http()->_GP('colorMission9Foreign', '#dddd77');
        $colorMission10Foreign = $this->http()->_GP('colorMission10Foreign', '#ff6600');
        $colorMission15Foreign = $this->http()->_GP('colorMission15Foreign', '#39d0a0');
        $colorMission16Foreign = $this->http()->_GP('colorMission16Foreign', '#39d0a0');
        $colorMission17Foreign = $this->http()->_GP('colorMission17Foreign', '#39d0a0');
        $colorMissionReturnForeign = $this->http()->_GP('colorMissionReturnForeign', '#6e8eea');

        $colorStaticTimer = $this->http()->_GP('colorStaticTimer', '#ffff00');
        $colorPositive    = $this->http()->_GP('colorPositive', '#00ff00');
        $colorNegative    = $this->http()->_GP('colorNegative', '#ff0000');
        $colorNeutral     = $this->http()->_GP('colorNeutral', '#ffd600');

        $stb_small_ress     = $this->http()->_GP('stb_small_ress', 500);
        $stb_med_ress       = $this->http()->_GP('stb_med_ress', 3000);
        $stb_big_ress       = $this->http()->_GP('stb_big_ress', 7000);
        $stb_small_time     = $this->http()->_GP('stb_small_time', 1);
        $stb_med_time       = $this->http()->_GP('stb_med_time', 2);
        $stb_big_time       = $this->http()->_GP('stb_big_time', 3);
        $stb_enabled        = $this->http()->_GP('stb_enabled', 0);

        $prio1  = $this->http()->_GP('type_mission_1', 1);
        $prio2  = $this->http()->_GP('type_mission_2', 2);
        $prio3  = $this->http()->_GP('type_mission_3', 0);
        $prio4  = $this->http()->_GP('type_mission_4', 3);
        $prio5  = $this->http()->_GP('type_mission_5', 4);
        $prio6  = $this->http()->_GP('type_mission_6', 5);
        $prio7  = $this->http()->_GP('type_mission_7', 6);
        $prio8  = $this->http()->_GP('type_mission_8', 7);
        $prio9  = $this->http()->_GP('type_mission_9', 8);
        $prio17 = $this->http()->_GP('type_mission_17', 9);

        $recordsOptIn       = $this->http()->_GP('recordsOptIn', 0);
        $messagesPerPage    = $this->http()->_GP('messagesPerPage', 10);
        $colonyWarn         = $this->http()->_GP('colonyWarn', 0);

        if ($messagesPerPage > 50) {
            $messagesPerPage = 50;
        } elseif ($messagesPerPage < 3) {
            $messagesPerPage = 3;
        }

        $publish_achievement = $this->http()->_GP('publish_achievement', 0);
        // Vertify

        if ($USER['authlevel'] != AUTH_USR) {
            $adminprotection = ($adminprotection == 1) ? $USER['authlevel'] : 0;
        } else {
            $adminprotection = 0;
            $discord_id = '';
            $discord_hook = '';
        }

        $spycount           = min(max(round($spycount), 1), 4294967295);
        $fleetactions       = min(max($fleetactions, 1), 99);

        if ($spyReportFreshHours < 1 || $spyReportRecentHours < 1
            || $spyReportRecentHours <= $spyReportFreshHours
            || $spyReportRecentHours > 65535) {
            $this->printMessage($LNG['op_spy_report_time_invalid'], [
                [
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=settings',
                ],
            ]);
        }

        $language = array_key_exists($language, $LNG->getAllowedLangs(false)) ? $language : $LNG->getLanguage();

        // Follow the new language's default only when the stored separator is
        // still that language's default. A custom override is kept when the
        // dropdown was not changed in this save.
        $decimalSeparator = (int) $decimalSeparator;
        $previousSeparator = (int) $USER['decimal_separator'];
        if ($language !== $USER['lang']
            && $decimalSeparator === $previousSeparator
            && $previousSeparator === (new NumberAbbreviation())->defaultDecimalSeparatorForLang($USER['lang'])
        ) {
            $decimalSeparator = (new NumberAbbreviation())->defaultDecimalSeparatorForLang($language);
        }

        $availableImageThemes = Theme::getAvailableImageThemes();
        $availableCssThemes = Theme::getAvailableCssThemes();
        $imageTheme = array_key_exists($imageThemeInput, $availableImageThemes) ? $imageThemeInput : $THEME->getImageThemeName();
        $cssTheme = array_key_exists($cssThemeInput, $availableCssThemes) ? $cssThemeInput : $THEME->getCssThemeName();

        $db = $this->db;
        $db->startTransaction();

        $sql = "SELECT * FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% us ON u.id = us.user_id WHERE u.universe = :universe AND u.id = :id FOR UPDATE;";
        $db->select($sql, [
            ':universe' => $this->universe()->current(),
            ':id' => $USER['id'],
        ]);
        $sql = "SELECT * FROM %%PLANETS%% WHERE universe = :universe AND id_owner = :id FOR UPDATE;";
        $db->select($sql, [
            ':universe' => $this->universe()->current(),
            ':id' => $USER['id'],
        ]);

        if (!empty($username) && $USER['username'] != $username) {
            if (!$this->playerUtil()->isNameValid($username)) {
                $this->printMessage($LNG['op_user_name_no_alphanumeric'], [
                    [
                        'label' => $LNG['sys_back'],
                        'url'   => 'game?page=settings',
                    ],
                ]);
            } elseif ($USER['uctime'] >= $this->state->runtime->timestamp - USERNAME_CHANGETIME) {
                $this->printMessage($LNG['op_change_name_pro_week'], [
                    [
                        'label' => $LNG['sys_back'],
                        'url'   => 'game?page=settings',
                    ],
                ]);
            } else {
                $sql = "SELECT
					(SELECT COUNT(*) FROM %%USERS%% WHERE universe = :universe AND username = :username)
				AS count";
                $Count = $db->selectSingle($sql, [
                    ':universe' => $this->universe()->current(),
                    ':username' => $username,
                ], 'count');

                if (!empty($Count)) {
                    $this->printMessage(sprintf($LNG['op_change_name_exist'], $username), [
                        [
                            'label' => $LNG['sys_back'],
                            'url'   => 'game?page=settings',
                        ],
                    ]);
                } else {
                    $sql = "UPDATE %%USERS%% SET username = :username, uctime = :timestamp WHERE id = :userID;";
                    $db->update($sql, [
                        ':username' => $username,
                        ':userID'   => $USER['id'],
                        ':timestamp' => $this->state->runtime->timestamp,
                    ]);

                    $this->state->auth->logout();
                }
            }
        }

        $sql =  "UPDATE %%USERS%% SET
		authattack				        = :adminProtection,
		discord_id				        = :discord_id,
		discord_hook				    = :discord_hook
		WHERE id = :userID;";
        $db->update($sql, [
            ':adminProtection'              => $adminprotection,
            ':discord_id'                   => $discord_id,
            ':discord_hook'                 => $discord_hook,
            ':userID'                       => $USER['id'],
        ]);

        $sql =  "UPDATE %%USERS_SETTINGS%% SET
		dpath					        = :imageTheme,
        css_dpath                      = :cssTheme,
		timezone				        = :timezone,
		planet_sort				        = :planetSort,
		planet_sort_order		        = :planetOrder,
		spio_anz				        = :spyCount,
		settings_fleetactions	        = :fleetActions,
		settings_esp			        = :galaxySpy,
		settings_wri			        = :galaxyMessage,
		settings_bud			        = :galaxyBuddyList,
		settings_mis			        = :galaxyMissle,
		settings_rep			        = :galaxySpyReport,
		spy_report_fresh_hours          = :spyReportFreshHours,
		spy_report_recent_hours         = :spyReportRecentHours,
		settings_blockPM		        = :blockPM,
		lang					        = :language,
        decimal_separator               = :decimalSeparator,
        showMessageCategory             = :showMessageCategory,
        enable_planet_styles            = :enablePlanetStyles,
		hof						        = :queueMessages,
		spyMessagesMode			        = :spyMessagesMode,
		spyReportsCollapsed		        = :spyReportsCollapsed,
        show_all_buildable_elements     = :show_all_buildable_elements,
        missing_requirements_opacity    = :missing_requirements_opacity,
        missing_resources_opacity       = :missing_resources_opacity,
        hide_useless                    = :hide_useless,
        hangar_filters                  = :hangar_filters,
        hangar_filters_enabled          = :hangar_filters_enabled,
        colorMission2friend             = :colorMission2friend,
        colorMission1Own                = :colorMission1Own,
        colorMission2Own                = :colorMission2Own,
        colorMission3Own                = :colorMission3Own,
        colorMission4Own                = :colorMission4Own,
        colorMission5Own                = :colorMission5Own,
        colorMission6Own                = :colorMission6Own,
        colorMission7Own                = :colorMission7Own,
        colorMission7OwnReturn          = :colorMission7OwnReturn,
        colorMission8Own                = :colorMission8Own,
        colorMission9Own                = :colorMission9Own,
        colorMission10Own               = :colorMission10Own,
        colorMission15Own               = :colorMission15Own,
        colorMission16Own               = :colorMission16Own,
        colorMission17Own               = :colorMission17Own,
        colorMissionReturnOwn           = :colorMissionReturnOwn,
        colorMission1Foreign            = :colorMission1Foreign,
        colorMission2Foreign            = :colorMission2Foreign,
        colorMission3Foreign            = :colorMission3Foreign,
        colorMission4Foreign            = :colorMission4Foreign,
        colorMission5Foreign            = :colorMission5Foreign,
        colorMission6Foreign            = :colorMission6Foreign,
        colorMission7Foreign            = :colorMission7Foreign,
        colorMission8Foreign            = :colorMission8Foreign,
        colorMission9Foreign            = :colorMission9Foreign,
        colorMission10Foreign           = :colorMission10Foreign,
        colorMission15Foreign           = :colorMission15Foreign,
        colorMission16Foreign           = :colorMission16Foreign,
        colorMission17Foreign           = :colorMission17Foreign,
        colorMissionReturnForeign       = :colorMissionReturnForeign,
        colorStaticTimer                = :colorStaticTimer,
        colorPositive                   = :colorPositive,
        colorNegative                   = :colorNegative,
        colorNeutral                    = :colorNeutral,
        prioMission1                    = :prioMission1,
        prioMission2                    = :prioMission2,
        prioMission3                    = :prioMission3,
        prioMission4                    = :prioMission4,
        prioMission5                    = :prioMission5,
        prioMission6                    = :prioMission6,
        prioMission7                    = :prioMission7,
        prioMission8                    = :prioMission8,
        prioMission9                    = :prioMission9,
        prioMission17                   = :prioMission17,
        records_optIn                   = :records_optIn,
        messages_per_page               = :messages_per_page,
        stb_small_ress                  = :stb_small_ress,
        stb_med_ress                    = :stb_med_ress,
        stb_big_ress                    = :stb_big_ress,
        stb_small_time                  = :stb_small_time,
        stb_med_time                    = :stb_med_time,
        stb_big_time                    = :stb_big_time,
        stb_enabled                     = :stb_enabled,
        publish_achievement             = :publish_achievement,
        colonyWarn                      = :colonyWarn,
        noob_interface                  = :noob_interface
		WHERE user_id = :userID;";
        $db->update($sql, [
            ':imageTheme'                   => $imageTheme,
            ':cssTheme'                     => $cssTheme,
            ':timezone'                     => $timezone,
            ':planetSort'                   => $planetSort,
            ':planetOrder'                  => $planetOrder,
            ':spyCount'                     => $spycount,
            ':fleetActions'                 => $fleetactions,
            ':galaxySpy'                    => $galaxySpy,
            ':galaxyMessage'                => $galaxyMessage,
            ':galaxyBuddyList'              => $galaxyBuddyList,
            ':galaxyMissle'                 => $galaxyMissle,
            ':galaxySpyReport'              => $galaxySpyReport,
            ':spyReportFreshHours'          => $spyReportFreshHours,
            ':spyReportRecentHours'         => $spyReportRecentHours,
            ':blockPM'                      => $blockPM,
            ':language'                     => $language,
            ':decimalSeparator'             => $decimalSeparator,
            ':showMessageCategory'          => $showMessageCategory,
            ':enablePlanetStyles'           => $enablePlanetStyles,
            ':queueMessages'                => $queueMessages,
            ':spyMessagesMode'              => $spyMessagesMode,
            ':spyReportsCollapsed'          => $spyReportsCollapsed,
            ':show_all_buildable_elements'  => $show_all_buildable_elements,
            ':missing_requirements_opacity' => $missing_requirements_opacity,
            ':missing_resources_opacity'    => $missing_resources_opacity,
            ':hide_useless'                 => $hide_useless,
            ':hangar_filters'               => $hangarFilters,
            ':hangar_filters_enabled'       => $hangarFiltersEnabled,
            ':userID'                       => $USER['id'],
            ':colorMission2friend'          => $colorMission2friend,
            ':colorMission1Own'             => $colorMission1Own,
            ':colorMission2Own'             => $colorMission2Own,
            ':colorMission3Own'             => $colorMission3Own,
            ':colorMission4Own'             => $colorMission4Own,
            ':colorMission5Own'             => $colorMission5Own,
            ':colorMission6Own'             => $colorMission6Own,
            ':colorMission7Own'             => $colorMission7Own,
            ':colorMission7OwnReturn'       => $colorMission7ReturnOwn,
            ':colorMission8Own'             => $colorMission8Own,
            ':colorMission9Own'             => $colorMission9Own,
            ':colorMission10Own'            => $colorMission10Own,
            ':colorMission15Own'            => $colorMission15Own,
            ':colorMission16Own'            => $colorMission16Own,
            ':colorMission17Own'            => $colorMission17Own,
            ':colorMissionReturnOwn'        => $colorMissionReturnOwn,
            ':colorMission1Foreign'         => $colorMission1Foreign,
            ':colorMission2Foreign'         => $colorMission2Foreign,
            ':colorMission3Foreign'         => $colorMission3Foreign,
            ':colorMission4Foreign'         => $colorMission4Foreign,
            ':colorMission5Foreign'         => $colorMission5Foreign,
            ':colorMission6Foreign'         => $colorMission6Foreign,
            ':colorMission7Foreign'         => $colorMission7Foreign,
            ':colorMission8Foreign'         => $colorMission8Foreign,
            ':colorMission9Foreign'         => $colorMission9Foreign,
            ':colorMission10Foreign'        => $colorMission10Foreign,
            ':colorMission15Foreign'        => $colorMission15Foreign,
            ':colorMission16Foreign'        => $colorMission16Foreign,
            ':colorMission17Foreign'        => $colorMission17Foreign,
            ':colorMissionReturnForeign'    => $colorMissionReturnForeign,
            ':colorStaticTimer'             => $colorStaticTimer,
            ':colorPositive'                => $colorPositive,
            ':colorNegative'                => $colorNegative,
            ':colorNeutral'                 => $colorNeutral,
            ':prioMission1'                 => $prio1,
            ':prioMission2'                 => $prio2,
            ':prioMission3'                 => $prio3,
            ':prioMission4'                 => $prio4,
            ':prioMission5'                 => $prio5,
            ':prioMission6'                 => $prio6,
            ':prioMission7'                 => $prio7,
            ':prioMission8'                 => $prio8,
            ':prioMission9'                 => $prio9,
            ':prioMission17'                => $prio17,
            ':records_optIn'                => $recordsOptIn,
            ':messages_per_page'            => $messagesPerPage,
            ':stb_small_ress'               => $stb_small_ress,
            ':stb_med_ress'                 => $stb_med_ress,
            ':stb_big_ress'                 => $stb_big_ress,
            ':stb_small_time'               => $stb_small_time,
            ':stb_med_time'                 => $stb_med_time,
            ':stb_big_time'                 => $stb_big_time,
            ':stb_enabled'                  => $stb_enabled,
            ':publish_achievement'          => $publish_achievement,
            ':colonyWarn'                   => $colonyWarn,
            ':noob_interface'               => $noobInterface,
        ]);

        $USER['dpath'] = $imageTheme;
        $USER['css_dpath'] = $cssTheme;
        $THEME->setUserTheme($imageTheme, $cssTheme);

        $db->commit();

        $this->miscAchievements()->checkSettingsAchievements($USER['id'], $recordsOptIn);

        $this->printMessage($LNG['op_options_changed'], [
            [
                'label' => $LNG['sys_forward'],
                'url'   => 'game?page=settings',
            ],
        ]);
    }
}
