<?php

namespace App\Game\Missions\Expedition;

use App\Game\BattleEngine\BattleFleet;
use App\Game\BattleEngine\BattleHandler;
use App\Game\BattleEngine\BattleResult;
use App\Game\Core\BattleReportStore;

/**
 * Stores the combat report of an expedition fight (table rw, in the format of the battle engine used) and builds the
 * short report message with the link to the full combat report. An expedition fight yields no debris and no loot.
 */
class ExpeditionCombatReport extends ExpeditionEvent
{
    /** Report message with the link to the full report (sprintf template; the line breaks are removed when it is used). */
    private const MESSAGE_HTML = <<<'HTML'
<div class="raportMessage">
    <table>
        <tr>
            <td colspan="2">
                <a href="report/%s" target="_blank"><span class="%s">%s %s (%s)</span></a>
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
        $reportID = bin2hex(random_bytes(16));

        $this->storeReport($battleHandler, $reportData, $reportID);

        $message = $this->buildMessage($combatResult, $reportID, $debris, $stealResource);

        $result = [];
        $result['reportID'] = $reportID;
        $result['msg'] = $message;
        $result['link'] = sprintf(
            '<a href="report/%s" target="_blank"> %s </a>',
            $reportID,
            $LNG['sys_mess_attack_report'],
        );

        return $result;
    }

    /**
     * Inserts the report into the rw table in the format of the engine that fought.
     *
     * @param array<string, mixed> $reportData generated report (with the time of the fight)
     */
    private function storeReport(BattleHandler $battleHandler, array $reportData, string $reportID): void
    {
        (new BattleReportStore($this->db))->insert($reportID, $reportData, $battleHandler->engine->getName(), $this->fleet['stay_time'], $this->fleet['owner']);
    }

    /**
     * The short message: link to the report in the colours of the result (win, lose, draw), the lost values, loot and
     * debris (always zero for an expedition).
     *
     * @param array<int, int|float> $debris debris by resource id
     * @param array<int, int|float> $stealResource loot by resource id
     */
    private function buildMessage(BattleResult $combatResult, string $reportID, array $debris, array $stealResource): string
    {
        $LNG = $this->LNG;
        $messageHTML = str_replace(["\n", "\t", "\r"], "", self::MESSAGE_HTML);

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

        return sprintf(
            $messageHTML,
            $reportID,
            $attackClass,
            $LNG['sys_mess_attack_report'],
            $LNG->format(
                'sys_adress_planet',
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
    }
}
