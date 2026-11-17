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
use App\Game\Core\ResourceUpdate;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Universe;

/**
 * Nightly housekeeping (default 02:25 every day, cronjob "daily"): clears caches, recalculates all next run times,
 * resets the eco hash of all planets (forces a production recalculation), then per universe: kicks long-inactive
 * players out of vacation mode, resets mine/energy settings of inactive players to 100%, removes inactive players
 * from alliances and buddy lists; finally cleans old battle reports, erases old multi-account IPs, deletes old device links, deletes old vote log entries and deletes
 * unvalidated registrations.
 */
class DailyCronjob extends AbstractCronjobTask
{
    /**
     * Runs all steps in order; the per-universe steps only act when the matching module is enabled in that universe.
     */
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
        $this->purgeDevices();
        $this->purgeVoteLog();
        $this->cleanAccountsValid();
    }

    /**
     * Maintenance::clearCache(): deletes the cache files in var/game/ and compiled templates, recalculates the cronjob times,
     * resets the eco hash and refreshes the stored game version.
     */
    public function clearCache(): void
    {
        $this->maintenance()->clearCache();
    }

    /**
     * Recomputes nextTime of every cronjob (Cronjob::reCalculateCronjobs()).
     */
    public function reCalculateCronjobs(): void
    {
        $this->cronjobs()->reCalculateCronjobs();
    }

    /**
     * Empties `planets.eco_hash` so every planet recalculates its production the next time it is updated.
     */
    public function clearEcoCache(): void
    {
        $sql	= "UPDATE %%PLANETS%% SET eco_hash = '';";
        $this->db->update($sql);
    }

    /**
     * Privacy: deletes device links (hashed device ids per player) not seen for 90 days.
     */
    public function purgeDevices(): void
    {
        (new \App\Game\Core\DeviceLinks($this->db, ''))->purge($this->state->runtime->timestamp);
    }

    /**
     * Privacy: deletes vote log entries (with the player's IP) older than 90 days.
     */
    public function purgeVoteLog(): void
    {
        (new \App\Community\VoteSites($this->db))->purge($this->state->runtime->timestamp);
    }

    /**
     * Privacy: replaces `multi.multi_ip` with 'cleared' for entries whose last activity is older than one month.
     */
    public function eraseIPAdresses(): void
    {
        $sql = "UPDATE %%MULTI%% SET multi_ip = 'cleared' WHERE lastActivity < :oneMonth;";
        $this->db->update($sql, [':oneMonth' => $this->state->runtime->timestamp - TIME_1_MONTH]);
    }

    /**
     * Module MODULE_VMODE_KICK: players in vacation mode who were offline longer than INACTIVE_LONG (28 days),
     * not banned, leave vacation mode (queues restarted via disable_vmode) and their planets are cleaned up.
     * @param int $universe universe id.
     */
    public function cancelVacation(int $universe): void
    {
        if ($this->viewHelpers()->isModuleAvailable(MODULE_VMODE_KICK, $universe)) {
            $sql = "SELECT id, b_tech_planet, b_tech, b_tech_id, b_tech_queue, urlaubs_until, universe FROM %%USERS%%
                    WHERE urlaubs_modus = 1 AND onlinetime < :inactive AND bana = 0 AND universe = :universe;";
            $players = $this->db->select($sql, [
                ':inactive' => $this->state->runtime->timestamp - INACTIVE_LONG,
                ':universe' => $universe,
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

    /**
     * Module MODULE_EMPTY_BUDDY: players (not in vacation mode) offline longer than INACTIVE (7 days) are removed
     * from their alliance and from all buddy lists.
     */
    public function emptyInactiveAllianceAndBuddy(int $universe): void
    {
        if ($this->viewHelpers()->isModuleAvailable(MODULE_EMPTY_BUDDY, $universe)) {
            $db = $this->db;
            $sql = "SELECT u.id FROM %%USERS%% AS u WHERE urlaubs_modus = 0 AND onlinetime < :inactive AND universe = :universe;";
            $players = $db->select($sql, [
                ':inactive' => $this->state->runtime->timestamp - INACTIVE,
                ':universe' => $universe,
            ]);
            foreach ($players as $player) {
                $sql = 'SELECT id, ally_id FROM %%USERS%% WHERE id = :userId;';
                $userData = $db->selectSingle($sql, [
                    ':userId'   => $player['id'],
                ]);
                $this->playerUtil()->removeFromAlliance($userData);
                $this->playerUtil()->removeFromBuddy($player['id']);
            }
        }
    }

    /**
     * Sets the production percentage of mines, solar plant, fusion plant and satellites on the home/colony planets
     * (type 1) of players offline longer than INACTIVE (7 days, not in vacation) back to 100% (stored as 10).
     * Every planet whose levels change is brought up to date first (stock integrated to now at the levels the player set),
     * so the new levels only count from now on and nothing is credited retroactively.
     * @param int $universe universe id.
     */
    public function updateInactiveMines(int $universe): void
    {
        $db = $this->db;
        $sql = "SELECT p.`id`, p.`id_owner` FROM %%PLANETS%% p INNER JOIN %%USERS%% u ON u.`id` = p.`id_owner`
				WHERE p.`planet_type` = :planet AND u.`urlaubs_modus` = 0 AND u.`onlinetime` < :inactive AND u.`universe` = :universe
				AND (p.`metal_mine_porcent` != '10' OR p.`crystal_mine_porcent` != '10' OR p.`deuterium_sintetizer_porcent` != '10'
				OR p.`solar_plant_porcent` != '10' OR p.`fusion_plant_porcent` != '10' OR p.`solar_satelit_porcent` != '10');";
        $planets = $db->select($sql, [
            ':planet'	=> 1,
            ':inactive' => $this->state->runtime->timestamp - INACTIVE,
            ':universe' => $universe,
        ]);

        foreach ($planets as $row) {
            $db->startTransaction();
            $user = $db->selectSingle('SELECT u.*, us.* FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% us ON us.`user_id` = u.`id` WHERE u.`id` = :id FOR UPDATE;', [':id' => $row['id_owner']]);
            $planet = $db->selectSingle('SELECT * FROM %%PLANETS%% WHERE `id` = :id FOR UPDATE;', [':id' => $row['id']]);
            if (!empty($user) && !empty($planet)) {
                (new ResourceUpdate($db, $this->configs, $this->state))->CalcResource($user, $planet, true);
                $db->update("UPDATE %%PLANETS%% SET `eco_hash` = '', `metal_mine_porcent` = :full, `crystal_mine_porcent` = :full, `deuterium_sintetizer_porcent` = :full, `solar_plant_porcent` = :full, `fusion_plant_porcent` = :full, `solar_satelit_porcent` = :full WHERE `id` = :id;", [
                    ':full' => 10, // 10 = 100%
                    ':id' => $row['id'],
                ]);
            }
            $db->commit();
        }
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
            ':threeDays' => $this->state->runtime->timestamp - TIME_72_HOURS,
        ]);
    }
}
