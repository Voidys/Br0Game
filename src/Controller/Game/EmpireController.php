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
use App\Game\Core\FleetFunctions;
use App\Game\Core\PlayerUtil;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\Singleton;
use App\Game\Core\Statbuilder;

/** Empire overview: resources, buildings, research and fleet of all planets (or moons) plus score charts. */
#[\App\Routing\GamePage('game.php', 'empire')]
#[\App\Routing\GamePage('game.php', 'empire', uniPrefix: true)]
class EmpireController extends AbstractGameController
{
    public static $requireModule = 'MODULE_IMPERIUM';

    public function initialize(): void
    {
        parent::initialize();
    }

    public function show(): Response
    {
        $USER = & $this->state->USER;
        $resource = & $this->state->resource;
        $reslist = & $this->state->reslist;
        $db = $this->db;
        $db->startTransaction();
        $u = $db->selectSingle("SELECT * FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id WHERE u.id = :userID FOR UPDATE;", [':userID' => $USER['id']]);
        // Calc from the locked user row. The page-start copy is stale under
        // concurrent requests and SavePlanetToDB writes its b_tech* fields back.
        if (!empty($u)) {
            $USER = array_merge($USER, $u);
        }
        $order = $USER['planet_sort_order'] == 1 ? 'DESC' : 'ASC';

        $sql = "SELECT * FROM %%PLANETS%% WHERE id_owner = :userID AND destruyed = '0' ORDER BY ";
        switch ($USER['planet_sort']) {
            case 2:
                $sql .= 'name ' . $order;
                break;
            case 1:
                $sql .= 'galaxy ' . $order . ', system ' . $order . ', planet ' . $order . ', planet_type ' . $order;
                break;
            default:
                $sql .= 'id ' . $order;
                break;
        }
        $sql .= " FOR UPDATE";
        $PlanetsRAW = $db->select($sql, [
            ':userID' => $USER['id'],
        ]);

        $PLANETS = [];

        $PlanetRess = new ResourceUpdate($this->db, $this->configs, $this->state);

        foreach ($PlanetsRAW as $CPLANET) {
            [$USER, $CPLANET] = $PlanetRess->CalcResource($USER, $CPLANET, true);

            $PLANETS[] = $CPLANET;
            unset($CPLANET);
        }

        $db->commit();

        $planetList = [];
        $config = $this->configs->get($USER['universe']);

        if (!empty($USER['b_tech_queue'])) {
            $researchque = StoredData::decode($USER['b_tech_queue']);

            $researchid = $researchque[0][0];
            $researchlvl = $researchque[0][1];
            $planetid = $researchque[0][4];
        } else {
            $researchid = -1;
            $researchlvl = -1;
            $planetid = -1;
        }
        $this->assign([
            'researchq_id'      => $researchid,
            'researchq_lvl'     => $researchlvl,
            'researchq_planet'  => $planetid,
        ]);

        $sumBuildings = [];
        foreach ($reslist['build'] as $elementID) {
            if (!$this->buildFunctions()->isEnabled($elementID)) {
                continue;
            }
            $sumBuildings[1][$elementID]['sum'] = 0;
            $sumBuildings[1][$elementID]['cnt'] = 0;
            $sumBuildings[3][$elementID]['sum'] = 0;
            $sumBuildings[3][$elementID]['cnt'] = 0;
        }

        $statbuidler = new Statbuilder($this->db, $this->configs, $this->state);

        $ownFleets = $db->select(
            "SELECT `ships`, `resource_metal`, `resource_crystal`, `resource_deuterium`, `start_id`, `end_id`, `mission`, `hasCanceled` FROM %%FLEETS%%
                WHERE `owner` = :userID;",
            [':userID' => $USER['id']],
        );

        foreach ($PLANETS as $Planet) {
            $planetList['points'][$Planet['id']] = $statbuidler->getBuildingScoreByPlanet($Planet)['points'];
            $planetList['image'][$Planet['id']]['id'] = $Planet['id'];
            $planetList['name'][$Planet['id']] = $Planet['name'];
            $planetList['image'][$Planet['id']]['picture'] = $Planet['image'];
            $planetList['image'][$Planet['id']]['planet_style'] = $Planet['planet_style'];

            $planetList['coords'][$Planet['id']]['galaxy'] = $Planet['galaxy'];
            $planetList['coords'][$Planet['id']]['system'] = $Planet['system'];
            $planetList['coords'][$Planet['id']]['planet'] = $Planet['planet'];

            $planetList['field'][$Planet['id']]['current'] = $Planet['field_current'];
            $planetList['field'][$Planet['id']]['max'] = $this->playerUtil()->calculateMaxPlanetFields($Planet);

            $planetList['temp'][$Planet['id']]['min'] = $Planet['temp_min'];
            $planetList['temp'][$Planet['id']]['max'] = $Planet['temp_max'];

            $planetList['energy_used'][$Planet['id']] = $Planet['energy'] + $Planet['energy_used'];

            $planetList['resource'][RESOURCE_METAL][$Planet['id']] = $Planet['metal'];
            $planetList['resource'][RESOURCE_CRYSTAL][$Planet['id']] = $Planet['crystal'];
            $planetList['resource'][RESOURCE_DEUT][$Planet['id']] = $Planet['deuterium'];
            $planetList['resource'][RESOURCE_ENERGY][$Planet['id']] = $Planet['energy'] + $Planet['energy_used'];

            if ($Planet['planet_type'] == 1) {
                $basic[RESOURCE_METAL][$Planet['id']] = $config->metal_basic_income * ($config->resource_multiplier / 2500);
                $basic[RESOURCE_CRYSTAL][$Planet['id']] = $config->crystal_basic_income * ($config->resource_multiplier / 2500);
                $basic[RESOURCE_DEUT][$Planet['id']] = $config->deuterium_basic_income * ($config->resource_multiplier / 2500);
                $planetList['resourceFull'][RESOURCE_METAL][$Planet['id']] = floor(max($Planet['metal_max'] - $Planet['metal'], 0) / max(1, $Planet['metal_perhour'] + $basic[901][$Planet['id']]));
                $planetList['resourceFull'][RESOURCE_CRYSTAL][$Planet['id']] = floor(max($Planet['crystal_max'] - $Planet['crystal'], 0) / max(1, $Planet['crystal_perhour'] + $basic[902][$Planet['id']]));
                $planetList['resourceFull'][RESOURCE_DEUT][$Planet['id']] = floor(max($Planet['deuterium_max'] - $Planet['deuterium'], 0) / max($basic[903][$Planet['id']] + $Planet['deuterium_perhour'], 1));
            } else {
                $basic[RESOURCE_METAL][$Planet['id']] = 0;
                $basic[RESOURCE_CRYSTAL][$Planet['id']] = 0;
                $basic[RESOURCE_DEUT][$Planet['id']] = 0;
            }
            if (!empty($Planet['b_building_id'])) {
                $Queue = StoredData::decode($Planet['b_building_id']);
                $planetList['currentBuilding'][$Planet['id']] = $Queue[0][0];
                $planetList['currentBuildinglvl'][$Planet['id']] = $Queue[0][1];
            } else {
                $planetList['currentBuilding'][$Planet['id']] = -1;
                $planetList['currentBuildinglvl'][$Planet['id']] = -1;
            }

            $planetList['resourcePerHour'][RESOURCE_METAL][$Planet['id']] = $basic[RESOURCE_METAL][$Planet['id']] + $Planet['metal_perhour'];
            $planetList['resourcePerHour'][RESOURCE_CRYSTAL][$Planet['id']] = $basic[RESOURCE_CRYSTAL][$Planet['id']] + $Planet['crystal_perhour'];
            $planetList['resourcePerHour'][RESOURCE_DEUT][$Planet['id']] = $basic[RESOURCE_DEUT][$Planet['id']] + $Planet['deuterium_perhour'];

            $planetList['resourceProductionPercent'][RESOURCE_METAL][$Planet['id']] = $Planet['metal_mine_porcent'];
            $planetList['resourceProductionPercent'][RESOURCE_CRYSTAL][$Planet['id']] = $Planet['crystal_mine_porcent'];
            $planetList['resourceProductionPercent'][RESOURCE_DEUT][$Planet['id']] = $Planet['deuterium_sintetizer_porcent'];

            $planetList['planet_type'][$Planet['id']] = $Planet['planet_type'];

            foreach ($reslist['build'] as $elementID) {
                if (!$this->buildFunctions()->isEnabled($elementID)) {
                    continue;
                }
                $planetList['build'][$elementID][$Planet['id']] = $Planet[$resource[$elementID]];

                if ($Planet[$resource[$elementID]] > 0 && in_array($elementID, $reslist['allow'][$Planet['planet_type']])) {
                    $sumBuildings[$Planet['planet_type']][$elementID]['sum'] += $Planet[$resource[$elementID]];
                    $sumBuildings[$Planet['planet_type']][$elementID]['cnt'] += 1;
                }

                $planetList['buildable'][$elementID][$Planet['id']] = false;
                if ($this->buildFunctions()->isTechnologieAccessible($USER, $Planet, $elementID)) {
                    $levelToBuild = $Planet[$resource[$elementID]] + 1;
                    $costResources = $this->buildFunctions()->getElementPrice($USER, $Planet, $elementID, false, $levelToBuild);
                    $planetList['buildable'][$elementID][$Planet['id']] = $this->buildFunctions()->isElementBuyable($USER, $Planet, $elementID, $costResources);
                }
            }

            foreach ($reslist['fleet'] as $elementID) {
                if (!$this->buildFunctions()->isEnabled($elementID)) {
                    continue;
                }
                $planetList['fleet'][$elementID][$Planet['id']] = $Planet[$resource[$elementID]];
                $planetList['currentShipyard'][$elementID][$Planet['id']] = 0;
                $planetList['currentFlying'][$elementID][$Planet['id']] = 0;

                $planetList['buildable'][$elementID][$Planet['id']] = false;
                if ($this->buildFunctions()->isTechnologieAccessible($USER, $Planet, $elementID)) {
                    $costResources = $this->buildFunctions()->getElementPrice($USER, $Planet, $elementID, false, 1);
                    $planetList['buildable'][$elementID][$Planet['id']] = $this->buildFunctions()->isElementBuyable($USER, $Planet, $elementID, $costResources);
                }
            }

            foreach ($reslist['defense'] as $elementID) {
                if (!$this->buildFunctions()->isEnabled($elementID)) {
                    continue;
                }
                $planetList['defense'][$elementID][$Planet['id']] = $Planet[$resource[$elementID]];
                $planetList['currentShipyard'][$elementID][$Planet['id']] = 0;

                $planetList['buildable'][$elementID][$Planet['id']] = false;
                if ($this->buildFunctions()->isTechnologieAccessible($USER, $Planet, $elementID)) {
                    $costResources = $this->buildFunctions()->getElementPrice($USER, $Planet, $elementID, false, 1);
                    $planetList['buildable'][$elementID][$Planet['id']] = $this->buildFunctions()->isElementBuyable($USER, $Planet, $elementID, $costResources);
                }
            }

            foreach ($reslist['missile'] as $elementID) {
                if (!$this->buildFunctions()->isEnabled($elementID)) {
                    continue;
                }
                $planetList['missiles'][$elementID][$Planet['id']] = $Planet[$resource[$elementID]];
                $planetList['currentShipyard'][$elementID][$Planet['id']] = 0;
                $planetList['currentFlying'][$elementID][$Planet['id']] = 0;

                $planetList['buildable'][$elementID][$Planet['id']] = false;
                if ($this->buildFunctions()->isTechnologieAccessible($USER, $Planet, $elementID)) {
                    $costResources = $this->buildFunctions()->getElementPrice($USER, $Planet, $elementID, false, 1);
                    $planetList['buildable'][$elementID][$Planet['id']] = $this->buildFunctions()->isElementBuyable($USER, $Planet, $elementID, $costResources);
                }
            }

            // Special case 'MISSION_STATION': will be selected for correct 'start_id'/'end_id' only depending on canceld status
            // Special case 'MISSION_TRANSPORT': ships return only to 'start_id', ress return to 'end_id' except if canceled
            // Others: ships and ress will return to 'start_id'
            // (all fleets of the user are loaded once above, the conditions are applied per planet here)
            $fleets = array_filter($ownFleets, static fn(array $fleet): bool => (
                $fleet['start_id'] == $Planet['id'] && ($fleet['mission'] != MISSION_STATION || $fleet['hasCanceled'] == 1)
            ) || (
                $fleet['end_id'] == $Planet['id']
                && ($fleet['mission'] == MISSION_STATION || $fleet['mission'] == MISSION_TRANSPORT)
                && $fleet['hasCanceled'] == 0
            ));

            $planetList['currentTransported'][RESOURCE_METAL][$Planet['id']] = 0;
            $planetList['currentTransported'][RESOURCE_CRYSTAL][$Planet['id']] = 0;
            $planetList['currentTransported'][RESOURCE_DEUT][$Planet['id']] = 0;

            foreach ($fleets as $fleetID => $fleet) {
                // Special case 'MISSION_TRANSPORT': ships return only to 'start_id'
                if ($fleet['mission'] != MISSION_TRANSPORT || $fleet['start_id'] == $Planet['id']) {
                    $fleetArray = $this->fleetFunctions()->unserialize($fleet['ships']);
                    foreach ($fleetArray as $elementID => $amount) {
                        $planetList['currentFlying'][$elementID][$Planet['id']] += $amount;
                    }
                }

                // Special case 'MISSION_TRANSPORT': ress return to 'end_id' except if canceled
                if ($fleet['mission'] != MISSION_TRANSPORT
                    || ($fleet['start_id'] == $Planet['id'] && $fleet['hasCanceled'])
                    || ($fleet['end_id'] == $Planet['id'] && !$fleet['hasCanceled'])
                ) {
                    $planetList['currentTransported'][RESOURCE_METAL][$Planet['id']] += $fleet['resource_metal'];
                    $planetList['currentTransported'][RESOURCE_CRYSTAL][$Planet['id']] += $fleet['resource_crystal'];
                    $planetList['currentTransported'][RESOURCE_DEUT][$Planet['id']] += $fleet['resource_deuterium'];
                }
            }

            if (!empty($Planet['b_hangar_id'])) {
                foreach (StoredData::decode($Planet['b_hangar_id']) as $que) {
                    $planetList['currentShipyard'][$que[0]][$Planet['id']] += $que[1];
                }
            }

            foreach ($reslist['tech'] as $elementID) {
                if (!$this->buildFunctions()->isEnabled($elementID)) {
                    continue;
                }

                $planetList['tech'][$elementID][$Planet['id']] = -1;
                $planetList['buildable'][$elementID][$Planet['id']] = false;
                if ($this->buildFunctions()->isTechnologieAccessible($USER, $Planet, $elementID)) {
                    $planetList['tech'][$elementID][$Planet['id']] = $USER[$resource[$elementID]];
                    $levelToBuild = $USER[$resource[$elementID]] + 1;
                    $costResources = $this->buildFunctions()->getElementPrice($USER, $Planet, $elementID, false, $levelToBuild);
                    $planetList['buildable'][$elementID][$Planet['id']] = $this->buildFunctions()->isElementBuyable($USER, $Planet, $elementID, $costResources);
                } elseif ($Planet[$resource[RESEARCH_LABORATORY]] > 0) {
                    $planetList['tech'][$elementID][$Planet['id']] = 0;
                }
            }
        }

        foreach ($reslist['build'] as $elementID) {
            if (!$this->buildFunctions()->isEnabled($elementID)) {
                continue;
            }
            if (in_array($elementID, $reslist['allow'][1]) && in_array($elementID, $reslist['allow'][3])
                    && ($sumBuildings[1][$elementID]['cnt'] + $sumBuildings[3][$elementID]['cnt']) > 0) {
                $avgBuildings[$elementID] = ($sumBuildings[1][$elementID]['sum'] + $sumBuildings[3][$elementID]['sum'])
                    / ($sumBuildings[1][$elementID]['cnt'] + $sumBuildings[3][$elementID]['cnt']);
            } elseif (in_array($elementID, $reslist['allow'][1]) && $sumBuildings[1][$elementID]['cnt'] > 0) {
                $avgBuildings[$elementID] = $sumBuildings[1][$elementID]['sum'] / $sumBuildings[1][$elementID]['cnt'];
            } elseif ($sumBuildings[3][$elementID]['cnt'] > 0) {
                $avgBuildings[$elementID] = $sumBuildings[3][$elementID]['sum'] / $sumBuildings[3][$elementID]['cnt'];
            } else {
                $avgBuildings[$elementID] = 0;
            }
        }

        $currentPlanetCount = $this->playerUtil()->currentPlanetCount($USER);
        $maxPlanetCount = $this->playerUtil()->maxPlanetCount($USER);
        $enablePlanetStyles = $USER['enable_planet_styles'];
        $chartPoints = $this->playerUtil()->getChartPoints($USER['id'], $USER, TIME_1_WEEK * 2);

        $this->assign([
            'colspan'                       => count($PLANETS) + 2,
            'chartPoints'                   => $chartPoints,
            'planetList'                    => $planetList,
            'avgBuildings'                  => $avgBuildings,
            'currentPlanetCount'            => $currentPlanetCount,
            'maxPlanetCount'                => $maxPlanetCount,
            'enablePlanetStyles'            => $enablePlanetStyles,
            'enableShowProdPerHour'         => $this->state->requestCookies['empire_showProdPerHour'] ?? true,
            'enableShowStorageFilled'       => $this->state->requestCookies['empire_showStorageFilled'] ?? true,
            'enableHiglightBuildable'       => $this->state->requestCookies['empire_highlightBuildable'] ?? false,
            'enableHiglightNonBuildable'    => $this->state->requestCookies['empire_highlightNonBuildable'] ?? false,
        ]);

        $this->addScript('empire.js');

        return $this->render('game/page.empire.default.html.twig');
    }
}
