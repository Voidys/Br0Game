<?php

declare(strict_types=1);

namespace App\Game\Model;

/**
 * Combat values of an element (`$CombatCaps[$id]`): attack, shield, big shield, stun and the rapidfire table
 * (target element id => shots). Immutable.
 */
final readonly class CombatStats
{
    /**
     * @param array<int, int> $rapidFire target element id => rapidfire value (`$CombatCaps[$id]['sd']`)
     */
    public function __construct(
        public int|float $attack,
        public int|float $shield,
        public int|float $bigShield,
        public int|float $stun,
        public array $rapidFire,
    ) {}

    /** Rapidfire shots against a target element (`$CombatCaps[$id]['sd'][$target] ?? 0`). */
    public function rapidFireAgainst(int $target): int
    {
        return $this->rapidFire[$target] ?? 0;
    }

    /**
     * @param array<string, mixed> $caps entry of `$CombatCaps`
     */
    public static function fromArray(array $caps): self
    {
        $rapid = [];
        foreach (is_array($caps['sd'] ?? null) ? $caps['sd'] : [] as $target => $shots) {
            $rapid[(int) $target] = (int) $shots;
        }

        return new self(
            Numeric::of($caps['attack'] ?? 0),
            Numeric::of($caps['shield'] ?? 0),
            Numeric::of($caps['bigshield'] ?? 0),
            Numeric::of($caps['stun'] ?? 0),
            $rapid,
        );
    }
}
