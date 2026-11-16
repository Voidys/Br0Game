<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Game\Model\Planet;
use PHPUnit\Framework\TestCase;

/**
 * The typed Planet snapshot: field mapping and casts, building level / ship count accessors with 0 defaults,
 * production percentages, research network list, type helpers.
 */
final class PlanetTest extends TestCase
{
    private const RESOURCE = [1 => 'metal_mine', 31 => 'laboratory', 202 => 'small_ship_cargo', 901 => 'metal', 911 => 'energy'];

    public function testMapsAndCastsTheStateArray(): void
    {
        $planet = Planet::fromState([
            'id' => '5',
            'id_owner' => '7',
            'name' => 'Home',
            'universe' => '1',
            'galaxy' => '1',
            'system' => '2',
            'planet' => '3',
            'planet_type' => '1',
            'field_current' => '10',
            'field_max' => '163',
            'temp_min' => '-10',
            'temp_max' => '30',
            'metal' => '1500.5',
            'metal_perhour' => '60.25',
            'metal_max' => '10000',
            'metal_bonus_percent' => '12.5',
            'energy' => '40',
            'energy_used' => '-10',
            'b_building' => '99',
            'b_building_id' => '[[1,2]]',
            'b_hangar_id' => null,
            'metal_mine' => '12',
            'metal_mine_porcent' => '10',
            'small_ship_cargo' => '9000000000',
        ], self::RESOURCE);

        $this->assertSame(5, $planet->id);
        $this->assertSame(7, $planet->ownerId);
        $this->assertSame('Home', $planet->name);
        $this->assertSame([1, 2, 3], [$planet->galaxy, $planet->system, $planet->position]);
        $this->assertSame(Planet::TYPE_PLANET, $planet->type);
        $this->assertFalse($planet->isMoon());
        $this->assertSame(163, $planet->fieldsMax);
        $this->assertSame(-10, $planet->tempMin);
        $this->assertSame(1500.5, $planet->metal);
        $this->assertSame(60.25, $planet->metalPerHour);
        $this->assertSame(10000.0, $planet->metalMax);
        $this->assertSame(12.5, $planet->metalBonusPercent);
        $this->assertSame(-10.0, $planet->energyUsed);
        $this->assertSame(99, $planet->buildingEnd);
        $this->assertSame('[[1,2]]', $planet->buildingQueue);
        $this->assertSame('', $planet->hangarQueue, 'NULL queue reads as an empty string');
        $this->assertSame(12, $planet->level(1));
        $this->assertSame(10, $planet->productionRate(1));
        $this->assertSame(9000000000.0, $planet->count(202));
    }

    public function testMissingKeysDefaultToZero(): void
    {
        $planet = Planet::fromState(['id' => 1, 'metal_mine' => 3], self::RESOURCE);

        $this->assertTrue($planet->has(1));
        $this->assertFalse($planet->has(202));
        $this->assertSame(0, $planet->level(31));
        $this->assertSame(0.0, $planet->count(202));
        $this->assertSame(0, $planet->productionRate(1));
        $this->assertSame(0.0, $planet->metal);
        $this->assertSame('', $planet->name);
        $this->assertSame(0, $planet->level(12345));
    }

    public function testMoonTypeAndResearchNetwork(): void
    {
        $moon = Planet::fromState(['planet_type' => 3, 'laboratory' => 4, 'laboratory_inter' => ['4', 7, 2]], self::RESOURCE);

        $this->assertTrue($moon->isMoon());
        $this->assertSame([4, 7, 2], $moon->labNetwork);

        // a plain number (or nothing) means: only the own laboratory counts
        $this->assertNull(Planet::fromState(['laboratory' => 4, 'laboratory_inter' => 4], self::RESOURCE)->labNetwork);
        $this->assertNull(Planet::fromState(['laboratory' => 4], self::RESOURCE)->labNetwork);
    }

    public function testIsASnapshot(): void
    {
        $row = ['metal_mine' => 1, 'metal' => 5];
        $planet = Planet::fromState($row, self::RESOURCE);
        $row['metal_mine'] = 9;
        $row['metal'] = 99;

        $this->assertSame(1, $planet->level(1));
        $this->assertSame(5.0, $planet->metal);
    }
}
