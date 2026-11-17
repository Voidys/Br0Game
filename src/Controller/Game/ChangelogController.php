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
     * Renders CHANGES.md as the changelog page: the intro text and one entry per "## " heading, newest first (the file lists the newest
     * last), each as a collapsible section.
     */
    #[GameRoute('game', 'game/changelog', 'changelog')]
    public function show(): Response
    {
        $parsedown = new Parsedown();
        $markdown = (string) file_get_contents($this->state->runtime->rootPath . 'CHANGES.md');

        // everything before the first "## " heading is the title and the intro
        $parts = preg_split('/^(?=## )/m', $markdown) ?: [];
        $intro = preg_replace('/^# .*\R+/', '', (string) array_shift($parts));

        $entries = [];
        foreach ($parts as $part) {
            [$heading, $body] = array_pad(explode("\n", $part, 2), 2, '');
            $title = trim(substr($heading, 3));
            $commit = '';
            if (preg_match('/^(.*?)\s*\(`([0-9a-f]{6,40})`\)\s*$/', $title, $match)) {
                [, $title, $commit] = $match;
            }
            $entries[] = [
                'title' => new \Twig\Markup($parsedown->line($title), 'UTF-8'),
                'commit' => $commit,
                'body' => new \Twig\Markup($parsedown->text(trim($body)), 'UTF-8'),
            ];
        }

        return $this->render('game/page.changelog.default.html.twig', [
            // CHANGES.md is part of the code base: its Markdown rendering is trusted HTML
            'ChangelogIntro' => new \Twig\Markup($parsedown->text(trim((string) $intro)), 'UTF-8'),
            'ChangelogEntries' => array_reverse($entries),
        ]);
    }
}
