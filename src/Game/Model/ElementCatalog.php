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
     * @param array<int, array<int, int>> $requirementMap element id => [required id => level] in game data order
     */
    private function __construct(
        private readonly array $elements,
        private readonly array $groups,
        private readonly array $allowed,
        private readonly array $shipTypes,
        private readonly array $resourceTypes,
        private readonly array $requirementMap = [],
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

        $requirementMap = [];
        foreach ($requeriments as $elementId => $required) {
            foreach (is_array($required) ? $required : [] as $requiredId => $level) {
                $requirementMap[(int) $elementId][(int) $requiredId] = (int) $level;
            }
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

        return new self($elements, $groups, $allowed, $shipTypes, $resourceTypes, $requirementMap);
    }

    /**
     * Catalog of one universe straight from the "vars" cache data (`Cache::getData('vars')`: universe id => the six
     * arrays), without the state: universe 0 holds the defaults, the universe's own rows override them, then the
     * fixed resources are added exactly like GameData::load() does (901 metal, 902 crystal, 903 deuterium, 911
     * energy; groups `ressources` and `resstype` 1 tradable, 2 energy, 3 none).
     *
     * @param array<int, array<string, mixed>> $vars vars cache data by universe id (must contain 0)
     */
    public static function fromVarsData(array $vars, int $universe): self
    {
        $data = $vars[0] ?? [];
        if (isset($vars[$universe])) {
            $data = array_replace_recursive($data, $vars[$universe]);
        }

        $resource = is_array($data['resource'] ?? null) ? $data['resource'] : [];
        $resource[RESOURCE_METAL] = 'metal';
        $resource[RESOURCE_CRYSTAL] = 'crystal';
        $resource[RESOURCE_DEUT] = 'deuterium';
        $resource[RESOURCE_ENERGY] = 'energy';

        $reslist = is_array($data['reslist'] ?? null) ? $data['reslist'] : [];
        $reslist['ressources'] = [RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT, RESOURCE_ENERGY];
        $reslist['resstype'][1] = [RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT];
        $reslist['resstype'][2] = [RESOURCE_ENERGY];
        $reslist['resstype'][3] = [];

        return self::fromArrays(
            $resource,
            $reslist,
            is_array($data['pricelist'] ?? null) ? $data['pricelist'] : [],
            is_array($data['CombatCaps'] ?? null) ? $data['CombatCaps'] : [],
            is_array($data['ProdGrid'] ?? null) ? $data['ProdGrid'] : [],
            is_array($data['requeriments'] ?? null) ? $data['requeriments'] : [],
        );
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

    /** Element or null for an id the game data does not know (the tolerant counterpart of get()). */
    public function find(int $id): ?Element
    {
        return $this->elements[$id] ?? null;
    }

    /**
     * Columns of the given element ids, in the order of the given ids (list, not keyed by id).
     *
     * @param list<int> $ids
     * @return list<string>
     * @throws \OutOfBoundsException for an unknown id
     */
    public function columns(array $ids): array
    {
        return array_map(fn(int $id): string => $this->column($id), array_values($ids));
    }

    /**
     * The whole element id => column table in game data order: exactly the shape of `$resource` (including 901, 902,
     * 903, 911). For code that needs the full map (`User::fromState()`, `Planet::fromState()`, reverse lookups).
     *
     * @return array<int, string>
     */
    public function columnMap(): array
    {
        return array_map(static fn(Element $element): string => $element->column, $this->elements);
    }

    /** Element id of a database column (`array_search($column, $resource)`), null when no element uses it. */
    public function idOfColumn(string $column): ?int
    {
        foreach ($this->elements as $id => $element) {
            if ($element->column === $column) {
                return $id;
            }
        }

        return null;
    }

    /** Base cost (`$pricelist[$id]['cost']`). @throws \OutOfBoundsException for an unknown id */
    public function cost(int $id): ResourceCost
    {
        return $this->get($id)->cost;
    }

    /** Combat values and rapidfire table (`$CombatCaps[$id]`). @throws \OutOfBoundsException for an unknown id */
    public function combat(int $id): CombatStats
    {
        return $this->get($id)->combat;
    }

    /** Cost growth factor per level (`$pricelist[$id]['factor']`). */
    public function factor(int $id): float
    {
        return $this->get($id)->factor;
    }

    /** Max level (`$pricelist[$id]['max']`), null when the data sets none. */
    public function maxLevel(int $id): ?int
    {
        return $this->get($id)->maxLevel;
    }

    /** Cargo capacity of a ship (`$pricelist[$id]['capacity']`). */
    public function capacity(int $id): float
    {
        return $this->get($id)->capacity;
    }

    /**
     * Production formulas of an element by resource id, only the non-empty ones (`$ProdGrid[$id]['production']`
     * entries that are set and not empty).
     *
     * @return array<int, string>
     */
    public function production(int $id): array
    {
        return $this->get($id)->production;
    }

    /**
     * Storage formulas of an element by resource id, only the non-empty ones (`$ProdGrid[$id]['storage']`).
     *
     * @return array<int, string>
     */
    public function storage(int $id): array
    {
        return $this->get($id)->storage;
    }

    /** `$ProdGrid[$id]['production'][$resourceId]`, null when the element does not produce the resource. */
    public function productionFormula(int $id, int $resourceId): ?string
    {
        return $this->get($id)->productionFormula($resourceId);
    }

    /** `$ProdGrid[$id]['storage'][$resourceId]`, null when the element does not store the resource. */
    public function storageFormula(int $id, int $resourceId): ?string
    {
        return $this->get($id)->storageFormula($resourceId);
    }

    /**
     * Requirements of one element, required id => level (`$requeriments[$id] ?? []`); empty for an element without
     * requirements or an unknown id.
     *
     * @return array<int, int>
     */
    public function requirements(int $id): array
    {
        return $this->requirementMap[$id] ?? [];
    }

    /**
     * The whole requirement table, element id => [required id => level], in game data order: the exact shape of
     * `$requeriments` (only elements that have requirements). For `TechTree::build()`.
     *
     * @return array<int, array<int, int>>
     */
    public function requirementMap(): array
    {
        return $this->requirementMap;
    }

    /**
     * All flat groups at once, group => ids (`ElementCatalog::GROUPS`), in game data order.
     *
     * @return array<string, list<int>>
     */
    public function groupMap(): array
    {
        return $this->groups;
    }

    /**
     * Buildings per planet type (`$reslist['allow']`: 1 planet, 3 moon), for loops over every type.
     *
     * @return array<int, list<int>>
     */
    public function allowedMap(): array
    {
        return $this->allowed;
    }

    /**
     * Ships per ship type (`$reslist['ship_type']`: unknown, civil, military, recycler), for loops over every type.
     *
     * @return array<string, list<int>>
     */
    public function shipTypes(): array
    {
        return $this->shipTypes;
    }

    /**
     * Resource ids per resource type (`$reslist['resstype']`: 1 tradable, 2 energy, 3 none).
     *
     * @return array<int, list<int>>
     */
    public function resourceTypes(): array
    {
        return $this->resourceTypes;
    }

    /** `in_array($id, $reslist['allow'][$planetType])`: the building can be built on that planet type. */
    public function isAllowedOn(int $id, int $planetType): bool
    {
        return in_array($id, $this->allowed[$planetType] ?? [], true);
    }

    /** `isset($reslist['ship_type'][$shipType][$id])`: the ship is of that type (civil, military, recycler, unknown). */
    public function isShipType(int $id, string $shipType): bool
    {
        return in_array($id, $this->shipTypes[$shipType] ?? [], true);
    }

    /** One of the resources 901, 902, 903, 911 (`$reslist['ressources']`). */
    public function isResource(int $id): bool
    {
        return in_array($id, $this->groups['ressources'], true);
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
