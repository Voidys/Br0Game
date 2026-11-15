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
        $resource = & $this->state->resource;
        $reslist = & $this->state->reslist;
        $db = $this->db;
        $fleetAttack = [];
        $fleetDefend = [];
        $userAttack = [];
        $userDefend = [];
        $incomingFleets = [];

        if ($this->_fleet['group'] != 0) {
            $sql = "DELETE FROM %%AKS%% WHERE `id` = :acsId;";
            $db->delete($sql, [
                ':acsId'    => $this->_fleet['group'],
            ]);

            $sql = "SELECT * FROM %%FLEETS%% WHERE `group` = :acsId " . $this->acsFleetsOrder . ";";

            $incomingFleetsResult = $db->select($sql, [
                ':acsId'    => $this->_fleet['group'],
            ]);

            foreach ($incomingFleetsResult as $incomingFleetRow) {
                $incomingFleets[$incomingFleetRow['id']] = $incomingFleetRow;
            }

            $attacker_id = $this->acsLeadIsFirstFleet ? $incomingFleetsResult[0]['id'] : $this->_fleet['id'];
            unset($incomingFleetsResult);
        } else {
            $incomingFleets = [$this->_fleet['id'] => $this->_fleet];
            $attacker_id = $this->_fleet['id'];
        }

        $currentTotalFleetState = [];
        $mainAttacker = null;
        foreach ($incomingFleets as $fleetID => $fleetDetail) {
            $sql = "SELECT * FROM %%USERS%% WHERE `id` = :userId;";
            $fleetAttack[$fleetID]['player'] = $db->selectSingle($sql, [
                ':userId'   => $fleetDetail['owner'],
            ]);

            $fleetAttack[$fleetID]['fleetDetail'] = $fleetDetail;
            $fleetAttack[$fleetID]['unit'] = $this->fleetFunctions()->unserialize($fleetDetail['ships']);

            if (!isset($currentTotalFleetState[$fleetDetail['owner']])) {
                $currentTotalFleetState[$fleetDetail['owner']] = $this->fleetFunctions()->calculateCurrentTotalFleetState($fleetDetail['owner']);
            }

            $userAttack[$fleetAttack[$fleetID]['player']['id']] = $fleetAttack[$fleetID]['player']['username'];

            if ($mainAttacker === null) {
                $mainAttacker = $fleetDetail['owner'];
            }
        }

        $sql = "SELECT * FROM %%FLEETS%%
            WHERE `mission` = :mission
            AND `end_id` = :fleetEndId
            AND (`arrival_time` < :timeStamp OR (`arrival_time` = :timeStamp AND `id` < :acsFleetId))
            AND `stay_time` >= :timeStamp FOR UPDATE;";

        $targetFleetsResult = $db->select($sql, [
            ':mission'      => MISSION_HOLD,
            ':fleetEndId'   => $this->_fleet['end_id'],
            ':timeStamp'    => $this->_fleet['arrival_time'],
            ':acsFleetId'   => $attacker_id,
        ]);

        foreach ($targetFleetsResult as $fleetDetail) {
            $fleetID = $fleetDetail['id'];

            $sql = "SELECT * FROM %%USERS%% WHERE `id` = :userId;";
            $fleetDefend[$fleetID]['player'] = $db->selectSingle($sql, [
                ':userId'   => $fleetDetail['owner'],
            ]);

            $fleetDefend[$fleetID]['fleetDetail'] = $fleetDetail;
            $fleetDefend[$fleetID]['unit'] = $this->fleetFunctions()->unserialize($fleetDetail['ships']);

            if (!isset($currentTotalFleetState[$fleetDetail['owner']])) {
                $currentTotalFleetState[$fleetDetail['owner']] = $this->fleetFunctions()->calculateCurrentTotalFleetState($fleetDetail['owner']);
            }

            $userDefend[$fleetDefend[$fleetID]['player']['id']] = $fleetDefend[$fleetID]['player']['username'];
        }

        unset($targetFleetsResult);

        $fleetDefend[0]['player'] = $targetUser;
        $fleetDefend[0]['fleetDetail'] = [
            'start_galaxy'    => $targetPlanet['galaxy'],
            'start_system'    => $targetPlanet['system'],
            'start_planet'    => $targetPlanet['planet'],
            'start_type'      => $targetPlanet['planet_type'],
        ];

        $fleetDefend[0]['unit'] = [];

        foreach (array_merge($reslist['fleet'], $reslist['defense']) as $elementID) {
            if (empty($targetPlanet[$resource[$elementID]])) {
                continue;
            }

            $fleetDefend[0]['unit'][$elementID] = $targetPlanet[$resource[$elementID]];
        }

        $userDefend[$fleetDefend[0]['player']['id']] = $fleetDefend[0]['player']['username'];
        if (!isset($currentTotalFleetState[$targetUser['id']])) {
            $currentTotalFleetState[$targetUser['id']] = $this->fleetFunctions()->calculateCurrentTotalFleetState($targetUser['id']);
        }
        $fleetDefend[0]['currentTotalFleetState'] = $currentTotalFleetState[$targetUser['id']];

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
     * Writes the fight result to the fleets: surviving ships are saved (fleet and log), fleets without ships are deleted and
     * logged as destroyed, the planet's ships and defense are set to what survived. Destroyed fleets are removed from the setup.
     */
    protected function applyLosses(BattleSetup $setup, string $reportID): void
    {
        $resource = & $this->state->resource;
        $db = $this->db;
        $fleetAttack = & $setup->fleetAttack;
        $fleetDefend = & $setup->fleetDefend;

        foreach ($fleetAttack as $fleetID => $fleetDetail) {
            $fleetArray = '';
            $totalCount = 0;

            $fleetDetail['unit'] = array_filter($fleetDetail['unit']);
            foreach ($fleetDetail['unit'] as $elementID => $amount) {
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

                unset($fleetAttack[$fleetID]);
            } elseif ($totalCount > 0) {
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
            } else {
                throw new OutOfRangeException("Negative Fleet amount ....");
            }
        }

        foreach ($fleetDefend as $fleetID => $fleetDetail) {
            if ($fleetID != 0) {
                // Stay fleet
                $fleetArray = '';
                $totalCount = 0;

                $fleetDetail['unit'] = array_filter($fleetDetail['unit']);

                foreach ($fleetDetail['unit'] as $elementID => $amount) {
                    $fleetArray .= $elementID . ',' . floatToString($amount) . ';';
                    $totalCount += $amount;
                }

                if ($totalCount == 0) {
                    $sql = 'DELETE %%FLEETS%%, %%FLEETS_EVENT%%
                        FROM %%FLEETS%%
                        INNER JOIN %%FLEETS_EVENT%% ON `fleetID` = `id`
                        WHERE `id` = :fleetId;';

                    $db->delete($sql, [
                        ':fleetId'  => $fleetID,
                    ]);

                    $sql = 'UPDATE %%LOG_FLEETS%% SET `report_id` = :reportId, `status` = :fleetState WHERE `id` = :fleetId;';
                    $db->update($sql, [
                        ':reportId'     => $reportID,
                        ':fleetId'      => $fleetID,
                        ':fleetState'   => FLEET_DESTROYED,
                    ]);

                    unset($fleetDefend[$fleetID]);
                } elseif ($totalCount > 0) {
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
                } else {
                    throw new OutOfRangeException("Negative Fleet amount ....");
                }
            } else {
                $params = [':planetId' => $this->_fleet['end_id']];

                // Planet fleet
                $fleetArray = [];
                foreach ($fleetDetail['unit'] as $elementID => $amount) {
                    $fleetArray[] = '`' . $resource[$elementID] . '` = :' . $resource[$elementID];
                    $params[':' . $resource[$elementID]] = $amount;
                }

                if (!empty($fleetArray)) {
                    $sql = 'UPDATE %%PLANETS%% SET ' . implode(', ', $fleetArray) . ' WHERE `id` = :planetId;';
                    $db->update($sql, $params);
                }
            }
        }
    }
}
