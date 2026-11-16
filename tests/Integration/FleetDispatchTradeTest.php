<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\Language;
use App\Game\Core\SessionManager;
use App\Game\Fleet\FleetDispatch;
use App\Game\Fleet\FleetDispatchRefused;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Fleet dispatch step 3 for the trade mission: the server enforces what the UI pre-check does, a resource offer
 * carries exactly one resource.
 */
final class FleetDispatchTradeTest extends GameTestCase
{
    /** @var list<int> */
    private array $fleetIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $lng = new Language($this->configs, 'en');
        $lng->includeData(['L18N', 'INGAME', 'TECH', 'FLEET', 'CUSTOM']);
        $this->state->LNG = $lng;
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::container()->get('request_stack')->push($request);
        $this->state->request = $request;
        $this->state->time = ['start' => microtime(true), 'end_common' => microtime(true), 'db' => 0];
    }

    protected function tearDown(): void
    {
        foreach ($this->fleetIds as $id) {
            $this->db->delete('DELETE FROM %%TRADES%% WHERE seller_fleet_id = :id;', [':id' => $id]);
            foreach (['FLEETS_EVENT' => 'fleetID', 'LOG_FLEETS' => 'id', 'FLEETS' => 'id'] as $table => $column) {
                $this->db->delete("DELETE FROM %%$table%% WHERE $column = :id;", [':id' => $id]);
            }
        }
        parent::tearDown();
    }

    /** @param array<string, mixed> $input posted resources */
    private function dispatchTrade(array $input): array
    {
        $resource = $this->state->resource;
        [$user, $planet] = $this->createPlayer([], [$resource[202] => 10, 'metal' => 1e6, 'crystal' => 1e6, 'deuterium' => 1e6]);
        $this->state->USER = $user;
        $this->state->PLANET = $planet;
        $marketSlot = $this->configs->get(1)->max_planets + 2;
        $session = (new SessionManager($this->db, $this->configs, $this->state))->load();
        $session->fleet = ['tok' => [
            'time' => time(), 'distance' => 1000, 'targetGalaxy' => $planet['galaxy'], 'targetSystem' => $planet['system'],
            'targetPlanet' => $marketSlot, 'targetType' => 1, 'fleetGroup' => 0, 'fleet' => [202 => 10], 'fleetRoom' => 50000,
            'fleetSpeed' => 10, 'ownPlanet' => $planet['id'],
        ]];
        $session->save();
        $this->state->requestInput = $input + ['token' => 'tok', 'mission' => MISSION_TRADE, 'markettype' => 0, 'resEx' => 3, 'exchange' => 100, 'staytime' => 1, 'visibility' => 0, 'maxFlightTime' => 0];

        return (new FleetDispatch($this->db, $this->configs, $this->state))->dispatch();
    }

    public function testATradeOfferWithTwoResourcesIsRefused(): void
    {
        try {
            $this->dispatchTrade(['metal' => 100, 'crystal' => 50]);
            $this->fail('the dispatch should be refused');
        } catch (FleetDispatchRefused $refused) {
            $this->assertSame($this->state->LNG['fl_trade_to_many_resources_loaded'], $refused->getMessage());
        }
    }

    public function testATradeOfferWithOneResourceIsSent(): void
    {
        $result = $this->dispatchTrade(['metal' => 100]);

        $this->assertSame('game?page=fleetTable', $result['goto']);
        $trade = $this->db->selectSingle('SELECT * FROM %%TRADES%% ORDER BY seller_fleet_id DESC LIMIT 1;');
        $this->fleetIds[] = (int) $trade['seller_fleet_id'];
        $this->assertEquals(100, $trade['resource_amount']);
        $this->assertSame(1, (int) $trade['resource_type']);
    }
}
