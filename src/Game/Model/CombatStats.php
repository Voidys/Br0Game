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
        public float $attack,
        public float $shield,
        public float $bigShield,
        public float $stun,
        public array $rapidFire,
    ) {}

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
            (float) ($caps['attack'] ?? 0),
            (float) ($caps['shield'] ?? 0),
            (float) ($caps['bigshield'] ?? 0),
            (float) ($caps['stun'] ?? 0),
            $rapid,
        );
    }
}
