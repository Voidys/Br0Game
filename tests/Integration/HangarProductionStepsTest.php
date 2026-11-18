<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\BuildFunctions;
use App\Game\Core\ResourceUpdate;
use App\Tests\Support\GameTestCase;

/**
 * Solar satellites from the shipyard queue raise production only from the moment they are finished, not for the whole
 * time since the last update (the update advances to each completion).
 */
final class HangarProductionStepsTest extends GameTestCase
{
    /** an energy-starved planet (production throttled) with a queue of solar satellites */
    private function starvedPlanet(int $satellites, int $lastUpdate): array
    {
        return $this->createPlayer([], [
            'metal_mine' => 25,
            'crystal_mine' => 20,
            'deuterium_sintetizer' => 15,
            'solar_plant' => 5,
            'metal_store' => 10,
            'crystal_store' => 10,
            'deuterium_store' => 10,
            'hangar' => 4,
            'metal' => 1000,
            'crystal' => 1000,
            'deuterium' => 1000,
            'last_update' => $lastUpdate,
            'eco_hash' => '',
            'b_hangar' => 0,
            'b_hangar_id' => '[[' . SHIP_SOLSAT . ',' . $satellites . ']]',
        ]);
    }

    private function update(array $user, array $planet, int $time): array
    {
        [, $planet] = (new ResourceUpdate($this->db, $this->configs, $this->state))->CalcResource($user, $planet, true, $time);

        return $this->loadPlanet((int) $planet['id']);
    }

    public function testFinishedSatellitesOnlyProduceFromTheirFinishTime(): void
    {
        $start = $this->state->runtime->timestamp - 200000;
        [$userA, $planetA] = $this->starvedPlanet(12, $start);
        [$userB, $planetB] = $this->starvedPlanet(12, $start);
        $unit = (int) (new BuildFunctions($this->db, $this->configs, $this->state))->getBuildingTime($userA, $planetA, SHIP_SOLSAT);
        $this->assertGreaterThan(0, $unit);
        $end = $start + 10 * $unit + intdiv($unit, 2);

        // one update over the whole interval
        $once = $this->update($userA, $planetA, $end);

        // reference: the same interval, updated one second before every completion (a plain update finishes the units due
        // at its end first, so it would count the unit for the whole step); the booked second is the tolerance below
        $stepped = $planetB;
        for ($k = 1; $k <= 10; $k++) {
            $stepped = $this->update($userB, $stepped, $start + $k * $unit - 1);
        }
        $stepped = $this->update($userB, $stepped, $end);

        $this->assertSame(10, (int) $once['solar_satelit']);
        $this->assertSame(10, (int) $stepped['solar_satelit']);
        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            $this->assertEqualsWithDelta((float) $stepped[$resource], (float) $once[$resource], 3.0, $resource . ' after one update vs. step by step');
        }
        $this->assertEqualsWithDelta((float) $stepped['energy'], (float) $once['energy'], 0.5);
        $this->assertSame($end, (int) $once['last_update']);
    }
}
