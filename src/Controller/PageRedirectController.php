<?php

namespace App\Controller;

use App\Routing\GamePage;
use Symfony\Component\HttpFoundation\RedirectResponse;

/** Old page URLs that moved: the board (the external forum is replaced by the built-in board of the account area) and the rules. */
final class PageRedirectController
{
    #[GamePage('index', 'board')]
    #[GamePage('game', 'board')]
    public function board(): RedirectResponse
    {
        return new RedirectResponse('account?page=board');
    }

    #[GamePage('index', 'rules')]
    public function rules(): RedirectResponse
    {
        return new RedirectResponse('/rules', 308);
    }
}
