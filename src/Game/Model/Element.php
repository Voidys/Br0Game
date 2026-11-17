<?php

declare(strict_types=1);

namespace App\Game\Model;

/**
 * One game element (building, research, ship, defense, missile or resource) of the current universe as the game
 * data tables describe it: database column, groups, cost, speed and fuel values, combat values, production and
 * storage formulas and requirements. Immutable, built by ElementCatalog from the loaded game data.
 *
 * Numeric columns the data leaves empty (NULL) read as 0. Production and storage formulas stay strings (they are
 * evaluated by ResourceProduction); only resources with a formula are listed.
 */
final readonly class Element
{
    /**
     * @param list<string>     $groups         reslist groups the element belongs to (prod, storage, one, build, tech, fleet,
     *                                         defense, missile)
     * @param string|null      $shipType       civil, military, recycler or unknown for ships, null otherwise
     * @param list<int>        $planetTypes    planet types (1 planet, 3 moon) a building can be built on
     * @param array<int, int>  $requirements   required element id => level
     * @param array<int, string> $production   resource id => production formula (non-empty only)
     * @param array<int, string> $storage      resource id => storage formula (non-empty only)
     * @param array<int, float> $consumption   drive 1..3 => fuel consumption
     * @param array<int, float> $speed         drive 1..3 => base speed
     * @param array<int, int>   $speedTech     drive 1..3 => technology that drives it
     */
    public function __construct(
        public int $id,
        public string $column,
        public array $groups,
        public ?string $shipType,
        public array $planetTypes,
        public ResourceCost $cost,
        public float $factor,
        public ?int $maxLevel,
        public int $maxUsefulLevel,
        public float $capacity,
        public array $consumption,
        public array $speed,
        public array $speedTech,
        public CombatStats $combat,
        public array $production,
        public array $storage,
        public array $requirements,
    ) {}

    public function inGroup(string $group): bool
    {
        return in_array($group, $this->groups, true);
    }

    public function isBuilding(): bool
    {
        return $this->inGroup('build');
    }

    public function isResearch(): bool
    {
        return $this->inGroup('tech');
    }

    public function isFleet(): bool
    {
        return $this->inGroup('fleet');
    }

    public function isDefense(): bool
    {
        return $this->inGroup('defense');
    }

    public function isMissile(): bool
    {
        return $this->inGroup('missile');
    }

    /** Ships, defense and missiles: elements bought in units from the hangar. */
    public function isHangarUnit(): bool
    {
        return $this->isFleet() || $this->isDefense() || $this->isMissile();
    }

    /** Only one of it may exist per planet (`onePerPlanet`). */
    public function isOnePerPlanet(): bool
    {
        return $this->inGroup('one');
    }

    public function consumption(int $drive): float
    {
        return $this->consumption[$drive] ?? 0.0;
    }

    public function speed(int $drive): float
    {
        return $this->speed[$drive] ?? 0.0;
    }

    public function speedTech(int $drive): int
    {
        return $this->speedTech[$drive] ?? 0;
    }

    /** Production formula for a resource, null when the element does not produce it. */
    public function productionFormula(int $resourceId): ?string
    {
        return $this->production[$resourceId] ?? null;
    }

    /** Storage formula for a resource, null when the element stores none of it. */
    public function storageFormula(int $resourceId): ?string
    {
        return $this->storage[$resourceId] ?? null;
    }

    /** Level the requirement element must reach, null when it is not required. */
    public function requiredLevel(int $elementId): ?int
    {
        return $this->requirements[$elementId] ?? null;
    }

    /**
     * Fuel consumption of the first drive with a non-zero value (drive 1, 2, then 3), 0 when the element has none.
     */
    public function baseConsumption(): float
    {
        foreach ([1, 2, 3] as $drive) {
            if ($this->consumption($drive) != 0) {
                return $this->consumption($drive);
            }
        }

        return 0.0;
    }
}
