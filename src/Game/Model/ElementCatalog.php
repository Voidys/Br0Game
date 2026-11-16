<?php

declare(strict_types=1);

namespace App\Game\Model;

use App\Game\Core\Singleton;

/**
 * Read-only catalog of the game elements of the current universe: the typed view of the game data tables that the
 * game state carries as the untyped arrays `$resource`, `$reslist`, `$pricelist`, `$CombatCaps`, `$ProdGrid` and
 * `$requeriments` (loaded by GameData from the vars cache).
 *
 * New code reads element data from here instead of from those arrays: `$this->elements()->get($id)->cost`,
 * `->column($id)`, `->ids('fleet')`, `->isDefense($id)`. Groups: build, tech, fleet, defense, missile, prod, storage,
 * one, ressources; nested ones through allowedOn() (planet type), shipTypeIds() and resourceIds() (resource type).
 *
 * Obtain it with `GameUtilities::elements()`; it is built once per loaded game data and shared by every class of the
 * request. It never changes after construction.
 */
final class ElementCatalog
{
    /** flat reslist groups with ids */
    public const GROUPS = ['prod', 'storage', 'one', 'build', 'tech', 'fleet', 'defense', 'missile', 'ressources'];

    /**
     * @param array<int, Element>        $elements
     * @param array<string, list<int>>   $groups     group => element ids in game data order
     * @param array<int, list<int>>      $allowed    planet type => building ids
     * @param array<string, list<int>>   $shipTypes  civil, military, recycler, unknown => ship ids
     * @param array<int, list<int>>      $resourceTypes resource type (1 tradable, 2 energy, 3 none) => resource ids
     */
    private function __construct(
        private readonly array $elements,
        private readonly array $groups,
        private readonly array $allowed,
        private readonly array $shipTypes,
        private readonly array $resourceTypes,
    ) {}

    /**
     * Builds the catalog from the six game data arrays of the state.
     *
     * @param array<int, string>                $resource     element id => column
     * @param array<string, mixed>              $reslist
     * @param array<int, array<string, mixed>>  $pricelist
     * @param array<int, array<string, mixed>>  $combatCaps
     * @param array<int, array<string, mixed>>  $prodGrid
     * @param array<int, array<int, mixed>>     $requeriments element id => [required id => level]
     */
    public static function fromArrays(
        array $resource,
        array $reslist,
        array $pricelist,
        array $combatCaps,
        array $prodGrid,
        array $requeriments,
    ): self {
        $groups = [];
        foreach (self::GROUPS as $group) {
            $groups[$group] = self::intList($reslist[$group] ?? []);
        }
        $allowed = [];
        foreach (is_array($reslist['allow'] ?? null) ? $reslist['allow'] : [] as $type => $ids) {
            $allowed[(int) $type] = self::intList($ids);
        }
        $shipTypes = [];
        foreach (is_array($reslist['ship_type'] ?? null) ? $reslist['ship_type'] : [] as $type => $ids) {
            $shipTypes[(string) $type] = self::intList($ids);
        }
        $resourceTypes = [];
        foreach (is_array($reslist['resstype'] ?? null) ? $reslist['resstype'] : [] as $type => $ids) {
            $resourceTypes[(int) $type] = self::intList($ids);
        }

        $elements = [];
        foreach ($resource as $id => $column) {
            $id = (int) $id;
            $memberOf = [];
            foreach (['prod', 'storage', 'one', 'build', 'tech', 'fleet', 'defense', 'missile'] as $group) {
                if (in_array($id, $groups[$group], true)) {
                    $memberOf[] = $group;
                }
            }
            $shipType = null;
            foreach ($shipTypes as $type => $ids) {
                if (in_array($id, $ids, true)) {
                    $shipType = (string) $type;
                }
            }
            $planetTypes = [];
            foreach ($allowed as $type => $ids) {
                if (in_array($id, $ids, true)) {
                    $planetTypes[] = $type;
                }
            }

            $price = $pricelist[$id] ?? [];
            $grid = $prodGrid[$id] ?? [];
            $requirements = [];
            foreach ($requeriments[$id] ?? [] as $required => $level) {
                $requirements[(int) $required] = (int) $level;
            }

            $elements[$id] = new Element(
                id: $id,
                column: (string) $column,
                groups: $memberOf,
                shipType: $shipType,
                planetTypes: $planetTypes,
                cost: ResourceCost::fromArray(is_array($price['cost'] ?? null) ? $price['cost'] : []),
                factor: (float) ($price['factor'] ?? 0),
                maxLevel: isset($price['max']) ? (int) $price['max'] : null,
                maxUsefulLevel: (int) ($price['max_useful_level'] ?? 0),
                capacity: (float) ($price['capacity'] ?? 0),
                consumption: self::drives($price, 'consumption'),
                speed: self::drives($price, 'speed'),
                speedTech: array_map('intval', self::drives($price, 'tech')),
                combat: CombatStats::fromArray($combatCaps[$id] ?? []),
                production: self::formulas($grid['production'] ?? []),
                storage: self::formulas($grid['storage'] ?? []),
                requirements: $requirements,
            );
        }

        return new self($elements, $groups, $allowed, $shipTypes, $resourceTypes);
    }

    /** Catalog of the game data currently loaded in the state. */
    public static function fromState(Singleton $state): self
    {
        return self::fromArrays(
            $state->resource ?? [],
            $state->reslist ?? [],
            $state->pricelist ?? [],
            $state->CombatCaps ?? [],
            $state->ProdGrid ?? [],
            $state->requeriments ?? [],
        );
    }

    public function has(int $id): bool
    {
        return isset($this->elements[$id]);
    }

    /**
     * @throws \OutOfBoundsException for an id the game data does not know
     */
    public function get(int $id): Element
    {
        return $this->elements[$id] ?? throw new \OutOfBoundsException('Unknown element id ' . $id . '.');
    }

    /** Database column (`$resource[$id]`) of an element, e.g. `metal_mine`, `metal`. */
    public function column(int $id): string
    {
        return $this->get($id)->column;
    }

    /**
     * All elements in game data order (id => Element).
     *
     * @return array<int, Element>
     */
    public function all(): array
    {
        return $this->elements;
    }

    /**
     * Element ids of a flat group (build, tech, fleet, defense, missile, prod, storage, one, ressources) in game data
     * order; an unknown group is empty.
     *
     * @return list<int>
     */
    public function ids(string $group): array
    {
        return $this->groups[$group] ?? [];
    }

    /**
     * Buildings that can be built on a planet type (`$reslist['allow'][$type]`: 1 planet, 3 moon).
     *
     * @return list<int>
     */
    public function allowedOn(int $planetType): array
    {
        return $this->allowed[$planetType] ?? [];
    }

    /**
     * Ships of a ship type (`$reslist['ship_type'][$type]`: civil, military, recycler, unknown).
     *
     * @return list<int>
     */
    public function shipTypeIds(string $shipType): array
    {
        return $this->shipTypes[$shipType] ?? [];
    }

    /**
     * Resource ids of a resource type (`$reslist['resstype'][$type]`: 1 tradable, 2 energy).
     *
     * @return list<int>
     */
    public function resourceIds(int $resourceType): array
    {
        return $this->resourceTypes[$resourceType] ?? [];
    }

    public function isBuilding(int $id): bool
    {
        return in_array($id, $this->groups['build'], true);
    }

    public function isResearch(int $id): bool
    {
        return in_array($id, $this->groups['tech'], true);
    }

    public function isFleet(int $id): bool
    {
        return in_array($id, $this->groups['fleet'], true);
    }

    public function isDefense(int $id): bool
    {
        return in_array($id, $this->groups['defense'], true);
    }

    public function isMissile(int $id): bool
    {
        return in_array($id, $this->groups['missile'], true);
    }

    /** Ship, defense or missile (bought in units). */
    public function isHangarUnit(int $id): bool
    {
        return $this->isFleet($id) || $this->isDefense($id) || $this->isMissile($id);
    }

    public function isOnePerPlanet(int $id): bool
    {
        return in_array($id, $this->groups['one'], true);
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $ids): array
    {
        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /**
     * @param array<string, mixed> $price pricelist entry
     * @return array<int, float>
     */
    private static function drives(array $price, string $prefix): array
    {
        $values = [];
        foreach ([1, 2, 3] as $drive) {
            $values[$drive] = (float) ($price[$prefix . $drive] ?? 0);
        }

        return $values;
    }

    /**
     * @return array<int, string>
     */
    private static function formulas(mixed $grid): array
    {
        $formulas = [];
        foreach (is_array($grid) ? $grid : [] as $resourceId => $formula) {
            if ($formula !== null && $formula !== '') {
                $formulas[(int) $resourceId] = (string) $formula;
            }
        }

        return $formulas;
    }
}
