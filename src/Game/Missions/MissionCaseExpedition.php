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

namespace App\Game\Missions;

use App\Game\Achievements\MiscAchievement;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\BattleEngine\BattleHandler;
use App\Game\Core\BuildFunctions;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\ExpeditionDeckHandler;
use App\Game\Core\ExpeditionFindableShipsHandler;
use App\Game\Core\FleetFunctions;
use App\Game\Core\Language;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;

class MissionCaseExpedition extends MissionFunctions implements MissionInterface
{
    private Language $LNG;
    private string $logbook = '';
    private string $eventsize = "";
    private string $eventspy = "";
    private string $eventvalue = "{}";
    private bool   $isEventPirate = false;

    public function __construct(array $fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet = $fleet;
        $this->LNG = $this->getLanguage(null, $this->_fleet['owner']);
        $this->LNG->includeData(['INGAME']);
    }

    public function TargetEvent(): void
    {
        $this->setState(FLEET_HOLD);
        $this->UpdateFleet('cannotCancel', 1);
        $this->SaveFleet();
    }

    private function calculatePointsAndCapacity(): array
    {
        $pricelist = & $this->state->pricelist;
        $reslist = & $this->state->reslist;
        $expeditionPoints = [];

        foreach ($reslist['fleet'] as $shipId) {
            $expeditionPoints[$shipId] = ($pricelist[$shipId]['cost'][RESOURCE_METAL]
                    + $pricelist[$shipId]['cost'][RESOURCE_CRYSTAL]) * 5 / 1000;
        }

        $fleetArray = $this->fleetFunctions()->unserialize($this->_fleet['ships']);
        $fleetPoints = 0;
        $fleetCapacity = 0;

        foreach ($fleetArray as $shipId => $shipAmount) {
            $fleetCapacity += $shipAmount * $pricelist[$shipId]['capacity'];
            $fleetPoints += $shipAmount * $expeditionPoints[$shipId];
        }

        $fleetCapacity -= $this->_fleet['resource_metal'] + $this->_fleet['resource_crystal']
            + $this->_fleet['resource_deuterium'];

        return [$fleetPoints, $fleetCapacity];
    }

    // Convert deck size to category (0=Small, 1=Medium, 2=Big)
    private function getSizeCategory(string $size): int
    {
        switch ($size) {
            case 'Small':
                return 0;
            case 'Medium':
                return 1;
            case 'Big':
            default:
                return 2;
        }
    }

    // Convert deck size to speed factor (Small=2, Medium=3, Big=5)
    private function getSizeFactor(string $size): int
    {
        switch ($size) {
            case 'Small':
                return 2;
            case 'Medium':
                return 3;
            case 'Big':
                return 5;
            default:
                return 2;
        }
    }

    private function handleEventFoundRes(): string
    {
        $resource = & $this->state->resource;

        $LNG = $this->LNG;
        $config = $this->configs->get($this->_fleet['universe']);
        $factor = 0;

        $fleetData = $this->calculatePointsAndCapacity();
        $fleetPoints = $fleetData[0];
        $fleetCapacity = $fleetData[1];

        // Use size category from deck, not generate a new one
        $sizeCategory = $this->getSizeCategory($this->eventsize);

        switch ($sizeCategory) {
            case 0:
                $Message = $LNG['sys_expe_found_ress_1_' . random_int(1, 4)];
                $factor = random_int(10, 50);
                $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::EXPO_RES_SMALL, 1);
                $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_RES_SMALL, $this->_fleet['stay_time']);
                break;
            case 1:
                $Message = $LNG['sys_expe_found_ress_2_' . random_int(1, 3)];
                $factor = random_int(50, 100);
                $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::EXPO_RES_MEDIUM, 1);
                $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_RES_MEDIUM, $this->_fleet['stay_time']);
                break;
            case 2:
                $Message = $LNG['sys_expe_found_ress_3_' . random_int(1, 2)];
                $factor = random_int(100, 200);
                $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::EXPO_RES_LARGE, 1);
                $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_RES_LARGE, $this->_fleet['stay_time']);
                break;
        }

        $chanceToFound = random_int(1, 100);
        if ($chanceToFound <= $config->expo_ress_met_chance) {
            $resourceId = RESOURCE_METAL;
        } elseif ($chanceToFound <= $config->expo_ress_met_chance + $config->expo_ress_crys_chance) {
            $resourceId = RESOURCE_CRYSTAL;
            $factor = $factor / 2;
        } else {
            $resourceId = RESOURCE_DEUT;
            $factor = $factor / 3;
        }

        $foundResources = $factor * max(min($fleetPoints, $this->fleetFunctions()->calculateMaxFactorRes($this->_fleet['universe'])), 200);

        if ($fleetCapacity < $foundResources) {
            $foundResources = $fleetCapacity;
            $Message .= '<br><br>' . sprintf(
                $LNG['sys_expe_found_goods'],
                $this->viewHelpers()->prettyNumber($foundResources),
                $LNG['tech'][$resourceId]
            );
            $this->logbook = $LNG['sys_expe_found_ress_logbook_' . random_int(1, 4)] . '<br>' . $this->logbook;
        } else {
            $Message .= '<br><br>' . sprintf(
                $LNG['sys_expe_found_goods'],
                $this->viewHelpers()->prettyNumber($foundResources),
                $LNG['tech'][$resourceId]
            );
        }
        $this->eventvalue = '{"' . $resourceId . '":' . $foundResources . "}";
        $fleetColName = 'resource_' . $resource[$resourceId];
        $this->UpdateFleet($fleetColName, $this->_fleet[$fleetColName] + $foundResources);
        $logColName = 'gained_' . $resource[$resourceId];
        $this->UpdateFleetLog($logColName, $foundResources);
        $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::getExpoFound($resourceId), $foundResources);
        $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::getExpoFirst($resourceId), $this->_fleet['stay_time']);

        return $Message;
    }

    private function determineFindableShips(array $fleetArray): array
    {
        return $this->expeditionShips()->findableShipsForFleet($this->_fleet['universe'], $fleetArray);
    }

    private function determineFoundShips(int $foundPoints, array $findableShips): array
    {
        $pricelist = & $this->state->pricelist;
        $Found = [];
        $upperValue = 3;

        $remainingPoints = $foundPoints;
        while ($findableShips !== [] && $upperValue > 0) {
            $ID = $findableShips[rand(0, count($findableShips) - 1)];
            $shipCost = ($pricelist[$ID]['cost'][RESOURCE_METAL] + $pricelist[$ID]['cost'][RESOURCE_CRYSTAL]);
            if ($shipCost <= 0) {
                $upperValue--;
                continue;
            }

            $MaxFound = floor($remainingPoints / $shipCost);
            if ($MaxFound <= 0) {
                $upperValue--;
                continue;
            }

            $Count = random_int(0, $MaxFound);

            if ($Count <= 0) {
                $upperValue--;
                continue;
            }

            // determine how many shipX were found, subtract their points from $remainingPoints, break if negative
            if (array_key_exists($ID, $Found)) {
                $Found[$ID] += $Count;
            } else {
                $Found[$ID] = $Count;
            }
            $remainingPoints -= $Count * $shipCost;
            if ($remainingPoints <= 0) {
                break;
            }
        }
        $this->eventvalue = "{";
        foreach ($Found as $shipId => $shipAmount) {
            $this->eventvalue .= '"' . $shipId . '":' . $shipAmount . ",";
        }
        $this->eventvalue = rtrim($this->eventvalue, ",") . "}";
        return $Found;
    }

    private function handleEventFoundShips()
    {
        $reslist = & $this->state->reslist;

        $LNG = $this->LNG;
        $Size = 0;
        $Message = "";
        $fleetPoints = $this->calculatePointsAndCapacity()[0]; // only points, capacity is irrelevant
        $fleetArray = $this->fleetFunctions()->unserialize($this->_fleet['ships']);

        // Use size category from deck, not generate a new one
        $sizeCategory = $this->getSizeCategory($this->eventsize);

        switch ($sizeCategory) {
            case 0:
                $Size = random_int(10, 50);
                $Message = $LNG['sys_expe_found_ships_1_' . random_int(1, 4)];
                $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::EXPO_SHIPS_SMALL, 1);
                $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_SHIPS_SMALL, $this->_fleet['stay_time']);
                break;
            case 1:
                $Size = random_int(52, 100);
                $Message = $LNG['sys_expe_found_ships_2_' . random_int(1, 2)];
                $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::EXPO_SHIPS_MEDIUM, 1);
                $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_SHIPS_MEDIUM, $this->_fleet['stay_time']);
                break;
            case 2:
                $Size = random_int(102, 200);
                $Message = $LNG['sys_expe_found_ships_3_' . random_int(1, 2)];
                $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::EXPO_SHIPS_LARGE, 1);
                $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_SHIPS_LARGE, $this->_fleet['stay_time']);
                break;
        }

        $MaxPoints = $this->fleetFunctions()->calculateMaxFactor($this->_fleet['universe']);

        if ($fleetPoints < $MaxPoints) {
            $this->logbook = $LNG['sys_expe_found_ships_logbook_' . random_int(1, 3)] . '<br>' . $this->logbook;
        }

        $FoundShipMess = "";
        $NewFleetArray = "";
        $CountTotal = 0;
        $foundPoints = max(round($Size * min($fleetPoints, $MaxPoints)), 10000);
        $findableShips = $this->determineFindableShips($fleetArray);
        $Found = $this->determineFoundShips($foundPoints, $findableShips);

        if (empty($Found)) {
            $Message .= '<br><br>' . $LNG['sys_expe_found_ships_nothing'];
        } else {
            foreach ($reslist['fleet'] as $ID) {
                $Count = 0;
                if (!empty($Found[$ID])) {
                    $Count += $Found[$ID];
                    $FoundShipMess .= '<br>' . $LNG['tech'][$ID] . ': ' . $this->viewHelpers()->prettyNumber($Count);
                    $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::getExpoFirst($ID), $this->_fleet['stay_time']);
                }
                if (!empty($fleetArray[$ID])) {
                    $Count += $fleetArray[$ID];
                }

                if ($Count > 0) {
                    $NewFleetArray .= $ID . "," . floatToString($Count) . ';';
                    $CountTotal += $Count;
                }
            }
            $this->statsHandler->updateFoundShipsAdvancedStats($this->_fleet['owner'], $Found);
            $Message .= '<br><br>' . $LNG['sys_expe_back_home_ships_flound'] . $FoundShipMess;
        }

        $this->UpdateFleet('ships', substr($NewFleetArray, 0, -1));
        $this->UpdateFleet('ship_amount', $CountTotal);
        $this->UpdateFleetLog('ships', substr($NewFleetArray, 0, -1));
        $this->UpdateFleetLog('ship_amount', $CountTotal);

        return $Message;
    }

    // pirate or alien. original: pirate chance = 58/84 ~ 69%
    private function setupPiratesOrAliens(array $fleetArray): array
    {
        $LNG = $this->LNG;
        $targetFleetData = [];
        $pirates = $this->isEventPirate;
        $attackFactor = 0;
        $Message = "";
        $bonusShip = SHIP_LIGHT_FIGHTER;
        
        // Use size category from deck, not generate a new one
        $sizeCategory = $this->getSizeCategory($this->eventsize);
        
        switch ($sizeCategory) {
            case 0:
                $Message = $pirates
                    ? $LNG['sys_expe_attack_1_1_' . rand(1, 5)] : $LNG['sys_expe_attack_2_1_' . rand(1, 4)];
                $attackFactor = $pirates ? (30 + random_int(-3, 3)) / 100 : (40 + random_int(-4, 4)) / 100;
                $bonusShip = $pirates ? SHIP_LIGHT_FIGHTER : SHIP_HEAVY_FIGHTER;
                $targetFleetData[$bonusShip] = 5;
                $this->statsHandler->increment($this->_fleet['owner'], $pirates ? AdvancedStatsEnum::EXPO_PIRATES_SMALL : AdvancedStatsEnum::EXPO_ALIENS_SMALL, 1);
                $this->statsHandler->setTimestamp($this->_fleet['owner'], $pirates ? AdvancedStatsEnum::FIRST_EXPO_PIRATES_SMALL : AdvancedStatsEnum::FIRST_EXPO_ALIENS_SMALL, $this->_fleet['stay_time']);
                break;
            case 1:
                $Message = $pirates
                    ? $LNG['sys_expe_attack_1_2_' . rand(1, 3)] : $LNG['sys_expe_attack_2_2_' . rand(1, 3)];
                $attackFactor = $pirates ? (50 + random_int(-5, 5)) / 100 : (60 + random_int(-6, 6)) / 100;
                $bonusShip = $pirates ? SHIP_CRUISER : SHIP_BATTLECRUISER;
                $targetFleetData[$bonusShip] = 3;
                $this->statsHandler->increment($this->_fleet['owner'], $pirates ? AdvancedStatsEnum::EXPO_PIRATES_MEDIUM : AdvancedStatsEnum::EXPO_ALIENS_MEDIUM, 1);
                $this->statsHandler->setTimestamp($this->_fleet['owner'], $pirates ? AdvancedStatsEnum::FIRST_EXPO_PIRATES_MEDIUM : AdvancedStatsEnum::FIRST_EXPO_ALIENS_MEDIUM, $this->_fleet['stay_time']);
                break;
            case 2:
                $Message = $pirates
                    ? $LNG['sys_expe_attack_1_3_' . rand(1, 3)] : $LNG['sys_expe_attack_2_3_' . rand(1, 3)];
                $attackFactor = $pirates ? (80 + random_int(-8, 8)) / 100 : (90 + random_int(-9, 9)) / 100;
                $bonusShip = $pirates ? SHIP_BATTLESHIP : SHIP_DESTROYER;
                $targetFleetData[$bonusShip] = 2;
                $this->statsHandler->increment($this->_fleet['owner'], $pirates ? AdvancedStatsEnum::EXPO_PIRATES_LARGE : AdvancedStatsEnum::EXPO_ALIENS_LARGE, 1);
                $this->statsHandler->setTimestamp($this->_fleet['owner'], $pirates ? AdvancedStatsEnum::FIRST_EXPO_PIRATES_LARGE : AdvancedStatsEnum::FIRST_EXPO_ALIENS_LARGE, $this->_fleet['stay_time']);
                break;
        }

        foreach ($fleetArray as $shipId => $shipAmount) {
            if (!isset($targetFleetData[$shipId])) {
                $targetFleetData[$shipId] = 0;
            }

            $rawShips = $shipAmount * $attackFactor;
            $targetFleetData[$shipId] += $pirates ? floor($rawShips) : ceil($rawShips);
        }

        $targetFleetData = array_filter($targetFleetData);

        $returnArray = [
            'message' => $Message,
            'fleetData' => $targetFleetData,
            'techbonus' => $pirates ? -3 : 3,
            'username' => $pirates ? $LNG['sys_expe_attackname_1'] : $LNG['sys_expe_attackname_2'],
        ];


        return $returnArray;
    }

    private function determineEnemyData(array $playerFleet): array
    {
        $senderData = $playerFleet['player'];

        $encounterSetupData = $this->setupPiratesOrAliens($playerFleet['unit']);
        $techBonus = $encounterSetupData['techbonus'];
        $targetFleetData = $encounterSetupData['fleetData'];

        $targetData = [
            'id' => 0,
            'username' => $encounterSetupData['username'],
            'military_tech' => max($senderData['military_tech'] + $techBonus, 0),
            'shield_tech' => max($senderData['shield_tech'] + $techBonus, 0),
            'defence_tech' => max($senderData['defence_tech'] + $techBonus, 0),
        ];

        $fleetDefend = [];
        $fleetDefend[0]['fleetDetail'] = [
            'start_galaxy' => $this->_fleet['end_galaxy'],
            'start_system' => $this->_fleet['end_system'],
            'start_planet' => $this->_fleet['end_planet'],
            'start_type' => 1,
            'end_galaxy' => $this->_fleet['end_galaxy'],
            'end_system' => $this->_fleet['end_system'],
            'end_planet' => $this->_fleet['end_planet'],
            'end_type' => 1,
            'resource_metal' => 0,
            'resource_crystal' => 0,
            'resource_deuterium' => 0,
        ];

        $bonusList = $this->buildFunctions()->getBonusList();

        $fleetDefend[0]['player'] = $targetData;
        $fleetDefend[0]['player']['factor'] = array_fill_keys($bonusList, 0);
        $fleetDefend[0]['unit'] = $targetFleetData;

        return [$fleetDefend, $encounterSetupData['message']];
    }

    private function generateCombatReport(array $combatResult, BattleHandler $battleHandler, array $fleetAttack, array $fleetDefend): array
    {


        $LNG = $this->LNG;
        $debris = [RESOURCE_METAL => 0, RESOURCE_CRYSTAL => 0];
        $stealResource = [RESOURCE_METAL => 0, RESOURCE_CRYSTAL => 0, RESOURCE_DEUT => 0];
        // test this.
        $messageHTML = <<<HTML
<div class="raportMessage">
    <table>
        <tr>
            <td colspan="2">
                <a href="CombatReport?raport=%s" target="_blank"><span class="%s">%s %s (%s)</span></a>
            </td>
        </tr>
        <tr>
            <td>%s</td>
            <td><span class="%s">%s: %s</span>&nbsp;<span class="%s">%s: %s</span></td>
        </tr>
        <tr>
            <td>%s</td>
            <td>
                <span>%s:&nbsp;<span class="raportSteal element901">%s</span>&nbsp;</span>
                <span>%s:&nbsp;<span class="raportSteal element902">%s</span>&nbsp;</span>
                <span>%s:&nbsp;<span class="raportSteal element903">%s</span></span>
            </td>
        </tr>
        <tr>
        <td>%s</td>
        <td>
            <span>%s:&nbsp;<span class="raportDebris element901">%s</span>&nbsp;</span>
            <span>%s:&nbsp;<span class="raportDebris element902">%s</span></span>
        </td>
        </tr>
    </table>
</div>
HTML;
        //Minize HTML
        $messageHTML = str_replace(["\n", "\t", "\r"], "", $messageHTML);

        $reportInfo = [
            'thisFleet' => $this->_fleet,
            'debris' => $debris,
            'stealResource' => $stealResource,
            'moonChance' => 0,
            'additionalChance' => 0,
            'moonDestroy' => false,
            'moonName' => null,
            'moonDestroyChance' => null,
            'moonDestroySuccess' => null,
            'fleetDestroyChance' => null,
            'fleetDestroySuccess' => null,
        ];

        $reportData = $battleHandler->GenerateReport($combatResult, $reportInfo, EXPO_FIGHT, $fleetAttack, $fleetDefend, $battleHandler->engine->getName());
        $reportData['time']	= $this->_fleet['stay_time'];
        $reportID = md5(uniqid('', true) . $this->state->runtime->timestamp);

        $sql = "INSERT INTO %%RW%% SET
			`rid` = :reportId,
			`raport` = :reportData,
			`time` = :time,
			`attacker` = :attacker,
            `battleengine` = :battleengine;";

        $this->db->insert($sql, [
            ':reportId' => $reportID,
            ':reportData'   => $battleHandler->engine->getName() === 'rust' ? compressToBase64(json_encode($reportData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : serialize($reportData),
            ':time' => $this->_fleet['stay_time'],
            ':attacker' => $this->_fleet['owner'],
            ':battleengine' => $battleHandler->engine->getName()
        ]);

        switch ($combatResult['won']) {
            case ATTACKER:
                $attackClass = 'raportWin';
                $defendClass = 'raportLose';
                break;
            case DEFENDER:
                $attackClass = 'raportLose';
                $defendClass = 'raportWin';
                break;
            default:
                $attackClass = 'raportDraw';
                $defendClass = 'raportDraw';
                break;
        }

        $message = sprintf(
            $messageHTML,
            $reportID,
            $attackClass,
            $LNG['sys_mess_attack_report'],
            sprintf(
                $LNG['sys_adress_planet'],
                $this->_fleet['end_galaxy'],
                $this->_fleet['end_system'],
                $this->_fleet['end_planet']
            ),
            $LNG['type_planet_short_' . $this->_fleet['end_type']],
            $LNG['sys_lost'],
            $attackClass,
            $LNG['sys_attack_attacker_pos'],
            $this->viewHelpers()->prettyNumber($combatResult['unitLost']['attacker']),
            $defendClass,
            $LNG['sys_attack_defender_pos'],
            $this->viewHelpers()->prettyNumber($combatResult['unitLost']['defender']),
            $LNG['sys_gain'],
            $LNG['tech'][RESOURCE_METAL],
            $this->viewHelpers()->prettyNumber($stealResource[RESOURCE_METAL]),
            $LNG['tech'][RESOURCE_CRYSTAL],
            $this->viewHelpers()->prettyNumber($stealResource[RESOURCE_CRYSTAL]),
            $LNG['tech'][RESOURCE_DEUT],
            $this->viewHelpers()->prettyNumber($stealResource[RESOURCE_DEUT]),
            $LNG['sys_debris'],
            $LNG['tech'][RESOURCE_METAL],
            $this->viewHelpers()->prettyNumber($debris[RESOURCE_METAL]),
            $LNG['tech'][RESOURCE_CRYSTAL],
            $this->viewHelpers()->prettyNumber($debris[RESOURCE_CRYSTAL])
        );

        $result = [];
        $result['reportID'] = $reportID;
        $result['msg'] = $message;
        $result['link'] = sprintf(
            '<a href="CombatReport?raport=%s" target="_blank"> %s </a>',
            $reportID,
            $LNG['sys_mess_attack_report']
        );

        return $result;
    }

    private function handleEventCombat(): string
    {
        $LNG = $this->LNG;
        $config = $this->configs->get($this->_fleet['universe']);
        $fleetArray = $this->fleetFunctions()->unserialize($this->_fleet['ships']);

        $sql = 'SELECT * FROM %%USERS%% WHERE `id` = :userId;';

        $senderData = $this->db->selectSingle($sql, [
            ':userId' => $this->_fleet['owner'],
        ]);

        $fleetID = $this->_fleet['id'];

        $fleetAttack = [];
        $fleetAttack[$fleetID]['fleetDetail'] = $this->_fleet;
        $fleetAttack[$fleetID]['player'] = $senderData;
        $fleetAttack[$fleetID]['unit'] = $fleetArray;

        // todo $Message as class variable? /discusswithself
        $enemyData = $this->determineEnemyData($fleetAttack[$fleetID]);
        $fleetDefend = $enemyData[0];
        $Message = $enemyData[1];

        $battleHandler = new BattleHandler(false, $this->db, $this->configs, $this->state);
        $combatResult = $battleHandler->engine->calculateAttack($fleetAttack, $fleetDefend, $this->_fleet['universe']);
        $report = $this->generateCombatReport($combatResult, $battleHandler, $fleetAttack, $fleetDefend);

        $fleetArray = '';
        $totalCount = 0;

        $fleetID = $this->_fleet['id'];
        $fleetAttack[$fleetID]['unit'] = array_filter($fleetAttack[$fleetID]['unit']);
        foreach ($fleetAttack[$fleetID]['unit'] as $element => $amount) {
            $fleetArray .= $element . ',' . $amount . ';';
            $totalCount += $amount;
        }

        if ($totalCount <= 0) {
            $this->KillFleet();
        } else {
            $this->UpdateFleet('ships', substr($fleetArray, 0, -1));
            $this->UpdateFleet('ship_amount', $totalCount);
            $this->UpdateFleetLog('ships', substr($fleetArray, 0, -1));
            $this->UpdateFleetLog('ship_amount', $totalCount);
            $this->UpdateFleetLog('report_id', $report['reportID']);
            $fleetArray = $this->fleetFunctions()->unserialize($fleetArray);
        }

        $Message .= '<br><br>' . $report['msg'];

        $this->playerUtil()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MESSAGE_TYPE_BATTLE_REPORT,
            $LNG['sys_mess_attack_report'],
            $report['msg'],
            $this->_fleet['stay_time'],
            1,
            $this->_fleet['universe']
        );

        return $Message;
    }

    private function drawExpeditionEvent(): array
    {
        $LNG = $this->LNG;
        
        // Initialize deck handler
        $deckHandler = new ExpeditionDeckHandler($this->db, $this->configs, $this->state, $this->_fleet['owner'], $this->_fleet['universe']);
        
        // Get Expeditions count in this system for depletion feedback
        $sql = "SELECT COUNT(*) AS total FROM %%LOG_FLEETS%% 
                WHERE `end_galaxy` = :end_galaxy
                  AND `end_system` = :end_system
                  AND `end_planet` = :end_planet
                  AND `stay_time` > UNIX_TIMESTAMP(NOW() - INTERVAL 1 DAY)
                  AND `universe` = :universe
                  AND `hasCanceled` = 0";

        $expeditionsCount = $this->db->selectSingle($sql, [
            'end_galaxy'  => $this->_fleet['end_galaxy'],
            'end_system'  => $this->_fleet['end_system'],
            'end_planet'  => $this->_fleet['end_planet'],
            'universe'    => $this->_fleet['universe'],
        ], 'total');

        // Depletion check
        $chanceDepleted = 0;
        if ($expeditionsCount <= 10) {
            $this->eventspy = "<10";
            $this->logbook = $LNG['sys_expe_depleted_not_' . random_int(1, 2)];
        } elseif ($expeditionsCount <= 25) {
            $chanceDepleted = 25;
            $this->eventspy = "<25";
            $this->logbook = $LNG['sys_expe_depleted_min_' . random_int(1, 3)];
        } elseif ($expeditionsCount <= 50) {
            $chanceDepleted = 50;
            $this->eventspy = "<50";
            $this->logbook = $LNG['sys_expe_depleted_med_' . random_int(1, 3)];
        } else {
            $chanceDepleted = 75;
            $this->eventspy = ">50";
            $this->logbook = $LNG['sys_expe_depleted_max_' . random_int(1, 3)];
        }

        $depleted = random_int(0, 100);
        if ($depleted < $chanceDepleted) {
            $card = [
                'type' => 'Nothing',
                'size' => 'none',
            ];
        } else {
            // Draw a card from the deck
            $card = $deckHandler->drawCard();
        }

        return $card;
    }

    public function EndStayEvent(): void
    {
        $LNG = $this->LNG;
        $fleetArray = $this->fleetFunctions()->unserialize($this->_fleet['ships']);

        $sql = 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $planetName = $this->db->selectSingle($sql, [
            ':planetId' => $this->_fleet['start_id'],
        ], 'name');
        $Message = sprintf(
            $LNG['sys_expe_report_msg'] . '<br><br>',
            $planetName,
            GetStartAddressLink($this->_fleet),
            GetTargetAddressLink($this->_fleet),
        );

        // Draw expedition event from deck
        $card = $this->drawExpeditionEvent();
        $eventType = $card['type'];
        $eventSize = $card['size'];
        $this->eventsize = $eventSize;

        $ExpoEventAndSize = "";
        $ExpoColor = "white";
        $factor = 0;

        // Handle event based on drawn card
        if ($eventType === 'Res') {
            // Find resources
            $Message .= $this->handleEventFoundRes();

            if ($eventSize === "Small") {
                $ExpoEventAndSize .= $LNG['Advanced_Stats']['expo_res_small'];
                $ExpoColor = "green";
            } elseif ($eventSize === "Medium") {
                $ExpoEventAndSize .= $LNG['Advanced_Stats']['expo_res_medium'];
                $ExpoColor = "green";
            } else {
                $ExpoEventAndSize .= $LNG['Advanced_Stats']['expo_res_large'];
                $ExpoColor = "green";
            }

        } elseif ($eventType === 'Ships') {
            // Find abandoned ships
            $Message .= $this->handleEventFoundShips();

            if ($eventSize === "Small") {
                $ExpoColor = "teal";
                $ExpoEventAndSize .= $LNG['Advanced_Stats']['expo_ships_small'];
            } elseif ($eventSize === "Medium") {
                $ExpoColor = "teal";
                $ExpoEventAndSize .= $LNG['Advanced_Stats']['expo_ships_medium'];
            } else {
                $ExpoColor = "teal";
                $ExpoEventAndSize .= $LNG['Advanced_Stats']['expo_ships_large'];
            }

        } elseif ($eventType === 'Pirates') {
            // Find pirates or aliens
            $this->isEventPirate = true;
            $Message .= $this->handleEventCombat();
            

            if ($eventSize === "Small") {
                $ExpoColor = "red";
                $ExpoEventAndSize .= $LNG['Advanced_Stats']['expo_pirates_small'];
            } elseif ($eventSize === "Medium") {
                $ExpoColor = "red";
                $ExpoEventAndSize .= $LNG['Advanced_Stats']['expo_pirates_medium'];
            } else {
                $ExpoColor = "red";
                $ExpoEventAndSize .= $LNG['Advanced_Stats']['expo_pirates_large'];
            }

        } elseif ($eventType === 'Aliens') {
            // Find aliens
            $this->isEventPirate = false;
            $Message .= $this->handleEventCombat();

            if ($eventSize === "Small") {
                $ExpoColor = "red";
                $ExpoEventAndSize .= $LNG['Advanced_Stats']['expo_aliens_small'];
            } elseif ($eventSize === "Medium") {
                $ExpoColor = "red";
                $ExpoEventAndSize .= $LNG['Advanced_Stats']['expo_aliens_medium'];
            } else {
                $ExpoColor = "red";
                $ExpoEventAndSize .= $LNG['Advanced_Stats']['expo_aliens_large'];
            }

        } elseif ($eventType === 'BlackHole') {
            // Black hole - fleet lost
            $this->statsHandler->updateExpoLostAdvancedStats($this->_fleet['owner'], $fleetArray);
            $this->KillFleet();
            $Message .= $LNG['sys_expe_lost_fleet_' . random_int(1, 4)];
            $ExpoColor = "red";
            $ExpoEventAndSize .= $LNG['Advanced_Stats']['expo_black_hole'];
            $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::EXPO_BLACK_HOLE, 1);
            $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_BLACK_HOLE, $this->_fleet['stay_time']);
            $this->miscAchievements()->checkExpoAchievements($this->_fleet['owner']);

        } elseif ($eventType === 'Fast') {
            // The fleet returns earlier
            $normalBackTime = $this->_fleet['return_time'] - $this->_fleet['stay_time'];
            $factor = $this->getSizeFactor($eventSize);
            $this->eventvalue = '{"factor":' . $factor . "}";
            
            // Less return time (faster)
            $endTime = $this->_fleet['stay_time'] + max(1, $normalBackTime * (1 - $factor/10));
            $this->UpdateFleet('return_time', $endTime);
            $this->UpdateFleetLog('return_time', $endTime);
            $Message .= $LNG['sys_expe_time_fast_' . random_int(1, 3)];
            $ExpoColor = "yellow";
            $ExpoEventAndSize .= $LNG['Advanced_Stats']['expo_fast'];
            $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::EXPO_FAST, 1);
            $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_FAST, $this->_fleet['stay_time']);

        } elseif ($eventType === 'Slow') {
            // The fleet delays in return
            $normalBackTime = $this->_fleet['return_time'] - $this->_fleet['stay_time'];
            $factor = $this->getSizeFactor($eventSize);
            $this->eventvalue = '{"factor":' . $factor . "}";
            
            // More return time (slower)
            $endTime = $this->_fleet['stay_time'] + $normalBackTime + TIME_1_HOUR * $factor;
            $this->UpdateFleet('return_time', $endTime);
            $this->UpdateFleetLog('return_time', $endTime);
            $Message .= $LNG['sys_expe_time_slow_' . random_int(1, 6)];
            $ExpoColor = "yellow";
            $ExpoEventAndSize .= $LNG['Advanced_Stats']['expo_slow'];
            $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::EXPO_SLOW, 1);
            $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_SLOW, $this->_fleet['stay_time']);

        } else {
            // Nothing happens
            $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::EXPO_NOTHING, 1);
            $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_NOTHING, $this->_fleet['stay_time']);
            $Message .= $LNG['sys_expe_nothing_' . random_int(1, 8)];
            $ExpoColor = "white";
            $ExpoEventAndSize .= $LNG['Advanced_Stats']['expo_nothing'];
        }

        if (isset($fleetArray[SHIP_PROBE])) {
            $Message .= '<br><br>' . $this->logbook;
        }

        if ($factor != 0) {
            $Message .= '<span class="expo-event expo-event--' . $ExpoColor . '"><br><br>' . $ExpoEventAndSize . " - Faktor " . $factor . '</span>';
        } else {
            $Message .= '<span class="expo-event expo-event--' . $ExpoColor . '"><br><br>' . $ExpoEventAndSize . '</span>';
        }

        if (isset($fleetArray[SHIP_PROBE])) {
            if ($this->eventspy == "<10") {
                // $Message .= '<span style="color: white; font-weight: bold;"><br>' . $LNG['sys_expe_feqeuence_no'] .'</span>';
            } elseif ($this->eventspy == "<25") {
                $Message .= '<span class="expo-freq expo-freq--yellow"><br>' . $LNG['sys_expe_feqeuence_few'] .'</span>';
            } elseif ($this->eventspy == "<50") {
                $Message .= '<span class="expo-freq expo-freq--orange"><br>' . $LNG['sys_expe_feqeuence_medium'] .'</span>';
            } else {
                $Message .= '<span class="expo-freq expo-freq--red"><br>' . $LNG['sys_expe_feqeuence_many'] .'</span>';
            }
        }

        $this->playerUtil()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MESSAGE_TYPE_EXPEDITION,
            $LNG['sys_expe_report'],
            $Message,
            $this->_fleet['stay_time'],
            1,
            $this->_fleet['universe']
        );
        $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::EXPO_COUNT, 1);
        $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_EXPEDITION, $this->_fleet['stay_time']);
        $this->setState(FLEET_RETURN);
        $this->SaveFleet();
    }

    public function ReturnEvent(): void
    {
        $pricelist = & $this->state->pricelist;
        $reslist = & $this->state->reslist;
        $LNG = $this->LNG;
        $sql = 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $planetName = $this->db->selectSingle($sql, [
            ':planetId' => $this->_fleet['start_id'],
        ], 'name');
        $Message = sprintf(
            $LNG['sys_expe_back_home'],
            $planetName,
            GetStartAddressLink($this->_fleet),
            GetTargetAddressLink($this->_fleet),
        );

        if (
            $this->_fleet['resource_metal'] > 0
            || $this->_fleet['resource_crystal'] > 0
            || $this->_fleet['resource_deuterium'] > 0
        ) {
            $Message .= '<br><br>' . sprintf(
                $LNG['sys_expe_back_home_ress'],
                $this->viewHelpers()->prettyNumber($this->_fleet['resource_metal']),
                $LNG['tech'][RESOURCE_METAL],
                $this->viewHelpers()->prettyNumber($this->_fleet['resource_crystal']),
                $LNG['tech'][RESOURCE_CRYSTAL],
                $this->viewHelpers()->prettyNumber($this->_fleet['resource_deuterium']),
                $LNG['tech'][RESOURCE_DEUT]
            );
        }

        $fleetArray = $this->fleetFunctions()->unserialize($this->_fleet['ships']);
        $fleetArrayStart = $this->fleetFunctions()->unserialize($this->_fleet['start_ships']);

        // correct empty fleets resulting from a fixed bug in findShips event
        if (empty($fleetArray) && !empty($fleetArrayStart)) {
            $this->_fleet['ships'] = $this->_fleet['start_ships'];
            $this->UpdateFleet('ships', $this->_fleet['start_ships']);
            //impossible when fleet is killed
            $this->SaveFleet();
        }

        if (isset($fleetArrayStart)) {
            $fleetAmmount = array_sum($fleetArray);
            $fleetAmmountStart = array_sum($fleetArrayStart);
            if ($fleetAmmountStart != $fleetAmmount) {
                if ($fleetAmmountStart < $fleetAmmount) {
                    $Message .= '<br><br>' . sprintf(
                        $LNG['sys_expe_back_home_ships_flound']
                    );
                } elseif ($fleetAmmountStart > $fleetAmmount) {
                    $Message .= '<br><br>' . sprintf(
                        $LNG['sys_expe_back_home_ships_lost']
                    );
                }
                foreach ($reslist['fleet'] as $shipId) {
                    if (isset($fleetArrayStart[$shipId]) && isset($fleetArray[$shipId])) {
                        if ($fleetArrayStart[$shipId] != $fleetArray[$shipId]) {
                            $Message .= '<br>' . $LNG['tech'][$shipId] . ': '
                                . $this->viewHelpers()->prettyNumber(abs($fleetArrayStart[$shipId] - $fleetArray[$shipId]));
                        }
                    } elseif (isset($fleetArrayStart[$shipId])) {
                        $Message .= '<br>' . $LNG['tech'][$shipId] . ': '
                            . $this->viewHelpers()->prettyNumber(abs($fleetArrayStart[$shipId]));
                    } elseif (isset($fleetArray[$shipId])) {
                        $Message .= '<br>' . $LNG['tech'][$shipId] . ': ' . $this->viewHelpers()->prettyNumber(abs($fleetArray[$shipId]));
                    }
                }
            }
        }

        $this->playerUtil()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MESSAGE_TYPE_SYSTEM,
            $LNG['sys_mess_fleetback'],
            $Message,
            $this->_fleet['return_time'],
            1,
            $this->_fleet['universe']
        );

        $this->RestoreFleet();
    }
}
