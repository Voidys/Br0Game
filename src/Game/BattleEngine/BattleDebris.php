<?php

declare(strict_types=1);

namespace App\Game\BattleEngine;

/**
 * Debris of a fight by origin: what the destroyed ships (and, for the defender, the destroyed defense) of each side
 * leave behind in metal and crystal, after the debris percentages of the universe. Immutable.
 */
final class BattleDebris
{
    /**
     * @param int|float $attackerMetal   metal from the attacking side's losses
     * @param int|float $attackerCrystal crystal from the attacking side's losses
     * @param int|float $defenderMetal   metal from the defending side's losses
     * @param int|float $defenderCrystal crystal from the defending side's losses
     */
    public function __construct(
        public readonly int|float $attackerMetal,
        public readonly int|float $attackerCrystal,
        public readonly int|float $defenderMetal,
        public readonly int|float $defenderCrystal,
    ) {}

    /** Metal debris of both sides together. */
    public function metal(): int|float
    {
        return $this->attackerMetal + $this->defenderMetal;
    }

    /** Crystal debris of both sides together. */
    public function crystal(): int|float
    {
        return $this->attackerCrystal + $this->defenderCrystal;
    }

    /**
     * Debris of both sides together for a resource id.
     *
     * @param int $resourceId RESOURCE_METAL (901) or RESOURCE_CRYSTAL (902)
     */
    public function total(int $resourceId): int|float
    {
        return $resourceId === RESOURCE_METAL ? $this->metal() : $this->crystal();
    }

    /**
     * Debris of both sides together as the resource-keyed list the missions write to the debris field.
     *
     * @return array{901: int|float, 902: int|float}
     */
    public function totals(): array
    {
        return [RESOURCE_METAL => $this->metal(), RESOURCE_CRYSTAL => $this->crystal()];
    }

    /**
     * Legacy layout `[side][resource id]`, as stored in the combat result.
     *
     * @return array{attacker: array{901: int|float, 902: int|float}, defender: array{901: int|float, 902: int|float}}
     */
    public function toArray(): array
    {
        return [
            'attacker' => [RESOURCE_METAL => $this->attackerMetal, RESOURCE_CRYSTAL => $this->attackerCrystal],
            'defender' => [RESOURCE_METAL => $this->defenderMetal, RESOURCE_CRYSTAL => $this->defenderCrystal],
        ];
    }
}
