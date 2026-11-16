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

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * Lists the fleets in flight of the emulated universe; fleets can be sent back (single or all attacking).
 */
#[\App\Routing\GamePage('admin.php', 'flyingFleet')]
#[\App\Routing\GamePage('admin.php', 'flyingFleet', uniPrefix: true)]
class FlyingFleetController extends AbstractAdminController
{
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $USER = &$this->state->USER;
        $db = $this->db;
        $id = $this->http()->_GP('id', 0);
        $massunlock = $this->http()->_GP('massunlock', 0);
        $sendFleetsBack = $this->http()->_GP('sendfleetsback', 0);
        $sendFleetBack = $this->http()->_GP('sendfleetback', 0);

        if (!empty($id)) {
            $lock = $this->http()->_GP('lock', 0);
            $SQL = ($lock == 0) ? null : "ADM_LOCK";

            $db->update("UPDATE %%FLEETS_EVENT%% SET `lock` = :lock WHERE `fleetID` = :id;", [
                ':lock' => $SQL,
                ':id' => $id,
            ]);
        } elseif (!empty($massunlock)) {
            $db->update("UPDATE %%FLEETS_EVENT%% SET `lock` = NULL");
        } elseif (!empty($sendFleetsBack)) {
            $this->returnAttackingFleets();
        } elseif (!empty($sendFleetBack)) {
            $this->returnFleet($sendFleetBack);
        }

        $fleetResult = $db->select(
            "SELECT fleet.*,
            event.`lock`,
            COUNT(event.`fleetID`) as error,
            pstart.`name` as startPlanetName,
            ptarget.`name` as targetPlanetName,
            ustart.`username` as startUserName,
            utarget.`username` as targetUserName,
            acs.`name` as acsName
            FROM %%FLEETS%% fleet
            LEFT JOIN %%FLEETS_EVENT%% event ON `fleetID` = fleet.`id`
            LEFT JOIN %%PLANETS%% pstart ON pstart.`id` = fleet.`start_id`
            LEFT JOIN %%PLANETS%% ptarget ON ptarget.`id` = fleet.`end_id`
            LEFT JOIN %%USERS%% ustart ON ustart.`id` = fleet.`owner`
            LEFT JOIN %%USERS%% utarget ON utarget.`id` = fleet.`target_owner`
            LEFT JOIN %%AKS%% acs ON acs.`id` = fleet.`group`
            WHERE fleet.`universe` = :universe
            GROUP BY event.`fleetID`
            ORDER BY `id`;",
            [
                ':universe' => $this->universe()->getEmulated(),
            ],
        );

        $FleetList = [];

        foreach ($fleetResult as $fleetRow) {
            $shipList = [];
            $shipArray = array_filter(explode(';', $fleetRow['ships']));
            foreach ($shipArray as $ship) {
                $shipDetail = explode(',', $ship);
                $shipList[$shipDetail[0]] = $shipDetail[1];
            }

            $USER = &$this->state->USER;
            $FleetList[] = [
                'fleetID'            => $fleetRow['id'],
                'lock'               => !empty($fleetRow['lock']),
                'count'              => $fleetRow['ship_amount'],
                'error'              => !$fleetRow['error'],
                'ships'              => $shipList,
                'state'              => $fleetRow['status'],
                'starttime'          => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $fleetRow['start_time'], $USER['timezone']),
                'arrivaltime'        => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $fleetRow['arrival_time'], $USER['timezone']),
                'stayhour'           => round(($fleetRow['stay_time'] - $fleetRow['arrival_time']) / 3600),
                'staytime'           => $fleetRow['arrival_time'] !== $fleetRow['stay_time']
                    ? $this->viewHelpers()->formatDate($LNG['php_tdformat'], $fleetRow['stay_time'], $USER['timezone']) : 0,
                'endtime'            => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $fleetRow['return_time'], $USER['timezone']),
                'missionID'          => $fleetRow['mission'],
                'acsID'              => $fleetRow['group'],
                'acsName'            => $fleetRow['acsName'],
                'startUserID'        => $fleetRow['owner'],
                'startUserName'      => $fleetRow['startUserName'],
                'startPlanetID'      => $fleetRow['start_id'],
                'startPlanetName'    => $fleetRow['startPlanetName'],
                'startPlanetGalaxy'  => $fleetRow['start_galaxy'],
                'startPlanetSystem'  => $fleetRow['start_system'],
                'startPlanetPlanet'  => $fleetRow['start_planet'],
                'startPlanetType'    => $fleetRow['start_type'],
                'targetUserID'       => $fleetRow['target_owner'],
                'targetUserName'     => $fleetRow['targetUserName'],
                'targetPlanetID'     => $fleetRow['end_id'],
                'targetPlanetName'   => $fleetRow['targetPlanetName'],
                'targetPlanetGalaxy' => $fleetRow['end_galaxy'],
                'targetPlanetSystem' => $fleetRow['end_system'],
                'targetPlanetPlanet' => $fleetRow['end_planet'],
                'targetPlanetType'   => $fleetRow['end_type'],
                'resource'           => [
                    RESOURCE_METAL => $fleetRow['resource_metal'],
                    RESOURCE_CRYSTAL => $fleetRow['resource_crystal'],
                    RESOURCE_DEUT => $fleetRow['resource_deuterium'],
                ],
            ];
        }

        $this->assign([
            'FleetList'    => $FleetList,
        ]);
        return $this->render('admin/FlyingFleetPage.html.twig');
    }

    /**
     * Sends one fleet back. Uses the same function as for the user
     *
     * @param integer $fleetID
     * @return void
     */
    private function returnFleet(int $fleetID): void
    {
        $db = $this->db;

        $sql = "SELECT u.`id` FROM %%USERS%% u
            JOIN %%FLEETS%% f ON f.`owner` = u.`id`
            WHERE f.`id` = :id;";
        $USER = $db->selectSingle($sql, [
            ':id' => $fleetID,
        ]);

        $this->fleetFunctions()->sendFleetBack($USER, $fleetID);
    }

    /**
     * Returns all flying fleets with mission Attack, ACS and Destroy on active players to its owner using the original return time.
     *
     * @return void
     */
    private function returnAttackingFleets(): void
    {
        $db = $this->db;

        $sql = "SELECT f.`id`, f.`group`, f.`return_time` FROM %%FLEETS%% f
            JOIN %%USERS%% u ON u.`id` = f.`target_owner`
            WHERE f.`mission` IN (:missions)
              AND u.`onlinetime` > :inactive AND f.`status` <> :fleetState;";
        $fleets = $db->select($sql, [
            ':missions'  => MISSION_ATTACK . ', ' . MISSION_ACS . ', ' . MISSION_DESTRUCTION,
            ':inactive'  => $this->state->runtime->timestamp - INACTIVE,
            ':fleetState' => FLEET_RETURN,
        ]);

        foreach ($fleets as $fleet) {
            if ($fleet['group'] > 0) {
                $sql = "DELETE %%AKS%%, %%USERS_TO_ACS%%
                    FROM %%AKS%%
                    LEFT JOIN %%USERS_TO_ACS%% ON `acsID` = %%AKS%%.id
                    WHERE %%AKS%%.`id` = :acsId;";

                $db->delete($sql, [
                    ':acsId' => $fleet['group'],
                ]);
            }

            $sql = "UPDATE %%FLEETS%% SET
                `hasCanceled` = :hasCanceled,
                `status` = :fleetState, `group`	= :fleetGroup
                WHERE `id` = :id;";
            $db->update($sql, [
                ':id'          => $fleet['id'],
                ':hasCanceled' => 1,
                ':fleetState'  => FLEET_RETURN,
                ':fleetGroup'  => 0,
            ]);

            $sql = "UPDATE %%LOG_FLEETS%% SET
                `hasCanceled` = :hasCanceled,
                `status` = :fleetState
                WHERE `id` = :id;";

            $db->update($sql, [
                ':id'          => $fleet['id'],
                ':hasCanceled' => 1,
                ':fleetState'   => FLEET_RETURN,
            ]);

            $sql = "UPDATE %%FLEETS_EVENT%% SET
                `time` = :endTime
                WHERE `fleetID` = :id;";
            $db->update($sql, [
                ':id'      => $fleet['id'],
                ':endTime' => $fleet['return_time'],
            ]);
        }
    }
}
