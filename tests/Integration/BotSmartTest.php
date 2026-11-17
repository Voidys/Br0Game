<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Bots\BotActions;
use App\Game\Bots\BotBrain;
use App\Game\Bots\BotExpeditions;
use App\Game\Bots\BotLogistics;
use App\Game\Bots\BotPersona;
use App\Game\Bots\BotProfiles;
use App\Game\Core\FleetFunctions;
use App\Tests\Support\GameTestCase;
use App\Game\Enum\Mission;

/**
 * The economy and fleet behaviour of bots: goal-based saving, consolidation transports, fleetsave (timing, cargo, chance), reaction to
 * incoming attacks with spy-technology dependent visibility, expedition composition by the game's point cap and the reaction to losses,
 * colony positions, reserved expedition slots and the new profile settings.
 */
final class BotSmartTest extends GameTestCase
{
    private const GALAXY = 6;

    private int $slot = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUniverseConfig(['noob_protection' => 0, 'bash_per_user' => 0, 'bash_per_planet' => 0, 'adm_attack' => 0]);
    }

    /**
     * @param array<string, mixed> $planet
     * @param array<string, mixed> $user
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function player(array $user = [], array $planet = []): array
    {
        $this->slot++;

        return $this->createPlayer($user, $planet + ['galaxy' => self::GALAXY, 'system' => 50 + $this->slot * 3, 'planet' => 8]);
    }

    /**
     * A bot with two planets (the second one taken over from a helper player) and ships on the first.
     *
     * @param array<mixed> $first
     * @param array<mixed> $second
     * @param array<string, mixed> $user
     * @return array<mixed>
     */
    private function bot(array $user = [], array $first = [], array $second = []): array
    {
        $r = $this->elements()->columnMap();
        [$u, $a] = $this->player($user + ['computer_tech' => 10, 'spy_tech' => 8], $first + [$r[203] => 40, $r[202] => 40, $r[206] => 20, 'metal' => 500000, 'crystal' => 800000, 'deuterium' => 200000, 'metal_max' => 2000000, 'crystal_max' => 2000000, 'deuterium_max' => 2000000]);
        [$helper, $b] = $this->player([], $second + ['metal' => 10, 'crystal' => 10, 'deuterium' => 10, 'metal_max' => 1000000, 'crystal_max' => 1000000, 'deuterium_max' => 1000000]);
        $this->db->update('UPDATE %%PLANETS%% SET id_owner = :o WHERE id = :id;', [':o' => $u['id'], ':id' => $b['id']]);
        $this->db->insert('INSERT INTO %%BOT_PLAYERS%% SET user_id = :u, universe = 1, profile_id = 4, skill = 0.9, personality = :p, created_at = :t;', [':u' => $u['id'], ':p' => '{}', ':t' => time() - 86400]);

        return [$u, $a, $this->loadPlanet((int) $b['id'])];
    }

    /**
     * @param array<string, mixed> $bot
     */
    private function ctx(array $bot): BotActions
    {
        $ctx = new BotActions(1, $this->db, $this->configs, $this->state, self::container()->get(BotPersona::class));
        $this->assertTrue($ctx->load((int) $bot['id']));

        return $ctx;
    }

    /**
     * @param array<mixed> $override
     * @return array<string, mixed>
     */
    private function settings(array $override = []): array
    {
        return $override + self::container()->get(BotProfiles::class)->defaults()['lunatic']['settings'];
    }

    /** @return array<string, string> action => all details of that action in the bot log, joined */
    private function log(int $botId): array
    {
        $out = [];
        foreach ($this->db->select('SELECT action, detail FROM %%BOT_LOG%% WHERE user_id = :u ORDER BY id;', [':u' => $botId]) as $row) {
            $out[$row['action']] = ($out[$row['action']] ?? '') . $row['detail'] . ' | ';
        }

        return $out;
    }

    public function testSavingKeepsCheapPurchasesFromTheGoal(): void
    {
        [$bot, $a] = $this->bot();
        $ctx = $this->ctx($bot);
        $planetId = (int) $a['id'];
        $this->db->update('UPDATE %%PLANETS%% SET metal = 1000, crystal = 500, deuterium = 0, metal_max = 100000, crystal_max = 100000 WHERE id = :id;', [':id' => $planetId]);
        $ctx = $this->ctx($bot);
        $brain = self::container()->get(BotBrain::class);
        $goal = ['type' => 'build', 'element' => 1, 'planet' => $planetId, 'cost' => [901 => 5000, 902 => 2500, 903 => 0], 'raw' => 80.0, 'affordable' => false, 'label' => 'metal_mine'];
        $other = fn(array $cost, string $type = 'build', int $element = 2): array => ['type' => $type, 'element' => $element, 'planet' => $planetId, 'cost' => $cost];

        $this->assertTrue($brain->compatibleWithSaving($ctx, $goal, $goal), 'the goal itself');
        $this->assertFalse($brain->compatibleWithSaving($ctx, $other([901 => 600]), $goal), 'would eat the metal the goal lacks');
        $this->assertTrue($brain->compatibleWithSaving($ctx, $other([901 => 200]), $goal), 'a rounding error of the goal');
        $this->assertTrue($brain->compatibleWithSaving($ctx, $other([903 => 5000]), $goal), 'a resource the goal does not lack');
        $this->db->update('UPDATE %%PLANETS%% SET metal = 95000, metal_max = 100000 WHERE id = :id;', [':id' => $planetId]);
        $this->assertTrue($brain->compatibleWithSaving($this->ctx($bot), $other([901 => 600]), $goal), 'metal about to overflow: spending it is fine');
    }

    public function testGoalIsTheBestValueForItsCostAndFarGoalsAreIgnored(): void
    {
        [$bot, $a] = $this->bot();
        $this->db->update('UPDATE %%PLANETS%% SET metal = 1000, crystal = 500, deuterium = 0 WHERE id = :id;', [':id' => $a['id']]);
        $ctx = $this->ctx($bot);
        $brain = self::container()->get(BotBrain::class);
        $p = (int) $a['id'];
        $cheap = ['type' => 'build', 'element' => 1, 'planet' => $p, 'cost' => [901 => 1100, 902 => 520, 903 => 0], 'raw' => 60.0, 'affordable' => false, 'label' => 'cheap'];
        $dear = ['type' => 'build', 'element' => 2, 'planet' => $p, 'cost' => [901 => 90000000, 902 => 40000000, 903 => 0], 'raw' => 90.0, 'affordable' => false, 'label' => 'dear'];
        $ship = ['type' => 'ship', 'element' => 202, 'planet' => $p, 'cost' => [901 => 100], 'raw' => 500.0, 'affordable' => true, 'label' => 'ship'];

        $goal = $brain->goal($ctx, [$dear, $ship, $cheap], 1.0);
        $this->assertSame('cheap', $goal['label'], 'ships are no goal; a goal days away is ignored');
        $this->assertNull($brain->goal($ctx, [$dear, $ship], 1.0));
    }

    public function testConsolidationBringsResourcesToTheGoalPlanet(): void
    {
        [$bot, $a, $b] = $this->bot();
        $ctx = $this->ctx($bot);
        $logistics = self::container()->get(BotLogistics::class);
        // goal on B (empty), A is rich and has cargo ships
        $goal = ['type' => 'build', 'element' => 1, 'planet' => (int) $b['id'], 'cost' => [901 => 60000, 902 => 30000, 903 => 0], 'label' => 'metal_mine 9'];

        $this->assertSame('transport', $logistics->consolidate($ctx, $goal));
        $fleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE owner = :u AND mission = :m;', [':u' => $bot['id'], ':m' => Mission::Transport->value]);
        $this->assertSame((int) $b['id'], (int) $fleet['end_id']);
        $this->assertEqualsWithDelta(59990, (float) $fleet['resource_metal'], 1);
        $this->assertEqualsWithDelta(29990, (float) $fleet['resource_crystal'], 1);
        $this->assertStringContainsString('metal_mine 9', $this->log((int) $bot['id'])['transport']);

        // what is on the way counts: a second call does not send it again
        $this->assertNull($logistics->consolidate($this->ctx($bot), $goal));
        $this->assertNull($logistics->consolidate($this->ctx($bot), ['planet' => (int) $a['id']] + $goal), 'the rich planet misses nothing');
    }

    public function testFleetsaveSendsShipsAndResourcesOnASlowTransportThatReturnsAfterTheOfflineTime(): void
    {
        [$bot, $a, $b] = $this->bot();
        $ctx = $this->ctx($bot);
        $logistics = self::container()->get(BotLogistics::class);
        $offline = 6 * 3600;

        $sent = $logistics->fleetSave($ctx, $this->settings(['fleetsave_chance' => 100]), $offline, 'test', [(int) $a['id']]);

        $this->assertSame(1, $sent);
        $fleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE owner = :u;', [':u' => $bot['id']]);
        $this->assertSame((int) Mission::Transport->value, (int) $fleet['mission']);
        $this->assertSame((int) $b['id'], (int) $fleet['end_id']);
        $this->assertGreaterThan(0, (float) $fleet['resource_metal'], 'the resources travel too');
        $this->assertGreaterThan(0, (float) $fleet['ship_amount']);
        $home = (int) $fleet['return_time'] - (int) $fleet['start_time'];
        $fastest = $this->fleetFunctions()->getMissionDuration(10, $this->fleetFunctions()->getFleetMaxSpeed($this->fleetFunctions()->unserialize($fleet['ships']), $bot), $this->fleetFunctions()->getTargetDistance([$a['galaxy'], $a['system'], $a['planet']], [$b['galaxy'], $b['system'], $b['planet']]), $this->fleetFunctions()->getGameSpeedFactor(), $bot);
        $this->assertGreaterThan(2 * $fastest, $home, 'slower than full speed');
        $this->assertTrue($home >= $offline || $home >= 2 * 10 * $fastest * 0.8, 'covers the offline time or flies as slowly as possible');
        $planet = $this->loadPlanet((int) $a['id']);
        $this->assertSame(0, (int) $planet[$this->elements()->column(203)], 'the ships left the planet');
        $this->assertGreaterThan(0.15 * 500000, (float) $planet['metal'], 'a share of the stock stays for local building');
        $this->assertStringContainsString('home again in', $this->log((int) $bot['id'])['fleetsave']);
    }

    public function testFleetsaveChanceMinimumOfflineAndSinglePlanet(): void
    {
        [$bot, $a] = $this->bot();
        $logistics = self::container()->get(BotLogistics::class);

        $this->assertSame(0, $logistics->fleetSave($this->ctx($bot), $this->settings(['fleetsave_chance' => 0]), 6 * 3600, 'test'), 'never with chance 0 (the noob who forgets)');
        $this->assertSame(0, $logistics->fleetSave($this->ctx($bot), $this->settings(['fleetsave_chance' => 100]), 600, 'test'), 'a short break is not worth it');
        $this->assertSame(0, $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%FLEETS%% WHERE owner = :u;', [':u' => $bot['id']], 'c') + 0);

        [$lonely, $planet] = $this->player(['computer_tech' => 5], [$this->elements()->column(203) => 5]);
        $this->db->insert('INSERT INTO %%BOT_PLAYERS%% SET user_id = :u, universe = 1, profile_id = 4, skill = 0.9, personality = :p, created_at = :t;', [':u' => $lonely['id'], ':p' => '{}', ':t' => time()]);
        $this->assertSame(0, $logistics->fleetSave($this->ctx($lonely), $this->settings(['fleetsave_chance' => 100]), 6 * 3600, 'test'));
        $this->assertStringContainsString('no second planet', $this->log((int) $lonely['id'])['fleetsave_skip']);
    }

    /**
     * Sends a hostile fleet from a fresh player at the bot's planet and returns the flying fleet id.
     *
     * @param array<mixed> $botPlanet
     * @param array<mixed> $ships
     */
    private function attackerAt(array $botPlanet, array $ships): int
    {
        $columns = [];
        foreach ($ships as $id => $count) {
            $columns[$this->elements()->column($id)] = $count;
        }
        [$enemy, $enemyPlanet] = $this->player([], $columns);
        $this->state->USER = $enemy;
        $this->state->PLANET = $enemyPlanet;
        $now = time();

        return (int) (new FleetFunctions($this->db, $this->configs, $this->state))->sendFleet(
            $ships,
            Mission::Attack->value,
            (int) $enemy['id'],
            (int) $enemyPlanet['id'],
            (int) $enemyPlanet['galaxy'],
            (int) $enemyPlanet['system'],
            (int) $enemyPlanet['planet'],
            1,
            (int) $botPlanet['id_owner'],
            (int) $botPlanet['id'],
            (int) $botPlanet['galaxy'],
            (int) $botPlanet['system'],
            (int) $botPlanet['planet'],
            1,
            [901 => 0, 902 => 0, 903 => 0],
            $now + 1800,
            $now + 1800,
            $now + 3600,
        );
    }

    public function testStrongerIncomingAttackTriggersFleetsaveAndAWeakOneDefense(): void
    {
        $r = $this->elements()->columnMap();
        [$bot, $a] = $this->bot(['spy_tech' => 8, 'military_tech' => 2, 'shield_tech' => 2, 'defence_tech' => 2]);
        $this->db->update('UPDATE %%PLANETS%% SET ' . $r[207] . ' = 3000, ' . $r[212] . ' = 0, hangar = 5 WHERE id = :id;', [':id' => $a['id']]);
        $enemyShips = [207 => 5000];
        $logistics = self::container()->get(BotLogistics::class);
        $personality = [];

        // a big fleet: the planet would fall, the bot fleetsaves (profile chance 100)
        $this->createEnemyFleet($a, $enemyShips);
        $acted = $logistics->threats($this->ctx($bot), $this->settings(['fleetsave_chance' => 100]), $personality, time());
        $this->assertSame(1, $acted, json_encode($this->log((int) $bot['id'])));
        $this->assertStringContainsString('likely stronger', $this->log((int) $bot['id'])['threat'] ?? 'no threat logged');
        $this->assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%FLEETS%% WHERE owner = :u AND mission = :m;', [':u' => $bot['id'], ':m' => Mission::Transport->value], 'c'));
        $this->assertNotEmpty($personality['_fs']['seen'], 'the fleet is judged once');
        $this->assertSame(0, $logistics->threats($this->ctx($bot), $this->settings(['fleetsave_chance' => 100]), $personality, time()), 'not judged twice');
    }

    public function testAHarmlessAttackGetsDefenseNotAFleetsave(): void
    {
        $r = $this->elements()->columnMap();
        [$bot, $a] = $this->bot(['spy_tech' => 8], [$r[401] => 400, 'hangar' => 6]);
        $logistics = self::container()->get(BotLogistics::class);
        $personality = [];
        $this->attackerAt($a, [204 => 3]);

        $acted = $logistics->threats($this->ctx($bot), $this->settings(['fleetsave_chance' => 100]), $personality, time());

        $this->assertSame(1, $acted);
        $this->assertStringContainsString('the planet should hold', $this->log((int) $bot['id'])['threat']);
        $this->assertStringContainsString('queued', $this->log((int) $bot['id'])['threat']);
        $this->assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%FLEETS%% WHERE owner = :u;', [':u' => $bot['id']], 'c'));
        $this->assertNotEmpty($this->loadPlanet((int) $a['id'])['b_hangar_id']);
    }

    /**
     * @param array<mixed> $botPlanet
     * @param array<mixed> $ships
     */
    private function createEnemyFleet(array $botPlanet, array $ships): void
    {
        $this->attackerAt($botPlanet, $ships);
    }

    public function testVisibilityDependsOnSpyTechnology(): void
    {
        [$bot, $a] = $this->bot(['spy_tech' => 1]);
        $logistics = self::container()->get(BotLogistics::class);
        $personality = [];
        $this->attackerAt($a, [206 => 1]);

        $logistics->threats($this->ctx($bot), $this->settings(['fleetsave_chance' => 0]), $personality, time());

        $log = $this->log((int) $bot['id']);
        $this->assertStringContainsString('not identifiable', $log['threat'], 'spy technology 1 shows nothing about the fleet');
    }

    public function testExpeditionFleetFollowsTheGamesPointCap(): void
    {
        $r = $this->elements()->columnMap();
        [$bot, $a, $b] = $this->bot([], [$r[203] => 2000, $r[202] => 500, $r[206] => 500]);
        $expeditions = self::container()->get(BotExpeditions::class);
        $cap = $expeditions->cap();
        $fleet = $expeditions->compose($this->loadPlanet((int) $a['id']));

        $this->assertArrayHasKey(SHIP_LARGE_CARGO, $fleet);
        $cargoPoints = $expeditions->points(array_intersect_key($fleet, [203 => 1, 202 => 1]));
        $this->assertLessThanOrEqual($cap + 60, $cargoPoints, 'no points beyond the cap');
        $this->assertGreaterThanOrEqual($cap * 0.8 * 0.8, $cargoPoints, 'close to the cap when the planet has the ships');
        $escort = $expeditions->points(array_intersect_key($fleet, [206 => 1]));
        $this->assertLessThanOrEqual(0.11 * $cargoPoints + 12, $escort, 'about a tenth of the points as escort');
        $this->assertSame([], $expeditions->compose($this->loadPlanet((int) $b['id'])), 'no cargo ships, no expedition');

        // a planet with few ships: caution halves the fleet (with plenty of ships the cap decides)
        $few = $this->loadPlanet((int) $a['id']);
        $few[$r[203]] = 6;
        $few[$r[202]] = 0;
        $few[$r[206]] = 0;
        $this->assertLessThanOrEqual((int) floor(6 * 0.5), $expeditions->compose($few, 0.5)[203], 'careful: half the ships');
        $this->assertGreaterThanOrEqual(4, $expeditions->compose($few, 1.0)[203]);
    }

    public function testExpeditionLossesMakeTheBotCareful(): void
    {
        [$bot, $a] = $this->bot();
        $expeditions = self::container()->get(BotExpeditions::class);
        $now = time();
        $id = $this->attackerFleetRow($bot, $a, [203 => 4]);
        $personality = ['_expo' => ['fleets' => [$id => ['ships' => [203 => 10], 'at' => $now]]]];

        $expeditions->settle(['user_id' => $bot['id'], 'universe' => 1], $personality, $now);
        $this->assertArrayNotHasKey($id, $personality['_expo']['fleets']);
        $this->assertSame(0.5, $personality['_expo']['mult']);
        $this->assertGreaterThan($now, $personality['_expo']['until']);
        $this->assertStringContainsString('lost 60 %', $this->log((int) $bot['id'])['expo_result']);

        $fine = $this->attackerFleetRow($bot, $a, [203 => 10]);
        $other = ['_expo' => ['fleets' => [$fine => ['ships' => [203 => 10], 'at' => $now]]]];
        $expeditions->settle(['user_id' => $bot['id'], 'universe' => 1], $other, $now);
        $this->assertArrayNotHasKey('mult', $other['_expo'], 'no loss, no caution');
    }

    /**
     * A returning fleet row of the bot with the given ships (as if an expedition came back).
     *
     * @param array<string, mixed> $bot
     * @param array<string, mixed> $planet
     * @param array<mixed> $ships
     */
    private function attackerFleetRow(array $bot, array $planet, array $ships): int
    {
        $this->state->USER = $bot;
        $now = time();
        $id = (new FleetFunctions($this->db, $this->configs, $this->state))->sendFleet(
            $ships,
            Mission::Expedition->value,
            (int) $bot['id'],
            (int) $planet['id'],
            (int) $planet['galaxy'],
            (int) $planet['system'],
            (int) $planet['planet'],
            1,
            0,
            0,
            (int) $planet['galaxy'],
            (int) $planet['system'],
            16,
            1,
            [901 => 0, 902 => 0, 903 => 0],
            $now - 100,
            $now - 50,
            $now + 1000,
        );
        $this->db->update('UPDATE %%FLEETS%% SET status = :s WHERE id = :id;', [':s' => FLEET_RETURN, ':id' => $id]);

        return (int) $id;
    }

    public function testColonyPositionsAreChosenLikeAPlayerWould(): void
    {
        $r = $this->elements()->columnMap();
        [$bot, $a] = $this->bot([$r[124] => 8], [$r[208] => 1]);
        $ctx = $this->ctx($bot);
        $method = new \ReflectionMethod($ctx, 'colonyTarget');
        for ($i = 0; $i < 12; $i++) {
            $target = $method->invoke($ctx, $ctx->planets[(int) $a['id']]);
            $this->assertNotNull($target);
            $this->assertContains($target[2], [4, 5, 6, 7, 8, 9, 10, 11, 12], 'mid positions, not the edges');
            $this->assertLessThanOrEqual(3 + 7, abs($target[1] - (int) $a['system']));
        }
    }

    public function testReservedSlotsAreKeptForExpeditionsOnly(): void
    {
        [$bot] = $this->bot(['computer_tech' => 3]); // 4 slots
        $ctx = $this->ctx($bot);
        $raw = $ctx->freeFleetSlots(true);
        $ctx->reservedSlots = 2;
        $this->assertSame($raw - 2, $ctx->freeFleetSlots());
        $this->assertSame($raw, $ctx->freeFleetSlots(true), 'expeditions may use them');
        $ctx->reservedSlots = 50;
        $this->assertSame(2, $ctx->freeFleetSlots(), 'a bot with few slots keeps two for other flights');
    }

    public function testNewProfileSettingsAreClampedAndDefaulted(): void
    {
        $profiles = self::container()->get(BotProfiles::class);
        $d = $profiles->defaults();
        $this->assertGreaterThan($d['noob']['settings']['fleetsave_chance'], $d['lunatic']['settings']['fleetsave_chance']);
        $this->assertGreaterThan($d['noob']['settings']['saving_discipline'], $d['lunatic']['settings']['saving_discipline']);
        $this->assertSame(2, $d['lunatic']['settings']['expedition_slots']);
        $n = $profiles->normalizeSettings(['fleetsave_chance' => 500, 'saving_discipline' => -4, 'expedition_slots' => 99]);
        $this->assertSame([100, 0, 5], [$n['fleetsave_chance'], $n['saving_discipline'], $n['expedition_slots']]);
    }

    private function fleetFunctions(): FleetFunctions
    {
        return new FleetFunctions($this->db, $this->configs, $this->state);
    }
}
