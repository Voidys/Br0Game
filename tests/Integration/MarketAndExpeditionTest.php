<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\ExpeditionFindableShipsHandler;
use App\Game\Core\MarketManager;
use App\Game\Missions\Expedition\ExpeditionShipEvent;
use App\Game\Missions\MissionCaseExpedition;
use App\Tests\Support\GameTestCase;

/**
 * Pins the pure value computations of the marketplace (resource-to-deuterium conversion at the stored/clamped trade ratios,
 * reference ratios, fleet value in metal-standard units and in score points, push detection by player score) and of the
 * expedition (points and cargo room of a fleet, which ships a fleet can find, invariants of the found-ship draw).
 *
 * Why: market conversion and expedition value drive trades and rewards in the economy; they are re-implemented behind typed
 * models and a split FleetFunctions/MarketManager in the legacy renewal and must keep their numbers.
 */
final class MarketAndExpeditionTest extends GameTestCase
{
    // GOLDEN-BEGIN
    private const GOLDEN = [
        'ratios default 2/1' => ['md' => 2.0, 'cd' => 1.0],
        'ratios stored 3/2' => ['md' => 3.0, 'cd' => 2.0],
        'ratios clamped low' => ['md' => 0.5, 'cd' => 0.5],
        'ratios clamped high' => ['md' => 5.0, 'cd' => 5.0],
        'reference ratios 3/2' => ['metal' => 3.0, 'crystal' => 2.0, 'deuterium' => 1.0],
        'reference ratios 2/0.9' => ['metal' => 2.22, 'crystal' => 1.0, 'deuterium' => 1.11],
        'deut relative ratios 3/2' => ['metal' => 3.0, 'crystal' => 2.0, 'deuterium' => 1.0],
        'deut of 3000 metal' => 1000,
        'deut of mixed 1000/1000/1000' => 1834,
        'deut rounds up' => 1,
        'deut numeric keys' => 550,
        'deut of nothing' => 0,
        'deut of expedition resource type 1' => 1000,
        'deut of expedition resource type 2' => 1500,
        'deut of expedition resource type 3' => 3000,
        'fleet value in deuterium units' => 253334,
        'fleet value in score points' => 668,
        'fleet value empty' => 0,
        'push strong -> weak, fair 1000/900' => false,
        'push strong -> weak, offer far below ask 500/1000' => true,
        'push strong -> weak, ask far below offer 1000/500' => false,
        'push weak -> strong, fair 1000/900' => false,
        'push weak -> strong, ask far below offer 1000/500' => true,
        'push weak -> strong, offer far below ask 500/1000' => false,
        'fleet push, small fleet for a lot' => false,
        'expedition points/capacity' => [1400, 178000],
        'expedition points/capacity, empty hold' => [5, 5],
        'findable by small cargo' => [202, 204, 210],
        'findable by battleship + cruiser' => [202, 203, 204, 205, 206, 207, 210, 215],
        'findable by death star' => [210, 202],
        'findable by an unknown finder falls back' => [202, 210],
        'default findable config' => [202 => ['ship_id' => 202, 'is_findable' => 1, 'findable_by' => [210, 202, 204, 203, 205, 206, 207, 215, 211, 213]], 203 => ['ship_id' => 203, 'is_findable' => 1, 'findable_by' => [204, 203, 205, 206, 207, 215, 211, 213]], 204 => ['ship_id' => 204, 'is_findable' => 1, 'findable_by' => [202, 204, 203, 205, 206, 207, 215, 211, 213]], 205 => ['ship_id' => 205, 'is_findable' => 1, 'findable_by' => [203, 205, 206, 207, 215, 211, 213]], 206 => ['ship_id' => 206, 'is_findable' => 1, 'findable_by' => [205, 206, 207, 215, 211, 213]], 207 => ['ship_id' => 207, 'is_findable' => 1, 'findable_by' => [206, 207, 215, 211, 213]], 210 => ['ship_id' => 210, 'is_findable' => 1, 'findable_by' => [210, 202, 204, 203, 205, 206, 207, 215, 211, 213]], 211 => ['ship_id' => 211, 'is_findable' => 1, 'findable_by' => [215, 211, 213]], 213 => ['ship_id' => 213, 'is_findable' => 1, 'findable_by' => [211, 213]], 215 => ['ship_id' => 215, 'is_findable' => 1, 'findable_by' => [207, 215, 211, 213]]],
    ];
    // GOLDEN-END

    private function market(float $metalPerDeut = 2.0, float $crystalPerDeut = 1.0): MarketManager
    {
        $this->db->update('UPDATE %%MARKETPLACE%% SET metDeutRatio = :md, crysDeutRatio = :cd WHERE universeId = 1;', [':md' => $metalPerDeut, ':cd' => $crystalPerDeut]);

        return new MarketManager($this->db, $this->configs, $this->state);
    }

    public function testRatiosFallBackToTheStoredValuesWithoutTrades(): void
    {
        $this->goldenAssert('ratios default 2/1', $this->market()->getAllTradeRatios());
        $this->goldenAssert('ratios stored 3/2', $this->market(3.0, 2.0)->getAllTradeRatios());
        $this->goldenAssert('ratios clamped low', $this->market(0.1, 0.2)->getAllTradeRatios());
        $this->goldenAssert('ratios clamped high', $this->market(9.0, 7.5)->getAllTradeRatios());
    }

    public function testReferenceRatiosScaleTheCheapestResourceToOne(): void
    {
        $this->goldenAssert('reference ratios 3/2', $this->market(3.0, 2.0)->getReferenceRatios());
        $this->goldenAssert('reference ratios 2/0.9', $this->market(2.0, 0.9)->getReferenceRatios());
        $this->goldenAssert('deut relative ratios 3/2', $this->market(3.0, 2.0)->getDeutRelativeRatios());
    }

    public function testConvertToDeut(): void
    {
        $market = $this->market(3.0, 2.0);

        $this->goldenAssert('deut of 3000 metal', $market->convertToDeut(['metal' => 3000]));
        $this->goldenAssert('deut of mixed 1000/1000/1000', $market->convertToDeut(['metal' => 1000, 'crystal' => 1000, 'deuterium' => 1000]));
        $this->goldenAssert('deut rounds up', $market->convertToDeut(['metal' => 1, 'crystal' => 1]));
        $this->goldenAssert('deut numeric keys', $market->convertToDeutNumeric([901 => 900, 902 => 400, 903 => 50]));
        $this->goldenAssert('deut of nothing', $market->convertToDeut([]));
        foreach ([1 => 3000.0, 2 => 3000.0, 3 => 3000.0] as $type => $amount) {
            $this->goldenAssert("deut of expedition resource type $type", $market->convertExpResTypeToDeut($type, $amount));
        }
        $this->expectException(\Exception::class);
        $market->convertExpResTypeToDeut(4, 1.0);
    }

    public function testFleetValue(): void
    {
        $market = $this->market(3.0, 2.0);
        $fleet = [202 => 5, 203 => 4, 207 => 10];

        $this->goldenAssert('fleet value in deuterium units', $market->getFleetValue($fleet));
        $this->goldenAssert('fleet value in score points', $market->getFleetValue($fleet, true));
        $this->goldenAssert('fleet value empty', $market->getFleetValue([]));
    }

    public function testPushDetectionUsesPlayerScoreAndMargin(): void
    {
        $market = $this->market(3.0, 2.0);
        [$weak] = $this->createPlayer();
        [$strong] = $this->createPlayer();
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = :p WHERE id_owner = :id AND stat_type = 1;', [':p' => 100000, ':id' => $strong['id']]);
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = :p WHERE id_owner = :id AND stat_type = 1;', [':p' => 5000, ':id' => $weak['id']]);

        // seller stronger than buyer: the offered value must not be far below the asked value (selling cheap to a weak player is
        // fine); seller weaker or equal: the ask must not be far below the offer (a weak player getting too much is a push)
        $this->goldenAssert('push strong -> weak, fair 1000/900', $market->isPush(1000, 900, (int) $strong['id'], (int) $weak['id']));
        $this->goldenAssert('push strong -> weak, offer far below ask 500/1000', $market->isPush(500, 1000, (int) $strong['id'], (int) $weak['id']));
        $this->goldenAssert('push strong -> weak, ask far below offer 1000/500', $market->isPush(1000, 500, (int) $strong['id'], (int) $weak['id']));
        $this->goldenAssert('push weak -> strong, fair 1000/900', $market->isPush(1000, 900, (int) $weak['id'], (int) $strong['id']));
        $this->goldenAssert('push weak -> strong, ask far below offer 1000/500', $market->isPush(1000, 500, (int) $weak['id'], (int) $strong['id']));
        $this->goldenAssert('push weak -> strong, offer far below ask 500/1000', $market->isPush(500, 1000, (int) $weak['id'], (int) $strong['id']));
        $this->goldenAssert('fleet push, small fleet for a lot', $market->isFleetPush([202 => 10], 1, (int) $strong['id'], (int) $weak['id']));

        $this->expectException(\Exception::class);
        $market->isPush(1, 1, 99999999, (int) $strong['id']);
    }

    /** @param array<string, mixed> $fleetRow */
    private function expedition(array $fleetRow): MissionCaseExpedition
    {
        return new MissionCaseExpedition($fleetRow + ['resource_metal' => 0, 'resource_crystal' => 0, 'resource_deuterium' => 0, 'universe' => 1], $this->db, $this->configs, $this->state);
    }

    public function testExpeditionPointsAndCapacity(): void
    {
        [$user] = $this->createPlayer();
        $row = ['owner' => $user['id'], 'ships' => '202,10;203,5;207,3', 'resource_metal' => 1000, 'resource_crystal' => 500];
        $points = new \ReflectionMethod(MissionCaseExpedition::class, 'calculatePointsAndCapacity');

        $this->goldenAssert('expedition points/capacity', $points->invoke($this->expedition($row)));
        $this->goldenAssert('expedition points/capacity, empty hold', $points->invoke($this->expedition(['ships' => '210,1', 'owner' => $user['id']])));
    }

    public function testFindableShipsPerFleet(): void
    {
        $handler = new ExpeditionFindableShipsHandler($this->db, $this->configs, $this->state);

        $this->goldenAssert('findable by small cargo', $handler->findableShipsForFleet(1, [202 => 10]));
        $this->goldenAssert('findable by battleship + cruiser', $handler->findableShipsForFleet(1, [207 => 3, 206 => 2]));
        $this->goldenAssert('findable by death star', $handler->findableShipsForFleet(1, [214 => 1]));
        $this->goldenAssert('findable by an unknown finder falls back', $handler->findableShipsForFleet(1, [210 => 1]));
        $this->goldenAssert('default findable config', $handler->getConfigForUniverse(1, true));
    }

    public function testFoundShipsNeverExceedTheAvailablePoints(): void
    {
        [$user] = $this->createPlayer();
        $mission = $this->expedition(['owner' => $user['id'], 'ships' => '202,1']);
        $context = (new \ReflectionProperty(MissionCaseExpedition::class, 'ctx'))->getValue($mission);
        $mission = new ExpeditionShipEvent($this->db, $this->configs, $this->state, $context);
        $found = new \ReflectionMethod(ExpeditionShipEvent::class, 'determineFoundShips');
        $pricelist = $this->state->pricelist;

        // the draw uses random_int(): not reproducible, so it is pinned by invariants
        for ($run = 0; $run < 50; $run++) {
            $ships = $found->invoke($mission, 40, [202, 203, 204, 210]);
            $cost = 0;
            foreach ($ships as $id => $amount) {
                $this->assertContains($id, [202, 203, 204, 210]);
                $this->assertGreaterThan(0, $amount);
                $cost += $amount * ($pricelist[$id]['cost'][901] + $pricelist[$id]['cost'][902]);
            }
            $this->assertLessThanOrEqual(40, $cost, 'found ships are worth at most the expedition points');
        }
        $this->assertSame([], $found->invoke($mission, 0, [202, 203]));
        $this->assertSame([], $found->invoke($mission, 100, []));
    }
}
