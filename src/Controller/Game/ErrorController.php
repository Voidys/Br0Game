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
 * Error page of the game area: shows a message inside the game layout and ends the request.
 */
#[\App\Routing\GamePage('game.php', 'error')]
#[\App\Routing\GamePage('game.php', 'error', uniPrefix: true)]
class ErrorController extends AbstractGameController
{
    public static $requireModule = 0;

    protected $disableEcoSystem = true;

    public function initialize(): void
    {
        parent::initialize();
    }

    /** the area's error page with a message (ends the request) */
    public function showError($Message, $fullSide = true, $redirect = null)
    {
        $this->initialize();
        $this->printMessage($Message, null, $redirect, $fullSide);
    }

    public function show() {}
}
