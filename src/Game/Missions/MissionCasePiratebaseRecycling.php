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

/**
 * Mission "pirate base recycling" (22): recyclers collect the debris field of a pirate base (stored in
 * `pirate_bases.der_metal/der_crystal`); same loading rules as MissionCaseRecycling.
 */
class MissionCasePiratebaseRecycling extends MissionFunctions implements MissionInterface
{
    public function __construct($Fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet = $Fleet;
    }

    /**
     * Arrival: like MissionCaseRecycling::TargetEvent but the debris is taken from the pirate base; if the base is gone
     * the fleet returns with a "base gone" message. Capacity is the recyclers' plus non-recycler space left after
     * the cargo on board; a too small capacity takes both resources proportionally.
     */
    public function TargetEvent()
    {
        $debrisIDs = [RESOURCE_METAL, RESOURCE_CRYSTAL];
        $collectedGoods = [RESOURCE_METAL => 0, RESOURCE_CRYSTAL => 0];
        $remainingGoods = [RESOURCE_METAL => 0, RESOURCE_CRYSTAL => 0];

        $targetData = $this->lockDebrisField('PIRATE_BASES', $this->_fleet['end_id'], $debrisIDs);

        // return fleet if the base is gone (removed or refreshed since launch)
        if ($targetData == false) {
            $this->sendPiratebaseGoneMessage();
            $this->setState(FLEET_RETURN);
            $this->SaveFleet();
            return;
        }

        if (!empty($targetData['total'])) {
            [$collectedGoods, $remainingGoods] = $this->collectDebris('PIRATE_BASES', $targetData);
        } else {
            $this->checkEmptyRecycling($collectedGoods);
        }

        $this->sendRecyclingReport($collectedGoods, $remainingGoods, $targetData);

        $this->setState(FLEET_RETURN);
        $this->SaveFleet();
    }

    /**
     * No hold phase.
     */
    public function EndStayEvent()
    {
        return;
    }

    /**
     * Sends the "recycling returned" message with the gathered resources and lands ships and cargo at the start planet.
     */
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
