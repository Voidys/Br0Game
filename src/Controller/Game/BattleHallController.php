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
use App\Game\Core\BattleHallFilter;
use App\Game\Core\Config;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;

/**
 * Hall of fame of the strongest battles (battle hall) with its filter.
 */
#[\App\Routing\GamePage('game', 'battleHall')]
#[\App\Controller\RequireModule('MODULE_BATTLEHALL')]
class BattleHallController extends AbstractGameController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Builds the filter dropdowns (memorial, time frame, diplomacy, galaxy) of the battle hall.
     */
    private function assembleSelectors()
    {
        $LNG = $this->state->LNG;

        $Selectors = [];
        $Selectors['memorial'] = [
            BattleHallFilter::MEMORIAL_ALL => $LNG['tkb_all'],
            BattleHallFilter::MEMORIAL_EXCLUDE => $LNG['tkb_exclude'],
            BattleHallFilter::MEMORIAL_ONLY => $LNG['tkb_only'],
        ];

        $Selectors['timeframe'] = [
            BattleHallFilter::TIMEFRAME_ALL => $LNG['tkb_all'],
            BattleHallFilter::TIMEFRAME_24H => $LNG['tkb_day'],
            BattleHallFilter::TIMEFRAME_1WK => $LNG['tkb_week'],
            BattleHallFilter::TIMEFRAME_1MTH => $LNG['tkb_month'],
        ];

        $Selectors['diplomacy'] = [
            BattleHallFilter::DIPLOMACY_ALL => $LNG['tkb_all'],
            BattleHallFilter::DIPLOMACY_ALLIANCE => $LNG['pl_ally'],
            BattleHallFilter::DIPLOMACY_SELF => $LNG['tkb_self'],
        ];

        $maxgala = $this->configs->get()->max_galaxy;

        $Selectors['galaxy'] = [
            0 => $LNG['tkb_all'],
        ];

        for ($curgala = 1; $curgala <= $maxgala; $curgala++) {
            $Selectors['galaxy'][$curgala] = $curgala;
        }

        return $Selectors;
    }

    /**
     * Hall of fame of the biggest battles with filters. Request: memorial (1 memorial battles only), timeframe, diplomacy, galaxy.
     */
    public function show(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;


        $memorial = $this->http()->_GP('memorial', 1);
        $timeframe = $this->http()->_GP('timeframe', 0);
        $diplomacy = $this->http()->_GP('diplomacy', 0);
        $galaxy = $this->http()->_GP('galaxy', 0);

        $filters = [
            'memorial' => $memorial,
            'timeframe' => $timeframe,
            'diplomacy' => $diplomacy,
            'galaxy' => $galaxy,
        ];

        $pBHFilter = new BattleHallFilter($this->db, $this->configs, $this->state, $filters);
        $top = $pBHFilter->getTopKBs();

        $TopKBList	= [];
        foreach ($top as $data) {
            $TopKBList[]	= [
                'result'	=> $data['result'],
                'date'		=> $this->viewHelpers()->formatDate($LNG['php_tdformat'], $data['time'], $USER['timezone']),
                'time'		=> $this->state->runtime->timestamp - $data['time'],
                'units'		=> $data['units'],
                'rid'		=> $data['rid'],
                'attacker'	=> $data['attacker'],
                'defender'	=> $data['defender'],
            ];
        }

        $Selectors = $this->assembleSelectors();

        $this->assign([
            'TopKBList'		=> $TopKBList,
            'Selectors'		=> $Selectors,
            'memorial'		=> $memorial,
            'timeframe'		=> $timeframe,
            'diplomacy'		=> $diplomacy,
            'galaxy'		=> $galaxy,
        ]);

        return $this->render('game/page.battleHall.default.html.twig');
    }
}
