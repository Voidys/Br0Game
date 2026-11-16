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
use Parsedown;

/** Changelog page: renders CHANGES.md. */
#[\App\Routing\GamePage('game.php', 'changelog')]
#[\App\Routing\GamePage('game.php', 'changelog', uniPrefix: true)]
class ChangelogController extends AbstractGameController
{
    public static $requireModule = 0;

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Renders CHANGES.md as the changelog page.
     */
    public function show(): Response
    {
        $parsedown = new Parsedown();

        $this->assign([
            // CHANGES.md is part of the code base: its Markdown rendering is trusted HTML
            'ChangelogList'	=> new \Twig\Markup($parsedown->text(file_get_contents($this->state->runtime->rootPath . 'CHANGES.md')), 'UTF-8'),
        ]);

        return $this->render('game/page.changelog.default.html.twig');
    }
}
