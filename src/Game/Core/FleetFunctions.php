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

use InvalidArgumentException;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;

class FleetFunctions
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    public static $allowedSpeed = [
        10 => 100,
        9 => 90,
        8 => 80,
        7 => 70,
        6 => 60,
        5 => 50,
        4 => 40,
        3 => 30,
        2 => 20,
        1 => 10
    ];

    private function getShipConsumption(int $Ship, array $Player): float
    {
        $pricelist = & $this->state->pricelist;

        if (($pricelist[$Ship]['tech3'] > 1 && $Player['hyperspace_motor_tech'] >= $pricelist[$Ship]['tech3']) ||
            $pricelist[$Ship]['tech3'] == 1) {
            $consumption = $pricelist[$Ship]['consumption3'];
        } elseif (($pricelist[$Ship]['tech2'] > 1 && $Player['impulse_motor_tech'] >= $pricelist[$Ship]['tech2']) ||
            $pricelist[$Ship]['tech2'] == 1) {
            $consumption = $pricelist[$Ship]['consumption2'];
        } else {
            $consumption = $pricelist[$Ship]['consumption1'];
        }

        $consumption *= (1 - (0.02 * $Player['fuel_efficiency_tech']));

        return $consumption;
    }

    private function onlyShipByID(array $Ships, int $ShipID): bool
    {
        return isset($Ships[$ShipID]) && count($Ships) === 1;
    }

    private function getShipSpeed(int $Ship, array $Player): int
    {
        $pricelist = & $this->state->pricelist;

        if (($pricelist[$Ship]['tech3'] > 1 && $Player['hyperspace_motor_tech'] >= $pricelist[$Ship]['tech3']) ||
            $pricelist[$Ship]['tech3'] == 1) {
            $base_speed = $pricelist[$Ship]['speed3'];
            $speed = $base_speed * (1 + (0.3 * $Player['hyperspace_motor_tech']));
        } elseif (($pricelist[$Ship]['tech2'] > 1 && $Player['impulse_motor_tech'] >= $pricelist[$Ship]['tech2']) ||
            $pricelist[$Ship]['tech2'] == 1) {
            $base_speed = $pricelist[$Ship]['speed2'];
            $speed = $base_speed * (1 + (0.2 * $Player['impulse_motor_tech']));
        } else {
            $base_speed = $pricelist[$Ship]['speed1'];
            $speed = $base_speed * (1 + (0.1 * $Player['combustion_tech']));
        }

        return $speed;
    }

    public function getExpeditionLimit(array $USER): int
    {
        $resource = & $this->state->resource;

        return floor(sqrt($USER[$resource[124]]));
    }

    public function inMissileRange(array $planet, array $target, int $levelIonEngine): bool
    {
        if ($target['galaxy'] != $planet['galaxy']) {
            return false;
        }
        $config = $this->configs->get();
        $Range = max(floor((($levelIonEngine * 5) - 1) * $config->missile_range_factor), 0);

        if ($config->galaxy_type == 2) {
            return $Range > 1;
        } elseif ($config->galaxy_type == 1) {
            $max = $config->max_system;
            $thisSystem = $planet['system'];
            $toSystem = $target['system'];

            if ($thisSystem + $Range > $max) {
                $systemMax = $thisSystem + $Range - $max;
            } else {
                $systemMax = $thisSystem + $Range;
            }

            if ($thisSystem - $Range < 1) {
                $systemMin = $max + ($thisSystem - $Range);
            } else {
                $systemMin = $thisSystem - $Range;
            }

            if ($Range * 2 >= $max) {
                return true;
            } elseif ($systemMin <= $systemMax) {
                return $toSystem >= $systemMin && $toSystem <= $systemMax;
            } else {
                return ($toSystem >= $systemMin && $toSystem <= $max) || ($toSystem >= 1 && $toSystem <= $systemMax);
            }
        } else {
            $systemMin = $planet['system'] - $Range;
            $systemMax = $planet['system'] + $Range;

            return $target['system'] >= $systemMin && $target['system'] <= $systemMax;
        }
    }

    public function checkUserSpeed(int $speed): bool
    {
        return isset(self::$allowedSpeed[$speed]);
    }

    /**
     * Calculates the distance to the target
     *
     * Changes needs to be done in scripts/game/flotten.js#GetDistance() aswell
     *
     * @return int
     */
    public function getTargetDistance(array $start, array $target): int
    {
        $thisGalaxy = $start[0];
        $thisSystem = $start[1];
        $thisPlanet = $start[2];
        $targetGalaxy = $target[0];
        $targetSystem = $target[1];
        $targetPlanet = $target[2];
        $config = $this->configs->get();

        if ($thisGalaxy != $targetGalaxy) {
            if ($config->uni_type == 2) {
                return $config->galaxy_distance_factor;
            } elseif ($config->uni_type == 1) {
                $max = $config->max_galaxy;
                $dist = 0;

                if (abs($thisGalaxy - $targetGalaxy) < ceil($max / 2)) {
                    $dist = abs($thisGalaxy - $targetGalaxy);
                } else {
                    $dist = floor($max / 2) - (abs($thisGalaxy - $targetGalaxy) - ceil($max / 2));
                }
                return $dist * $config->galaxy_distance_factor;
            } else {
                return abs($thisGalaxy - $targetGalaxy) * $config->galaxy_distance_factor;
            }
        } elseif ($thisSystem != $targetSystem) {
            if ($config->galaxy_type == 2) {
                return $config->system_distance_factor + $config->system_distance;
            } elseif ($config->galaxy_type == 1) {
                $max = $config->max_system;
                $dist = 0;

                if (abs($thisSystem - $targetSystem) < ceil($max / 2)) {
                    $dist = abs($thisSystem - $targetSystem);
                } else {
                    $dist = floor($max / 2) - (abs($thisSystem - $targetSystem) - ceil($max / 2));
                }
                return $dist * $config->system_distance_factor + $config->system_distance;
            } else {
                return abs($thisSystem - $targetSystem) * $config->system_distance_factor + $config->system_distance;
            }
        } elseif ($thisPlanet != $targetPlanet) {
            return abs($thisPlanet - $targetPlanet) * $config->planet_distance_factor + $config->planet_distance;
        }
        return 5;
    }

    /**
     * Travel time in seconds.
     * Keep scripts/game/flotten.js#GetRawDuration() and ConfigUni.tpl#getDuration() in sync.
     */
    public function getMissionDuration(int $SpeedFactor, int $MaxFleetSpeed, int $Distance, int $GameSpeed, array $USER): int
    {
        $duration = (3500 / ($SpeedFactor * 0.1));
        $duration *= pow($Distance * 10 / $MaxFleetSpeed, 0.5);
        $duration += 10;
        $duration /= $GameSpeed;

        if (isset($USER['factor']['FlyTime'])) {
            $duration *= max(0, 1 + $USER['factor']['FlyTime']);
        }

        return floor(max($duration, MIN_FLEET_TIME));
    }

    public function getMIPDuration(int $startSystem, int $targetSystem): int
    {
        $config = $this->configs->get();
        if ($config->galaxy_type == 2) {
            $Distance = 2;
        } elseif ($config->galaxy_type == 1) {
            $max = $config->max_system;
            if (abs($startSystem - $targetSystem) < ceil($max / 2)) {
                $Distance = abs($startSystem - $targetSystem);
            } else {
                $Distance = floor($max / 2) - (abs($startSystem - $targetSystem) - ceil($max / 2));
            }
        } else {
            $Distance = abs($startSystem - $targetSystem);
        }

        $Duration = max(round((30 + 60 * $Distance) / $this->getGameSpeedFactor()), MIN_FLEET_TIME);

        return floor($Duration);
    }

    public function getGameSpeedFactor(): int
    {
        return $this->configs->get()->fleet_speed / 2500;
    }

    public function getMaxFleetSlots(array $USER): int
    {
        $resource = & $this->state->resource;
        return 1 + $USER[$resource[108]];
    }

    public function getFleetRoom(array $Fleet): int
    {
        $pricelist = & $this->state->pricelist;
        $FleetRoom = 0;
        foreach ($Fleet as $ShipID => $amount) {
            $FleetRoom += $pricelist[$ShipID]['capacity'] * $amount;
        }
        return floor($FleetRoom);
    }

    public function getFleetMaxSpeed(array|int $Fleets, array $Player): int
    {
        if (empty($Fleets)) {
            return 0;
        }

        $FleetArray = (!is_array($Fleets)) ? [$Fleets => 1] : $Fleets;
        $speedalls = [];

        foreach ($FleetArray as $ShipID => $Count) {
            $speedalls[$ShipID] = $this->getShipSpeed($ShipID, $Player);
        }

        return min($speedalls);
    }

    public function getFleetConsumption(array $FleetArray, int $MissionDuration, int $MissionDistance, array $Player, int $GameSpeed): float
    {
        $consumption = 0;

        foreach ($FleetArray as $ShipID => $Count) {
            $ShipSpeed = $this->getShipSpeed($ShipID, $Player);
            $ShipConsumption = $this->getShipConsumption($ShipID, $Player);

            $spd = 35000 / max($MissionDuration * $GameSpeed - 10, 1) * sqrt($MissionDistance * 10 / $ShipSpeed);
            $basicConsumption = $ShipConsumption * $Count;
            $consumption += $basicConsumption * $MissionDistance / 35000 * (($spd / 10) + 1) * (($spd / 10) + 1);
        }
        return (round($consumption * $this->playerUtil()->calculate_honorpoints_boost($Player['id'])) + 1);
    }

    public function getFleetMissions(array $USER, array $MisInfo, array $Planet): array
    {
        $resource = & $this->state->resource;
        $Missions = $this->getAvailableMissions($USER, $MisInfo, $Planet);
        $stayBlock = [];
        $exchange = false;

        $haltSpeed = $this->configs->get($USER['universe'])->expo_hold_multiplier;

        if (in_array(MISSION_EXPEDITION, $Missions)) {
            for ($i = 1; $i <= $USER[$resource[124]]; $i++) {
                $stayBlock[$i] = round($i / $haltSpeed, 2);
            }
        } elseif (in_array(MISSION_HOLD, $Missions)) {
            $stayBlock = [1 => 1, 2 => 2, 4 => 4, 8 => 8, 12 => 12, 16 => 16, 32 => 32];
        } elseif (in_array(MISSION_TRADE, $Missions)) {
            $stayBlock = [1 => 1, 2 => 2, 4 => 4, 8 => 8, 12 => 12, 24 => 24, 48 => 48, 168 => 168];
            $exchange = true;
        }

        return ['MissionSelector' => $Missions, 'StayBlock' => $stayBlock, 'Exchange' => $exchange];
    }

    /*
     *
     * Unserialize an Fleetstring to an array
     *
     * @param string
     *
     * @return array
     *
     */

    public function unserialize(string $fleetAmount): array
    {
        if (empty($fleetAmount)) {
            return [];
        }

        $fleetTyps = explode(';', $fleetAmount);

        $fleetAmount = [];

        foreach ($fleetTyps as $fleetTyp) {
            $temp = explode(',', $fleetTyp);

            if (empty($temp[0])) {
                continue;
            }

            if (!isset($fleetAmount[$temp[0]])) {
                $fleetAmount[$temp[0]] = 0;
            }

            $fleetAmount[$temp[0]] += $temp[1];
        }

        return $fleetAmount;
    }

    public function serialize(array $fleet): string
    {
        $serialized = '';
        if (empty($fleet)) {
            return $serialized;
        }

        foreach ($fleet as $elementID => $amount) {
            if ($amount > 0) {
                $serialized .= $elementID . ',' . floatToString($amount) . ';';
            }
        }

        return empty($fleet) ? $serialized : substr($serialized, 0, -1);
    }

    public function getACSDuration(int $acsId): int
    {
        if (empty($acsId)) {
            return 0;
        }

        $sql = 'SELECT ankunft FROM %%AKS%% WHERE id = :acsId;';
        $acsEndTime = $this->db->selectSingle($sql, [
            ':acsId' => $acsId
        ], 'ankunft');

        return empty($acsEndTime) ? 0 : floor($acsEndTime - $this->state->runtime->timestamp);
    }

    public function setACSTime(int $timeDifference, int $acsId): bool
    {
        if (empty($acsId)) {
            throw new InvalidArgumentException('Missing acsId on ' . __CLASS__ . '::' . __METHOD__);
        }

        $db = $this->db;

        $sql = 'UPDATE %%AKS%% SET ankunft = ankunft + :time WHERE id = :acsId;';
        $db->update($sql, [
            ':time' => $timeDifference,
            ':acsId' => $acsId,
        ]);

        $sql = 'UPDATE %%FLEETS%%, %%FLEETS_EVENT%% SET
            `arrival_time` = arrival_time + :time,
            `stay_time`   = stay_time + :time,
            `return_time` = return_time + :time,
            `time`        = time + :time
            WHERE `group` = :acsId AND `id` = fleetID;';

        $db->update($sql, [
            ':time'     => $timeDifference,
            ':acsId'    => $acsId,
        ]);

        $sql = 'SELECT `id`, `arrival_time`, `stay_time`, `return_time`
            FROM %%LOG_FLEETS%%
            WHERE `group` = :acsId;';
        $logFleets = $db->select($sql, [
            ':acsId' => $acsId,
        ]);

        $sql = 'UPDATE %%LOG_FLEETS%% SET
            `arrival_time`           = :arrival_time,
            `arrival_time_formated`  = :arrival_time_formated,
            `stay_time`             = :stay_time,
            `stay_time_formated`    = :stay_time_formated,
            `return_time`           = :return_time,
            `return_time_formated`  = :return_time_formated
            WHERE `id` = :id;';
        foreach ($logFleets as $logFleet) {
            $db->update($sql, [
                ':arrival_time'          => $logFleet['arrival_time'] + $timeDifference,
                ':arrival_time_formated' => $this->db->formatDate($logFleet['arrival_time'] + $timeDifference),
                ':stay_time'            => $logFleet['stay_time'] + $timeDifference,
                ':stay_time_formated'   => $this->db->formatDate($logFleet['stay_time'] + $timeDifference),
                ':return_time'          => $logFleet['return_time'] + $timeDifference,
                ':return_time_formated' => $this->db->formatDate($logFleet['return_time'] + $timeDifference),
                ':id'                   => $logFleet['id'],
            ]);
        }

        return true;
    }

    public function getCurrentFleets(int $userId, int $fleetMission = 10, bool $thisMission = false): int
    {
        if ($thisMission) {
            $sql = 'SELECT COUNT(*) as state
                FROM %%FLEETS%%
                WHERE `owner` = :userId AND `mission` = :fleetMission;';
        } else {
            $sql = 'SELECT COUNT(*) as state
                FROM %%FLEETS%%
                WHERE `owner` = :userId AND `mission` != :fleetMission;';
        }

        $ActualFleets = $this->db->selectSingle($sql, [
            ':userId' => $userId,
            ':fleetMission' => $fleetMission,
        ]);
        return $ActualFleets['state'];
    }

    public function sendFleetBack(array $USER, int $FleetID): bool
    {
        $db = $this->db;

        $sql = 'SELECT `start_time`, `arrival_time`, `mission`, `group`, `owner`, `status`
            FROM %%FLEETS%%
            WHERE `id` = :fleetId AND `cannotCancel` = :cannotCancel;';
        $fleetResult = $db->selectSingle($sql, [
            ':fleetId' => $FleetID,
            ':cannotCancel' => 0,
        ]);

        if (empty($fleetResult)) {
            return false;
        }

        if (empty($fleetResult['start_time'])) {
            $fleetResult['start_time'] = 0;
        }
        if (empty($fleetResult['arrival_time'])) {
            $fleetResult['arrival_time'] = 0;
        }
        if (empty($fleetResult['mission'])) {
            $fleetResult['mission'] = 0;
        }
        if (empty($fleetResult['group'])) {
            $fleetResult['group'] = 0;
        }
        if (empty($fleetResult['owner'])) {
            $fleetResult['owner'] = 0;
        }
        if (empty($fleetResult['status'])) {
            $fleetResult['status'] = FLEET_OUTWARD;
        }

        if ($fleetResult['owner'] != $USER['id'] || $fleetResult['status'] == FLEET_RETURN) {
            return false;
        }

        // Interplanetary missiles cannot be recalled (their return event does nothing)
        if ($fleetResult['mission'] == MISSION_MISSILE) {
            return false;
        }

        $sqlWhere = '`id`';

        if ($fleetResult['mission'] == MISSION_ATTACK && $fleetResult['group'] != 0) {
            $sql = 'SELECT COUNT(*) as state FROM %%USERS_TO_ACS%% WHERE `acsID` = :acsId;';
            $isInGroup = $db->selectSingle($sql, [
                ':acsId' => $fleetResult['group'],
            ], 'state');

            if ($isInGroup) {
                $sql = 'DELETE %%AKS%%, %%USERS_TO_ACS%%
                    FROM %%AKS%%
                    LEFT JOIN %%USERS_TO_ACS%% ON `acsID` = %%AKS%%.id
                    WHERE %%AKS%%.id = :acsId;';

                $db->delete($sql, [
                    ':acsId' => $fleetResult['group']
                ]);

                $FleetID = $fleetResult['group'];
                $sqlWhere = '`group`';
            }
        }

        if (
            ($fleetResult['mission'] == MISSION_HOLD || $fleetResult['mission'] == MISSION_TRADE)
            && $fleetResult['status'] == FLEET_HOLD
        ) {
            $endTime = ($fleetResult['arrival_time'] - $fleetResult['start_time']) + $this->state->runtime->timestamp;
        } else {
            $endTime = ($this->state->runtime->timestamp - $fleetResult['start_time']) + $this->state->runtime->timestamp;
        }

        // An ACS group is recalled as a whole, but every fleet gets its return time from its own start time
        $recallFleets = [$FleetID => $endTime];
        if ($sqlWhere === '`group`') {
            $recallFleets = [];
            $groupFleets = $db->select('SELECT `id`, `start_time` FROM %%FLEETS%% WHERE `group` = :id;', [
                ':id' => $FleetID,
            ]);
            foreach ($groupFleets as $groupFleet) {
                $recallFleets[$groupFleet['id']] = ($this->state->runtime->timestamp - $groupFleet['start_time'])
                    + $this->state->runtime->timestamp;
            }
        }

        foreach ($recallFleets as $recallFleetId => $recallEndTime) {
            $sql = 'UPDATE %%FLEETS%%, %%FLEETS_EVENT%% SET `group` = :fleetGroup,
                `stay_time`     = :endStayTime,
                `return_time`   = :endTime,
                `status`        = :fleetState,
                `hasCanceled`   = :hasCanceled,
                `time`          = :endTime
                WHERE `id` = :id AND `id` = `fleetID`;';

            $db->update($sql, [
                ':id'           => $recallFleetId,
                ':endStayTime'  => $this->state->runtime->timestamp,
                ':endTime'      => $recallEndTime,
                ':fleetGroup'   => 0,
                ':hasCanceled'  => 1,
                ':fleetState'   => FLEET_RETURN
            ]);

            $sql = 'UPDATE %%LOG_FLEETS%% SET
                `stay_time`             = :endStayTime,
                `stay_time_formated`    = :stayTimeFormated,
                `return_time`           = :endTime,
                `return_time_formated`  = :endTimeFormated,
                `status`                = :fleetState,
                `hasCanceled`           = :hasCanceled
                WHERE `id` = :id;';

            $db->update($sql, [
                ':id' => $recallFleetId,
                ':endStayTime' => $this->state->runtime->timestamp,
                ':endTime' => $recallEndTime,
                ':stayTimeFormated' => $this->db->formatDate($this->state->runtime->timestamp),
                ':endTimeFormated' => $this->db->formatDate($recallEndTime),
                ':fleetState' => FLEET_RETURN,
                ':hasCanceled' => 1
            ]);
        }

        return true;
    }

    public function getFleetShipInfo(array $FleetArray, array $Player): array
    {
        $FleetInfo = [];
        foreach ($FleetArray as $ShipID => $Amount) {
            $FleetInfo[$ShipID] = [
                'consumption' => $this->getShipConsumption($ShipID, $Player),
                'speed' => $this->getFleetMaxSpeed($ShipID, $Player),
                'amount' => floatToString($Amount)
            ];
        }
        return $FleetInfo;
    }

    public function gotoFleetPage($Code = 0): void
    {
        $LNG = & $this->state->LNG;
        if ($this->configs->get()->debug == 1) {
            $temp = debug_backtrace();
            echo sprintf(
                "%s on %d | Code: %d | Error: %s",
                str_replace($this->state->requestServer["DOCUMENT_ROOT"], '.', $temp[0]['file']),
                $temp[0]['line'],
                $Code,
                (isset($LNG['fl_send_error'][$Code]) ? $LNG['fl_send_error'][$Code] : '')
            );
            throw new \App\Game\Core\EndOfRequest();
        }

        $this->http()->redirectTo('game?page=fleetTable&code=' . $Code);
    }

    public function getAvailableMissions(array $USER, array $MissionInfo, array $GetInfoPlanet): array
    {
        $reslist = & $this->state->reslist;
        $YourPlanet = (!empty($GetInfoPlanet['id_owner']) && $GetInfoPlanet['id_owner'] == $USER['id']) ? true : false;
        $UsedPlanet = (!empty($GetInfoPlanet['id_owner'])) ? true : false;
        $ExpeditionPosition = ($MissionInfo['planet'] == ($this->configs->get($USER['universe'])->max_planets + 1)) ? true : false;
        $TradePosition = ($MissionInfo['planet'] == ($this->configs->get($USER['universe'])->max_planets + 2)) ? true : false;
        $PirateBasePosition = ($MissionInfo['planet'] == ($this->configs->get($USER['universe'])->max_planets + 3)) ? true : false;
        $availableMissions = [];

        if ($ExpeditionPosition && $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_EXPEDITION)) {
            $availableMissions[] = MISSION_EXPEDITION;
        } elseif ($TradePosition && $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_TRADE)) {
            $availableMissions[] = MISSION_TRADE;
        } elseif ($PirateBasePosition && $this->viewHelpers()->isModuleAvailable(MODULE_PIRATE_BASES) && $MissionInfo['planettype'] != 2) {
            if ($YourPlanet && $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_ATTACK)) {
                $availableMissions[] = MISSION_PIRATEBASE_ATTACK;
            }
            if (!empty($MissionInfo['IsAKS']) && $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_ACS)) {
                $availableMissions[] = MISSION_ACS;
            }
            if ($YourPlanet && $this->onlyShipByID($MissionInfo['Ship'], 210) && $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_SPY)) {
                $availableMissions[] = MISSION_PIRATEBASE_SPY;
            }
        } elseif ($MissionInfo['planettype'] == 2 && $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_RECYCLE)) {
            $recs = false;
            foreach ($MissionInfo['Ship'] as $ShipID => $ShipCount) {
                if (isset($reslist['ship_type'][SHIP_TYPE_RECYCLER][$ShipID]) && $ShipCount > 0) {
                    $recs = true;
                    break;
                }
            }
            if ($recs && $UsedPlanet && !empty($GetInfoPlanet)) {
                if ($PirateBasePosition && $YourPlanet && $this->viewHelpers()->isModuleAvailable(MODULE_PIRATE_BASES)) {
                    $availableMissions[] = MISSION_PIRATEBASE_RECYCLING;
                } elseif (($GetInfoPlanet['der_metal'] + $GetInfoPlanet['der_crystal']) > 0 || $GetInfoPlanet['tf_active'] == 1) {
                    $availableMissions[] = MISSION_RECYCLING;
                }
            }
        } elseif (
            !empty($MissionInfo['IsAKS']) && !$YourPlanet && $UsedPlanet &&
            ($this->viewHelpers()->isModuleAvailable(MODULE_MISSION_ATTACK) || $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_ACS))
        ) {
            if ($this->viewHelpers()->isModuleAvailable(MODULE_MISSION_ATTACK)) {
                $availableMissions[] = MISSION_ATTACK;
            }
            if ($this->viewHelpers()->isModuleAvailable(MODULE_MISSION_ACS)) {
                $availableMissions[] = MISSION_ACS;
            }
        } else {
            if (!$UsedPlanet) {
                if (
                    isset($MissionInfo['Ship'][208]) && $MissionInfo['planettype'] == 1
                    && $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_COLONY)
                ) {
                    $availableMissions[] = MISSION_COLONISATION;
                }
            } else {
                if ($this->viewHelpers()->isModuleAvailable(MODULE_MISSION_TRANSPORT)) {
                    $MissionInfo['planet'];
                    $availableMissions[] = MISSION_TRANSPORT;
                }

                if (
                    !$YourPlanet && $this->onlyShipByID($MissionInfo['Ship'], 210)
                    && $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_SPY)
                ) {
                    $availableMissions[] = MISSION_SPY;
                }

                if (!$YourPlanet) {
                    if ($this->viewHelpers()->isModuleAvailable(MODULE_MISSION_TRANSFER)) {
                        $availableMissions[] = MISSION_TRANSFER;
                    }

                    if ($this->viewHelpers()->isModuleAvailable(MODULE_MISSION_ATTACK)) {
                        $availableMissions[] = MISSION_ATTACK;
                    }
                    if ($this->viewHelpers()->isModuleAvailable(MODULE_MISSION_HOLD)) {
                        $availableMissions[] = MISSION_HOLD;
                    }
                } elseif ($this->viewHelpers()->isModuleAvailable(MODULE_MISSION_STATION)) {
                    $availableMissions[] = MISSION_STATION;
                }

                if (
                    !$YourPlanet && $MissionInfo['planettype'] == 3 
                    && (isset($MissionInfo['Ship'][SHIP_RIP]) || isset($MissionInfo['Ship'][VOID_TITAN]))
                    && $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_DESTROY)
                ) {
                    $availableMissions[] = MISSION_DESTRUCTION;
                }
            }
        }

        return $availableMissions;
    }

    public function checkBash(int $Target, int $fleetGroup = 0): bool
    {
        $USER = & $this->state->USER;
        $config = $this->configs->get();
        $bashPerPlanet  = $config->bash_per_planet;
        $bashPerUser    = $config->bash_per_user;

        if ($bashPerPlanet == 0 && $bashPerUser == 0) {
            return false;
        }

        $PlanetOwner = $this->db->selectSingle('SELECT id_owner FROM %%PLANETS%% where id = :id', [
            ':id' => $Target,
        ], 'id_owner');

        $targetAllianceId = $this->db->selectSingle('SELECT ally_id FROM %%USERS%% where id = :id', [
            ':id' => $PlanetOwner,
        ], 'ally_id');

        $targetOnlineTime = $this->db->selectSingle('SELECT onlinetime FROM %%USERS%% where id = :id', [
            ':id' => $PlanetOwner,
        ], 'onlinetime');

        if ($targetOnlineTime < $this->state->runtime->timestamp - INACTIVE) {
            return false;
        }

        $sql = 'SELECT accept, request_time from %%DIPLO%%
        where ((owner_1 = :allyA and owner_2 = :allyD)
        or (owner_1 = :allyD and owner_2 = :allyA))
        and level = 5';

        $War = $this->db->selectSingle($sql, [
            ':allyA' => $USER['ally_id'],
            ':allyD' => $targetAllianceId
        ]);

        if (!empty($War) && ($War['accept'] == 1 && $War['request_time'] < $this->state->runtime->timestamp - ONE_DAY)) {
            return false;
        }

        $isUserBash = false;
        if ($bashPerUser != 0) {
            $userCounts   = $this->userBashCheck($USER, $PlanetOwner, $fleetGroup);
            $isUserBash   = ($userCounts['CountSoloAttacksOnUser'] + $userCounts['CountAcsAttacksOnUser']) >= $bashPerUser;
        }

        $isPlanetBash = false;
        if ($bashPerPlanet != 0) {
            $planetCounts   = $this->planetBashCheck($USER, $Target, $fleetGroup);
            $isPlanetBash   = ($planetCounts['CountSoloAttacksOnPlanet'] + $planetCounts['CountAcsAttacksOnPlanet']) >= $bashPerPlanet;
        }

        return  ($isUserBash || $isPlanetBash);
    }

    private function userBashCheck(array $USER, int $targetUser, int $fleetGroup): array
    {
        $sql = 'SELECT ships
            FROM %%LOG_FLEETS%%
            WHERE `owner` = :fleetOwner
            AND `target_owner` = :fleetEndOwner
            AND `arrival_time` > :arrivalTime
            AND `mission` IN (:att,:aks,:dest)
            AND `group` = 0
            AND `hasCanceled` = 0;';

        $log_fleets = $this->db->select($sql, [
            ':fleetOwner' => $USER['id'],
            ':fleetEndOwner' => $targetUser,
            ':arrivalTime' => ($this->state->runtime->timestamp - BASH_TIME),
            ':att' => MISSION_ATTACK,
            ':aks' => MISSION_ACS,
            ':dest' => MISSION_DESTRUCTION,
        ]);

        $CountSoloAttacksOnUser = 0;
        foreach ($log_fleets as $log_fleet) {
            $fleetArray = $this->unserialize($log_fleet['ships']);
            if (count($fleetArray) != 1 || !array_key_exists(210, $fleetArray)) {
                $CountSoloAttacksOnUser++;
            }
        }

        $sql = 'SELECT COUNT(DISTINCT `group`) as amount
		FROM %%LOG_FLEETS%%
		WHERE `owner` = :fleetOwner
		AND `target_owner` = :fleetEndOwner
		AND `arrival_time` > :arrivalTime
		AND `mission` IN (:att,:aks,:dest)
        AND `group` != :fleetGroup
        AND `group` != 0
        AND `hasCanceled` = 0;';

        $CountAcsAttacksOnUser = $this->db->selectSingle($sql, [
            ':fleetOwner' => $USER['id'],
            ':fleetEndOwner' => $targetUser,
            ':arrivalTime' => ($this->state->runtime->timestamp - BASH_TIME),
            ':att' => MISSION_ATTACK,
            ':aks' => MISSION_ACS,
            ':dest' => MISSION_DESTRUCTION,
            ':fleetGroup' => $fleetGroup,
        ], 'amount');
        return [
            'CountSoloAttacksOnUser' => $CountSoloAttacksOnUser,
            'CountAcsAttacksOnUser'  => $CountAcsAttacksOnUser
        ];
    }

    private function planetBashCheck(array $USER, int $Target, int $fleetGroup): array
    {
        $sql = 'SELECT ships
		FROM %%LOG_FLEETS%%
		WHERE `owner` = :fleetOwner
		AND `end_id` = :fleetEndId
		AND `arrival_time` > :arrivalTime
		AND `mission` IN (:att,:aks,:dest)
        AND `group` = 0
        AND `hasCanceled` = 0;';

        $log_fleets = $this->db->select($sql, [
            ':fleetOwner' => $USER['id'],
            ':fleetEndId' => $Target,
            ':arrivalTime' => ($this->state->runtime->timestamp - BASH_TIME),
            ':att' => MISSION_ATTACK,
            ':aks' => MISSION_ACS,
            ':dest' => MISSION_DESTRUCTION,
        ]);

        $CountSoloAttacksOnPlanet = 0;
        foreach ($log_fleets as $log_fleet) {
            $fleetArray = $this->unserialize($log_fleet['ships']);
            if (count($fleetArray) != 1 || !array_key_exists(210, $fleetArray)) {
                $CountSoloAttacksOnPlanet++;
            }
        }

        $sql = 'SELECT COUNT(DISTINCT `group`) as amount
		FROM %%LOG_FLEETS%%
		WHERE `owner` = :fleetOwner
		AND `end_id` = :fleetEndId
		AND `arrival_time` > :arrivalTime
		AND `mission` IN (:att,:aks,:dest)
        AND `group` != :fleetGroup
        AND `group` != 0
        AND `hasCanceled` = 0;';

        $CountAcsAttacksOnPlanet = $this->db->selectSingle($sql, [
            ':fleetOwner' => $USER['id'],
            ':fleetEndId' => $Target,
            ':arrivalTime' => ($this->state->runtime->timestamp - BASH_TIME),
            ':att' => MISSION_ATTACK,
            ':aks' => MISSION_ACS,
            ':dest' => MISSION_DESTRUCTION,
            ':fleetGroup' => $fleetGroup,
        ], 'amount');
        return [
            'CountSoloAttacksOnPlanet' => $CountSoloAttacksOnPlanet,
            'CountAcsAttacksOnPlanet'  => $CountAcsAttacksOnPlanet
        ];
    }

    public function sendFleet(
        array $fleetArray,
        int $fleetMission,
        int $fleetStartOwner,
        int $fleetStartPlanetID,
        int $fleetStartPlanetGalaxy,
        int $fleetStartPlanetSystem,
        int $fleetStartPlanetPlanet,
        int $fleetStartPlanetType,
        int $fleetTargetOwner,
        int $fleetTargetPlanetID,
        int $fleetTargetPlanetGalaxy,
        int $fleetTargetPlanetSystem,
        int $fleetTargetPlanetPlanet,
        int $fleetTargetPlanetType,
        array $fleetResource,
        int $arrivalTime,
        int $stayTime,
        int $endTime,
        int $fleetGroup = 0,
        int $missileTarget = 0,
        int $cannotCancel = 0,
        float $consumption = 0.0
    ): int
    {
        $resource = & $this->state->resource;
        $fleetShipCount = array_sum($fleetArray);
        $fleetData = [];

        $db = $this->db;

        $params = [':planetId' => $fleetStartPlanetID];

        $fleetTargetPlanetType = ($fleetTargetPlanetID === 0 ? 1 : $fleetTargetPlanetType);

        $planetQuery = [];
        foreach ($fleetArray as $ShipID => $ShipCount) {
            $fleetData[] = $ShipID . ',' . floatToString($ShipCount);
            $planetQuery[] = $resource[$ShipID] . " = " . $resource[$ShipID] . " - :" . $resource[$ShipID];

            $params[':' . $resource[$ShipID]] = floatToString($ShipCount);
        }

        foreach ($fleetResource as $resourceId => $amount) {
            $planetQuery[] = $resource[$resourceId] . " = " . $resource[$resourceId] . " - :" . $resource[$resourceId];
            if ($resourceId == RESOURCE_DEUT && $consumption > 0) {
                $amount += $consumption;
            }
            $params[':' . $resource[$resourceId]] = $amount;
        }

        $sql = 'UPDATE %%PLANETS%% SET ' . implode(', ', $planetQuery) . ' WHERE id = :planetId;';

        $db->update($sql, $params);

        $sql = 'INSERT INTO %%FLEETS%% SET
            `group`                 = :fleetGroup,
            `universe`              = :universe,
            `mission`               = :fleetMission,
            `owner`                 = :fleetStartOwner,
            `target_owner`          = :fleetTargetOwner,
            `ship_amount`           = :fleetShipCount,
            `ships`                 = :fleetData,
            `start_ships`           = :fleetDataStart,
            `start_time`            = :startTime,
            `arrival_time`           = :arrivalTime,
            `stay_time`             = :stayTime,
            `return_time`           = :endTime,
            `start_id`              = :fleetStartPlanetID,
            `start_galaxy`          = :fleetStartPlanetGalaxy,
            `start_system`          = :fleetStartPlanetSystem,
            `start_planet`          = :fleetStartPlanetPlanet,
            `start_type`            = :fleetStartPlanetType,
            `end_id`                = :fleetTargetPlanetID,
            `end_galaxy`            = :fleetTargetPlanetGalaxy,
            `end_system`            = :fleetTargetPlanetSystem,
            `end_planet`            = :fleetTargetPlanetPlanet,
            `end_type`              = :fleetTargetPlanetType,
            `missile_target`        = :missileTarget,
            `fuel_cost`             = :fuelCost,
            `resource_metal`        = :fleetResource901,
            `resource_crystal`      = :fleetResource902,
            `resource_deuterium`    = :fleetResource903,
            `cannotCancel`          = :cannotCancel;';

        $db->insert($sql, [
            ':fleetGroup' => $fleetGroup,
            ':universe' => $this->universe()->current(),
            ':fleetMission' => $fleetMission,
            ':fleetStartOwner' => $fleetStartOwner,
            ':fleetTargetOwner' => $fleetTargetOwner,
            ':fleetShipCount' => $fleetShipCount,
            ':fleetData' => implode(';', $fleetData),
            ':fleetDataStart' => implode(';', $fleetData),
            ':startTime' => $this->state->runtime->timestamp,
            ':arrivalTime' => $arrivalTime,
            ':stayTime' => $stayTime,
            ':endTime' => $endTime,
            ':fleetStartPlanetID' => $fleetStartPlanetID,
            ':fleetStartPlanetGalaxy' => $fleetStartPlanetGalaxy,
            ':fleetStartPlanetSystem' => $fleetStartPlanetSystem,
            ':fleetStartPlanetPlanet' => $fleetStartPlanetPlanet,
            ':fleetStartPlanetType' => $fleetStartPlanetType,
            ':fleetTargetPlanetID' => $fleetTargetPlanetID,
            ':fleetTargetPlanetGalaxy' => $fleetTargetPlanetGalaxy,
            ':fleetTargetPlanetSystem' => $fleetTargetPlanetSystem,
            ':fleetTargetPlanetPlanet' => $fleetTargetPlanetPlanet,
            ':fleetTargetPlanetType' => $fleetTargetPlanetType,
            ':missileTarget' => $missileTarget,
            ':fuelCost' => $consumption,
            ':fleetResource901' => $fleetResource[901],
            ':fleetResource902' => $fleetResource[902],
            ':fleetResource903' => $fleetResource[903],
            ':cannotCancel' => $cannotCancel,
        ]);

        $fleetId = $db->lastInsertId();

        $sql = 'INSERT INTO %%FLEETS_EVENT%% SET fleetID	= :fleetId, `time` = :endTime, `universe` = :universe;';
        $db->insert($sql, [
            ':fleetId' => $fleetId,
            ':endTime' => $arrivalTime,
            ':universe' => $this->universe()->current(),
        ]);

        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $statsHandler->increment($fleetStartOwner, AdvancedStatsEnum::USED_FUEL, $consumption);

        $pMarket = new MarketManager($this->db, $this->configs, $this->state);
        $refrates = $pMarket->getReferenceRatios();
        $current_market_rate = MarketManager::formatReferenceRatio($refrates['metal'])
            . ';' . MarketManager::formatReferenceRatio($refrates['crystal'])
            . ';' . MarketManager::formatReferenceRatio($refrates['deuterium']);
        $current_market_value_metal = $fleetResource[901] / $refrates['metal'];
        $current_market_value_crystal = $fleetResource[902] / $refrates['crystal'];
        $current_market_value_deuterium = $fleetResource[903] / $refrates['deuterium'];

        $current_market_value = $current_market_value_metal + $current_market_value_crystal + $current_market_value_deuterium;

        $sql = 'INSERT INTO %%LOG_FLEETS%% SET
            `id`                    = :fleetId,
            `group`                 = :fleetGroup,
            `universe`              = :universe,
            `mission`               = :fleetMission,
            `owner`                 = :fleetStartOwner,
            `owner_name`            = :fleetStartOwnerName,
            `target_owner`          = :fleetTargetOwner,
            `target_owner_name`     = :fleetTargetOwnerName,
            `ship_amount`           = :fleetShipCount,
            `ships`                 = :fleetData,
            `start_ships`           = :fleetDataStart,
            `start_time`            = :startTime,
            `start_time_formated`   = :startTimeFormated,
            `arrival_time`           = :arrivalTime,
            `arrival_time_formated`  = :arrivalTimeFormated,
            `stay_time`             = :stayTime,
            `stay_time_formated`    = :stayTimeFormated,
            `return_time`           = :endTime,
            `return_time_formated`  = :endTimeFormated,
            `start_id`              = :fleetStartPlanetID,
            `start_galaxy`          = :fleetStartPlanetGalaxy,
            `start_system`          = :fleetStartPlanetSystem,
            `start_planet`          = :fleetStartPlanetPlanet,
            `start_type`            = :fleetStartPlanetType,
            `end_id`                = :fleetTargetPlanetID,
            `end_galaxy`            = :fleetTargetPlanetGalaxy,
            `end_system`            = :fleetTargetPlanetSystem,
            `end_planet`            = :fleetTargetPlanetPlanet,
            `end_type`              = :fleetTargetPlanetType,
            `missile_target`        = :missileTarget,
            `fuel_cost`             = :fuelCost,
            `resource_metal`        = :fleetResource901,
            `resource_crystal`      = :fleetResource902,
            `resource_deuterium`    = :fleetResource903,
            `current_market_rate`   = :current_market_rate,
            `current_market_value_based_on_DSE` = :current_market_value,
            `cannotCancel`          = :cannotCancel;';

        $fleetStartOwnerName = $db->selectSingle("SELECT username from %%USERS%% WHERE id = :id", [':id' => $fleetStartOwner], 'username');
        $fleetTargetOwnerName = $db->selectSingle("SELECT username from %%USERS%% WHERE id = :id", [':id' => $fleetTargetOwner], 'username');

        $db->insert($sql, [
            ':fleetId' => $fleetId,
            ':fleetGroup' => $fleetGroup,
            ':universe' => $this->universe()->current(),
            ':fleetMission' => $fleetMission,
            ':fleetStartOwner' => $fleetStartOwner,
            ':fleetStartOwnerName' => $fleetStartOwnerName,
            ':fleetTargetOwner' => $fleetTargetOwner,
            ':fleetTargetOwnerName' => $fleetTargetOwnerName,
            ':fleetShipCount' => $fleetShipCount,
            ':fleetData' => implode(';', $fleetData),
            ':fleetDataStart' => implode(';', $fleetData),
            ':startTime' => $this->state->runtime->timestamp,
            ':startTimeFormated' => $this->db->formatDate($this->state->runtime->timestamp),
            ':arrivalTime' => $arrivalTime,
            ':arrivalTimeFormated' => $this->db->formatDate($arrivalTime),
            ':stayTime' => $stayTime,
            ':stayTimeFormated' => $this->db->formatDate($stayTime),
            ':endTime' => $endTime,
            ':endTimeFormated' => $this->db->formatDate($endTime),
            ':fleetStartPlanetID' => $fleetStartPlanetID,
            ':fleetStartPlanetGalaxy' => $fleetStartPlanetGalaxy,
            ':fleetStartPlanetSystem' => $fleetStartPlanetSystem,
            ':fleetStartPlanetPlanet' => $fleetStartPlanetPlanet,
            ':fleetStartPlanetType' => $fleetStartPlanetType,
            ':fleetTargetPlanetID' => $fleetTargetPlanetID,
            ':fleetTargetPlanetGalaxy' => $fleetTargetPlanetGalaxy,
            ':fleetTargetPlanetSystem' => $fleetTargetPlanetSystem,
            ':fleetTargetPlanetPlanet' => $fleetTargetPlanetPlanet,
            ':fleetTargetPlanetType' => $fleetTargetPlanetType,
            ':missileTarget' => $missileTarget,
            ':fuelCost' => $consumption,
            ':fleetResource901' => $fleetResource[901],
            ':fleetResource902' => $fleetResource[902],
            ':fleetResource903' => $fleetResource[903],
            ':current_market_rate' => $current_market_rate,
            ':current_market_value' => $current_market_value,
            ':cannotCancel' => $cannotCancel,
        ]);
        return $fleetId;
    }

    // double this for resource finds!
    public function calculateMaxFactor(int $universeId): int
    {
        $sql = "SELECT MAX(`total_points`) as total FROM %%STATPOINTS%%
            WHERE `stat_type` = :type AND `universe` = :universe;";

        $topPoints = $this->db->selectSingle($sql, [
            ':type' => 1,
            ':universe' => $universeId
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

    // for some reason, 1 value differs from the table. Danke Merkel.
    public function calculateMaxFactorRes(int $universeId): int
    {
        $maxFactor = $this->calculateMaxFactor($universeId) * 2;
        return ($maxFactor != 2500 ? $maxFactor : 2400);
    }

    public function calculateCurrentTotalFleetState(int $userID): array
    {
        $db = $this->db;
        $resource = & $this->state->resource;
        $reslist = & $this->state->reslist;
        $pricelist = & $this->state->pricelist;

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

    /** phalanx scan rules (moved from the phalanx page, used by it and the galaxy view) */
    public function allowPhalanx($toGalaxy, $toSystem, $targetUser)
    {
        $PLANET = & $this->state->PLANET;
        $resource = & $this->state->resource;
        $USER = & $this->state->USER;
        $checknoob = $this->playerUtil()->checkNoobProtection($USER, $targetUser);

        if ($PLANET['galaxy'] != $toGalaxy || $PLANET[$resource[42]] == 0 || !$this->viewHelpers()->isModuleAvailable(MODULE_PHALANX) || $PLANET[$resource[903]] < PHALANX_DEUTERIUM || $USER['urlaubs_modus'] == 1 || $checknoob['NoobPlayer'] || $checknoob['StrongPlayer']) {
            return false;
        }

        $PhRange	= $this->getPhalanxRange($PLANET[$resource[42]]);
        // if ($this->configs->get()->galaxy_type == 2) {
        // TODO or else
        // } else if
        if ($this->configs->get()->galaxy_type == 1) {
            $max = $this->configs->get()->max_system;
            $thisSystem = $PLANET['system'];

            if ($thisSystem + $PhRange > $max) {
                $systemMax = $thisSystem + $PhRange - $max;
            } else {
                $systemMax = $thisSystem + $PhRange;
            }

            if ($thisSystem - $PhRange < 1) {
                $systemMin = $max + ($thisSystem - $PhRange);
            } else {
                $systemMin = $thisSystem - $PhRange;
            }

            if ($PhRange * 2 >= $max) {
                return true;
            } elseif ($systemMin <= $systemMax) {
                return $toSystem >= $systemMin && $toSystem <= $systemMax;
            } else {
                return ($toSystem >= $systemMin && $toSystem <= $max) || ($toSystem >= 1 && $toSystem <= $systemMax);
            }
        } else {
            $systemMin  = max(1, $PLANET['system'] - $PhRange);
            $systemMax  = $PLANET['system'] + $PhRange;

            return $toSystem >= $systemMin && $toSystem <= $systemMax;
        }
    }

    public function getPhalanxRange($PhalanxLevel)
    {
        $config = $this->configs->get();
        return ($PhalanxLevel == 1) ? 0 : floor((pow($PhalanxLevel, 2) - 1) * $config->phalanx_range_factor);
    }
}
