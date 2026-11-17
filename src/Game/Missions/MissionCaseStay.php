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

use App\Game\Enum\MessageType;

/**
 * Mission "station" (4, deploy): the fleet moves to one of the player's own planets or moons (or a friendly one)
 * and stays there permanently, i.e. its ships and cargo join the target planet; there is no return trip.
 */
class MissionCaseStay extends MissionFunctions implements MissionInterface
{
    public function __construct($Fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet = $Fleet;
    }

    /**
     * Arrival: returns the fleet if the target planet is gone/abandoned or (for a foreign target) its owner is in
     * vacation mode. Otherwise refunds half of the deuterium paid at dispatch (fuel_cost, or a recomputed
     * consumption for old fleets) into the cargo, sends the target owner a "fleet stationed" message and lands
     * ships and cargo at the target planet (RestoreFleet(false)), which also deletes the fleet.
     */
    public function TargetEvent()
    {
        $db = $this->db;

        $sql = 'SELECT * FROM %%PLANETS%% WHERE `id` = :planetId;';
        $targetPlanet = $db->selectSingle($sql, [
            ':planetId' => $this->_fleet['end_id'],
        ]);

        // return fleet if target planet deleted or abandoned (destruyed is set while the row waits for cleanup)
        if ($targetPlanet == false || !empty($targetPlanet['destruyed'])) {
            $this->setState(FLEET_RETURN);
            $this->SaveFleet();
            return;
        }

        // vacation mode of the target owner: hostile missions turn around
        if ($targetPlanet['id_owner'] != $this->_fleet['owner'] && $this->returnIfTargetInVacation($targetPlanet['id_owner'])) {
            return;
        }

        $sql = 'SELECT * FROM %%USERS%% as u 
            INNER JOIN %%USERS_SETTINGS%% as s ON s.`user_id` = u.`id` 
            WHERE u.`id` = :userId;';
        $senderUser = $db->selectSingle($sql, [
            ':userId'   => $this->_fleet['owner'],
        ]);

        $fleetArray = $this->fleetFunctions()->unserialize($this->_fleet['ships']);
        $this->refundFuel($senderUser, $fleetArray);

        $this->sendArrivalMessage($senderUser, $targetPlanet, $fleetArray);
        $this->RestoreFleet(false);
    }

    /**
     * Refunds half of the deuterium the flight cost: the cost really paid at dispatch (fuel_cost), for old fleets without
     * it a recomputation from the flight. The refund is added to the fleet's cargo.
     *
     * @param array<string, mixed> $senderUser fleet owner (users + settings)
     * @param array<int, int|float|string> $fleetArray ships by element id
     */
    private function refundFuel(array $senderUser, array $fleetArray): void
    {
        $duration = $this->_fleet['arrival_time'] - $this->_fleet['start_time'];

        $SpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        $distance = $this->fleetFunctions()->getTargetDistance(
            [
                $this->_fleet['start_galaxy'],
                $this->_fleet['start_system'],
                $this->_fleet['start_planet'],
            ],
            [
                $this->_fleet['end_galaxy'],
                $this->_fleet['end_system'],
                $this->_fleet['end_planet'],
            ],
        );

        $consumption = $this->fleetFunctions()->getFleetConsumption(
            $fleetArray,
            $duration,
            $distance,
            $senderUser,
            $SpeedFactor,
        );

        // Refund half of the fuel that was really paid at dispatch (fall back to a recomputation for old fleets)
        if (!empty($this->_fleet['fuel_cost'])) {
            $consumption = $this->_fleet['fuel_cost'];
        }

        $this->UpdateFleet('resource_deuterium', $this->_fleet['resource_deuterium'] + $consumption / 2);
    }

    /**
     * Tells the target owner that a fleet is stationed on his planet (sender planet, cargo and ship list), in the
     * language of the fleet owner.
     *
     * @param array<string, mixed> $senderUser fleet owner (users + settings)
     * @param array<string, mixed> $targetPlanet planet row
     * @param array<int, int|float|string> $fleetArray ships by element id
     */
    private function sendArrivalMessage(array $senderUser, array $targetPlanet, array $fleetArray): void
    {
        $db = $this->db;

        $LNG = $this->getLanguage($senderUser['lang']);
        $TargetUserID = $this->_fleet['target_owner'];

        $sql = 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $startPlanetName = $db->selectSingle($sql, [
            ':planetId'	=> $this->_fleet['start_id'],
        ], 'name');

        $stationShips = "";
        foreach ($fleetArray as $shipID => $amount) {
            $stationShips .= "<br>" . $LNG['tech'][$shipID] . ": " . $amount;
        }

        $TargetMessage = $LNG->format(
            'sys_stat_mess',
            $startPlanetName,
            GetStartAddressLink($this->_fleet, ''),
            $LNG['type_planet_' . $this->_fleet['end_type']],
            $targetPlanet['name'],
            GetTargetAddressLink($this->_fleet, ''),
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_metal']),
            $LNG['tech'][RESOURCE_METAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_crystal']),
            $LNG['tech'][RESOURCE_CRYSTAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_deuterium']),
            $LNG['tech'][RESOURCE_DEUT],
            $stationShips,
        );

        $this->playerMessenger()->sendMessage(
            $TargetUserID,
            0,
            $LNG['sys_mess_tower'],
            MessageType::Transport->value,
            $LNG['sys_stat_mess_stay'],
            $TargetMessage,
            $this->_fleet['arrival_time'],
            1,
            $this->_fleet['universe'],
        );
    }

    /**
     * No hold phase.
     */
    public function EndStayEvent()
    {
        return;
    }

    /**
     * Fleet that was turned back (target gone/vacation): message "fleet returned" with ship list and cargo, then landing at the start planet.
     */
    public function ReturnEvent()
    {
        $LNG = $this->getLanguage(null, $this->_fleet['owner']);

        $db = $this->db;
        $sql = 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $targetPlanet = $db->selectSingle($sql, [
            ':planetId' => $this->_fleet['start_id'],
        ]);

        $stationShips = "";
        $fleetArray = $this->fleetFunctions()->unserialize($this->_fleet['ships']);

        foreach ($fleetArray as $shipID => $amount) {
            $stationShips .= "<br>" . $LNG['tech'][$shipID] . ": " . $amount;
        }

        $Message = $LNG->format(
            'sys_fleet_return',
            $LNG['type_planet_' . $this->_fleet['start_type']],
            $targetPlanet['name'],
            GetStartAddressLink($this->_fleet, ''),
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_metal']),
            $LNG['tech'][901],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_crystal']),
            $LNG['tech'][902],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_deuterium']),
            $LNG['tech'][903],
            $stationShips,
        );

        $this->playerMessenger()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MessageType::System->value,
            $LNG['sys_mess_fleetback'],
            $Message,
            $this->_fleet['return_time'],
            1,
            $this->_fleet['universe'],
        );

        $this->RestoreFleet();
    }
}
