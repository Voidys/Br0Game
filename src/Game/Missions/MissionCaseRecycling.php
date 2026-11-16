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

namespace App\Game\Missions;

use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;
use App\Game\Achievements\MiscAchievement;
use App\Game\AdvancedStats\AdvancedStatsEnum;

class MissionCaseRecycling extends MissionFunctions implements MissionInterface
{
    public function __construct($Fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet = $Fleet;
    }

    public function TargetEvent()
    {
        $pricelist = $this->state->pricelist;
        $resource = $this->state->resource;
        $reslist = $this->state->reslist;
        $resourceIDs = [RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT];
        $debrisIDs = [RESOURCE_METAL, RESOURCE_CRYSTAL];
        $resQuery = [];
        $collectQuery = [];

        $collectedGoods = [];
        $remainingGoods = [];
        foreach ($debrisIDs as $debrisID) {
            $collectedGoods[$debrisID] = 0;
            $remainingGoods[$debrisID] = 0;
            $resQuery[] = '`der_' . $resource[$debrisID] . '`';
        }

        $sql = 'SELECT ' . implode(',', $resQuery) . ', (' . implode(' + ', $resQuery) . ') as total'
                . ' FROM %%PLANETS%% WHERE `id` = :planetId FOR UPDATE';

        $targetData = $this->db->selectSingle($sql, [
            ':planetId' => $this->_fleet['end_id'],
        ]);

        // return fleet if target planet deleted
        if ($targetData == false) {
            $this->setState(FLEET_RETURN);
            $this->SaveFleet();
            return;
        }

        if (!empty($targetData['total'])) {
            // Get fleet capacity
            $fleetData = $this->fleetFunctions()->unserialize($this->_fleet['ships']);

            $recyclerStorage = 0;
            $otherFleetStorage = 0;
            $recCount = 0;

            foreach ($fleetData as $shipId => $shipAmount) {
                if (isset($reslist['ship_type'][SHIP_TYPE_RECYCLER][$shipId])) {
                    $recyclerStorage += $pricelist[$shipId]['capacity'] * $shipAmount;
                    $recCount += $shipAmount;
                } else {
                    $otherFleetStorage += $pricelist[$shipId]['capacity'] * $shipAmount;
                }
            }

            $incomingGoods = 0;
            foreach ($resourceIDs as $resourceID) {
                $incomingGoods += $this->_fleet['resource_' . $resource[$resourceID]];
            }

            $totalStorage = $recyclerStorage + min(0, $otherFleetStorage - $incomingGoods);

            $param = [
                ':planetId' => $this->_fleet['end_id'],
            ];

            // fast way
            $collectFactor = min(1, $totalStorage / $targetData['total']);
            foreach ($debrisIDs as $debrisID) {
                $fleetColName = 'resource_' . $resource[$debrisID];
                $logColName = 'gained_' . $resource[$debrisID];
                $debrisColName = 'der_' . $resource[$debrisID];

                $collectedGoods[$debrisID] = ceil($targetData[$debrisColName] * $collectFactor);
                $remainingGoods[$debrisID] = max(0, $targetData[$debrisColName] - $collectedGoods[$debrisID]);
                $collectQuery[] = $debrisColName . ' = GREATEST(0, ' . $debrisColName . ' - :'
                    . $resource[$debrisID] . ')';
                $param[':' . $resource[$debrisID]] = $collectedGoods[$debrisID];

                $this->UpdateFleet($fleetColName, $this->_fleet[$fleetColName] + $collectedGoods[$debrisID]);
                $this->UpdateFleetLog($logColName, $collectedGoods[$debrisID]);
            }

            $sql = 'UPDATE %%PLANETS%% SET ' . implode(',', $collectQuery) . ' WHERE `id` = :planetId;';

            $this->db->update($sql, $param);
            $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::RECYCLED_METAL, $collectedGoods[RESOURCE_METAL]);
            $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::RECYCLED_CRYSTAL, $collectedGoods[RESOURCE_CRYSTAL]);
            $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_RECYCLING, $this->_fleet['arrival_time']);
        } else {
            $fleetData = $this->fleetFunctions()->unserialize($this->_fleet['ships']);
            $recCount = 0;
            foreach ($fleetData as $shipId => $shipAmount) {
                if (isset($reslist['ship_type'][SHIP_TYPE_RECYCLER][$shipId])) {
                    $recCount += $shipAmount;
                }
            }
            $this->miscAchievements()->checkRecAchievements($this->_fleet['owner'], $collectedGoods, $recCount);
        }

        $LNG = $this->getLanguage(null, $this->_fleet['owner']);
        $LNG->includeData(['INGAME']);

        $Message = $LNG->format('sys_recy_gotten',
            GetTargetAddressLink($this->_fleet, ''),
            $this->viewHelpers()->prettyNumber($collectedGoods[RESOURCE_METAL]),
            $this->viewHelpers()->prettyNumber($collectedGoods[RESOURCE_CRYSTAL]),
            $this->viewHelpers()->prettyNumber($targetData[$resQuery[0]]),
            $this->viewHelpers()->prettyNumber($targetData[$resQuery[1]]),
            $LNG['tech'][RESOURCE_METAL],
            $LNG['tech'][RESOURCE_CRYSTAL],
        );
        $Message .= '<br>';
        $Message .= $LNG->format('bs_derbis_raport',
            $this->viewHelpers()->prettyNumber(ceil(array_sum($remainingGoods) / $pricelist[SHIP_RECYCLER]['capacity'])),
            $LNG['tech'][SHIP_RECYCLER]
        );

        $this->playerUtil()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MESSAGE_TYPE_TRANSPORT,
            $LNG['sys_recy_report'],
            $Message,
            $this->_fleet['arrival_time'],
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id']
        );

        $this->setState(FLEET_RETURN);
        $this->SaveFleet();
    }

    public function EndStayEvent()
    {
        return;
    }

    public function ReturnEvent()
    {
        $LNG = $this->getLanguage(null, $this->_fleet['owner']);

        $sql = 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $planetName = $this->db->selectSingle($sql, [
            ':planetId' => $this->_fleet['start_id'],
        ], 'name');

        $Message = $LNG->format('sys_recy_mess',
            $planetName,
            GetStartAddressLink($this->_fleet, ''),
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_metal']),
            $LNG['tech'][RESOURCE_METAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_crystal']),
            $LNG['tech'][RESOURCE_CRYSTAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_deuterium']),
            $LNG['tech'][RESOURCE_DEUT]
        );

        $this->playerUtil()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MESSAGE_TYPE_SYSTEM,
            $LNG['sys_mess_fleetback'],
            $Message,
            $this->_fleet['return_time'],
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id']
        );

        $this->RestoreFleet();
    }
}
