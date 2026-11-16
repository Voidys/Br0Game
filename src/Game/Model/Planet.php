<?php

declare(strict_types=1);

namespace App\Game\Model;

/**
 * Typed, immutable read model of one planet or moon row (`planets`) as the game mechanics see it.
 *
 * What it is: a snapshot of the `$PLANET` state array: identity and coordinates, type, temperature and fields,
 * the three stocks with production per hour, storage and bonus percentages, energy, queue end times and raw queue
 * strings, plus the building levels, ship/defense/missile counts and production percentages as typed element maps.
 * It is built from the array at the moment of the call and does NOT follow later changes of the array.
 *
 * What it is not: a write model. Resource updates, queue changes and the like still mutate the state array and go
 * through its persistence code; build a fresh snapshot afterwards when the new values matter.
 *
 * How to get one: `$this->models()->planet($PLANET)` in any class using the `GameUtilities` trait, or
 * `Planet::fromState($row, $resource)` where `$resource` is the element id => column name table of the game data
 * (`$this->state->resource`). Missing or NULL keys never raise: they read as 0 / '' / null, `has()` tells whether the
 * row carried the element at all.
 */
final readonly class Planet
{
    public const TYPE_PLANET = 1;
    public const TYPE_MOON = 3;

    /**
     * @param array<int, float> $elements       building level / ship, defense and missile count per element id
     * @param array<int, int>   $productionRate production percentage (0-10) per producing element id (`*_porcent`)
     * @param list<int>|null    $labNetwork     laboratory levels usable for a research (own lab plus network labs),
     *                                          null when the row carries no list (only the own lab counts)
     */
    public function __construct(
        public int $id,
        public int $ownerId,
        public string $name,
        public int $universe,
        public int $galaxy,
        public int $system,
        public int $position,
        public int $type,
        public int $lastUpdate,
        public int $destroyed,
        public int $fieldsCurrent,
        public int $fieldsMax,
        public int $tempMin,
        public int $tempMax,
        public float $metal,
        public float $crystal,
        public float $deuterium,
        public float $metalPerHour,
        public float $crystalPerHour,
        public float $deuteriumPerHour,
        public float $metalMax,
        public float $crystalMax,
        public float $deuteriumMax,
        public float $metalBonusPercent,
        public float $crystalBonusPercent,
        public float $deuteriumBonusPercent,
        public float $energy,
        public float $energyUsed,
        public int $buildingEnd,
        public string $buildingQueue,
        public int $hangarEnd,
        public string $hangarQueue,
        public int $moonId,
        public int $lastJumpTime,
        private array $elements,
        private array $productionRate,
        public ?array $labNetwork,
    ) {}

    /**
     * Builds the snapshot from a planet row / `$PLANET` state array.
     *
     * @param array<string, mixed> $row      the planet row (extra keys such as `<lab>_inter` are used when present)
     * @param array<int, string>   $resource element id => column name table (`$this->state->resource`)
     */
    public static function fromState(array $row, array $resource): self
    {
        $elements = [];
        $rates = [];
        foreach ($resource as $elementId => $column) {
            if (isset($row[$column]) && is_numeric($row[$column])) {
                $elements[(int) $elementId] = (float) $row[$column];
            }
            if (isset($row[$column . '_porcent']) && is_numeric($row[$column . '_porcent'])) {
                $rates[(int) $elementId] = (int) $row[$column . '_porcent'];
            }
        }

        $labNetwork = null;
        $inter = $row[($resource[31] ?? 'laboratory') . '_inter'] ?? null;
        if (is_array($inter)) {
            $labNetwork = array_map(static fn($level): int => (int) $level, array_values($inter));
        }

        return new self(
            id: (int) ($row['id'] ?? 0),
            ownerId: (int) ($row['id_owner'] ?? 0),
            name: (string) ($row['name'] ?? ''),
            universe: (int) ($row['universe'] ?? 0),
            galaxy: (int) ($row['galaxy'] ?? 0),
            system: (int) ($row['system'] ?? 0),
            position: (int) ($row['planet'] ?? 0),
            type: (int) ($row['planet_type'] ?? 0),
            lastUpdate: (int) ($row['last_update'] ?? 0),
            destroyed: (int) ($row['destruyed'] ?? 0),
            fieldsCurrent: (int) ($row['field_current'] ?? 0),
            fieldsMax: (int) ($row['field_max'] ?? 0),
            tempMin: (int) ($row['temp_min'] ?? 0),
            tempMax: (int) ($row['temp_max'] ?? 0),
            metal: (float) ($row['metal'] ?? 0),
            crystal: (float) ($row['crystal'] ?? 0),
            deuterium: (float) ($row['deuterium'] ?? 0),
            metalPerHour: (float) ($row['metal_perhour'] ?? 0),
            crystalPerHour: (float) ($row['crystal_perhour'] ?? 0),
            deuteriumPerHour: (float) ($row['deuterium_perhour'] ?? 0),
            metalMax: (float) ($row['metal_max'] ?? 0),
            crystalMax: (float) ($row['crystal_max'] ?? 0),
            deuteriumMax: (float) ($row['deuterium_max'] ?? 0),
            metalBonusPercent: (float) ($row['metal_bonus_percent'] ?? 0),
            crystalBonusPercent: (float) ($row['crystal_bonus_percent'] ?? 0),
            deuteriumBonusPercent: (float) ($row['deuterium_bonus_percent'] ?? 0),
            energy: (float) ($row['energy'] ?? 0),
            energyUsed: (float) ($row['energy_used'] ?? 0),
            buildingEnd: (int) ($row['b_building'] ?? 0),
            buildingQueue: (string) ($row['b_building_id'] ?? ''),
            hangarEnd: (int) ($row['b_hangar'] ?? 0),
            hangarQueue: (string) ($row['b_hangar_id'] ?? ''),
            moonId: (int) ($row['id_luna'] ?? 0),
            lastJumpTime: (int) ($row['last_jump_time'] ?? 0),
            elements: $elements,
            productionRate: $rates,
            labNetwork: $labNetwork,
        );
    }

    /** Whether the row carried a value for this element (the `isset($PLANET[$resource[$id]])` test of the array code). */
    public function has(int $elementId): bool
    {
        return isset($this->elements[$elementId]);
    }

    /** Building level of an element (also usable for any element stored as a whole number); 0 when absent. */
    public function level(int $elementId): int
    {
        return (int) ($this->elements[$elementId] ?? 0);
    }

    /** Number of ships, defense units or missiles of an element (float like the 64-bit counters in the mechanics); 0 when absent. */
    public function count(int $elementId): float
    {
        return $this->elements[$elementId] ?? 0.0;
    }

    /** Production percentage (0-10) the player set for a producing element; 0 when absent. */
    public function productionRate(int $elementId): int
    {
        return $this->productionRate[$elementId] ?? 0;
    }

    /** True for a moon (planet type 3). */
    public function isMoon(): bool
    {
        return $this->type === self::TYPE_MOON;
    }
}
