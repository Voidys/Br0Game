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
#[\App\Routing\GamePage('game', 'advancedStats')]
class AdvancedStatsController extends AbstractGameController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Whether an advanced-statistics key is shown: keys ending in an element id (e.g. `destroyed_202`) only for
     * buildings, research, ships, defense, missiles or the three resources that are enabled in this universe;
     * keys without an id are always shown.
     */
    private function checkAvailable(string $key)
    {
        $result = false;
        $id = substr(strrchr($key, "_"), 1);

        if (!is_numeric($id)) {
            return true;
        }

        $elements = $this->elements();
        $elementId = (int) $id;

        if (($elements->isBuilding($elementId) || $elements->isResearch($elementId)
            || $elements->isFleet($elementId) || $elements->isDefense($elementId)
            || $elements->isMissile($elementId)
            || $id == RESOURCE_METAL || $id == RESOURCE_CRYSTAL || $id == RESOURCE_DEUT)
            && $this->buildFunctions()->isEnabled($id)
        ) {
            $result = true;
        }
        return $result;
    }

    /**
     * Advanced statistics of the player (counters from AdvancedStats such as built, repaired or destroyed elements), grouped for the template.
     */
    public function show(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        $advancedStats = $statsHandler->getAllStats($USER['id']);
        // key part => group of the page; a key can belong to several groups, keys of none land in "rest"
        $groups = ['build_' => [], 'lost_' => [], 'repaired_' => [], 'destroyed_' => [], 'expo_' => [], 'first_' => []];
        $rest = [];
        foreach ($advancedStats as $key => $value) {
            if ($key == 'userId') {
                continue;
            }
            $others = true;

            $value = $this->formatStat($key, $value);

            foreach ($groups as $part => $entries) {
                if (str_contains($key, $part)) {
                    $others = false;
                    if ($this->checkAvailable($key)) {
                        $groups[$part][$key] = ['text' => $statsHandler->getLangKey($key), 'value' => $value];
                    }
                }
            }
            if ($others) {
                $rest[$key] = ['text' => $statsHandler->getLangKey($key), 'value' => $value];
            }
        }

        $build = $groups['build_'];
        $lost = $groups['lost_'];
        $repaired = $groups['repaired_'];
        $destroyed = $groups['destroyed_'];
        $expo = $groups['expo_'];
        $first = $groups['first_'];

        $this->assign([
            'build'     => $build,
            'lost'      => $lost,
            'repaired'  => $repaired,
            'destroyed' => $destroyed,
            'expo'      => $expo,
            'first'     => $first,
            'rest'      => $rest,
        ]);

        return $this->render('game/page.advancedStats.default.html.twig');
    }

    /**
     * Display value of a counter: `-` for zero, a date for the "first_*" timestamps, otherwise a formatted number.
     */
    private function formatStat(string $key, mixed $value): string
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        if ($value > 0) {
            if (str_contains($key, 'first_') && $key !== "first_moon_trys") { //Unfortunate "first_moon_trys" also starts with "first_", but its a number
                return $this->viewHelpers()->formatDate($LNG['php_tdformat'], $value, $USER['timezone']);
            }

            return $this->viewHelpers()->prettyNumber($value);
        }

        return '-';
    }
}
