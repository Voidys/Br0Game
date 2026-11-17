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
    /**
     * Ban management for players (mode `user`) and login accounts (mode `account`). GET: order/order2 (id or name sort of the
     * lists), view=bana (only banned). POST: panel / panel_change (show the ban form of ban_id or unban_id), bannow (ban),
     * unban. Ban data: ban_id, ban_name, why (reason), days/hour/mins/secs (duration in addition to a still running ban),
     * permanent (until 2147483647), vacat (also put the player(s) in vacation mode). Writes USERS_BANNED / ACCOUNTS_BANNED and
     * the `bana`/`banaday` flags; an account ban also bans the account's players in the emulated universe. Unbanning
     * resets the flags. Player id 1 and targets above the admin's authlevel are not offered.
     */
    public function show(): Response
    {
        if (empty($this->state->requestQuery['mode'])) {
            $this->state->requestQuery['mode'] = $this->state->requestQuery['page'];
        }
        if (!isset($this->state->requestQuery['order'])) {
            $this->state->requestQuery['order'] = '';
        }

        // "view=bana" restricts the left list to the players that are already banned
        $onlyBanned = false;
        if (!isset($this->state->requestQuery['view'])) {
            $this->state->requestQuery['view'] = '';
        }
        if ($this->state->requestQuery['view'] == 'bana') {
            $onlyBanned = true;
        }
        switch ($this->state->requestQuery['mode']) {
            case 'user':
                return $this->showUserBans($onlyBanned);
            case 'account':
                return $this->showAccountBans($onlyBanned);
            default:
                return $this->render('admin/BanPage.html.twig');
        }
    }

    /**
     * Mode `user`: builds both player lists, then handles the posted action (show the ban form, ban, unban) for the
     * selected player and renders the page. Ban/unban end the request with a message (EndOfRequest).
     */
    private function showUserBans(bool $onlyBanned): Response
    {
        $db = $this->db;
        $LNG = $this->state->LNG;

        [$userSelect['List'], $users] = $this->playerOptionList($onlyBanned);

        if (!isset($this->state->requestQuery['order2'])) {
            $this->state->requestQuery['order2'] = '';
        }
        [$userSelect['ListBan'], $banned] = $this->bannedPlayerOptionList();

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

        if ($this->isBanPost() && !$this->mayActOn((int) $id)) {
            $this->deny();
        }

        if (!empty($bannedUser) && (isset($this->state->requestPost['panel']) || isset($this->state->requestPost['panel_change']))) {
            if (isset($this->state->requestPost['panel_change'])) {
                $vacation = ($bannedUser['urlaubs_modus'] == 1) ? true : false;
                $perma = ($bannedUser['banaday'] == 2147483647) ? true : false;
            } else {
                $vacation = true;
                $perma = false;
            }
            $this->assignBanPanel($bannedUser, $id, $bannedUser['username'], 'user_id', $perma, $vacation);
        } elseif (!empty($bannedUser) && isset($this->state->requestPost['bannow']) && $bannedUser['id'] != 1) {
            $this->banPlayer($bannedUser);
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
    }

    /**
     * Mode `account`: same as showUserBans() for login accounts (no universe filter, an account ban cascades to the
     * account's players).
     */
    private function showAccountBans(bool $onlyBanned): Response
    {
        $db = $this->db;
        $LNG = $this->state->LNG;

        [$accountSelect['List'], $accounts] = $this->accountOptionList($onlyBanned);

        if (!isset($this->state->requestQuery['order2'])) {
            $this->state->requestQuery['order2'] = '';
        }
        [$accountSelect['ListBan'], $banned] = $this->bannedAccountOptionList();

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

        if ($this->isBanPost() && !$this->mayActOnAccount((int) $id)) {
            $this->deny();
        }

        if (!empty($bannedAccount) && (isset($this->state->requestPost['panel']) || isset($this->state->requestPost['panel_change']))) {
            $perma = isset($this->state->requestPost['panel_change']) ? ($bannedAccount['banaday'] == 2147483647) : false;
            $this->assignBanPanel($bannedAccount, $id, $bannedAccount['accountname'], 'account_id', $perma, true);
        } elseif (!empty($bannedAccount) && isset($this->state->requestPost['bannow']) && $bannedAccount['id'] != 1) {
            $this->banAccount($bannedAccount);
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
    }

    /**
     * Left list of the player ban page: <option> HTML of all players the admin may ban (not id 1, authlevel up to the
     * admin's), sorted by the `order` query value (id or name), optionally only the banned ones.
     *
     * @return array{0: string, 1: int} option markup and number of players
     */
    private function playerOptionList(bool $onlyBanned): array
    {
        $db = $this->db;
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;

        $ORDER = $this->state->requestQuery['order'] == 'id' ? "id" : "username";
        // $ORDER is one of two fixed column names, never the raw request value
        $qb = $db->qb()
            ->select('`username`', '`id`', '`bana`')
            ->from($db->table('USERS'))
            ->where('`id` != 1')
            ->andWhere('`authlevel` <= :authlevel')
            ->andWhere('`universe` = :universe')
            ->setParameter('authlevel', $USER['authlevel'])
            ->setParameter('universe', $this->universe()->getEmulated())
            ->orderBy($ORDER, 'ASC');
        if ($onlyBanned) {
            $qb->andWhere("`bana` = '1'");
        }
        $html = '';
        $count = 0;
        foreach ($db->fetchAll($qb) as $user) {
            $html .= '<option value="' . $user['id'] . '">' . GameEscaping::escape((string) $user['username'], 'UTF-8') . '&nbsp;&nbsp;(ID:&nbsp;' . $user['id'] . ')' . (($user['bana'] == '1') ? $LNG['bo_characters_suus'] : '') . '</option>';
            $count++;
        }
        return [$html, $count];
    }

    /**
     * Right list of the player ban page: all banned players of the emulated universe, sorted by `order2` (id or name).
     *
     * @return array{0: string, 1: int} option markup and number of banned players
     */
    private function bannedPlayerOptionList(): array
    {
        $db = $this->db;
        $ORDER2 = $this->state->requestQuery['order2'] == 'id' ? "id" : "username";

        $html = '';
        $count = 0;
        $userListBan = $db->fetchAll(
            $db->qb()
                ->select('`username`', '`id`')
                ->from($db->table('USERS'))
                ->where("`bana` = '1'")
                ->andWhere('`universe` = :universe')
                ->setParameter('universe', $this->universe()->getEmulated())
                ->orderBy($ORDER2, 'ASC'),
        );
        foreach ($userListBan as $user) {
            $html .= '<option value="' . $user['id'] . '">' . GameEscaping::escape((string) $user['username'], 'UTF-8') . '&nbsp;&nbsp;(ID:&nbsp;' . $user['id'] . ')</option>';
            $count++;
        }
        return [$html, $count];
    }

    /**
     * Left list of the account ban page (see playerOptionList()); accounts are not tied to a universe.
     *
     * @return array{0: string, 1: int} option markup and number of accounts
     */
    private function accountOptionList(bool $onlyBanned): array
    {
        $db = $this->db;
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;

        $ORDER = $this->state->requestQuery['order'] == 'id' ? "id" : "accountname";
        $qb = $db->qb()
            ->select('`accountname`', '`id`', '`bana`')
            ->from($db->table('ACCOUNTS'))
            ->where('`id` != 1')
            ->andWhere('`authlevel` <= :authlevel')
            ->setParameter('authlevel', $USER['authlevel'])
            ->orderBy($ORDER, 'ASC');
        if ($onlyBanned) {
            $qb->andWhere("`bana` = '1'");
        }
        $html = '';
        $count = 0;
        foreach ($db->fetchAll($qb) as $account) {
            $html .= '<option value="' . $account['id'] . '">' . GameEscaping::escape((string) $account['accountname'], 'UTF-8') . '&nbsp;&nbsp;(ID:&nbsp;' . $account['id'] . ')' . (($account['bana'] == '1') ? $LNG['bo_characters_suus'] : '') . '</option>';
            $count++;
        }
        return [$html, $count];
    }

    /**
     * Right list of the account ban page: all banned accounts, sorted by `order2` (id or name).
     *
     * @return array{0: string, 1: int} option markup and number of banned accounts
     */
    private function bannedAccountOptionList(): array
    {
        $db = $this->db;
        $ORDER2 = $this->state->requestQuery['order2'] == 'id' ? "id" : "accountname";

        $html = '';
        $count = 0;
        $accountListBan = $db->fetchAll(
            $db->qb()
                ->select('`accountname`', '`id`')
                ->from($db->table('ACCOUNTS'))
                ->where("`bana` = '1'")
                ->orderBy($ORDER2, 'ASC'),
        );
        foreach ($accountListBan as $account) {
            $html .= '<option value="' . $account['id'] . '">' . GameEscaping::escape((string) $account['accountname'], 'UTF-8') . '&nbsp;&nbsp;(ID:&nbsp;' . $account['id'] . ')</option>';
            $count++;
        }
        return [$html, $count];
    }

    /**
     * Assigns the ban form variables (title, current reason and end of a running ban) for a player or an account.
     *
     * @param array  $row       the USERS/ACCOUNTS row joined with the ban record (theme, longer, banaday)
     * @param mixed  $id        selected id (assigned as `$idKey`)
     * @param string $idKey     template variable of the id: `user_id` or `account_id`
     */
    private function assignBanPanel(array $row, mixed $id, string $name, string $idKey, bool $perma, bool $vacation): void
    {
        $LNG = $this->state->LNG;

        if ($row['banaday'] <= $this->state->runtime->timestamp) {
            $title = $LNG['bo_bbb_title_1'];
            $changedate = $LNG['bo_bbb_title_2'];
            $changedate_advert = '';
            $reas = '';
            $timesus = '';
        } else {
            $title = $LNG['bo_bbb_title_3'];
            $changedate = $LNG['bo_bbb_title_6'];
            $changedate_advert = $LNG['bo_bbb_title_4'];
            $reas = $row['theme'];
            $timesus
                = '<div class="field-row"><span class="field-label">' . $LNG['bo_bbb_title_5'] . '</span>'
                . '<div class="field-control"><strong>' . date($LNG['php_tdformat'], $row['longer']) . '</strong></div></div>';
        }

        $this->assign([
            $idKey              => $id,
            'name'              => $name,
            'bantitle'          => $title,
            'changedate'        => $changedate,
            'reas'              => $reas,
            'changedate_advert' => $changedate_advert,
            'timesus'           => new Markup($timesus, 'UTF-8'),
            'perma'             => $perma,
            'vacation'          => $vacation,
        ]);
    }

    /** Does the request carry one of the ban form actions (panel, ban, unban)? */
    private function isBanPost(): bool
    {
        $post = $this->state->requestPost;

        return isset($post['panel']) || isset($post['panel_change']) || isset($post['bannow']) || isset($post['unban_id']);
    }

    /** May the acting admin ban/unban the account: every player of it must be one they may act on (never themselves, user 1 or an equal/higher level). */
    private function mayActOnAccount(int $accountId): bool
    {
        $users = $this->db->select('SELECT `user_id` FROM %%ACCOUNTS_TO_USERS%% WHERE `account_id` = :id;', [':id' => $accountId]);
        foreach ($users as $row) {
            if (!$this->mayActOn((int) $row['user_id'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reads the posted ban form: target id, name, reason and the resulting end timestamp (permanent = 2147483647; else
     * now + the posted days/hours/minutes/seconds plus the rest of a still running, non-permanent ban).
     *
     * @param array $row the current ban row of the target (longer, banaday)
     * @return array{id: mixed, name: string, reason: string, until: int, admin: string}
     */
    private function readBanForm(array $row): array
    {
        $id = $row['id'];
        $Name = $this->http()->_GP('ban_name', '', true);
        $reas = $this->http()->_GP('why', '', true);
        $days = $this->http()->_GP('days', 0);
        $hour = $this->http()->_GP('hour', 0);
        $mins = $this->http()->_GP('mins', 0);
        $secs = $this->http()->_GP('secs', 0);
        $BanTime = $days * 86400 + $hour * 3600 + $mins * 60 + $secs;

        if ($row['longer'] > $this->state->runtime->timestamp && $row['banaday'] != 2147483647) {
            $BanTime += ($row['longer'] - $this->state->runtime->timestamp);
        }

        if (isset($this->state->requestPost['permanent'])) {
            $BannedUntil = 2147483647;
        } else {
            $BannedUntil = ($BanTime + $this->state->runtime->timestamp) < $this->state->runtime->timestamp ? $this->state->runtime->timestamp : $this->state->runtime->timestamp + $BanTime;
        }

        return [
            'id'     => $id,
            'name'   => $Name,
            'reason' => $reas,
            'until'  => $BannedUntil,
            'admin'  => $this->state->USER['username'],
        ];
    }

    /**
     * Bans one player: updates or inserts the USERS_BANNED record, sets the `bana`/`banaday` flags and optionally puts
     * the player in vacation mode (`vacat`). Ends with a confirmation message (the caller ends the request).
     */
    private function banPlayer(array $bannedUser): void
    {
        $db = $this->db;
        $LNG = $this->state->LNG;
        $form = $this->readBanForm($bannedUser);
        $id = $form['id'];
        $Name = $form['name'];
        $reas = $form['reason'];
        $admin = $form['admin'];
        $BannedUntil = $form['until'];
        $mail = ""; //$ACCOUNT['email'];

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
    }

    /**
     * Bans a login account and every player linked to it (ACCOUNTS_BANNED + one USERS_BANNED record per player of the
     * emulated universe, flags on both tables, optional vacation mode per player). Ends with a confirmation message.
     */
    private function banAccount(array $bannedAccount): void
    {
        $db = $this->db;
        $LNG = $this->state->LNG;
        $form = $this->readBanForm($bannedAccount);
        $id = $form['id'];
        $Name = $form['name'];
        $reas = $form['reason'];
        $admin = $form['admin'];
        $BannedUntil = $form['until'];
        $mail = "";//$ACCOUNT['email'];

        $sql = "SELECT au.user_id AS id, u.username, u.universe 
                    FROM %%ACCOUNTS_TO_USERS%% au 
                    JOIN %%USERS%% u ON u.`id` = au.`user_id` 
                    WHERE au.`account_id` = :account_id;";
        $users = $db->select($sql, [':account_id' => $id]);

        $this->storeAccountBanRecords($bannedAccount, $users, $id, $Name, $reas, $admin, $BannedUntil, $mail);

        $this->flagBannedAccount($id, $users, $BannedUntil);
        $this->sessions()->revokeAccountSessions((int) $id);

        $this->message($LNG['bo_the_player'] . $Name . $LNG['bo_banned'], '?page=ban');
    }

    /**
     * Writes the ban records of an account ban: update of the running ban or insert of a new one in ACCOUNTS_BANNED and
     * one USERS_BANNED record per linked player of the emulated universe.
     *
     * @param array $bannedAccount the account row joined with its ban (banaday)
     * @param array $users linked players (id, username)
     */
    private function storeAccountBanRecords(array $bannedAccount, array $users, mixed $id, mixed $Name, mixed $reas, mixed $admin, mixed $BannedUntil, string $mail): void
    {
        $db = $this->db;

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
                    ':universe' => $user['universe'],
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
                    ':universe' => $user['universe'],
                    ':mail'     => $mail,
                ]);
            }
        }
    }

    /**
     * Sets the `bana`/`banaday` flags on the account and its players and, with `vacat`, puts each player in vacation
     * mode.
     *
     * @param array $users linked players (id, username)
     */
    private function flagBannedAccount(mixed $id, array $users, mixed $BannedUntil): void
    {
        $db = $this->db;

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
                ':universe'    => $user['universe'],
            ]);
            if (isset($this->state->requestPost['vacat'])) {
                $planetDummy = [];
                $this->playerUtil()->enable_vmode($user, $planetDummy, true);
            }
        }
    }
}
