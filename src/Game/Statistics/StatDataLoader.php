<?php

namespace App\Game\Statistics;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\Core\Database;

/** Reads the data the statistics run works on (see load()). Read only. */
class StatDataLoader
{
    /**
     * @param list<int>                                $universes ids of all universes
     * @param array<int, array<int, string>>           $resources universe => element id => column name
     * @param array<int, array<string, array<int>>>    $reslists  universe => list name => element ids
     */
    public function __construct(
        private readonly Database $db,
        private readonly array $universes,
        private readonly array $resources,
        private readonly array $reslists,
    ) {}

    /**
     * Loads the raw data of one statistics run for all universes: planets with building levels, users with research
     * levels and ship/defense sums over their planets (plus their old ranks), the ships of flying fleets per owner, the
     * honor data and the alliances (with old ranks).
     *
     * @return array{Planets: list<array<string, mixed>>, Users: list<array<string, mixed>>, Fleets: array<int, array<int, int|string>>, Alliance: list<array<string, mixed>>, Honor: array<int, array<string, int>>}
     */
    public function load(): array
    {
        $database = $this->db;
        $Return = [
            'Planets' => [],
            'Users' => [],
            'Fleets' => [],
            'Alliance' => [],
            'Honor' => [],
        ];
        foreach ($this->universes as $uni) {
            $Return['Fleets'] = array_replace($Return['Fleets'], $this->flyingShips($uni));
            $Return['Honor'] = array_replace($Return['Honor'], $this->getHonorDataByUniverse($uni));
            $Return['Planets'] = array_merge($Return['Planets'], $this->loadPlanets($uni));
            $Return['Users'] = array_merge($Return['Users'], $this->loadUsers($uni));
        }
        $Return['Alliance'] = $database->select(
            "SELECT SQL_BIG_RESULT DISTINCT a.`id`, a.`ally_universe`, s.`tech_rank` AS old_tech_rank, s.`build_rank` AS old_build_rank,
                s.`defs_rank` AS old_defs_rank, s.`fleet_rank` AS old_fleet_rank, s.`total_rank` AS old_total_rank
            FROM %%ALLIANCE%% as a
            LEFT JOIN %%STATPOINTS%% as s ON s.`stat_type` = 2 AND s.`id_owner` = a.`id`
            GROUP BY a.`id`;",
        );

        return $Return;
    }

    /**
     * Ships in flight per owner and ship type of a universe (sums over all fleets of the owner).
     *
     * @return array<int, array<int, int|string>> owner id => ship id => amount
     */
    private function flyingShips(int $uni): array
    {
        $database = $this->db;
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

        return $FlyingFleets;
    }

    /**
     * Planets of a universe (not awaiting deletion) with the levels of all buildings and their owner's name, ban and
     * authlevel.
     *
     * @return list<array<string, mixed>>
     */
    private function loadPlanets(int $uni): array
    {
        $database = $this->db;
        $resource = $this->resources[$uni];
        $select_buildings = '';
        foreach ($this->reslists[$uni]['build'] as $Building) {
            $select_buildings .= " p." . $resource[$Building] . ",";
        }

        return $database->select(
            "SELECT SQL_BIG_RESULT $select_buildings p.`id`, p.`universe`, p.`id_owner`, u.`authlevel`, u.`bana`, u.`username` 
            FROM %%PLANETS%% as p 
            LEFT JOIN %%USERS%% as u ON u.`id` = p.`id_owner` 
            WHERE p.`universe` = :universe and p.`destruyed` = 0;",
            [':universe' => $uni],
        );
    }

    /**
     * Players of a universe with their research levels, the ship and defense sums over their planets and their ranks of
     * the last statistics run (old_*_rank).
     *
     * @return list<array<string, mixed>>
     */
    private function loadUsers(int $uni): array
    {
        $database = $this->db;
        $resource = $this->resources[$uni];
        $reslist = $this->reslists[$uni];
        $select_defenses = '';
        $selected_tech = '';
        $select_fleets = '';

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

        return $database->select(
            "SELECT SQL_BIG_RESULT $selected_tech $select_fleets $select_defenses u.`id`, u.`ally_id`, u.`authlevel`,
                u.`bana`, u.`universe`, u.`username`, u.`onlinetime`, s.`tech_rank` AS old_tech_rank, s.`build_rank` AS old_build_rank,
                s.`defs_rank` AS old_defs_rank, s.`fleet_rank` AS old_fleet_rank, s.`total_rank` AS old_total_rank, s.`honor_rank` AS old_honor_rank, s.`units_rank` AS old_units_rank
            FROM %%USERS%% as u
            LEFT JOIN %%STATPOINTS%% as s ON s.`stat_type` = 1 AND s.`id_owner` = u.`id`
            LEFT JOIN %%PLANETS%% as p ON u.`id` = p.`id_owner`
            WHERE u.`universe` = :universe
            GROUP BY s.`id_owner`, u.`id`, u.`authlevel`;",
            [':universe' => $uni],
        );
    }

    /**
     * Honor figures per user of a universe from the advanced stats: honor points, honorable and dishonorable fights and
     * the units points (really destroyed minus really lost units).
     *
     * @return array<int, array{honor_points: int, honorable_fights: int, dishonorable_fights: int, units_points: int}>
     */
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
}
