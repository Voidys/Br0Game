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
use App\Game\BattleEngine\BattleFleet;
use App\Game\BattleEngine\BattleHandler;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;

/**
 * Battle simulator: prepares the form with the player's techs and resources and renders the simulated report.
 */
#[\App\Routing\GamePage('game.php', 'battleSimulator')]
#[\App\Routing\GamePage('game.php', 'battleSimulator', uniPrefix: true)]
class BattleSimulatorController extends AbstractGameController
{
    public static $requireModule = 'MODULE_SIMULATOR';

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Runs a simulation (XHR). Request: battleinput (slots with fleets, techs and resources; sanitized, ids numeric and amounts capped). Throttled to 10
     * stored simulations per user per minute (marker `sim:<userId>` in RW.attacker). Simulates with the battle engine on virtual fleets (fixed 1:33:7
     * coordinates), stores the report in RW and answers the report id as JSON, or 0 for invalid or rejected input.
     */
    public function send()
    {
        $reslist = $this->state->reslist;
        $pricelist = $this->state->pricelist;
        $LNG = $this->state->LNG;
        if (!isset($this->state->requestInput['battleinput'])) {
            $this->sendJSON(0);
        }

        $BattleArray = $this->sanitizeBattleInput($this->state->requestInput['battleinput']);
        if ($BattleArray === null) {
            $this->sendJSON(0);
        }

        // Throttle: stored simulator reports are marked in `attacker`, at most 10 per user per minute
        $simMarker = 'sim:' . $this->state->USER['id'];
        $sql = "SELECT COUNT(*) AS cnt FROM %%RW%% WHERE `attacker` = :marker AND `time` > :since;";
        $recent = $this->db->selectSingle($sql, [
            ':marker' => $simMarker,
            ':since'  => $this->state->runtime->timestamp - 60,
        ], 'cnt');
        if ($recent >= 10) {
            $this->sendJSON(0);
        }

        $battleElementAmount = 0;
        $fleetDefend = [];
        $fleetAttack = [];
        foreach ($BattleArray as $BattleSlotID => $BattleSlot) {
            if (isset($BattleSlot[0]) && (array_sum($BattleSlot[0]) > 0 || $BattleSlotID == 0)) {
                $attackerPlayer = [
                    'id' => (1000 + $BattleSlotID + 1),
                    'username'  => $LNG['bs_atter'] . ' Nr.' . ($BattleSlotID + 1),
                    'military_tech' => getNumber($BattleSlot[0][MILITARY_TECH]),
                    'shield_tech' => getNumber($BattleSlot[0][SHIELD_TECH]),
                    'defence_tech' => getNumber($BattleSlot[0][DEFENCE_TECH]),
                ];

                foreach ($BattleSlot[0] as $ID => $Count) {
                    $isFleet = in_array($ID, $reslist['fleet']);
                    if (!$isFleet || getNumber($BattleSlot[0][$ID]) <= 0 || $ID == SHIP_SOLSAT) {
                        unset($BattleSlot[0][$ID]);
                    } elseif ($isFleet) {
                        $battleElementAmount += getNumber($BattleSlot[0][$ID]);
                    }
                }

                $fleetAttack[] = new BattleFleet(count($fleetAttack), $attackerPlayer, $BattleSlot[0], $this->simulatedFleetDetail());
            }

            if (isset($BattleSlot[1]) && (array_sum($BattleSlot[1]) > 0 || $BattleSlotID == 0)) {
                $defenderPlayer = [
                    'id' => (2000 + $BattleSlotID + 1),
                    'username'  => $LNG['bs_deffer'] . ' Nr.' . ($BattleSlotID + 1),
                    'military_tech' => getNumber($BattleSlot[1][MILITARY_TECH]),
                    'shield_tech' => getNumber($BattleSlot[1][SHIELD_TECH]),
                    'defence_tech' => getNumber($BattleSlot[1][DEFENCE_TECH]),
                ];

                foreach ($BattleSlot[1] as $ID => $Count) {
                    $isBattleElement = in_array($ID, $reslist['fleet']) || in_array($ID, $reslist['defense']);
                    if (!$isBattleElement || getNumber($BattleSlot[1][$ID]) <= 0) {
                        unset($BattleSlot[1][$ID]);
                    } elseif ($isBattleElement) {
                        $battleElementAmount += getNumber($BattleSlot[1][$ID]);
                    }
                }

                $fleetDefend[] = new BattleFleet(count($fleetDefend), $defenderPlayer, $BattleSlot[1], $this->simulatedFleetDetail());
            }
        }

        //Move defending planet to the end to match mission attack
        if (empty($fleetAttack) || !isset($fleetDefend[0])) {
            $this->sendJSON(0);
        }
        $tempFleet = $fleetDefend[0];
        unset($fleetDefend[0]);
        $fleetDefend[0] = $tempFleet;
        // the fleets are keyed by their list position (as before); the planet is fleet 0 and fires last

        if ($battleElementAmount > 135000000) {
            $this->sendJSON(0); // return
        }

        $LNG->includeData(['FLEET']);


        $battleHandler = new BattleHandler(false, $this->db, $this->configs, $this->state);
        $combatResult = $battleHandler->engine->fight($fleetAttack, $fleetDefend, $this->state->USER['universe'], true);

        if (!$combatResult) {
            $this->sendJSON(0); // return
        }

        if ($combatResult->won == ATTACKER) {
            $stealResource = $battleHandler->calculateSteal($fleetAttack, [
                'metal' => getNumber($BattleArray[0][1][RESOURCE_METAL]),
                'crystal' => getNumber($BattleArray[0][1][RESOURCE_CRYSTAL]),
                'deuterium' => getNumber($BattleArray[0][1][RESOURCE_DEUT]),
            ], $this->state->USER['universe'], true);
        } else {
            $stealResource = [
                RESOURCE_METAL => 0,
                RESOURCE_CRYSTAL => 0,
                RESOURCE_DEUT => 0,
            ];
        }
        $capacityNeeded = $battleHandler->calculateMinCapacity([
            'metal' => getNumber($BattleArray[0][1][RESOURCE_METAL]),
            'crystal' => getNumber($BattleArray[0][1][RESOURCE_CRYSTAL]),
            'deuterium' => getNumber($BattleArray[0][1][RESOURCE_DEUT]),
        ]);

        $debris = [];

        foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL] as $elementID) {
            $debris[$elementID] = $combatResult->debris->total($elementID);
        }

        $debrisTotal = array_sum($debris);

        $moonFactor = $this->configs->get()->moon_factor;
        $maxMoonChance = $this->configs->get()->moon_chance;

        $chanceCreateMoon = floor($debrisTotal / 100000 * $moonFactor);
        $chanceCreateMoon = min($chanceCreateMoon, $maxMoonChance);

        $stealResourceInformation = $LNG->format('bs_steal_raport',
            $this->viewHelpers()->prettyNumber(ceil($capacityNeeded / $pricelist[SHIP_SMALL_CARGO]['capacity'])),
            $LNG['tech'][SHIP_SMALL_CARGO],
            $this->viewHelpers()->prettyNumber(ceil($capacityNeeded / $pricelist[SHIP_LARGE_CARGO]['capacity'])),
            $LNG['tech'][SHIP_LARGE_CARGO],
        );

        $reportInfo = [
            'thisFleet'             => [
                'start_galaxy'    => 1,
                'start_system'    => 33,
                'start_planet'    => 7,
                'start_type'      => 1,
                'end_galaxy'      => 1,
                'end_system'      => 33,
                'end_planet'      => 7,
                'end_type'        => 1,
                'arrival_time'      => $this->state->runtime->timestamp,
            ],
            'debris'                => $debris,
            'stealResource'         => $stealResource,
            'moonChance'            => $chanceCreateMoon,
            'additionalChance'      => 0,
            'moonDestroy'           => false,
            'moonName'              => null,
            'moonDestroyChance'     => null,
            'moonDestroySuccess'    => null,
            'fleetDestroyChance'    => null,
            'fleetDestroySuccess'   => null,
            'additionalInfo'        => $stealResourceInformation,
        ];

        $reportData = $battleHandler->GenerateReport($combatResult, $reportInfo, SIM_FIGHT, $fleetAttack, $fleetDefend, $battleHandler->engine->getName());
        unset($reportData['repaired']);
        $reportID = bin2hex(random_bytes(16));

        $db = $this->db;

        $sql = "INSERT INTO %%RW%% SET rid = :reportID, raport = :reportData, time = :time, attacker = :marker, battleengine = :battleengine;";
        $db->insert($sql, [
            ':reportID'     => $reportID,
            ':reportData'   => $battleHandler->engine->getName() === 'rust' ? compressToBase64(json_encode($reportData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : StoredData::encode($reportData),
            ':time'         => $this->state->runtime->timestamp,
            ':marker'       => $simMarker,
            ':battleengine' => $battleHandler->engine->getName(),
        ]);

        $this->sendJSON($reportID);
    }

    /**
     * Fleet row of a simulated fleet: every slot starts and ends at the virtual position 1:33:7 and carries no resources.
     *
     * @return array<string, int>
     */
    private function simulatedFleetDetail(): array
    {
        return [
            'start_galaxy' => 1,
            'start_system' => 33,
            'start_planet' => 7,
            'start_type' => 1,
            'end_galaxy' => 1,
            'end_system' => 33,
            'end_planet' => 7,
            'end_type' => 1,
            'resource_metal' => 0,
            'resource_crystal' => 0,
            'resource_deuterium' => 0,
        ];
    }

    /**
     * Normalises the posted slots: only numeric ids, amounts cast to non-negative numbers (capped), techs capped.
     * Slot 0 needs both an attacker [0] and a defender [1] entry, otherwise null is returned.
     */
    private function sanitizeBattleInput(mixed $input): ?array
    {
        if (!is_array($input) || !isset($input[0][0], $input[0][1]) || !is_array($input[0][0]) || !is_array($input[0][1])) {
            return null;
        }

        $techs = [MILITARY_TECH, SHIELD_TECH, DEFENCE_TECH];
        $clean = [];
        foreach ($input as $slotID => $slot) {
            if (!is_numeric($slotID) || !is_array($slot) || count($clean) >= 16) {
                continue;
            }
            foreach ([0, 1] as $side) {
                if (!isset($slot[$side]) || !is_array($slot[$side])) {
                    continue;
                }
                foreach ($slot[$side] as $ID => $amount) {
                    if (!is_numeric($ID) || !is_scalar($amount)) {
                        continue;
                    }
                    $amount = floor(max(0, (float) getNumber($amount)));
                    $clean[(int) $slotID][$side][(int) $ID] = in_array((int) $ID, $techs)
                        ? min($amount, 255)
                        : min($amount, 135000000);
                }
            }
        }

        if (!isset($clean[0][0], $clean[0][1])) {
            return null;
        }
        foreach ([0, 1] as $side) {
            foreach ($techs as $tech) {
                $clean[0][$side][$tech] ??= 0;
            }
        }
        foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT] as $res) {
            $clean[0][1][$res] ??= 0;
        }
        foreach ($clean as $slotID => $slot) {
            foreach ($slot as $side => $values) {
                foreach ($techs as $tech) {
                    $clean[$slotID][$side][$tech] ??= 0;
                }
            }
        }

        return $clean;
    }

    /**
     * Simulator page. Request: slots (number of slots), action=moreslots (adds slots), battleinput[] (prefill, e.g. from a spy report; otherwise the form
     * starts with the player's own techs).
     */
    public function show(): Response
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $reslist = $this->state->reslist;
        $resource = $this->state->resource;
        $Slots = $this->http()->_GP('slots', 1);
        $action = $this->http()->_GP('action', '');

        $BattleArray[0][0][MILITARY_TECH] = $USER[$resource[MILITARY_TECH]];
        $BattleArray[0][0][SHIELD_TECH] = $USER[$resource[SHIELD_TECH]];
        $BattleArray[0][0][DEFENCE_TECH] = $USER[$resource[DEFENCE_TECH]];

        if (empty($this->state->requestInput['battleinput'])) {
            foreach ($reslist['resstype'][1] as $ID) {
                $BattleArray[0][0][$ID] = floor($PLANET[$resource[$ID]]);
            }
            foreach ($reslist['fleet'] as $ID) {
                $BattleArray[0][0][$ID] = $PLANET[$resource[$ID]];
            }
            foreach ($reslist['defense'] as $ID) {
                $BattleArray[0][0][$ID] = $PLANET[$resource[$ID]];
            }
        } else {
            $BattleArray = $this->http()->_GP('battleinput', []);
        }

        if ($action == 'moreslots') {
            $Slots += 1;
        } elseif ($action == 'switch') {
            foreach ($BattleArray as $slot => $a) {
                $temp = $BattleArray[$slot][0];
                $BattleArray[$slot][0] = $BattleArray[$slot][1];
                $BattleArray[$slot][1] = $temp;
                unset($temp);
            }
        }

        if (isset($this->state->requestInput['im'])) {
            foreach ($this->state->requestInput['im'] as $ID => $Count) {
                $BattleArray[0][1][$ID] = floatToString($Count);
            }
        }

        $this->addScript('../base/battlesim/lz-string.js');
        $this->addScript('battlesim.js');

        $this->assign([
            'Slots'         => $Slots,
            'battleinput'   => $BattleArray,
            'fleetList'     => $reslist['fleet'],
            'defensiveList' => $reslist['defense'],
        ]);

        return $this->render('game/page.battleSimulator.default.html.twig');
    }
}
