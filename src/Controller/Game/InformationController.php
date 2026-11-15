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
use App\Game\Core\HTTP;
use App\Game\Core\FormulaEvaluator;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\Singleton;

/**
 * Element information popup: description, production/storage tables, jump gate and missile actions (modes: show,
 * sendFleet, destroyMissiles).
 */
#[\App\Routing\GamePage('game.php', 'information')]
#[\App\Routing\GamePage('game.php', 'information', uniPrefix: true)]
class InformationController extends AbstractGameController
{
    public static $requireModule = 'MODULE_INFORMATION';

    // protected $disableEcoSystem = true;

    public function initialize(): void
    {
        parent::initialize();
    }

    protected function getNextJumpWaitTime($lastTime, $jumpLevel)
    {
        if ($jumpLevel >= 15) {
            $cooldownMinutes = 10;
        } elseif ($jumpLevel >= 1) {
            // floor(0.25*x*x - 7*x + 60) == intdiv(x*x - 28*x + 240, 4)
            $x = (int) $jumpLevel - 1;
            $cooldownMinutes = intdiv($x * $x - 28 * $x + 240, 4);
        } else {
            $cooldownMinutes = 60;
        }

        return $lastTime + ceil(($cooldownMinutes * 60) / $this->configs->get()->jumpgate_factor);
    }

    public function sendFleet()
    {
        $PLANET = & $this->state->PLANET;
        $USER = & $this->state->USER;
        $resource = & $this->state->resource;
        $LNG = & $this->state->LNG;
        $reslist = & $this->state->reslist;
        $db = $this->db;

        if (isVacationMode($USER)) {
            $this->sendJSON([
                'message'   => $LNG['fa_vacation_mode_current'],
                'error'     => true,
            ]);
        }

        // The source planet needs a jump gate itself
        if ($PLANET[$resource[43]] <= 0) {
            $this->sendJSON([
                'message'   => $LNG['in_jump_gate_doesnt_have_one'],
                'error'     => true,
            ]);
        }

        $NextJumpTime = $this->getNextJumpWaitTime($PLANET['last_jump_time'], $PLANET['sprungtor']);

        if ($this->state->runtime->timestamp < $NextJumpTime) {
            $this->sendJSON([
                'message'   => $LNG['in_jump_gate_already_used'] . ' ' . $this->viewHelpers()->prettyTime($NextJumpTime - $this->state->runtime->timestamp),
                'error'     => true,
            ]);
        }

        $TargetPlanet = (int) $this->http()->_GP('jmpto', (int) $PLANET['id']);

        $sql = "SELECT id, last_jump_time, sprungtor FROM %%PLANETS%% WHERE id = :targetID AND id_owner = :userID AND"
            . " sprungtor > 0;";
        $TargetGate = $db->selectSingle($sql, [
            ':targetID' => $TargetPlanet,
            ':userID'   => $USER['id'],
        ]);

        if (!isset($TargetGate) || $TargetPlanet == $PLANET['id']) {
            $this->sendJSON([
                'message' => $LNG['in_jump_gate_doesnt_have_one'],
                'error' => true,
            ]);
        }

        $NextJumpTime   = $this->getNextJumpWaitTime($TargetGate['last_jump_time'], $TargetGate['sprungtor']);

        if ($this->state->runtime->timestamp < $NextJumpTime) {
            $this->sendJSON([
                'message' => $LNG['in_jump_gate_not_ready_target'] . ' ' . $this->viewHelpers()->prettyTime($NextJumpTime - $this->state->runtime->timestamp),
                'error' => true,
            ]);
        }

        $ShipArray      = [];
        $SubQueryOri    = "";
        $SubQueryDes    = "";
        $Ships          = $this->http()->_GP('ship', []);
        if (!is_array($Ships)) {
            $Ships = [];
        }

        foreach ($reslist['fleet'] as $Ship) {
            if (!isset($Ships[$Ship]) || $Ship == 212) {
                continue;
            }

            $ShipArray[$Ship]   = max(0, min((int) $Ships[$Ship], (int) $PLANET[$resource[$Ship]]));

            if (empty($ShipArray[$Ship])) {
                continue;
            }

            $SubQueryOri        .= $resource[$Ship] . " = " . $resource[$Ship] . " - " . $ShipArray[$Ship] . ", ";
            $SubQueryDes        .= $resource[$Ship] . " = " . $resource[$Ship] . " + " . $ShipArray[$Ship] . ", ";
            $PLANET[$resource[$Ship]] -= $ShipArray[$Ship];
        }

        if (empty($SubQueryOri)) {
            $this->sendJSON([
                'message' => $LNG['in_jump_gate_error_data'],
                'error' => true,
            ]);
        }

        $array_merge =  [':planetID' => $PLANET['id'],':jumptime' => $this->state->runtime->timestamp];
        $sql  = "UPDATE %%PLANETS%% SET " . $SubQueryOri . " `last_jump_time` = :jumptime WHERE id = :planetID;";
        $db->update($sql, $array_merge);

        $sql  = "UPDATE %%PLANETS%% SET " . $SubQueryDes . " `last_jump_time` = :jumptime WHERE id = :targetID;";
        $db->update($sql, [
            ':targetID' => $TargetPlanet,
            ':jumptime' => $this->state->runtime->timestamp,
        ]);

        $PLANET['last_jump_time']   = $this->state->runtime->timestamp;
        $NextJumpTime   = $this->getNextJumpWaitTime($PLANET['last_jump_time'], $PLANET['sprungtor']);
        $this->sendJSON([
            'message' => sprintf($LNG['in_jump_gate_done'], $this->viewHelpers()->prettyTime($NextJumpTime - $this->state->runtime->timestamp)),
            'error' => false,
        ]);
    }

    private function getAvailableFleets()
    {
        $reslist = & $this->state->reslist;
        $resource = & $this->state->resource;
        $PLANET = & $this->state->PLANET;
        $fleetList  = [];

        foreach ($reslist['fleet'] as $Ship) {
            if ($Ship == 212 || $PLANET[$resource[$Ship]] <= 0) {
                continue;
            }

            $fleetList[$Ship]   = $PLANET[$resource[$Ship]];
        }

        return $fleetList;
    }

    public function destroyMissiles()
    {
        $resource = & $this->state->resource;
        $PLANET = & $this->state->PLANET;
        $db = $this->db;

        $Missle = $this->http()->_GP('missile', []);
        if (!is_array($Missle)) {
            $Missle = [];
        }
        $PLANET[$resource[502]] -= max(0, min((int) ($Missle[502] ?? 0), (int) $PLANET[$resource[502]]));
        $PLANET[$resource[503]] -= max(0, min((int) ($Missle[503] ?? 0), (int) $PLANET[$resource[503]]));

        $sql = "UPDATE %%PLANETS%% SET " . $resource[502] . " = :resource502Val, " . $resource[503]
            . " = :resource503Val WHERE id = :planetID;";
        $db->update($sql, [
            ':resource502Val'   => $PLANET[$resource[502]],
            ':resource503Val'   => $PLANET[$resource[503]],
            ':planetID'         => $PLANET['id'],
        ]);

        $this->sendJSON([$PLANET[$resource[502]], $PLANET[$resource[503]]]);
    }

    private function getTargetGates()
    {
        $resource = & $this->state->resource;
        $USER = & $this->state->USER;
        $PLANET = & $this->state->PLANET;
        $db = $this->db;

        $order = $USER['planet_sort_order'] == 1 ? "DESC" : "ASC" ;
        $sort  = $USER['planet_sort'];

        $sql = "SELECT id, name, galaxy, system, planet, last_jump_time, sprungtor, " . $resource[43]
            . " FROM %%PLANETS%% WHERE id != :planetID AND id_owner = :userID AND planet_type = '3' AND "
            . $resource[43] . " > 0 ORDER BY ";

        switch ($sort) {
            case 1:
                $sql .= 'galaxy ' . $order . ', system ' . $order . ', planet ' . $order . ', planet_type ' . $order;
                break;
            case 2:
                $sql .= 'name ' . $order;
                break;
            default:
                $sql .= 'id ' . $order;
                break;
        }


        $moonResult = $db->select($sql, [
            ':planetID'         => $PLANET['id'],
            ':userID'           => $USER['id'],
        ]);

        $moonList   = [];

        foreach ($moonResult as $moonRow) {
            $NextJumpTime               = $this->getNextJumpWaitTime($moonRow['last_jump_time'], $moonRow['sprungtor']);
            $moonList[$moonRow['id']]   = '[' . $moonRow['galaxy'] . ':' . $moonRow['system'] . ':' . $moonRow['planet']
                . '] ' . $moonRow['name']
                . ($this->state->runtime->timestamp < $NextJumpTime ? ' (' . $this->viewHelpers()->prettyTime($NextJumpTime - $this->state->runtime->timestamp) . ')' : '');
        }

        return $moonList;
    }

    public function show(): Response
    {
        $USER = & $this->state->USER;
        $PLANET = & $this->state->PLANET;
        $LNG = & $this->state->LNG;
        $resource = & $this->state->resource;
        $pricelist = & $this->state->pricelist;
        $reslist = & $this->state->reslist;
        $CombatCaps = & $this->state->CombatCaps;
        $ProdGrid = & $this->state->ProdGrid;
        $elementID  = $this->http()->_GP('id', 0);

        $this->setWindow('popup');

        $productionTable    = [];
        $FleetInfo          = [];
        $MissileList        = [];
        $gateData           = [];

        $CurrentLevel       = 0;

        $ressIDs            = array_merge([], $reslist['resstype'][1], $reslist['resstype'][2]);

        if (in_array($elementID, $reslist['prod'])) {
            /* Data for eval */
            $BuildEnergy        = $USER[$resource[113]];
            $BuildTemp          = $PLANET['temp_max'];
            $BuildLevelFactor   = $PLANET[$resource[$elementID] . '_porcent'];

            $CurrentLevel       = $PLANET[$resource[$elementID]];
            $BuildStartLvl      = max($CurrentLevel - 2, 0);
            for ($BuildLevel = $BuildStartLvl; $BuildLevel < $BuildStartLvl + 15; $BuildLevel++) {
                foreach ($ressIDs as $ID) {
                    if (!isset($ProdGrid[$elementID]['production'][$ID])) {
                        continue;
                    }

                    $Production = FormulaEvaluator::evaluate($ProdGrid[$elementID]['production'][$ID], compact('BuildLevel', 'BuildLevelFactor', 'BuildEnergy', 'BuildTemp'));

                    if (in_array($ID, $reslist['resstype'][2])) {
                        $Production *= $this->configs->get()->energy_multiplier;
                    } else {
                        $Production *= ($this->configs->get()->resource_multiplier / 2500);
                    }

                    $productionTable['production'][$BuildLevel][$ID]    = $Production;
                }
            }

            $productionTable['usedResource']    = array_keys($productionTable['production'][$BuildStartLvl]);
        }
        if (in_array($elementID, $reslist['storage'])) {
            $CurrentLevel       = $PLANET[$resource[$elementID]];
            $BuildStartLvl      = max($CurrentLevel - 2, 0);

            for ($BuildLevel = $BuildStartLvl; $BuildLevel < $BuildStartLvl + 15; $BuildLevel++) {
                foreach ($ressIDs as $ID) {
                    if (!isset($ProdGrid[$elementID]['storage'][$ID])) {
                        continue;
                    }

                    $production = round(FormulaEvaluator::evaluate($ProdGrid[$elementID]['storage'][$ID], compact('BuildLevel')));
                    $production *= $this->configs->get()->storage_multiplier;

                    $productionTable['storage'][$BuildLevel][$ID]   = $production;
                }
            }

            $productionTable['usedResource']    = array_keys($productionTable['storage'][$BuildStartLvl]);
        }
        if (in_array($elementID, $reslist['fleet'])) {
            $FleetInfo  = [
                'structure'     => $pricelist[$elementID]['cost'][901] + $pricelist[$elementID]['cost'][902],
                'tech1'         => $pricelist[$elementID]['tech1'],
                'tech2'         => $pricelist[$elementID]['tech2'],
                'tech3'         => $pricelist[$elementID]['tech3'],
                'attack'        => $CombatCaps[$elementID]['attack'],
                'shield'        => $CombatCaps[$elementID]['shield'],
                'capacity'      => $pricelist[$elementID]['capacity'],
                'speed1'        => $pricelist[$elementID]['speed1'],
                'speed2'        => $pricelist[$elementID]['speed2'],
                'speed3'        => $pricelist[$elementID]['speed3'],
                'consumption1'  => $pricelist[$elementID]['consumption1'],
                'consumption2'  => $pricelist[$elementID]['consumption2'],
                'consumption3'  => $pricelist[$elementID]['consumption3'],
                'rapidfire'     => [
                    'from'  => [],
                    'to'    => [],
                ],
            ];

            $fleetIDs   = array_merge($reslist['fleet'], $reslist['defense']);

            foreach ($fleetIDs as $fleetID) {
                if (isset($CombatCaps[$elementID]['sd']) && !empty($CombatCaps[$elementID]['sd'][$fleetID]) && $CombatCaps[$elementID]['sd'][$fleetID] > 1) {
                    $shoots = $CombatCaps[$elementID]['sd'][$fleetID];
                    $FleetInfo['rapidfire']['to'][$fleetID] = round(($shoots - 1) / $shoots * 100, 2);
                }

                if (isset($CombatCaps[$fleetID]['sd']) && !empty($CombatCaps[$fleetID]['sd'][$elementID]) && $CombatCaps[$fleetID]['sd'][$elementID] > 1) {
                    $shoots = $CombatCaps[$fleetID]['sd'][$elementID];
                    $FleetInfo['rapidfire']['from'][$fleetID] = round(($shoots - 1) / $shoots * 100, 2);
                }
            }
        }
        if (in_array($elementID, $reslist['defense'])) {
            $FleetInfo  = [
                'structure'     => $pricelist[$elementID]['cost'][901] + $pricelist[$elementID]['cost'][902],
                'attack'        => $CombatCaps[$elementID]['attack'],
                'shield'        => $CombatCaps[$elementID]['shield'],
                'rapidfire'     => [
                    'from'  => [],
                    'to'    => [],
                ],
            ];

            $fleetIDs   = array_merge($reslist['fleet'], $reslist['defense']);

            foreach ($fleetIDs as $fleetID) {
                if (isset($CombatCaps[$elementID]['sd']) && !empty($CombatCaps[$elementID]['sd'][$fleetID]) && $CombatCaps[$elementID]['sd'][$fleetID] > 1) {
                    $shoots = $CombatCaps[$elementID]['sd'][$fleetID];
                    $FleetInfo['rapidfire']['to'][$fleetID] = round(($shoots - 1) / $shoots * 100, 2);
                }

                if (isset($CombatCaps[$fleetID]['sd']) && !empty($CombatCaps[$fleetID]['sd'][$elementID]) && $CombatCaps[$fleetID]['sd'][$elementID] > 1) {
                    $shoots = $CombatCaps[$fleetID]['sd'][$elementID];
                    $FleetInfo['rapidfire']['from'][$fleetID] = round(($shoots - 1) / $shoots * 100, 2);
                }
            }
        }

        if ($elementID == 43 && $PLANET[$resource[43]] > 0) {
            $this->addScript('gate.js');
            $nextTime   = $this->getNextJumpWaitTime($PLANET['last_jump_time'], $PLANET['sprungtor']);
            $gateData   = [
                'nextTime'  => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $nextTime, $USER['timezone']),
                'restTime'  => max(0, $nextTime - $this->state->runtime->timestamp),
                'startLink' => $PLANET['name'] . ' ' . strip_tags(BuildPlanetAddressLink($PLANET)),
                'gateList'  => $this->getTargetGates(),
                'fleetList' => $this->getAvailableFleets(),
            ];
        } elseif ($elementID == 44 && $PLANET[$resource[44]] > 0) {
            $MissileList    = [
                502 => $PLANET[$resource[502]],
                503 => $PLANET[$resource[503]],
            ];
        }

        $this->assign([
            'elementID'             => $elementID,
            'productionTable'       => $productionTable,
            'CurrentLevel'          => $CurrentLevel,
            'MissileList'           => $MissileList,
            'FleetInfo'             => $FleetInfo,
            'gateData'              => $gateData,
            'spytechStatsEnabled'   => $this->viewHelpers()->isModuleAvailable(MODULE_SPYTECH_DEPENDENT_STATS),
        ]);

        return $this->render('game/page.information.default.html.twig');
    }
}
