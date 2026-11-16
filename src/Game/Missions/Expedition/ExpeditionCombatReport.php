<?php

namespace App\Game\Missions\Expedition;

use App\Game\BattleEngine\BattleFleet;
use App\Game\BattleEngine\BattleHandler;
use App\Game\BattleEngine\BattleResult;
use App\Game\Core\StoredData;

/**
 * Stores the combat report of an expedition fight (table rw, in the format of the battle engine used) and builds the
 * short report message with the link to the full combat report. An expedition fight yields no debris and no loot.
 */
class ExpeditionCombatReport extends ExpeditionEvent
{
    /**
     * Generates and stores the report of the fight.
     *
     * Side effect: inserts the report into the rw table.
     *
     * @param BattleResult                  $combatResult result of the battle engine
     * @param array<int|string, BattleFleet> $fleetAttack  attacking fleets as given to the engine
     * @param array<int|string, BattleFleet> $fleetDefend  defending fleets as given to the engine
     * @return array{reportID: string, msg: string, link: string} report id, message html, link html
     */
    public function generate(BattleResult $combatResult, BattleHandler $battleHandler, array $fleetAttack, array $fleetDefend): array
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
            'thisFleet' => $this->fleet,
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
        $reportData['time']	= $this->fleet['stay_time'];
        $reportID = md5(uniqid('', true) . $this->state->runtime->timestamp);

        $sql = "INSERT INTO %%RW%% SET
			`rid` = :reportId,
			`raport` = :reportData,
			`time` = :time,
			`attacker` = :attacker,
            `battleengine` = :battleengine;";

        $this->db->insert($sql, [
            ':reportId' => $reportID,
            ':reportData'   => $battleHandler->engine->getName() === 'rust' ? compressToBase64(json_encode($reportData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : StoredData::encode($reportData),
            ':time' => $this->fleet['stay_time'],
            ':attacker' => $this->fleet['owner'],
            ':battleengine' => $battleHandler->engine->getName(),
        ]);

        switch ($combatResult->won) {
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
            $LNG->format('sys_adress_planet',
                $this->fleet['end_galaxy'],
                $this->fleet['end_system'],
                $this->fleet['end_planet'],
            ),
            $LNG['type_planet_short_' . $this->fleet['end_type']],
            $LNG['sys_lost'],
            $attackClass,
            $LNG['sys_attack_attacker_pos'],
            $this->viewHelpers()->prettyNumber($combatResult->losses->attackerValue),
            $defendClass,
            $LNG['sys_attack_defender_pos'],
            $this->viewHelpers()->prettyNumber($combatResult->losses->defenderValue),
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
            $this->viewHelpers()->prettyNumber($debris[RESOURCE_CRYSTAL]),
        );

        $result = [];
        $result['reportID'] = $reportID;
        $result['msg'] = $message;
        $result['link'] = sprintf(
            '<a href="CombatReport?raport=%s" target="_blank"> %s </a>',
            $reportID,
            $LNG['sys_mess_attack_report'],
        );

        return $result;
    }
}
