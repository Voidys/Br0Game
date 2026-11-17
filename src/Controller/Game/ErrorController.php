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
 * Error page of the game area: shows a message inside the game layout (no route of its own: built with `new` by the entry fallbacks).
 */
class ErrorController extends AbstractGameController
{
    use \App\Controller\Shared\ErrorResponsePage;
    protected $disableEcoSystem = true;
}
