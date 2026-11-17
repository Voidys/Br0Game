<?php

namespace App\Controller\Admin;

use App\Game\Enum\AuthLevel;
use App\Game\Core\Universe;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use App\Twig\GameEscaping;
use Twig\Markup;

/** Swaps the login accounts of two players. */
class AccountswapController extends AbstractAdminController
{
    /**
     * Renders the two player pickers (users up to the acting admin's authlevel).
     */
    #[GameRoute('admin', 'admin/account-swap', 'accountSwap')]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $db = $this->db;

        $sql = 'SELECT `id`, `username`, `authlevel` FROM %%USERS%% WHERE `authlevel` <= :userLevel AND `universe` = :universe ORDER BY `username` ASC;';
        $UserQuerry = $db->select($sql, [
            ':userLevel' => $USER['authlevel'],
            ':universe'  => $this->universe()->getEmulated(),
        ]);

        $Userlist = '';
        foreach ($UserQuerry as $User) {
            $Userlist .= sprintf('<option value="%d">%s&nbsp;&nbsp;(%s)</option>', $User['id'], GameEscaping::escape((string) $User['username'], 'UTF-8'), $LNG['se_rank'][$User['authlevel']]);
        }

        return $this->render('admin/AccountSwapPage.html.twig', [
            'Userlist' => new Markup($Userlist, 'UTF-8'),
        ]);
    }

    /**
     * Swaps the login accounts of two players of the universe. POST user1 and user2 (player ids, must differ and both
     * have an ACCOUNTS_TO_USERS row): exchanges their account_id in one UPDATE.
     */
    #[GameRoute('admin', 'admin/account-swap', 'accountSwap', 'swap', ['POST'])]
    public function swap(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $db = $this->db;

        $user1 = $this->input()->int('user1');
        $user2 = $this->input()->int('user2');

        if (empty($user1) || empty($user2) || $user1 == $user2) {
            return $this->messageResponse($LNG['ac_swap_invalid']);
        }

        // both players of the emulated universe, never user 1 or a player of the same or a higher level (own player only for admins)
        $inUniverse = (int) $db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%USERS%% WHERE `id` IN (:u1, :u2) AND `universe` = :universe;',
            [':u1' => $user1, ':u2' => $user2, ':universe' => $this->universe()->getEmulated()],
            'c',
        );
        if ($inUniverse !== 2 || !$this->mayActOn((int) $user1, $USER['authlevel'] == AuthLevel::Admin->value) || !$this->mayActOn((int) $user2, $USER['authlevel'] == AuthLevel::Admin->value)) {
            return $this->messageResponse($LNG['ac_swap_invalid']);
        }

        $acc1 = $db->selectSingle('SELECT `account_id` FROM %%ACCOUNTS_TO_USERS%% WHERE `user_id` = :userID;', [':userID' => $user1]);
        $acc2 = $db->selectSingle('SELECT `account_id` FROM %%ACCOUNTS_TO_USERS%% WHERE `user_id` = :userID;', [':userID' => $user2]);

        if (empty($acc1) || empty($acc2)) {
            return $this->messageResponse($LNG['ac_swap_no_entry']);
        }

        $sql = 'UPDATE %%ACCOUNTS_TO_USERS%% SET `account_id` = CASE `user_id` WHEN :u1 THEN :a2 WHEN :u2 THEN :a1 END WHERE `user_id` IN (:u1, :u2);';
        $db->update($sql, [
            ':u1' => $user1,
            ':u2' => $user2,
            ':a1' => $acc1['account_id'],
            ':a2' => $acc2['account_id'],
        ]);

        return $this->messageResponse($LNG['ac_swap_success'], $this->router->generate('admin_account_swap_show'));
    }
}
