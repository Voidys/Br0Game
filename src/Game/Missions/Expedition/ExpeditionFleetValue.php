<?php

namespace App\Game\Missions\Expedition;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Expedition value of a fleet: its expedition points (ship cost in metal + crystal, times 5 / 1000, summed) and the free
 * cargo room left after the resources it already carries. Both limit what an expedition can find.
 */
class ExpeditionFleetValue
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * @param array<string, mixed> $fleet fleet row (ships, resource_metal/crystal/deuterium)
     * @return array{0: int|float, 1: int|float} expedition points, free cargo capacity
     */
    public function pointsAndCapacity(array $fleet): array
    {
        $e = $this->elements();
        $expeditionPoints = [];

        foreach ($e->ids('fleet') as $shipId) {
            $expeditionPoints[$shipId] = ($e->cost($shipId)->metal
                    + $e->cost($shipId)->crystal) * 5 / 1000;
        }

        $fleetArray = $this->fleetFunctions()->unserialize($fleet['ships']);
        $fleetPoints = 0;
        $fleetCapacity = 0;

        foreach ($fleetArray as $shipId => $shipAmount) {
            $fleetCapacity += $shipAmount * $e->capacity($shipId);
            $fleetPoints += $shipAmount * $expeditionPoints[$shipId];
        }

        $fleetCapacity -= $fleet['resource_metal'] + $fleet['resource_crystal']
            + $fleet['resource_deuterium'];

        return [$fleetPoints, $fleetCapacity];
    }

}
