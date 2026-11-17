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

namespace App\Controller\Admin;

use App\Game\Enum\AuthLevel;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin password prompt: grants admin access to the session after re-entering the account password.
 * The password is only read from a POST body (never from the URL), attempts are limited per IP and
 * account, and the session id is renewed when the access is granted (session fixation).
 */
class LoginController extends AbstractAdminController
{
    /**
     * Runs the base permission check for the login page only (the page itself sets up admin access).
     */
    protected function construct(): void
    {
        $USER = $this->state->USER;
        if ($USER['authlevel'] == AuthLevel::User->value) {
            $this->deny();
        }

        parent::construct();
    }

    /**
     * The admin password prompt (shown at any admin URL until the session has admin access); with admin access it
     * redirects to /admin.
     */
    #[GameRoute('admin', 'admin/login', 'login')]
    public function show(): Response
    {
        $USER = $this->state->USER;
        $accountId = $this->playerLookup()->get_account_id($USER['id']);
        $account = $this->accountUtil()->get_account($accountId);

        $session = $this->sessions()->create();
        $session->universe = $USER['universe'];
        if ($session->adminAccess == 1) {
            return $this->redirectToRoute('admin_index_show');
        }

        return $this->render('admin/LoginPage.html.twig', [
            'bodyclass'    => 'standalone',
            'email'        => $account['email'],
        ]);
    }

    /**
     * POST admin_pw is checked against the account password (rate limited, failures are throttled and answered
     * with a message); on success admin access is stored in the session and the request redirects to /admin, a wrong
     * password shows the prompt again.
     */
    #[GameRoute('admin', 'admin/login', 'login', 'send', ['POST'])]
    public function send(): Response
    {
        $USER = $this->state->USER;
        $accountId = $this->playerLookup()->get_account_id($USER['id']);
        $account = $this->accountUtil()->get_account($accountId);

        $session = $this->sessions()->create();
        $session->universe = $USER['universe'];
        if ($session->adminAccess == 1) {
            return $this->redirectToRoute('admin_index_show');
        }

        if (isset($this->state->requestPost['admin_pw']) && is_string($this->state->requestPost['admin_pw'])) {
            if (!$this->rateLimits->allowAdminElevation($this->sessions()->getClientIp(), (int) $accountId)) {
                return $this->messageResponse($this->state->LNG['adm_login_throttled'], $this->router->generate('admin_login_show'), 5, true);
            }
            if (\App\Security\PasswordSupport::verifyAny($this->state->requestPost['admin_pw'], $account['password'])) {
                $this->rateLimits->clearAdminElevation($this->sessions()->getClientIp(), (int) $accountId);
                $session->adminAccess = 1;
                $this->sessions()->regenerateId();

                return $this->redirectToRoute('admin_index_show');
            }
        }

        return $this->show();
    }
}
