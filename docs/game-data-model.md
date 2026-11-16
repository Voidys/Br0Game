# Typed game data (`App\Game\Model`)

The game state still carries the old untyped arrays, but new code reads through typed, immutable value objects.

## Element catalog (game data tables)

`$this->state->resource`, `reslist`, `pricelist`, `CombatCaps`, `ProdGrid` and `requeriments` are loaded by `GameData::load()` from the vars cache. `ElementCatalog` is the typed read-only view of them:

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

`tests/Integration/ElementCatalogTest.php` compares the catalog with the arrays for every element id.

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
