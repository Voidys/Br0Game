<?php

namespace App\Game\Core;

/**
 * Statistics-derived fleet figures: the point-based fleet size caps (used by expedition finds) and a user's total
 * fleet units/capacity across planets and flights.
 *
 * Collaborators: Database (STATPOINTS, FLEETS, PLANETS), Singleton (resource/reslist/pricelist tables).
 */
class FleetStatistics
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Fleet size cap tier derived from the top player's total points of the universe (double this for resource finds).
     */
    public function calculateMaxFactor(int $universeId): int
    {
        $sql = "SELECT MAX(`total_points`) as total FROM %%STATPOINTS%%
            WHERE `stat_type` = :type AND `universe` = :universe;";

        $topPoints = $this->db->selectSingle($sql, [
            ':type' => 1,
            ':universe' => $universeId,
        ], 'total');

        if ($topPoints > 100000000) {
            return 12500;
        } elseif ($topPoints > 75000000) {
            return 10500;
        } elseif ($topPoints > 50000000) {
            return 9000;
        } elseif ($topPoints > 25000000) {
            return 7500;
        } elseif ($topPoints > 5000000) {
            return 6000;
        } elseif ($topPoints > 1000000) {
            return 4500;
        } elseif ($topPoints > 100000) {
            return 3000;
        } elseif ($topPoints > 10000) {
            return 1250;
        } else {
            return 100;
        }
    }

    /** Resource find cap: twice the tier, except that 2500 is read as 2400 (value differs from the table). */
    public function calculateMaxFactorRes(int $universeId): int
    {
        $maxFactor = $this->calculateMaxFactor($universeId) * 2;
        return ($maxFactor != 2500 ? $maxFactor : 2400);
    }

    /**
     * Sum of metal+crystal cost ("units") and cargo capacity of all ships of a user: on planets plus in flight.
     *
     * @return array{totalUnits: int|float, totalCapacity: int|float}
     */
    public function calculateCurrentTotalFleetState(int $userID): array
    {
        $db = $this->db;
        $resource = $this->state->resource;
        $reslist = $this->state->reslist;
        $pricelist = $this->state->pricelist;

        $FlyingFleets = [];
        $sql = 'SELECT `ships` FROM %%FLEETS%% WHERE `owner` = :userID;';
        $SQLFleets = $db->select($sql, [
            ':userID' => $userID,
        ]);
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
                if (!isset($FlyingFleets[$Ship[0]])) {
                    $FlyingFleets[$Ship[0]] = $Ship[1];
                } else {
                    $FlyingFleets[$Ship[0]] += $Ship[1];
                }
            }
        }

        $select_fleets = '';
        foreach ($reslist['fleet'] as $shipID) {
            $select_fleets .= " SUM(p.`" . $resource[$shipID] . "`) as " . $resource[$shipID] . ",";
        }
        $select_fleets = rtrim($select_fleets, ",");
        $sql = 'SELECT ' . $select_fleets . ' FROM %%PLANETS%% as p WHERE p.`id_owner` = :userID AND p.`destruyed` = 0;';

        $userData = $db->selectSingle($sql, [
            ':userID' => $userID,
        ]);

        if (!empty($FlyingFleets)) {
            foreach ($FlyingFleets as $ID => $Amount) {
                if (!isset($userData[$resource[$ID]])) {
                    continue; //Missiles and any unknown
                }
                $userData[$resource[$ID]] += $Amount;
            }
        }

        $totalUnits = 0;
        $totalCapacity = 0;
        foreach ($reslist['fleet'] as $shipID) {
            if (!isset($userData[$resource[$shipID]]) || $userData[$resource[$shipID]] == 0) {
                continue;
            }
            $amount = $userData[$resource[$shipID]];

            $Units = $pricelist[$shipID]['cost'][901] + $pricelist[$shipID]['cost'][902];
            $totalUnits += $Units * $amount;
            $totalCapacity += $pricelist[$shipID]['capacity'] * $amount;
        }

        return ['totalUnits' => $totalUnits, 'totalCapacity' => $totalCapacity];
    }
}
