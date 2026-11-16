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
use App\Game\Core\StoredData;
use App\Game\Core\BuildFunctions;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\PlayerUtil;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\Singleton;

/** Research: research queue of the player and the technology list. */
#[\App\Routing\GamePage('game.php', 'research')]
#[\App\Routing\GamePage('game.php', 'research', uniPrefix: true)]
class ResearchController extends AbstractGameController
{
    public static $requireModule = 'MODULE_RESEARCH';

    private const QUEUE_ELEMENT_ID = 0;
    private const QUEUE_LEVEL = 1;
    private const QUEUE_ELEMENT_TIME = 2;
    private const QUEUE_BUILD_END_TIME = 3;
    private const QUEUE_PLANET_ID = 4;
    private const QUEUE_COST = 5;

    public function initialize(): void
    {
        parent::initialize();
    }

    private function checkLabSettingsInQueue(): bool
    {
        $USER = $this->state->USER;
        $db = $this->db;
        $sql    = "SELECT * FROM %%PLANETS%% WHERE id_owner = :owner AND destruyed = 0 FOR UPDATE;";
        $planets    = $db->select($sql, [
            ':owner'    => $USER['id'],
        ]);

        foreach ($planets as $planet) {
            if (empty($planet['b_building']) || empty($planet['b_building_id'])) {
                continue;
            }

            $CurrentQueue       = StoredData::decode($planet['b_building_id']);
            foreach ($CurrentQueue as $ListIDArray) {
                if ($ListIDArray[self::QUEUE_ELEMENT_ID] == university || $ListIDArray[self::QUEUE_ELEMENT_ID] == RESEARCH_LABORATORY) {
                    return false;
                }
            }
        }

        return true;
    }

    private function cancelResearchFromQueue(): bool
    {
        $PLANET = & $this->state->PLANET;
        $USER = & $this->state->USER;
        $resource = $this->state->resource;
        $CurrentQueue  = !empty($USER['b_tech_queue']) ? StoredData::decode($USER['b_tech_queue']) : [];
        if (empty($CurrentQueue) || empty($USER['b_tech'])) {
            $USER['b_tech_queue']   = '';
            $USER['b_tech_planet']  = 0;
            $USER['b_tech_id']      = 0;
            $USER['b_tech']         = 0;

            return false;
        }

        $db = $this->db;

        $elementId = $USER['b_tech_id'];
        if (isset($CurrentQueue[0][self::QUEUE_COST])) {
            $costResources = $CurrentQueue[0][self::QUEUE_COST];
        } else {
            $costResources = $this->buildFunctions()->getElementPrice(
                $USER,
                $PLANET,
                $elementId,
                false,
                $USER[$resource[$elementId]] + 1,
            );
        }

        if ($PLANET['id'] == $USER['b_tech_planet']) {
            $this->ecoObj->addResources($USER['b_tech_planet'], $costResources, $PLANET);
        } else {
            $this->ecoObj->addResources($USER['b_tech_planet'], $costResources);
        }

        $USER['b_tech_id']          = 0;
        $USER['b_tech']             = 0;
        $USER['b_tech_planet']      = 0;

        array_shift($CurrentQueue);

        if (count($CurrentQueue) == 0) {
            $USER['b_tech_queue']   = '';
            $USER['b_tech_planet']  = 0;
            $USER['b_tech_id']      = 0;
            $USER['b_tech']         = 0;
        } else {
            $BuildEndTime       = $this->state->runtime->timestamp;
            $NewCurrentQueue    = [];
            $saveRessources = false; // save resources if one of the new techs is on the current planet
            foreach ($CurrentQueue as $ListIDArray) {
                if ($elementId == $ListIDArray[self::QUEUE_ELEMENT_ID] || empty($ListIDArray[self::QUEUE_ELEMENT_ID])) {
                    continue;
                }

                if ($ListIDArray[self::QUEUE_PLANET_ID] != $PLANET['id']) {
                    $sql = "SELECT " . $resource[university] . " , " . $resource[RESEARCH_LABORATORY] . ", id FROM %%PLANETS%% WHERE id = :id;";
                    $CPLANET = $db->selectSingle($sql, [
                        ':id' => $ListIDArray[self::QUEUE_PLANET_ID],
                    ]);
                } else {
                    $CPLANET = $PLANET;
                    $saveRessources = true;
                }

                $CPLANET[$resource[RESEARCH_LABORATORY] . '_inter']    = $this->ecoObj->getNetworkLevel($USER, $CPLANET);
                $BuildEndTime += $this->buildFunctions()->getBuildingTime(
                    $USER,
                    $CPLANET,
                    $ListIDArray[self::QUEUE_ELEMENT_ID],
                    null,
                    false,
                    $ListIDArray[self::QUEUE_LEVEL],
                );
                $ListIDArray[self::QUEUE_BUILD_END_TIME] = $BuildEndTime;
                $NewCurrentQueue[] = $ListIDArray;
            }

            if (!empty($NewCurrentQueue)) {
                $USER['b_tech']             = $this->state->runtime->timestamp;
                $USER['b_tech_queue']       = StoredData::encode($NewCurrentQueue);
                $this->ecoObj->setData($USER, $PLANET);
                $this->ecoObj->SetNextQueueTechOnTop($saveRessources);
                [$USER, $PLANET] = $this->ecoObj->getData();
            } else {
                $USER['b_tech']         = 0;
                $USER['b_tech_queue']   = '';
            }
        }

        return true;
    }

    private function removeResearchFromQueue(int $QueueID): bool
    {
        $USER = & $this->state->USER;
        $PLANET = $this->state->PLANET;
        $resource = $this->state->resource;
        $CurrentQueue  = !empty($USER['b_tech_queue']) ? StoredData::decode($USER['b_tech_queue']) : [];
        if ($QueueID <= 1 || empty($CurrentQueue)) {
            return false;
        }

        $ActualCount   = count($CurrentQueue);
        if ($ActualCount <= 1 || !isset($CurrentQueue[$QueueID - 2])) {
            // Stale request: never cancel the running research by accident
            return false;
        }

        if (!isset($CurrentQueue[$QueueID - 1])) {
            return false;
        }

        // Later levels of the removed element depend on it and are dropped too
        $elementId      = $CurrentQueue[$QueueID - 1][self::QUEUE_ELEMENT_ID];
        $BuildEndTime   = $CurrentQueue[$QueueID - 2][self::QUEUE_BUILD_END_TIME];
        unset($CurrentQueue[$QueueID - 1]);
        $NewCurrentQueue = [];
        foreach ($CurrentQueue as $ID => $ListIDArray) {
            if ($ID < $QueueID - 1) {
                $NewCurrentQueue[] = $ListIDArray;
            } else {
                if ($elementId == $ListIDArray[self::QUEUE_ELEMENT_ID]) {
                    continue;
                }

                if ($ListIDArray[self::QUEUE_PLANET_ID] != $PLANET['id']) {
                    $db = $this->db;

                    $sql = "SELECT " . $resource[university] . ", " . $resource[RESEARCH_LABORATORY] . ", id FROM %%PLANETS%% WHERE id = :id;";
                    $CPLANET = $db->selectSingle($sql, [
                        ':id' => $ListIDArray[self::QUEUE_PLANET_ID],
                    ]);
                } else {
                    $CPLANET = $PLANET;
                }

                $CPLANET[$resource[RESEARCH_LABORATORY] . '_inter'] = $this->ecoObj->getNetworkLevel($USER, $CPLANET);

                $BuildEndTime += $this->buildFunctions()->getBuildingTime($USER, $CPLANET, $ListIDArray[self::QUEUE_ELEMENT_ID], null, false, $ListIDArray[1]);
                $ListIDArray[self::QUEUE_BUILD_END_TIME] = $BuildEndTime;
                $NewCurrentQueue[] = $ListIDArray;
            }
        }

        if (!empty($NewCurrentQueue)) {
            $USER['b_tech_queue'] = StoredData::encode($NewCurrentQueue);
        } else {
            $USER['b_tech_queue'] = "";
        }

        return true;
    }

    private function addResearchToQueue(int $elementId, bool $AddMode = true): bool
    {
        $PLANET = & $this->state->PLANET;
        $USER = & $this->state->USER;
        $resource = $this->state->resource;
        $reslist = $this->state->reslist;
        $pricelist = $this->state->pricelist;
        if (
            !in_array($elementId, $reslist['tech'])
            || !$this->buildFunctions()->isEnabled($elementId)
            || !$this->buildFunctions()->isTechnologieAccessible($USER, $PLANET, $elementId)
            || !$this->checkLabSettingsInQueue()
        ) {
            return false;
        }

        $CurrentQueue       = !empty($USER['b_tech_queue']) ? StoredData::decode($USER['b_tech_queue']) : [];

        if (!empty($CurrentQueue)) {
            $ActualCount    = count($CurrentQueue);
        } else {
            $CurrentQueue   = [];
            $ActualCount    = 0;
        }

        if ($this->configs->get()->max_elements_tech != 0 && $this->configs->get()->max_elements_tech <= $ActualCount) {
            return false;
        }

        if ($PLANET['watch_id'] == $elementId) {
            $PLANET['watch_id'] = 0;
            $db = $this->db;
            $db->update(
                'UPDATE %%PLANETS%% SET watch_id = :watch_id WHERE id = :planetId;',
                [
                    ':watch_id' => $PLANET['watch_id'],
                    ':planetId' => $PLANET['id'],
                ],
            );
        }

        $BuildLevel                 = $USER[$resource[$elementId]] + 1;
        if ($ActualCount == 0) {
            if ($pricelist[$elementId]['max'] < $BuildLevel) {
                return false;
            }

            $costResources      = $this->buildFunctions()->getElementPrice($USER, $PLANET, $elementId, !$AddMode, $BuildLevel);

            if (!$this->buildFunctions()->isElementBuyable($USER, $PLANET, $elementId, $costResources)) {
                return false;
            }

            $this->ecoObj->removeResources($PLANET['id'], $costResources, $PLANET);

            $elementTime                = $this->buildFunctions()->getBuildingTime($USER, $PLANET, $elementId, $costResources);
            $BuildEndTime               = $this->state->runtime->timestamp + $elementTime;

            $USER['b_tech_queue'] = StoredData::encode([[$elementId, $BuildLevel, $elementTime, $BuildEndTime, $PLANET['id'], $costResources]]);
            $USER['b_tech'] = $BuildEndTime;
            $USER['b_tech_id'] = $elementId;
            $USER['b_tech_planet'] = $PLANET['id'];
        } else {
            $addLevel = 0;
            foreach ($CurrentQueue as $QueueSubArray) {
                if ($QueueSubArray[self::QUEUE_ELEMENT_ID] != $elementId) {
                    continue;
                }

                $addLevel++;
            }

            $BuildLevel                 += $addLevel;

            if ($pricelist[$elementId]['max'] < $BuildLevel) {
                return false;
            }

            $elementTime = $this->buildFunctions()->getBuildingTime($USER, $PLANET, $elementId, null, !$AddMode, $BuildLevel);

            $BuildEndTime = $CurrentQueue[$ActualCount - 1][self::QUEUE_BUILD_END_TIME] + $elementTime;
            $CurrentQueue[] = [$elementId, $BuildLevel, $elementTime, $BuildEndTime, $PLANET['id']];
            $USER['b_tech_queue'] = StoredData::encode($CurrentQueue);
        }

        return true;
    }

    private function getQueueData(): array
    {
        $LNG = $this->state->LNG;
        $PLANET = $this->state->PLANET;
        $USER = $this->state->USER;
        $scriptData     = [];
        $quickinfo      = [];

        if ($USER['b_tech'] == 0) {
            return ['queue' => $scriptData, 'quickinfo' => $quickinfo];
        }

        $CurrentQueue   = !empty($USER['b_tech_queue']) ? StoredData::decode($USER['b_tech_queue']) : [];

        foreach ($CurrentQueue as $BuildArray) {
            if ($BuildArray[self::QUEUE_BUILD_END_TIME] < $this->state->runtime->timestamp && !$USER['urlaubs_modus']) {
                continue;
            }

            $PlanetName = '';

            $quickinfo[$BuildArray[self::QUEUE_ELEMENT_ID]]  = $BuildArray[self::QUEUE_LEVEL];

            if ($BuildArray[self::QUEUE_PLANET_ID] != $PLANET['id']) {
                $PlanetName     = $USER['PLANETS'][$BuildArray[self::QUEUE_PLANET_ID]]['name'];
            }

            $scriptData[] = [
                'element'   => $BuildArray[self::QUEUE_ELEMENT_ID],
                'level'     => $BuildArray[self::QUEUE_LEVEL],
                'time'      => $BuildArray[self::QUEUE_ELEMENT_TIME],
                'resttime'  => $USER['urlaubs_modus'] ? ($BuildArray[self::QUEUE_BUILD_END_TIME] - $USER['urlaubs_start']) : ($BuildArray[self::QUEUE_BUILD_END_TIME] - $this->state->runtime->timestamp),
                'destroy'   => false,
                'endtime'   => $this->viewHelpers()->formatDate('U', $BuildArray[self::QUEUE_BUILD_END_TIME], $USER['timezone']),
                'display'   => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $BuildArray[self::QUEUE_BUILD_END_TIME], $USER['timezone']),
                'planet'    => $PlanetName,
            ];
        }

        return ['queue' => $scriptData, 'quickinfo' => $quickinfo];
    }

    public function show(): Response
    {
        $PLANET = & $this->state->PLANET;
        $USER = & $this->state->USER;
        $LNG = $this->state->LNG;
        $requeriments = $this->state->requeriments;
        $resource = $this->state->resource;
        $reslist = $this->state->reslist;
        $pricelist = $this->state->pricelist;
        $TheCommand     = '';
        $elementId      = 0;
        $ListID         = 0;
        $hasLab         = false;
        if ($PLANET[$resource[RESEARCH_LABORATORY]] != 0) {
            $TheCommand     = $this->http()->_GP('cmd', '');
            $elementId      = $this->http()->_GP('tech', 0);
            $ListID         = $this->http()->_GP('listid', 0);
            $hasLab         = true;
        }
        $catchupFactor  = $this->playerUtil()->getCatchUpFactor($USER);

        if ($this->http()->_GP('watch', '') != '') {
            $db = $this->db;
            $db->update(
                'UPDATE %%PLANETS%% SET watch_id = :watch_id WHERE id = :planetId;',
                [':watch_id' => max(0, (int) $this->http()->_GP('watch', 0)),
                    ':planetId' => $PLANET['id'],
                ],
            );

            $this->redirectTo('game?page=research');
        }

        $PLANET[$resource[RESEARCH_LABORATORY] . '_inter'] = $this->resourceUpdate()->getNetworkLevel($USER, $PLANET);

        if (!empty($TheCommand) && $this->state->requestServer['REQUEST_METHOD'] === 'POST' && $USER['urlaubs_modus'] == 0) {
            $db = $this->db;
            $db->startTransaction();
            $USER = $db->selectSingle("SELECT u.*, us.* FROM %%USERS%% u JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id WHERE id = :userID FOR UPDATE;", [':userID' => $USER['id']]);
            $PLANET = $db->selectSingle("SELECT * FROM %%PLANETS%% WHERE id = :planetId FOR UPDATE;", [':planetId' => $PLANET['id']]);
            $db->select("SELECT * FROM %%PLANETS%% WHERE id_owner = :userID FOR UPDATE;", [':userID' => $USER['id']]);
            $PLANET[$resource[RESEARCH_LABORATORY] . '_inter'] = $this->resourceUpdate()->getNetworkLevel($USER, $PLANET);
            $validCommand = true;
            switch ($TheCommand) {
                case 'cancel':
                    $this->cancelResearchFromQueue();
                    break;
                case 'remove':
                    $this->removeResearchFromQueue($ListID);
                    break;
                case 'insert':
                    $this->addResearchToQueue($elementId, true);
                    break;
                case 'destroy':
                    $this->addResearchToQueue($elementId, false);
                    break;
                default:
                    error_log($TheCommand);
                    $validCommand = false;
                    break;
            }

            if ($validCommand) {
                $this->ecoObj->saveTechQueue($USER);
            }
            $db->commit();
            $this->redirectTo('game?page=research');
        }

        $bContinue      = $this->checkLabSettingsInQueue();

        $queueData      = $this->getQueueData();
        $TechQueue      = $queueData['queue'];
        $QueueCount     = count($TechQueue);
        $ResearchList   = [];
        $config         = $this->configs->get();
        $metproduction  = $PLANET['metal_perhour'] + $config->metal_basic_income * ($config->resource_multiplier / 2500);
        $kristproduction = $PLANET['crystal_perhour'] + $config->crystal_basic_income * ($config->resource_multiplier / 2500);
        $deutproduction = $PLANET['deuterium_perhour']  + $config->deuterium_basic_income * ($config->resource_multiplier / 2500);

        foreach ($reslist['tech'] as $elementId) {
            if (!$this->buildFunctions()->isEnabled($elementId)) {
                continue;
            }
            $requirementsList = [];
            if (!$this->buildFunctions()->isTechnologieAccessible($USER, $PLANET, $elementId)) {
                if (!$USER['show_all_buildable_elements']) {
                    continue;
                }
                if (isset($requeriments[$elementId])) {
                    foreach ($requeriments[$elementId] as $requireID => $RedCount) {
                        $requirementsList[$requireID] = [
                            'count' => $RedCount,
                            'own'   => isset($PLANET[$resource[$requireID]]) ? $PLANET[$resource[$requireID]] : $USER[$resource[$requireID]],
                        ];
                    }
                }
            }

            if (isset($queueData['quickinfo'][$elementId])) {
                $levelToBuild   = $queueData['quickinfo'][$elementId];
            } else {
                $levelToBuild   = $USER[$resource[$elementId]];
            }

            $costResources = $this->buildFunctions()->getElementPrice($USER, $PLANET, $elementId, false, $levelToBuild + 1);
            $costOverflow = $this->buildFunctions()->getRestPrice($USER, $PLANET, $elementId, $costResources);
            $timetobuild = 0;
            if (array_key_exists(RESOURCE_METAL, $costOverflow) && $costOverflow[RESOURCE_METAL] != 0 && $metproduction > 0) {
                $timetobuild = max($timetobuild, $costOverflow[RESOURCE_METAL] / $metproduction);
            }
            if (array_key_exists(RESOURCE_CRYSTAL, $costOverflow) && $costOverflow[RESOURCE_CRYSTAL] != 0 && $kristproduction > 0) {
                $timetobuild = max($timetobuild, $costOverflow[RESOURCE_CRYSTAL] / $kristproduction);
            }
            if (array_key_exists(RESOURCE_DEUT, $costOverflow) && $costOverflow[RESOURCE_DEUT] != 0 && $deutproduction > 0) {
                $timetobuild = max($timetobuild, $costOverflow[RESOURCE_DEUT] / $deutproduction);
            }
            $timetobuild = floor($timetobuild * 3600) ;
            $elementTime = $this->buildFunctions()->getBuildingTime($USER, $PLANET, $elementId, $costResources);
            $buyable = $QueueCount != 0 || $this->buildFunctions()->isElementBuyable($USER, $PLANET, $elementId, $costResources);

            $fade = ($USER['missing_requirements_opacity'] && !empty($requirementsList))
                || ($USER['missing_resources_opacity'] && !$buyable)
                || $pricelist[$elementId]['max'] == $levelToBuild;

            $max_useful_level = $pricelist[$elementId]['max_useful_level'];

            $factor = $this->playerUtil()->getCatchUpFactorForElement($catchupFactor, $elementId, $levelToBuild + 1);

            $ResearchList[$elementId]   = [
                'id'                => $elementId,
                'level'             => $USER[$resource[$elementId]],
                'maxLevel'          => $pricelist[$elementId]['max'],
                'costResources'     => $costResources,
                'costOverflow'      => $costOverflow,
                'elementTime'       => $elementTime,
                'buyable'           => $buyable,
                'levelToBuild'      => $levelToBuild,
                'timetobuild'       => $timetobuild,
                'requirements'      => $requirementsList,
                'fade'              => $fade,
                'max_useful_level'  => $max_useful_level,
                'factor'            => $catchupFactor != $factor ? round((1 - $factor) * 100, 2) : null,
            ];
        }

        $this->assign([
            'umode'         => $USER['urlaubs_modus'],
            'ResearchList'  => $ResearchList,
            'IsLabinBuild'  => !$bContinue,
            'hasLab'        => $hasLab,
            'IsFullQueue'   => $this->configs->get()->max_elements_tech == 0
                || $this->configs->get()->max_elements_tech == count($TechQueue),
            'Queue'         => $TechQueue,
            'queueSlots'    => max((int) $this->configs->get()->max_elements_tech, count($TechQueue)),
            'isCatchUp'     => $config->catch_up,
            'catchUp'       => $LNG->format('sys_catchup', round((1 - $catchupFactor) * 100, 2)),
            'hide_useless'  => $USER['hide_useless'],
            'watch'         => $PLANET['watch_id'],
        ]);

        return $this->render('game/page.research.default.html.twig');
    }
}
