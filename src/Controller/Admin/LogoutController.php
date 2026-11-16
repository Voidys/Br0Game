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
use App\Game\Core\Session;
use App\Game\Core\Singleton;

/** Leaves the admin area: drops admin access from the session and returns to the game. */
#[\App\Routing\GamePage('admin.php', 'logout')]
#[\App\Routing\GamePage('admin.php', 'logout', uniPrefix: true)]
class LogoutController extends AbstractAdminController
{
    protected function construct(): void
    {
        $USER = $this->state->USER;
        if ($USER['authlevel'] == AUTH_USR) {
            throw new Exception($this->state->LNG['adm_permission_error']);
        }

        parent::construct();
    }

    public function show(): void
    {
        $session = $this->sessions()->create();
        $session->adminAccess = 0;
        // back to the game (the game's "drop admin rights" banner calls this with fetch and ignores the answer)
        $this->http()->redirectTo('game');
    }
}
