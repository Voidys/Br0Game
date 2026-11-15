<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 * For the full copyright and license information, please view the LICENSE
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
use App\Game\Achievements\MiscAchievement;
use App\Game\Core\BuildFunctions;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HangarFilters;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;

/** Shipyard and defence: build queue, ship/defence lists and hangar filters (modes: show, saveFilters). */
#[\App\Routing\GamePage('game.php', 'shipyard')]
#[\App\Routing\GamePage('game.php', 'shipyard', uniPrefix: true)]
class ShipyardController extends AbstractGameController
{
    public static $requireModule = 0;

    public static $defaultController = 'show';

    private const QUEUE_ELEMENT_ID = 0;
    private const QUEUE_AMMOUNT = 1;

    public function initialize(): void
    {
        parent::initialize();
    }

    private function cancelAuftr(): bool
    {
        $USER = & $this->state->USER;
        $PLANET = & $this->state->PLANET;
        $ElementQueue = !empty($PLANET['b_hangar_id']) ? unserialize($PLANET['b_hangar_id']) : [];

        $CancelArray = $this->http()->_GP('auftr', []);

        if (!is_array($CancelArray)) {
            return false;
        }

        foreach ($CancelArray as $Auftr) {
            if (!isset($ElementQueue[$Auftr])) {
                continue;
            }

            if ($Auftr == 0) {
                $PLANET['b_hangar'] = 0;
            }

            $Element = $ElementQueue[$Auftr][self::QUEUE_ELEMENT_ID];
            $Count = $ElementQueue[$Auftr][self::QUEUE_AMMOUNT];

            $costResources = $this->buildFunctions()->getElementPrice($USER, $PLANET, $Element, false, $Count);

            $this->ecoObj->addResources($PLANET['id'], $costResources, $PLANET, FACTOR_CANCEL_SHIPYARD);

            unset($ElementQueue[$Auftr]);
        }

        if (empty($ElementQueue)) {
            $PLANET['b_hangar_id'] = '';
        } else {
            $PLANET['b_hangar_id'] = serialize(array_values($ElementQueue));
        }

        $this->ecoObj->saveShipyardQueue($PLANET);

        return true;
    }

    private function buildAuftr(array $fmenge): void
    {
        $USER = & $this->state->USER;
        $PLANET = & $this->state->PLANET;
        $reslist = & $this->state->reslist;
        $resource = & $this->state->resource;
        // Working copy: resources already committed to earlier entries of this order are not available again
        $availablePlanet = $PLANET;
        $maxBuildQueue = $this->configs->get()->max_elements_ships;

        $totalCostResources = [
            RESOURCE_METAL => 0,
            RESOURCE_CRYSTAL => 0,
            RESOURCE_DEUT => 0,
        ];
        foreach ($fmenge as $Element => $Count) {
            if (
                empty($Count)
                || !in_array($Element, array_merge($reslist['fleet'], $reslist['defense'], $reslist['missile']))
                || !$this->buildFunctions()->isEnabled($Element)
                || !$this->buildFunctions()->isTechnologieAccessible($USER, $PLANET, $Element)
            ) {
                continue;
            }

            $BuildArray = !empty($PLANET['b_hangar_id']) ? unserialize($PLANET['b_hangar_id']) : [];
            if ($maxBuildQueue != 0 && count($BuildArray) >= $maxBuildQueue) {
                break;
            }

            $MaxElements = $this->buildFunctions()->getMaxConstructibleElements($USER, $availablePlanet, $Element);
            $Count = is_numeric($Count) ? round($Count) : 0;
            $Count = max(min($Count, $this->configs->get()->max_fleet_per_build), 0);
            $Count = min($Count, $MaxElements);

            if (in_array($Element, $reslist['missile'])) {
                // Owned and already queued missiles are both counted by getMaxConstructibleRockets()
                $MaxMissiles = $this->buildFunctions()->getMaxConstructibleRockets($PLANET);
                $Count = min($Count, $MaxMissiles[$Element]);
            } elseif (in_array($Element, $reslist['one'])) {
                $InBuild = false;
                foreach ($BuildArray as $ElementArray) {
                    if ($ElementArray[self::QUEUE_ELEMENT_ID] == $Element) {
                        $InBuild = true;
                        break;
                    }
                }

                if ($InBuild || $PLANET[$resource[$Element]] != 0) {
                    continue;
                }

                if ($Count != 0) {
                    $Count = 1;
                }
            }

            if (empty($Count)) {
                continue;
            }

            $costResources = $this->buildFunctions()->getElementPrice($USER, $PLANET, $Element, false, $Count);

            foreach ($costResources as $resourceId => $amount) {
                $totalCostResources[$resourceId] += $amount;
                $availablePlanet[$resource[$resourceId]] -= $amount;
            }

            $this->miscAchievements()->checkShipyardAchievements($USER['id'], $Count, $Element);
            $BuildArray[] = [$Element, $Count];
            $PLANET['b_hangar_id'] = serialize($BuildArray);
        }
        $this->ecoObj->removeResources($PLANET['id'], $totalCostResources, $PLANET);
        $this->ecoObj->saveShipyardQueue($PLANET);
    }

    public function show(): Response
    {
        $USER = & $this->state->USER;
        $PLANET = & $this->state->PLANET;
        $LNG = & $this->state->LNG;
        $requeriments = & $this->state->requeriments;
        $resource = & $this->state->resource;
        $reslist = & $this->state->reslist;
        if ($PLANET[$resource[SHIPYARD]] == 0) {
            $this->printMessage($LNG['bd_shipyard_required']);
        }

        $mode = $this->http()->_GP('mode', 'fleet');
        $buildTodo = $this->http()->_GP('fmenge', []);
        $action = $this->http()->_GP('action', '');

        $NotBuilding = true;
        if (!empty($PLANET['b_building_id'])) {
            $CurrentQueue = !empty($PLANET['b_building_id']) ? unserialize($PLANET['b_building_id']) : [];
            foreach ($CurrentQueue as $ElementArray) {
                if ($ElementArray[self::QUEUE_ELEMENT_ID] == SHIPYARD || $ElementArray[self::QUEUE_ELEMENT_ID] == NANITE_FACTORY) {
                    $NotBuilding = false;
                    break;
                }
            }
        }

        $ElementQueue = !empty($PLANET['b_hangar_id']) ? unserialize($PLANET['b_hangar_id']) : [];
        if (empty($ElementQueue)) {
            $Count = 0;
        } else {
            $Count = count($ElementQueue);
        }

        if ($USER['urlaubs_modus'] == 0 && $NotBuilding == true) {
            if (!empty($buildTodo)) {
                $maxBuildQueue = $this->configs->get()->max_elements_ships;
                if ($maxBuildQueue != 0 && $Count >= $maxBuildQueue) {
                    $this->printMessage(sprintf($LNG['bd_max_builds'], $maxBuildQueue));
                }

                $db = $this->db;
                $db->startTransaction();
                $PLANET = $db->selectSingle("SELECT * FROM %%PLANETS%% WHERE id = :planetId FOR UPDATE;", [':planetId' => $PLANET['id']]);
                $this->buildAuftr($buildTodo);
                $db->commit();
                $this->redirectTo('game?page=shipyard&mode=' . $mode);
            }

            if ($action == "delete") {
                $db = $this->db;
                $db->startTransaction();
                $PLANET = $db->selectSingle("SELECT * FROM %%PLANETS%% WHERE id = :planetId FOR UPDATE;", [':planetId' => $PLANET['id']]);
                $this->cancelAuftr();
                $db->commit();
                $this->redirectTo('game?page=shipyard&mode=' . $mode);
            }
        }

        $elementInQueue = [];
        $ElementQueue = !empty($PLANET['b_hangar_id']) ? unserialize($PLANET['b_hangar_id']) : [];
        $buildList = [];
        $elementList = [];

        if (!empty($ElementQueue)) {
            $Shipyard = [];
            $QueueTime = 0;
            foreach ($ElementQueue as $Element) {
                if (empty($Element)) {
                    continue;
                }

                $elementInQueue[$Element[self::QUEUE_ELEMENT_ID]] = true;
                $ElementTime = $this->buildFunctions()->getBuildingTime($USER, $PLANET, $Element[self::QUEUE_ELEMENT_ID]);
                $QueueTime += $ElementTime * $Element[self::QUEUE_AMMOUNT];
                $Shipyard[] = [$LNG['tech'][$Element[self::QUEUE_ELEMENT_ID]], $Element[self::QUEUE_AMMOUNT], $ElementTime, $Element[self::QUEUE_ELEMENT_ID]];
            }

            $buildList = [
                'Queue' => $Shipyard,
                'b_hangar_id_plus' => $PLANET['b_hangar'],
                'queue_time' => max($QueueTime - $PLANET['b_hangar'], 0),
                'pretty_time_b_hangar' => $this->viewHelpers()->prettyTime(max($QueueTime - $PLANET['b_hangar'], 0)),
            ];
        }

        if ($mode == 'defense') {
            $elementIDs = array_merge($reslist['defense'], $reslist['missile']);
        } else {
            $elementIDs = $reslist['fleet'];
        }

        $Missiles = [];

        foreach ($reslist['missile'] as $elementID) {
            $Missiles[$elementID] = $PLANET[$resource[$elementID]];
        }

        $MaxMissiles = $this->buildFunctions()->getMaxConstructibleRockets($PLANET, $Missiles);
        $SolarEnergy = round((($PLANET['temp_max'] + 160) / 6) * $this->configs->get()->energy_multiplier, 1);

        foreach ($elementIDs as $Element) {
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

            $costResources = $this->buildFunctions()->getElementPrice($USER, $PLANET, $Element);
            $costOverflow = $this->buildFunctions()->getRestPrice($USER, $PLANET, $Element, $costResources);
            $elementTime = $this->buildFunctions()->getBuildingTime($USER, $PLANET, $Element, $costResources);
            $buyable = $this->buildFunctions()->isElementBuyable($USER, $PLANET, $Element, $costResources);
            $maxBuildable = $this->buildFunctions()->getMaxConstructibleElements($USER, $PLANET, $Element, $costResources);
            if (str_starts_with(strval($Element), 2)) {
                $type = $this->buildFunctions()->getType($Element);
            } elseif (str_starts_with(strval($Element), 4)) {
                $type = "defense";
            } else {
                $type = "missile";
            }

            if (isset($MaxMissiles[$Element])) {
                $maxBuildable = min($maxBuildable, $MaxMissiles[$Element]);
            }

            $AlreadyBuild = in_array($Element, $reslist['one'])
                && (isset($elementInQueue[$Element]) || $PLANET[$resource[$Element]] != 0);

            $fade = ($USER['missing_requirements_opacity'] && !empty($requirementsList))
                || ($USER['missing_resources_opacity'] && !$buyable)
                || $AlreadyBuild;

            $elementList[$Element] = [
                'id' => $Element,
                'available' => $PLANET[$resource[$Element]],
                'costResources' => $costResources,
                'costOverflow' => $costOverflow,
                'elementTime' => $elementTime,
                'buyable' => $buyable,
                'maxBuildable' => floatToString($maxBuildable),
                'AlreadyBuild' => $AlreadyBuild,
                'requirements' => $requirementsList,
                'fade' => $fade,
                'type' => $type,
            ];
        }

        $civil = "";
        $military = "";
        $recycler = "";
        foreach ($elementList as $Element => $ElementData) {
            if ($ElementData['type'] == 'civil') {
                $civil .= "#s" . $Element . ", ";
            } elseif ($ElementData['type'] == 'military') {
                $military .= "#s" . $Element . ", ";
            } elseif ($ElementData['type'] == 'recycler') {
                $recycler .= "#s" . $Element . ", ";
            }
        }
        $civil = rtrim($civil, ", ");
        $military = rtrim($military, ", ");
        $recycler = rtrim($recycler, ", ");

        $hangarFilters = $this->hangarFilters()->parse($USER['hangar_filters'] ?? null);
        $hangarShipIds = $this->hangarFilters()->shipIds($hangarFilters);
        $hangarShipOptions = [];
        foreach ($reslist['fleet'] as $shipId) {
            $shipId = (int) $shipId;
            if (in_array($shipId, $hangarShipIds, true)) {
                continue;
            }
            $hangarShipOptions[$shipId] = $LNG['shortNames'][$shipId] ?? $LNG['tech'][$shipId] ?? ('#' . $shipId);
        }

        $this->assign([
            'umode'       => $USER['urlaubs_modus'],
            'elementList' => $elementList,
            'NotBuilding' => $NotBuilding,
            'BuildList' => $buildList,
            'maxlength' => strlen($this->configs->get()->max_fleet_per_build),
            'mode' => $mode,
            'SolarEnergy' => $SolarEnergy,
            'civil' => $civil,
            'military' => $military,
            'recycler' => $recycler,
            'hangarFiltersEnabled' => !empty($USER['hangar_filters_enabled']),
            'hangarGroupCivil' => $this->hangarFilters()->hasGroup($hangarFilters, 'civil'),
            'hangarGroupMilitary' => $this->hangarFilters()->hasGroup($hangarFilters, 'military'),
            'hangarGroupRecycler' => $this->hangarFilters()->hasGroup($hangarFilters, 'recycler'),
            'hangarGroupAll' => $this->hangarFilters()->hasGroup($hangarFilters, 'all'),
            'hangarShipIds' => $hangarShipIds,
            'hangarShipOptions' => $hangarShipOptions,
            'hangarShipOrder' => array_map('intval', array_values($reslist['fleet'])),
        ]);

        return $this->render('game/page.shipyard.default.html.twig');
    }

    /**
     * Persist pinned hangar ship filters without changing group visibility.
     */
    public function saveFilters()
    {
        $USER = & $this->state->USER;
        $ships = $this->http()->_GP('ships', []);
        $filters = $this->hangarFilters()->mergeShips(
            $this->hangarFilters()->parse($USER['hangar_filters'] ?? null),
            $ships,
        );
        $encoded = $this->hangarFilters()->encode($filters);

        $this->db->update(
            'UPDATE %%USERS_SETTINGS%% SET hangar_filters = :filters WHERE user_id = :userID;',
            [
                ':filters' => $encoded,
                ':userID' => $USER['id'],
            ],
        );

        $USER['hangar_filters'] = $encoded;

        $this->sendJSON([
            'ok' => true,
            'filters' => $filters,
        ]);
    }
}
