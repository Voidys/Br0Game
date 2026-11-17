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
use App\Game\Core\Session;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Leaves the admin area: drops admin access from the session and returns to the game. */
class LogoutController extends AbstractAdminController
{
    /**
     * No page right is required to leave the admin area.
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
     * Drops admin access from the session and redirects to /game.
     */
    #[GameRoute('admin', 'admin/logout', 'logout', 'show', ['POST'])]
    public function show(): Response
    {
        $session = $this->sessions()->create();
        $session->adminAccess = 0;
        // back to the game (the game's "drop admin rights" banner calls this with fetch and ignores the answer)
        return $this->redirectResponse('game');
    }
}
