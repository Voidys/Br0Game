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

use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Session;

/** Login target: the form POST is handled by Symfony Security; a direct visit is sent back to the start page. */
#[\App\Routing\GamePage('index.php', 'login')]
#[\App\Routing\GamePage('index.php', 'login', uniPrefix: true)]
class LoginController extends AbstractLoginController
{
    public static $requireModule = 0;

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * The login form is posted here, but Symfony Security handles that POST before the controller
     * (App\Security\LoginFormAuthenticator: password check, failed-login counter, verification link).
     * Only a direct visit of ?page=login ends up here.
     */
    public function show()
    {
        $this->http()->redirectTo('./');
    }
}
