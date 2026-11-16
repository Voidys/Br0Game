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

use App\Game\BattleEngine\BattleFleet;
use App\Game\Core\ResourceUpdate;
use OutOfRangeException;

/**
 * Fleet side of a fight for AbstractBattleMission: collecting the attacking (ACS) and defending fleets and writing the
 * losses back to fleets and planet. Uses the mission's _fleet, db, state and the properties of AbstractBattleMission.
 */
trait BattleFleetHandling
{
    /**
     * Collects the fleets of the fight: the attacking fleets (the whole ACS group, deleting the group), the fleets holding at the
     * target that arrived before, and the planet's own ships and defense as fleet 0; and the current fleet state of every
     * involved player (for the rebuild protection).
     *
     * @param array<string, mixed> $targetUser   owner of the target
     * @param array<string, mixed> $targetPlanet target planet row
     */
    protected function collectParticipants(array $targetUser, array $targetPlanet): BattleSetup
    {
        $userAttack = [];
        $userDefend = [];

        [$incomingFleets, $attacker_id] = $this->loadIncomingFleets();

        $currentTotalFleetState = [];
        $fleetAttack = $this->battleFleetsOf($incomingFleets, $userAttack, $currentTotalFleetState);
        $firstFleet = reset($incomingFleets);
        $mainAttacker = $firstFleet === false ? null : $firstFleet['owner'];

        $fleetDefend = $this->battleFleetsOf($this->loadHoldingFleets($attacker_id), $userDefend, $currentTotalFleetState);

        $userDefend[$targetUser['id']] = $targetUser['username'];
        if (!isset($currentTotalFleetState[$targetUser['id']])) {
            $currentTotalFleetState[$targetUser['id']] = $this->fleetFunctions()->calculateCurrentTotalFleetState($targetUser['id']);
        }
        $fleetDefend[0] = new BattleFleet(
            0,
            $targetUser,
            $this->planetBattleUnits($targetPlanet),
            [
                'start_galaxy'    => $targetPlanet['galaxy'],
                'start_system'    => $targetPlanet['system'],
                'start_planet'    => $targetPlanet['planet'],
                'start_type'      => $targetPlanet['planet_type'],
            ],
            $currentTotalFleetState[$targetUser['id']],
        );

        $setup = new BattleSetup();
        $setup->fleetAttack = $fleetAttack;
        $setup->fleetDefend = $fleetDefend;
        $setup->userAttack = $userAttack;
        $setup->userDefend = $userDefend;
        $setup->currentTotalFleetState = $currentTotalFleetState;
        $setup->mainAttacker = $mainAttacker;

        return $setup;
    }

    /**
     * The attacking fleet rows: the whole ACS group (the group is deleted, its fleets row-locked or ordered by id, see
     * $acsFleetsLocked), or just this fleet.
     *
     * @return array{0: array<int|string, array<string, mixed>>, 1: int|string} fleet rows by fleet id, and the id of the
     *         lead fleet (first of the group when acsLeadIsFirstFleet, otherwise this fleet)
     */
    private function loadIncomingFleets(): array
    {
        $db = $this->db;
        $incomingFleets = [];

        if ($this->_fleet['group'] != 0) {
            $sql = "DELETE FROM %%AKS%% WHERE `id` = :acsId;";
            $db->delete($sql, [
                ':acsId'    => $this->_fleet['group'],
            ]);

            $acsQuery = $db->qb()
                ->select('*')
                ->from($db->table('FLEETS'))
                ->where('`group` = :acsId')
                ->setParameter('acsId', $this->_fleet['group']);
            if ($this->acsFleetsLocked) {
                $acsQuery->forUpdate();
            } else {
                $acsQuery->orderBy('`id`', 'ASC');
            }
            $incomingFleetsResult = $db->fetchAll($acsQuery);

            foreach ($incomingFleetsResult as $incomingFleetRow) {
                $incomingFleets[$incomingFleetRow['id']] = $incomingFleetRow;
            }

            $attacker_id = $this->acsLeadIsFirstFleet ? $incomingFleetsResult[0]['id'] : $this->_fleet['id'];
        } else {
            $incomingFleets = [$this->_fleet['id'] => $this->_fleet];
            $attacker_id = $this->_fleet['id'];
        }

        return [$incomingFleets, $attacker_id];
    }

    /**
     * Fleets holding at the target that arrived before the attack (earlier arrival time, or the same time and a lower
     * fleet id than the lead attacker); row-locked.
     *
     * @return list<array<string, mixed>> fleet rows
     */
    private function loadHoldingFleets(int|string $attackerId): array
    {
        $sql = "SELECT * FROM %%FLEETS%%
            WHERE `mission` = :mission
            AND `end_id` = :fleetEndId
            AND (`arrival_time` < :timeStamp OR (`arrival_time` = :timeStamp AND `id` < :acsFleetId))
            AND `stay_time` >= :timeStamp FOR UPDATE;";

        return $this->db->select($sql, [
            ':mission'      => MISSION_HOLD,
            ':fleetEndId'   => $this->_fleet['end_id'],
            ':timeStamp'    => $this->_fleet['arrival_time'],
            ':acsFleetId'   => $attackerId,
        ]);
    }

    /**
     * Turns fleet rows into battle fleets (with the owner's user row) and registers every owner: his name in $users and
     * his current total fleet state (for the rebuild protection) once in $currentTotalFleetState.
     *
     * @param iterable<array<string, mixed>> $fleetRows
     * @param array<int|string, string> $users out: user id => username
     * @param array<int|string, mixed> $currentTotalFleetState out: owner id => state
     * @return array<int|string, BattleFleet> by fleet id
     */
    private function battleFleetsOf(iterable $fleetRows, array &$users, array &$currentTotalFleetState): array
    {
        $fleets = [];
        foreach ($fleetRows as $fleetDetail) {
            $fleetID = $fleetDetail['id'];

            $sql = "SELECT * FROM %%USERS%% WHERE `id` = :userId;";
            $player = $this->db->selectSingle($sql, [
                ':userId'   => $fleetDetail['owner'],
            ]);

            $fleets[$fleetID] = new BattleFleet(
                $fleetID,
                $player,
                $this->fleetFunctions()->unserialize($fleetDetail['ships']),
                $fleetDetail,
            );

            if (!isset($currentTotalFleetState[$fleetDetail['owner']])) {
                $currentTotalFleetState[$fleetDetail['owner']] = $this->fleetFunctions()->calculateCurrentTotalFleetState($fleetDetail['owner']);
            }

            $users[$player['id']] = $player['username'];
        }

        return $fleets;
    }

    /**
     * The ships and defense standing on the planet (non-empty types only): the planet's own side of the fight, as the
     * units of fleet 0.
     *
     * @param array<string, mixed> $targetPlanet planet row
     * @return array<int, int|float|string> element id => amount
     */
    private function planetBattleUnits(array $targetPlanet): array
    {
        $resource = $this->state->resource;
        $reslist = $this->state->reslist;
        $planetUnits = [];
        foreach (array_merge($reslist['fleet'], $reslist['defense']) as $elementID) {
            if (empty($targetPlanet[$resource[$elementID]])) {
                continue;
            }

            $planetUnits[$elementID] = $targetPlanet[$resource[$elementID]];
        }

        return $planetUnits;
    }

    /**
     * Writes the fight result to the fleets: surviving ships are saved (fleet and log), fleets without ships are deleted and
     * logged as destroyed, the planet's ships and defense are set to what survived. Destroyed fleets are removed from the setup.
     */
    protected function applyLosses(BattleSetup $setup, string $reportID): void
    {
        $resource = $this->state->resource;

        foreach ($setup->fleetAttack as $fleetID => $fleet) {
            if ($this->saveFleetSurvivors($fleetID, $fleet, $reportID)) {
                unset($setup->fleetAttack[$fleetID]);
            }
        }

        foreach ($setup->fleetDefend as $fleetID => $fleet) {
            if ($fleetID != 0) {
                // Stay fleet
                if ($this->saveFleetSurvivors($fleetID, $fleet, $reportID)) {
                    unset($setup->fleetDefend[$fleetID]);
                }
            } else {
                // Planet fleet: the surviving ships and defense (the resource names are fixed game data)
                $survivorColumns = [];
                foreach ($fleet->units as $elementID => $amount) {
                    $survivorColumns[$resource[$elementID]] = $amount;
                }
                $this->db->updateRows('PLANETS', $survivorColumns, ['id' => $this->_fleet['end_id']]);
            }
        }
    }

    /**
     * Writes the survivors of one fleet: without ships the fleet is killed (the current fleet) or deleted with its event
     * and its log entry is marked destroyed with the report id; otherwise the remaining ships are saved to the fleet and
     * its log together with the report id.
     *
     * @param int|string $fleetID fleet id
     * @return bool true when the fleet was destroyed (the caller removes it from the setup)
     * @throws OutOfRangeException on a negative ship count
     */
    private function saveFleetSurvivors(int|string $fleetID, BattleFleet $fleet, string $reportID): bool
    {
        $db = $this->db;
        $fleetArray = '';
        $totalCount = 0;

        $survivors = array_filter($fleet->units);
        foreach ($survivors as $elementID => $amount) {
            $fleetArray .= $elementID . ',' . floatToString($amount) . ';';
            $totalCount += $amount;
        }

        if ($totalCount == 0) {
            if ($this->_fleet['id'] == $fleetID) {
                $this->KillFleet();
            } else {
                $sql = 'DELETE %%FLEETS%%, %%FLEETS_EVENT%%
                    FROM %%FLEETS%%
                    INNER JOIN %%FLEETS_EVENT%% ON `fleetID` = `id`
                    WHERE `id` = :fleetId;';

                $db->delete($sql, [
                    ':fleetId'  => $fleetID,
                ]);
            }

            $sql = 'UPDATE %%LOG_FLEETS%% SET `report_id` = :reportId, `status` = :fleetState WHERE `id` = :fleetId;';
            $db->update($sql, [
                ':reportId'     => $reportID,
                ':fleetId'      => $fleetID,
                ':fleetState'   => FLEET_DESTROYED,
            ]);

            return true;
        }

        if ($totalCount > 0) {
            $sql = "UPDATE %%FLEETS%% f, %%LOG_FLEETS%% l SET
                f.`ships` = :fleetData,
                f.`ship_amount` = :fleetCount,
                l.`ships` = :fleetData,
                l.`ship_amount` = :fleetCount,
                l.`report_id` = :reportId
                WHERE f.`id` = :fleetId AND l.`id` = :fleetId;";

            $db->update($sql, [
                ':fleetData'    => substr($fleetArray, 0, -1),
                ':fleetCount'   => $totalCount,
                ':fleetId'      => $fleetID,
                ':reportId'     => $reportID,
            ]);

            return false;
        }

        throw new OutOfRangeException("Negative Fleet amount ....");
    }
}
