<?php

/**
 * pr0game powered by steemnova
 * Accounts
 * (c) 2024 reflexrecon
 */

namespace App\Game\Core;

use Cron\CronExpression;
use DateTime;
use Exception;

class AccountUtil
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    public function cryptPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 13]);
    }

    public function isNameValid(string $name): bool
    {
        if (UTF8_SUPPORT) {
            return preg_match('/^[0-9a-zA-ZäöüÄÖÜß _.-]*$/u', $name);
        } else {
            return preg_match('/^[A-z0-9_\-. ]*$/', $name);
        }
    }

    public function isMailValid(string $address): bool
    {
        if (function_exists('filter_var')) {
            return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
        } else {
            return preg_match('^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$', $address);
        }
    }

    /**
     * Create Account
     *
     * @param string $accountname
     * @param string $userPassword
     * @param string $userMail
     * @param string $userLanguage
     * @param int $authlevel
     * @param string $userIpAddress
     * @param string $timezone
     * @return int
     */
    public function createAccount(
        string $accountname,
        string $userPassword,
        string $userMail,
        ?string $userLanguage = null,
        int $authlevel = 0,
        ?string $userIpAddress = null,
        string $timezone = "Europe/Berlin"
    ): int
    {
        $db = $this->db;

        $params = [
            ':accountname'       => $accountname,
            ':email'             => $userMail,
            ':email2'            => $userMail,
            ':authlevel'         => $authlevel,
            ':registerAddress'   => !empty($userIpAddress) ? $userIpAddress : $this->sessions()->getClientIp(),
            ':registerTimestamp' => $this->state->runtime->timestamp,
            ':password'          => $userPassword,
            ':language'          => $userLanguage,
            ':dpath'             => \App\Game\Core\Theme::resolveDefaultTheme($this->configs->get()->default_theme ?? null),
            ':css_dpath'         => \App\Game\Core\Theme::resolveDefaultTheme($this->configs->get()->default_theme ?? null),
            ':timezone'          => $timezone,
        ];

        $sql = 'INSERT INTO %%ACCOUNTS%% SET
            `accountname`	    = :accountname,
            `email`			    = :email,
            `email_2`		    = :email2,
            `authlevel`		    = :authlevel,
            `ip_at_reg`		    = :registerAddress,
            `account_lastip`    = :registerAddress,
            `register_time`     = :registerTimestamp,
            `password`		    = :password,
            `dpath`			    = :dpath,
            `css_dpath`         = :css_dpath,
            `timezone`		    = :timezone,
            `lang`			    = :language;';

        $db->insert($sql, $params);

        $accountId = $db->lastInsertId();

        return $accountId;
    }

    /**
     * Delete Account
     *
     * @param int $accountId
     * @return bool
     */
    public function deleteAccount(int $accountId): bool
    {
        if (ROOT_USER == $accountId) {
            // superuser can not be deleted.
            throw new Exception("Superuser #" . ROOT_USER . " can't be deleted!");
        }
        $playerUniverses = $this->get_player_universes($accountId);
        foreach ($playerUniverses as $playerUniverse) {
            $this->playerUtil()->deletePlayer($playerUniverse['user_id']);
        }
        $db = $this->db;
        $sql = 'SELECT `id` FROM %%ACCOUNTS%% WHERE `id` = :accountId;';
        $userData = $db->selectSingle($sql, [
            ':accountId'   => $accountId
        ]);

        if (empty($userData)) {
            return false;
        }

        $sql = 'DELETE FROM %%ACCOUNTS%% WHERE `id` = :accountId;';
        $db->delete($sql, [
            ':accountId'   => $accountId
        ]);

        $sql = 'DELETE FROM %%ACCOUNTS_TO_USERS%% WHERE `account_id` = :accountId;';
        $db->delete($sql, [
            ':accountId'   => $accountId
        ]);

        $sql = 'DELETE FROM %%ACCOUNTS_BANNED%% WHERE `account_id` = :accountId;';
        $db->delete($sql, [
            ':accountId'   => $accountId
        ]);

        // account-level leftovers (board posts are kept as public content)
        foreach (['LOSTPASSWORD' => 'accountID', 'RECAPTCHA' => 'accountId', 'SESSION' => 'accountID'] as $table => $column) {
            $db->delete('DELETE FROM %%' . $table . '%% WHERE `' . $column . '` = :accountId;', [
                ':accountId'   => $accountId
            ]);
        }

        return true;
    }

    public function getDeletionDate(int $dbdeaktjava, object $config): int
    {
        $db = $this->db;
        $sql = 'SELECT * FROM %%CRONJOBS%% WHERE `cronjobID` = 4;';
        $deletionJob = $db->selectSingle($sql);
        $deletionDate = 0;
        if (!empty($deletionJob) && $deletionJob['isActive'] == 1) {
            $earliestTime = $dbdeaktjava + ($config->del_user_manually * 86400);
            if ($earliestTime < $deletionJob['nextTime']) {
                $deletionDate = $deletionJob['nextTime'];
            } else {
                $nextTime = $this->state->runtime->timestamp + 60;
                $cronTabString = implode(' ', [$deletionJob['min'], $deletionJob['hours'], $deletionJob['dom'], $deletionJob['month'], $deletionJob['dow']]);
                $cron = new CronExpression($cronTabString);
                for ($i = 0; $i < 10; $i++) {
                    $nextTime = $cron->getNextRunDate(new DateTime('@' . $nextTime))->getTimestamp();
                    if ($nextTime > $earliestTime) {
                        $deletionDate = $nextTime;
                        break;
                    }
                }
            }
        }
        return $deletionDate;
    }

    /**
     * Get Universes in which the Account has a User
     *
     * @param int $accountId
     * @return array
     */
    public function get_player_universes(int $accountId): array
    {
        $db = $this->db;

        $sql = 'SELECT u.universe as uni, u.username, s.total_rank, u.id as user_id, u.authlevel
                FROM %%ACCOUNTS%% a 
                LEFT JOIN %%ACCOUNTS_TO_USERS%% au on au.account_id = a.id 
                LEFT JOIN %%USERS%% u on u.id = au.user_id 
                LEFT JOIN %%STATPOINTS%% s on s.id_owner = u.id AND s.stat_type = 1
                WHERE a.id = :id AND u.id IS NOT NULL
                ORDER BY u.universe ASC;';
        $playerUniverses = $db->select($sql, [
            ':id'       => $accountId,
        ]);

        foreach ($playerUniverses as $key => $universe) {
            $config = $this->configs->get($universe['uni']);
            $playerUniverses[$key]['uni_name'] = $config->uni_name;
            $playerUniverses[$key]['start'] = $config->start;
            $playerUniverses[$key]['end'] = $config->end;
            $playerUniverses[$key]['uni_status'] = $config->uni_status;
        }
        return $playerUniverses;
    }

    /**
     * Get Universes in which the Account has no User
     *
     * @param int $accountId
     * @return array
     */
    public function get_non_player_universes(int $accountId): array
    {
        $db = $this->db;

        $sql = 'SELECT universe as uni, count(id) as active 
                FROM %%USERS%% u 
                WHERE u.onlinetime > :inactive AND NOT EXISTS (
                    SELECT 1
                    FROM %%ACCOUNTS_TO_USERS%% au
                    JOIN %%USERS%% u2 ON u2.`id` = au.`user_id`
                    WHERE au.`account_id` = :id AND u2.universe = u.universe
                )
                GROUP BY universe';
        $nonPlayerUniverses = $db->select($sql, [
            ':id'       => $accountId,
            ':inactive' => $this->state->runtime->timestamp - TIME_1_WEEK,
        ]);

        foreach ($nonPlayerUniverses as $key => $universe) {
            $config = $this->configs->get($universe['uni']);
            $nonPlayerUniverses[$key]['uni_name'] = $config->uni_name;
            $nonPlayerUniverses[$key]['start'] = $config->start;
            $nonPlayerUniverses[$key]['end'] = $config->end;
            $nonPlayerUniverses[$key]['uni_status'] = $config->uni_status;
        }
        return $nonPlayerUniverses;
    }

    /**
     * Get AuthLevel of Account
     *
     * @param int $accountId
     * @return int
     */
    public function get_authlevel(int $accountId): int
    {
        $db = $this->db;

        $sql = 'SELECT `authlevel` FROM %%ACCOUNTS%% WHERE `id` = :accountID;';
        $authlevel = $db->selectSingle($sql, [
            ':accountID'       => $accountId,
        ], 'authlevel');

        return $authlevel;
    }
    /**
     * Get data of Account
     *
     * @param int $accountId
     * @return array
     */
    public function get_account(int $accountId): array
    {
        $db = $this->db;

        $sql = 'SELECT * FROM %%ACCOUNTS%% WHERE `id` = :accountID;';
        $account = $db->selectSingle($sql, [
            ':accountID'       => $accountId,
        ]);

        return $account;
    }

    public function banAccount(int $accountId, string $banReason, int $banTime = 2147483647, bool $vMode = true, string $admin = 'System', string $mail = ''): void
    {
        $db = $this->db;

        $sql = 'SELECT `accountname` FROM %%ACCOUNTS%% WHERE `id` = :accountId;';
        $accName = $db->selectSingle($sql, [
            ':accountId'   => $accountId
        ], 'accountname');

        $sql = 'INSERT INTO %%ACCOUNTS_BANNED%% SET
            `account_id`	= :accountId,
            `who`           = :name,
            `theme`	        = :banReason,
            `time`		    = :time,
            `longer`		= :banTime,
            `author`        = :author,
            `email`         = :mail;';

        $db->insert($sql, [
            ':accountId'    => $accountId,
            ':name'         => $accName,
            ':banReason'    => $banReason,
            ':time'         => $this->state->runtime->timestamp,
            ':banTime'      => $banTime,
            ':author'       => $admin,
            ':mail'         => $mail,
        ]);

        $sql = "UPDATE %%ACCOUNTS%% SET `bana` = 1, `banaday` = :bannedUntil 
            WHERE `id` = :account_id;";
        $db->update($sql, [
            ':bannedUntil'  => $banTime,
            ':account_id'   => $accountId,
        ]);

        $sql = "SELECT au.user_id AS id, u.username, u.universe 
            FROM %%ACCOUNTS_TO_USERS%% au
            JOIN %%USERS%% u ON u.`id` = au.`user_id`
            WHERE au.`account_id` = :account_id;";
        $users = $db->select($sql, [':account_id' => $accountId]);

        foreach ($users as $user) {
            $sql = "INSERT INTO %%USERS_BANNED%% SET `user_id` = :user_id, `who` = :name, `theme` = :reas, `time` = :time, 
                `longer` = :longer, `author` = :author, `universe` = :universe, `email` = :mail;";
            $db->insert($sql, [
                ':user_id'  => $user['id'],
                ':name'  	=> $user['username'],
                ':reas'     => $banReason,
                ':time'     => $this->state->runtime->timestamp,
                ':longer'   => $banTime,
                ':author'   => $admin,
                ':universe' => $user['universe'],
                ':mail'     => $mail,
            ]);

            $sql = "UPDATE %%USERS%% SET `bana` = 1, `banaday` = :bannedUntil 
                WHERE `id` = :user_id AND `universe` = :universe;";
            $db->update($sql, [
                ':bannedUntil'  => $banTime,
                ':user_id'      => $user['id'],
                ':universe'    	=> $user['universe'],
            ]);
            if ($vMode) {
                $planet = [];
                $this->playerUtil()->enable_vmode($user, $planet, true);
            }
        }
    }
}
/*
    Enable to debug
*/
// try {
//     define('MODE', 'INSTALL');
//     define('ROOT_PATH', 'G:/xampp/htdocs/pr0game/');
//     define('TIMESTAMP', time());
//     require 'src/Game/Support/constants.php';
//     require 'includes/classes/Database.class.php';
//     require 'includes/classes/Cache.class.php';
//     require 'src/Game/Support/vars.php';
//     require 'includes/classes/Config.class.php';

//     PlayerUtil::randomHP(1);
// } catch (Exception $e) {
// }
