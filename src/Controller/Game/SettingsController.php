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

use App\Game\Settings\SettingsRefused;
use App\Game\Settings\SettingsUpdater;
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

    /**
     * Settings page of the player: while in vacation mode only the vacation view is rendered, otherwise the options form (display, fleet, notification
     * settings and the buttons for vacation mode and account deletion).
     */
    public function show(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $THEME = $this->state->THEME;
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

    /**
     * Switches vacation mode. Leaving is only possible after `urlaubs_until`; entering needs checkVMode() to pass. Locks user and planet
     * rows, then PlayerUtil::enable_vmode / disable_vmode, and ends with a message.
     */
    public function toggleVacation()
    {
        $LNG = $this->state->LNG;

        if ($this->state->USER['urlaubs_modus'] == 1) {
            if ($this->state->USER['urlaubs_until'] > $this->state->runtime->timestamp) {
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
                ':userID'   => $this->state->USER['id'],
            ]);

            $sql = "SELECT id FROM %%PLANETS%% WHERE universe = :universe AND id_owner = :userID FOR UPDATE;";
            $db->select($sql, [
                ':universe' => $this->universe()->current(),
                ':userID'   => $this->state->USER['id'],
            ]);

            $this->playerUtil()->disable_vmode($this->state->USER, $this->state->PLANET);
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

            $this->playerUtil()->enable_vmode($this->state->USER, $this->state->PLANET);
            $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
            $statsHandler->setTimestamp($this->state->USER['id'], AdvancedStatsEnum::FIRST_VACATION, $this->state->runtime->timestamp);

            // translations are trusted HTML
            $text = $LNG['op_options_vacation_activated'];
            $text .= '<br>' . $LNG['op_options_no_other_settings_changed'];
            if ($this->viewHelpers()->isModuleAvailable(MODULE_VMODE_KICK, $this->state->USER['universe'])) {
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

    /**
     * Toggles the deletion flag (`db_deaktjava` = request time, or 0 to cancel); the maintenance cronjob deletes flagged players later. Ends with a message.
     */
    public function toggleDelete()
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;

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

    /**
     * True when vacation mode may be entered: no own fleet is in flight. Recalculates all other planets under lock first so the stored state is
     * current when the vacation mode is applied.
     */
    private function checkVMode()
    {
        $PLANET = $this->state->PLANET;
        $db = $this->db;

        $sql = "SELECT COUNT(*) as state FROM %%FLEETS%% WHERE `owner` = :userID FOR UPDATE;";
        $fleets = $db->selectSingle($sql, [':userID' => $this->state->USER['id']], 'state');

        if ($fleets != 0) {
            return false;
        }

        $db->startTransaction();
        // Lock the user row first and calc from it. The page-start copy is
        // stale under concurrent requests and SavePlanetToDB writes its b_tech*
        // fields back.
        $sql = "SELECT * FROM %%USERS%% WHERE id = :userID FOR UPDATE;";
        $u = $db->selectSingle($sql, [':userID' => $this->state->USER['id']]);
        if (empty($u) || $u['urlaubs_modus'] == 1) {
            $db->commit();
            return false;
        }
        $this->state->USER = array_merge($this->state->USER, $u);

        $sql = "SELECT * FROM %%PLANETS%% WHERE id_owner = :userID AND id != :planetID AND destruyed = 0 FOR UPDATE;";
        $query = $db->select($sql, [
            ':userID'   => $this->state->USER['id'],
            ':planetID' => $PLANET['id'],
        ]);

        foreach ($query as $CPLANET) {
            [$this->state->USER, $CPLANET] = $this->ecoObj->CalcResource($this->state->USER, $CPLANET, true);
            unset($CPLANET);
        }

        $db->commit();
        return true;
    }

    /**
     * Saves the settings form (delegates to the settings saving code) and ends with a message.
     */
    public function send()
    {
        $LNG = $this->state->LNG;
        try {
            (new SettingsUpdater($this->db, $this->configs, $this->state))->save();
        } catch (SettingsRefused $refused) {
            $this->printMessage($refused->getMessage(), [
                [
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=settings',
                ],
            ]);
        }

        $this->printMessage($LNG['op_options_changed'], [
            [
                'label' => $LNG['sys_forward'],
                'url'   => 'game?page=settings',
            ],
        ]);
    }
}
