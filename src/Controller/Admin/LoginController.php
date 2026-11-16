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

use Exception;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin password prompt: grants admin access to the session after re-entering the account password.
 * The password is only read from a POST body (never from the URL), attempts are limited per IP and
 * account, and the session id is renewed when the access is granted (session fixation).
 */
#[\App\Routing\GamePage('admin.php', 'login')]
#[\App\Routing\GamePage('admin.php', 'login', uniPrefix: true)]
class LoginController extends AbstractAdminController
{
    protected function construct(): void
    {
        $USER = $this->state->USER;
        if ($USER['authlevel'] == AUTH_USR) {
            throw new Exception($this->state->LNG['adm_permission_error']);
        }

        parent::construct();
    }

    public function show(): Response
    {
        $USER = $this->state->USER;
        $accountId = $this->playerUtil()->get_account_id($USER['id']);
        $account = $this->accountUtil()->get_account($accountId);

        $session = $this->sessions()->create();
        $session->universe = $USER['universe'];
        if ($session->adminAccess == 1) {
            $this->http()->redirectTo('admin');
        }

        if (isset($this->state->requestPost['admin_pw']) && is_string($this->state->requestPost['admin_pw'])) {
            if (!$this->rateLimits->allowAdminElevation($this->sessions()->getClientIp(), (int) $accountId)) {
                $this->message($this->state->LNG['adm_login_throttled'], 'admin?page=login', 5, true);
            }
            if (password_verify($this->state->requestPost['admin_pw'], $account['password'])) {
                $session->adminAccess = 1;
                $this->sessions()->regenerateId();
                $this->http()->redirectTo('admin');
            }
        }

        $this->assign([
            'bodyclass'    => 'standalone',
            'email'        => $account['email'],
        ]);
        return $this->render('admin/LoginPage.html.twig');
    }
}
