<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Game\Core\StoredDataMigrator;
use PHPUnit\Framework\TestCase;

/**
 * The per-value conversion rules of the serialize() to JSON migration: the result must decode to exactly the legacy
 * structure, and anything unsafe (damaged, objects, too long) is refused so the row stays as it was.
 */
final class StoredDataMigratorTest extends TestCase
{
    private function migrator(): StoredDataMigrator
    {
        return new StoredDataMigrator($this->createStub(\PDO::class), 'uni1_');
    }

    public function testConvertsAQueueKeepingTypesAndKeys(): void
    {
        $queue = [[1, 5, 182.0, 1794778000.0, 'build', [901 => 303.75, 902 => 75.9375]], [2, 1, 60, 1794778100, 'destroy']];

        $json = $this->migrator()->convertValue(serialize($queue));

        $this->assertSame('[[1,5,182.0,1794778000.0,"build",{"901":303.75,"902":75.9375}],[2,1,60,1794778100,"destroy"]]', $json);
        $this->assertSame($queue, json_decode($json, true));
    }

    public function testConvertsAnEmptyArray(): void
    {
        $this->assertSame('[]', $this->migrator()->convertValue('a:0:{}'));
    }

    public function testRefusesObjectsDamagedValuesAndOversizedResults(): void
    {
        $this->assertNull($this->migrator()->convertValue('a:1:{i:0;O:8:"stdClass":0:{}}'));
        $this->assertNull($this->migrator()->convertValue('a:2:{i:0;i:1;'));
        $this->assertNull($this->migrator()->convertValue(serialize([str_repeat('x', 100)]), 50));
    }

    public function testLegacyValueIsRecognisedJsonIsNot(): void
    {
        $this->assertTrue(\App\Game\Core\StoredData::isLegacySerialized('a:0:{}'));
        $this->assertFalse(\App\Game\Core\StoredData::isLegacySerialized('[]'));
    }
}
