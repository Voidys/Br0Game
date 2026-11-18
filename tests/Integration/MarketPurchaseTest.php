<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Market\MarketPurchase;
use App\Tests\Support\GameTestCase;
use App\Game\Enum\Mission;

/**
 * Buys marketplace offers end to end on the test database: a resource offer (seller fleet holding at the market + trade
 * row) is bought by another player; checks the paid resources, the buyer's fleet, the re-routing of the seller's fleet,
 * the trade row link and the two messages, plus the refusals (own offer, push prevention, not enough ships, unknown offer).
 */
final class MarketPurchaseTest extends GameTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // the market pages run with the full language; the resource names in the trade messages come from the tech domain
        $this->state->LNG->includeData(['TECH']);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: int} seller, buyer, buyer planet, offer fleet id */
    private function offer(float $wantedMetal = 900): array
    {
        [$seller, $sellerPlanet] = $this->createPlayer(['computer_tech' => 5]);
        [$buyer, $buyerPlanet] = $this->createPlayer(['computer_tech' => 5], ['metal' => 1e7, 'crystal' => 1e7, 'deuterium' => 1e7, 'small_ship_cargo' => 100, 'big_ship_cargo' => 20]);
        $now = time();
        $this->db->insert(
            "INSERT INTO %%FLEETS%% (universe, mission, owner, target_owner, ship_amount, ships, start_ships, start_time, arrival_time, stay_time, return_time, start_id, end_id, start_galaxy, start_system, start_planet, start_type, end_galaxy, end_system, end_planet, end_type, resource_metal, status, cannotCancel) VALUES (1, :m, :o, 0, 5, '202,5', '202,5', :t, :t, :stay, :ret, :p, 0, :g, :s, :pl, 1, :g, :s, :mp, 1, 1000, :st, 1);",
            [':m' => Mission::Trade->value, ':o' => $seller['id'], ':t' => $now - 100, ':stay' => $now + 20000, ':ret' => $now + 40000, ':p' => $sellerPlanet['id'], ':g' => $sellerPlanet['galaxy'], ':s' => $sellerPlanet['system'], ':pl' => $sellerPlanet['planet'], ':mp' => $this->configs->get(1)->max_planets + 2, ':st' => FLEET_HOLD],
        );
        $fleetId = $this->db->lastInsertId();
        $this->db->insert(
            'INSERT INTO %%TRADES%% SET transaction_type = 0, seller_fleet_id = :f, filter_visibility = 0, filter_flighttime = 0, ex_resource_type = 1, ex_resource_amount = :w, resource_type = 1, resource_amount = 1000, marketplace_galaxy = :g, marketplace_system = :s;',
            [':f' => $fleetId, ':w' => $wantedMetal, ':g' => $sellerPlanet['galaxy'], ':s' => $sellerPlanet['system']],
        );

        return [$seller, $buyer, $buyerPlanet, $fleetId];
    }

    /**
     * @param array<mixed> $buyer
     * @param array<string, mixed> $planet
     */
    private function buy(array $buyer, array $planet, int $fleetId, int $shipType = 1): string
    {
        $this->state->USER = $buyer;
        $this->state->PLANET = $planet;
        $this->state->requestInput = ['fleetID' => $fleetId, 'shipType' => $shipType];

        return (new MarketPurchase($this->db, $this->configs, $this->state))->buy($fleetId, (string) $shipType);
    }

    public function testBuyingAnOfferPaysSendsAFleetAndReroutesTheSellerFleet(): void
    {
        [$seller, $buyer, $planet, $fleetId] = $this->offer();

        $message = $this->buy($buyer, $planet, $fleetId);

        $this->assertSame(sprintf($this->state->LNG['market_p_msg_sent'], 1, 0), $message);
        $this->assertEquals(1e7 - 900, $this->state->PLANET['metal'], 'the wanted metal is deducted');
        $offerFleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);
        $this->assertSame(FLEET_OUTWARD, (int) $offerFleet['status']);
        $this->assertSame((int) $buyer['id'], (int) $offerFleet['target_owner']);
        $this->assertEquals(0, $offerFleet['resource_metal'], 'the offered cargo travels with the buyer fleet, not the seller fleet');
        $trade = $this->db->selectSingle('SELECT * FROM %%TRADES%% WHERE seller_fleet_id = :id;', [':id' => $fleetId]);
        $buyerFleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $trade['buyer_fleet_id']]);
        $this->assertSame((int) $buyer['id'], (int) $buyerFleet['owner']);
        $this->assertEquals(900, $buyerFleet['resource_metal']);
        $this->assertSame(Mission::Trade->value, (int) $buyerFleet['mission']);
        $messages = (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS_TO_MESSAGES%% WHERE user_id IN (:a, :b);', [':a' => $seller['id'], ':b' => $buyer['id']], 'c');
        $this->assertGreaterThanOrEqual(2, $messages);
    }

    public function testRefusals(): void
    {
        [$seller, $buyer, $planet, $fleetId] = $this->offer();
        $LNG = $this->state->LNG;

        $this->assertSame($LNG['market_p_msg_not_found'], $this->buy($buyer, $planet, $fleetId + 999));
        // the seller cannot buy their own offer
        [$sellerRow, $sellerPlanet] = [$this->loadUser((int) $seller['id']), $this->loadPlanet((int) $seller['id_planet'])];
        $this->assertSame($LNG['market_p_msg_own_offer'], $this->buy($sellerRow, $sellerPlanet, $fleetId));
        // push prevention: weak seller, strong buyer, the ask is far below the offer
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = :p WHERE id_owner = :id AND stat_type = 1;', [':p' => 5000, ':id' => $seller['id']]);
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = :p WHERE id_owner = :id AND stat_type = 1;', [':p' => 100000, ':id' => $buyer['id']]);
        $this->db->update('UPDATE %%TRADES%% SET ex_resource_amount = 1 WHERE seller_fleet_id = :f;', [':f' => $fleetId]);
        $this->assertSame($LNG['market_buyable_no_tech'], $this->buy($buyer, $planet, $fleetId));
        $this->assertSame(FLEET_HOLD, (int) $this->db->selectSingle('SELECT status FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId], 'status'));
    }

    public function testNotEnoughShipsIsRefused(): void
    {
        [, $buyer, $planet, $fleetId] = $this->offer(1e9);

        $this->assertSame($this->state->LNG['market_p_msg_more_ships_is_needed'], $this->buy($buyer, $planet, $fleetId));
    }

    public function testASoldOfferCannotBeBoughtAgainWhileItsFleetHoldsAtTheMarket(): void
    {
        [, $buyer, $planet, $fleetId] = $this->offer();
        $this->buy($buyer, $planet, $fleetId);
        // the seller fleet has arrived at the market again and holds with the payment (finished trade)
        $this->db->update('UPDATE %%FLEETS%% SET status = :s WHERE id = :id;', [':s' => FLEET_HOLD, ':id' => $fleetId]);
        [, $second, $secondPlanet] = [null, ...array_slice($this->createPlayer(['computer_tech' => 5], ['metal' => 1e7, 'crystal' => 1e7, 'deuterium' => 1e7, 'small_ship_cargo' => 100, 'big_ship_cargo' => 20]), 0, 2)];
        $linked = $this->db->selectSingle('SELECT buyer_fleet_id FROM %%TRADES%% WHERE seller_fleet_id = :id;', [':id' => $fleetId], 'buyer_fleet_id');

        $this->assertSame($this->state->LNG['market_p_msg_not_found'], $this->buy($second, $secondPlanet, $fleetId));
        $this->assertSame($linked, $this->db->selectSingle('SELECT buyer_fleet_id FROM %%TRADES%% WHERE seller_fleet_id = :id;', [':id' => $fleetId], 'buyer_fleet_id'), 'the trade stays linked to the first buyer');
    }

    public function testAnOfferWhoseSellerPlanetIsGoneOrDestroyedIsRefused(): void
    {
        [, $buyer, $planet, $fleetId] = $this->offer();
        $startId = (int) $this->db->selectSingle('SELECT start_id FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId], 'start_id');
        $this->db->update('UPDATE %%PLANETS%% SET destruyed = :t WHERE id = :id;', [':t' => time() + 3600, ':id' => $startId]);

        $this->assertSame($this->state->LNG['market_p_msg_not_found'], $this->buy($buyer, $planet, $fleetId));
        $this->assertNull($this->db->selectSingle('SELECT buyer_fleet_id FROM %%TRADES%% WHERE seller_fleet_id = :id;', [':id' => $fleetId], 'buyer_fleet_id'));

        $this->db->update('UPDATE %%FLEETS%% SET start_id = 99999999 WHERE id = :id;', [':id' => $fleetId]);
        $this->assertSame($this->state->LNG['market_p_msg_not_found'], $this->buy($buyer, $planet, $fleetId));
    }

    public function testAnOfferWhoseStayIsOverCannotBeBought(): void
    {
        [, $buyer, $planet, $fleetId] = $this->offer();
        $this->db->update('UPDATE %%FLEETS%% SET stay_time = :t WHERE id = :id;', [':t' => $this->state->runtime->timestamp - 5, ':id' => $fleetId]);

        $this->assertSame($this->state->LNG['market_p_msg_not_found'], $this->buy($buyer, $planet, $fleetId));
    }

    public function testATradeBlockRefusesPublicOffersInBothDirections(): void
    {
        [$seller, $buyer, $planet, $fleetId] = $this->offer();
        $blocked = $this->state->LNG['market_buyable_no_enemies'];

        // the seller blocked the buyer
        $this->db->insert('INSERT INTO %%USERS_BLOCKLIST%% (blocked_user, blocking_user, block_dm, block_trade) VALUES (:b, :s, 0, 1);', [':b' => $buyer['id'], ':s' => $seller['id']]);
        $this->assertSame($blocked, $this->buy($buyer, $planet, $fleetId));
        $this->db->delete('DELETE FROM %%USERS_BLOCKLIST%%;');

        // the buyer blocked the seller
        $this->db->insert('INSERT INTO %%USERS_BLOCKLIST%% (blocked_user, blocking_user, block_dm, block_trade) VALUES (:b, :s, 0, 1);', [':b' => $seller['id'], ':s' => $buyer['id']]);
        $this->assertSame($blocked, $this->buy($buyer, $planet, $fleetId));
        $this->assertSame(FLEET_HOLD, (int) $this->db->selectSingle('SELECT status FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId], 'status'));

        // without a block the public offer is bought
        $this->db->delete('DELETE FROM %%USERS_BLOCKLIST%%;');
        $this->assertSame(sprintf($this->state->LNG['market_p_msg_sent'], 1, 0), $this->buy($buyer, $planet, $fleetId));
    }

    public function testTheSlotCheckRunsOnTheLockedBuyerNotOnTheStaleCopy(): void
    {
        [, $buyer, $planet, $first] = $this->offer();
        [, , , $second] = $this->offer();
        // no fleet slot beyond the first: the stale copy of the user still says computer_tech 5
        $this->db->update('UPDATE %%USERS%% SET computer_tech = 0 WHERE id = :id;', [':id' => $buyer['id']]);

        $this->assertSame(sprintf($this->state->LNG['market_p_msg_sent'], 1, 0), $this->buy($buyer, $planet, $first));
        // the second purchase starts from the stale arrays again and must still be refused (slot used by the first)
        $this->assertSame($this->state->LNG['fl_no_slots'], $this->buy($buyer, $planet, $second));
        $this->assertSame(FLEET_HOLD, (int) $this->db->selectSingle('SELECT status FROM %%FLEETS%% WHERE id = :id;', [':id' => $second], 'status'));
    }
}
