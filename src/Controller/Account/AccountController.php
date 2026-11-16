<?php

/**
 * pr0game powered by steemnova
 * Accounts
 * (c) 2024 reflexrecon
 */

namespace App\Controller\Account;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\AccountUtil;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Session;
use App\Game\Core\Singleton;

/** Account lobby: lists the universes with the account's players and enters one (modes: show, send). */
#[\App\Routing\GamePage('account.php', 'account', default: true)]
#[\App\Routing\GamePage('account.php', 'account', default: true, uniPrefix: true)]
class AccountController extends AbstractAccountController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Account lobby (/account?page=account): lists the universes the account has a player in and the ones it can still register in.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $LNG->includeData(['INGAME', 'ACCOUNT']);
        $ACCOUNT = $this->state->ACCOUNT;
        $newUniverses = $this->accountUtil()->get_non_player_universes($ACCOUNT['id']);
        $playerUniverses = $this->accountUtil()->get_player_universes($ACCOUNT['id']);
        $this->assign([
            'id'                => $ACCOUNT['id'],
            'username'          => $ACCOUNT['accountname'],
            'authlevel'         => $ACCOUNT['authlevel'],
            'newUniverses'      => $newUniverses,
            'playerUniverses'   => $playerUniverses,
            'toplink'           => "account?page=accountSettings",
            'topname'           => $LNG->format('Settings'),
        ]);

        return $this->render('account/page.account.default.html.twig');
    }

    /**
     * Enters a universe (mode=send): POST userID (player id); the universe comes from the player.
     * Checks that the player belongs to the logged in account (ACCOUNTS_TO_USERS), switches the session to that player and universe
     * (admin access is dropped) and redirects to /game. A foreign player id redirects back to the lobby.
     */
    public function send()
    {
        $ACCOUNT = $this->state->ACCOUNT;
        $userID = $this->http()->_GP('userID', 0);

        $db = $this->db;
        // the universe is the one the player lives in (the request's `universe` is not trusted)
        $sql = "SELECT u.`universe` FROM %%ACCOUNTS_TO_USERS%% au INNER JOIN %%USERS%% u ON u.`id` = au.`user_id` WHERE au.`account_id` = :accountId AND au.`user_id` = :userId AND au.`end_time` = 0;";
        $universe = $db->selectSingle($sql, [':accountId' => $ACCOUNT['id'], ':userId' => $userID], 'universe');

        if (empty($universe)) {
            $this->http()->redirectTo('account?page=account');
        }

        $session	= $this->sessions()->create();
        $session->userId		= $userID;
        $session->universe      = $universe;
        $session->planetId      = 0;
        $session->adminAccess	= 0;
        $session->save();

        $this->http()->redirectTo('game');
    }
}
