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
use App\Game\Core\Player\PlanetQueries;
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

    /**
     * Timestamp at which a jump gate can be used again. $lastTime = last jump (timestamp), $jumpLevel = gate level: 60 minutes at level 0,
     * falling with the level along 0.25*x^2 - 7x + 60 (x = level - 1) down to 10 minutes from level 15, divided by `jumpgate_factor`.
     */
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

    /**
     * Jump gate jump (XHR). Request: jmpto (target gate planet id), ship[<id>] = counts. Both gates must be ready (cooldown above), counts are
     * limited to what the planet holds (solar satellites excluded); moves the ships to the target planet in two UPDATEs, sets `last_jump_time` on both
     * and answers JSON {message, error}.
     */
    public function sendFleet()
    {
        $USER = $this->state->USER;
        $resource = $this->state->resource;
        $LNG = $this->state->LNG;
        $reslist = $this->state->reslist;
        $db = $this->db;

        if (isVacationMode($USER)) {
            $this->sendJSON([
                'message'   => $LNG['fa_vacation_mode_current'],
                'error'     => true,
            ]);
        }

        // The source planet needs a jump gate itself
        if ($this->state->PLANET[$resource[43]] <= 0) {
            $this->sendJSON([
                'message'   => $LNG['in_jump_gate_doesnt_have_one'],
                'error'     => true,
            ]);
        }

        $NextJumpTime = $this->getNextJumpWaitTime($this->state->PLANET['last_jump_time'], $this->state->PLANET['sprungtor']);

        if ($this->state->runtime->timestamp < $NextJumpTime) {
            $this->sendJSON([
                'message'   => $LNG['in_jump_gate_already_used'] . ' ' . $this->viewHelpers()->prettyTime($NextJumpTime - $this->state->runtime->timestamp),
                'error'     => true,
            ]);
        }

        $TargetPlanet = (int) $this->http()->_GP('jmpto', (int) $this->state->PLANET['id']);

        $sql = "SELECT id, last_jump_time, sprungtor FROM %%PLANETS%% WHERE id = :targetID AND id_owner = :userID AND"
            . " sprungtor > 0;";
        $TargetGate = $db->selectSingle($sql, [
            ':targetID' => $TargetPlanet,
            ':userID'   => $USER['id'],
        ]);

        if (!isset($TargetGate) || $TargetPlanet == $this->state->PLANET['id']) {
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
        $shipsOut       = [];
        $Ships          = $this->http()->_GP('ship', []);
        if (!is_array($Ships)) {
            $Ships = [];
        }

        foreach ($reslist['fleet'] as $Ship) {
            if (!isset($Ships[$Ship]) || $Ship == 212) {
                continue;
            }

            $ShipArray[$Ship]   = max(0, min((int) $Ships[$Ship], (int) $this->state->PLANET[$resource[$Ship]]));

            if (empty($ShipArray[$Ship])) {
                continue;
            }

            $shipsOut[$resource[$Ship]] = $ShipArray[$Ship];
            $this->state->PLANET[$resource[$Ship]] -= $ShipArray[$Ship];
        }

        if (empty($shipsOut)) {
            $this->sendJSON([
                'message' => $LNG['in_jump_gate_error_data'],
                'error' => true,
            ]);
        }

        // ships leave the origin gate planet and arrive at the target; the amounts are clamped integers, the columns come from $resource
        $leave = $db->qb()->update($db->table('PLANETS'));
        $arrive = $db->qb()->update($db->table('PLANETS'));
        $index = 0;
        foreach ($shipsOut as $column => $amount) {
            $leave->set('`' . $column . '`', '`' . $column . '` - :amount' . $index)->setParameter('amount' . $index, $amount);
            $arrive->set('`' . $column . '`', '`' . $column . '` + :amount' . $index)->setParameter('amount' . $index, $amount);
            $index++;
        }
        $db->execute(
            $leave->set('`last_jump_time`', ':jumptime')
                ->where('id = :planetID')
                ->setParameter('jumptime', $this->state->runtime->timestamp)
                ->setParameter('planetID', $this->state->PLANET['id'])
        );
        $db->execute(
            $arrive->set('`last_jump_time`', ':jumptime')
                ->where('id = :targetID')
                ->setParameter('jumptime', $this->state->runtime->timestamp)
                ->setParameter('targetID', $TargetPlanet)
        );

        $this->state->PLANET['last_jump_time']   = $this->state->runtime->timestamp;
        $NextJumpTime   = $this->getNextJumpWaitTime($this->state->PLANET['last_jump_time'], $this->state->PLANET['sprungtor']);
        $this->sendJSON([
            'message' => $LNG->format('in_jump_gate_done', $this->viewHelpers()->prettyTime($NextJumpTime - $this->state->runtime->timestamp)),
            'error' => false,
        ]);
    }

    private function getAvailableFleets()
    {
        $reslist = $this->state->reslist;
        $resource = $this->state->resource;
        $PLANET = $this->state->PLANET;
        $fleetList  = [];

        foreach ($reslist['fleet'] as $Ship) {
            if ($Ship == 212 || $PLANET[$resource[$Ship]] <= 0) {
                continue;
            }

            $fleetList[$Ship]   = $PLANET[$resource[$Ship]];
        }

        return $fleetList;
    }

    /**
     * Removes missiles from the current silo (request: missile[502], missile[503] = interceptor / interplanetary missiles to destroy);
     * answers the remaining counts as JSON.
     */
    public function destroyMissiles()
    {
        $resource = $this->state->resource;
        $db = $this->db;

        $Missle = $this->http()->_GP('missile', []);
        if (!is_array($Missle)) {
            $Missle = [];
        }
        $this->state->PLANET[$resource[502]] -= max(0, min((int) ($Missle[502] ?? 0), (int) $this->state->PLANET[$resource[502]]));
        $this->state->PLANET[$resource[503]] -= max(0, min((int) ($Missle[503] ?? 0), (int) $this->state->PLANET[$resource[503]]));

        $db->execute(
            $db->qb()
                ->update($db->table('PLANETS'))
                ->set('`' . $resource[502] . '`', ':resource502Val')
                ->set('`' . $resource[503] . '`', ':resource503Val')
                ->where('id = :planetID')
                ->setParameter('resource502Val', $this->state->PLANET[$resource[502]])
                ->setParameter('resource503Val', $this->state->PLANET[$resource[503]])
                ->setParameter('planetID', $this->state->PLANET['id'])
        );

        $this->sendJSON([$this->state->PLANET[$resource[502]], $this->state->PLANET[$resource[503]]]);
    }

    /**
     * The player's other moons with a jump gate (planet type 3), ordered by the player's planet sort setting.
     */
    private function getTargetGates()
    {
        $resource = $this->state->resource;
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $db = $this->db;

        $order = $USER['planet_sort_order'] == 1 ? "DESC" : "ASC" ;
        $sort  = $USER['planet_sort'];

        $moonQuery = $db->qb()
            ->select('id', 'name', 'galaxy', '`system`', 'planet', 'last_jump_time', 'sprungtor', '`' . $resource[43] . '`')
            ->from($db->table('PLANETS'))
            ->where('id != :planetID')
            ->andWhere('id_owner = :userID')
            ->andWhere("planet_type = '3'")
            ->andWhere('`' . $resource[43] . '` > 0')
            ->setParameter('planetID', $PLANET['id'])
            ->setParameter('userID', $USER['id']);
        PlanetQueries::orderBySetting($moonQuery, $sort, $order);
        $moonResult = $db->fetchAll($moonQuery);

        $moonList   = [];

        foreach ($moonResult as $moonRow) {
            $NextJumpTime               = $this->getNextJumpWaitTime($moonRow['last_jump_time'], $moonRow['sprungtor']);
            $moonList[$moonRow['id']]   = '[' . $moonRow['galaxy'] . ':' . $moonRow['system'] . ':' . $moonRow['planet']
                . '] ' . $moonRow['name']
                . ($this->state->runtime->timestamp < $NextJumpTime ? ' (' . $this->viewHelpers()->prettyTime($NextJumpTime - $this->state->runtime->timestamp) . ')' : '');
        }

        return $moonList;
    }

    /**
     * Element info popup (request: id = element id): description, production and consumption table for 15 levels around the current one
     * for producers, storage capacity, rapid fire / combat values for ships and defense, missile list for the silo and the jump gate target list
     * for the jump gate. The tables evaluate the formulas from the game data (ProdGrid).
     */
    public function show(): Response
    {

        $LNG = $this->state->LNG;
        $resource = $this->state->resource;
        $pricelist = $this->state->pricelist;
        $reslist = $this->state->reslist;
        $CombatCaps = $this->state->CombatCaps;
        $ProdGrid = $this->state->ProdGrid;
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
            $BuildEnergy        = $this->state->USER[$resource[113]];
            $BuildTemp          = $this->state->PLANET['temp_max'];
            $BuildLevelFactor   = $this->state->PLANET[$resource[$elementID] . '_porcent'];

            $CurrentLevel       = $this->state->PLANET[$resource[$elementID]];
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
            $CurrentLevel       = $this->state->PLANET[$resource[$elementID]];
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

        if ($elementID == 43 && $this->state->PLANET[$resource[43]] > 0) {
            $this->addScript('gate.js');
            $nextTime   = $this->getNextJumpWaitTime($this->state->PLANET['last_jump_time'], $this->state->PLANET['sprungtor']);
            $gateData   = [
                'nextTime'  => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $nextTime, $this->state->USER['timezone']),
                'restTime'  => max(0, $nextTime - $this->state->runtime->timestamp),
                'startLink' => $this->state->PLANET['name'] . ' ' . strip_tags(BuildPlanetAddressLink($this->state->PLANET)),
                'gateList'  => $this->getTargetGates(),
                'fleetList' => $this->getAvailableFleets(),
            ];
        } elseif ($elementID == 44 && $this->state->PLANET[$resource[44]] > 0) {
            $MissileList    = [
                502 => $this->state->PLANET[$resource[502]],
                503 => $this->state->PLANET[$resource[503]],
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
