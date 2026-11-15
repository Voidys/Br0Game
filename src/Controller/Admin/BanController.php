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

namespace App\Controller\Admin;

use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;
use Symfony\Component\HttpFoundation\Response;
use App\Twig\GameEscaping;
use Twig\Markup;

/** Bans and unbans players and login accounts. */
#[\App\Routing\GamePage('admin.php', 'ban')]
#[\App\Routing\GamePage('admin.php', 'ban', uniPrefix: true)]
class BanController extends AbstractAdminController
{
    public function show(): Response
    {
        $db = $this->db;
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;

        if (empty($this->state->requestQuery['mode'])) {
            $this->state->requestQuery['mode'] = $this->state->requestQuery['page'];
        }
        if (!isset($this->state->requestQuery['order'])) {
            $this->state->requestQuery['order'] = '';
        }

        $WHEREBANA = "";
        if (!isset($this->state->requestQuery['view'])) {
            $this->state->requestQuery['view'] = '';
        }
        if ($this->state->requestQuery['view'] == 'bana') {
            $WHEREBANA = "AND `bana` = '1'";
        }
        switch ($this->state->requestQuery['mode']) {
            case 'user':
                $ORDER = $this->state->requestQuery['order'] == 'id' ? "id" : "username";
                $sql = "SELECT `username`, `id`, `bana` FROM %%USERS%% WHERE `id` != 1 AND `authlevel` <= :authlevel AND `universe` = :universe " . $WHEREBANA . " ORDER BY " . $ORDER . " ASC;";
                $userList = $db->select($sql, [
                    ':authlevel' => $USER['authlevel'],
                    ':universe'  => $this->universe()->getEmulated(),
                ]);
                $userSelect = ['List' => '', 'ListBan' => ''];

                $users = 0;
                foreach ($userList as $user) {
                    $userSelect['List'] .= '<option value="' . $user['id'] . '">' . GameEscaping::escape((string) $user['username'], 'UTF-8') . '&nbsp;&nbsp;(ID:&nbsp;' . $user['id'] . ')' . (($user['bana'] == '1') ? $LNG['bo_characters_suus'] : '') . '</option>';
                    $users++;
                }

                if (!isset($this->state->requestQuery['order2'])) {
                    $this->state->requestQuery['order2'] = '';
                }
                $ORDER2 = $this->state->requestQuery['order2'] == 'id' ? "id" : "username";

                $banned = 0;
                $sql = "SELECT `username`, `id`
                FROM %%USERS%%
                WHERE `bana` = '1' AND `universe` = :universe
                ORDER BY " . $ORDER2 . " ASC;";
                $userListBan = $db->select($sql, [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
                foreach ($userListBan as $user) {
                    $userSelect['ListBan'] .= '<option value="' . $user['id'] . '">' . GameEscaping::escape((string) $user['username'], 'UTF-8') . '&nbsp;&nbsp;(ID:&nbsp;' . $user['id'] . ')</option>';
                    $banned++;
                }

                $id = $this->http()->_GP('ban_id', 0);
                if (isset($this->state->requestPost['panel_change']) || isset($this->state->requestPost['unban_id'])) {
                    $id = $this->http()->_GP('unban_id', 0);
                }
                $sql = "SELECT b.`theme`, b.`longer`, u.`id`, u.`urlaubs_modus`, u.`banaday`, u.`username`
                FROM %%USERS%% as u 
                LEFT JOIN %%USERS_BANNED%% as b ON b.`user_id` = u.`id`
                WHERE u.`id` = :user_id AND u.`universe` = :universe;";
                $bannedUser = $db->SelectSingle($sql, [
                    ':user_id'  => $id,
                    ':universe' => $this->universe()->getEmulated(),
                ]);

                if (!empty($bannedUser) && (isset($this->state->requestPost['panel']) || isset($this->state->requestPost['panel_change']))) {
                    if ($bannedUser['banaday'] <= $this->state->runtime->timestamp) {
                        $title = $LNG['bo_bbb_title_1'];
                        $changedate = $LNG['bo_bbb_title_2'];
                        $changedate_advert = '';
                        $reas = '';
                        $timesus = '';
                    } else {
                        $title = $LNG['bo_bbb_title_3'];
                        $changedate = $LNG['bo_bbb_title_6'];
                        $changedate_advert = $LNG['bo_bbb_title_4'];
                        $reas = $bannedUser['theme'];
                        $timesus
                            = '<div class="field-row"><span class="field-label">' . $LNG['bo_bbb_title_5'] . '</span>'
                            . '<div class="field-control"><strong>' . date($LNG['php_tdformat'], $bannedUser['longer']) . '</strong></div></div>';
                    }

                    if (isset($this->state->requestPost['panel_change'])) {
                        $vacation = ($bannedUser['urlaubs_modus'] == 1) ? true : false;
                        $perma = ($bannedUser['banaday'] == 2147483647) ? true : false;
                    } else {
                        $vacation = true;
                        $perma = false;
                    }

                    $this->assign([
                        'user_id'           => $id,
                        'name'              => $bannedUser['username'],
                        'bantitle'          => $title,
                        'changedate'        => $changedate,
                        'reas'              => $reas,
                        'changedate_advert' => $changedate_advert,
                        'timesus'           => new Markup($timesus, 'UTF-8'),
                        'perma'             => $perma,
                        'vacation'          => $vacation,
                    ]);
                } elseif (!empty($bannedUser) && isset($this->state->requestPost['bannow']) && $bannedUser['id'] != 1) {
                    $id = $this->http()->_GP('ban_id', 0);
                    $Name = $this->http()->_GP('ban_name', '', true);
                    $reas = $this->http()->_GP('why', '', true);
                    $days = $this->http()->_GP('days', 0);
                    $hour = $this->http()->_GP('hour', 0);
                    $mins = $this->http()->_GP('mins', 0);
                    $secs = $this->http()->_GP('secs', 0);
                    $admin = $USER['username'];
                    $mail = ""; //$ACCOUNT['email'];
                    $BanTime = $days * 86400 + $hour * 3600 + $mins * 60 + $secs;

                    if ($bannedUser['longer'] > $this->state->runtime->timestamp && $bannedUser['banaday'] != 2147483647) {
                        $BanTime += ($bannedUser['longer'] - $this->state->runtime->timestamp);
                    }

                    if (isset($this->state->requestPost['permanent'])) {
                        $BannedUntil = 2147483647;
                    } else {
                        $BannedUntil = ($BanTime + $this->state->runtime->timestamp) < $this->state->runtime->timestamp ? $this->state->runtime->timestamp : $this->state->runtime->timestamp + $BanTime;
                    }

                    if ($bannedUser['banaday'] > $this->state->runtime->timestamp) {
                        $sql = "UPDATE %%USERS_BANNED%% SET `theme` = :reas, `time` = :time, 
                        `longer` = :longer, `author` = :author, `email` = :mail
                        WHERE `user_id` = :user_id AND `universe` = :universe;";
                        $db->update($sql, [
                            ':reas'     => $reas,
                            ':time'     => $this->state->runtime->timestamp,
                            ':longer'   => $BannedUntil,
                            ':author'   => $admin,
                            ':mail'     => $mail,
                            ':user_id'  => $id,
                            ':universe' => $this->universe()->getEmulated(),
                        ]);
                    } else {
                        $sql = "INSERT INTO %%USERS_BANNED%% SET `user_id` = :user_id, `who` = :name, `theme` = :reas, `time` = :time, 
                        `longer` = :longer, `author` = :author, `universe` = :universe, `email` = :mail;";
                        $db->insert($sql, [
                            ':user_id'  => $id,
                            ':name'     => $Name,
                            ':reas'     => $reas,
                            ':time'     => $this->state->runtime->timestamp,
                            ':longer'   => $BannedUntil,
                            ':author'   => $admin,
                            ':universe' => $this->universe()->getEmulated(),
                            ':mail'     => $mail,
                        ]);
                    }

                    $sql = "UPDATE %%USERS%% SET `bana` = 1, `banaday` = :bannedUntil 
                    WHERE `id` = :user_id AND `universe` = :universe;";
                    $db->update($sql, [
                        ':bannedUntil' => $BannedUntil,
                        ':user_id'     => $id,
                        ':universe'    => $this->universe()->getEmulated(),
                    ]);
                    if (isset($this->state->requestPost['vacat'])) {
                        $planetDummy = [];
                        $this->playerUtil()->enable_vmode($bannedUser, $planetDummy, true);
                    }

                    $this->message($LNG['bo_the_player'] . $Name . $LNG['bo_banned'], '?page=ban');
                    throw new \App\Game\Core\EndOfRequest();
                } elseif (isset($this->state->requestPost['unban_id'])) {
                    $id = $this->http()->_GP('unban_id', 0);

                    $sql = "UPDATE %%USERS%% SET `bana` = 0, `banaday` = 0 
                    WHERE `id` = :user_id AND `universe` = :universe;";
                    $db->update($sql, [
                        ':user_id'  => $id,
                        ':universe' => $this->universe()->getEmulated(),
                    ]);
                    $this->message($LNG['bo_the_player2'] . $bannedUser['username'] . $LNG['bo_unbanned'], '?page=ban');
                    throw new \App\Game\Core\EndOfRequest();
                }
                $this->assign([
                    'UserSelect'      => array_map(static fn(string $options) => new Markup($options, 'UTF-8'), $userSelect),
                    'usercount'       => $users,
                    'bancount'        => $banned,
                    'bo_select_title' => $LNG['bo_select_title'],
                ]);
                return $this->render('admin/BanPageUser.html.twig');
            case 'account':
                $ORDER = $this->state->requestQuery['order'] == 'id' ? "id" : "accountname";
                $sql = "SELECT `accountname`, `id`, `bana` 
                FROM %%ACCOUNTS%% 
                WHERE `id` != 1 AND `authlevel` <= :authlevel " . $WHEREBANA . " 
                ORDER BY " . $ORDER . " ASC;";
                $accountList = $db->select($sql, [
                    ':authlevel' => $USER['authlevel'],
                ]);
                $accountSelect = ['List' => '', 'ListBan' => ''];

                $accounts = 0;
                foreach ($accountList as $account) {
                    $accountSelect['List'] .= '<option value="' . $account['id'] . '">' . GameEscaping::escape((string) $account['accountname'], 'UTF-8') . '&nbsp;&nbsp;(ID:&nbsp;' . $account['id'] . ')' . (($account['bana'] == '1') ? $LNG['bo_characters_suus'] : '') . '</option>';
                    $accounts++;
                }

                if (!isset($this->state->requestQuery['order2'])) {
                    $this->state->requestQuery['order2'] = '';
                }
                $ORDER2 = $this->state->requestQuery['order2'] == 'id' ? "id" : "accountname";

                $banned = 0;
                $sql = "SELECT `accountname`, `id` 
                FROM %%ACCOUNTS%% 
                WHERE `bana` = '1' 
                ORDER BY " . $ORDER2 . " ASC;";
                $accountListBan = $db->select($sql, []);
                foreach ($accountListBan as $account) {
                    $accountSelect['ListBan'] .= '<option value="' . $account['id'] . '">' . GameEscaping::escape((string) $account['accountname'], 'UTF-8') . '&nbsp;&nbsp;(ID:&nbsp;' . $account['id'] . ')</option>';
                    $banned++;
                }

                $id = $this->http()->_GP('ban_id', 0);
                if (isset($this->state->requestPost['panel_change']) || isset($this->state->requestPost['unban_id'])) {
                    $id = $this->http()->_GP('unban_id', 0);
                }
                $sql = "SELECT b.`theme`, b.`longer`, a.`id`, a.`banaday`, a.`accountname` 
                FROM %%ACCOUNTS%% as a 
                LEFT JOIN %%ACCOUNTS_BANNED%% as b ON b.`account_id` = a.`id`
                WHERE a.`id` = :account_id;";
                $bannedAccount = $db->SelectSingle($sql, [
                    ':account_id' => $id,
                ]);

                if (!empty($bannedAccount) && (isset($this->state->requestPost['panel']) || isset($this->state->requestPost['panel_change']))) {
                    if ($bannedAccount['banaday'] <= $this->state->runtime->timestamp) {
                        $title = $LNG['bo_bbb_title_1'];
                        $changedate = $LNG['bo_bbb_title_2'];
                        $changedate_advert = '';
                        $reas = '';
                        $timesus = '';
                    } else {
                        $title = $LNG['bo_bbb_title_3'];
                        $changedate = $LNG['bo_bbb_title_6'];
                        $changedate_advert = $LNG['bo_bbb_title_4'];
                        $reas = $bannedAccount['theme'];
                        $timesus
                            = '<div class="field-row"><span class="field-label">' . $LNG['bo_bbb_title_5'] . '</span>'
                            . '<div class="field-control"><strong>' . date($LNG['php_tdformat'], $bannedAccount['longer']) . '</strong></div></div>';
                    }

                    if (isset($this->state->requestPost['panel_change'])) {
                        $vacation = true;
                        $perma = ($bannedAccount['banaday'] == 2147483647) ? true : false;
                    } else {
                        $vacation = true;
                        $perma = false;
                    }

                    $this->assign([
                        'account_id'        => $id,
                        'name'              => $bannedAccount['accountname'],
                        'bantitle'          => $title,
                        'changedate'        => $changedate,
                        'reas'              => $reas,
                        'changedate_advert' => $changedate_advert,
                        'timesus'           => new Markup($timesus, 'UTF-8'),
                        'perma'             => $perma,
                        'vacation'          => $vacation,
                    ]);
                } elseif (!empty($bannedAccount) && isset($this->state->requestPost['bannow']) && $bannedAccount['id'] != 1) {
                    $id = $this->http()->_GP('ban_id', 0);
                    $Name = $this->http()->_GP('ban_name', '', true);
                    $reas = $this->http()->_GP('why', '', true);
                    $days = $this->http()->_GP('days', 0);
                    $hour = $this->http()->_GP('hour', 0);
                    $mins = $this->http()->_GP('mins', 0);
                    $secs = $this->http()->_GP('secs', 0);
                    $admin = $USER['username'];
                    $mail = "";//$ACCOUNT['email'];
                    $BanTime = $days * 86400 + $hour * 3600 + $mins * 60 + $secs;

                    if ($bannedAccount['longer'] > $this->state->runtime->timestamp && $bannedAccount['banaday'] != 2147483647) {
                        $BanTime += ($bannedAccount['longer'] - $this->state->runtime->timestamp);
                    }

                    if (isset($this->state->requestPost['permanent'])) {
                        $BannedUntil = 2147483647;
                    } else {
                        $BannedUntil = ($BanTime + $this->state->runtime->timestamp) < $this->state->runtime->timestamp ? $this->state->runtime->timestamp : $this->state->runtime->timestamp + $BanTime;
                    }

                    $sql = "SELECT au.user_id AS id, u.username 
                    FROM %%ACCOUNTS_TO_USERS%% au 
                    JOIN %%USERS%% u ON u.`id` = au.`user_id` 
                    WHERE au.`account_id` = :account_id;";
                    $users = $db->select($sql, [':account_id' => $id]);

                    if ($bannedAccount['banaday'] > $this->state->runtime->timestamp) {
                        $sql = "UPDATE %%ACCOUNTS_BANNED%% SET `theme` = :reas, `time` = :time, 
                        `longer` = :longer, `author` = :author, `email` = :mail
                        WHERE account_id = :account_id;";
                        $db->update($sql, [
                            ':reas'       => $reas,
                            ':time'       => $this->state->runtime->timestamp,
                            ':longer'     => $BannedUntil,
                            ':author'     => $admin,
                            ':mail'       => $mail,
                            ':account_id' => $id,
                        ]);

                        foreach ($users as $user) {
                            $sql = "UPDATE %%USERS_BANNED%% SET `theme` = :reas, `time` = :time, 
                            `longer` = :longer, `author` = :author, `email` = :mail
                            WHERE `user_id` = :user_id AND `universe` = :universe;";
                            $db->update($sql, [
                                ':reas'     => $reas,
                                ':time'     => $this->state->runtime->timestamp,
                                ':longer'   => $BannedUntil,
                                ':author'   => $admin,
                                ':mail'     => $mail,
                                ':user_id'  => $user['id'],
                                ':universe' => $this->universe()->getEmulated(),
                            ]);
                        }
                    } else {
                        $sql = "INSERT INTO %%ACCOUNTS_BANNED%% SET `account_id` = :account_id, `who` = :name, `theme` = :reas, `time` = :time, 
                        `longer` = :longer, `author` = :author, `email` = :mail;";
                        $db->insert($sql, [
                            ':account_id' => $id,
                            ':name'       => $Name,
                            ':reas'       => $reas,
                            ':time'       => $this->state->runtime->timestamp,
                            ':longer'     => $BannedUntil,
                            ':author'     => $admin,
                            ':mail'       => $mail,
                        ]);

                        foreach ($users as $user) {
                            $sql = "INSERT INTO %%USERS_BANNED%% SET `user_id` = :user_id, `who` = :name, `theme` = :reas, `time` = :time, 
                            `longer` = :longer, `author` = :author, `universe` = :universe, `email` = :mail;";
                            $db->insert($sql, [
                                ':user_id'  => $user['id'],
                                ':name'     => $user['username'],
                                ':reas'     => $reas,
                                ':time'     => $this->state->runtime->timestamp,
                                ':longer'   => $BannedUntil,
                                ':author'   => $admin,
                                ':universe' => $this->universe()->getEmulated(),
                                ':mail'     => $mail,
                            ]);
                        }
                    }

                    $sql = "UPDATE %%ACCOUNTS%% SET `bana` = 1, `banaday` = :bannedUntil 
                    WHERE `id` = :account_id;";
                    $db->update($sql, [
                        ':bannedUntil' => $BannedUntil,
                        ':account_id'  => $id,
                    ]);

                    foreach ($users as $user) {
                        $sql = "UPDATE %%USERS%% SET `bana` = 1, `banaday` = :bannedUntil 
                        WHERE `id` = :user_id AND `universe` = :universe;";
                        $db->update($sql, [
                            ':bannedUntil' => $BannedUntil,
                            ':user_id'     => $user['id'],
                            ':universe'    => $this->universe()->getEmulated(),
                        ]);
                        if (isset($this->state->requestPost['vacat'])) {
                            $planetDummy = [];
                            $this->playerUtil()->enable_vmode($user, $planetDummy, true);
                        }
                    }

                    $this->message($LNG['bo_the_player'] . $Name . $LNG['bo_banned'], '?page=ban');
                    throw new \App\Game\Core\EndOfRequest();
                } elseif (isset($this->state->requestPost['unban_id'])) {
                    $id = $this->http()->_GP('unban_id', 0);

                    $sql = "UPDATE %%ACCOUNTS%% SET `bana` = 0, `banaday` = 0 
                    WHERE `id` = :account_id;";
                    $db->update($sql, [
                        ':account_id' => $id,
                    ]);
                    $this->message($LNG['bo_the_player2'] . $bannedAccount['accountname'] . $LNG['bo_unbanned'], '?page=ban');
                    throw new \App\Game\Core\EndOfRequest();
                }
                $this->assign([
                    'accountSelect'   => array_map(static fn(string $options) => new Markup($options, 'UTF-8'), $accountSelect),
                    'accountCount'    => $accounts,
                    'bancount'        => $banned,
                    'bo_select_title' => $LNG['bo_select_title'],
                ]);
                return $this->render('admin/BanPageAccount.html.twig');
            default:
                return $this->render('admin/BanPage.html.twig');
        }
    }
}
