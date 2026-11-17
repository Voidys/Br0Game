<?php

/**
 * pr0game powered by steemnova
 * Accounts
 * (c) 2024 reflexrecon
 */

namespace App\Controller\Account;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Logs the account out and shows the goodbye page. */
class LogoutController extends AbstractAccountController
{
    use \App\Controller\Shared\LogoutPage {
        show as protected renderLogout;
    }

    #[GameRoute('account', 'account/logout', 'logout', methods: ['GET', 'POST'])]
    public function show(): Response
    {
        return $this->renderLogout();
    }
}
