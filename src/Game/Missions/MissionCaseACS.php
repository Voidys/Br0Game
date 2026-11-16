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

/**
 * Mission "ACS" (2): the entry of a fleet that joined an ACS group attack. The fight of the whole group is
 * resolved once by the lead attack fleet (AbstractBattleMission::collectParticipants loads all fleets of the
 * group), so when this fleet's own arrival event fires it only turns around (its ships and cargo were already
 * updated by that fight). Return behaves like an attack: message plus landing.
 */
class MissionCaseACS extends MissionFunctions implements MissionInterface
{
    public function __construct($Fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet	= $Fleet;
    }

    /**
     * Turns the fleet around (state FLEET_RETURN); the group fight is not run here.
     */
    public function TargetEvent()
    {
        $this->setState(FLEET_RETURN);
        $this->SaveFleet();
        return;
    }

    /**
     * No hold phase.
     */
    public function EndStayEvent()
    {
        return;
    }

    /**
     * Sends the "fleet returned" message and lands ships and cargo at the start planet.
     */
    public function ReturnEvent()
    {
        $this->sendAttackReturnMessage();

        $this->RestoreFleet();
    }
}
