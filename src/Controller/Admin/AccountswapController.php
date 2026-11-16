<?php

namespace App\Controller\Admin;

use Exception;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;
use Symfony\Component\HttpFoundation\Response;
use App\Twig\GameEscaping;
use Twig\Markup;

/** Swaps the login accounts of two players. */
#[\App\Routing\GamePage('admin.php', 'accountswap')]
#[\App\Routing\GamePage('admin.php', 'accountswap', uniPrefix: true)]
class AccountswapController extends AbstractAdminController
{
    /**
     * Swaps the login accounts of two players of the universe. action=swap with user1 and user2 (player ids, must differ and both
     * have an ACCOUNTS_TO_USERS row): exchanges their account_id in one UPDATE. Without action it renders the two player pickers
     * (users up to the acting admin's authlevel).
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $db = $this->db;

        $action = $this->http()->_GP('action', '');
        if ($action === 'swap') {
            $user1 = $this->http()->_GP('user1', 0);
            $user2 = $this->http()->_GP('user2', 0);

            if (empty($user1) || empty($user2) || $user1 == $user2) {
                $this->message($LNG['ac_swap_invalid']);
            }

            // both players of the emulated universe, never user 1 or a player of the same or a higher level (own player only for admins)
            $inUniverse = (int) $db->selectSingle(
                'SELECT COUNT(*) AS c FROM %%USERS%% WHERE `id` IN (:u1, :u2) AND `universe` = :universe;',
                [':u1' => $user1, ':u2' => $user2, ':universe' => $this->universe()->getEmulated()],
                'c',
            );
            if ($inUniverse !== 2 || !$this->mayActOn((int) $user1, $USER['authlevel'] == AUTH_ADM) || !$this->mayActOn((int) $user2, $USER['authlevel'] == AUTH_ADM)) {
                $this->message($LNG['ac_swap_invalid']);
            }

            $acc1 = $db->selectSingle('SELECT `account_id` FROM %%ACCOUNTS_TO_USERS%% WHERE `user_id` = :userID;', [':userID' => $user1]);
            $acc2 = $db->selectSingle('SELECT `account_id` FROM %%ACCOUNTS_TO_USERS%% WHERE `user_id` = :userID;', [':userID' => $user2]);

            if (empty($acc1) || empty($acc2)) {
                $this->message($LNG['ac_swap_no_entry']);
            }

            $sql = 'UPDATE %%ACCOUNTS_TO_USERS%% SET `account_id` = CASE `user_id` WHEN :u1 THEN :a2 WHEN :u2 THEN :a1 END WHERE `user_id` IN (:u1, :u2);';
            $db->update($sql, [
                ':u1' => $user1,
                ':u2' => $user2,
                ':a1' => $acc1['account_id'],
                ':a2' => $acc2['account_id'],
            ]);

            $this->message($LNG['ac_swap_success'], '?page=accountswap');
            throw new \App\Game\Core\EndOfRequest();
        }

        $sql = 'SELECT `id`, `username`, `authlevel` FROM %%USERS%% WHERE `authlevel` <= :userLevel AND `universe` = :universe ORDER BY `username` ASC;';
        $UserQuerry = $db->select($sql, [
            ':userLevel' => $USER['authlevel'],
            ':universe'  => $this->universe()->getEmulated(),
        ]);

        $Userlist = '';
        foreach ($UserQuerry as $User) {
            $Userlist .= sprintf('<option value="%d">%s&nbsp;&nbsp;(%s)</option>', $User['id'], GameEscaping::escape((string) $User['username'], 'UTF-8'), $LNG['se_rank'][$User['authlevel']]);
        }

        $this->assign([
            'Userlist' => new Markup($Userlist, 'UTF-8'),
        ]);
        return $this->render('admin/AccountSwapPage.html.twig');
    }
}
