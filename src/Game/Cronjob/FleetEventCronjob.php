<?php

declare(strict_types=1);

namespace App\Game\Cronjob;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\FlyingFleetHandler;
use App\Game\Core\GameData;
use App\Game\Core\Singleton;

/**
 * Processes the due fleet events of every universe whose fleet-event module is on, independent of page views
 * (arrivals, returns and stays end on time even when nobody loads a page). Runs every minute as cronjob "fleets"
 * and every few seconds through `bin/console app:fleets:run`. Claims are atomic (random lock token per claim),
 * so it is safe next to page requests and other runners. The current universe of the process is restored afterwards.
 */
class FleetEventCronjob extends AbstractCronjobTask
{
    public function __construct(
        Database $db,
        ConfigRegistry $configs,
        Singleton $state,
        private readonly FlyingFleetHandler $fleetHandler,
    ) {
        parent::__construct($db, $configs, $state);
    }

    /**
     * Claims and runs the due events per universe (module MODULE_FLEET_EVENTS permitting).
     */
    public function run(): void
    {
        $this->processDueEvents();
    }

    /**
     * Same as run(), returns the number of events claimed over all universes.
     */
    public function processDueEvents(): int
    {
        $original = $this->state->universeCurrent;
        $claimed = 0;

        try {
            foreach ($this->universe()->availableUniverses() as $universe) {
                $universe = (int) $universe;
                if (!$this->viewHelpers()->isModuleAvailable(MODULE_FLEET_EVENTS, $universe)) {
                    continue;
                }

                $this->useUniverse($universe);
                $claimed += $this->fleetHandler->processDue($universe);
            }
        } finally {
            if ($original !== null) {
                $this->useUniverse((int) $original);
            }
        }

        return $claimed;
    }

    /**
     * Makes $universe the current universe (missions and game data follow it).
     */
    private function useUniverse(int $universe): void
    {
        if ((int) $this->state->universeCurrent === $universe && $this->elements()->all() !== []) {
            return;
        }

        $this->state->universeCurrent = $universe;
        (new GameData($this->db, $this->configs, $this->state))->load();
    }
}
