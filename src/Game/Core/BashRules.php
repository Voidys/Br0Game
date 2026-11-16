<?php

namespace App\Game\Core;

/**
 * Bash protection: decides whether the current user already attacked a target user/planet too often within
 * BASH_TIME (config bash_per_user / bash_per_planet), counting solo attacks and distinct ACS groups.
 *
 * Collaborators: Database (LOG_FLEETS, USERS, PLANETS, DIPLO), Singleton (current user, request time), FleetString.
 * Rules: inactive targets and targets in a declared, accepted war older than a day are never bash-protected;
 * single espionage probe fleets (ship 210) do not count as attacks.
 */
class BashRules
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Whether attacking planet $Target with the given ACS group (0 = none) would exceed the bash limits of the
     * current user. Reads the current user from the game state.
     */
    public function checkBash(int $Target, int $fleetGroup = 0): bool
    {
        $USER = $this->state->USER;
        $config = $this->configs->get();
        $bashPerPlanet  = $config->bash_per_planet;
        $bashPerUser    = $config->bash_per_user;

        if ($bashPerPlanet == 0 && $bashPerUser == 0) {
            return false;
        }

        $PlanetOwner = $this->db->selectSingle('SELECT id_owner FROM %%PLANETS%% where id = :id', [
            ':id' => $Target,
        ], 'id_owner');

        $targetAllianceId = $this->db->selectSingle('SELECT ally_id FROM %%USERS%% where id = :id', [
            ':id' => $PlanetOwner,
        ], 'ally_id');

        $targetOnlineTime = $this->db->selectSingle('SELECT onlinetime FROM %%USERS%% where id = :id', [
            ':id' => $PlanetOwner,
        ], 'onlinetime');

        if ($targetOnlineTime < $this->state->runtime->timestamp - INACTIVE) {
            return false;
        }

        $sql = 'SELECT accept, request_time from %%DIPLO%%
        where ((owner_1 = :allyA and owner_2 = :allyD)
        or (owner_1 = :allyD and owner_2 = :allyA))
        and level = 5';

        $War = $this->db->selectSingle($sql, [
            ':allyA' => $USER['ally_id'],
            ':allyD' => $targetAllianceId,
        ]);

        if (!empty($War) && ($War['accept'] == 1 && $War['request_time'] < $this->state->runtime->timestamp - ONE_DAY)) {
            return false;
        }

        $isUserBash = false;
        if ($bashPerUser != 0) {
            $userCounts   = $this->userBashCheck($USER, $PlanetOwner, $fleetGroup);
            $isUserBash   = ($userCounts['CountSoloAttacksOnUser'] + $userCounts['CountAcsAttacksOnUser']) >= $bashPerUser;
        }

        $isPlanetBash = false;
        if ($bashPerPlanet != 0) {
            $planetCounts   = $this->planetBashCheck($USER, $Target, $fleetGroup);
            $isPlanetBash   = ($planetCounts['CountSoloAttacksOnPlanet'] + $planetCounts['CountAcsAttacksOnPlanet']) >= $bashPerPlanet;
        }

        return  ($isUserBash || $isPlanetBash);
    }

    /**
     * Attack counts of $USER on a target user within BASH_TIME: solo flights and distinct other ACS groups.
     *
     * @return array{CountSoloAttacksOnUser: int, CountAcsAttacksOnUser: int}
     */
    private function userBashCheck(array $USER, int $targetUser, int $fleetGroup): array
    {
        $sql = 'SELECT ships
            FROM %%LOG_FLEETS%%
            WHERE `owner` = :fleetOwner
            AND `target_owner` = :fleetEndOwner
            AND `arrival_time` > :arrivalTime
            AND `mission` IN (:att,:aks,:dest)
            AND `group` = 0
            AND `hasCanceled` = 0;';

        $log_fleets = $this->db->select($sql, [
            ':fleetOwner' => $USER['id'],
            ':fleetEndOwner' => $targetUser,
            ':arrivalTime' => ($this->state->runtime->timestamp - BASH_TIME),
            ':att' => MISSION_ATTACK,
            ':aks' => MISSION_ACS,
            ':dest' => MISSION_DESTRUCTION,
        ]);

        $CountSoloAttacksOnUser = 0;
        foreach ($log_fleets as $log_fleet) {
            $fleetArray = (new FleetString())->unserialize($log_fleet['ships']);
            if (count($fleetArray) != 1 || !array_key_exists(210, $fleetArray)) {
                $CountSoloAttacksOnUser++;
            }
        }

        $sql = 'SELECT COUNT(DISTINCT `group`) as amount
		FROM %%LOG_FLEETS%%
		WHERE `owner` = :fleetOwner
		AND `target_owner` = :fleetEndOwner
		AND `arrival_time` > :arrivalTime
		AND `mission` IN (:att,:aks,:dest)
        AND `group` != :fleetGroup
        AND `group` != 0
        AND `hasCanceled` = 0;';

        $CountAcsAttacksOnUser = $this->db->selectSingle($sql, [
            ':fleetOwner' => $USER['id'],
            ':fleetEndOwner' => $targetUser,
            ':arrivalTime' => ($this->state->runtime->timestamp - BASH_TIME),
            ':att' => MISSION_ATTACK,
            ':aks' => MISSION_ACS,
            ':dest' => MISSION_DESTRUCTION,
            ':fleetGroup' => $fleetGroup,
        ], 'amount');
        return [
            'CountSoloAttacksOnUser' => $CountSoloAttacksOnUser,
            'CountAcsAttacksOnUser'  => $CountAcsAttacksOnUser,
        ];
    }

    /**
     * Attack counts of $USER on a target planet within BASH_TIME: solo flights and distinct other ACS groups.
     *
     * @return array{CountSoloAttacksOnPlanet: int, CountAcsAttacksOnPlanet: int}
     */
    private function planetBashCheck(array $USER, int $Target, int $fleetGroup): array
    {
        $sql = 'SELECT ships
		FROM %%LOG_FLEETS%%
		WHERE `owner` = :fleetOwner
		AND `end_id` = :fleetEndId
		AND `arrival_time` > :arrivalTime
		AND `mission` IN (:att,:aks,:dest)
        AND `group` = 0
        AND `hasCanceled` = 0;';

        $log_fleets = $this->db->select($sql, [
            ':fleetOwner' => $USER['id'],
            ':fleetEndId' => $Target,
            ':arrivalTime' => ($this->state->runtime->timestamp - BASH_TIME),
            ':att' => MISSION_ATTACK,
            ':aks' => MISSION_ACS,
            ':dest' => MISSION_DESTRUCTION,
        ]);

        $CountSoloAttacksOnPlanet = 0;
        foreach ($log_fleets as $log_fleet) {
            $fleetArray = (new FleetString())->unserialize($log_fleet['ships']);
            if (count($fleetArray) != 1 || !array_key_exists(210, $fleetArray)) {
                $CountSoloAttacksOnPlanet++;
            }
        }

        $sql = 'SELECT COUNT(DISTINCT `group`) as amount
		FROM %%LOG_FLEETS%%
		WHERE `owner` = :fleetOwner
		AND `end_id` = :fleetEndId
		AND `arrival_time` > :arrivalTime
		AND `mission` IN (:att,:aks,:dest)
        AND `group` != :fleetGroup
        AND `group` != 0
        AND `hasCanceled` = 0;';

        $CountAcsAttacksOnPlanet = $this->db->selectSingle($sql, [
            ':fleetOwner' => $USER['id'],
            ':fleetEndId' => $Target,
            ':arrivalTime' => ($this->state->runtime->timestamp - BASH_TIME),
            ':att' => MISSION_ATTACK,
            ':aks' => MISSION_ACS,
            ':dest' => MISSION_DESTRUCTION,
            ':fleetGroup' => $fleetGroup,
        ], 'amount');
        return [
            'CountSoloAttacksOnPlanet' => $CountSoloAttacksOnPlanet,
            'CountAcsAttacksOnPlanet'  => $CountAcsAttacksOnPlanet,
        ];
    }
}
