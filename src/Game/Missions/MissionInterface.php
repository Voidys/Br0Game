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
 * Lifecycle of a fleet mission, called by FlyingFleetHandler depending on the fleet status when its event is due:
 * TargetEvent (status FLEET_OUTWARD, arrived at the target), EndStayEvent (FLEET_HOLD, hold time over) and
 * ReturnEvent (FLEET_RETURN, back at the start planet).
 */
interface MissionInterface
{
    /**
     * The fleet reached its target at arrival_time: execute the mission and set the next state (usually FLEET_RETURN).
     */
    public function TargetEvent();

    /**
     * The hold time (stay_time) of a fleet in state FLEET_HOLD is over.
     */
    public function EndStayEvent();

    /**
     * The fleet is back at its start planet at return_time: report and land ships and cargo.
     */
    public function ReturnEvent();
}
