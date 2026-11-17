<?php

declare(strict_types=1);

namespace App\Game\Model;

/**
 * Base cost of an element per resource (level 1 for buildings and research, one unit for ships, defense and missiles).
 * Immutable. A resource the element does not cost reads as 0. Values keep the type the game data has (int or float, see Numeric).
 */
final readonly class ResourceCost
{
    public function __construct(
        public int|float $metal,
        public int|float $crystal,
        public int|float $deuterium,
        public int|float $energy,
    ) {}

    /**
     * @param array<int, mixed> $cost resource id (901, 902, 903, 911) => amount, as in `$pricelist[$id]['cost']`
     */
    public static function fromArray(array $cost): self
    {
        return new self(
            Numeric::of($cost[RESOURCE_METAL] ?? 0),
            Numeric::of($cost[RESOURCE_CRYSTAL] ?? 0),
            Numeric::of($cost[RESOURCE_DEUT] ?? 0),
            Numeric::of($cost[RESOURCE_ENERGY] ?? 0),
        );
    }

    /** Cost in one resource; 0 for an id that is no resource. */
    public function of(int $resourceId): int|float
    {
        return match ($resourceId) {
            RESOURCE_METAL => $this->metal,
            RESOURCE_CRYSTAL => $this->crystal,
            RESOURCE_DEUT => $this->deuterium,
            RESOURCE_ENERGY => $this->energy,
            default => 0,
        };
    }

    /**
     * All four resources keyed by resource id in the order metal, crystal, deuterium, energy: the shape of
     * `$pricelist[$id]['cost']`.
     *
     * @return array<int, int|float>
     */
    public function toArray(): array
    {
        return [
            RESOURCE_METAL => $this->metal,
            RESOURCE_CRYSTAL => $this->crystal,
            RESOURCE_DEUT => $this->deuterium,
            RESOURCE_ENERGY => $this->energy,
        ];
    }

    /** Metal plus crystal (`cost[901] + cost[902]`), the base of the structure points and debris. */
    public function metalCrystal(): int|float
    {
        return $this->metal + $this->crystal;
    }

    /** Metal plus crystal plus deuterium (`cost[901] + cost[902] + cost[903]`), the base of the statistic points. */
    public function metalCrystalDeuterium(): int|float
    {
        return $this->metal + $this->crystal + $this->deuterium;
    }
}
