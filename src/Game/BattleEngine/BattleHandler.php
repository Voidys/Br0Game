<?php

namespace App\Game\BattleEngine;

use App\Game\Achievements\BattleAchievement;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;

class BattleHandler
{
    use \App\Game\Core\GameUtilities;

    public BattleEngineInterface $engine;

    public function __construct(
        bool $sim,
        private \App\Game\Core\Database $db,
        private \App\Game\Core\ConfigRegistry $configs,
        private \App\Game\Core\Singleton $state,
    ) {
        $this->engine = $this->resolve($sim);
    }

    /**
     * @return array<BattleEngineInterface>
     */
    private function candidates(): array
    {
        return [
            BattleEngineRust::class,
            BattleEngineSteemNova::class,
        ];
    }

    private function resolve(bool $sim = false): BattleEngineInterface
    {
        foreach ($this->candidates() as $class) {
            $engine = new $class($this->db, $this->configs, $this->state);

            if ($engine->isAvailable($sim)) {
                return $engine;
            }
        }

        throw new \RuntimeException('Keine Battle\-Engine verfügbar');
    }

    public function CheckRebuildProtection(array $currentTotalFleetState, array $combatResult, int $combatTime, int $noobProtectionPoints)
    {
        $db = $this->db;

        //Limit to a max of 5.000 points, which is 5.000.000 units, so that it still triggers in universes with high noob protection numbers
        $noobProtectionPoints = min($noobProtectionPoints, 5000);

        $attacker = [];
        foreach ($combatResult['unitLostPerSideAndUser']['attacker'] as $userID => $value) {
            $attacker[] = $userID;
        }

        $noobProtection = $noobProtectionPoints * 1000;
        foreach ($currentTotalFleetState as $userID => $state) {
            $sql = "SELECT `rebuild_protection_locked_until` FROM %%USERS%% WHERE `id` = :userID;";
            $until = $db->selectSingle($sql, [':userID' => $userID], 'rebuild_protection_locked_until');
            if ($until > $combatTime) {
                continue;
            }

            $lostUnits = 0;
            if (isset($combatResult['unitLostPerSideAndUser']['attacker'][$userID])) {
                $lostUnits += $combatResult['unitLostPerSideAndUser']['attacker'][$userID];
            }
            if (isset($combatResult['unitLostPerSideAndUser']['defender'][$userID])) {
                $lostUnits += $combatResult['unitLostPerSideAndUser']['defender'][$userID];
            }

            $lostCapacity = 0;
            if (isset($combatResult['transportCapacityLostPerSideAndUser']['attacker'][$userID])) {
                $lostCapacity += $combatResult['transportCapacityLostPerSideAndUser']['attacker'][$userID];
            }
            if (isset($combatResult['transportCapacityLostPerSideAndUser']['defender'][$userID])) {
                $lostCapacity += $combatResult['transportCapacityLostPerSideAndUser']['defender'][$userID];
            }

            if (($lostUnits > 0 && $lostUnits > $noobProtection && ($lostUnits / $state['totalUnits']) > 0.7) ||
                ($lostCapacity > 0 && $lostCapacity > $noobProtection && ($lostCapacity / $state['totalCapacity']) > 0.8)) {
                $sql = "UPDATE %%USERS%% SET `rebuild_protection` = :someTime, `rebuild_protection_attackers` = :attackers, `rebuild_protection_locked_until` = :someTimeLater
                WHERE `id` = :userID;";
                $db->update($sql, [
                    ':someTime'      => $combatTime,
                    ':attackers'     => json_encode($attacker),
                    ':someTimeLater' => $combatTime + TIME_1_MONTH,
                    ':userID'        => $userID,
                ]);
            }
        }
    }

    public function GenerateReport($combatResult, $reportInfo, $battleType, $attackers, $defenders, $battleEngineName = null)
    {
        $Destroy = ['att' => 0, 'def' => 0];
        $DATA = [];
        $DATA['mode'] = (int)$reportInfo['moonDestroy'];
        $DATA['time'] = $reportInfo['thisFleet']['arrival_time'];
        $DATA['start'] = [$reportInfo['thisFleet']['start_galaxy'], $reportInfo['thisFleet']['start_system'], $reportInfo['thisFleet']['start_planet'], $reportInfo['thisFleet']['start_type']];
        $DATA['koords'] = [$reportInfo['thisFleet']['end_galaxy'], $reportInfo['thisFleet']['end_system'], $reportInfo['thisFleet']['end_planet'], $reportInfo['thisFleet']['end_type']];
        $DATA['units'] = [$combatResult['unitLost']['attacker'], $combatResult['unitLost']['defender']];
        $DATA['debris'] = $reportInfo['debris'];
        $DATA['steal'] = $reportInfo['stealResource'];
        $DATA['result'] = $combatResult['won'];
        $DATA['duration'] = $combatResult['duration'];
        $DATA['moon'] = [
            'moonName'            => $reportInfo['moonName'],
            'moonChance'          => (int)$reportInfo['moonChance'],
            'additionalChance'    => (int)$reportInfo['additionalChance'],
            'moonDestroyChance'   => (int)$reportInfo['moonDestroyChance'],
            'moonDestroySuccess'  => (int)$reportInfo['moonDestroySuccess'],
            'fleetDestroyChance'  => (int)$reportInfo['fleetDestroyChance'],
            'fleetDestroySuccess' => (int)$reportInfo['fleetDestroySuccess'],
        ];
        $DATA['repaired'] = $combatResult['repaired'];
        $DATA['wreckfield'] = $combatResult['wreckfield'];
        if (isset($reportInfo['additionalInfo'])) {
            $DATA['additionalInfo'] = $reportInfo['additionalInfo'];
        } else {
            $DATA['additionalInfo'] = "";
        }

        foreach (['attackers', 'defenders'] as $side) {
            foreach ($$side as $player) {
                $DATA['players'][$player['player']['id']] = [
                    'name'   => $player['player']['username'],
                    'koords' => [
                        $player['fleetDetail']['start_galaxy'],
                        $player['fleetDetail']['start_system'],
                        $player['fleetDetail']['start_planet'],
                        $player['fleetDetail']['start_type'],
                    ],

                    'tech' => [
                        $player['player']['military_tech'] * 100,
                        $player['player']['shield_tech'] * 100,
                        $player['player']['defence_tech'] * 100,
                    ],
                ];
            }
        }

        if ($battleEngineName !== 'rust') {
            foreach ($combatResult['rw'] as $Round => $RoundInfo) {
                foreach ($RoundInfo['attackers'] as $FleetID => $player) {
                    $playerData = ['userID' => $player['player']['id'], 'ships' => []];

                    if (array_sum($player['unit']) == 0) {
                        $DATA['rounds'][$Round]['attacker'][] = $playerData;
                        $Destroy['att']++;
                        continue;
                    }

                    foreach ($player['unit'] as $ShipID => $Amount) {
                        if ($Amount <= 0) {
                            continue;
                        }

                        $ShipInfo = $RoundInfo['infoA'][$FleetID][$ShipID];
                        $playerData['ships'][$ShipID] = [
                            $Amount,
                            $ShipInfo['att'],
                            $ShipInfo['shield'],
                            $ShipInfo['def'],
                        ];
                    }

                    $DATA['rounds'][$Round]['attacker'][] = $playerData;
                }

                foreach ($RoundInfo['defenders'] as $FleetID => $player) {
                    $playerData = ['userID' => $player['player']['id'], 'ships' => []];
                    if (array_sum($player['unit']) == 0) {
                        $DATA['rounds'][$Round]['defender'][] = $playerData;
                        $Destroy['def']++;
                        continue;
                    }

                    foreach ($player['unit'] as $ShipID => $Amount) {
                        if ($Amount <= 0) {
                            // $Destroy['def']++;
                            continue;
                        }

                        $ShipInfo = $RoundInfo['infoD'][$FleetID][$ShipID];
                        $playerData['ships'][$ShipID] = [
                            $Amount,
                            $ShipInfo['att'],
                            $ShipInfo['shield'],
                            $ShipInfo['def'],
                        ];
                    }
                    $DATA['rounds'][$Round]['defender'][] = $playerData;
                }

                if (isset($RoundInfo['attack'], $RoundInfo['attackShield'], $RoundInfo['defense'], $RoundInfo['defShield'])) {
                    $DATA['rounds'][$Round]['info'] = [$RoundInfo['attack'], $RoundInfo['attackShield'], $RoundInfo['defense'], $RoundInfo['defShield']];
                } else {
                    $DATA['rounds'][$Round]['info'] = [null, null, null, null];
                }
            }
        } else {
            $DATA['rounds'] = $combatResult['rw'];
        }
        if ($battleType == REAL_FIGHT) {
            $this->battleAchievements()->checkBattleAchievements($reportInfo, $combatResult, $DATA);

            if ($this->viewHelpers()->isModuleAvailable(MODULE_HONORPOINTS)) {
                $attackers = array_values(array_unique(array_map(function ($p) {
                    return (int)$p['player']['id'];
                }, $combatResult['rw'][0]['attackers'])));
                $countAttackers = count($attackers);

                $defenders = array_values(array_unique(array_map(function ($p) {
                    return (int)$p['player']['id'];
                }, $combatResult['rw'][0]['defenders'])));
                $countDefenders = count($defenders);

                if ($countAttackers == 1 && $countDefenders == 1) {
                    $honorpoints = $this->playerUtil()->calculate_honorpoints_single($attackers[0], $defenders[0], $combatResult['unitLost']['attacker'], $combatResult['unitLost']['defender']);
                } else {
                    $honorpoints = $this->playerUtil()->calculate_honorpoints_multi($combatResult['rw'][0]['attackers'], $combatResult['rw'][0]['defenders'], $combatResult['unitLost']['attacker'], $combatResult['unitLost']['defender']);
                }

                $DATA['honorpoints'] = $honorpoints;
            }
        }

        if ($battleType == EXPO_FIGHT) {
            $this->battleAchievements()->checkExpoBattleAchievements($reportInfo, $combatResult);
        }

        return $DATA;
    }

    public function GenerateWreckField($planetID, $combatResult, int $defenderPlayerID, int $universe)
    {
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK) || !isset($combatResult['wreckfield']) || empty($combatResult['wreckfield'])) {
            return;
        }

        $db = $this->db;

        $sql = "SELECT * FROM %%PLANET_WRECKFIELD%% WHERE `planetID` = :planetID FOR UPDATE;";
        $wreckfield = $db->selectSingle($sql, [':planetID' => $planetID]);

        if (!empty($wreckfield)) {
            $fleetArray = $this->fleetFunctions()->unserialize($wreckfield['ships']);
            foreach ($combatResult['wreckfield'] as $elementID => $amount) {
                if ($amount > 0) {
                    if (!isset($fleetArray[$elementID])) {
                        $fleetArray[$elementID] = $amount;
                    } else {
                        $fleetArray[$elementID] += $amount;
                    }
                }
            }
            $sql = "UPDATE %%PLANET_WRECKFIELD%%
            SET `created` = :created, `ships` = :shipArray
            WHERE `planetID` = :planetID;";
            $db->update($sql, [
                ':planetID'  => $planetID,
                ':created'   => $this->state->runtime->timestamp,
                ':shipArray' => $this->fleetFunctions()->serialize($fleetArray),
            ]);
        } else {
            $sql = "INSERT INTO %%PLANET_WRECKFIELD%%
            SET `planetID` = :planetID, `created` = :created, `ships` = :shipArray;";
            $db->insert($sql, [
                ':planetID'  => $planetID,
                ':created'   => $this->state->runtime->timestamp,
                ':shipArray' => $this->fleetFunctions()->serialize($combatResult['wreckfield']),
            ]);
        }

        // Update defender's wreckfield cooldown
        $sql = "UPDATE %%USERS%% SET `wreckfield_cooldown_until` = :cooldownUntil WHERE `id` = :playerID;";
        $db->update($sql, [
            ':cooldownUntil' => $this->state->runtime->timestamp + $this->configs->get($universe)->wreckfield_cooldown,
            ':playerID'      => $defenderPlayerID,
        ]);
    }

    public function calculateSteal($attackFleets, $defenderPlanet, $universe, $simulate = false)
    {
        // See: http://www.owiki.de/Beute
        $pricelist =& $this->state->pricelist;
        $resource =& $this->state->resource;
        $firstResource = RESOURCE_METAL;
        $secondResource = RESOURCE_CRYSTAL;
        $thirdResource = RESOURCE_DEUT;

        $SortFleets = [];
        $capacity = 0;

        $stealResource = [
            $firstResource  => 0,
            $secondResource => 0,
            $thirdResource  => 0,
        ];

        foreach ($attackFleets as $FleetID => $Attacker) {
            $SortFleets[$FleetID] = 0;

            foreach ($Attacker['unit'] as $Element => $amount) {
                if ($Element != 210) { //Exclude spy drone from raid capacity
                    $SortFleets[$FleetID] += $pricelist[$Element]['capacity'] * $amount;
                }
            }

            $SortFleets[$FleetID] -= $Attacker['fleetDetail']['resource_metal'];
            $SortFleets[$FleetID] -= $Attacker['fleetDetail']['resource_crystal'];
            $SortFleets[$FleetID] -= $Attacker['fleetDetail']['resource_deuterium'];
            if ($SortFleets[$FleetID] < 0) {//if fleet is already full but spydrones are not factored in,
                $SortFleets[$FleetID] = 0; //capacity can be negative, resulting in negative steel numbers.
            }
            $capacity += $SortFleets[$FleetID];
        }

        $AllCapacity = $capacity;
        if ($AllCapacity <= 0) {
            return $stealResource;
        }

        $planetResource = [
            $firstResource  => $defenderPlanet[$resource[$firstResource]],
            $secondResource => $defenderPlanet[$resource[$secondResource]],
            $thirdResource  => $defenderPlanet[$resource[$thirdResource]],
        ];
        $protected = [
            $firstResource  => 0,
            $secondResource => 0,
            $thirdResource  => 0,
        ];

        if ($this->viewHelpers()->isModuleAvailable(MODULE_RESOURCE_STASH) && !$simulate && $defenderPlanet['planet_type'] != 4) {
            $config = $this->configs->get($universe);
            // Up to 10% (1% per resource store level) of daily production is protected from stealing
            $dailyProduction = [
                $firstResource  => ($defenderPlanet['metal_perhour'] + $config->metal_basic_income * ($config->resource_multiplier / 2500)) / 3600 * TIME_24_HOURS,
                $secondResource => ($defenderPlanet['crystal_perhour'] + $config->crystal_basic_income * ($config->resource_multiplier / 2500)) / 3600 * TIME_24_HOURS,
                $thirdResource  => ($defenderPlanet['deuterium_perhour'] + $config->deuterium_basic_income * ($config->resource_multiplier / 2500)) / 3600 * TIME_24_HOURS,
            ];
            $protected = [
                $firstResource  => $dailyProduction[$firstResource] * min(0.1, 0.01 * $defenderPlanet[$resource[METAL_STORE]]),
                $secondResource => $dailyProduction[$secondResource] * min(0.1, 0.01 * $defenderPlanet[$resource[CRYSTAL_STORE]]),
                $thirdResource  => $dailyProduction[$thirdResource] * min(0.1, 0.01 * $defenderPlanet[$resource[DEUTERIUM_STORE]]),
            ];
        }

        $factorHonorPoints = 1;
        if ($defenderPlanet['planet_type'] == 4) {
            $factorHonorPoints = 2; // Pirate Base raids take 100% resources
        } elseif (!$simulate && isset($defenderPlanet['id_owner'])) {
            $factorHonorPoints = $this->playerUtil()->calculate_honorpoints_decrease($defenderPlanet['id_owner']);
        }

        // Step 1
        $stealResource[$firstResource] = min(
            $capacity / 3, //one third of capacity
            $planetResource[$firstResource] / 2 * $factorHonorPoints, //half of metal
            max(0, $planetResource[$firstResource] - $protected[$firstResource]) //leave protected amount
        );
        $capacity -= $stealResource[$firstResource];

        // Step 2
        $stealResource[$secondResource] = min(
            $capacity / 2, //half of rest capacity (= one third or more)
            $planetResource[$secondResource] / 2 * $factorHonorPoints, //half of crystal
            max(0, $planetResource[$secondResource] - $protected[$secondResource]) //leave protected amount
        );
        $capacity -= $stealResource[$secondResource];

        // Step 3
        $stealResource[$thirdResource] = min(
            $capacity, //rest capacity (= one third or more)
            $planetResource[$thirdResource] / 2 * $factorHonorPoints, //half of deut
            max(0, $planetResource[$thirdResource] - $protected[$thirdResource]) //leave protected amount
        );
        $capacity -= $stealResource[$thirdResource];

        // Step 4
        $oldMetalBooty = $stealResource[$firstResource];
        $stealResource[$firstResource] += min(
            $capacity / 2, //half of rest capacity
            $planetResource[$firstResource] / 2 * $factorHonorPoints - $stealResource[$firstResource], //half of metal minus already stolen
            max(0, $planetResource[$firstResource] - $stealResource[$firstResource] - $protected[$firstResource]) //leave protected amount
        );
        $capacity -= $stealResource[$firstResource] - $oldMetalBooty;

        // Step 5
        $stealResource[$secondResource] += min(
            $capacity, //other half of rest capacity (or more)
            $planetResource[$secondResource] / 2 * $factorHonorPoints - $stealResource[$secondResource], //half of crystal minus already stolen
            max(0, $planetResource[$secondResource] - $stealResource[$secondResource] - $protected[$secondResource]) //leave protected amount
        );

        $stealResource[$firstResource] = floor(max(0, $stealResource[$firstResource]));
        $stealResource[$secondResource] = floor(max(0, $stealResource[$secondResource]));
        $stealResource[$thirdResource] = floor(max(0, $stealResource[$thirdResource]));

        if ($simulate) {
            return $stealResource;
        }

        $db = $this->db;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        // Split the loot per fleet in whole units: every share is floored and the remainder goes to the first
        // fleet, so the fleets together get exactly what the planet loses.
        $shares = [];
        $remainder = [
            $firstResource  => $stealResource[$firstResource],
            $secondResource => $stealResource[$secondResource],
            $thirdResource  => $stealResource[$thirdResource],
        ];
        foreach ($SortFleets as $FleetID => $Capacity) {
            foreach ($stealResource as $resourceID => $stolen) {
                $shares[$FleetID][$resourceID] = floor($stolen * $Capacity / $AllCapacity);
                $remainder[$resourceID] -= $shares[$FleetID][$resourceID];
            }
        }
        $firstFleetID = array_key_first($SortFleets);
        foreach ($remainder as $resourceID => $rest) {
            $shares[$firstFleetID][$resourceID] += $rest;
        }

        foreach ($SortFleets as $FleetID => $Capacity) {
            $share = $shares[$FleetID];
            $statsHandler->increment($attackFleets[$FleetID]['player']['id'], AdvancedStatsEnum::RAIDED_METAL, $share[$firstResource]);
            $statsHandler->increment($attackFleets[$FleetID]['player']['id'], AdvancedStatsEnum::RAIDED_CRYSTAL, $share[$secondResource]);
            $statsHandler->increment($attackFleets[$FleetID]['player']['id'], AdvancedStatsEnum::RAIDED_DEUTERIUM, $share[$thirdResource]);

            if ($defenderPlanet['planet_type'] != 4) {
                $statsHandler->increment($defenderPlanet['id_owner'], AdvancedStatsEnum::STOLEN_METAL, $share[$firstResource]);
                $statsHandler->increment($defenderPlanet['id_owner'], AdvancedStatsEnum::STOLEN_CRYSTAL, $share[$secondResource]);
                $statsHandler->increment($defenderPlanet['id_owner'], AdvancedStatsEnum::STOLEN_DEUTERIUM, $share[$thirdResource]);
            }

            $sql = "UPDATE %%FLEETS%% SET
		`resource_metal` = `resource_metal` + '"
                .($share[$firstResource])."',
		`resource_crystal` = `resource_crystal` + '"
                .($share[$secondResource])."',
		`resource_deuterium` = `resource_deuterium` + '"
                .($share[$thirdResource])."'
		WHERE id = :fleetId;";

            $db->update($sql, [
                ':fleetId' => $FleetID,
            ]);

            $sql = "UPDATE %%LOG_FLEETS%% SET
		`gained_metal` = `gained_metal` + '"
                .($share[$firstResource])."',
		`gained_crystal` = `gained_crystal` + '"
                .($share[$secondResource])."',
		`gained_deuterium` = `gained_deuterium` + '"
                .($share[$thirdResource])."'
		WHERE id = :fleetId;";

            $db->update($sql, [
                ':fleetId' => $FleetID,
            ]);
        }

        return $stealResource;
    }

    public function calculateMinCapacity(array $defenderPlanet): int
    {
        $pricelist =& $this->state->pricelist;
        $resource  =& $this->state->resource;

        $targetResource = [
            RESOURCE_METAL   => $defenderPlanet[$resource[RESOURCE_METAL]]   / 2,
            RESOURCE_CRYSTAL => $defenderPlanet[$resource[RESOURCE_CRYSTAL]] / 2,
            RESOURCE_DEUT    => $defenderPlanet[$resource[RESOURCE_DEUT]]    / 2,
        ];

        $shipCapacity = $pricelist[SHIP_SMALL_CARGO]['capacity'];

        $low  = 0;
        $high = max(
            $shipCapacity,
            (int)ceil(array_sum($targetResource) / $shipCapacity) * $shipCapacity
        );

        while (!$this->canStealHalf($high, $targetResource)) {
            $high *= 2;
        }

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($this->canStealHalf($mid, $targetResource)) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }

        return $high;
    }

    private function canStealHalf(int $capacity, array $targetResource): bool
    {
        $stolenMetal = min($capacity / 3, $targetResource[RESOURCE_METAL]);
        $remaining   = $capacity - $stolenMetal;

        $stolenCrystal = min($remaining / 2, $targetResource[RESOURCE_CRYSTAL]);
        $remaining    -= $stolenCrystal;

        $stolenDeut = min($remaining, $targetResource[RESOURCE_DEUT]);
        $remaining -= $stolenDeut;

        $extraMetal   = min($remaining / 2, max(0, $targetResource[RESOURCE_METAL] - $stolenMetal));
        $stolenMetal += $extraMetal;
        $remaining   -= $extraMetal;

        $stolenCrystal += min($remaining, max(0, $targetResource[RESOURCE_CRYSTAL] - $stolenCrystal));

        return $stolenMetal   >= $targetResource[RESOURCE_METAL]
            && $stolenCrystal >= $targetResource[RESOURCE_CRYSTAL]
            && $stolenDeut    >= $targetResource[RESOURCE_DEUT];
    }
}