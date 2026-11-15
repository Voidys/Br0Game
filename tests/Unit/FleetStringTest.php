<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\MarketManager;
use App\Game\Core\Singleton;
use App\Runtime\RuntimeContext;
use PHPUnit\Framework\TestCase;

/**
 * Pins the pure helpers that need no game data: the fleet string format ("202,10;204,5", stored in `fleets.fleet_array`
 * and sent in mission forms) in both directions, and the marketplace reference-ratio formatting.
 *
 * Why: the fleet string is a persisted wire format shared with the client and the database; the typed fleet model and the
 * FleetFunctions split must keep reading and writing exactly this.
 */
final class FleetStringTest extends TestCase
{
    private function fleets(): FleetFunctions
    {
        return new FleetFunctions(
            $this->createStub(Database::class),
            $this->createStub(ConfigRegistry::class),
            new Singleton(new RuntimeContext(dirname(__DIR__, 2))),
        );
    }

    public function testUnserialize(): void
    {
        $fleets = $this->fleets();

        $this->assertSame([202 => 10, 204 => 5], $fleets->unserialize('202,10;204,5'));
        $this->assertSame([], $fleets->unserialize(''));
        $this->assertSame([202 => 15], $fleets->unserialize('202,10;202,5'), 'duplicate ship ids add up');
        $this->assertSame([204 => 3], $fleets->unserialize(';204,3;,7'), 'empty entries are skipped');
        $this->assertEquals([207 => 1000000000000], $fleets->unserialize('207,1000000000000'));
    }

    public function testSerialize(): void
    {
        $fleets = $this->fleets();

        $this->assertSame('202,10;204,5', $fleets->serialize([202 => 10, 204 => 5]));
        $this->assertSame('', $fleets->serialize([]));
        $this->assertSame('204,5', $fleets->serialize([202 => 0, 204 => 5]), 'zero amounts are dropped');
        $this->assertSame('207,1000000000000', $fleets->serialize([207 => 1.0e12]), 'big amounts are written without exponent');
    }

    public function testRoundTrip(): void
    {
        $fleets = $this->fleets();
        $fleet = [202 => 10, 203 => 25, 214 => 1];

        $this->assertEquals($fleet, $fleets->unserialize($fleets->serialize($fleet)));
    }

    public function testReferenceRatioFormat(): void
    {
        $this->assertSame('1.00', MarketManager::formatReferenceRatio(1.0));
        $this->assertSame('1.11', MarketManager::formatReferenceRatio(1.111111));
        $this->assertSame('3.50', MarketManager::formatReferenceRatio(3.5));
        $this->assertSame('1234.57', MarketManager::formatReferenceRatio(1234.5678), 'period decimal, no thousands grouping');
        $this->assertSame('0.01', MarketManager::formatReferenceRatio(0.005));
    }
}
