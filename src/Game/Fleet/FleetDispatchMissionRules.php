<?php

namespace App\Game\Fleet;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use Exception;

/**
 * The mission rules of FleetDispatch (is the mission allowed against this target, protection rules, hold limits); works on
 * the dispatch state of the host class and refuses through its refuse() method.
 */
trait FleetDispatchMissionRules
{
    /** Checks the mission is allowed against this target: available missions, vacation, bash rule, admin attack, noob protection (or outlaw status), buddy/alliance for hold and transfer, hold limits. */
    private function checkMission(): void
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        $db = $this->db;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $MisInfo = [];
        $MisInfo['galaxy'] = $this->targetGalaxy;
        $MisInfo['system'] = $this->targetSystem;
        $MisInfo['planet'] = $this->targetPlanet;
        $MisInfo['planettype'] = $this->targetType;
        $MisInfo['IsAKS'] = $this->fleetGroup;
        $MisInfo['Ship'] = $this->fleetArray;

        $this->availableMissions = $this->fleetFunctions()->getFleetMissions($USER, $MisInfo, $this->targetPlanetData);

        if (!in_array($this->targetMission, $this->availableMissions['MissionSelector'])) {
            $this->refuse($LNG['fl_invalid_mission'], 'game?page=fleetStep2');
        }

        if ($this->targetMission != MISSION_RECYCLING && IsVacationMode($this->targetPlayerData)) {
            $this->refuse($LNG['fl_target_exists'], 'game?page=fleetStep1');
        }

        if ($this->targetMission == MISSION_ATTACK || $this->targetMission == MISSION_ACS || $this->targetMission == MISSION_DESTRUCTION) {
            if (count($this->fleetArray) != 1 || !array_key_exists(210, $this->fleetArray)) {
                if ($this->fleetFunctions()->checkBash($this->targetPlanetData['id'], $this->fleetGroup)) {
                    $this->refuse($LNG['fl_bash_protection'], 'game?page=fleetTable');
                }
            }
        }
        $this->checkHostileRules();
        $this->checkHoldRules();
    }

    /** Hold and transfer need the same alliance or a buddy; hold is limited by the number of fleets and players already holding at the target. */
    private function checkHoldRules(): void
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        $db = $this->db;
        if ($this->targetMission == MISSION_HOLD || $this->targetMission == MISSION_TRANSFER) {
            if ($this->targetPlayerData['ally_id'] != $USER['ally_id'] || $USER['ally_id'] == 0) {
                $sql = "SELECT COUNT(*) as state FROM %%BUDDY%%
                    WHERE `id` NOT IN (SELECT `id` FROM %%BUDDY_REQUEST%% WHERE %%BUDDY_REQUEST%%.`id` = %%BUDDY%%.`id`) 
                      AND ((`owner` = :ownerID AND `sender` = :userID) OR (`owner` = :userID AND `sender` = :ownerID));";
                $buddy = $db->selectSingle($sql, [
                    ':ownerID'  => $this->targetPlayerData['id'],
                    ':userID'   => $USER['id'],
                ], 'state');

                if ($buddy == 0) {
                    $this->refuse($LNG['fl_no_same_alliance'], 'game?page=fleetTable');
                }
            }
        }

        if ($this->targetMission == MISSION_HOLD) {
            $sql = "SELECT COUNT(id) AS anz
                FROM %%FLEETS%%
                WHERE `end_id` = :planetID AND `mission` = :mission AND `status` IN (:stateOutward, :stateHold);";
            $fleetCount = $db->selectSingle($sql, [
                ':planetID' => $this->targetPlanetData['id'],
                ':mission'  => MISSION_HOLD,
                ':stateOutward' => FLEET_OUTWARD,
                ':stateHold' => FLEET_HOLD,
            ], "anz");

            $sql = "SELECT COUNT(DISTINCT `owner`) AS anz FROM %%FLEETS%%
                WHERE `end_id` = :planetID AND `mission` = :mission AND `status` IN (:stateOutward, :stateHold)
                  AND `owner` != :userID;";
            $playerCount = $db->selectSingle($sql, [
                ':planetID' => $this->targetPlanetData['id'],
                ':mission'  => MISSION_HOLD,
                ':stateOutward' => FLEET_OUTWARD,
                ':stateHold' => FLEET_HOLD,
                ':userID' => $USER['id'],
            ], "anz");

            if (($fleetCount + 1) >= $this->config->max_fleets_per_acs) {
                $this->refuse($LNG['fl_hold_max_fleets'], 'game?page=fleetTable');
            } elseif (($playerCount + 1) >= $this->config->max_participants_per_acs) {
                $this->refuse($LNG['fl_hold_max_user'], 'game?page=fleetTable');
            }
        }
    }

    /** Hostile missions (attack, ACS, hold, spy, destruction): admin protection, noob protection and the outlaw status for attacking stronger players. */
    private function checkHostileRules(): void
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        $db = $this->db;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        if (
            $this->targetMission == MISSION_ATTACK || $this->targetMission == MISSION_ACS || $this->targetMission == MISSION_HOLD || $this->targetMission == MISSION_SPY
            || $this->targetMission == MISSION_DESTRUCTION
        ) {
            if ($this->configs->get()->adm_attack == 1 && $this->targetPlayerData['authattack'] > $USER['authlevel']) {
                $this->refuse($LNG['fl_admin_attack'], 'game?page=fleetTable');
            }

            $sql = 'SELECT `total_points`
                FROM %%STATPOINTS%%
                WHERE `id_owner` = :userId AND `stat_type` = :statType';

            try {
                $USER += $this->db->selectSingle($sql, [
                    ':userId' => $USER['id'],
                    ':statType' => 1,
                ]) ?: ['total_points' => 0];
            } catch (Exception $exception) {
            }

            $IsNoobProtec = $this->playerUtil()->checkNoobProtectionFor($this->models()->user($USER), $this->models()->user($this->targetPlayerData));

            if ($IsNoobProtec['NoobPlayer']) {
                $this->refuse($LNG['fl_player_is_noob'], 'game?page=fleetTable');
            }

            if ($IsNoobProtec['StrongPlayer']) {
                if ($this->configs->get()->noob_protection_outlaw) {
                    $db->update('UPDATE %%USERS%% SET outlaw_time = :outlawTime WHERE id = :userId;', [
                        ':outlawTime' => $this->state->runtime->timestamp,
                        ':userId'     => $USER['id'],
                    ]);
                    $statsHandler->increment($USER['id'], AdvancedStatsEnum::OUTLAW_ACTIVATIONS);
                } else {
                    $this->refuse($LNG['fl_player_is_strong'], 'game?page=fleetTable');
                }
            }
        }

    }
}
