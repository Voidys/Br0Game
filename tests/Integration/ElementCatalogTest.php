<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\GameUtilities;
use App\Game\Model\ElementCatalog;
use App\Tests\Support\GameTestCase;

/**
 * The typed element catalog must say exactly what the untyped game data arrays of the state say, for every element
 * of the loaded game data (columns, groups, costs, speeds, combat values, formulas, requirements).
 */
final class ElementCatalogTest extends GameTestCase
{
    use GameUtilities;

    private function catalog(): ElementCatalog
    {
        return ElementCatalog::fromState($this->state);
    }

    public function testEveryElementMatchesTheArrays(): void
    {
        $catalog = $this->catalog();
        $resource = $this->state->resource;
        $pricelist = $this->state->pricelist;
        $caps = $this->state->CombatCaps;
        $grid = $this->state->ProdGrid;
        $requirements = $this->state->requeriments;
        $reslist = $this->state->reslist;

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
        $reslist = $this->state->reslist;

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
        $this->assertSame(2000.0, $catalog->get(202)->cost->metal);
        $this->assertSame('metal', $catalog->column(RESOURCE_METAL));
    }

    public function testUtilityAccessorsShareOneCatalogAndSnapshotCurrentRows(): void
    {
        $this->state->USER = ['id' => '5', 'username' => 'neo'];
        $this->state->PLANET = ['id' => '9', 'metal' => '12.5'];

        $this->assertSame($this->elements(), $this->elements());
        $this->assertSame(5, $this->player()->id);
        $this->assertSame(12.5, $this->planet()->metal);

        $this->state->PLANET['metal'] = '20';
        $this->assertSame(20.0, $this->planet()->metal);
    }
}
