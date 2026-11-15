<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 * For the full copyright and license information, please view the LICENSE
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Game\Core;

use Exception;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\Models\StatPoints;

class Statbuilder
{
    use \App\Game\Core\GameUtilities;

    public const STAT_TYPE_USER = 1;
    public const STAT_TYPE_ALLIANCE = 2;

    private float $startTime;
    private array $memory;
    private int $time;
    private array $universes;
    private array $resources;
    private array $reslists;
    private array $pricelists;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {
        $this->startTime = microtime(true);
        $this->memory = [round(memory_get_usage() / 1024, 1), round(memory_get_usage(1) / 1024, 1)];
        $this->time = $this->state->runtime->timestamp;

        $this->getUniverses();

        $cache  = new Cache($this->db, $this->state);
        $vars = $cache->getData('vars');
        foreach ($this->universes as $uni) {
            $result = $vars[0];
            if (isset($vars[$uni])) {
                $result = array_replace_recursive($result, $vars[$uni]);
            }

            $this->resources[$uni] = $result['resource'];
            $this->reslists[$uni] = $result['reslist'];
            $this->pricelists[$uni] = $result['pricelist'];
        }
    }

    /**
     * @throws Exception
     */
    final public function generateStats(): array
    {
        $allianceScores = [];
        $userStats = $this->getUserInfosFromDB();
        $userScores = $this->setUserScoresByPlanets($userStats);
        $universeData = [];

        foreach ($userStats['Users'] as $userData) {
            $resource = $this->resources[$userData['universe']];

            $statPoints = new StatPoints(
                $userData['id'],
                $userData['ally_id'],
                self::STAT_TYPE_USER,
                $userData['universe']
            );

            if (!isset($universeData[$userData['universe']])) {
                $universeData[$userData['universe']] = 0;
            }

            $universeData[$userData['universe']]++;

            if ((in_array($this->configs->get()->stat, [1, 2]) && $userData['authlevel'] >= $this->configs->get()->stat_level) || !empty($userData['bana'])) {
                $this->updateUserStats($statPoints);
                continue;
            }

            if (isset($userStats['Fleets'][$userData['id']])) {
                foreach ($userStats['Fleets'][$userData['id']] as $ID => $Amount) {
                    $userData[$resource[$ID]] += $Amount;
                }
            }

            $techPoints = $this->getResearchScoreByUser($userData);
            $fleetPoints = $this->getFleetScoreByUser($userData);
            $defensePoints = $this->getDefenseScoreByUser($userData);

            $statPoints->build_count = (int)$userScores[$userData['id']]['build']['count'];
            $statPoints->build_points = (int)$userScores[$userData['id']]['build']['points'];
            $statPoints->fleet_count = (int)$fleetPoints['count'];
            $statPoints->fleet_points = (int)$fleetPoints['points'];
            $statPoints->defs_count = (int)$defensePoints['count'];
            $statPoints->defs_points = (int)$defensePoints['points'];
            $statPoints->tech_count = (int)$techPoints['count'];
            $statPoints->tech_points = (int)$techPoints['points'];

            $honorData = $userStats['Honor'][$userData['id']] ?? [
                'honor_points'        => 0,
                'honorable_fights'    => 0,
                'dishonorable_fights' => 0,
                'units_points'        => 0,
            ];

            $statPoints->honor_points = $honorData['honor_points'];
            $statPoints->honorable_fights = $honorData['honorable_fights'];
            $statPoints->dishonorable_fights = $honorData['dishonorable_fights'];
            $statPoints->units_points = $honorData['units_points'];

            $statPoints->build_old_rank = (int)$userData['old_build_rank'];
            $statPoints->fleet_old_rank = (int)$userData['old_fleet_rank'];
            $statPoints->defs_old_rank = (int)$userData['old_defs_rank'];
            $statPoints->tech_old_rank = (int)$userData['old_tech_rank'];
            $statPoints->total_old_rank = (int)$userData['old_total_rank'];
            $statPoints->honor_old_rank = (int)$userData['old_honor_rank'];
            $statPoints->units_old_rank = (int)$userData['old_units_rank'];

            $statPoints->setTotalStats();
            $this->updateUserStats($statPoints);

            if ($userData['ally_id'] != 0) {
                if (!isset($allianceScores[$userData['ally_id']])) {
                    $allianceScores[$userData['ally_id']] = new StatPoints(
                        $userData['ally_id'],
                        0,
                        self::STAT_TYPE_ALLIANCE,
                        $userData['universe']
                    );
                }

                if ($userData['onlinetime'] >= $this->state->runtime->timestamp - INACTIVE) {
                    $allianceScores[$userData['ally_id']]->build_count += (int)$userScores[$userData['id']]['build']['count'];
                    $allianceScores[$userData['ally_id']]->build_points += (int)$userScores[$userData['id']]['build']['points'] ?? 0;
                    $allianceScores[$userData['ally_id']]->fleet_count += (int)$fleetPoints['count'];
                    $allianceScores[$userData['ally_id']]->fleet_points += (int)$fleetPoints['points'];
                    $allianceScores[$userData['ally_id']]->defs_count += (int)$defensePoints['count'];
                    $allianceScores[$userData['ally_id']]->defs_points += (int)$defensePoints['points'];
                    $allianceScores[$userData['ally_id']]->tech_count += (int)$techPoints['count'];
                    $allianceScores[$userData['ally_id']]->tech_points += (int)$techPoints['points'];
                    $allianceScores[$userData['ally_id']]->honor_points += $honorData['honor_points'];
                    $allianceScores[$userData['ally_id']]->honorable_fights += $honorData['honorable_fights'];
                    $allianceScores[$userData['ally_id']]->dishonorable_fights += $honorData['dishonorable_fights'];
                    $allianceScores[$userData['ally_id']]->units_points += $honorData['units_points'];
                    $allianceScores[$userData['ally_id']]->setTotalStats();
                }
            }
        }

        if (count($allianceScores) != 0) {
            foreach ($allianceScores as $allianceData) {
                $this->updateUserStats($allianceData);
            }
        }

        $this->flushUserStats();
        $this->SetNewRanks();
        $this->setHonorRanks();
        $this->setHonorTitles();
        $this->checkUniverseAccounts($universeData);

        return $this->getStatsArray();
    }

    /**
     * @throws Exception
     */
    private function getUniverses(): void
    {
        $this->universes = [];

        $uniResult = $this->db->select("SELECT DISTINCT uni FROM %%CONFIG_UNIVERSE%% ORDER BY uni ASC;");
        foreach ($uniResult as $uni) {
            $this->universes[] = $uni['uni'];
        }
    }

    private function getStatsArray(): array
    {
        return [
            'stats_time' => $this->time,
            'totaltime' => round(microtime(true) - $this->startTime, 7),
            'memory_peak' => [round(memory_get_peak_usage() / 1024, 1), round(memory_get_peak_usage(1) / 1024, 1)],
            'initial_memory' => $this->memory,
            'end_memory' => [round(memory_get_usage() / 1024, 1), round(memory_get_usage(1) / 1024, 1)],
            'sql_count' => $this->db->getQueryCounter(),
        ];
    }

    private function checkUniverseAccounts(array $UniData): void
    {
        $UniData = $UniData + array_combine($this->universes, array_fill(1, count($this->universes), 0));
        foreach ($UniData as $Uni => $Amount) {
            $config = $this->configs->get($Uni);
            $config->users_amount = $Amount;
            $config->save();
        }
    }

    private function getUserInfosFromDB(): array
    {
        $database = $this->db;
        $Return = [
            'Planets' => [],
            'Users' => [],
            'Fleets' => [],
            'Alliance' => [],
            'Honor' => [],
        ];
        $database = $this->db;
        foreach ($this->universes as $uni) {

            $resource = $this->resources[$uni];
            $reslist = $this->reslists[$uni];
            $select_defenses = '';
            $select_buildings = '';
            $selected_tech = '';
            $select_fleets = '';

            foreach ($reslist['build'] as $Building) {
                $select_buildings .= " p." . $resource[$Building] . ",";
            }

            foreach ($reslist['tech'] as $Techno) {
                $selected_tech .= " u." . $resource[$Techno] . ",";
            }

            foreach ($reslist['fleet'] as $Fleet) {
                $select_fleets .= " SUM(p." . $resource[$Fleet] . ") as " . $resource[$Fleet] . ",";
            }

            foreach ($reslist['defense'] as $Defense) {
                $select_defenses .= " SUM(p." . $resource[$Defense] . ") as " . $resource[$Defense] . ",";
            }

            foreach ($reslist['missile'] as $Defense) {
                $select_defenses .= " SUM(p." . $resource[$Defense] . ") as " . $resource[$Defense] . ",";
            }

            $FlyingFleets = [];
            $SQLFleets = $database->select('SELECT `ships`, `owner` FROM %%FLEETS%% WHERE `universe` = :universe;', [':universe' => $uni]);
            foreach ($SQLFleets as $CurFleets) {
                $FleetRec = explode(";", $CurFleets['ships']);

                if (!is_array($FleetRec)) {
                    continue;
                }

                foreach ($FleetRec as $Group) {
                    if (empty($Group)) {
                        continue;
                    }

                    $Ship = explode(",", $Group);
                    if (!isset($FlyingFleets[$CurFleets['owner']][$Ship[0]])) {
                        $FlyingFleets[$CurFleets['owner']][$Ship[0]] = $Ship[1];
                    } else {
                        $FlyingFleets[$CurFleets['owner']][$Ship[0]] += $Ship[1];
                    }
                }
            }

            $Return['Fleets'] = array_replace($Return['Fleets'], $FlyingFleets);
            $Return['Honor'] = array_replace($Return['Honor'], $this->getHonorDataByUniverse($uni));
            $Return['Planets'] = array_merge($Return['Planets'],
                $database->select(
                    "SELECT SQL_BIG_RESULT $select_buildings p.`id`, p.`universe`, p.`id_owner`, u.`authlevel`, u.`bana`, u.`username` 
                    FROM %%PLANETS%% as p 
                    LEFT JOIN %%USERS%% as u ON u.`id` = p.`id_owner` 
                    WHERE p.`universe` = :universe and p.`destruyed` = 0;",
                    [':universe' => $uni]
                ));
            $Return['Users'] = array_merge($Return['Users'],
                $database->select(
                    "SELECT SQL_BIG_RESULT $selected_tech $select_fleets $select_defenses u.`id`, u.`ally_id`, u.`authlevel`,
                        u.`bana`, u.`universe`, u.`username`, u.`onlinetime`, s.`tech_rank` AS old_tech_rank, s.`build_rank` AS old_build_rank,
                        s.`defs_rank` AS old_defs_rank, s.`fleet_rank` AS old_fleet_rank, s.`total_rank` AS old_total_rank, s.`honor_rank` AS old_honor_rank, s.`units_rank` AS old_units_rank
                    FROM %%USERS%% as u
                    LEFT JOIN %%STATPOINTS%% as s ON s.`stat_type` = 1 AND s.`id_owner` = u.`id`
                    LEFT JOIN %%PLANETS%% as p ON u.`id` = p.`id_owner`
                    WHERE u.`universe` = :universe
                    GROUP BY s.`id_owner`, u.`id`, u.`authlevel`;",
                    [':universe' => $uni]
                ));
        }
        $Return['Alliance'] = $database->select(
            "SELECT SQL_BIG_RESULT DISTINCT a.`id`, a.`ally_universe`, s.`tech_rank` AS old_tech_rank, s.`build_rank` AS old_build_rank,
                s.`defs_rank` AS old_defs_rank, s.`fleet_rank` AS old_fleet_rank, s.`total_rank` AS old_total_rank
            FROM %%ALLIANCE%% as a
            LEFT JOIN %%STATPOINTS%% as s ON s.`stat_type` = 2 AND s.`id_owner` = a.`id`
            GROUP BY a.`id`;"
            );

        return $Return;
    }

    private function getHonorDataByUniverse(int $uni): array
    {
        $sql = "SELECT userId,
                    MAX(CASE WHEN statkey = :hp_key THEN statvalue END) AS honor_points,
                    MAX(CASE WHEN statkey = :hf_key THEN statvalue END) AS honorable_fights,
                    MAX(CASE WHEN statkey = :df_key THEN statvalue END) AS dishonorable_fights,
                    MAX(CASE WHEN statkey = :des_key THEN statvalue END) AS destroyed_units,
                    MAX(CASE WHEN statkey = :lost_key THEN statvalue END) AS lost_units
                FROM %%ADVANCED_STATS%%
                WHERE universe = :universe
                GROUP BY userId;";

        $result = $this->db->select($sql, [
            ':hp_key'   => AdvancedStatsEnum::HONORPOINTS->name,
            ':hf_key'   => AdvancedStatsEnum::HONORABLE_FIGHTS->name,
            ':df_key'   => AdvancedStatsEnum::DISHONORABLE_FIGHTS->name,
            ':des_key'  => AdvancedStatsEnum::DESUNITS_REAL->name,
            ':lost_key' => AdvancedStatsEnum::LOSTUNITS_REAL->name,
            ':universe' => $uni,
        ]);

        $honor = [];
        foreach ($result as $row) {
            $honor[(int) $row['userId']] = [
                'honor_points'        => (int) $row['honor_points'],
                'honorable_fights'    => (int) $row['honorable_fights'],
                'dishonorable_fights' => (int) $row['dishonorable_fights'],
                'units_points'        => (int) $row['destroyed_units'] - (int) $row['lost_units'],
            ];
        }

        return $honor;
    }

    /** @var array<int, array<int, array<int, float|int>>> cost of an element level by universe, element, level */
    private array $levelCosts = [];

    /** Total cost of building/researching $element up to $level (level $level only), memoized. */
    private function levelCost(int $uni, int $element, int $level): float|int
    {
        return $this->levelCosts[$uni][$element][$level] ??= (
            $this->pricelists[$uni][$element]['cost'][901]
            + $this->pricelists[$uni][$element]['cost'][902]
            + $this->pricelists[$uni][$element]['cost'][903]
        ) * pow($this->pricelists[$uni][$element]['factor'], $level - 1);
    }

    private function getResearchScoreByUser(array $USER): array
    {
        $resource = $this->resources[$USER['universe']];
        $reslist = $this->reslists[$USER['universe']];
        $pricelist = $this->pricelists[$USER['universe']];
        $TechCounts = 0;
        $TechPoints = 0;

        foreach ($reslist['tech'] as $Techno) {
            if ($USER[$resource[$Techno]] == 0) {
                continue;
            }

            // PointsPerCot == Config::get()->stat_settings
            for ($i = 1; $i <= $USER[$resource[$Techno]]; $i++) {
                $TechPoints += $this->levelCost($USER['universe'], $Techno, $i);
            }
            $TechCounts += $USER[$resource[$Techno]];
        }

        return ['count' => $TechCounts, 'points' => ($TechPoints / $this->configs->get()->stat_settings)];
    }

    public function getBuildingScoreByPlanet(array $PLANET): array
    {
        $resource = $this->resources[$PLANET['universe']];
        $reslist = $this->reslists[$PLANET['universe']];
        $pricelist = $this->pricelists[$PLANET['universe']];
        $BuildCounts = 0;
        $BuildPoints = 0;

        foreach ($reslist['build'] as $Build) {
            if ($PLANET[$resource[$Build]] == 0) {
                continue;
            }

            // PointsPerCost == Config::get()->stat_settings
            for ($i = 1; $i <= $PLANET[$resource[$Build]]; $i++) {
                $BuildPoints += $this->levelCost($PLANET['universe'], $Build, $i);
            }
            $BuildCounts += $PLANET[$resource[$Build]];
        }
        return ['count' => $BuildCounts, 'points' => ($BuildPoints / $this->configs->get()->stat_settings)];
    }

    private function getDefenseScoreByUser(array $USER): array
    {
        $resource = $this->resources[$USER['universe']];
        $reslist = $this->reslists[$USER['universe']];
        $pricelist = $this->pricelists[$USER['universe']];
        $DefenseCounts = 0;
        $DefensePoints = 0;

        foreach (array_merge($reslist['defense'], $reslist['missile']) as $Defense) {
            if ($USER[$resource[$Defense]] == 0) {
                continue;
            }

            $Units = $pricelist[$Defense]['cost'][901] + $pricelist[$Defense]['cost'][902] + $pricelist[$Defense]['cost'][903];
            $DefensePoints += $Units * $USER[$resource[$Defense]];
            $DefenseCounts += $USER[$resource[$Defense]];
        }

        return ['count' => $DefenseCounts, 'points' => ($DefensePoints / $this->configs->get()->stat_settings)];
    }

    private function getFleetScoreByUser(array $USER): array
    {
        $resource = $this->resources[$USER['universe']];
        $reslist = $this->reslists[$USER['universe']];
        $pricelist = $this->pricelists[$USER['universe']];
        $FleetCounts = 0;
        $FleetPoints = 0;

        foreach ($reslist['fleet'] as $Fleet) {
            if ($USER[$resource[$Fleet]] == 0) {
                continue;
            }

            $Units = $pricelist[$Fleet]['cost'][901] + $pricelist[$Fleet]['cost'][902] + $pricelist[$Fleet]['cost'][903];
            $FleetPoints += $Units * $USER[$resource[$Fleet]];
            $FleetCounts += $USER[$resource[$Fleet]];
        }

        return ['count' => $FleetCounts, 'points' => ($FleetPoints / $this->configs->get()->stat_settings)];
    }

    private function SetNewRanks(): void
    {
        $db = $this->db;
        foreach ($this->universes as $uni) {
            foreach (['tech', 'build', 'defs', 'fleet', 'total', 'units'] as $type) {
                // User ranking, invisible admins at bottom of same points
                if ($this->configs->get()->stat == 2) {
                    $sql = 'CREATE TEMPORARY TABLE temp_ranking AS
                        SELECT s.id_owner, s.' . $type . '_points, 0 AS ' . $type . '_rank
                        FROM %%STATPOINTS%% s
                        JOIN %%USERS%% u ON u.id = s.id_owner
                        WHERE s.universe = ' . $uni . ' AND s.stat_type = 1
                        ORDER BY s.' . $type . '_points DESC, u.authlevel ASC, s.id_owner ASC;';
                    $db->nativeQuery($sql);

                    $db->nativeQuery('SELECT @i := 0;');

                    $sql = 'UPDATE temp_ranking
                        SET ' . $type . '_rank = (SELECT @i := @i + 1);';
                    $db->update($sql);

                    $sql = 'UPDATE %%STATPOINTS%% s
                        JOIN temp_ranking tr ON s.id_owner = tr.id_owner
                        SET s.' . $type . '_rank = tr.' . $type . '_rank
                        WHERE s.universe = :uni AND s.stat_type = :type;';
                    $db->update($sql, [
                        ':uni' => $uni,
                        ':type' => 1,
                    ]);

                    $sql = 'DROP TEMPORARY TABLE temp_ranking;';
                    $db->nativeQuery($sql);
                } else { // User ranking, visible admins in normal order
                    $db->nativeQuery('SELECT @i := 0;');

                    $sql = 'UPDATE %%STATPOINTS%%
                        SET ' . $type . '_rank = (SELECT @i := @i + 1)
                        WHERE universe = :uni AND stat_type = :type
                        ORDER BY ' . $type . '_points DESC, id_owner ASC;';
                    $db->update($sql, [
                        ':uni' => $uni,
                        ':type' => 1,
                    ]);
                }

                // Alliance ranking
                $db->nativeQuery('SELECT @i := 0;');

                $sql = 'UPDATE %%STATPOINTS%%
                    SET ' . $type . '_rank = (SELECT @i := @i + 1)
                    WHERE universe = :uni AND stat_type = :type
                    ORDER BY ' . $type . '_points DESC, id_owner ASC;';
                $db->update($sql, [
                    ':uni' => $uni,
                    ':type' => 2,
                ]);
            }
        }
    }

    private function setHonorRanks(): void
    {
        $db = $this->db;

        foreach ($this->universes as $uni) {
            // User ranking, invisible admins at bottom of same points
            if ($this->configs->get()->stat == 2) {
                $sql = 'CREATE TEMPORARY TABLE temp_honor_ranking AS
                    SELECT s.id_owner, 0 AS honor_rank
                    FROM %%STATPOINTS%% s
                    JOIN %%USERS%% u ON u.id = s.id_owner
                    WHERE s.universe = ' . $uni . ' AND s.stat_type = 1
                    ORDER BY s.honor_points DESC, s.honorable_fights DESC,
                             s.dishonorable_fights ASC, u.authlevel ASC, s.id_owner ASC;';
                $db->nativeQuery($sql);

                $db->nativeQuery('SELECT @i := 0;');

                $db->update('UPDATE temp_honor_ranking SET honor_rank = (SELECT @i := @i + 1);');

                $db->update('UPDATE %%STATPOINTS%% s
                    JOIN temp_honor_ranking tr ON s.id_owner = tr.id_owner
                    SET s.honor_rank = tr.honor_rank
                    WHERE s.universe = :uni AND s.stat_type = :type;', [
                    ':uni' => $uni,
                    ':type' => self::STAT_TYPE_USER,
                ]);

                $db->nativeQuery('DROP TEMPORARY TABLE temp_honor_ranking;');
            } else {
                $db->nativeQuery('SELECT @i := 0;');

                $db->update('UPDATE %%STATPOINTS%%
                    SET honor_rank = (SELECT @i := @i + 1)
                    WHERE universe = :uni AND stat_type = :type
                    ORDER BY honor_points DESC, honorable_fights DESC,
                             dishonorable_fights ASC, id_owner ASC;', [
                    ':uni' => $uni,
                    ':type' => self::STAT_TYPE_USER,
                ]);
            }

            // Alliance ranking
            $db->nativeQuery('SELECT @i := 0;');

            $db->update('UPDATE %%STATPOINTS%%
                SET honor_rank = (SELECT @i := @i + 1)
                WHERE universe = :uni AND stat_type = :type
                ORDER BY honor_points DESC, honorable_fights DESC,
                         dishonorable_fights ASC, id_owner ASC;', [
                ':uni' => $uni,
                ':type' => self::STAT_TYPE_ALLIANCE,
            ]);
        }
    }

    private function setHonorTitles(): void
    {
        $db = $this->db;

        foreach ($this->universes as $uni) {
            $userAmount = (int) $db->selectSingle(
                'SELECT COUNT(*) AS count FROM %%USERS%% WHERE universe = :universe;',
                [':universe' => $uni],
                'count'
            );

            $totalRanked = (int) $db->selectSingle(
                'SELECT COUNT(*) AS count FROM %%STATPOINTS%%
                 WHERE universe = :universe AND stat_type = :type;',
                [':universe' => $uni, ':type' => self::STAT_TYPE_USER],
                'count'
            );

            if ($totalRanked === 0) {
                continue;
            }

            $limTop10 = (int) ceil(($userAmount - 1) * 0.10);
            $limTop20 = (int) ceil(($userAmount - 1) * 0.20);
            $limTop30 = (int) ceil(($userAmount - 1) * 0.30);

            $db->update(
                "UPDATE %%STATPOINTS%% SET honor_title = 'neutral'
                 WHERE universe = :universe AND stat_type = :type;",
                [':universe' => $uni, ':type' => self::STAT_TYPE_USER]
            );

            $tiers = [
                ['top1',  'honor_rank = :bound',  1,                            'honor_points >= :floor',  50000],
                ['top10', 'honor_rank < :bound',  $limTop10,                    'honor_points >= :floor',  15000],
                ['top20', 'honor_rank < :bound',  $limTop20,                    'honor_points >= :floor',   2500],
                ['top30', 'honor_rank < :bound',  $limTop30,                    'honor_points >= :floor',    500],
                ['low1',  'honor_rank = :bound',  $totalRanked,                 'honor_points <= :floor', -50000],
                ['low10', 'honor_rank > :bound',  $totalRanked - $limTop10 + 1, 'honor_points <= :floor', -15000],
                ['low20', 'honor_rank > :bound',  $totalRanked - $limTop20 + 1, 'honor_points <= :floor',  -2500],
                ['low30', 'honor_rank > :bound',  $totalRanked - $limTop30 + 1, 'honor_points <= :floor',   -500],
            ];

            foreach ($tiers as [$title, $rankPredicate, $bound, $pointsPredicate, $floor]) {
                if ($bound <= 0) {
                    continue;
                }

                $db->update(
                    "UPDATE %%STATPOINTS%%
                     SET honor_title = :title
                     WHERE universe = :universe AND stat_type = :type
                       AND honor_title = 'neutral'
                       AND {$rankPredicate} AND {$pointsPredicate};",
                    [
                        ':title'    => $title,
                        ':universe' => $uni,
                        ':type'     => self::STAT_TYPE_USER,
                        ':bound'    => $bound,
                        ':floor'    => $floor,
                    ]
                );
            }
        }
    }

    private function setUserScoresByPlanets(array $userStats): array
    {
        $userScores = [];

        foreach ($userStats['Planets'] as $PlanetData) {
            if ((in_array($this->configs->get()->stat, [1, 2]) && $PlanetData['authlevel'] >= $this->configs->get()->stat_level) || !empty($PlanetData['bana'])) {
                continue;
            }

            if (!isset($userScores[$PlanetData['id_owner']])) {
                $userScores[$PlanetData['id_owner']]['build']['count'] = $userScores[$PlanetData['id_owner']]['build']['points'] = 0;
            }

            $BuildPoints = $this->getBuildingScoreByPlanet($PlanetData);
            $userScores[$PlanetData['id_owner']]['build']['count'] += $BuildPoints['count'];
            $userScores[$PlanetData['id_owner']]['build']['points'] += $BuildPoints['points'];
        }

        return $userScores;
    }

    /** Rows of updateUserStats() waiting to be written, see flushUserStats() */
    private array $pendingStatRows = [];

    private const STAT_COLUMNS = [
        'id_owner', 'id_ally', 'stat_type', 'universe',
        'tech_rank', 'tech_old_rank', 'tech_points', 'tech_count',
        'build_rank', 'build_old_rank', 'build_points', 'build_count',
        'defs_rank', 'defs_old_rank', 'defs_points', 'defs_count',
        'fleet_rank', 'fleet_old_rank', 'fleet_points', 'fleet_count',
        'total_rank', 'total_old_rank', 'total_points', 'total_count',
        'honor_points', 'honor_rank', 'honor_old_rank', 'honorable_fights', 'dishonorable_fights',
        'units_points', 'units_rank', 'units_old_rank',
    ];

    /**
     * Queues the row of a user/alliance for the statpoints table; the rows are written in batches
     * (one multi-row upsert per 200 rows instead of one query per row).
     */
    private function updateUserStats(StatPoints $data): void
    {
        $row = [];
        foreach (self::STAT_COLUMNS as $column) {
            $row[] = $data->$column;
        }
        $this->pendingStatRows[] = $row;

        if (count($this->pendingStatRows) >= 200) {
            $this->flushUserStats();
        }
    }

    private function flushUserStats(): void
    {
        if ($this->pendingStatRows === []) {
            return;
        }

        $columnCount = count(self::STAT_COLUMNS);
        $placeholders = '(' . implode(', ', array_fill(0, $columnCount, '?')) . ')';
        $update = [];
        foreach (self::STAT_COLUMNS as $column) {
            if ($column !== 'id_owner') {
                $update[] = "`$column` = VALUES(`$column`)";
            }
        }

        $query = "INSERT IGNORE INTO `%%STATPOINTS%%` (`" . implode('`, `', self::STAT_COLUMNS) . "`) VALUES "
            . implode(', ', array_fill(0, count($this->pendingStatRows), $placeholders))
            . " ON DUPLICATE KEY UPDATE " . implode(', ', $update);

        $params = [];
        foreach ($this->pendingStatRows as $row) {
            foreach ($row as $value) {
                $params[] = $value;
            }
        }
        $this->pendingStatRows = [];

        $this->db->insert($query, $params);
    }

    public function generateJson(): void
    {
        $database = $this->db;

        foreach ($this->universes as $uni) {
            $config = $this->configs->get($uni);

            $columns = [
                'sh.id_owner AS playerId',
                'u.username AS playerName',
                'u.universe AS playerUniverse',
                'u.galaxy AS playerGalaxy',
                'sh.id_ally AS allianceId',
                'a.ally_name AS allianceName',
                'sh.total_rank AS rank',
                'sh.total_points AS score',
                'sh.tech_rank AS researchRank',
                'sh.tech_points AS researchScore',
                'sh.build_rank AS buildingRank',
                'sh.build_points AS buildingScore',
                'sh.defs_rank AS defensiveRank',
                'sh.defs_points AS defensiveScore',
                'sh.fleet_rank AS fleetRank',
                'sh.fleet_points AS fleetScore',
                'adw.statvalue AS battlesWon',
                'adl.statvalue AS battlesLost',
                'addr.statvalue AS battlesDraw',
                'adm.statvalue AS debrisMetal',
                'adk.statvalue AS debrisCrystal',
                'addu.statvalue AS unitsDestroyed',
                'adlu.statvalue AS unitsLost',
                'admr.statvalue AS realDebrisMetal',
                'adkr.statvalue AS realDebrisCrystal',
                'addur.statvalue AS realUnitsDestroyed',
                'adlur.statvalue AS realUnitsLost',
                'sh.timestamp as timestamp',
            ];

            $sql = trim("SELECT SQL_BIG_RESULT
                " . implode(',', $columns) . "
                FROM %%STATPOINTS_HISTORY%% sh
                INNER JOIN %%USERS%% u ON u.`id` = sh.`id_owner`
                LEFT JOIN %%ALLIANCE%% a ON a.`id` = sh.`id_ally`
                LEFT JOIN %%ADVANCED_STATS_HISTORY%% adw on adw.userId = u.id AND adw.timestamp = sh.timestamp AND adw.statkey = :wons
                LEFT JOIN %%ADVANCED_STATS_HISTORY%% adl on adl.userId = u.id AND adl.timestamp = sh.timestamp AND adl.statkey = :loos
                LEFT JOIN %%ADVANCED_STATS_HISTORY%% addr on addr.userId = u.id AND addr.timestamp = sh.timestamp AND addr.statkey = :draw
                LEFT JOIN %%ADVANCED_STATS_HISTORY%% adm on adm.userId = u.id AND adm.timestamp = sh.timestamp AND adm.statkey = :kbmetalt
                LEFT JOIN %%ADVANCED_STATS_HISTORY%% adk on adk.userId = u.id AND adk.timestamp = sh.timestamp AND adk.statkey = :kbcrystalt
                LEFT JOIN %%ADVANCED_STATS_HISTORY%% addu on addu.userId = u.id AND addu.timestamp = sh.timestamp AND addu.statkey = :desunitst
                LEFT JOIN %%ADVANCED_STATS_HISTORY%% adlu on adlu.userId = u.id AND adlu.timestamp = sh.timestamp AND adlu.statkey = :lostunitst
                LEFT JOIN %%ADVANCED_STATS_HISTORY%% admr on admr.userId = u.id AND admr.timestamp = sh.timestamp AND admr.statkey = :kbmetalr
                LEFT JOIN %%ADVANCED_STATS_HISTORY%% adkr on adkr.userId = u.id AND adkr.timestamp = sh.timestamp AND adkr.statkey = :kbcrystalr
                LEFT JOIN %%ADVANCED_STATS_HISTORY%% addur on addur.userId = u.id AND addur.timestamp = sh.timestamp AND addur.statkey = :desunitsr
                LEFT JOIN %%ADVANCED_STATS_HISTORY%% adlur on adlur.userId = u.id AND adlur.timestamp = sh.timestamp AND adlur.statkey = :lostunitsr
                WHERE sh.`stat_type` = 1 AND sh.universe = :universe
                  AND sh.`timestamp` = (
                      SELECT MAX(s2.`timestamp`)
                      FROM %%STATPOINTS_HISTORY%% s2
                      WHERE s2.`timestamp` < (UNIX_TIMESTAMP() - :delay)
                        AND s2.universe = :universe
                        AND s2.`stat_type` = 1
                  )
                ORDER BY sh.`total_rank`;");

            if ($this->viewHelpers()->isModuleAvailable(MODULE_STATS_JSON, $uni) && $config->uni_status != STATUS_REG_ONLY && $config->uni_status != STATUS_CLOSED) {
                $scores = $database->select($sql, [
                    ':wons' => AdvancedStatsEnum::WONS->name,
                    ':loos' => AdvancedStatsEnum::LOOS->name,
                    ':draw' => AdvancedStatsEnum::DRAWS->name,
                    ':kbmetalt' => AdvancedStatsEnum::KBMETAL->name,
                    ':kbcrystalt' => AdvancedStatsEnum::KBCRYSTAL->name,
                    ':desunitst' => AdvancedStatsEnum::DESUNITS->name,
                    ':lostunitst' => AdvancedStatsEnum::LOSTUNITS->name,
                    ':kbmetalr' => AdvancedStatsEnum::KBMETAL_REAL->name,
                    ':kbcrystalr' => AdvancedStatsEnum::KBCRYSTAL_REAL->name,
                    ':desunitsr' => AdvancedStatsEnum::DESUNITS_REAL->name,
                    ':lostunitsr' => AdvancedStatsEnum::LOSTUNITS_REAL->name,
                    ':universe' => $uni,
                    ':delay' => $config->statsjson_delay,
                ]);
                $name = $config->uni_name;
                $name = str_replace(' ', '_', $name);
                $fh = fopen($this->state->runtime->rootPath . 'stats/stats_' . $name . '_' . date('Y-m-d_H') . '.json', 'w+');
                fwrite($fh, json_encode($scores, JSON_PRETTY_PRINT));
                fclose($fh);

                $fh = fopen($this->state->runtime->rootPath . 'stats_' . $name . '.json', 'w+');
                fwrite($fh, json_encode($scores, JSON_PRETTY_PRINT));
                fclose($fh);
            }
        }
    }

    private function getHighestUser(string $type, int $uni): array
    {
        $resource = $this->resources[$uni];
        $reslist = $this->reslists[$uni];

        $columns = [];
        foreach ($reslist[$type] as $Techno) {
            $columns[$Techno] = $resource[$Techno];
        }

        // Highest level per column among the non-hidden users, then every user holding such a level
        // (including hidden ones, as before) - two queries instead of two per column.
        return $this->getHighestRows('%%USERS%%', 'id', 'authlevel < :level', $columns, $uni);
    }

    private function getHighestPlanet(string $type, int $uni): array
    {
        $resource = $this->resources[$uni];
        $reslist = $this->reslists[$uni];

        $columns = [];
        foreach ($reslist[$type] as $kind) {
            $columns[$kind] = $resource[$kind];
        }

        return $this->getHighestRows('%%PLANETS%%', 'id_owner', 'p.destruyed = 0 AND u.authlevel < :level', $columns, $uni);
    }

    /**
     * @param string $table        %%USERS%% (rows are users) or %%PLANETS%% (rows are planets, owner by id_owner)
     * @param string $idColumn     column of $table that identifies the user
     * @param string $maxCondition condition for the rows that count for the maximum
     * @param array  $columns      element id => column name
     * @return array list (per element with a level above 0) of rows [id, level, 0 => element id]
     */
    private function getHighestRows(string $table, string $idColumn, string $maxCondition, array $columns, int $uni): array
    {
        $db = $this->db;
        $isPlanet = $table === '%%PLANETS%%';
        $level = $this->configs->get()->stat_level;

        if ($columns === []) {
            return [];
        }

        $maxSelect = [];
        foreach ($columns as $element => $column) {
            $maxSelect[] = "MAX(" . ($isPlanet ? 'p.' : '') . "`$column`) AS `m$element`";
        }
        if ($isPlanet) {
            $sql = "SELECT " . implode(', ', $maxSelect) . " FROM %%PLANETS%% p
                JOIN %%USERS%% u ON p.id_owner = u.id
                WHERE p.universe = :universe AND $maxCondition;";
        } else {
            $sql = "SELECT " . implode(', ', $maxSelect) . " FROM %%USERS%%
                WHERE universe = :universe AND $maxCondition;";
        }
        $max = $db->selectSingle($sql, [':universe' => $uni, ':level' => $level]);

        $wanted = [];
        $conditions = [];
        foreach ($columns as $element => $column) {
            $value = $max['m' . $element] ?? null;
            if ($value !== null && $value > 0) {
                $wanted[$element] = $value;
                $conditions[] = "`$column` = " . (int) $value;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $select = ['`' . $idColumn . '` AS `id`'];
        foreach ($wanted as $element => $value) {
            $select[] = '`' . $columns[$element] . '` AS `c' . $element . '`';
        }
        $sql = "SELECT " . implode(', ', $select) . " FROM $table
            WHERE universe = :universe AND (" . implode(' OR ', $conditions) . ");";
        $rows = $db->select($sql, [':universe' => $uni]);

        $result = [];
        foreach ($wanted as $element => $value) {
            $dataFinal = [];
            $seen = [];
            foreach ($rows as $row) {
                if ($row['c' . $element] != $value) {
                    continue;
                }
                if ($isPlanet) {
                    // DISTINCT id_owner
                    if (isset($seen[$row['id']])) {
                        continue;
                    }
                    $seen[$row['id']] = true;
                }
                $dataFinal[] = ['id' => $row['id'], 'level' => $row['c' . $element], 0 => $element];
            }
            $result[] = $dataFinal;
        }

        return $result;
    }

    private function insertQueries(array $records, int $uni): void
    {
        $db = $this->db;
        $values = [];
        $params = [];
        foreach ($records as $record) {
            foreach ($record as $row) {
                $values[] = '(?, ?, ?, ?)';
                array_push($params, $row['id'], $row['0'], $row['level'], $uni);
            }
        }

        foreach (array_chunk($values, 1000, true) as $offset => $chunk) {
            $sql = "INSERT INTO %%RECORDS%% (userID, elementID, level, universe) VALUES " . implode(', ', $chunk) . ";";
            $db->insert($sql, array_slice($params, array_key_first($chunk) * 4, count($chunk) * 4));
        }
    }

    public function buildRecords(): void
    {
        $db = $this->db;
        $sql = "DELETE FROM %%RECORDS%%;";
        $db->delete($sql);

        foreach ($this->universes as $uni) {
            $tech = $this->getHighestUser('tech', $uni);
            $buildings = $this->getHighestPlanet('build', $uni);
            //$defenses = $this->getHighestPlanet('defense', $uni);
            //$fleet = $this->getHighestPlanet('fleet', $uni);
            //$missiles = $this->getHighestPlanet('missile', $uni);
            $this->insertQueries($tech, $uni);
            $this->insertQueries($buildings, $uni);
        }
    }

    public function archiveStats(): void
    {
        $db = $this->db;
        $sql = "SELECT DISTINCT `uni` FROM %%CONFIG_UNIVERSE%%
            WHERE `key` = 'uni_status' AND `value` IN (:status_open, :status_login)
            ORDER BY `uni` ASC;";
        $uniResult = $db->select($sql, [
            ':status_open' => STATUS_OPEN,
            ':status_login' => STATUS_LOGIN_ONLY,
        ]);

        foreach ($uniResult as $uni) {
            $sql = "INSERT INTO %%STATPOINTS_HISTORY%%
                SELECT :timestamp AS `timestamp`, `id_owner`, `id_ally`, `stat_type`, `universe`,
                `tech_rank`, `tech_points`, `tech_count`, `build_rank`, `build_points`, `build_count`,
                `defs_rank`, `defs_points`, `defs_count`, `fleet_rank`, `fleet_points`, `fleet_count`,
                `total_rank`, `total_points`, `total_count`,
                `honor_points`, `honor_rank`, `honorable_fights`, `dishonorable_fights`, `honor_title`
                FROM %%STATPOINTS%% WHERE `universe` = :universe;";
            $db->insert($sql, [':timestamp' => $this->state->runtime->timestamp, ':universe' => $uni['uni']]);

            $sql = "INSERT INTO %%ADVANCED_STATS_HISTORY%%
                SELECT :timestamp AS `timestamp`, st.*
                    FROM %%ADVANCED_STATS%% st
                    WHERE st.`universe` = :universe;";
            $db->insert($sql, [':timestamp' => $this->state->runtime->timestamp, ':universe' => $uni['uni']]);
        }
    }
}

/*
    Enable to debug
*/
// define('MODE', 'INSTALL');
// define('ROOT_PATH', 'G:/xampp/htdocs/pr0game/');

// require 'includes/pages/game/AbstractGamePage.class.php';
// require 'includes/pages/game/ShowErrorPage.class.php';
// (bootstrap: App\Runtime\GameBootstrap::run())

// // require 'includes/classes/Database.class.php';
// require 'src/Game/Support/vars.php';
// require 'includes/models/StatPoints.php';
// $class = new statbuilder();
// try {
//     $class->generateStats();
// } catch (\Throwable $th) {
//     echo $th;
// }
