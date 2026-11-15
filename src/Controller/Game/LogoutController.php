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

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Session;

/**
 * Logs the player out and shows the logout popup.
 */
#[\App\Routing\GamePage('game.php', 'logout')]
#[\App\Routing\GamePage('game.php', 'logout', uniPrefix: true)]
class LogoutController extends AbstractGameController
{
    public static $requireModule = 0;

    public function initialize(): void
    {
        parent::initialize();
        $this->setWindow('popup');
    }

    public function show(): Response
    {
        $this->state->auth->logout();
        return $this->render('game/page.logout.default.html.twig');
    }
}
