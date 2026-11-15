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

/**
 * Redirect to the built-in board of the account area.
 */
#[\App\Routing\GamePage('game.php', 'board')]
#[\App\Routing\GamePage('game.php', 'board', uniPrefix: true)]
class BoardController extends AbstractGameController
{
    public static $requireModule = 0;

    public function initialize(): void
    {
        parent::initialize();
    }

    /** the external forum is replaced by the built-in board (account area) */
    public function show()
    {
        $this->http()->redirectTo('account?page=board');
    }
}
