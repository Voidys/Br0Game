<?php

/**
 * pr0game powered by steemnova
 * achievements
 * (c) 2022 reflexrecon
 */

namespace App\Game\Achievements;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\BattleEngine\BattleResult;
use App\Game\Core\Achievement;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * Achievement rules triggered by fights (checkBattleAchievements() for real fights, checkExpoBattleAchievements()
 * for expedition fights); called from BattleHandler::GenerateReport(). Each checkBattleAchievementN() unlocks
 * achievement N (see the achievements table) when the fight meets its condition.
 */
class BattleAchievement
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Check for achievements related to pvp battles
     *
     * @param array			$reportInfo       	The report array
     * @param BattleResult		$combatResult       The combat result
     * @param array			$DATA       		The report data array
     */
    public function checkBattleAchievements($reportInfo, BattleResult $combatResult, $DATA)
    {
        $db = $this->db;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $mainAttacker = $reportInfo['thisFleet']['owner'];
        $planetDefender = $combatResult->startDefenders()[0]->userId();
        $this->checkBattleAchievement6($reportInfo, $combatResult);
        $this->checkBattleAchievement39($reportInfo, $combatResult, $mainAttacker);
        $this->checkBattleAchievement10($reportInfo, $mainAttacker);
        $this->checkBattleAchievement34($planetDefender);
        $this->checkBattleAchievement47($DATA, $mainAttacker, $statsHandler);
        $this->checkBattleAchievement48($DATA, $mainAttacker, $statsHandler, $reportInfo);
        $this->checkBattleAchievement37($planetDefender, $combatResult);
        $this->checkBattleAchievement35($mainAttacker, $combatResult, $db, $planetDefender);
        $this->checkBattleAchievement38($mainAttacker, $combatResult, $db, $planetDefender);
        $this->checkBattleAchievement32($mainAttacker, $combatResult, $db, $statsHandler);
        $this->checkBattleAchievement3($combatResult, $planetDefender, $db);
        $this->checkBattleAchievement45($combatResult, $db, $planetDefender);
        $this->checkBattleAchievement43($combatResult, $planetDefender, $db, $DATA);
    }

    /**
     * Check for achievements related to expedition battles
     *
     * @param array			$reportInfo       	The report array
     * @param BattleResult		$combatResult       The combat result
     */
    public function checkExpoBattleAchievements($reportInfo, BattleResult $combatResult)
    {
        $mainAttacker = $reportInfo['thisFleet']['owner'];
        $this->checkBattleAchievement7($reportInfo, $combatResult, $mainAttacker);
    }

    /**
     * Check for achievement 6
     * Low performer: Have an attack come back with exqactly 1 metal, 1 crystal and 1 deuterium
     *
     * @param array			$reportInfo       	The report array
     * @param BattleResult		$combatResult       The combat result
     */
    public function checkBattleAchievement6($reportInfo, BattleResult $combatResult)
    {
        if ($reportInfo['stealResource'][RESOURCE_METAL] == 1 && $reportInfo['stealResource'][RESOURCE_CRYSTAL] == 1 && $reportInfo['stealResource'][RESOURCE_CRYSTAL] == 1) {
            foreach ($combatResult->startAttackers() as $fleet) {
                if (!$this->achievement()->checkAchievement($fleet->userId(), 6)) {
                    $this->achievement()->setAchievement($fleet->userId(), 6);
                }
            }
        }
    }

    /**
     * Check for achievement 39
     * The meaning of life: Attack someone with 42 ships
     *
     * @param array			$reportInfo       	The report array
     * @param BattleResult		$combatResult       The combat result
     * @param int	 		$mainAttacker       The id of the User
     */
    public function checkBattleAchievement39($reportInfo, BattleResult $combatResult, $mainAttacker)
    {
        if ($reportInfo['thisFleet']['ship_amount'] == 42 && !$this->achievement()->checkAchievement($mainAttacker, 39)
            && count($combatResult->startAttackers()) == 1) {
            $this->achievement()->setAchievement($mainAttacker, 39);
        }
    }

    /**
     * Check for achievement 10
     * Marble game: Destroy one moon
     *
     * @param array			$reportInfo       	The report array
     * @param int	 		$mainAttacker       The id of the User
     */
    public function checkBattleAchievement10($reportInfo, $mainAttacker)
    {
        if ($reportInfo['moonDestroySuccess'] == 1 && !$this->achievement()->checkAchievement($mainAttacker, 10)) {
            $this->achievement()->setAchievement($mainAttacker, 10);
        }
    }

    /**
     * Check for achievement 34
     * Noble farm: Get attacked once
     *
     * @param int	 		$planetDefender     The id of the User
     */
    public function checkBattleAchievement34($planetDefender)
    {
        if (!$this->achievement()->checkAchievement($planetDefender, 34)) {
            $this->achievement()->setAchievement($planetDefender, 34);
        }
    }

    /**
     * Check for achievement 47
     * Jack the Ripper: Destroy 5 moons
    *
    * @param array						$DATA       		The report data array
    * @param int	 					$mainAttacker       The id of the User
    * @param AdvancedStatsHandler	 	$statsHandler		The stats handler object
     */
    public function checkBattleAchievement47($DATA, $mainAttacker, AdvancedStatsHandler $statsHandler)
    {
        if ($DATA['moon']['moonDestroySuccess'] == 1) {
            $count = $statsHandler->getValue($mainAttacker, AdvancedStatsEnum::MOONS_DESTROYED);
            if (!$this->achievement()->checkAchievement($mainAttacker, 47) && $count >= 5) {
                $this->achievement()->setAchievement($mainAttacker, 47);
            }
        }
    }

    /**
     * Check for achievement 48
     * Rip the Jacker: Lose 5 rips while trying to destroy moons
     *
     * @param array						$DATA       		The report data array
     * @param int	 					$mainAttacker       The id of the User
     * @param AdvancedStatsHandler	 	$statsHandler       The stats handler object
     * @param array						$reportInfo       	The report array
     */
    public function checkBattleAchievement48($DATA, $mainAttacker, AdvancedStatsHandler $statsHandler, $reportInfo)
    {
        if ($DATA['moon']['fleetDestroySuccess'] == 1) {
            $shiptypes = explode(";", $reportInfo['thisFleet']["ships"]);
            foreach ($shiptypes as $shiptype) {
                $ship = explode(",", $shiptype);
                if ($ship[0] == SHIP_RIP || $ship[0] == VOID_TITAN) {
                    $count = $statsHandler->getValue($mainAttacker, AdvancedStatsEnum::DESTROY_MOON_RIPS_LOST);

                    if (!$this->achievement()->checkAchievement($mainAttacker, 48) && $count >= 5) {
                        $this->achievement()->setAchievement($mainAttacker, 48);
                    }
                }
            }
        }
    }

    /**
     * Check for achievement 37
     * I am not one of those: Get attacked by a 5 man ACS and lose
     *
     * @param BattleResult		$combatResult       The combat result
     * @param int	 		$planetDefender     The id of the User
     */
    public function checkBattleAchievement37($planetDefender, BattleResult $combatResult)
    {
        if (!$this->achievement()->checkAchievement($planetDefender, 37) && $combatResult->won == ATTACKER) {
            $countAttacker = 0;
            $attackers = [];
            foreach ($combatResult->startAttackers() as $fleet) {
                if (!in_array($fleet->userId(), $attackers)) {
                    $countAttacker++;
                    array_push($attackers, $fleet->userId());
                }
            }
            //max aks participants var?
            if ($countAttacker == 5) {
                $this->achievement()->setAchievement($planetDefender, 37);
            }
        }
    }

    /**
     * Check for achievement 35
     * That's not nice!: Attack an active player with less than 10% of your points
     *
     * @param BattleResult		$combatResult       The combat result
     * @param int	 		$mainAttacker     	The id of the User
     * @param Database	 	$db       			The database object
     * @param int	 		$planetDefender     The id of the User
     */
    public function checkBattleAchievement35($mainAttacker, BattleResult $combatResult, $db, $planetDefender)
    {
        if (!$this->achievement()->checkAchievement($mainAttacker, 35) && $combatResult->won == ATTACKER && count($combatResult->startAttackers()) == 1) {
            $sql = "SELECT total_points FROM %%STATPOINTS%% a WHERE id_owner = :attacker AND stat_type = 1;";
            $attackerPoints = $db->selectSingle($sql, [
                ':attacker' => $mainAttacker,
            ], 'total_points');

            $sql = "SELECT total_points FROM %%STATPOINTS%% a WHERE id_owner = :defender AND stat_type = 1;";
            $defenderPoints = $db->selectSingle($sql, [
                ':defender' => $planetDefender,
            ], 'total_points');

            $sql = "SELECT onlinetime FROM %%USERS%% WHERE id = :defender;";
            $defenderOnlinetime = $db->selectSingle($sql, [
                ':defender' => $planetDefender,
            ], 'onlinetime');

            if ($attackerPoints / 10 > $defenderPoints && $defenderOnlinetime > $this->state->runtime->timestamp - INACTIVE) {
                $this->achievement()->setAchievement($mainAttacker, 35);
            }
        }
    }

    /**
     * Check for achievement 38
     * Cornag and Offz: Attack a player with the same alliance
     *
     * @param BattleResult		$combatResult       The combat result
     * @param int	 		$mainAttacker     	The id of the User
     * @param Database	 	$db       			The database object
     * @param int	 		$planetDefender     The id of the User
     */
    public function checkBattleAchievement38($mainAttacker, BattleResult $combatResult, $db, $planetDefender)
    {
        if (!$this->achievement()->checkAchievement($mainAttacker, 38) && count($combatResult->startAttackers()) == 1) {
            $sql = "SELECT ally_id FROM %%USERS%% WHERE id = :attacker;";
            $attackerAlly = $db->selectSingle($sql, [
                ':attacker' => $mainAttacker,
            ], 'ally_id');
            $sql = "SELECT ally_id FROM %%USERS%% WHERE id = :defender;";
            $defenderAlly = $db->selectSingle($sql, [
                ':defender' => $planetDefender,
            ], 'ally_id');
            if ($attackerAlly != 0 && $attackerAlly == $defenderAlly) {
                $this->achievement()->setAchievement($mainAttacker, 38);
            }
        }
    }

    /**
     * Check for achievement 32
     * Privateer: Win 1000 battles with the SetSail theme active
     *
     * @param array						$combatResult       The combat result array
     * @param int	 					$mainAttacker     	The id of the User
     * @param Database	 				$db					The database object
     * @param AdvancedStatsHandler	 	$statsHandler       The stats handler object
     */
    public function checkBattleAchievement32($mainAttacker, BattleResult $combatResult, $db, AdvancedStatsHandler $statsHandler)
    {
        if (($combatResult->won == ATTACKER)) {
            $count = $statsHandler->getValue($mainAttacker, AdvancedStatsEnum::SET_SAIL_WINS);

            if (!$this->achievement()->checkAchievement($mainAttacker, 32) && $count >= 1000) {
                $this->achievement()->setAchievement($mainAttacker, 32);
            }
        }
    }

    /**
     * Check for achievement 3
     * Deserved: Lose atleast 1000 fleet points and atleast 50% of your fleet while your language is set to french
     *
     * @param BattleResult		$combatResult       The combat result
     * @param int	 		$planetDefender     The id of the User
     * @param Database	 	$db       			The database object
     */
    public function checkBattleAchievement3(BattleResult $combatResult, $planetDefender, $db)
    {
        if (($combatResult->won == ATTACKER && !$this->achievement()->checkAchievement($planetDefender, 3))) {
            //check for loose condition mindestens 1k fleet punkte und mindestens 50%der flotte
            $sql = "SELECT fleet_points FROM %%STATPOINTS%% WHERE id_owner = :id AND stat_type = 1;";
            $fleet_points = $db->selectSingle($sql, [
                ':id' => $planetDefender,
            ], 'fleet_points');
            $reslist = $this->state->reslist;
            $pricelist = $this->state->pricelist;
            $lostFleetPoints = 0;

            foreach ($reslist['fleet'] as $Fleet) {

                if ($combatResult->startDefenders()[0]->units == 0) {
                    continue;
                }

                $Units = $pricelist[$Fleet]['cost'][901] + $pricelist[$Fleet]['cost'][902];

                $lostFleetPoints += $Units / 1000 ;
            }
            $sql = "SELECT lang FROM %%USERS_SETTINGS%% WHERE user_id = :id;";
            $lang = $db->selectSingle($sql, [
                ':id' => $planetDefender,
            ], 'lang');
            if ($lostFleetPoints > 1000 && $lostFleetPoints > $fleet_points / 2 && $lang == "fr") {
                $this->achievement()->setAchievement($planetDefender, 3);
            }
        }
    }

    /**
     * Check for achievement 45
     * Locksmith: Win agaisnt a top 5 defense rank player
     *
     * @param int	 		$mainAttacker       The id of the User
     * @param BattleResult		$combatResult       The combat result
     * @param Database	 	$db       			The database object
     * @param int	 		$planetDefender     The id of the User
     */
    public function checkBattleAchievement45(BattleResult $combatResult, $db, $planetDefender)
    {
        $sql = "SELECT defs_rank FROM %%STATPOINTS%% WHERE id_owner = :id AND stat_type = 1;";
        $defs_rank = $db->selectSingle($sql, [
            ':id' => $planetDefender,
        ], 'defs_rank');
        if ($defs_rank <= 5 && $combatResult->won == ATTACKER) {
            $countAttacker = 0;
            $attackers = [];
            foreach ($combatResult->startAttackers() as $fleet) {
                if (!in_array($fleet->userId(), $attackers) && !$this->achievement()->checkAchievement($fleet->userId(), 45)) {
                    $countAttacker++;
                    array_push($attackers, $fleet->userId());
                }
            }
            foreach ($attackers as $attacker) {
                $this->achievement()->setAchievement($attacker, 45);
            }
        }
    }

    /**
     * Check for achievement 43
     * With my Eyes closed: Win as a definder while your account is flagged as inactive, with a fleet that was away for 7 days
     *
     * @param BattleResult		$combatResult       The combat result
     * @param int	 		$planetDefender     The id of the User
     * @param Database	 	$db       			The database object
     * @param array			$DATA       		The report data array
     */
    public function checkBattleAchievement43(BattleResult $combatResult, $planetDefender, $db, $DATA)
    {
        if ($combatResult->won == DEFENDER) {
            $sql = "SELECT onlinetime FROM %%USERS%% WHERE id = :defender;";
            $onlinetime = $db->selectSingle($sql, [
                ':defender' => $planetDefender,
            ], 'onlinetime');
            if ($onlinetime < $this->state->runtime->timestamp - INACTIVE) {
                $sql = "SELECT count(*) as count FROM %%LOG_FLEETS%% WHERE owner = :defender AND return_time - start_time >= :inactive AND return_time < :timestamp AND
					start_galaxy = :start_galaxy AND start_system = :start_system AND start_planet = :start_planet AND start_type = :start_type;";
                $count = $db->selectSingle($sql, [
                    ':defender' => $planetDefender,
                    ':inactive' => INACTIVE,
                    ':timestamp' => $this->state->runtime->timestamp,
                    ':start_galaxy' => $DATA['koords'][0],
                    ':start_system' => $DATA['koords'][1],
                    ':start_planet' => $DATA['koords'][2],
                    ':start_type' => $DATA['koords'][3],
                ], 'count');

                if ($count > 0 && !$this->achievement()->checkAchievement($planetDefender, 43)) {
                    $this->achievement()->setAchievement($planetDefender, 43);
                }
            }
        }
    }

    /**
     * Check for achievement 7
     * French revolution: Win against pirates or aliens with atleast 10.000 ships
     *
     * @param array			$reportInfo       	The report array
     * @param BattleResult		$combatResult       The combat result
     * @param int	 		$mainAttacker       The id of the User
     */
    public function checkBattleAchievement7($reportInfo, BattleResult $combatResult, $mainAttacker)
    {
        if ($reportInfo['thisFleet']['ship_amount'] >= 10000 && !$this->achievement()->checkAchievement($mainAttacker, 7)
            && $combatResult->won == ATTACKER) {
            $this->achievement()->setAchievement($mainAttacker, 7);
        }
    }
}
