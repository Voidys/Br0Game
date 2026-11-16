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
use App\Game\Core\Config;
use App\Game\Core\Singleton;

/**
 * Game rules page.
 */
#[\App\Routing\GamePage('game.php', 'rules')]
#[\App\Routing\GamePage('game.php', 'rules', uniPrefix: true)]
class RulesController extends AbstractGameController
{
    public static $requireModule = 0;

    public function initialize(): void
    {
        parent::initialize();
    }

    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $this->assign([
            // templates/texts/<lang>/rules.txt is trusted HTML shipped with the code; the link is escaped
            'rules'		=> new \Twig\Markup(sprintf($LNG->getTemplate('rules'), htmlspecialchars((string) $this->configs->get()->git_issues_link, ENT_QUOTES, double_encode: false)), 'UTF-8'),
        ]);

        return $this->render('game/page.rules.default.html.twig');
    }
}
