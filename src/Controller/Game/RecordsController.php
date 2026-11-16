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
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\BuildFunctions;
use App\Game\Cronjob\Cronjob;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * Records page: highest building and research levels of the universe next to the player's own.
 */
#[\App\Routing\GamePage('game.php', 'records')]
#[\App\Routing\GamePage('game.php', 'records', uniPrefix: true)]
class RecordsController extends AbstractGameController
{
    public static $requireModule = 'MODULE_RECORDS';

    public function initialize(): void
    {
        parent::initialize();
    }

    public function show(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $reslist = $this->state->reslist;
        $resource = $this->state->resource;
        $db = $this->db;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        $universe = $this->universe()->current();

        $sql = "SELECT advu.statkey, advu.userId, u.username AS current_name, advu.username, advu.statvalue, adv.statvalue AS uservalue, settings.records_optIn
            FROM %%ADVANCED_STATS_UNIVERSE%% advu
            LEFT JOIN %%USERS%% u ON u.id = advu.userId
            LEFT JOIN %%ADVANCED_STATS%% adv ON adv.statkey = advu.statkey AND adv.userId = :userId
            LEFT JOIN %%USERS_SETTINGS%% settings ON settings.user_id = u.id
            WHERE advu.universe = :universe and advu.statvalue < :one_week_old ORDER BY `statvalue` ASC;";
        $firstTimesResult = $db->select($sql, [
            ':universe'      => $universe,
            ':one_week_old'  => $this->state->runtime->timestamp - TIME_1_WEEK,
            ':userId'        => $USER['id'],
        ]);

        $firstTimes = [];
        foreach ($firstTimesResult as $result) {
            $element = substr(strrchr($result['statkey'], "_"), 1);
            $text = $statsHandler->getLangKey(strtolower($result['statkey']));

            $firstTimes[$result['statkey']] = [
                'text'      => $text,
                'name'      => ($result['records_optIn'] === 0 ? '-' : ($result['current_name'] ?: $result['username'])),
                'ts'        => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $result['statvalue'], $USER['timezone']),
                'user_ts'   => $result['uservalue'] != null ? $this->viewHelpers()->formatDate($LNG['php_tdformat'], $result['uservalue'], $USER['timezone']) : '-',
            ];
        }

        $sql = "SELECT r.elementID, r.level, r.userID, u.username, settings.records_optIn, v.name
            FROM %%USERS%% u
            INNER JOIN %%USERS_SETTINGS%% as settings ON settings.user_id = u.id
            INNER JOIN %%RECORDS%% r ON r.userID = u.id
            INNER JOIN %%VARS%% v ON v.elementID = r.elementID
            WHERE r.universe = :universe;";
        $recordResult = $db->select($sql, [':universe' => $universe]);

        $selected_tech = [];
        foreach ($reslist['tech'] as $Techno) {
            $selected_tech[] = $resource[$Techno];
        }

        $sql = "SELECT " . implode(", ", $selected_tech) . " FROM %%USERS%% WHERE id = :userId;";
        $userTechResult = $db->selectSingle($sql, [':userId' => $USER['id']]);

        $select_buildings = [];
        foreach ($reslist['build'] as $Building) {
            $select_buildings[] = sprintf('max(%1$s) AS \'%1$s\'', $resource[$Building]);
        }

        $sql = "SELECT " . implode(", ", $select_buildings) . " FROM %%PLANETS%% WHERE id_owner = :userId;";
        $userBuildResult = $db->selectSingle($sql, [':userId' => $USER['id']]);

        $defenseList = array_fill_keys($reslist['defense'], []);
        $fleetList = array_fill_keys($reslist['fleet'], []);
        $researchList = array_fill_keys($reslist['tech'], []);
        $buildList = array_fill_keys($reslist['build'], []);

        foreach ($recordResult as $recordRow) {
            $element = $recordRow['elementID'];
            if ($this->buildFunctions()->isEnabled($element)) {
                if (in_array($element, $reslist['defense'])) {
                    $defenseList[$element][] = $recordRow;
                } elseif (in_array($element, $reslist['fleet'])) {
                    $fleetList[$element][] = $recordRow;
                } elseif (in_array($element, $reslist['tech'])) {
                    $researchList[$element][] = $recordRow;
                } elseif (in_array($element, $reslist['build'])) {
                    $buildList[$element][] = $recordRow;
                } elseif (in_array($element, $reslist['missile'])) {
                    $defenseList[$element][] = $recordRow;
                }
            }
        }

        $this->assign([
            'defenseList'   => $defenseList,
            'fleetList'     => $fleetList,
            'researchList'  => $researchList,
            'buildList'     => $buildList,
            'update'        => $this->viewHelpers()->formatDate(
                $LNG['php_tdformat'],
                $this->cronjobs()->getLastExecutionTime('statistic'),
                $USER['timezone'],
            ),
            'userTech'      => $userTechResult,
            'userBuild'     => $userBuildResult,
            'firstTimes'    => $firstTimes,
        ]);

        return $this->render('game/page.records.default.html.twig');
    }
}
