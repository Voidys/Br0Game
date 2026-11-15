<?php

/**
 * pr0game powered by steemnova
 * Accounts
 * (c) 2024 reflexrecon
 */

namespace App\Controller\Account;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Session;

/** Logs the account out and shows the goodbye page. */
#[\App\Routing\GamePage('account.php', 'logout')]
#[\App\Routing\GamePage('account.php', 'logout', uniPrefix: true)]
class LogoutController extends AbstractAccountController
{
    public static $requireModule = 0;

    public function initialize(): void
    {
        parent::initialize();
        $this->setWindow('popup');
    }

    public function show(): Response
    {
        $this->state->auth->logout();
        return $this->render('account/page.logout.default.html.twig');
    }
}
