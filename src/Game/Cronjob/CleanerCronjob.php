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
use App\Game\Core\Database;
use App\Game\Core\AccountUtil;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Universe;

class CleanerCronjob extends AbstractCronjobTask
{
    public function run(): void
    {
        $config = $this->configs->get(ROOT_UNI);
        $db     = $this->db;
        $unis   = $this->universe()->availableUniverses();

        //Delete old messages
        $del_before     = $this->state->runtime->timestamp - ($config->del_oldstuff * 86400);
        $del_inactive   = $this->state->runtime->timestamp - ($config->del_user_automatic * 86400);
        $del_deleted    = $this->state->runtime->timestamp - ($config->del_user_manually * 86400);
        $del_messages   = $this->state->runtime->timestamp - ($config->message_delete_days * 86400);

        $sql = 'DELETE um FROM %%USERS_TO_MESSAGES%% um
            LEFT JOIN %%MESSAGES%% m ON m.`message_id` = um.`message_id`
            WHERE `deleted_at` < :time OR (um.`created_at` < :time AND (um.`message_favorite` = 0 OR m.`message_type` = :mess_type_spy));';
        $db->delete($sql, [
            ':time'             => $del_messages,
            ':mess_type_spy'    => MESSAGE_TYPE_SPY_REPORT,
        ]);

        $sql = 'DELETE m
            FROM %%MESSAGES%% m
            LEFT JOIN %%USERS_TO_MESSAGES%% um ON m.message_id = um.message_id
            WHERE um.message_id IS NULL;';
        $db->delete($sql);

        // ranks of empty alliances go first, while the alliance rows still identify them
        $sql = 'DELETE r FROM %%ALLIANCE_RANK%% r JOIN %%ALLIANCE%% a ON a.`id` = r.`allianceID` WHERE a.`ally_members` = 0;';
        $db->delete($sql);

        $sql = 'DELETE FROM %%ALLIANCE%% WHERE `ally_members` = 0;';
        $db->delete($sql);

        $sql = 'DELETE FROM %%PLANETS%% WHERE `destruyed` < :time AND `destruyed` != 0;';
        $db->delete($sql, [
            ':time' => $this->state->runtime->timestamp
        ]);

        $sql = 'DELETE FROM %%SESSION%% WHERE `lastonline` < :time;';
        $db->delete($sql, [
            ':time' => $this->state->runtime->timestamp - SESSION_LIFETIME
        ]);

        $sql = 'DELETE FROM %%FLEETS_EVENT%% WHERE `fleetID` NOT IN (SELECT `id` FROM %%FLEETS%%);';
        $db->delete($sql);

        $sql = 'DELETE FROM %%LOG_FLEETS%% WHERE `start_time` < :time;';
        $db->delete($sql, [
            ':time' => $del_before
        ]);

        $sql = 'UPDATE %%PLANETS%% SET `tf_active` = 0 WHERE `tf_active` = 1;';
        $db->update($sql);

        $sql = 'DELETE FROM %%PHALANX_LOG%% WHERE `phalanx_time` < :time;';
        $db->delete($sql, [
            ':time' => $del_before
        ]);

        $sql = 'DELETE FROM %%PHALANX_FLEETS%% WHERE `phalanx_log_id` NOT IN (SELECT `id` FROM %%PHALANX_LOG%%)';
        $db->delete($sql);

        $sql = "DELETE FROM %%ADVANCED_STATS_HISTORY%% WHERE `timestamp` < :timestamp;";
        $db->delete($sql, [
            ':timestamp' => $this->state->runtime->timestamp - (7 * 86400)
        ]);

        foreach ($unis as $uni) {
            $config = $this->configs->get($uni);
            $status = $config->uni_status;
            if ($status == STATUS_REG_ONLY) {
                continue;
            }

            // del_user_automatic = 0 means disabled: no onlinetime is smaller than 0, so nobody matches
            $del_inactive   = $config->del_user_automatic > 0
                ? $this->state->runtime->timestamp - ($config->del_user_automatic * 86400)
                : 0;
            $del_deleted    = $this->state->runtime->timestamp - ($config->del_user_manually * 86400);
            $sql = 'SELECT `id` FROM %%USERS%% WHERE `authlevel` = :authlevel AND universe = :uni
                AND ((`db_deaktjava` != 0 AND `db_deaktjava` < :timeDeleted) OR `onlinetime` < :timeInactive);';

            $deleteUserIds = $db->select($sql, [
                ':authlevel'    => AUTH_USR,
                ':timeDeleted'  => $del_deleted,
                ':timeInactive' => $del_inactive,
                ':uni'          => $uni
            ]);

            if (!empty($deleteUserIds)) {
                foreach ($deleteUserIds as $dataRow) {
                    $this->playerUtil()->deletePlayer($dataRow['id']);
                }
            }
        }

        //delete accounts set to delete
        $config = $this->configs->get();
        $del_deleted  = $this->state->runtime->timestamp - ($config->del_user_manually * 86400);
        $sql = 'SELECT `id` FROM %%ACCOUNTS%% WHERE `authlevel` = :authlevel AND 
            (`db_deaktjava` != 0 AND `db_deaktjava` < :timeDeleted);';

        $deleteAccountIds = $db->select($sql, [
            ':authlevel'    => AUTH_USR,
            ':timeDeleted'  => $del_deleted
        ]);

        if (!empty($deleteAccountIds)) {
            foreach ($deleteAccountIds as $dataRow) {
                $this->accountUtil()->deleteAccount($dataRow['id']);
            }
        }

        //delete accounts without users without activity for 28 days
        $sql = "SELECT * FROM %%ACCOUNTS%% WHERE `id` NOT IN (SELECT DISTINCT(account_id) FROM %%ACCOUNTS_TO_USERS%%) AND `onlinetime` < :inactive AND `authlevel` = :authlevel;";
        $deleteAccountIds = $db->select($sql, [
            ':authlevel'    => AUTH_USR,
            ':inactive'     => $this->state->runtime->timestamp - TIME_1_MONTH
        ]);

        if (!empty($deleteAccountIds)) {
            foreach ($deleteAccountIds as $dataRow) {
                $this->accountUtil()->deleteAccount($dataRow['id']);
            }
        }
    }
}
