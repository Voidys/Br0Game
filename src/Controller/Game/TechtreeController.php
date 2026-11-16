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
use App\Game\Core\TechTree;

/** Technology tree: requirements of every building, research, ship and defence. */
#[\App\Routing\GamePage('game.php', 'techtree')]
#[\App\Routing\GamePage('game.php', 'techtree', uniPrefix: true)]
class TechtreeController extends AbstractGameController
{
    public static $requireModule = 'MODULE_TECHTREE';

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Technology tree: a tab per category (buildings, research, ships, defence, missiles) with the elements and their
     * requirement chain as a graph; the player's levels come from the current planet, or from the user for research.
     */
    public function show(): Response
    {
        $elements = $this->elements();
        $LNG = $this->state->LNG;

        $groups = [];
        $levels = [];
        $names = [];
        foreach (array_keys(TechTree::HEADLINES) as $group) {
            $groups[$group] = array_values(array_filter(
                $elements->ids($group),
                fn($id) => $elements->has((int) $id) && $this->buildFunctions()->isEnabled((int) $id),
            ));
            foreach ($groups[$group] as $id) {
                $names[$id] = $LNG['tech'][$id] ?? (string) $id;
                $column = $elements->column($id);
                $levels[$id] = $this->state->PLANET[$column] ?? $this->state->USER[$column] ?? 0;
            }
        }

        $this->assign([
            'TechTree' => (new TechTree())->build($groups, $this->state->requeriments, $levels, $names),
            'Headlines' => array_map(static fn(int $id): string => $LNG['tech'][$id] ?? '', TechTree::HEADLINES),
        ]);

        return $this->render('game/page.techTree.default.html.twig');
    }
}
