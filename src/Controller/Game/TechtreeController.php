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
use App\Game\Core\BuildFunctions;
use App\Game\Core\Singleton;

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

    public function show(): Response
    {
        $resource = $this->state->resource;
        $requeriments = $this->state->requeriments;
        $reslist = $this->state->reslist;
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        $elementIDs		= array_merge(
            [0],
            $reslist['build'],
            [100],
            $reslist['tech'],
            [200],
            $reslist['fleet'],
            [400],
            $reslist['defense'],
            [500],
            $reslist['missile'],
        );

        $techTreeList = [];
        foreach ($elementIDs as $elementId) {
            if (!$this->buildFunctions()->isEnabled($elementId)) {
                continue;
            } elseif (!isset($resource[$elementId])) {
                $techTreeList[$elementId]	= $elementId;
            } else {
                $requirementsList	= [];
                if (isset($requeriments[$elementId])) {
                    foreach ($requeriments[$elementId] as $requireID => $RedCount) {
                        $requirementsList[$requireID]	= [
                            'count' => $RedCount,
                            'own'   => isset($PLANET[$resource[$requireID]]) ? $PLANET[$resource[$requireID]] : $USER[$resource[$requireID]],
                        ];
                    }
                }

                $techTreeList[$elementId]	= $requirementsList;
            }
        }

        $this->assign([
            'TechTreeList'		=> $techTreeList,
        ]);

        return $this->render('game/page.techTree.default.html.twig');
    }
}
