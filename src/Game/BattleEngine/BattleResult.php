<?php

declare(strict_types=1);

namespace App\Game\BattleEngine;

/**
 * Result of a fight, returned by BattleEngineInterface::fight(): winner, round log, losses, debris, the rebuilt defense and
 * the wreck field. The survivors are not copied here: the engines reduce the units of the BattleFleet objects they were
 * given, so those objects are the survivors (the fleets as they were before the fight are in the round log).
 *
 * Immutable. The round log is the report's data and stays an array because its format depends on the engine: the PHP engine
 * records per round the fleets and the per-unit sums (`attackers`, `defenders`, `infoA`, `infoD`, damage totals), the Rust
 * engine its `Roundstats`/`Statistics` maps; in both, `rounds[0]` holds the fleets before the fight as arrays (`attackers`,
 * `defenders`). BattleHandler::generateReport() knows both formats.
 */
final class BattleResult
{
    /** @var array<int|string, BattleFleet>|null cache of startFleets() */
    private ?array $startAttackers = null;
    /** @var array<int|string, BattleFleet>|null cache of startFleets() */
    private ?array $startDefenders = null;

    /**
     * @param string $won ATTACKER, DEFENDER or DRAW
     * @param BattleDebris $debris debris by side
     * @param array<int, array<string, mixed>> $rounds round log: start state first, then one entry per fight (engine specific format)
     * @param BattleLosses $losses lost value and transport capacity by side and user
     * @param array<int, array{units: int|float, percent: int|float}> $repaired rebuilt defense units of the defending planet by unit id, with the percent of the loss
     * @param array<int, int|float> $wreckfield ships of the defending planet that go into the wreck field, by unit id (empty when no wreck field is created)
     * @param float $duration seconds the engine needed
     */
    public function __construct(
        public readonly string $won,
        public readonly BattleDebris $debris,
        public readonly array $rounds,
        public readonly BattleLosses $losses,
        public readonly array $repaired,
        public readonly array $wreckfield,
        public readonly float $duration,
    ) {}

    /**
     * The attacking fleets as they were before the fight (from the round log), keyed by fleet id.
     *
     * @return array<int|string, BattleFleet>
     */
    public function startAttackers(): array
    {
        return $this->startAttackers ??= BattleFleet::listFromArrays($this->rounds[0]['attackers'] ?? []);
    }

    /**
     * The defending fleets (stay fleets and the planet as fleet 0) as they were before the fight, keyed by fleet id.
     *
     * @return array<int|string, BattleFleet>
     */
    public function startDefenders(): array
    {
        return $this->startDefenders ??= BattleFleet::listFromArrays($this->rounds[0]['defenders'] ?? []);
    }

    /** Whether the attacker won. */
    public function attackerWon(): bool
    {
        return $this->won === ATTACKER;
    }

    /**
     * Legacy array layout of the combat result (won, debris, rw, unitLost, repaired, wreckfield, duration,
     * unitLostPerSideAndUser, transportCapacityLostPerSideAndUser).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'won' => $this->won,
            'debris' => $this->debris->toArray(),
            'rw' => $this->rounds,
            'unitLost' => ['attacker' => $this->losses->attackerValue, 'defender' => $this->losses->defenderValue],
            'repaired' => $this->repaired,
            'wreckfield' => $this->wreckfield,
            'duration' => $this->duration,
            'unitLostPerSideAndUser' => $this->losses->valuePerUser,
            'transportCapacityLostPerSideAndUser' => $this->losses->capacityPerUser,
        ];
    }
}
