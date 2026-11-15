<?php

namespace App\Game\Missions\Expedition;

use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Language;

/**
 * What the expedition event handlers share with the mission that runs them: the fleet (read live, written through the
 * mission's UpdateFleet/UpdateFleetLog/KillFleet, so the updates are saved by the mission), the language of the owner,
 * the advanced-stats handler and the mutable report state of the current event (logbook text, event value for the log,
 * size and frequency of the drawn card, pirate or alien).
 *
 * The fleet access is passed as closures bound to the mission because the mission functions are protected.
 */
final class ExpeditionContext
{
    public string $logbook = '';
    public string $eventsize = '';
    public string $eventspy = '';
    public string $eventvalue = '{}';
    public bool $isEventPirate = false;

    /**
     * @param \Closure(): array<string, mixed>   $readFleet
     * @param \Closure(string, mixed): void      $writeFleet
     * @param \Closure(string, mixed): void      $writeFleetLog
     * @param \Closure(): void                   $kill
     */
    public function __construct(
        public readonly Language $LNG,
        public readonly AdvancedStatsHandler $stats,
        private readonly \Closure $readFleet,
        private readonly \Closure $writeFleet,
        private readonly \Closure $writeFleetLog,
        private readonly \Closure $kill,
    ) {}

    /** @return array<string, mixed> the current fleet row of the mission */
    public function fleet(): array
    {
        return ($this->readFleet)();
    }

    public function updateFleet(string $column, mixed $value): void
    {
        ($this->writeFleet)($column, $value);
    }

    public function updateFleetLog(string $column, mixed $value): void
    {
        ($this->writeFleetLog)($column, $value);
    }

    /** Deletes the fleet (and its event) from the database. */
    public function killFleet(): void
    {
        ($this->kill)();
    }
}
