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

namespace App\Game\Core;


class GalaxyRows
{
    use \App\Game\Core\GameUtilities;

    private int $Galaxy;
    private int $System;
    private array $galaxyData = [];
    private array $galaxyRow = [];
    private array $spyReportAges = [];

    public const PLANET_DESTROYED = true;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {
    }

    public function setGalaxy(int $Galaxy): self
    {
        $this->Galaxy   = $Galaxy;
        return $this;
    }

    public function setSystem(int $System): self
    {
        $this->System   = $System;
        return $this;
    }

    public function getGalaxyData(): array
    {
        $USER = $this->state->USER;

        $sql = "SELECT SQL_BIG_RESULT DISTINCT
                p.`galaxy`, p.`system`, p.`planet`, p.`id` as planet_id, p.`id_owner`, p.`name`, p.`image`, p.`planet_style`, p.`last_update`, p.`diameter`, p.`temp_min`,
                p.`destruyed`, p.`der_metal`, p.`der_crystal`, p.`id_luna`, u.`id`, u.`ally_id`, u.`username`, u.`rebuild_protection`, u.`rebuild_protection_attackers`, u.`outlaw_time`,
                u.`onlinetime`, u.`urlaubs_modus`, u.`banaday`, m.`id` as m_id, m.`diameter` as m_diameter, m.`name` as m_name, m.`image` as m_image, m.`planet_style` as m_planet_style,
                m.`temp_min` as m_temp_min, m.`last_update` as m_last_update, s.`total_points`, s.`total_rank`, a.`id` as allyid,
                a.`ally_tag`, a.`ally_web`, a.`ally_members`, a.`ally_name`, allys.`total_rank` as ally_rank,
                (a.`ally_owner`=u.`id`) as is_leader, a.`ally_owner_range`, r.`DIPLOMATIC` as is_diplo,
                COUNT(buddy.`id`) as buddy, d.`level` as diploLevel 
            FROM %%PLANETS%% p
            LEFT JOIN %%USERS%% u ON p.`id_owner` = u.`id`
            LEFT JOIN %%PLANETS%% m ON m.`id` = p.`id_luna`
            LEFT JOIN %%STATPOINTS%% s ON s.`id_owner` = u.`id` AND s.`stat_type` = :statTypeUser
            LEFT JOIN %%ALLIANCE%% a ON a.`id` = u.`ally_id`
            LEFT JOIN %%ALLIANCE_RANK%% as r ON r.`allianceID` = u.`ally_id` AND r.`rankID` = u.`ally_rank_id`
            LEFT JOIN %%DIPLO%% as d ON ((d.`owner_1` = :allianceId AND d.`owner_2` = a.`id`) OR (d.`owner_1` = a.`id`
                AND d.`owner_2` = :allianceId)) AND d.`accept` = :accept
            LEFT JOIN %%STATPOINTS%% allys ON allys.`stat_type` = :statTypeAlliance AND allys.`id_owner` = a.`id`
            LEFT JOIN %%BUDDY%% buddy ON ((buddy.`sender` = :userId AND buddy.`owner` = u.`id`) OR (buddy.`sender` = u.`id`
                AND buddy.`owner` = :userId)) AND u.`id` != :userId
            WHERE p.`universe` = :universe AND p.`galaxy` = :galaxy AND p.`system` = :system
              AND p.`planet_type` = :planetTypePlanet
            GROUP BY p.`id`;";

        $galaxyResult = $this->db->select($sql, [
            ':statTypeUser'     => 1,
            ':statTypeAlliance' => 2,
            ':allianceId'       => $USER['ally_id'],
            ':userId'           => $USER['id'],
            ':universe'         => $this->universe()->current(),
            ':galaxy'           => $this->Galaxy,
            ':system'           => $this->System,
            ':planetTypePlanet' => 1,
            ':accept'           => 1,
        ]);

        $this->fetchSpyReportAges();

        foreach ($galaxyResult as $galaxyRow) {
            $this->galaxyRow = $galaxyRow;

            $this->galaxyData[$this->galaxyRow['planet']] = [];

            $this->checkOwnPlanet();
            $this->getAllowedMissions();

            if ($this->galaxyRow['destruyed'] != 0) {
                $this->galaxyData[$this->galaxyRow['planet']]['destroyed'] = self::PLANET_DESTROYED;
                $this->galaxyData[$this->galaxyRow['planet']]['planet'] = [
                    'id'      => $this->galaxyRow['planet_id'],
                ];
                $this->getDebrisData();
                continue;
            }

            $this->galaxyData[$this->galaxyRow['planet']]['destroyed'] = !self::PLANET_DESTROYED;
            $this->setLastActivity();

            $this->getPlayerData();
            $this->getPlanetData();
            $this->getAllianceData();
            $this->getDebrisData();
            $this->getMoonData();
            $this->getActionButtons();
            $this->getSpyReportIndicator();
        }

        return $this->galaxyData;
    }

    private function setLastActivity(): void
    {
        $LNG = $this->state->LNG;

        $lastActivity = floor(
            ($this->state->runtime->timestamp - max($this->galaxyRow['last_update'], $this->galaxyRow['m_last_update'])) / 60
        );

        if ($lastActivity < 15) {
            $this->galaxyData[$this->galaxyRow['planet']]['lastActivity'] = $LNG['gl_activity'];
        } elseif ($lastActivity < 60) {
            $this->galaxyData[$this->galaxyRow['planet']]['lastActivity'] = $LNG->format('gl_activity_inactive',
                $lastActivity
            );
        } else {
            $this->galaxyData[$this->galaxyRow['planet']]['lastActivity']   = '';
        }
    }

    private function checkOwnPlanet(): void
    {
        $USER = $this->state->USER;

        $this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']  = $this->galaxyRow['id_owner'] == $USER['id'];
    }

    private function getAllowedMissions(): void
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $resource = $this->state->resource;
        $this->galaxyData[$this->galaxyRow['planet']]['missions'] = [
            1 => !$this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']
                && $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_ATTACK),
            3 => $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_TRANSPORT),
            4 => $this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']
                && $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_STATION),
            5 => !$this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']
                && $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_HOLD),
            6 => !$this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']
                && $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_SPY),
            8 => $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_RECYCLE),
            9 => !$this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']
                && ($PLANET[$resource[SHIP_RIP]] > 0 || $PLANET[$resource[VOID_TITAN]] > 0)
                && $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_DESTROY),
            10 => !$this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']
                && $PLANET[$resource[INTERPLANETARY_MISSILE]] > 0
                && $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_ATTACK)
                && $this->viewHelpers()->isModuleAvailable(MODULE_MISSILEATTACK)
                && $this->fleetFunctions()->inMissileRange($PLANET, $this->galaxyRow, $USER[$resource[117]]),
        ];
    }

    private function getActionButtons(): void
    {
        $USER = $this->state->USER;
        if ($this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']) {
            $this->galaxyData[$this->galaxyRow['planet']]['action'] = false;
        } else {
            $this->galaxyData[$this->galaxyRow['planet']]['action'] = [
                'esp'     => $USER['settings_esp'] == 1
                    && $this->galaxyData[$this->galaxyRow['planet']]['missions'][MISSION_SPY],
                'message' => $USER['settings_wri'] == 1
                    && $this->viewHelpers()->isModuleAvailable(MODULE_MESSAGES),
                'buddy'   => $USER['settings_bud'] == 1
                    && $this->viewHelpers()->isModuleAvailable(MODULE_BUDDYLIST) && $this->galaxyRow['buddy'] == 0,
                'missle'  => $USER['settings_mis'] == 1
                    && $this->galaxyData[$this->galaxyRow['planet']]['missions'][MISSION_MISSILE],
                'spyReport' => $USER['settings_rep'] == 1,
                'phalanx' => $this->viewHelpers()->isModuleAvailable(MODULE_PHALANX) && $this->galaxyRow['urlaubs_modus'] == 0 &&
                    $this->fleetFunctions()->allowPhalanx($this->galaxyRow['galaxy'], $this->galaxyRow['system'], $this->galaxyRow),
            ];
        }
    }

    private function getPlayerData(): void
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $IsNoobProtec = $this->playerUtil()->checkNoobProtectionFor($this->models()->user($USER), $this->models()->user($this->galaxyRow));
        $Class = $this->playerUtil()->userStatus($this->galaxyRow, $IsNoobProtec);

        $this->galaxyData[$this->galaxyRow['planet']]['user'] = [
            'id'            => $this->galaxyRow['id'],
            'username'      => htmlspecialchars($this->galaxyRow['username'], ENT_QUOTES, "UTF-8"),
            'rank'          => $this->galaxyRow['total_rank'],
            'points'        => $this->viewHelpers()->prettyNumber($this->galaxyRow['total_points']),
            'playerrank'    => $this->viewHelpers()->isModuleAvailable(MODULE_STATISTICS) ? $LNG->format('gl_in_the_rank',
                htmlspecialchars($this->galaxyRow['username'], ENT_QUOTES, "UTF-8"),
                $this->galaxyRow['total_rank']
            ) : htmlspecialchars($this->galaxyRow['username'], ENT_QUOTES, "UTF-8"),
            'class'         => $Class,
            'isBuddy'       => $this->galaxyRow['buddy'] == 1,
            'is_leader'     => $this->galaxyRow['is_leader'],
            'is_diplo'      => $this->galaxyRow['is_diplo'],
            'ally_owner_range' => $this->galaxyRow['ally_owner_range'],
        ];
    }

    private function getAllianceData(): void
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        if (empty($this->galaxyRow['allyid'])) {
            $this->galaxyData[$this->galaxyRow['planet']]['alliance']   = false;
        } else {
            $Class  = [];
            switch ($this->galaxyRow['diploLevel']) {
                case 1:
                case 2:
                    $Class = ['member'];
                    break;
                case 3:
                    $Class = ['trade'];
                    break;
                case 4:
                    $Class = ['friend'];
                    break;
                case 5:
                    $Class = ['enemy'];
                    break;
                case 6:
                    $Class = ['secret'];
                    break;
            }

            if ($USER['ally_id'] == $this->galaxyRow['ally_id']) {
                $Class = ['member'];
            }

            $this->galaxyData[$this->galaxyRow['planet']]['alliance'] = [
                'id'     => $this->galaxyRow['allyid'],
                'name'   => htmlspecialchars($this->galaxyRow['ally_name'], ENT_QUOTES, "UTF-8"),
                'member' => $LNG->format(
                    ($this->galaxyRow['ally_members'] == 1) ? 'gl_member_add' : 'gl_member',
                    $this->galaxyRow['ally_members']
                ),
                'web'    => $this->galaxyRow['ally_web'],
                'tag'    => $this->galaxyRow['ally_tag'],
                'rank'   => $this->galaxyRow['ally_rank'],
                'class'  => $Class,
            ];
        }
    }

    private function getDebrisData(): void
    {
        $total = $this->galaxyRow['der_metal'] + $this->galaxyRow['der_crystal'];
        if ($total == 0) {
            $this->galaxyData[$this->galaxyRow['planet']]['debris'] = false;
        } else {
            $this->galaxyData[$this->galaxyRow['planet']]['debris'] = [
                'metal'   => $this->galaxyRow['der_metal'],
                'crystal' => $this->galaxyRow['der_crystal'],
            ];
        }
    }

    private function getMoonData(): void
    {
        if (!isset($this->galaxyRow['m_id'])) {
            $this->galaxyData[$this->galaxyRow['planet']]['moon'] = false;
        } else {
            $USER = $this->state->USER;
            $THEME = $this->state->THEME;

            if (!$USER['enable_planet_styles']) {
                $image = $THEME->asset("planeten/" . $this->galaxyRow['m_image'] . ".jpg");
            } else {
                switch ($this->galaxyRow['m_planet_style']) {
                    case 1:
                        $image = $THEME->asset("planeten/varianten/v1_magic/" . $this->galaxyRow['m_image'] . "_v1_magic.webp");
                        break;
                    case 2:
                        $image = $THEME->asset("planeten/varianten/v2_storm/" . $this->galaxyRow['m_image'] . "_v2_storm.webp");
                        break;
                    case 3:
                        $image = $THEME->asset("planeten/varianten/v3_voronoi/" . $this->galaxyRow['m_image'] . "_v3_voronoi.webp");
                        break;
                    default:
                        $image = $THEME->asset("planeten/" . $this->galaxyRow['m_image'] . ".jpg");
                        break;
                }
            }

            $this->galaxyData[$this->galaxyRow['planet']]['moon'] = [
                'id'       => $this->galaxyRow['m_id'],
                'name'     => htmlspecialchars($this->galaxyRow['m_name'], ENT_QUOTES, "UTF-8"),
                'diameter' => $this->galaxyRow['m_diameter'],
                'image'    => $image,
                'phalanx'  => false,
                'type'     => 3,
            ];
        }
    }

    private function getPlanetData(): void
    {
        $USER = $this->state->USER;
        $THEME = $this->state->THEME;

        if (!$USER['enable_planet_styles']) {
            $image = $THEME->asset("planeten/" . $this->galaxyRow['image'] . ".jpg");
        } else {
            switch ($this->galaxyRow['planet_style']) {
                case 1:
                    $image = $THEME->asset("planeten/varianten/v1_magic/" . $this->galaxyRow['image'] . "_v1_magic.webp");
                    break;
                case 2:
                    $image = $THEME->asset("planeten/varianten/v2_storm/" . $this->galaxyRow['image'] . "_v2_storm.webp");
                    break;
                case 3:
                    $image = $THEME->asset("planeten/varianten/v3_voronoi/" . $this->galaxyRow['image'] . "_v3_voronoi.webp");
                    break;
                default:
                    $image = $THEME->asset("planeten/" . $this->galaxyRow['image'] . ".jpg");
                    break;
            }
        }

        $this->galaxyData[$this->galaxyRow['planet']]['planet'] = [
            'id'            => $this->galaxyRow['planet_id'],
            'name'          => htmlspecialchars($this->galaxyRow['name'], ENT_QUOTES, "UTF-8"),
            'image'         => $image,
            'phalanx'       => $this->viewHelpers()->isModuleAvailable(MODULE_PHALANX) && $this->galaxyRow['urlaubs_modus'] == 0 &&
                $this->fleetFunctions()->allowPhalanx($this->galaxyRow['galaxy'], $this->galaxyRow['system'], $this->galaxyRow),
            'type'     => 1,
        ];
    }

    private function fetchSpyReportAges(): void
    {
        $USER = $this->state->USER;
        $db = $this->db;

        $sql = "SELECT p.id as planet_id, MAX(m.message_time) as latest_spy_time
            FROM %%PLANETS%% p
            INNER JOIN %%MESSAGES%% m ON m.message_group = CONCAT('m_', p.id)
            INNER JOIN %%USERS_TO_MESSAGES%% um ON m.message_id = um.message_id
            WHERE m.message_type = :messageType
              AND um.user_id = :userId
              AND um.deleted_at IS NULL
              AND p.galaxy = :galaxy
              AND p.`system` = :system
              AND p.universe = :universe
            GROUP BY p.id";

        $results = $db->select($sql, [
            ':messageType' => MESSAGE_TYPE_SPY_REPORT,
            ':userId'      => $USER['id'],
            ':galaxy'      => $this->Galaxy,
            ':system'      => $this->System,
            ':universe'    => $this->universe()->current(),
        ]);

        foreach ($results as $row) {
            $this->spyReportAges[$row['planet_id']] = $row['latest_spy_time'];
        }
    }

    private function getSpyReportIndicator(): void
    {
        if ($this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']) {
            return;
        }

        $USER = $this->state->USER;
        $LNG  = $this->state->LNG;

        $freshHours  = (int) ($USER['spy_report_fresh_hours']  ?? 24);
        $recentHours = (int) ($USER['spy_report_recent_hours'] ?? 168);

        $planetId = $this->galaxyRow['planet_id'];

        if (!isset($this->spyReportAges[$planetId])) {
            $this->galaxyData[$this->galaxyRow['planet']]['spyReport']      = 'none';
            $this->galaxyData[$this->galaxyRow['planet']]['spyReportTitle'] = $LNG['gl_spy_report_none'];
            return;
        }

        $age = $this->state->runtime->timestamp - $this->spyReportAges[$planetId];

        if ($age < $freshHours * 3600) {
            $this->galaxyData[$this->galaxyRow['planet']]['spyReport']      = 'fresh';
            $this->galaxyData[$this->galaxyRow['planet']]['spyReportTitle'] = $LNG->format('gl_spy_report_fresh', $freshHours);
        } elseif ($age < $recentHours * 3600) {
            $this->galaxyData[$this->galaxyRow['planet']]['spyReport']      = 'recent';
            $this->galaxyData[$this->galaxyRow['planet']]['spyReportTitle'] = $LNG->format('gl_spy_report_recent', $recentHours);
        } else {
            $this->galaxyData[$this->galaxyRow['planet']]['spyReport']      = 'old';
            $this->galaxyData[$this->galaxyRow['planet']]['spyReportTitle'] = $LNG->format('gl_spy_report_old', $recentHours);
        }
    }
}
