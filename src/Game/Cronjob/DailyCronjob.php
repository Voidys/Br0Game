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

namespace App\Game\Cronjob;

use App\Game\Core\Config;
use App\Game\Cronjob\Cronjob;
use App\Game\Core\Database;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Universe;

class DailyCronjob extends AbstractCronjobTask
{
    public function run(): void
    {
        $this->clearCache();
        $this->reCalculateCronjobs();
        $this->clearEcoCache();
        $universes = $this->universe()->availableUniverses();
        foreach ($universes as $universe) {
            $this->cancelVacation($universe);
            $this->updateInactiveMines($universe);
            $this->emptyInactiveAllianceAndBuddy($universe);
        }
        $this->cleanReports($universes);
        $this->eraseIPAdresses();
        $this->cleanAccountsValid();
    }

    public function clearCache(): void
    {
        $this->maintenance()->clearCache();
    }

    public function reCalculateCronjobs(): void
    {
        $this->cronjobs()->reCalculateCronjobs();
    }

    public function clearEcoCache(): void
    {
        $sql	= "UPDATE %%PLANETS%% SET eco_hash = '';";
        $this->db->update($sql);
    }

    public function eraseIPAdresses(): void
    {
        $sql = "UPDATE %%MULTI%% SET multi_ip = 'cleared' WHERE lastActivity < :oneMonth;";
        $this->db->update($sql, [':oneMonth' => $this->state->runtime->timestamp - TIME_1_MONTH]);
    }

    public function cancelVacation(int $universe): void
    {
        if ($this->viewHelpers()->isModuleAvailable(MODULE_VMODE_KICK, $universe)) {
            $sql = "SELECT id, b_tech_planet, b_tech, b_tech_id, b_tech_queue, urlaubs_until, universe FROM %%USERS%%
                    WHERE urlaubs_modus = 1 AND onlinetime < :inactive AND bana = 0 AND universe = :universe;";
            $players = $this->db->select($sql, [
                ':inactive' => $this->state->runtime->timestamp - INACTIVE_LONG,
                ':universe' => $universe
            ]);
            $db = $this->db;
            foreach ($players as $player) {
                $db->startTransaction();
                $this->playerUtil()->disable_vmode($player);
                $db->commit();
                $this->playerUtil()->clearPlanets($player);
            }
        }
    }

    public function emptyInactiveAllianceAndBuddy(int $universe): void
    {
        if ($this->viewHelpers()->isModuleAvailable(MODULE_EMPTY_BUDDY, $universe)) {
            $db = $this->db;
            $sql = "SELECT u.id FROM %%USERS%% AS u WHERE urlaubs_modus = 0 AND onlinetime < :inactive AND universe = :universe;";
            $players = $db->select($sql, [
                ':inactive' => $this->state->runtime->timestamp - INACTIVE,
                ':universe' => $universe
            ]);
            foreach ($players as $player) {
                $sql = 'SELECT id, ally_id FROM %%USERS%% WHERE id = :userId;';
                $userData = $db->selectSingle($sql, [
                    ':userId'   => $player['id']
                ]);
                $this->playerUtil()->removeFromAlliance($userData);
                $this->playerUtil()->removeFromBuddy($player['id']);
            }
        }
    }

    public function updateInactiveMines(int $universe): void
    {
        $sql = "UPDATE %%PLANETS%% set metal_mine_porcent = :full, crystal_mine_porcent = :full, deuterium_sintetizer_porcent = :full, solar_plant_porcent = :full, fusion_plant_porcent = :full, solar_satelit_porcent = :full
				WHERE planet_type = :planet AND id_owner IN (SELECT u.id FROM %%USERS%% AS u WHERE urlaubs_modus = 0 AND onlinetime < :inactive AND universe = :universe);";

        $this->db->update($sql, [
            ':full' 	=> 10, // 10 = 100%
            ':planet'	=> 1,
            ':inactive' => $this->state->runtime->timestamp - INACTIVE,
            ':universe' => $universe
        ]);
    }

    /**
     * Deletes old battle reports depending on config settings and unit size for battle hall
     *
     * @param array $universes
     * @return void
     */
    private function cleanReports(array $universes): void
    {
        $db = $this->db;

        $config	= $this->configs->get(ROOT_UNI);
        $del_before = $this->state->runtime->timestamp - ($config->del_oldstuff * 86400); // Global setting: Delete messages/reports after X days

        foreach ($universes as $universe) {
            // Select lowest units to keep per universe
            $sql = "SELECT `units` FROM %%TOPKB%% WHERE `universe` = :universe AND `memorial` = 0 ORDER BY `units` DESC LIMIT 1 OFFSET :offset;";
            $units = $db->selectSingle($sql, [
                ':universe' => $universe,
                ':offset'  => TOPKB_LIMIT - 1,
            ], 'units');

            if (!empty($units) && $units > 0) {
                // Delete lower battlehall KBs
                $sql = "DELETE FROM %%TOPKB%% WHERE `universe` = :universe AND `units` < :units AND `time` < :oldStuff AND `memorial` = 0;";
                $db->delete($sql, [
                    ':universe' => $universe,
                    ':units' => $units,
                    ':oldStuff' => $del_before,
                ]);
            }
        }

        // Delete simulations/expofights older than 3 days and KBs older than config setting and not in Battlehall
        $sql = "DELETE FROM %%RW%% WHERE 
            `keep` = 0 AND (
                ( `defender` = '' AND `time` < :threeDays ) OR
                ( `defender` != '' AND `time` < :oldStuff AND `rid` NOT IN ( SELECT `rid` FROM %%TOPKB%% ) )
            );";
        $db->delete($sql, [
            ':threeDays' => $this->state->runtime->timestamp - TIME_72_HOURS, // Keep only last 3 days of sims/pirate/aliens
            ':oldStuff' => $del_before,
        ]);

        // Delete unused user_to_topkb
        $sql = "DELETE FROM %%TOPKB_USERS%% WHERE `rid` NOT IN ( SELECT `rid` FROM %%TOPKB%% );";
        $db->delete($sql);
    }

    /**
     * Deletes not validated Accounts after 3 days
     *
     * @return void
     */
    private function cleanAccountsValid(): void
    {
        $db = $this->db;

        $sql = "DELETE FROM %%ACCOUNTS_VALID%% WHERE date < :threeDays;";
        $db->delete($sql, [
            ':threeDays' => $this->state->runtime->timestamp - TIME_72_HOURS
        ]);
    }
}


/*
    Enable to debug
    includes/classes/Universe.class.php set var $currentUniverse to 1 instead of NULL
*/

// define('MODE', 'INSTALL');
// $this->configs->get(1);
// define('TIMESTAMP', time());
// $class = new DailyCronJob();
// $class->cancelVacation();
