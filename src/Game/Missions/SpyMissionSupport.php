<?php

namespace App\Game\Missions;

use App\Game\Enum\MessageType;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\BattleEngine\BattleHandler;

/**
 * Helpers shared by the two espionage missions (MissionCaseSpy, MissionCasePiratebaseSpy): danger value, counter-espionage
 * chance, cargo ships needed and the spy report message. Uses the mission's `_fleet`, `db`, `configs`, `state` and `statsHandler`.
 */
trait SpyMissionSupport
{
    /**
     * Chance (percent, at most 100) that the target shoots the probes down. OGame rule
     * (https://ogame.fandom.com/wiki/Counterespionage): 2^(t-s) * probes * ships_and_defenses_on_target * 0.0025,
     * rounded; t/s = spy tech of target/sender.
     */
    private function getDetectionChance(int|float|string $senderSpyTech, int|float|string $targetSpyTech, int|float|string $fleetAmount, int|float|string $totalShipDefCount): int|float
    {
        return min(round(pow(2, $targetSpyTech - $senderSpyTech) * $fleetAmount * $totalShipDefCount * 0.0025), 100);
    }

    /**
     * Cargo ships needed to carry the target's resources (the capacity BattleHandler::calculateMinCapacity() gives for
     * metal, crystal and deuterium).
     *
     * @param array<string, mixed> $target planet or pirate base row
     * @return array{0: float, 1: float} small and large cargos needed
     */
    private function cargoNeeded(array $target): array
    {
        $e = $this->elements();
        $battleHandler = new BattleHandler(false, $this->db, $this->configs, $this->state);

        $capacityNeeded = $battleHandler->calculateMinCapacity([
            'metal' => getNumber($target[$e->column(RESOURCE_METAL)]),
            'crystal' => getNumber($target[$e->column(RESOURCE_CRYSTAL)]),
            'deuterium' => getNumber($target[$e->column(RESOURCE_DEUT)]),
        ]);
        $smallCargoNeeded = ceil($capacityNeeded / $e->capacity(SHIP_SMALL_CARGO));
        $largeCargoNeeded = ceil($capacityNeeded / $e->capacity(SHIP_LARGE_CARGO));

        return [$smallCargoNeeded, $largeCargoNeeded];
    }

    /**
     * Sends the spy report (JSON) to the fleet owner and sets the "first espionage" stat.
     *
     * @param array<string, mixed> $reportData report content
     */
    private function sendReport(array $reportData, \App\Game\Core\Language $LNG): void
    {
        $this->playerMessenger()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_spy_qg'],
            MessageType::SpyReport->value,
            $LNG['sys_mess_spy_report'],
            json_encode($reportData),
            $this->_fleet['arrival_time'],
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id'],
        );

        $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_ESPIONAGE, $this->_fleet['arrival_time']);
    }

    /**
     * Returns the combined base attack values of ships and defences
     */
    public function getDangerValue($spyData)
    {
        $e = $this->elements();
        $dangerValue = 0;

        // ships
        if (isset($spyData[200])) {
            foreach ($spyData[200] as $elementID => $amount) {
                $dangerValue += $e->combat($elementID)->attack * $amount;
            }
        }

        // defence
        if (isset($spyData[400])) {
            foreach ($spyData[400] as $elementID => $amount) {
                if (
                    !($elementID == INTERCEPTOR_MISSILE)
                    and !($elementID == INTERPLANETARY_MISSILE)
                ) {
                    $dangerValue += $e->combat($elementID)->attack * $amount;
                }
            }
        }

        return  $dangerValue;
    }
}
