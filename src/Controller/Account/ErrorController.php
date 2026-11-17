<?php

/**
 * pr0game powered by steemnova
 * Accounts
 * (c) 2024 reflexrecon
 */

namespace App\Controller\Account;

/** Error page of the account area. */
class ErrorController extends AbstractAccountController
{
    use \App\Controller\Shared\ErrorResponsePage;
    protected $disableEcoSystem = true;

    /**
     * No account checks: the error page must work for guests too.
     */
    public function initialize(): void
    {
        parent::initialize();
        $LNG		= $this->state->LNG;
        $ACCOUNT	= $this->state->ACCOUNT;
        $this->assign([
            'id'                => $ACCOUNT['id'],
            'username'          => $ACCOUNT['accountname'],
            'authlevel'         => $ACCOUNT['authlevel'],
            'toplink'           => $this->router->generate('account_account_show'),
            'topname'           => $LNG->format('Back'),
        ]);
    }
}
