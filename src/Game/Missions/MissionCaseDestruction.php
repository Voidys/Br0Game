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
use App\Game\BattleEngine\BattleHandler;

/**
 * Fleet mission "moon destruction": a normal fight (AbstractBattleMission); when the attacker wins, the moon is destroyed
 * with a chance from the moon size and the attacking death stars, and the fleet is lost with a chance from the moon size.
 */
class MissionCaseDestruction extends AbstractBattleMission
{
    /**
     * Arrival at the moon: fights, applies losses and debris, rolls the moon and fleet destruction on a win, reports to
     * everybody, then deletes the moon (a destroyed moon is not looted) or takes the loot, and sends the fleets home.
     */
    public function TargetEvent()
    {
        $config = $this->configs->get($this->_fleet['universe']);
        $stealResource = [
            901 => 0,
            902 => 0,
            903 => 0,
        ];
        $messageHTML = $this->getMessageText();

        $target = $this->lockTarget();
        if ($target === null) {
            return;
        }
        [$targetUser, $targetPlanet, $targetPlanetID] = $target;
        $setup = $this->collectParticipants($targetUser, $targetPlanet);

        $battleHandler = new BattleHandler(false, $this->db, $this->configs, $this->state);

        $combatResult = $battleHandler->engine->calculateAttack($setup->fleetAttack, $setup->fleetDefend, $this->_fleet['universe']);
        $reportID = md5(uniqid('', true) . $this->state->runtime->timestamp);
        if (!$combatResult) {
            $this->cancelFight($setup->userAttack, $setup->userDefend, $targetPlanet);
            return;
        }

        if ($config->noob_protection && $config->rebuild_protection) {
            $battleHandler->CheckRebuildProtection($setup->currentTotalFleetState, $combatResult, $this->_fleet['arrival_time'], $config->noob_protection_time);
        }

        $this->applyLosses($setup, $reportID);

        if ($combatResult['won'] == ATTACKER) {
            $stealResource = $battleHandler->calculateSteal($setup->fleetAttack, $targetPlanet, $this->_fleet['universe']);
        }

        [$debris, $planetDebris] = $this->computeDebris($targetPlanet, $combatResult);

        $reportInfo = [
            'thisFleet'             => $this->_fleet,
            'debris'                => $debris,
            'stealResource'         => $stealResource,
            'moonChance'            => null,
            'additionalChance'      => 0,
            'moonDestroy'           => true,
            'moonName'              => null,
            'moonDestroyChance'     => null,
            'moonDestroySuccess'    => null,
            'fleetDestroyChance'    => null,
            'fleetDestroySuccess'   => false,
        ];

        $units = $combatResult['unitLost']['attacker'] + $combatResult['unitLost']['defender'];

        if ($combatResult['won'] == ATTACKER) {
            $this->rollDestruction($setup, $targetPlanet, $reportInfo);
        }
        $class = $this->recordResultStats($setup, $combatResult, $units);
        if ($combatResult['won'] != ATTACKER) {
            $reportInfo['moonDestroySuccess'] = -1;
        }

        $reportData = $battleHandler->GenerateReport($combatResult, $reportInfo, REAL_FIGHT, $setup->fleetAttack, $setup->fleetDefend, $battleHandler->engine->getName());

        $battleHandler->GenerateWreckField($targetPlanetID, $combatResult, $targetUser['id'], $this->_fleet['universe']);

        $this->storeReport($setup, $battleHandler, $reportData, $reportID);
        $topkb = $this->sendReportMessages($setup, $combatResult, $reportID, $messageHTML, $class, $stealResource, $debris, $units);
        $this->writeDebris($planetDebris);

        if ($reportInfo['moonDestroySuccess'] == 1) {
            $this->playerUtil()->deletePlanet($targetPlanet['id']);
            $this->statsHandler->increment($targetUser['id'], AdvancedStatsEnum::MOONS_LOST, 1);
            $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::MOONS_DESTROYED, 1);
        } else {
            $this->takeLoot($stealResource);
        }

        if ($topkb) {
            $this->insertTopKb($combatResult, $reportID, $units);
        }

        $this->setState(FLEET_RETURN);
        $this->SaveFleet();
    }

    /**
     * Rolls the moon and fleet destruction after an attacker win. Moon destruction chance = (100 - sqrt(diameter)) *
     * sqrt(RIPs of all surviving attacking fleets), clamped to 0..100; on success the fleets stationed at or flying to the
     * moon are moved to the parent planet. The attacking fleet is lost with a chance of sqrt(diameter) / 2 percent.
     *
     * @param array<string, mixed> $targetPlanet the moon
     * @param array<string, mixed> $reportInfo   report data; the moonDestroy and fleetDestroy entries are filled in (modified)
     */
    private function rollDestruction(BattleSetup $setup, array $targetPlanet, array &$reportInfo): void
    {
        $db = $this->db;
        $fleetAttack = $setup->fleetAttack;

        // Moon destruction uses the RIPs of all surviving fleets of the attacking group (ACS);
        // $leadRips is only the lead fleet's own share (for its lost-RIP statistic).
        $rips = 0;
        foreach ($fleetAttack as $attackFleet) {
            $rips += $attackFleet['unit'][SHIP_RIP] ?? 0;
            $rips += $attackFleet['unit'][VOID_TITAN] ?? 0;
        }
        $leadUnits = $fleetAttack[$this->_fleet['id']]['unit'] ?? [];
        $leadRips = ($leadUnits[SHIP_RIP] ?? 0) + ($leadUnits[VOID_TITAN] ?? 0);

        $moonDestroyChance = round((100 - sqrt($targetPlanet['diameter'])) * sqrt($rips), 1);

        // Max 100% | Min 0%
        $moonDestroyChance = min($moonDestroyChance, 100);
        $moonDestroyChance = max($moonDestroyChance, 0);

        $randChance = random_int(1, 100);
        if ($randChance <= $moonDestroyChance) {
            $sql = 'SELECT id FROM %%PLANETS%% WHERE id_luna = :moonId;';
            $planetID = $db->selectSingle($sql, [
                ':moonId'   => $targetPlanet['id'],
            ], 'id');


            $sql = 'UPDATE %%FLEETS%% SET
                `start_type` = :startType,
                `start_id` = :planetId
                WHERE `start_id` = :moonId;';

            $db->update($sql, [
                ':startType' => TYPE_PLANET,
                ':planetId' => $planetID,
                ':moonId'   => $targetPlanet['id'],
            ]);

            $sql = 'UPDATE %%FLEETS%% SET
                `end_type` = :endType,
                `end_id` = :planetId,
                `mission` = IF(`mission` = :dest, :att, `mission`)
                WHERE `end_id` = :moonId
                AND `id` != :fleetId;';

            $db->update($sql, [
                ':endType'  => TYPE_PLANET,
                ':planetId' => $planetID,
                ':moonId'   => $targetPlanet['id'],
                ':fleetId'  => $this->_fleet['id'],
                ':dest'     => MISSION_DESTRUCTION,
                ':att'      => MISSION_ATTACK,
            ]);

            $sql = "UPDATE %%AKS%% SET `target` = :planetId WHERE `target` = :moonId;";
            $db->update($sql, [
                ':planetId' => $planetID,
                ':moonId'   => $targetPlanet['id'],
            ]);

            $reportInfo['moonDestroySuccess'] = 1;
        } else {
            $reportInfo['moonDestroySuccess'] = 0;
        }

        $fleetDestroyChance = round(sqrt($targetPlanet['diameter']) / 2);

        $randChance = random_int(1, 100);
        if ($randChance <= $fleetDestroyChance) {
            if (isset($fleetAttack[$this->_fleet['id']])) {
                $this->statsHandler->updateLostAdvancedStats($this->_fleet['owner'], $leadUnits);
                $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::DESTROY_MOON_RIPS_LOST, $leadRips);
            }
            $this->KillFleet();
            $reportInfo['fleetDestroySuccess'] = true;
        } else {
            $reportInfo['fleetDestroySuccess'] = false;
        }

        $reportInfo['moonDestroyChance'] = $moonDestroyChance;
        $reportInfo['fleetDestroyChance'] = $fleetDestroyChance;
    }
}
