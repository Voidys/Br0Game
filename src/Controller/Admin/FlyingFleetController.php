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

use App\Game\Enum\Mission;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Universe;

/**
 * Lists the fleets in flight of the emulated universe; fleets can be sent back (single or all attacking).
 */
class FlyingFleetController extends AbstractAdminController
{
    /**
     * Fleets in flight of the emulated universe with owner, mission, origin and target.
     */
    #[GameRoute('admin', 'admin/flying-fleets', 'flyingFleet')]
    public function show(): Response
    {
        $FleetList = [];

        foreach ($this->fleetsInFlight() as $fleetRow) {
            $FleetList[] = $this->fleetListEntry($fleetRow);
        }

        return $this->render('admin/FlyingFleetPage.html.twig', [
            'FleetList'    => $FleetList,
        ]);
    }

    /** POST lock (0 = unlock, else lock): locks or unlocks the event of one fleet, then lists the fleets. */
    #[GameRoute('admin', 'admin/flying-fleets/{id}/lock', 'flyingFleet', 'lock', ['POST'], ['id' => '\d+'])]
    public function lock(): Response
    {
        $id = $this->input()->int('id');
        if (!empty($id)) {
            $lock = $this->input()->int('lock');
            $SQL = ($lock == 0) ? null : "ADM_LOCK";

            if (!$this->mayActOnFleetOwner($id)) {
                $this->deny();
            }
            $this->db->update("UPDATE %%FLEETS_EVENT%% SET `lock` = :lock WHERE `fleetID` = :id AND `fleetID` IN (SELECT `id` FROM %%FLEETS%% WHERE `universe` = :universe);", [
                ':lock' => $SQL,
                ':id' => $id,
                ':universe' => $this->universe()->getEmulated(),
            ]);
        }

        return $this->show();
    }

    /** POST: unlocks the events of all fleets of the universe, then lists the fleets. */
    #[GameRoute('admin', 'admin/flying-fleets/unlock-all', 'flyingFleet', 'unlockAll', ['POST'])]
    public function unlockAll(): Response
    {
        $this->db->update("UPDATE %%FLEETS_EVENT%% SET `lock` = NULL WHERE `fleetID` IN (SELECT `id` FROM %%FLEETS%% WHERE `universe` = :universe);", [
            ':universe' => $this->universe()->getEmulated(),
        ]);

        return $this->show();
    }

    /** POST: recalls all attacking fleets (undoing alliance combat groups), then lists the fleets. */
    #[GameRoute('admin', 'admin/flying-fleets/recall-all', 'flyingFleet', 'recallAll', ['POST'])]
    public function recallAll(): Response
    {
        $this->returnAttackingFleets();

        return $this->show();
    }

    /** POST: recalls one fleet like a player would, then lists the fleets. */
    #[GameRoute('admin', 'admin/flying-fleets/{id}/recall', 'flyingFleet', 'recall', ['POST'], ['id' => '\d+'])]
    public function recall(): Response
    {
        $fleetId = $this->input()->int('id');
        if (!empty($fleetId)) {
            $this->returnFleet($fleetId);
        }

        return $this->show();
    }

    /**
     * All fleets of the emulated universe with lock state, event count (0 = event missing) and the names of start and
     * target planet/player and of the ACS group.
     *
     * @return array<int, array<string, mixed>> fleet rows
     */
    private function fleetsInFlight(): array
    {
        $db = $this->db;

        return $db->select(
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
    }

    /**
     * Template row of one fleet: parsed ship list, formatted times, start and target data and the carried resources.
     *
     * @param array<string, mixed> $fleetRow row from fleetsInFlight()
     * @return array<string, mixed>
     */
    private function fleetListEntry(array $fleetRow): array
    {
        $LNG = $this->state->LNG;

        $shipList = [];
        $shipArray = array_filter(explode(';', $fleetRow['ships']));
        foreach ($shipArray as $ship) {
            $shipDetail = explode(',', $ship);
            $shipList[$shipDetail[0]] = $shipDetail[1];
        }

        return [
            'fleetID'            => $fleetRow['id'],
            'lock'               => !empty($fleetRow['lock']),
            'count'              => $fleetRow['ship_amount'],
            'error'              => !$fleetRow['error'],
            'ships'              => $shipList,
            'state'              => $fleetRow['status'],
            'starttime'          => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $fleetRow['start_time'], $this->state->USER['timezone']),
            'arrivaltime'        => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $fleetRow['arrival_time'], $this->state->USER['timezone']),
            'stayhour'           => round(($fleetRow['stay_time'] - $fleetRow['arrival_time']) / 3600),
            'staytime'           => $fleetRow['arrival_time'] !== $fleetRow['stay_time']
                ? $this->viewHelpers()->formatDate($LNG['php_tdformat'], $fleetRow['stay_time'], $this->state->USER['timezone']) : 0,
            'endtime'            => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $fleetRow['return_time'], $this->state->USER['timezone']),
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

    /** May the acting admin act on the owner of the fleet (own fleets included; unknown fleets are refused). */
    private function mayActOnFleetOwner(int $fleetID): bool
    {
        $owner = $this->db->selectSingle('SELECT `owner` FROM %%FLEETS%% WHERE `id` = :id AND `universe` = :universe;', [
            ':id' => $fleetID,
            ':universe' => $this->universe()->getEmulated(),
        ], 'owner');

        return !empty($owner) && $this->mayActOn((int) $owner, true);
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
            WHERE f.`id` = :id AND f.`universe` = :universe;";
        $USER = $db->selectSingle($sql, [
            ':id' => $fleetID,
            ':universe' => $this->universe()->getEmulated(),
        ]);

        if (empty($USER) || !$this->mayActOn((int) $USER['id'], true)) {
            $this->deny();
        }

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

        $sql = "SELECT f.`id`, f.`owner`, f.`group`, f.`return_time` FROM %%FLEETS%% f
            JOIN %%USERS%% u ON u.`id` = f.`target_owner`
            WHERE f.`mission` IN (:attack, :acs, :destruction) AND f.`universe` = :universe
              AND u.`onlinetime` > :inactive AND f.`status` <> :fleetState;";
        $fleets = $db->select($sql, [
            ':attack'      => Mission::Attack->value,
            ':acs'         => Mission::Acs->value,
            ':destruction' => Mission::Destruction->value,
            ':universe'    => $this->universe()->getEmulated(),
            ':inactive'  => $this->state->runtime->timestamp - INACTIVE,
            ':fleetState' => FLEET_RETURN,
        ]);

        foreach ($fleets as $fleet) {
            if (!$this->mayActOn((int) $fleet['owner'], true)) {
                continue;
            }
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
