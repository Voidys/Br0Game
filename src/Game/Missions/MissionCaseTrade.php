<?php

/**
 *  2Moons / Steemnova
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package Steemnova
 * @author Adam Jordanek <dotevo@gmail.com>
 * @licence MIT
 */

namespace App\Game\Missions;

use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\PlayerUtil;

class MissionCaseTrade extends MissionFunctions implements MissionInterface
{
    public function __construct($fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet	= $fleet;
    }

    public function TargetEvent()
    {
        $db = $this->db;
        $LNG		= $this->getLanguage(null, $this->_fleet['owner']);
        if ($this->_fleet['end_id'] != 0) {
            /*
            * Buyer fleet flies to its own market place first
            * then gets rerouted to sellers marketplace
            */
            $sql = "SELECT `marketplace_system`, `marketplace_galaxy` 
                FROM %%TRADES%% WHERE `seller_fleet_id` = :id OR `buyer_fleet_id` = :id;";
            $target = $db->selectSingle($sql, [
                ':id' => $this->_fleet['id']
            ]);
            //$this->UpdateFleet('mission', 3);
            $sql = "SELECT * FROM %%USERS%% WHERE `id` = :userId;";
            $owner = $db->selectSingle($sql, [':userId' => $this->_fleet['owner']]);
            $config = $this->configs->get($owner['universe']);
            $fleetArray = $this->fleetFunctions()->unserialize($this->_fleet['ships']);
            $SpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
            $Distance = $this->fleetFunctions()->getTargetDistance(
                [$this->_fleet['start_galaxy'], $this->_fleet['start_system'], ($config->max_planets + 2)],
                [$target['marketplace_galaxy'], $target['marketplace_system'], ($config->max_planets + 2)]
            );
            $SpeedAllMin = $this->fleetFunctions()->getFleetMaxSpeed($fleetArray, $owner);
            $Duration = $this->fleetFunctions()->getMissionDuration(10, $SpeedAllMin, $Distance, $SpeedFactor, $owner);
            //if trade is with another system, set start time to arrival time on own market place to remove the extra time back
            if ($target['marketplace_system'] != $this->_fleet['start_system'] || $target['marketplace_galaxy'] != $this->_fleet['start_galaxy']) {
                $this->UpdateFleet('start_time', $this->_fleet['arrival_time']);
            }
            $fleetStartTime = $Duration + $this->_fleet['arrival_time'];
            $fleetStayTime = $fleetStartTime + TIME_6_HOURS;
            $fleetEndTime = $fleetStayTime + $Duration;
            $this->UpdateFleet('arrival_time', $fleetStartTime);
            $this->UpdateFleet('stay_time', $fleetStayTime);
            $this->UpdateFleet('return_time', $fleetEndTime);
            $this->UpdateFleet('end_id', 0);
            $this->UpdateFleet('end_galaxy', $target['marketplace_galaxy']);
            $this->UpdateFleet('end_system', $target['marketplace_system']);
            $this->UpdateFleetLog('end_galaxy', $target['marketplace_galaxy']);
            $this->UpdateFleetLog('end_system', $target['marketplace_system']);
            $this->UpdateFleet('resource_metal', 0);
            $this->UpdateFleet('resource_crystal', 0);
            $this->UpdateFleet('resource_deuterium', 0);
            $this->setState(FLEET_OUTWARD);
        } elseif ($this->_fleet['end_id'] == 0) {
            /*
            * either exchange ressources or hold at market place
            * else is for holding(normal flight to marketplace)
            */
            $sql = "SELECT `seller_fleet_id`, `ex_resource_type`, `ex_resource_amount`, `buy_time` 
                FROM %%TRADES%% WHERE `seller_fleet_id` = :id;";
            $tradeSeller = $db->selectSingle($sql, [
                ':id' => $this->_fleet['id']
            ]);
            $sql = "SELECT `buyer_fleet_id`, `resource_type`, `resource_amount`, `buy_time` 
                FROM %%TRADES%% WHERE `buyer_fleet_id` = :id;";
            $tradeBuyer = $db->selectSingle($sql, [
                ':id' => $this->_fleet['id']
            ]);
            if (!empty($tradeSeller)) {
                if (!empty($tradeSeller['buy_time'])) {
                    switch ($tradeSeller['ex_resource_type']) {
                        case 1:
                            $ressource = 'resource_metal';
                            break;
                        case 2:
                            $ressource = 'resource_crystal';
                            break;
                        case 3:
                            $ressource = 'resource_deuterium';
                            break;
                    }
                    $this->UpdateFleet($ressource, $tradeSeller['ex_resource_amount']);
                    $this->UpdateFleetLog('wanted_resource', $tradeSeller['ex_resource_type']);
                    $this->UpdateFleetLog('wanted_resource_amount', $tradeSeller['ex_resource_amount']);
                    $this->setState(FLEET_HOLD);
                    $this->UpdateFleet('cannotCancel', 0);

                    $this->sendHoldMessage();
                } else {
                    $this->setState(FLEET_HOLD);
                    $this->UpdateFleet('cannotCancel', 1);
                }
            } elseif (!empty($tradeBuyer)) {
                if (!empty($tradeBuyer['buy_time'])) {
                    switch ($tradeBuyer['resource_type']) {
                        case 1:
                            $ressource = 'resource_metal';
                            break;
                        case 2:
                            $ressource = 'resource_crystal';
                            break;
                        case 3:
                            $ressource = 'resource_deuterium';
                            break;
                    }
                    $this->UpdateFleet($ressource, $tradeBuyer['resource_amount']);
                    $this->UpdateFleetLog('wanted_resource', $tradeBuyer['resource_type']);
                    $this->UpdateFleetLog('wanted_resource_amount', $tradeBuyer['resource_amount']);
                    $this->setState(FLEET_HOLD);
                    $this->UpdateFleet('cannotCancel', 0);

                    $this->sendHoldMessage();
                } else {
                    $this->setState(FLEET_HOLD);
                    $this->UpdateFleet('cannotCancel', 1);
                }
            }
        } else {
            $this->setState(FLEET_HOLD);
            $this->UpdateFleet('cannotCancel', 1);
        }

        $this->SaveFleet();
    }

    public function EndStayEvent()
    {
        $this->setState(FLEET_RETURN);
        $this->SaveFleet();
    }

    public function ReturnEvent()
    {
        $LNG		= $this->getLanguage(null, $this->_fleet['owner']);
        $db = $this->db;
        $sql		= 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $planetName	= $db->selectSingle($sql, [
            ':planetId'	=> $this->_fleet['start_id'],
        ], 'name');
        $sql = "SELECT `buyer_fleet_id` FROM %%TRADES%% WHERE `seller_fleet_id` = :id;";
        $sellerBuy	= $db->selectSingle($sql, [
            ':id'	=> $this->_fleet['id'],
        ], 'buyer_fleet_id');
        $sql = "SELECT `buyer_fleet_id` FROM %%TRADES%% WHERE `buyer_fleet_id` = :id;";
        $buyerBuy	= $db->selectSingle($sql, [
            ':id'	=> $this->_fleet['id'],
        ], 'buyer_fleet_id');

        if ($sellerBuy || $buyerBuy) {
            $Message	= sprintf(
                $LNG['sys_trade_mess_back_success'],
                $this->_fleet['resource_metal'],
                $this->_fleet['resource_crystal'],
                $this->_fleet['resource_deuterium'],
                $planetName,
                GetStartAddressLink($this->_fleet, '')
            );
        } else {
            $Message	= sprintf($LNG['sys_trade_mess_back'], $planetName, GetStartAddressLink($this->_fleet, ''));
        }

        $this->playerUtil()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MESSAGE_TYPE_SYSTEM,
            $LNG['sys_mess_fleetback'],
            $Message,
            $this->_fleet['return_time'],
            1,
            $this->_fleet['universe']
        );

        $this->RestoreFleet();
    }

    private function sendHoldMessage()
    {
        $LNG		= $this->getLanguage(null, $this->_fleet['owner']);
        $db = $this->db;
        $sql		= 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $planetName	= $db->selectSingle($sql, [
            ':planetId'	=> $this->_fleet['start_id'],
        ], 'name');
        // Holding trade recalls fly for arrival_time - start_time
        // (FleetFunctions::sendFleetBack). return_time - stay_time is only
        // the scheduled leg and is too short when both markets share a system.
        $returnFlightTime = max(0, (int) $this->_fleet['arrival_time'] - (int) $this->_fleet['start_time']);
        $Message	= sprintf(
            $LNG['sys_trade_mess_arr_success'],
            $planetName,
            GetStartAddressLink($this->_fleet, ''),
            $this->_fleet['fleet_id'],
            $LNG['fl_send_back'],
            $this->viewHelpers()->prettyTime($returnFlightTime)
        );
        $this->playerUtil()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MESSAGE_TYPE_SYSTEM,
            $LNG['sys_mess_fleetback'],
            $Message,
            $this->_fleet['arrival_time'],
            1,
            $this->_fleet['universe']
        );
    }
}
