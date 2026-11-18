<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\MarketManager;
use App\Game\Enum\Mission;
use App\Game\Market\MarketOfferListing;
use App\Game\Market\MarketPurchase;
use App\Tests\Support\GameTestCase;

/**
 * Marketplace offers of the fleet kind (the seller's ships against resources), the "partners only" visibility for players
 * without an alliance and the seller's flight time limit, at buy time and in the offer listing.
 */
final class MarketOfferFiltersTest extends GameTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->state->LNG->includeData(['TECH']);
    }

    /**
     * Seller fleet holding at the market with its trade row. A resource offer (kind 0) sells 1000 metal for 900 metal, a
     * fleet offer (kind 1) sells 5 light fighters for 7500 deuterium.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: int} seller, buyer, buyer planet, offer fleet id
     */
    private function offer(int $kind = 0, int $visibility = 0, int $flightTime = 0): array
    {
        [$seller, $sellerPlanet] = $this->createPlayer(['computer_tech' => 5]);
        [$buyer, $buyerPlanet] = $this->createPlayer(['computer_tech' => 5], ['metal' => 1e7, 'crystal' => 1e7, 'deuterium' => 1e7, 'small_ship_cargo' => 100, 'big_ship_cargo' => 20]);
        $now = time();
        $ships = $kind === 1 ? '204,5' : '202,5';
        $this->db->insert(
            "INSERT INTO %%FLEETS%% (universe, mission, owner, target_owner, ship_amount, ships, start_ships, start_time, arrival_time, stay_time, return_time, start_id, end_id, start_galaxy, start_system, start_planet, start_type, end_galaxy, end_system, end_planet, end_type, resource_metal, status, cannotCancel) VALUES (1, :m, :o, 0, 5, :ships, :ships, :t, :t, :stay, :ret, :p, 0, :g, :s, :pl, 1, :g, :s, :mp, 1, :res, :st, 1);",
            [':m' => Mission::Trade->value, ':o' => $seller['id'], ':ships' => $ships, ':t' => $now - 100, ':stay' => $now + 20000, ':ret' => $now + 40000, ':p' => $sellerPlanet['id'], ':g' => $sellerPlanet['galaxy'], ':s' => $sellerPlanet['system'], ':pl' => $sellerPlanet['planet'], ':mp' => $this->configs->get(1)->max_planets + 2, ':st' => FLEET_HOLD, ':res' => $kind === 1 ? 0 : 1000],
        );
        $fleetId = $this->db->lastInsertId();
        $this->db->insert(
            'INSERT INTO %%TRADES%% SET transaction_type = :k, seller_fleet_id = :f, filter_visibility = :v, filter_flighttime = :ft, ex_resource_type = :et, ex_resource_amount = :w, resource_type = :rt, resource_amount = :ra, marketplace_galaxy = :g, marketplace_system = :s;',
            [':k' => $kind, ':f' => $fleetId, ':v' => $visibility, ':ft' => $flightTime, ':et' => $kind === 1 ? 3 : 1, ':w' => $kind === 1 ? 7500 : 900, ':rt' => $kind === 1 ? 0 : 1, ':ra' => $kind === 1 ? 0 : 1000, ':g' => $sellerPlanet['galaxy'], ':s' => $sellerPlanet['system']],
        );

        return [$seller, $buyer, $buyerPlanet, $fleetId];
    }

    /**
     * @param array<mixed> $buyer
     * @param array<string, mixed> $planet
     */
    private function buy(array $buyer, array $planet, int $fleetId): string
    {
        $this->state->USER = $buyer;
        $this->state->PLANET = $planet;
        $this->state->requestInput = ['fleetID' => $fleetId, 'shipType' => 1];

        return (new MarketPurchase($this->db, $this->configs, $this->state))->buy($fleetId, '1');
    }

    public function testAFleetOfferCanBeBoughtAndTheBuyerFleetCarriesThePayment(): void
    {
        [$seller, $buyer, $planet, $fleetId] = $this->offer(1);
        // ask the value of the ships, so the push rule lets the deal pass
        $ask = (new MarketManager($this->db, $this->configs, $this->state))->getFleetValue([204 => 5]);
        $this->db->update('UPDATE %%TRADES%% SET ex_resource_amount = :a WHERE seller_fleet_id = :f;', [':a' => $ask, ':f' => $fleetId]);
        $cargos = (int) ceil($ask / 5000);

        $this->buy($buyer, $planet, $fleetId);
        $trade = $this->db->selectSingle('SELECT * FROM %%TRADES%% WHERE seller_fleet_id = :id;', [':id' => $fleetId]);
        $this->assertNotNull($trade['buyer_fleet_id']);
        $buyerFleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $trade['buyer_fleet_id']]);
        $this->assertSame(Mission::Transport->value, (int) $buyerFleet['mission']);
        $this->assertSame((int) $planet['id'], (int) $buyerFleet['start_id']);
        $this->assertSame((int) $seller['id_planet'], (int) $buyerFleet['end_id'], 'the payment is delivered to the seller planet');
        $this->assertEquals($ask, $buyerFleet['resource_deuterium']);
        $this->assertSame('202,' . $cargos, $buyerFleet['ships']);
        $offerFleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);
        $this->assertSame(Mission::Transfer->value, (int) $offerFleet['mission'], 'the sold ships are transferred to the buyer');
        $this->assertSame((int) $buyer['id'], (int) $offerFleet['target_owner']);
        $this->assertSame((int) $planet['id'], (int) $offerFleet['end_id']);
        $this->assertSame(FLEET_OUTWARD, (int) $offerFleet['status']);
        $this->assertSame('204,5', $offerFleet['ships']);
        $this->assertGreaterThan($ask, 1e7 - $this->state->PLANET['deuterium'], 'the payment and the fuel of both fleets are paid by the buyer');
    }

    public function testAResourceOfferIsStillSizedForTheLargerOfOfferAndAsk(): void
    {
        [, $buyer, $planet, $fleetId] = $this->offer();
        // ask 900 metal, offer 900000 metal (from the stronger player, so no push): the buyer fleet has to be able to carry the offer
        $this->db->update('UPDATE %%TRADES%% SET resource_amount = 900000 WHERE seller_fleet_id = :f;', [':f' => $fleetId]);
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 200000 WHERE stat_type = 1 AND id_owner = (SELECT owner FROM %%FLEETS%% WHERE id = :f);', [':f' => $fleetId]);
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 100000 WHERE stat_type = 1 AND id_owner = :b;', [':b' => $buyer['id']]);

        $this->buy($buyer, $planet, $fleetId);

        $trade = $this->db->selectSingle('SELECT buyer_fleet_id FROM %%TRADES%% WHERE seller_fleet_id = :id;', [':id' => $fleetId]);
        $buyerFleet = $this->db->selectSingle('SELECT ships FROM %%FLEETS%% WHERE id = :id;', [':id' => $trade['buyer_fleet_id']]);
        $this->assertSame('202,100;203,16', $buyerFleet['ships'], '100 small cargos (500000) and 16 large cargos for the larger offered amount');
    }

    public function testPartnerOnlyOffersAreRefusedForStrangersWithoutAnAlliance(): void
    {
        [$seller, $buyer, $planet, $fleetId] = $this->offer(0, 1);
        $LNG = $this->state->LNG;

        // neither has an alliance (id 0): that is not one shared alliance
        $this->assertSame($LNG['market_buyable_only_trade_partners'], $this->buy($buyer, $planet, $fleetId));
        $this->assertNull($this->db->selectSingle('SELECT buyer_fleet_id FROM %%TRADES%% WHERE seller_fleet_id = :id;', [':id' => $fleetId], 'buyer_fleet_id'));

        // a buddy may buy
        $this->db->insert('INSERT INTO %%BUDDY%% (sender, owner, universe) VALUES (:s, :o, 1);', [':s' => $buyer['id'], ':o' => $seller['id']]);
        $this->assertSame(sprintf($LNG['market_p_msg_sent'], 5, 0), $this->buy($buyer, $planet, $fleetId));
    }

    public function testPartnerOnlyOffersAreBuyableByMembersOfTheSameAlliance(): void
    {
        [$seller, $buyer, $planet, $fleetId] = $this->offer(0, 1);
        $this->db->update('UPDATE %%USERS%% SET ally_id = 777 WHERE id IN (:a, :b);', [':a' => $seller['id'], ':b' => $buyer['id']]);
        $buyer['ally_id'] = 777;

        $this->assertSame(sprintf($this->state->LNG['market_p_msg_sent'], 5, 0), $this->buy($buyer, $planet, $fleetId));
    }

    public function testTheSellersFlightTimeLimitIsEnforcedAtBuyTime(): void
    {
        [, $buyer, $planet, $fleetId] = $this->offer(0, 0, 1);

        $this->assertSame($this->state->LNG['market_p_msg_too_far'], $this->buy($buyer, $planet, $fleetId));
        $this->assertNull($this->db->selectSingle('SELECT buyer_fleet_id FROM %%TRADES%% WHERE seller_fleet_id = :id;', [':id' => $fleetId], 'buyer_fleet_id'));
        $this->assertSame(FLEET_HOLD, (int) $this->db->selectSingle('SELECT status FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId], 'status'));
        $this->assertEquals(1e7, $this->db->selectSingle('SELECT metal FROM %%PLANETS%% WHERE id = :id;', [':id' => $planet['id']], 'metal'), 'a refused purchase costs nothing');

        // a generous limit and no limit (0) both allow the purchase
        $this->db->update('UPDATE %%TRADES%% SET filter_flighttime = 7 * 24 * 3600 WHERE seller_fleet_id = :f;', [':f' => $fleetId]);
        $this->assertSame(sprintf($this->state->LNG['market_p_msg_sent'], 5, 0), $this->buy($buyer, $planet, $fleetId));
    }

    public function testNoFlightTimeLimitMeansUnlimited(): void
    {
        [, $buyer, $planet, $fleetId] = $this->offer(0, 0, 0);

        $this->assertSame(sprintf($this->state->LNG['market_p_msg_sent'], 5, 0), $this->buy($buyer, $planet, $fleetId));
    }

    public function testTheListingMarksOffersBeyondTheFlightTimeLimit(): void
    {
        [, $buyer, $planet, $fleetId] = $this->offer(0, 0, 1);
        $this->state->USER = $buyer;
        $this->state->PLANET = $planet;

        $rows = (new MarketOfferListing($this->db, $this->configs, $this->state))->offers();
        $row = array_values(array_filter($rows, static fn(array $r): bool => (int) $r['id'] === $fleetId))[0];

        $this->assertFalse($row['possible_to_buy']);
        $this->assertSame($this->state->LNG['market_buyable_too_far'], $row['reason']);
    }
}
