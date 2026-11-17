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

namespace App\Controller\Game;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs the player out and shows the logout popup.
 */
class LogoutController extends AbstractGameController
{
    use \App\Controller\Shared\LogoutPage {
        show as protected renderLogout;
    }

    #[GameRoute('game', 'game/logout', 'logout', methods: ['GET', 'POST'])]
    public function show(): Response
    {
        return $this->renderLogout();
    }
}
