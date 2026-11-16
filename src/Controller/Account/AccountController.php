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

    public function send()
    {
        $ACCOUNT = $this->state->ACCOUNT;
        $userID = $this->http()->_GP('userID', 0);
        $universe = $this->http()->_GP('universe', 0);

        $db = $this->db;
        $sql = "SELECT count(*) as count FROM %%ACCOUNTS_TO_USERS%% WHERE `account_id` = :accountId && user_id = :userId;";
        $count = $db->selectSingle($sql, [':accountId' => $ACCOUNT['id'], ':userId' => $userID], 'count');

        if ($count == 0) {
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
