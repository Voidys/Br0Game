<?php

/**
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Reflexrecon
 * @copyright 2023 Reflexrecon
 * @licence MIT
 * @version 1.8.0
 */

namespace App\Controller\Game;

use Symfony\Component\HttpFoundation\Response;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\BuildFunctions;
use App\Game\Core\Singleton;

/**
 * Advanced statistics page of the player.
 */
#[\App\Routing\GamePage('game.php', 'advancedStats')]
#[\App\Routing\GamePage('game.php', 'advancedStats', uniPrefix: true)]
class AdvancedStatsController extends AbstractGameController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    private function checkAvailable(string $key)
    {
        $reslist = & $this->state->reslist;
        $result = false;
        $id = substr(strrchr($key, "_"), 1);

        if (!is_numeric($id)) {
            return true;
        }

        if ((in_array($id, $reslist['build']) || in_array($id, $reslist['tech'])
            || in_array($id, $reslist['fleet']) || in_array($id, $reslist['defense'])
            || in_array($id, $reslist['missile'])
            || $id == RESOURCE_METAL || $id == RESOURCE_CRYSTAL || $id == RESOURCE_DEUT)
            && $this->buildFunctions()->isEnabled($id)
        ) {
            $result = true;
        }
        return $result;
    }

    public function show(): Response
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        $advancedStats = $statsHandler->getAllStats($USER['id']);
        $build = [];
        $lost = [];
        $repaired = [];
        $destroyed = [];
        $expo = [];
        $first = [];
        $rest = [];
        foreach ($advancedStats as $key => $value) {
            if ($key == 'userId') {
                continue;
            }
            $others = true;

            if ($value > 0) {
                if (str_contains($key, 'first_') && $key !== "first_moon_trys") { //Unfortunate "first_moon_trys" also starts with "first_", but its a number
                    $value = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $value, $USER['timezone']);
                } else {
                    $value = $this->viewHelpers()->prettyNumber($value);
                }
            } else {
                $value = '-';
            }

            if (str_contains($key, 'build_')) {
                $others = false;
                if ($this->checkAvailable($key)) {
                    $build[$key] = ['text' => $statsHandler->getLangKey($key), 'value' => $value];
                }
            }
            if (str_contains($key, 'lost_')) {
                $others = false;
                if ($this->checkAvailable($key)) {
                    $lost[$key] = ['text' => $statsHandler->getLangKey($key), 'value' => $value];
                }
            }
            if (str_contains($key, 'repaired_')) {
                $others = false;
                if ($this->checkAvailable($key)) {
                    $repaired[$key] = ['text' => $statsHandler->getLangKey($key), 'value' => $value];
                }
            }
            if (str_contains($key, 'destroyed_')) {
                $others = false;
                if ($this->checkAvailable($key)) {
                    $destroyed[$key] = ['text' => $statsHandler->getLangKey($key), 'value' => $value];
                }
            }
            if (str_contains($key, 'expo_')) {
                $others = false;
                if ($this->checkAvailable($key)) {
                    $expo[$key] = ['text' => $statsHandler->getLangKey($key), 'value' => $value];
                }
            }
            if (str_contains($key, 'first_')) {
                $others = false;
                if ($this->checkAvailable($key)) {
                    $first[$key] = ['text' => $statsHandler->getLangKey($key), 'value' => $value];
                }
            }
            if ($others) {
                $rest[$key] = ['text' => $statsHandler->getLangKey($key), 'value' => $value];
            }
        }

        $this->assign([
            'build'     => $build,
            'lost'      => $lost,
            'repaired'  => $repaired,
            'destroyed' => $destroyed,
            'expo'      => $expo,
            'first'     => $first,
            'rest'      => $rest,
            'userId'    => $USER['id'],
        ]);

        return $this->render('game/page.advancedStats.default.html.twig');
    }
}
