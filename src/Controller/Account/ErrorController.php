<?php

/**
 * pr0game powered by steemnova
 * Accounts
 * (c) 2024 reflexrecon
 */

namespace App\Controller\Account;

use App\Game\Core\Singleton;

/** Error page of the account area. */
#[\App\Routing\GamePage('account.php', 'error')]
#[\App\Routing\GamePage('account.php', 'error', uniPrefix: true)]
class ErrorController extends AbstractAccountController
{
    public static $requireModule = 0;

    protected $disableEcoSystem = true;

    public function initialize(): void
    {
        parent::initialize();
        $LNG		= $this->state->LNG;
        $ACCOUNT	= $this->state->ACCOUNT;
        $this->assign([
            'id'                => $ACCOUNT['id'],
            'username'          => $ACCOUNT['accountname'],
            'authlevel'         => $ACCOUNT['authlevel'],
            'toplink'           => "account?page=account",
            'topname'           => $LNG->format('Back'),
        ]);
    }

    /** the area's error page with a message (ends the request) */
    public function showError($Message, $fullSide = true, $redirect = null)
    {
        $this->initialize();
        $this->printMessage($Message, null, $redirect, $fullSide);
    }

    public function show() {}
}
