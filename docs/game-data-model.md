# Typed game data (`App\Game\Model`)

The game state still carries the old untyped arrays, but new code reads through typed, immutable value objects.

## Element catalog (game data tables)

The former state arrays `resource`, `reslist`, `pricelist`, `CombatCaps`, `ProdGrid` and `requeriments` are retired: `GameData::load()` builds `ElementCatalog` from the vars cache and publishes only it, as `$state->elementCatalog` (`GameUtilities::elements()` throws `LogicException('game data not loaded')` before that). The catalog is the only source of the unit data; the `was $...` notes and the migration table below are history. `ElementCatalogTest` proves it equal to the raw vars data with its own reference loader.

```php
$elements = $this->elements();               // GameUtilities, built lazily once per loaded game data

$elements->column(RESOURCE_METAL);           // 'metal'            (was $resource[901])
$elements->ids('fleet');                     // list<int>          (was $reslist['fleet'])
$elements->allowedOn($planetType);           // buildings allowed on a planet type (was $reslist['allow'][$t])
$elements->shipTypeIds('civil');             // was $reslist['ship_type']['civil']
$elements->resourceIds(1);                   // was $reslist['resstype'][1]
$elements->isDefense($id);                   // also isBuilding/isResearch/isFleet/isMissile/isHangarUnit/isOnePerPlanet

$element = $elements->get($id);              // Element, OutOfBoundsException for an unknown id
$element->cost->of(RESOURCE_CRYSTAL);        // was $pricelist[$id]['cost'][902]
$element->factor; $element->maxLevel; $element->capacity;
$element->consumption(1); $element->speed(1); $element->speedTech(1);
$element->combat->attack; $element->combat->rapidFire;      // was $CombatCaps[$id]
$element->productionFormula(RESOURCE_METAL); $element->storageFormula(...);   // was $ProdGrid
$element->requirements;                      // required id => level (was $requeriments[$id])
```

Groups: `build`, `tech`, `fleet`, `defense`, `missile`, `prod`, `storage`, `one`, `ressources` (`ElementCatalog::GROUPS`), in the order of the game data. Numeric columns the data leaves NULL read as 0 (`maxLevel` as null). The data never changes after `GameData::load()`, so one catalog is shared by the whole request (`$state->elementCatalog`, reset by `load()`).

`tests/Integration/ElementCatalogTest.php` compares the catalog with the arrays for every element id and every accessor, and `fromVarsData()` with the state-built catalog.

## Migration table: array access to catalog call

`$e = $this->elements();` (`GameUtilities`). Lists are `list<int>` re-indexed from 0 (the arrays were keyed by element id; only iterate or use `in_array`, never index them by id). All lists keep the order of the arrays. Replace the local copy line (`$resource = $this->state->resource;` ...) as well as the accesses; do not keep both.

| Array access | Catalog call |
|---|---|
| `$resource[$id]` (column, e.g. `$PLANET[$resource[$id]]`) | `$e->column($id)` (throws `OutOfBoundsException` for an unknown id; use `$e->find($id)?->column` where the old code tolerated it) |
| `$resource[$id] ?? 0` / `isset($resource[$id])` | `$e->has($id)` / `$e->find($id)?->column` |
| `$resource[RESOURCE_METAL]` ... (901/902/903/911) | `$e->column(RESOURCE_METAL)` (same call, those ids are in the catalog) |
| `array_map(fn($i) => $resource[$i], $ids)` | `$e->columns($ids)` (list, same order as `$ids`) |
| `foreach ($resource as $id => $column)` | `foreach ($e->all() as $id => $element)` with `$element->column` (or `foreach ($e->columnMap() as ...)`) |
| `array_keys($resource)` / `array_key_exists` | `array_keys($e->all())` / `$e->has($id)` |
| `array_search($column, $resource, true)` | `$e->idOfColumn($column)` (`?int`) |
| whole `$resource` handed to a function (`User::fromState($row, $resource)`, `Planet::fromState`, `checkPlanetRessAchievements28($p, $resource, ..)`, `BotActions::res()`) | `$e->columnMap()` (exact `$resource` shape: `array<int, string>`, game data order) |
| `$reslist['fleet']`, `['defense']`, `['build']`, `['tech']`, `['missile']`, `['prod']`, `['storage']`, `['one']`, `['ressources']`, `$reslist[$group]` | `$e->ids('fleet')` etc. (`$e->ids($group)` for a variable group; unknown group = `[]`) |
| `$reslist['allow'][$planetType]` | `$e->allowedOn($planetType)` |
| `in_array($id, $reslist['allow'][$t])` | `$e->isAllowedOn($id, $t)` |
| `foreach ($reslist['allow'] as $type => $ids)` | `foreach ($e->allowedMap() as $type => $ids)` |
| `$reslist['ship_type'][$type]` (`SHIP_TYPE_RECYCLER`, `'civil'`, `'military'`, `'unknown'`) | `$e->shipTypeIds($type)` |
| `isset($reslist['ship_type'][$type][$id])` / `in_array` | `$e->isShipType($id, $type)` |
| `foreach ($reslist['ship_type'] as $type => $ids)` | `foreach ($e->shipTypes() as $type => $ids)` |
| `$reslist['resstype'][1|2|3]` | `$e->resourceIds(1|2|3)` (1 tradable 901-903, 2 energy 911, 3 none) |
| `foreach ($reslist['resstype'] as ...)` | `$e->resourceTypes()` |
| `in_array($id, $reslist['build'])` ... `['tech']`, `['fleet']`, `['defense']`, `['missile']`, `['one']`, `['ressources']` | `$e->isBuilding($id)`, `isResearch`, `isFleet`, `isDefense`, `isMissile`, `isOnePerPlanet`, `isResource`; fleet+defense+missile: `isHangarUnit`; other groups (`prod`, `storage`): `$e->get($id)->inGroup('prod')` or `in_array($id, $e->ids('prod'), true)` |
| `array_merge($reslist['fleet'], $reslist['defense'])` etc. | `array_merge($e->ids('fleet'), $e->ids('defense'))` |
| whole `$reslist` handed on (`GiveawayController` template, `Statbuilder`) | pass `$e->groupMap()` (flat groups only) plus `allowedMap()`/`shipTypes()`/`resourceTypes()` where nested lists are needed; better pass the catalog / the ids the consumer iterates |
| `$pricelist[$id]['cost'][RESOURCE_METAL]` (also 902/903/911, `$pricelist[$id]['cost'][$res]`, `?? 0`) | `$e->cost($id)->metal` / `->crystal` / `->deuterium` / `->energy`, variable resource id: `->of($resId)` (0.0 when not costed) |
| `$cost[901] + $cost[902]` | `$e->cost($id)->metalCrystal()` |
| `$cost[901] + $cost[902] + $cost[903]` | `$e->cost($id)->metalCrystalDeuterium()` |
| `$pricelist[$id]['cost']` as an array (templates, loops over resource ids, `FleetDealer`) | `$e->cost($id)->toArray()` (`[901 => .., 902 => .., 903 => .., 911 => ..]`, floats) or `->nonZero()` (only non-zero, same order) |
| `$pricelist[$id]['factor']` | `$e->factor($id)` (float) |
| `$pricelist[$id]['max']` | `$e->maxLevel($id)` (`?int`; `null < $level` equals `0 < $level`, so `?? 0` keeps old comparisons) |
| `$pricelist[$id]['max_useful_level']` | `$e->get($id)->maxUsefulLevel` |
| `$pricelist[$id]['capacity']` | `$e->capacity($id)` (float) |
| `$pricelist[$id]['consumption1..3']` | `$e->get($id)->consumption(1..3)` |
| `$pricelist[$id]['speed1..3']` | `$e->get($id)->speed(1..3)` |
| `$pricelist[$id]['tech1..3']` | `$e->get($id)->speedTech(1..3)` (int) |
| first non-empty `consumption1..3` (AllianceDepot `baseConsumption`) | `$e->get($id)->baseConsumption()` |
| `$pricelist[$id] ?? []` (tolerant lookup) | `$e->find($id)` (null when unknown) |
| `foreach ($pricelist as $id => $price)` | `foreach ($e->all() as $id => $element)` |
| `$CombatCaps[$id]['attack']` / `['shield']` / `['bigshield']` / `['stun']` | `$e->combat($id)->attack` / `->shield` / `->bigShield` / `->stun` (floats) |
| `$CombatCaps[$id]['sd']` (whole rapidfire table) | `$e->combat($id)->rapidFire` (`array<int,int>`, may be empty) |
| `isset($caps[$id]['sd'][$t]) && $caps[$id]['sd'][$t] > 1` / `$caps[$id]['sd'][$t] ?? 0` | `$e->combat($id)->rapidFireAgainst($t)` (0 = none) |
| `$CombatCaps[$id]['sd'] ?? new \stdClass()` (JSON for the Rust engine) | `$e->combat($id)->rapidFire ?: new \stdClass()` |
| `foreach ($CombatCaps[$id]['sd'] as $target => $shots)` | `foreach ($e->combat($id)->rapidFire as $target => $shots)` |
| `$ProdGrid[$id]['production'][$resId]` (evaluated with `FormulaEvaluator`) | `$e->productionFormula($id, $resId)` (`?string`, null = element does not produce it) |
| `isset($ProdGrid[$id]['production'][$resId])` | `$e->productionFormula($id, $resId) !== null` |
| `$ProdGrid[$id]['storage'][$resId]` / `isset(...)` | `$e->storageFormula($id, $resId)` / `!== null` |
| `foreach ($ProdGrid[$id]['production'] as $resId => $formula)` | `foreach ($e->production($id) as $resId => $formula)` (non-empty only; the arrays hold 901/902/903/911 keys with NULL for none) |
| `foreach ($ProdGrid[$id]['storage'] ...)` | `foreach ($e->storage($id) as ...)` |
| `foreach ($ProdGrid as $id => $kinds)` | `foreach ($e->all() as $id => $element)` with `$element->production` / `$element->storage` |
| `$requeriments[$id]` / `$requeriments[$id] ?? []` | `$e->requirements($id)` (`array<int,int>`, `[]` when none or unknown) |
| `isset($requeriments[$id][$req])` / `$requeriments[$id][$req]` | `$e->get($id)->requiredLevel($req)` (`?int`) |
| whole `$requeriments` to `TechTree::build()` | `$e->requirementMap()` (exact shape: element id => [required id => level], only elements with requirements, game data order, levels as int) |

Not present as arrays in this code base: `reslist['bonus']` (no such group exists in the vars cache), `ProdGrid`/`requeriments` for ids missing from `resource`.

### Per-universe data (Statbuilder, StatScores, StatDataLoader, StatRecords)

`ElementCatalog::fromVarsData(array $vars, int $universe)` builds the catalog of any universe from the vars cache data (`(new Cache($db, $state))->getData('vars')`) without touching the state: universe 0 defaults, overridden recursively by the universe's rows, then the fixed 901/902/903/911 resources and the `ressources`/`resstype` lists, exactly as `GameData::load()` does. Replace `$this->resources[$uni]`, `$this->reslists[$uni]`, `$this->pricelists[$uni]` by one `array<int, ElementCatalog>` per universe. (Unlike the old statistics copies these catalogs also contain 901-911; only iterate groups, not `all()`, where that matters.)

### Writes to the arrays

Only `GameData::load()` writes them (after publishing the vars cache data of the universe): `resource[901|902|903|911]`, `reslist['ressources']`, `reslist['resstype'][1|2|3]`. `VarsBuildCache` builds its own local arrays (the cache data, not the state). No other code assigns to the state arrays; all `$resource[...] = ...` seen in the migration targets assign into local planet/delta arrays that merely use `$resource[$id]` as the column name key (`$PLANET[$resource[$id]] = ...` becomes `$PLANET[$e->column($id)] = ...`).

## Player and planet snapshots

`User` (the player row) and `Planet` (a planet or moon row) are typed read snapshots of `$USER` / `$PLANET`: DB strings become `int` / `float` / `bool`, missing keys read as 0 or empty.

```php
$this->player()->allianceId;                 // snapshot of $this->state->USER, built per call
$this->planet()->metal;                      // float, snapshot of $this->state->PLANET
$this->models()->user($row); $this->models()->planet($row);   // any other row
```

They are snapshots: nothing is cached and they do not follow later changes of the arrays. Writes keep using the state arrays (`$this->state->PLANET['metal'] -= ...`); build a new snapshot afterwards when the new values matter.

## Rule for new code

Read element data from the catalog and player/planet values from the snapshots; do not index `$resource`, `$reslist`, `$pricelist` & co. in new code. Existing code is migrated step by step (done: `BuildFunctions`, `ResourceProduction`, the buildings, research, shipyard, empire, techtree, records and advanced stats controllers).
