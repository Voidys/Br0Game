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

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\PlayerUtil;

class MissionCaseColonisation extends MissionFunctions implements MissionInterface
{
    public function __construct($Fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet = $Fleet;
    }

    public function TargetEvent()
    {
        $db = $this->db;

        $sql = 'SELECT * FROM %%USERS%% as u 
            INNER JOIN %%USERS_SETTINGS%% as s ON s.`user_id` = u.`id` 
            WHERE u.`id` = :userId;';

        $senderUser = $db->selectSingle($sql, [
            ':userId'   => $this->_fleet['owner'],
        ]);

        $LNG = $this->getLanguage($senderUser['lang']);

        $checkPosition = $this->playerUtil()->checkPosition(
            $this->_fleet['universe'],
            $this->_fleet['end_galaxy'],
            $this->_fleet['end_system'],
            $this->_fleet['end_planet']
        );
        $isPositionFree = $this->playerUtil()->isPositionFree(
            $this->_fleet['universe'],
            $this->_fleet['end_galaxy'],
            $this->_fleet['end_system'],
            $this->_fleet['end_planet']
        );

        if (!$isPositionFree || !$checkPosition) {
            $message = $LNG->format('sys_colo_notfree', GetTargetAddressLink($this->_fleet, ''));
        } else {
            $allowPlanetPosition = $this->playerUtil()->allowPlanetPosition($this->_fleet['end_planet'], $senderUser);
            if (!$allowPlanetPosition) {
                $message = $LNG->format('sys_colo_notech', GetTargetAddressLink($this->_fleet, ''));
            } else {
                $currentPlanetCount = $this->playerUtil()->currentPlanetCount($senderUser);
                $maxPlanetCount = $this->playerUtil()->maxPlanetCount($senderUser);

                if ($currentPlanetCount >= $maxPlanetCount) {
                    $message = $LNG->format('sys_colo_maxcolo',
                        GetTargetAddressLink($this->_fleet, ''),
                        $maxPlanetCount
                    );
                } else {
                    $NewOwnerPlanet = $this->playerUtil()->createPlanet(
                        $this->_fleet['end_galaxy'],
                        $this->_fleet['end_system'],
                        $this->_fleet['end_planet'],
                        $this->_fleet['universe'],
                        $this->_fleet['owner'],
                        $LNG['fcp_colony'],
                        false,
                        $senderUser['authlevel']
                    );

                    if ($NewOwnerPlanet === false) {
                        $message = $LNG->format('sys_colo_badpos', GetTargetAddressLink($this->_fleet, ''));
                        $this->setState(FLEET_RETURN);
                    } else {
                        $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::PLANETS_CREATED, 1);
                        $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_COLONY, $this->_fleet['arrival_time']);
                        $this->_fleet['end_id'] = $NewOwnerPlanet;
                        $message = $LNG->format('sys_colo_allisok', GetTargetAddressLink($this->_fleet, ''));
                        $this->StoreGoodsToPlanet();
                        if ($this->_fleet['ship_amount'] == 1) {
                            $this->KillFleet();
                        } else {
                            $CurrentFleet = explode(";", $this->_fleet['ships']);
                            $NewFleet = '';
                            foreach ($CurrentFleet as $Group) {
                                if (empty($Group)) {
                                    continue;
                                }

                                $Class = explode(",", $Group);
                                if ($Class[0] == SHIP_COLOSHIP && $Class[1] > 1) {
                                    $NewFleet .= $Class[0] . "," . ($Class[1] - 1) . ";";
                                } elseif ($Class[0] != SHIP_COLOSHIP && $Class[1] > 0) {
                                    $NewFleet .= $Class[0] . "," . $Class[1] . ";";
                                }
                            }

                            $this->UpdateFleet('ships', $NewFleet);
                            $this->UpdateFleet('ship_amount', ($this->_fleet['ship_amount'] - 1));
                            $this->UpdateFleet('resource_metal', 0);
                            $this->UpdateFleet('resource_crystal', 0);
                            $this->UpdateFleet('resource_deuterium', 0);
                        }
                    }
                }
            }
        }

        $this->playerUtil()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_colo_mess_from'],
            MESSAGE_TYPE_SYSTEM,
            $LNG['sys_colo_mess_report'],
            $message,
            $this->_fleet['arrival_time'],
            1,
            $this->_fleet['universe']
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

        $db = $this->db;
        $sql = 'SELECT `name` FROM %%PLANETS%% WHERE id = :planetId;';
        $startPlanet = $db->selectSingle($sql, [
            ':planetId' => $this->_fleet['start_id'],
        ]);

        $remainingShips = "";
        $fleetArray = $this->fleetFunctions()->unserialize($this->_fleet['ships']);

        foreach ($fleetArray as $shipID => $amount) {
            $remainingShips .= "<br>" . $LNG['tech'][$shipID] . ": " . $amount;
        }

        $Message = $LNG->format('sys_fleet_return',
            $LNG['type_planet_' . $this->_fleet['start_type']],
            $startPlanet['name'],
            GetStartAddressLink($this->_fleet, ''),
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_metal']),
            $LNG['tech'][RESOURCE_METAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_crystal']),
            $LNG['tech'][RESOURCE_CRYSTAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_deuterium']),
            $LNG['tech'][RESOURCE_DEUT],
            $remainingShips,
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
            $this->_fleet['universe']
        );

        $this->RestoreFleet();
    }
}
