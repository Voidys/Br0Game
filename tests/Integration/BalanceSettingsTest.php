<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\AllianceDepot;
use App\Game\Core\BashRules;
use App\Game\Core\FleetString;
use App\Game\Core\MarketManager;
use App\Game\Core\Language;
use App\Game\Core\Player\HonorPoints;
use App\Game\Core\SessionManager;
use App\Game\Fleet\FleetDispatch;
use App\Game\Fleet\FleetDispatchRefused;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Admin settings from the abuse audit (F-02, F-17, F-18, F-20): each default reproduces the former behaviour, the
 * switched setting changes it.
 */
final class BalanceSettingsTest extends GameTestCase
{
    public function testPushToleranceDefaultIsThirtyPercentAndConfigurable(): void
    {
        [$seller] = $this->createPlayer();
        [$buyer] = $this->createPlayer();
        foreach ([$seller, $buyer] as $user) {
            $this->db->insert('INSERT INTO %%STATPOINTS%% SET id_owner = :id, universe = 1, stat_type = 1, total_points = 100 ON DUPLICATE KEY UPDATE total_points = 100;', [':id' => $user['id']]);
        }
        $market = new MarketManager($this->db, $this->configs, $this->state);
        $this->assertSame(30, (int) $this->configs->get()->market_push_tolerance);

        // equal scores: pushing when the ask is below 70 % of the highest value
        $this->assertFalse($market->isPush(100, 70, $seller['id'], $buyer['id']));
        $this->assertTrue($market->isPush(100, 69, $seller['id'], $buyer['id']));

        $this->setUniverseConfig(['market_push_tolerance' => 10]);
        $this->assertTrue($market->isPush(100, 80, $seller['id'], $buyer['id']));
        $this->assertFalse($market->isPush(100, 90, $seller['id'], $buyer['id']));
    }

    public function testDepotCostDefaultIsBaseConsumption(): void
    {
        [$owner] = $this->createPlayer([$this->state->resource[FUEL_EFFICIENCY_TECH] => 10]);
        $depot = new AllianceDepot($this->db, $this->configs, $this->state);
        $fleet = (new FleetString())->unserialize('202,10');

        $this->assertFalse((bool) $this->configs->get()->depot_cost_real_consumption);
        $this->assertSame(10, $depot->hourlyCost($fleet));
        $this->assertSame(10, $depot->hourlyCost($fleet, (int) $owner['id']));
    }

    public function testDepotCostByRealConsumptionUsesTheOwnersTechs(): void
    {
        [$owner] = $this->createPlayer([$this->state->resource[FUEL_EFFICIENCY_TECH] => 10]);
        $this->setUniverseConfig(['depot_cost_real_consumption' => 1]);
        $depot = new AllianceDepot($this->db, $this->configs, $this->state);
        $fleet = (new FleetString())->unserialize('202,10');

        // 10 per ship, minus 2 % per fuel efficiency level (10 levels = 8), ten ships, divided by 10
        $this->assertSame(8, $depot->hourlyCost($fleet, (int) $owner['id']));
        // without an owner there is nothing to compute techs for: base consumption
        $this->assertSame(10, $depot->hourlyCost($fleet));
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} attacker, target planet */
    private function warScenario(): array
    {
        $this->setUniverseConfig(['bash_per_planet' => 1, 'bash_per_user' => 0]);
        $now = $this->state->runtime->timestamp;
        $allies = [];
        foreach ([1, 2] as $i) {
            $this->db->insert('INSERT INTO %%ALLIANCE%% SET ally_name = :n, ally_tag = :t, ally_owner = 0, ally_register_time = 0, ally_universe = 1;', [':n' => 'bw' . $i . mt_rand(), ':t' => 'B' . $i]);
            $allies[$i] = (int) $this->db->lastInsertId();
        }
        [$attacker] = $this->createPlayer(['ally_id' => $allies[1]]);
        [$victim, $planet] = $this->createPlayer(['ally_id' => $allies[2], 'onlinetime' => $now]);
        $this->db->insert('INSERT INTO %%DIPLO%% SET owner_1 = :a, owner_2 = :b, level = 5, accept = 1, accept_text = \'\', request_time = :t, universe = 1;', [':a' => $allies[1], ':b' => $allies[2], ':t' => $now - 2 * ONE_DAY]);
        $this->db->insert(
            'INSERT INTO %%LOG_FLEETS%% SET id = :id, `group` = 0, universe = 1, mission = :m, owner = :o, target_owner = :t, start_id = 0, end_id = :e, ships = \'204,5\', arrival_time = :at, hasCanceled = 0;',
            [':id' => mt_rand(1000000, 99999999), ':m' => MISSION_ATTACK, ':o' => $attacker['id'], ':t' => $victim['id'], ':e' => $planet['id'], ':at' => $now - 100],
        );
        $this->state->USER = $attacker;

        return [$attacker, $planet];
    }

    public function testWarExemptsFromBashByDefault(): void
    {
        [, $planet] = $this->warScenario();
        $this->assertTrue((bool) $this->configs->get()->bash_war_exempt);
        $this->assertFalse((new BashRules($this->db, $this->configs, $this->state))->checkBash((int) $planet['id']));
    }

    public function testWarAttacksCountWhenTheExemptionIsOff(): void
    {
        [, $planet] = $this->warScenario();
        $this->setUniverseConfig(['bash_war_exempt' => 0]);
        $this->assertTrue((new BashRules($this->db, $this->configs, $this->state))->checkBash((int) $planet['id']));
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function honorPair(): array
    {
        $now = $this->state->runtime->timestamp;
        [$a] = $this->createPlayer(['onlinetime' => $now]);
        [$d] = $this->createPlayer(['onlinetime' => $now]);
        foreach ([$a, $d] as $user) {
            $this->db->insert('INSERT INTO %%STATPOINTS%% SET id_owner = :id, universe = 1, stat_type = 1, total_points = 100, fleet_points = 0, defs_points = 0 ON DUPLICATE KEY UPDATE total_points = 100, fleet_points = 0, defs_points = 0;', [':id' => $user['id']]);
        }

        return [$a, $d];
    }

    private function honorFights(int $user): int
    {
        return (int) (new AdvancedStatsHandler($this->db, $this->configs, $this->state))->getValue($user, AdvancedStatsEnum::HONORABLE_FIGHTS);
    }

    public function testHonorIsUnlimitedByDefault(): void
    {
        [$a, $d] = $this->honorPair();
        $honor = new HonorPoints($this->db, $this->configs, $this->state);
        $this->assertSame(0, (int) $this->configs->get()->honor_pair_cap);
        for ($i = 0; $i < 4; $i++) {
            $honor->calculate_honorpoints_single((int) $a['id'], (int) $d['id'], 1000000, 1000000);
        }
        $this->assertSame(4, $this->honorFights((int) $a['id']));
        $this->assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS anz FROM %%HONOR_FIGHTS%%;', [], 'anz'));
    }

    public function testHonorPairCapStopsFurtherAwardsInBothDirections(): void
    {
        $this->setUniverseConfig(['honor_pair_cap' => 2]);
        [$a, $d] = $this->honorPair();
        $honor = new HonorPoints($this->db, $this->configs, $this->state);
        $honor->calculate_honorpoints_single((int) $a['id'], (int) $d['id'], 1000000, 1000000);
        $honor->calculate_honorpoints_single((int) $d['id'], (int) $a['id'], 1000000, 1000000);
        $result = $honor->calculate_honorpoints_single((int) $a['id'], (int) $d['id'], 1000000, 1000000);

        $this->assertSame(['attackersH' => [], 'defendersH' => []], $result);
        $this->assertSame(2, $this->honorFights((int) $a['id']));
        $this->assertSame(2, $this->honorFights((int) $d['id']));
    }

    /** @var list<int> fleets created by dispatches */
    private array $fleetIds = [];

    protected function tearDown(): void
    {
        foreach ($this->fleetIds as $id) {
            foreach (['FLEETS_EVENT' => 'fleetID', 'LOG_FLEETS' => 'id', 'FLEETS' => 'id'] as $table => $column) {
                $this->db->delete("DELETE FROM %%$table%% WHERE $column = :id;", [':id' => $id]);
            }
        }
        $this->fleetIds = [];
        parent::tearDown();
    }

    /**
     * Dispatches a transport of 100000 metal. Returns the refusal message, or null when the flight was sent.
     *
     * @param array<string, mixed> $config universe settings
     */
    private function transport(array $config, bool $toOwnPlanet = false, bool $receiverSentBack = false): ?string
    {
        $lng = new Language($this->configs, 'en');
        $lng->includeData(['L18N', 'INGAME', 'TECH', 'FLEET', 'CUSTOM']);
        $this->state->LNG = $lng;
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::container()->get('request_stack')->push($request);
        $this->state->request = $request;
        $this->state->time = ['start' => microtime(true), 'end_common' => microtime(true), 'db' => 0];
        $this->setUniverseConfig(['noob_protection' => 0] + $config);

        $resource = $this->state->resource;
        [$user, $planet] = $this->createPlayer([], [$resource[203] => 20, 'metal' => 1e6, 'crystal' => 1e6, 'deuterium' => 1e6]);
        [$other, $otherPlanet] = $this->createPlayer();
        $target = $toOwnPlanet ? $this->createPlanetFor($user) : $otherPlanet;
        if ($receiverSentBack) {
            $this->db->insert(
                'INSERT INTO %%LOG_FLEETS%% SET id = :id, `group` = 0, universe = 1, mission = :m, owner = :o, target_owner = :t, start_id = 0, end_id = 0, ships = \'202,1\', start_time = :st, arrival_time = :st, resource_metal = 5000, hasCanceled = 0;',
                [':id' => mt_rand(100000000, 900000000), ':m' => MISSION_TRANSPORT, ':o' => $other['id'], ':t' => $user['id'], ':st' => $this->state->runtime->timestamp - 3 * ONE_DAY],
            );
        }
        $this->state->USER = $user;
        $this->state->PLANET = $planet;
        $session = (new SessionManager($this->db, $this->configs, $this->state))->load();
        $session->fleet = ['tok' => [
            'time' => time(), 'distance' => 1000, 'targetGalaxy' => $target['galaxy'], 'targetSystem' => $target['system'],
            'targetPlanet' => $target['planet'], 'targetType' => 1, 'fleetGroup' => 0, 'fleet' => [203 => 20], 'fleetRoom' => 500000,
            'fleetSpeed' => 10, 'ownPlanet' => $planet['id'],
        ]];
        $session->save();
        $this->state->requestInput = ['token' => 'tok', 'mission' => MISSION_TRANSPORT, 'metal' => 100000, 'crystal' => 0, 'deuterium' => 0, 'staytime' => 1];
        try {
            (new FleetDispatch($this->db, $this->configs, $this->state))->dispatch();
        } catch (FleetDispatchRefused $refused) {
            return $refused->getMessage();
        } finally {
            foreach ($this->db->select('SELECT id FROM %%FLEETS%% WHERE owner = :o;', [':o' => $user['id']]) as $row) {
                $this->fleetIds[] = (int) $row['id'];
            }
        }

        return null;
    }

    /**
     * A second planet of $user, used as the own target.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function createPlanetFor(array $user): array
    {
        $home = $this->db->selectSingle('SELECT * FROM %%PLANETS%% WHERE id_owner = :o ORDER BY id LIMIT 1;', [':o' => $user['id']]);
        $free = (int) $home['planet'] === 15 ? 14 : 15;
        $columns = array_values(array_filter(
            array_keys($home),
            static fn(string $column): bool => $column !== 'id',
        ));
        $list = '`' . implode('`, `', $columns) . '`';
        $select = implode(', ', array_map(static fn(string $c): string => $c === 'planet' ? (string) $free : '`' . $c . '`', $columns));
        $this->db->insert("INSERT INTO %%PLANETS%% ($list) SELECT $select FROM %%PLANETS%% WHERE id = :id;", [':id' => $home['id']]);

        return $this->db->selectSingle('SELECT * FROM %%PLANETS%% WHERE id = :id;', [':id' => $this->db->lastInsertId()]);
    }

    public function testLargeTransportIsAllowedWithTheGuardOffByDefault(): void
    {
        $this->assertFalse((bool) $this->configs->get()->transport_push_check);
        $this->assertNull($this->transport(['transport_push_limit' => 1000]));
    }

    public function testTransportAboveTheLimitIsRefusedWhenTheGuardIsOn(): void
    {
        $message = $this->transport(['transport_push_check' => 1, 'transport_push_limit' => 1000]);
        $this->assertSame($this->state->LNG['fl_push_guard'], $message);
    }

    public function testTransportIsAllowedWhenTheReceiverSentResourcesBack(): void
    {
        $this->assertNull($this->transport(['transport_push_check' => 1, 'transport_push_limit' => 1000], false, true));
    }

    public function testTransportBelowTheLimitIsAllowed(): void
    {
        $this->assertNull($this->transport(['transport_push_check' => 1, 'transport_push_limit' => 100000000]));
    }

    public function testTransportToOwnPlanetIsNeverRefused(): void
    {
        $this->assertNull($this->transport(['transport_push_check' => 1, 'transport_push_limit' => 1000], true));
    }
}
