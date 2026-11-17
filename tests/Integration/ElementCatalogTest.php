<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\Cache;
use App\Game\Core\GameUtilities;
use App\Game\Core\Universe;
use App\Game\Model\ElementCatalog;
use App\Tests\Support\GameTestCase;

/**
 * The typed element catalog must say exactly what the untyped game data arrays of the state say, for every element
 * of the loaded game data (columns, groups, costs, speeds, combat values, formulas, requirements).
 */
final class ElementCatalogTest extends GameTestCase
{
    use GameUtilities;

    /**
     * Reference loader: the raw game data arrays read from the vars cache exactly like the former
     * GameData::load() (universe 0 merged with the current universe, plus the fixed resources).
     *
     * @return array{resource: array<int, string>, reslist: array<string, mixed>, pricelist: array<int, array<string, mixed>>, CombatCaps: array<int, array<string, mixed>>, ProdGrid: array<int, array<string, mixed>>, requeriments: array<int, array<int, mixed>>}
     */
    private function raw(): array
    {
        $universe = (new Universe($this->state))->current();
        $vars = (new Cache($this->db, $this->state))->getData('vars');
        $result = $vars[0];
        if (isset($vars[$universe])) {
            $result = array_replace_recursive($result, $vars[$universe]);
        }

        $result['resource'][901] = 'metal';
        $result['resource'][902] = 'crystal';
        $result['resource'][903] = 'deuterium';
        $result['resource'][911] = 'energy';

        $result['reslist']['ressources'] = [901, 902, 903, 911];
        $result['reslist']['resstype'][1] = [901, 902, 903];
        $result['reslist']['resstype'][2] = [911];
        $result['reslist']['resstype'][3] = [];

        return $result;
    }

    private function catalog(): ElementCatalog
    {
        return $this->elements();
    }

    public function testEveryElementMatchesTheArrays(): void
    {
        $catalog = $this->catalog();
        $resource = $this->raw()['resource'];
        $pricelist = $this->raw()['pricelist'];
        $caps = $this->raw()['CombatCaps'];
        $grid = $this->raw()['ProdGrid'];
        $requirements = $this->raw()['requeriments'];
        $reslist = $this->raw()['reslist'];

        $this->assertNotEmpty($resource);
        $this->assertSame(array_map('intval', array_keys($resource)), array_keys($catalog->all()));

        foreach ($resource as $id => $column) {
            $element = $catalog->get($id);
            $this->assertSame($column, $element->column);
            $this->assertSame($column, $catalog->column($id));

            foreach (['prod', 'storage', 'one', 'build', 'tech', 'fleet', 'defense', 'missile'] as $group) {
                $this->assertSame(in_array($id, $reslist[$group]), $element->inGroup($group), "$id in $group");
            }
            $this->assertSame(in_array($id, $reslist['build']), $catalog->isBuilding($id));
            $this->assertSame(in_array($id, $reslist['tech']), $catalog->isResearch($id));
            $this->assertSame(in_array($id, $reslist['fleet']), $catalog->isFleet($id));
            $this->assertSame(in_array($id, $reslist['defense']), $catalog->isDefense($id));
            $this->assertSame(in_array($id, $reslist['missile']), $catalog->isMissile($id));
            $this->assertSame(in_array($id, $reslist['one']), $catalog->isOnePerPlanet($id));
            foreach ([1, 3] as $type) {
                $this->assertSame(in_array($id, $reslist['allow'][$type]), in_array($type, $element->planetTypes), "$id on $type");
            }
            foreach ($reslist['ship_type'] as $type => $ids) {
                $this->assertSame(in_array($id, $ids), $element->shipType === (string) $type, "$id ship type $type");
            }

            $price = $pricelist[$id] ?? [];
            foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT, RESOURCE_ENERGY] as $resId) {
                $this->assertEquals((float) ($price['cost'][$resId] ?? 0), $element->cost->of($resId), "$id cost $resId");
            }
            $this->assertEquals((float) ($price['factor'] ?? 0), $element->factor, "$id factor");
            $this->assertSame(isset($price['max']) ? (int) $price['max'] : null, $element->maxLevel, "$id max");
            $this->assertEquals((int) ($price['max_useful_level'] ?? 0), $element->maxUsefulLevel);
            $this->assertEquals((float) ($price['capacity'] ?? 0), $element->capacity, "$id capacity");
            foreach ([1, 2, 3] as $drive) {
                $this->assertEquals((float) ($price['consumption' . $drive] ?? 0), $element->consumption($drive));
                $this->assertEquals((float) ($price['speed' . $drive] ?? 0), $element->speed($drive));
                $this->assertEquals((int) ($price['tech' . $drive] ?? 0), $element->speedTech($drive));
            }

            $this->assertEquals((float) ($caps[$id]['attack'] ?? 0), $element->combat->attack, "$id attack");
            $this->assertEquals((float) ($caps[$id]['shield'] ?? 0), $element->combat->shield);
            $this->assertEquals((float) ($caps[$id]['bigshield'] ?? 0), $element->combat->bigShield);
            $this->assertEquals((float) ($caps[$id]['stun'] ?? 0), $element->combat->stun);
            $this->assertEquals($caps[$id]['sd'] ?? [], $element->combat->rapidFire, "$id rapidfire");

            foreach ($grid[$id]['production'] ?? [] as $resId => $formula) {
                $this->assertSame($formula === null ? null : (string) $formula, $element->productionFormula($resId), "$id production $resId");
            }
            foreach ($grid[$id]['storage'] ?? [] as $resId => $formula) {
                $this->assertSame($formula === null ? null : (string) $formula, $element->storageFormula($resId), "$id storage $resId");
            }
            $this->assertEquals($requirements[$id] ?? [], $element->requirements, "$id requirements");
        }
    }

    public function testGroupListsKeepTheOrderOfTheGameData(): void
    {
        $catalog = $this->catalog();
        $reslist = $this->raw()['reslist'];

        foreach (ElementCatalog::GROUPS as $group) {
            $this->assertEquals(array_values($reslist[$group]), $catalog->ids($group), $group);
        }
        $this->assertEquals(array_values($reslist['allow'][1]), $catalog->allowedOn(1));
        $this->assertEquals(array_values($reslist['allow'][3]), $catalog->allowedOn(3));
        foreach ($reslist['ship_type'] as $type => $ids) {
            $this->assertEquals(array_values($ids), $catalog->shipTypeIds((string) $type));
        }
        foreach ([1, 2, 3] as $type) {
            $this->assertEquals($reslist['resstype'][$type], $catalog->resourceIds($type));
        }
        $this->assertSame([], $catalog->ids('nonexistent'));
    }

    public function testUnknownElement(): void
    {
        $catalog = $this->catalog();

        $this->assertFalse($catalog->has(999999));
        $this->expectException(\OutOfBoundsException::class);
        $catalog->get(999999);
    }

    public function testKnownAnchors(): void
    {
        $catalog = $this->catalog();

        $this->assertSame('metal_mine', $catalog->column(1));
        $this->assertTrue($catalog->isBuilding(1));
        $this->assertTrue($catalog->isFleet(202));
        $this->assertTrue($catalog->isHangarUnit(401));
        $this->assertTrue($catalog->isMissile(502));
        $this->assertSame(2000, $catalog->get(202)->cost->metal);
        $this->assertSame('metal', $catalog->column(RESOURCE_METAL));
    }

    public function testUtilityAccessorsShareOneCatalogAndSnapshotCurrentRows(): void
    {
        [$user, $planet] = $this->createPlayer([], ['metal' => 12.5]);
        $this->state->USER = $user;
        $this->state->PLANET = $planet;

        $this->assertSame($this->elements(), $this->elements());
        $this->assertSame((int) $user['id'], $this->player()->id);
        $this->assertSame(12.5, $this->planet()->metal);

        // the snapshot follows in-place writes of the state row
        $this->state->PLANET['metal'] = 20;
        $this->assertSame(20.0, $this->planet()->metal);

        // raw DB strings are typed by the snapshot factory
        $this->assertSame(5, $this->models()->user(['id' => '5', 'username' => 'neo'])->id);
        $this->assertSame(12.5, $this->models()->planet(['id' => '9', 'metal' => '12.5'])->metal);
    }

    public function testWholeTablesKeepShapeAndOrderOfTheArrays(): void
    {
        $catalog = $this->catalog();
        $state = $this->raw();

        $this->assertSame($state['resource'], $catalog->columnMap());
        $this->assertSame(array_keys($state['resource']), array_keys($catalog->columnMap()));
        $this->assertEquals($state['requeriments'], $catalog->requirementMap());
        $this->assertSame(array_keys($state['requeriments']), array_keys($catalog->requirementMap()));
        $this->assertEquals(array_map('array_values', $state['reslist']['allow']), $catalog->allowedMap());
        $this->assertEquals(array_map('array_values', $state['reslist']['ship_type']), $catalog->shipTypes());
        $this->assertEquals(array_map('array_values', $state['reslist']['resstype']), $catalog->resourceTypes());
        foreach (ElementCatalog::GROUPS as $group) {
            $this->assertEquals(array_values($state['reslist'][$group]), $catalog->groupMap()[$group]);
        }
        $this->assertSame(['metal', 'crystal'], $catalog->columns([RESOURCE_METAL, RESOURCE_CRYSTAL]));
        $this->assertSame(RESOURCE_DEUT, $catalog->idOfColumn('deuterium'));
        $this->assertNull($catalog->idOfColumn('no_such_column'));
        $this->assertNull($catalog->find(999999));
        $this->assertSame([], $catalog->requirements(999999));
        foreach ($state['resource'] as $id => $column) {
            foreach ([1, 3] as $type) {
                $this->assertSame(in_array($id, $state['reslist']['allow'][$type]), $catalog->isAllowedOn($id, $type));
            }
            foreach ($state['reslist']['ship_type'] as $type => $ids) {
                $this->assertSame(isset($ids[$id]), $catalog->isShipType($id, (string) $type));
            }
        }
        $this->assertTrue($catalog->isResource(RESOURCE_ENERGY));
        $this->assertFalse($catalog->isResource(1));
        foreach ($state['resource'] as $id => $column) {
            $this->assertSame($id, array_search($column, $state['resource'], true));
            $this->assertSame($id, $catalog->idOfColumn($column));
        }
    }

    public function testShortcutsAndHelpersMatchTheArrays(): void
    {
        $catalog = $this->catalog();
        $state = $this->raw();

        foreach ($state['resource'] as $id => $column) {
            $price = $state['pricelist'][$id] ?? [];
            $caps = $state['CombatCaps'][$id] ?? [];
            $grid = $state['ProdGrid'][$id] ?? [];

            $this->assertSame($catalog->get($id)->cost, $catalog->cost($id));
            $this->assertSame($catalog->get($id)->combat, $catalog->combat($id));
            $this->assertEquals((float) ($price['factor'] ?? 0), $catalog->factor($id));
            $this->assertEquals((float) ($price['capacity'] ?? 0), $catalog->capacity($id));
            $this->assertSame(isset($price['max']) ? (int) $price['max'] : null, $catalog->maxLevel($id));

            $costArray = $catalog->cost($id)->toArray();
            $this->assertSame([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT, RESOURCE_ENERGY], array_keys($costArray));
            foreach ($costArray as $resId => $amount) {
                $this->assertEquals((float) ($price['cost'][$resId] ?? 0), $amount);
            }
            $this->assertEquals(
                (float) ($price['cost'][901] ?? 0) + (float) ($price['cost'][902] ?? 0),
                $catalog->cost($id)->metalCrystal(),
            );
            $this->assertEquals(
                (float) ($price['cost'][901] ?? 0) + (float) ($price['cost'][902] ?? 0) + (float) ($price['cost'][903] ?? 0),
                $catalog->cost($id)->metalCrystalDeuterium(),
            );

            // AllianceDepot::baseConsumption(): first non-empty consumption1..3
            $expected = 0.0;
            foreach (['consumption1', 'consumption2', 'consumption3'] as $key) {
                if (!empty($price[$key])) {
                    $expected = (float) $price[$key];
                    break;
                }
            }
            $this->assertEquals($expected, $catalog->get($id)->baseConsumption(), "$id base consumption");

            foreach ($catalog->all() as $targetId => $_) {
                $this->assertSame((int) ($caps['sd'][$targetId] ?? 0), $catalog->combat($id)->rapidFireAgainst($targetId));
            }

            foreach (['production', 'storage'] as $kind) {
                $expectedFormulas = [];
                foreach ($grid[$kind] ?? [] as $resId => $formula) {
                    // the arrays only hold null or non-empty formulas, so isset() and "non-empty" mean the same
                    $this->assertNotSame('', $formula, "$id $kind $resId is an empty formula");
                    if ($formula !== null) {
                        $expectedFormulas[$resId] = (string) $formula;
                    }
                }
                $this->assertSame($expectedFormulas, $kind === 'production' ? $catalog->production($id) : $catalog->storage($id));
                foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT, RESOURCE_ENERGY] as $resId) {
                    $this->assertSame(
                        isset($grid[$kind][$resId]) ? (string) $grid[$kind][$resId] : null,
                        $kind === 'production' ? $catalog->productionFormula($id, $resId) : $catalog->storageFormula($id, $resId),
                    );
                }
            }
            $this->assertEquals($state['requeriments'][$id] ?? [], $catalog->requirements($id));
        }
    }

    public function testFromVarsDataEqualsTheStateBuiltCatalog(): void
    {
        $vars = (new Cache($this->db, $this->state))->getData('vars');
        $universe = (new Universe($this->state))->current();

        $this->assertEquals($this->catalog(), ElementCatalog::fromVarsData($vars, $universe));
        // an unknown universe falls back to the defaults of universe 0
        $this->assertEquals(ElementCatalog::fromVarsData($vars, 0), ElementCatalog::fromVarsData($vars, 987654));
    }
}
