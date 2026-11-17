<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\MarketManager;
use App\Tests\Support\GameTestCase;
use App\Game\Enum\Mission;

/**
 * F-19: the market reference ratio weighs every pair of traders with at most the volume of all other pairs, so wash
 * trades between two accounts cannot steer it, while independent traders still set it.
 */
final class MarketRatioPairCapTest extends GameTestCase
{
    private function market(): MarketManager
    {
        $this->db->update('UPDATE %%MARKETPLACE%% SET metDeutRatio = 2, crysDeutRatio = 1 WHERE universeId = 1;');
        $this->db->delete('DELETE FROM %%TRADES%%;');

        return new MarketManager($this->db, $this->configs, $this->state);
    }

    /** One finished trade: $seller offered $metal metal for $deuterium deuterium, bought by $buyer. */
    private function trade(int $seller, int $buyer, float $metal, float $deuterium): void
    {
        $fleetIds = [];
        foreach ([[$seller, $metal], [$buyer, 0.0]] as [$owner, $cargo]) {
            $id = mt_rand(1000000, 99999999);
            $this->db->insert(
                'INSERT INTO %%LOG_FLEETS%% SET id = :id, `group` = 0, universe = 1, mission = :m, owner = :o, target_owner = 0, start_id = 0, end_id = 0, ships = \'203,1\', arrival_time = 0, hasCanceled = 0, resource_metal = :metal, resource_crystal = 0, resource_deuterium = 0;',
                [':id' => $id, ':m' => Mission::Trade->value, ':o' => $owner, ':metal' => $cargo],
            );
            $fleetIds[] = $id;
        }
        $this->db->insert(
            'INSERT INTO %%TRADES%% SET seller_fleet_id = :s, buyer_fleet_id = :b, buy_time = NOW(), transaction_type = 0, filter_visibility = 0, filter_flighttime = 0, ex_resource_type = 3, ex_resource_amount = :d, resource_type = 1, resource_amount = :m, marketplace_galaxy = 1, marketplace_system = 1;',
            [':s' => $fleetIds[0], ':b' => $fleetIds[1], ':d' => $deuterium, ':m' => $metal],
        );
    }

    public function testIndependentTradersSetTheRatio(): void
    {
        $market = $this->market();
        foreach ([[11, 12], [13, 14], [15, 16]] as [$seller, $buyer]) {
            $this->trade($seller, $buyer, 30000, 10000);
        }

        $this->assertEqualsWithDelta(3.0, $market->getAllTradeRatios()['md'], 0.01);
    }

    public function testOnePairOfWashTradersCannotDominate(): void
    {
        $market = $this->market();
        // two honest pairs at 3:1, one colluding pair with a huge volume at the 5:1 cap
        $this->trade(11, 12, 30000, 10000);
        $this->trade(13, 14, 30000, 10000);
        $this->trade(21, 22, 5000000, 1000000);
        $this->trade(22, 21, 5000000, 1000000);

        $ratio = $market->getAllTradeRatios()['md'];
        // plain volume weighting would give ~4.97; capped, the pair carries at most half of the weight
        $this->assertLessThanOrEqual(4.0 + 0.01, $ratio);
        $this->assertGreaterThanOrEqual(3.0 - 0.01, $ratio);
    }

    public function testALonePairDoesNotMoveTheStoredRatio(): void
    {
        $market = $this->market();
        $this->trade(21, 22, 5000000, 1000000);

        $this->assertEqualsWithDelta(2.0, $market->getAllTradeRatios()['md'], 0.01);
    }
}
