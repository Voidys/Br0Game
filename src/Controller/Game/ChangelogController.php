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
use Parsedown;
use Symfony\Component\HttpFoundation\Response;

/** Changelog page: renders CHANGES.md. */
class ChangelogController extends AbstractGameController
{
    /**
     * Renders CHANGES.md as the changelog page.
     */
    #[GameRoute('game', 'game/changelog', 'changelog')]
    public function show(): Response
    {
        $parsedown = new Parsedown();

        return $this->render('game/page.changelog.default.html.twig', [
            // CHANGES.md is part of the code base: its Markdown rendering is trusted HTML
            'ChangelogList'	=> new \Twig\Markup($parsedown->text(file_get_contents($this->state->runtime->rootPath . 'CHANGES.md')), 'UTF-8'),
        ]);
    }
}
