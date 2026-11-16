<?php

declare(strict_types=1);

namespace App\Game\Model;

/**
 * Base cost of an element per resource (level 1 for buildings and research, one unit for ships, defense and missiles).
 * Immutable. A resource the element does not cost reads as 0.0.
 */
final readonly class ResourceCost
{
    public function __construct(
        public float $metal,
        public float $crystal,
        public float $deuterium,
        public float $energy,
    ) {}

    /**
     * @param array<int, mixed> $cost resource id (901, 902, 903, 911) => amount, as in `$pricelist[$id]['cost']`
     */
    public static function fromArray(array $cost): self
    {
        return new self(
            (float) ($cost[RESOURCE_METAL] ?? 0),
            (float) ($cost[RESOURCE_CRYSTAL] ?? 0),
            (float) ($cost[RESOURCE_DEUT] ?? 0),
            (float) ($cost[RESOURCE_ENERGY] ?? 0),
        );
    }

    /** Cost in one resource; 0.0 for an id that is no resource. */
    public function of(int $resourceId): float
    {
        return match ($resourceId) {
            RESOURCE_METAL => $this->metal,
            RESOURCE_CRYSTAL => $this->crystal,
            RESOURCE_DEUT => $this->deuterium,
            RESOURCE_ENERGY => $this->energy,
            default => 0.0,
        };
    }

    /**
     * Only the resources with a non-zero cost, keyed by resource id, in the order metal, crystal, deuterium, energy.
     *
     * @return array<int, float>
     */
    public function nonZero(): array
    {
        $result = [];
        foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT, RESOURCE_ENERGY] as $resourceId) {
            if ($this->of($resourceId) != 0) {
                $result[$resourceId] = $this->of($resourceId);
            }
        }

        return $result;
    }
}
