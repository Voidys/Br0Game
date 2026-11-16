<?php

declare(strict_types=1);

namespace App\Game\BattleEngine;

/**
 * The combat techs of one fight participant: weapons, shields and armor levels (each level adds 10 percent to the
 * attack, shield and hull of every unit of the fleet) and the `factor` bonus list some participants carry (the
 * expedition enemy lists every bonus with 0; the engines ignore it, the report data keeps it with the player).
 *
 * Immutable. Levels are the values of the user row (military_tech, shield_tech, defence_tech) or, for NPC enemies,
 * the player's levels changed by a bonus (withBonus()).
 */
final class BattleTechs
{
    /**
     * @param int|float $weapons weapons tech level (users.military_tech)
     * @param int|float $shields shield tech level (users.shield_tech)
     * @param int|float $armor   armor tech level (users.defence_tech)
     * @param array<int|string, int|float> $factor bonus list of the participant (`player.factor`), empty when it has none
     */
    public function __construct(
        public readonly int|float $weapons,
        public readonly int|float $shields,
        public readonly int|float $armor,
        public readonly array $factor = [],
    ) {}

    /**
     * Techs of a user row (or of the player array of a simulated participant). Missing or non-numeric levels count as 0.
     *
     * @param array<string, mixed> $player must hold military_tech, shield_tech and defence_tech, may hold `factor`
     */
    public static function fromPlayer(array $player): self
    {
        $level = static fn(mixed $value): int|float => is_numeric($value) ? $value + 0 : 0;

        return new self(
            $level($player['military_tech'] ?? 0),
            $level($player['shield_tech'] ?? 0),
            $level($player['defence_tech'] ?? 0),
            \is_array($player['factor'] ?? null) ? $player['factor'] : [],
        );
    }

    /** Attack multiplier: +10 percent per weapons level. */
    public function attackMultiplier(): float
    {
        return 1 + (0.1 * $this->weapons);
    }

    /** Shield multiplier: +10 percent per shield level. */
    public function shieldMultiplier(): float
    {
        return 1 + (0.1 * $this->shields);
    }

    /** Armor multiplier: +10 percent per armor level. */
    public function armorMultiplier(): float
    {
        return 1 + (0.1 * $this->armor);
    }

    /**
     * Same techs with every level moved by $bonus, never below 0 (expedition pirates -3, aliens +3).
     */
    public function withBonus(int $bonus): self
    {
        return new self(
            max($this->weapons + $bonus, 0),
            max($this->shields + $bonus, 0),
            max($this->armor + $bonus, 0),
            $this->factor,
        );
    }

    /**
     * The tech multipliers in the order the PHP engine stores them with its fleets (`techs`).
     *
     * @return array{0: float, 1: float, 2: float} attack, shield, armor
     */
    public function multipliers(): array
    {
        return [$this->attackMultiplier(), $this->shieldMultiplier(), $this->armorMultiplier()];
    }
}
