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
        $LNG = $this->state->LNG;
        $db = $this->db;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $MisInfo = [];
        $MisInfo['galaxy'] = $this->targetGalaxy;
        $MisInfo['system'] = $this->targetSystem;
        $MisInfo['planet'] = $this->targetPlanet;
        $MisInfo['planettype'] = $this->targetType;
        $MisInfo['IsAKS'] = $this->fleetGroup;
        $MisInfo['Ship'] = $this->fleetArray;

        $this->availableMissions = $this->fleetFunctions()->getFleetMissions($this->state->USER, $MisInfo, $this->targetPlanetData);

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
        $LNG = $this->state->LNG;
        $db = $this->db;
        if ($this->targetMission == MISSION_HOLD || $this->targetMission == MISSION_TRANSFER) {
            if ($this->targetPlayerData['ally_id'] != $this->state->USER['ally_id'] || $this->state->USER['ally_id'] == 0) {
                $sql = "SELECT COUNT(*) as state FROM %%BUDDY%%
                    WHERE `id` NOT IN (SELECT `id` FROM %%BUDDY_REQUEST%% WHERE %%BUDDY_REQUEST%%.`id` = %%BUDDY%%.`id`) 
                      AND ((`owner` = :ownerID AND `sender` = :userID) OR (`owner` = :userID AND `sender` = :ownerID));";
                $buddy = $db->selectSingle($sql, [
                    ':ownerID'  => $this->targetPlayerData['id'],
                    ':userID'   => $this->state->USER['id'],
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
                ':userID' => $this->state->USER['id'],
            ], "anz");

            if (($fleetCount + 1) >= $this->config->max_fleets_per_acs) {
                $this->refuse($LNG['fl_hold_max_fleets'], 'game?page=fleetTable');
            } elseif (($playerCount + 1) >= $this->config->max_participants_per_acs) {
                $this->refuse($LNG['fl_hold_max_user'], 'game?page=fleetTable');
            }
        }
    }

    /**
     * Anti-push guard (audit F-02, off by default): with the universe setting transport_push_check on and
     * transport_push_limit above 0, a transport or hold to another player whose cargo is worth more than the limit
     * (deuterium units at the current market ratios) is refused unless that player sent resources back to the sender
     * within the last 7 days.
     */
    private function checkPushGuard(): void
    {
        $config = $this->configs->get();
        $limit = (float) $config->transport_push_limit;
        if (
            !$config->transport_push_check || $limit <= 0
            || ($this->targetMission != MISSION_TRANSPORT && $this->targetMission != MISSION_HOLD)
            || ($this->targetPlayerData['id'] ?? 0) == $this->state->USER['id']
        ) {
            return;
        }

        $market = new \App\Game\Core\MarketManager($this->db, $this->configs, $this->state);
        $value = $market->convertToDeutNumeric([
            901 => $this->fleetResource[901],
            902 => $this->fleetResource[902],
            903 => $this->fleetResource[903],
        ]);
        if ($value <= $limit) {
            return;
        }

        $returned = (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS anz FROM %%LOG_FLEETS%%
            WHERE `owner` = :target AND `target_owner` = :sender AND `mission` IN (:transport, :hold)
              AND `start_time` > :since AND `hasCanceled` = 0
              AND (`resource_metal` + `resource_crystal` + `resource_deuterium`) > 0;',
            [
                ':target' => $this->targetPlayerData['id'],
                ':sender' => $this->state->USER['id'],
                ':transport' => MISSION_TRANSPORT,
                ':hold' => MISSION_HOLD,
                ':since' => $this->state->runtime->timestamp - 7 * ONE_DAY,
            ],
            'anz',
        );
        if ($returned == 0) {
            $this->refuse($this->state->LNG['fl_push_guard'], 'game?page=fleetTable');
        }
    }

    /** Hostile missions (attack, ACS, hold, spy, destruction): admin protection, noob protection and the outlaw status for attacking stronger players. */
    private function checkHostileRules(): void
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        if (
            $this->targetMission == MISSION_ATTACK || $this->targetMission == MISSION_ACS || $this->targetMission == MISSION_HOLD || $this->targetMission == MISSION_SPY
            || $this->targetMission == MISSION_DESTRUCTION
        ) {
            if ($this->configs->get()->adm_attack == 1 && $this->targetPlayerData['authattack'] > $this->state->USER['authlevel']) {
                $this->refuse($LNG['fl_admin_attack'], 'game?page=fleetTable');
            }

            $sql = 'SELECT `total_points`
                FROM %%STATPOINTS%%
                WHERE `id_owner` = :userId AND `stat_type` = :statType';

            try {
                $this->state->USER += $this->db->selectSingle($sql, [
                    ':userId' => $this->state->USER['id'],
                    ':statType' => 1,
                ]) ?: ['total_points' => 0];
            } catch (Exception $exception) {
            }

            $IsNoobProtec = $this->playerUtil()->checkNoobProtectionFor($this->models()->user($this->state->USER), $this->models()->user($this->targetPlayerData));

            if ($IsNoobProtec['NoobPlayer']) {
                $this->refuse($LNG['fl_player_is_noob'], 'game?page=fleetTable');
            }

            if ($IsNoobProtec['StrongPlayer']) {
                if ($this->configs->get()->noob_protection_outlaw) {
                    $db->update('UPDATE %%USERS%% SET outlaw_time = :outlawTime WHERE id = :userId;', [
                        ':outlawTime' => $this->state->runtime->timestamp,
                        ':userId'     => $this->state->USER['id'],
                    ]);
                    $statsHandler->increment($this->state->USER['id'], AdvancedStatsEnum::OUTLAW_ACTIVATIONS);
                } else {
                    $this->refuse($LNG['fl_player_is_strong'], 'game?page=fleetTable');
                }
            }
        }

    }
}
