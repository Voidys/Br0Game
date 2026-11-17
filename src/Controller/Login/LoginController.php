<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Login;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Login target: the form POST is handled by Symfony Security; a direct visit is sent back to the start page. */
class LoginController extends AbstractLoginController
{
    /**
     * The login form is posted here (route login_login_send), but Symfony Security handles that POST before the
     * controller (App\Security\LoginFormAuthenticator: password check, failed-login counter, verification link).
     * Only a direct visit of /login ends up here.
     */
    #[GameRoute('index', 'login', 'login')]
    #[GameRoute('index', 'login', 'login', 'send', ['POST'])]
    public function show(): Response
    {
        return $this->redirectResponse('./');
    }
}
