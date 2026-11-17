<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\Language;
use App\Game\Core\SessionManager;
use App\Game\Fleet\FleetDispatch;
use App\Game\Fleet\FleetDispatchRefused;
use App\Security\FleetDailyCaps;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use App\Game\Enum\Mission;

/**
 * Audit F-14: optional daily caps per account on fleet launches and attacks (FleetDailyCaps), off by default.
 */
final class FleetDailyCapTest extends GameTestCase
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

    private function caps(int $launches, int $attacks): FleetDailyCaps
    {
        $window = static fn(string $id, int $limit): RateLimiterFactory => new RateLimiterFactory(
            ['id' => $id, 'policy' => 'fixed_window', 'limit' => max(1, $limit), 'interval' => '1 day'],
            new InMemoryStorage(),
        );

        return new FleetDailyCaps($window('l', $launches), $window('a', $attacks), $launches, $attacks);
    }

    /** One trade fleet (a launch, no attack) of the prepared player; the fleet row is cleaned up in tearDown(). */
    private function launchTrade(FleetDailyCaps $caps, string $token): void
    {
        $session = (new SessionManager($this->db, $this->configs, $this->state))->load();
        $planet = $this->state->PLANET;
        $session->fleet = ($session->fleet ?? []) + [$token => [
            'time' => time(), 'distance' => 1000, 'targetGalaxy' => $planet['galaxy'], 'targetSystem' => $planet['system'],
            'targetPlanet' => $this->configs->get(1)->max_planets + 2, 'targetType' => 1, 'fleetGroup' => 0, 'fleet' => [202 => 1], 'fleetRoom' => 50000,
            'fleetSpeed' => 10, 'ownPlanet' => $planet['id'],
        ]];
        $session->save();
        $this->state->requestInput = ['token' => $token, 'mission' => Mission::Trade->value, 'markettype' => 0, 'resEx' => 3, 'exchange' => 100, 'staytime' => 1, 'visibility' => 0, 'maxFlightTime' => 0, 'metal' => 100];
        try {
            (new FleetDispatch($this->db, $this->configs, $this->state, $caps))->dispatch();
        } finally {
            foreach ($this->db->select('SELECT `id` FROM %%FLEETS%% WHERE `owner` = :u;', [':u' => $this->state->USER['id']]) as $row) {
                $this->fleetIds[(int) $row['id']] = (int) $row['id'];
            }
        }
    }

    private function preparePlayer(): void
    {
        $resource = $this->elements()->columnMap();
        [$user, $planet] = $this->createPlayer([$resource[108] => 10], [$resource[202] => 10, 'metal' => 1e6, 'crystal' => 1e6, 'deuterium' => 1e6]);
        $this->state->USER = $user;
        $this->state->PLANET = $planet;
    }

    private function sentFleets(): int
    {
        return (int) $this->db->selectSingle('SELECT COUNT(*) AS n FROM %%FLEETS%% WHERE `owner` = :u;', [':u' => $this->state->USER['id']])['n'];
    }

    public function testOffByDefaultManyLaunchesPass(): void
    {
        $this->preparePlayer();
        $caps = $this->caps(0, 0);
        for ($i = 0; $i < 4; $i++) {
            $this->launchTrade($caps, "t$i");
        }
        $this->assertSame(4, $this->sentFleets());
    }

    public function testThirdLaunchWithCapTwoIsRefusedAndNothingIsSent(): void
    {
        $this->preparePlayer();
        $caps = $this->caps(2, 0);
        $this->launchTrade($caps, 't0');
        $this->launchTrade($caps, 't1');
        try {
            $this->launchTrade($caps, 't2');
            $this->fail('the third launch should be refused');
        } catch (FleetDispatchRefused $refused) {
            $this->assertStringContainsString('Daily fleet limit of 2', $refused->getMessage());
        }
        $this->assertSame(2, $this->sentFleets());
    }

    public function testAttackCapCountsOnlyAttackMissions(): void
    {
        $caps = $this->caps(0, 2);
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($caps->consume(7, Mission::Transport->value), 'transport ' . $i);
            $this->assertNull($caps->consume(7, Mission::Spy->value), 'spy ' . $i);
        }
        $this->assertNull($caps->consume(7, Mission::Attack->value));
        $this->assertNull($caps->consume(7, Mission::Destruction->value));
        $refused = $caps->consume(7, Mission::Acs->value);
        $this->assertSame('fl_daily_attack_cap_reached', $refused['key'] ?? null);
        $this->assertNull($caps->consume(7, Mission::Transport->value), 'other missions still pass');
        $this->assertNull($caps->consume(8, Mission::Attack->value), 'another account has its own quota');
    }

    public function testMissileCountsAsLaunchAndAttack(): void
    {
        $caps = $this->caps(3, 1);
        $this->assertNull($caps->consume(7, Mission::Missile->value));
        $this->assertSame('fl_daily_attack_cap_reached', $caps->consume(7, Mission::Missile->value)['key'] ?? null);
        // the refused missile used no launch: two launches of the cap of three are left
        $this->assertNull($caps->consume(7, Mission::Transport->value));
        $this->assertNull($caps->consume(7, Mission::Transport->value));
        $this->assertSame('fl_daily_cap_reached', $caps->consume(7, Mission::Transport->value)['key'] ?? null);
    }
}
