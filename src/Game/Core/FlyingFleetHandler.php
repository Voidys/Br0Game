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

use App\Game\Missions\MissionCaseACS;
use App\Game\Missions\MissionCaseAttack;
use App\Game\Missions\MissionCaseColonisation;
use App\Game\Missions\MissionCaseDestruction;
use App\Game\Missions\MissionCaseExpedition;
use App\Game\Missions\MissionCaseMIP;
use App\Game\Missions\MissionCasePiratebaseAttack;
use App\Game\Missions\MissionCasePiratebaseRecycling;
use App\Game\Missions\MissionCasePiratebaseSpy;
use App\Game\Missions\MissionCaseRecycling;
use App\Game\Missions\MissionCaseSpy;
use App\Game\Missions\MissionCaseStay;
use App\Game\Missions\MissionCaseStayAlly;
use App\Game\Missions\MissionCaseTrade;
use App\Game\Missions\MissionCaseTransfer;
use App\Game\Missions\MissionCaseTransport;
use App\Game\Missions\MissionInterface;

class FlyingFleetHandler
{
    public function __construct(
        private Database $db,
        private ConfigRegistry $configs,
        private Singleton $state,
    ) {}

    protected string $token;

    /**
     * Mission id => mission class. A method (not a static property) because the MISSION_* constants
     * only exist after the game bootstrap, while the container loads this class earlier.
     *
     * @return array<int, class-string>
     */
    public static function missionClasses(): array
    {
        return [
        MISSION_ATTACK                  => MissionCaseAttack::class,
        MISSION_ACS                     => MissionCaseACS::class,
        MISSION_TRANSPORT               => MissionCaseTransport::class,
        MISSION_STATION                 => MissionCaseStay::class,
        MISSION_HOLD                    => MissionCaseStayAlly::class,
        MISSION_SPY                     => MissionCaseSpy::class,
        MISSION_COLONISATION            => MissionCaseColonisation::class,
        MISSION_RECYCLING               => MissionCaseRecycling::class,
        MISSION_DESTRUCTION             => MissionCaseDestruction::class,
        MISSION_MISSILE                 => MissionCaseMIP::class,
        MISSION_EXPEDITION              => MissionCaseExpedition::class,
        MISSION_TRADE                   => MissionCaseTrade::class,
        MISSION_TRANSFER                => MissionCaseTransfer::class,
        MISSION_PIRATEBASE_ATTACK       => MissionCasePiratebaseAttack::class,
        MISSION_PIRATEBASE_SPY          => MissionCasePiratebaseSpy::class,
        MISSION_PIRATEBASE_RECYCLING    => MissionCasePiratebaseRecycling::class,
        ];
    }

    public function setToken(string $token): void
    {
        $this->token    = $token;
    }

    public function run(): void
    {

        $db = $this->db;

        $sql = 'SELECT f.*
            FROM %%FLEETS_EVENT%% fe
            INNER JOIN %%FLEETS%% f ON fe.`fleetID` = f.`id`
            WHERE fe.`lock` = :token ORDER BY fe.`time` ASC, fe.`fleetID` ASC FOR UPDATE;';

        $fleetResult = $db->select($sql, [
            ':token'    => $this->token
        ]);

        foreach ($fleetResult as $fleetRow) {
            if (!isset(self::missionClasses()[$fleetRow['mission']])) {
                $sql = 'DELETE FROM %%FLEETS%% WHERE `id` = :fleetId;';

                $db->delete($sql, [
                    ':fleetId'  => $fleetRow['id']
                ]);

                continue;
            }

            $missionName    = self::missionClasses()[$fleetRow['mission']];

            /** @var MissionInterface $missionObj */
            $missionObj = new $missionName($fleetRow, $this->db, $this->configs, $this->state);

            switch ($fleetRow['status']) {
                case FLEET_OUTWARD:
                    $missionObj->TargetEvent();
                    break;
                case FLEET_RETURN:
                    $missionObj->ReturnEvent();
                    break;
                case FLEET_HOLD:
                    $missionObj->EndStayEvent();
                    break;
            }
        }
    }
}
