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
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\PlayerUtil;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\Singleton;

/** Buildings: build queue of the current planet, start/cancel/demolish buildings and the building list. */
#[\App\Routing\GamePage('game.php', 'buildings')]
#[\App\Routing\GamePage('game.php', 'buildings', uniPrefix: true)]
class BuildingsController extends AbstractGameController
{
    public static $requireModule = 'MODULE_BUILDING';

    private const QUEUE_ELEMENT_ID = 0;
    private const QUEUE_LEVEL = 1;
    private const QUEUE_ELEMENT_TIME = 2;
    private const QUEUE_BUILD_END_TIME = 3;
    private const QUEUE_MODE = 4;
    private const QUEUE_COST = 5;

    public function initialize(): void
    {
        parent::initialize();
    }

    private function cancelBuildingFromQueue(): bool
    {
        $PLANET = &$this->state->PLANET;
        $USER = &$this->state->USER;
        $CurrentQueue  = !empty($PLANET['b_building_id']) ? unserialize($PLANET['b_building_id']) : [];
        if (empty($CurrentQueue)) {
            $PLANET['b_building_id']    = '';
            $PLANET['b_building']       = 0;
            return false;
        }

        if (isset($CurrentQueue[0][self::QUEUE_COST])) {
            $costResources = $CurrentQueue[0][self::QUEUE_COST];
        } else {
            $Element                = $CurrentQueue[0][self::QUEUE_ELEMENT_ID];
            $BuildLevel             = $CurrentQueue[0][self::QUEUE_LEVEL];
            $BuildMode              = $CurrentQueue[0][self::QUEUE_MODE];

            $costResources = $this->buildFunctions()->getElementPrice(
                $USER,
                $PLANET,
                $Element,
                $BuildMode == 'destroy',
                $BuildLevel,
            );
        }

        $this->ecoObj->addResources($PLANET['id'], $costResources, $PLANET);

        array_shift($CurrentQueue);
        if (count($CurrentQueue) == 0) {
            $PLANET['b_building']       = 0;
            $PLANET['b_building_id']    = '';
        } else {
            $PLANET['b_building']       = 0;
            $PLANET['b_building_id']    = '';
            foreach ($CurrentQueue as $ListIDArray) {
                $this->addBuildingToQueue($ListIDArray[self::QUEUE_ELEMENT_ID], 'build' == $ListIDArray[self::QUEUE_MODE]);
            }
        }
        return true;
    }

    private function removeBuildingFromQueue(int $QueueID): bool
    {
        $PLANET = &$this->state->PLANET;
        if ($QueueID <= 1 || empty($PLANET['b_building_id'])) {
            return false;
        }

        $CurrentQueue  = unserialize($PLANET['b_building_id']);
        $ActualCount   = count($CurrentQueue);
        if ($ActualCount <= 1) {
            // Stale request: never cancel the running building by accident
            return false;
        }

        if ($QueueID - $ActualCount >= 1) {
            // Avoid race conditions
            return false;
        }

        $NewQueueArray = [];
        for ($i = $QueueID, $j = 0, $max = sizeof($CurrentQueue); $i <= $max; $i++, $j++) {
            if ($i != $QueueID) {
                $NewQueueArray[$j] = $CurrentQueue[$i - 1];
            }
            unset($CurrentQueue[$i - 1]);
        }

        if (!empty($CurrentQueue)) {
            $PLANET['b_building_id'] = serialize($CurrentQueue);
        } else {
            $PLANET['b_building_id'] = "";
        }

        foreach ($NewQueueArray as $ListIDArray) {
            $this->addBuildingToQueue($ListIDArray[self::QUEUE_ELEMENT_ID], 'build' == $ListIDArray[self::QUEUE_MODE]);
        }

        return true;
    }

    private function addBuildingToQueue(int $Element, bool $AddMode = true): void
    {
        $PLANET = &$this->state->PLANET;
        $USER = &$this->state->USER;
        $resource = &$this->state->resource;
        $reslist = &$this->state->reslist;
        $pricelist = &$this->state->pricelist;
        if (
            !in_array($Element, $reslist['allow'][$PLANET['planet_type']])
            || !$this->buildFunctions()->isEnabled($Element)
            || !$this->buildFunctions()->isTechnologieAccessible($USER, $PLANET, $Element)
            || ($Element == RESEARCH_LABORATORY && $USER["b_tech_planet"] != 0)
            || (($Element == NANITE_FACTORY || $Element == SHIPYARD) && !empty($PLANET['b_hangar_id']))
            || (!$AddMode && (($Element == SILO && ($PLANET[$resource[INTERCEPTOR_MISSILE]] + $PLANET[$resource[INTERPLANETARY_MISSILE]]) > 0) || $Element == TERRAFORMER || $Element == MOONBASE))
            || (!$AddMode && $PLANET[$resource[$Element]] == 0)
        ) {
            return;
        }

        $CurrentQueue       = !empty($PLANET['b_building_id']) ? unserialize($PLANET['b_building_id']) : [];
        if (!is_array($CurrentQueue)) {
            $CurrentQueue = [];
        }
        $ActualCount = count($CurrentQueue);

        $config = $this->configs->get();

        if (
            ($config->max_elements_build != 0 && $ActualCount == $config->max_elements_build)
            || !$this->buildFunctions()->canQueueBuildingOnFields($PLANET, $CurrentQueue, $AddMode)
        ) {
            return;
        }

        if ($PLANET['watch_id'] == $Element) {
            $PLANET['watch_id'] = 0;
            ;
        }

        $BuildMode          = $AddMode ? 'build' : 'destroy';
        $BuildLevel         = $PLANET[$resource[$Element]] + (int) $AddMode;

        if ($ActualCount == 0) {
            if ($pricelist[$Element]['max'] < $BuildLevel) {
                return;
            }

            $costResources      = $this->buildFunctions()->getElementPrice($USER, $PLANET, $Element, !$AddMode, $BuildLevel);

            if (!$this->buildFunctions()->isElementBuyable($USER, $PLANET, $Element, $costResources)) {
                return;
            }

            $this->ecoObj->removeResources($PLANET['id'], $costResources, $PLANET);

            $elementTime                = $this->buildFunctions()->getBuildingTime($USER, $PLANET, $Element, $costResources, !$AddMode);
            $BuildEndTime               = $this->state->runtime->timestamp + $elementTime;

            $PLANET['b_building_id']    = serialize([[$Element, $BuildLevel, $elementTime, $BuildEndTime, $BuildMode, $costResources]]);
            $PLANET['b_building']       = $BuildEndTime;
        } else {
            $addLevel = 0;
            foreach ($CurrentQueue as $QueueSubArray) {
                if ($QueueSubArray[self::QUEUE_ELEMENT_ID] != $Element) {
                    continue;
                }

                if ($QueueSubArray[self::QUEUE_MODE] == 'build') {
                    $addLevel++;
                } else {
                    $addLevel--;
                }
            }

            $BuildLevel                 += $addLevel;

            if (!$AddMode && $BuildLevel == 0) {
                return;
            }

            if ($pricelist[$Element]['max'] < $BuildLevel) {
                return;
            }

            $elementTime = $this->buildFunctions()->getBuildingTime($USER, $PLANET, $Element, null, !$AddMode, $BuildLevel, $ActualCount);
            $BuildEndTime = $CurrentQueue[$ActualCount - 1][self::QUEUE_BUILD_END_TIME] + $elementTime;
            $CurrentQueue[] = [$Element, $BuildLevel, $elementTime, $BuildEndTime, $BuildMode];
            $PLANET['b_building_id'] = serialize($CurrentQueue);
        }
    }

    private function getQueueData(): array
    {
        $LNG = &$this->state->LNG;
        $PLANET = &$this->state->PLANET;
        $USER = &$this->state->USER;
        $scriptData = [];
        $quickinfo = [];

        if (empty($PLANET['b_building']) || empty($PLANET['b_building_id'])) {
            return ['queue' => $scriptData, 'quickinfo' => $quickinfo];
        }

        $buildQueue     = unserialize($PLANET['b_building_id']);

        foreach ($buildQueue as $BuildArray) {
            if ($BuildArray[self::QUEUE_BUILD_END_TIME] < $this->state->runtime->timestamp && !$USER['urlaubs_modus']) {
                continue;
            }

            $quickinfo[$BuildArray[self::QUEUE_ELEMENT_ID]]  = $BuildArray[self::QUEUE_LEVEL];

            $scriptData[] = [
                'element'   => $BuildArray[self::QUEUE_ELEMENT_ID],
                'level'     => $BuildArray[self::QUEUE_LEVEL],
                'time'      => $BuildArray[self::QUEUE_ELEMENT_TIME],
                'resttime'  => $USER['urlaubs_modus'] ? ($BuildArray[self::QUEUE_BUILD_END_TIME] - $USER['urlaubs_start']) : ($BuildArray[self::QUEUE_BUILD_END_TIME] - $this->state->runtime->timestamp),
                'destroy'   => ($BuildArray[self::QUEUE_MODE] == 'destroy'),
                'endtime'   => $this->viewHelpers()->formatDate('U', $BuildArray[self::QUEUE_BUILD_END_TIME], $USER['timezone']),
                'display'   => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $BuildArray[self::QUEUE_BUILD_END_TIME], $USER['timezone']),
            ];
        }

        return ['queue' => $scriptData, 'quickinfo' => $quickinfo];
    }

    public function show(): Response
    {
        $ProdGrid = &$this->state->ProdGrid;
        $LNG = &$this->state->LNG;
        $requeriments = & $this->state->requeriments;
        $resource = &$this->state->resource;
        $reslist = &$this->state->reslist;
        $PLANET = &$this->state->PLANET;
        $USER = &$this->state->USER;
        $pricelist = &$this->state->pricelist;
        $TheCommand     = $this->http()->_GP('cmd', '');
        $catchupFactor  = $this->playerUtil()->getCatchUpFactor($USER);


        if ($this->http()->_GP('watch', '') != '') {
            $db = $this->db;
            $db->update(
                'UPDATE %%PLANETS%% SET watch_id = :watch_id WHERE id = :planetId;',
                [
                    ':watch_id' => max(0, (int) $this->http()->_GP('watch', 0)),
                    ':planetId' => $PLANET['id'],
                ],
            );

            $this->redirectTo('game?page=buildings');
        }

        // wellformed buildURLs
        if (!empty($TheCommand) && $this->state->requestServer['REQUEST_METHOD'] === 'POST' && $USER['urlaubs_modus'] == 0) {
            $Element        = $this->http()->_GP('building', 0);
            $ListID         = $this->http()->_GP('listid', 0);
            $db = $this->db;
            $db->startTransaction();
            $PLANET = $db->selectSingle("SELECT * FROM %%PLANETS%% WHERE id = :planetId FOR UPDATE;", [':planetId' => $PLANET['id']]);
            $validCommand = true;
            switch ($TheCommand) {
                case 'cancel':
                    $this->cancelBuildingFromQueue();
                    break;
                case 'remove':
                    $this->removeBuildingFromQueue($ListID);
                    break;
                case 'insert':
                    $this->addBuildingToQueue($Element, true);
                    break;
                case 'destroy':
                    $this->addBuildingToQueue($Element, false);
                    break;
                default:
                    error_log($TheCommand);
                    $validCommand = false;
                    break;
            }

            if ($validCommand) {
                $this->ecoObj->saveBuilingQueue($PLANET);
            }
            $db->commit();
            $this->redirectTo('game?page=buildings');
        }

        $config             = $this->configs->get();

        $queueData          = $this->getQueueData();
        $Queue              = $queueData['queue'];
        $QueueCount         = count($Queue);

        $CanBuildElement = isVacationMode($USER)
            || $config->max_elements_build == 0
            || $QueueCount < $config->max_elements_build;
        $CurrentQueue = !empty($PLANET['b_building_id']) ? unserialize($PLANET['b_building_id']) : [];
        if (!is_array($CurrentQueue)) {
            $CurrentQueue = [];
        }
        $RoomIsOk = $this->buildFunctions()->canQueueBuildingOnFields($PLANET, $CurrentQueue);

        /* Data for eval */
        $BuildEnergy        = $USER[$resource[ENERGY_TECH]];
        $BuildLevelFactor   = 10;
        $BuildTemp          = $PLANET['temp_max'];

        $BuildInfoList      = [];
        $Elements           = $reslist['allow'][$PLANET['planet_type']];
        $metproduction      = $PLANET['metal_perhour'] + $config->metal_basic_income * ($config->resource_multiplier / 2500);
        $kristproduction    = $PLANET['crystal_perhour'] + $config->crystal_basic_income * ($config->resource_multiplier / 2500);
        $deutproduction     = $PLANET['deuterium_perhour']  + $config->deuterium_basic_income * ($config->resource_multiplier / 2500);


        foreach ($Elements as $Element) {
            if (!$this->buildFunctions()->isEnabled($Element)) {
                continue;
            }
            $requirementsList = [];
            if (!$this->buildFunctions()->isTechnologieAccessible($USER, $PLANET, $Element)) {
                if (!$USER['show_all_buildable_elements']) {
                    continue;
                }
                if (isset($requeriments[$Element])) {
                    foreach ($requeriments[$Element] as $requireID => $RedCount) {
                        $requirementsList[$requireID] = [
                            'count' => $RedCount,
                            'own'   => isset($PLANET[$resource[$requireID]]) ? $PLANET[$resource[$requireID]] : $USER[$resource[$requireID]],
                        ];
                    }
                }
            }

            $infoEnergy = null;

            if (isset($queueData['quickinfo'][$Element])) {
                $levelToBuild   = $queueData['quickinfo'][$Element];
                $levelToDestroy = $levelToBuild - 1;
            } else {
                $levelToBuild   = $PLANET[$resource[$Element]];
                $levelToDestroy = $levelToBuild;
            }

            if (in_array($Element, $reslist['prod'])) {
                $BuildLevel = $PLANET[$resource[$Element]];
                $Need       = eval($this->resourceUpdate()->getProd($ProdGrid[$Element]['production'][RESOURCE_ENERGY], $Element));

                $BuildLevel = $levelToBuild + 1;
                $Prod       = eval($this->resourceUpdate()->getProd($ProdGrid[$Element]['production'][RESOURCE_ENERGY], $Element));

                $requireEnergy  = $Prod - $Need;
                $requireEnergy  = round($requireEnergy * $config->energy_multiplier);

                // the template formats bd_need_engine / bd_more_engine with this amount
                $infoEnergy = [
                    'consumes' => $requireEnergy < 0,
                    'amount'   => $this->viewHelpers()->prettyNumber(abs($requireEnergy)),
                ];
            }

            $costResources = $this->buildFunctions()->getElementPrice(
                $USER,
                $PLANET,
                $Element,
                false,
                $levelToBuild + 1,
            );
            $costOverflow = $this->buildFunctions()->getRestPrice($USER, $PLANET, $Element, $costResources);
            $timetobuild = 0;
            if ($PLANET['planet_type'] != 3) {
                if (array_key_exists(RESOURCE_METAL, $costOverflow) && $costOverflow[RESOURCE_METAL] != 0 && $metproduction > 0) {
                    $timetobuild = max($timetobuild, $costOverflow[RESOURCE_METAL] / $metproduction);
                }
                if (array_key_exists(RESOURCE_CRYSTAL, $costOverflow) && $costOverflow[RESOURCE_CRYSTAL] != 0 && $kristproduction > 0) {
                    $timetobuild = max($timetobuild, $costOverflow[RESOURCE_CRYSTAL] / $kristproduction);
                }
                if (array_key_exists(RESOURCE_DEUT, $costOverflow) && $costOverflow[RESOURCE_DEUT] != 0 && $deutproduction > 0) {
                    $timetobuild = max($timetobuild, $costOverflow[RESOURCE_DEUT] / $deutproduction);
                }
                $timetobuild = floor($timetobuild * 3600);
            }

            $elementTime = $this->buildFunctions()->getBuildingTime($USER, $PLANET, $Element, $costResources);
            $destroyResources = $this->buildFunctions()->getElementPrice($USER, $PLANET, $Element, true, $levelToDestroy);
            $destroyTime = $this->buildFunctions()->getBuildingTime($USER, $PLANET, $Element, $destroyResources, true);
            $destroyOverflow = $this->buildFunctions()->getRestPrice($USER, $PLANET, $Element, $destroyResources);
            $buyable = $QueueCount != 0 || $this->buildFunctions()->isElementBuyable($USER, $PLANET, $Element, $costResources);
            $destroyable = ($Element != SILO || ($PLANET[$resource[INTERCEPTOR_MISSILE]] + $PLANET[$resource[INTERPLANETARY_MISSILE]]) == 0) && $Element != TERRAFORMER && $Element != MOONBASE;

            $filterClass = 'other';
            if (in_array($Element, $reslist['prod'])) {
                $filterClass = 'prod';
            } elseif (in_array($Element, $reslist['storage'])) {
                $filterClass = 'storage';
            }

            $fade = ($USER['missing_requirements_opacity'] && !empty($requirementsList))
                || ($USER['missing_resources_opacity'] && !$buyable)
                || $pricelist[$Element]['max'] == $levelToBuild;

            $factor = $this->playerUtil()->getCatchUpFactorForElement($catchupFactor, $Element, $levelToBuild + 1);

            $BuildInfoList[$Element]    = [
                'level'             => $PLANET[$resource[$Element]],
                'maxLevel'          => $pricelist[$Element]['max'],
                'infoEnergy'        => $infoEnergy,
                'costResources'     => $costResources,
                'costOverflow'      => $costOverflow,
                'elementTime'       => $elementTime,
                'destroyResources'  => $destroyResources,
                'destroyTime'       => $destroyTime,
                'destroyOverflow'   => $destroyOverflow,
                'buyable'           => $buyable,
                'destroyable'       => $destroyable,
                'levelToBuild'      => $levelToBuild,
                'levelToDestroy'    => $levelToDestroy,
                'timetobuild'       => $timetobuild,
                'requirements'      => $requirementsList,
                'filterClass'       => $filterClass,
                'fade'              => $fade,
                'factor'            => $catchupFactor != $factor ? round((1 - $factor) * 100, 2) : null,
            ];
        }

        $sql = "SELECT `repair_order` FROM %%PLANET_WRECKFIELD%% WHERE planetID = :planetID;";
        $wreckfield = $this->db->selectSingle($sql, [':planetID' => $PLANET['id']]);

        if ($QueueCount != 0) {
            $this->addScript('buildlist.js');
        }

        $this->assign([
            'umode'             => $USER['urlaubs_modus'],
            'BuildInfoList'     => $BuildInfoList,
            'CanBuildElement'   => $CanBuildElement,
            'RoomIsOk'          => $RoomIsOk,
            'Queue'             => $Queue,
            // fixed slot count of the queue list (free slots shown), at least the current length
            'queueSlots'        => max((int) $config->max_elements_build, count($Queue)),
            'isBusy'            => [
                'shipyard' => !empty($PLANET['b_hangar_id']),
                'research' => $USER['b_tech_planet'] != 0,
                'repairdock' => !empty($wreckfield['repair_order']),
            ],
            'userLang'          => $USER['lang'],
            'isCatchUp'         => $config->catch_up,
            'catchUp'           => sprintf($LNG['sys_catchup'], round((1 - $catchupFactor) * 100, 2)),
            'watch'             => $PLANET['watch_id'],
            'planet_field_current' => $PLANET['field_current'],
            'planet_field_max'     => $this->playerUtil()->calculateMaxPlanetFields($PLANET),
        ]);

        return $this->render('game/page.buildings.default.html.twig');
    }
}
