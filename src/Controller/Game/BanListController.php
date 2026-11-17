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

/**
 * List of banned players.
 */
#[\App\Routing\GamePage('game', 'banList')]
#[\App\Controller\RequireModule('MODULE_BANLIST')]
class BanListController extends AbstractGameController
{
    use \App\Controller\Shared\BanListPage;

    /**
     * Ban list of the universe; start and end of the bans in the player timezone.
     */
    public function show(): Response
    {
        $this->assign($this->banListData($this->state->USER['timezone']));

        return $this->render('game/page.banList.default.html.twig');
    }
}
