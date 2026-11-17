<?php

/**
 * pr0game powered by steemnova
 * Accounts
 * (c) 2024 reflexrecon
 */

namespace App\Controller\Account;

/** Logs the account out and shows the goodbye page. */
#[\App\Routing\GamePage('account', 'logout')]
class LogoutController extends AbstractAccountController
{
    use \App\Controller\Shared\LogoutPage;
}
