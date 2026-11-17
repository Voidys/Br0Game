<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\BattleEngine\BattleEngineInterface;
use App\Game\BattleEngine\BattleEngineRust;
use App\Game\BattleEngine\BattleEngineSteemNova;
use App\Tests\Support\GameTestCase;

/**
 * Pins the combat engines. Two implementations exist: the PHP engine (BattleEngineSteemNova) and the Rust engine
 * (BattleEngineRust, bin/battlerust, source tools/battlerust). Both are fed the same fleets (simulator mode, so
 * nothing is written).
 *
 * - Both engines draw their randomness from PHP's mt_rand stream (the Rust library has its own bit-exact MT19937 and
 *   is seeded through BattleEngineRust::useSeed()), so with the same seed they must give the SAME result, not just a
 *   similar one: the golden inputs and a few hundred generated fleet setups are compared field by field (winner,
 *   rounds, debris, losses per side and user, repaired defense, wreck field, surviving ships).
 * - The PHP engine's goldens pin its behaviour for a mixed fight, a multi-fleet fight and fights decided without luck.
 * - Invariants (ships never multiply, debris matches the losses, per-user figures add up) hold for both engines.
 *
 * Why: the legacy renewal ported the Rust engine to source in the repository (phase 5) and the PHP engine may be
 * refactored; these tests prove the replacements fight the same battle.
 */
final class BattleEngineTest extends GameTestCase
{
    // GOLDEN-BEGIN
    private const GOLDEN = [
        'decided core [{"202":10},[0,0,0],{"202":10},[0,0,0]]' => ['won' => 'w', 'rounds' => 7, 'debris' => ['attacker' => [901 => 0.0, 902 => 0.0], 'defender' => [901 => 0.0, 902 => 0.0]], 'unitLost' => ['attacker' => 0, 'defender' => 0], 'wreckfield' => [], 'attackersLeft' => [[202 => 10]], 'defendersLeft' => [[202 => 10]]],
        'decided per-user books [{"202":10},[0,0,0],{"202":10},[0,0,0]]' => [['attacker' => [1001 => 0], 'defender' => [2001 => 0]], ['attacker' => [1001 => 0], 'defender' => [2001 => 0]]],
        'decided core [{"207":1000},[0,0,0],{"204":1},[0,0,0]]' => ['won' => 'a', 'rounds' => 2, 'debris' => ['attacker' => [901 => 0.0, 902 => 0.0], 'defender' => [901 => 900.0, 902 => 300.0]], 'unitLost' => ['attacker' => 0, 'defender' => 4000], 'wreckfield' => [], 'attackersLeft' => [[207 => 1000]], 'defendersLeft' => [[]]],
        'decided per-user books [{"207":1000},[0,0,0],{"204":1},[0,0,0]]' => [['attacker' => [1001 => 0], 'defender' => [2001 => 4000]], ['attacker' => [1001 => 0], 'defender' => [2001 => 50]]],
        'decided core [{"213":600},[2,2,2],{"203":10,"208":2},[0,0,0]]' => ['won' => 'a', 'rounds' => 2, 'debris' => ['attacker' => [901 => 0.0, 902 => 0.0], 'defender' => [901 => 24000.0, 902 => 30000.0]], 'unitLost' => ['attacker' => 0, 'defender' => 180000], 'wreckfield' => [], 'attackersLeft' => [[213 => 600]], 'defendersLeft' => [[]]],
        'decided per-user books [{"213":600},[2,2,2],{"203":10,"208":2},[0,0,0]]' => [['attacker' => [1001 => 0], 'defender' => [2001 => 180000]], ['attacker' => [1001 => 0], 'defender' => [2001 => 265000]]],
        'php seed 42: fleet A' => ['won' => 'a', 'rounds' => 7, 'debris' => ['attacker' => [901 => 66600.0, 902 => 24600.0], 'defender' => [901 => 57000.0, 902 => 27000.0]], 'unitLost' => ['attacker' => 304000, 'defender' => 500000], 'unitLostPerSideAndUser' => ['attacker' => [1001 => 304000], 'defender' => [2001 => 280000]], 'capacityLostPerSideAndUser' => ['attacker' => [1001 => 3700], 'defender' => [2001 => 102500]], 'repaired' => [401 => ['units' => 56.0, 'percent' => 70.0], 402 => ['units' => 22.0, 'percent' => 73.33333333333333]], 'wreckfield' => [], 'attackersLeft' => [[204 => 34, 205 => 36, 207 => 10]], 'defendersLeft' => [[401 => 56.0, 402 => 22.0]]],
        'php seed 7: fleet A' => ['won' => 'a', 'rounds' => 6, 'debris' => ['attacker' => [901 => 54000.0, 902 => 19800.0], 'defender' => [901 => 57000.0, 902 => 27000.0]], 'unitLost' => ['attacker' => 246000, 'defender' => 500000], 'unitLostPerSideAndUser' => ['attacker' => [1001 => 246000], 'defender' => [2001 => 280000]], 'capacityLostPerSideAndUser' => ['attacker' => [1001 => 3000], 'defender' => [2001 => 102500]], 'repaired' => [401 => ['units' => 51.0, 'percent' => 63.74999999999999], 402 => ['units' => 24.0, 'percent' => 80.0]], 'wreckfield' => [], 'attackersLeft' => [[204 => 46, 205 => 37, 207 => 10]], 'defendersLeft' => [[401 => 51.0, 402 => 24.0]]],
        'php seed 42: fleet D' => ['won' => 'r', 'rounds' => 7, 'debris' => ['attacker' => [901 => 525000.0, 902 => 190500.0], 'defender' => [901 => 69000.0, 902 => 38400.0]], 'unitLost' => ['attacker' => 2385000, 'defender' => 950000], 'unitLostPerSideAndUser' => ['attacker' => [1001 => 2385000], 'defender' => [2001 => 358000]], 'capacityLostPerSideAndUser' => ['attacker' => [1001 => 56500], 'defender' => [2001 => 5700]], 'repaired' => [401 => ['units' => 116.0, 'percent' => 69.04761904761905], 403 => ['units' => 20.0, 'percent' => 62.5]], 'wreckfield' => [], 'attackersLeft' => [[]], 'defendersLeft' => [[205 => 15, 206 => 6, 401 => 148.0, 403 => 38.0, 404 => 5, 407 => 1]]],
        'php seed 99: fleet D' => ['won' => 'r', 'rounds' => 6, 'debris' => ['attacker' => [901 => 525000.0, 902 => 190500.0], 'defender' => [901 => 61200.0, 902 => 35100.0]], 'unitLost' => ['attacker' => 2385000, 'defender' => 809000], 'unitLostPerSideAndUser' => ['attacker' => [1001 => 2385000], 'defender' => [2001 => 321000]], 'capacityLostPerSideAndUser' => ['attacker' => [1001 => 56500], 'defender' => [2001 => 4800]], 'repaired' => [401 => ['units' => 98.0, 'percent' => 66.21621621621621], 403 => ['units' => 18.0, 'percent' => 75.0]], 'wreckfield' => [], 'attackersLeft' => [[]], 'defendersLeft' => [[205 => 16, 206 => 7, 401 => 150.0, 403 => 44.0, 404 => 5, 407 => 1]]],
        'php seed 5: two attacking fleets' => ['won' => 'w', 'rounds' => 7, 'debris' => ['attacker' => [901 => 259500.0, 902 => 89700.0], 'defender' => [901 => 132000.0, 902 => 69000.0]], 'unitLost' => ['attacker' => 1164000, 'defender' => 1610000], 'unitLostPerSideAndUser' => ['attacker' => [1001 => 600000, 1002 => 564000], 'defender' => [2001 => 670000]], 'capacityLostPerSideAndUser' => ['attacker' => [1001 => 17500, 1002 => 12600], 'defender' => [2001 => 12000]], 'repaired' => [401 => ['units' => 160.0, 'percent' => 80.0], 403 => ['units' => 32.0, 'percent' => 64.0], 404 => ['units' => 4, 'percent' => 100]], 'wreckfield' => [], 'attackersLeft' => [[206 => 10, 207 => 19, 211 => 5], [206 => 3]], 'defendersLeft' => [[401 => 160.0, 403 => 32.0, 404 => 5, 407 => 1]]],
    ];
    // GOLDEN-END

    private const FLEET_A = [[204 => 100, 205 => 40, 207 => 10], [5, 3, 4], [202 => 20, 204 => 50, 401 => 80, 402 => 30], [3, 2, 2]];
    private const FLEET_D = [[206 => 30, 207 => 20, 211 => 5], [8, 6, 7], [205 => 40, 206 => 10, 401 => 200, 403 => 50, 404 => 5, 407 => 1], [7, 7, 8]];

    /**
     * @param array<int, int> $units @param array{0: int, 1: int, 2: int} $tech weapons, shields, armour
     * @param array<mixed> $tech
     * @return array<mixed>
     */
    private function fleet(int $userId, array $units, array $tech = [0, 0, 0]): array
    {
        return [
            'fleetDetail' => ['start_galaxy' => 1, 'start_system' => 1, 'start_planet' => 1, 'start_type' => 1, 'end_galaxy' => 1, 'end_system' => 1, 'end_planet' => 1, 'end_type' => 1, 'resource_metal' => 0, 'resource_crystal' => 0, 'resource_deuterium' => 0],
            'player' => ['id' => $userId, 'username' => 'p' . $userId, 'military_tech' => $tech[0], 'shield_tech' => $tech[1], 'defence_tech' => $tech[2]],
            'unit' => $units,
        ];
    }

    private function engine(string $name): BattleEngineInterface
    {
        $engine = $name === 'rust'
            ? new BattleEngineRust($this->db, $this->configs, $this->state)
            : new BattleEngineSteemNova($this->db, $this->configs, $this->state);
        if (!$engine->isAvailable(true)) {
            $this->markTestSkipped("battle engine '$name' is not available here");
        }

        return $engine;
    }

    /**
     * Runs one fight (attackers[0] vs the defending planet) and returns the figures worth pinning.
     *
     * @param array{0: array<int, int>, 1: array{0: int, 1: int, 2: int}, 2: array<int, int>, 3: array{0: int, 1: int, 2: int}} $setup
     * @param array<mixed> $extraAttackers
     * @return array<string, mixed>
     */
    private function fight(string $engine, array $setup, ?int $seed = null, array $extraAttackers = []): array
    {
        [$attackUnits, $attackTech, $defendUnits, $defendTech] = $setup;
        $attackers = [$this->fleet(1001, $attackUnits, $attackTech)] + $extraAttackers;
        $defenders = [0 => $this->fleet(2001, $defendUnits, $defendTech)];

        return $this->fightFleets($engine, $attackers, $defenders, $seed);
    }

    /**
     * Runs one fight between the given fleets (by-ref like the engines take them) and returns the figures worth pinning.
     * With a seed both engines start from the same random stream (mt_srand for PHP, useSeed for Rust).
     *
     * @param array<int, array<string, mixed>> $attackers
     * @param array<int, array<string, mixed>> $defenders
     * @return array<string, mixed>
     */
    private function fightFleets(string $engine, array $attackers, array $defenders, ?int $seed = null): array
    {
        $engineObject = $this->engine($engine);
        if ($seed !== null) {
            mt_srand($seed);
            if ($engineObject instanceof BattleEngineRust) {
                $engineObject->useSeed($seed);
            }
        }

        $result = $engineObject->calculateAttack($attackers, $defenders, 1, true);
        $this->assertIsArray($result, "engine $engine returned no result");

        $remaining = static fn(array $fleets): array => array_map(static fn(array $fleet): array => array_filter($fleet['unit']), $fleets);

        return [
            'won' => $result['won'],
            'rounds' => count($result['rw']),
            'debris' => $result['debris'],
            'unitLost' => $result['unitLost'],
            'unitLostPerSideAndUser' => $result['unitLostPerSideAndUser'],
            'capacityLostPerSideAndUser' => $result['transportCapacityLostPerSideAndUser'],
            'repaired' => $result['repaired'],
            'wreckfield' => $result['wreckfield'],
            'attackersLeft' => $remaining($attackers),
            'defendersLeft' => $remaining($defenders),
        ];
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function engines(): iterable
    {
        yield 'php' => ['php'];
        yield 'rust' => ['rust'];
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function decidedFights(): iterable
    {
        // nothing can hurt anything: 5 attack against 10 shield, six empty rounds, the defender holds
        yield 'stalemate: small cargo vs small cargo' => [[[202 => 10], [0, 0, 0], [202 => 10], [0, 0, 0]]];
        // one battleship wall against a single light fighter: it dies in round 1, no losses for the attacker
        yield 'overwhelming: 1000 battleships vs 1 light fighter' => [[[207 => 1000], [0, 0, 0], [204 => 1], [0, 0, 0]]];
        // a harmless transport convoy against a big warfleet: every defender dies, the attacker loses nothing
        yield 'one-sided: 600 destroyers vs 10 large cargo + 2 colony ships' => [[[213 => 600], [2, 2, 2], [203 => 10, 208 => 2], [0, 0, 0]]];
    }

    /**
     * @param array<mixed> $setup
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('decidedFights')]
    public function testFightsDecidedWithoutLuckGiveTheSameResultInBothEngines(array $setup): void
    {
        $php = $this->fight('php', $setup, 1);
        $rust = $this->fight('rust', $setup, 1);

        $this->assertEqualsWithDelta($php, $rust, 1e-6, 'the engines decided the fight differently');
        $name = json_encode($setup);
        $core = array_diff_key($php, ['repaired' => 1, 'unitLostPerSideAndUser' => 1, 'capacityLostPerSideAndUser' => 1]);
        $this->goldenAssert('decided core ' . $name, $core);
        $this->goldenAssert('decided per-user books ' . $name, [$php['unitLostPerSideAndUser'], $php['capacityLostPerSideAndUser']]);
    }

    public function testPhpEngineIsReproducibleWithAFixedSeed(): void
    {
        $first = $this->fight('php', self::FLEET_A, 42);
        $second = $this->fight('php', self::FLEET_A, 42);

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $this->fight('php', self::FLEET_A, 43), 'another seed must give another fight');
    }

    public function testPhpEngineGoldenFights(): void
    {
        $this->goldenAssert('php seed 42: fleet A', $this->fight('php', self::FLEET_A, 42));
        $this->goldenAssert('php seed 7: fleet A', $this->fight('php', self::FLEET_A, 7));
        $this->goldenAssert('php seed 42: fleet D', $this->fight('php', self::FLEET_D, 42));
        $this->goldenAssert('php seed 99: fleet D', $this->fight('php', self::FLEET_D, 99));
    }

    public function testPhpEngineGoldenFightWithTwoAttackingFleets(): void
    {
        $second = $this->fleet(1002, [206 => 15, 204 => 60], [6, 4, 5]);

        $this->goldenAssert('php seed 5: two attacking fleets', $this->fight('php', self::FLEET_D, 5, [1 => $second]));
    }

    /** @param string $engine */
    #[\PHPUnit\Framework\Attributes\DataProvider('engines')]
    public function testResultInvariantsHoldForEveryFight(string $engine): void
    {
        $this->setUniverseConfig(['fleet_debris_percentage' => 30, 'def_debris_percentage' => 0]);
        $setup = self::FLEET_D;

        for ($run = 0; $run < 5; $run++) {
            $result = $this->fight($engine, $setup, 300 + $run);

            // ships never come back from the dead and never multiply
            foreach ($result['attackersLeft'][0] ?? [] as $unit => $left) {
                $this->assertLessThanOrEqual($setup[0][$unit], $left);
            }
            foreach ($result['defendersLeft'][0] ?? [] as $unit => $left) {
                // destroyed defence is partly rebuilt, but never above the starting amount
                $this->assertLessThanOrEqual($setup[2][$unit], $left);
            }

            // attacker debris is 30% of the metal/crystal of the lost ships
            $lostAttackerMetal = $lostAttackerCrystal = 0;
            foreach ($setup[0] as $unit => $start) {
                $lost = $start - ($result['attackersLeft'][0][$unit] ?? 0);
                $lostAttackerMetal += $lost * $this->elements()->cost($unit)->metal;
                $lostAttackerCrystal += $lost * $this->elements()->cost($unit)->crystal;
            }
            $this->assertEqualsWithDelta($lostAttackerMetal * 0.3, $result['debris']['attacker'][901], 0.01);
            $this->assertEqualsWithDelta($lostAttackerCrystal * 0.3, $result['debris']['attacker'][902], 0.01);
            $this->assertEqualsWithDelta($lostAttackerMetal + $lostAttackerCrystal, $result['unitLost']['attacker'], 0.01);

            // the per-user figures add up to the totals (defender: ships only, defence is counted in unitLost)
            $this->assertEqualsWithDelta($result['unitLost']['attacker'], array_sum($result['unitLostPerSideAndUser']['attacker']), 0.01);
            $this->assertContains($result['won'], ['a', 'r', 'w']);
            $this->assertGreaterThanOrEqual(2, $result['rounds']);
            $this->assertLessThanOrEqual(7, $result['rounds']);
        }
    }

    /**
     * Unit ids that fight (ships and defense) and exist in this installation's game data.
     *
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function fightingUnits(): array
    {
        $elements = $this->elements();
        $ships = array_values(array_filter([202, 203, 204, 205, 206, 207, 208, 209, 210, 211, 212, 213, 214, 215], static fn(int $id): bool => $elements->has($id)));
        $defence = array_values(array_filter([401, 402, 403, 404, 405, 406, 407, 408], static fn(int $id): bool => $elements->has($id)));

        return [$ships, $defence];
    }

    /**
     * A reproducible random battle: 1-3 attacking fleets, a defending planet (ships and defense) and sometimes
     * stationed allied fleets, random units, amounts and tech levels.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function randomBattle(int $index): array
    {
        [$ships, $defence] = $this->fightingUnits();
        mt_srand(900_000 + $index);
        $pick = static function (array $pool, int $max, int $maxAmount): array {
            $units = [];
            foreach (array_rand(array_flip($pool), min(count($pool), mt_rand(1, $max))) as $unit) {
                $units[(int) $unit] = mt_rand(0, 1) ? mt_rand(1, $maxAmount) : mt_rand(1, 6);
            }
            ksort($units);

            return $units;
        };
        $tech = static fn(): array => [mt_rand(0, 12), mt_rand(0, 12), mt_rand(0, 12)];

        $attackers = [];
        $attackerFleets = mt_rand(1, 3);
        for ($i = 0; $i < $attackerFleets; $i++) {
            $attackers[100 + $i * 7] = $this->fleet(1001 + $i, $pick($ships, 5, 150), $tech());
        }
        $defending = $pick($ships, 3, 80) + (mt_rand(0, 3) ? $pick($defence, 4, 120) : []);
        $defenders = [0 => $this->fleet(2001, $defending, $tech())];
        if (mt_rand(0, 2) === 0) {
            $defenders[55] = $this->fleet(2002, $pick($ships, 3, 60), $tech());
        }

        return [$attackers, $defenders];
    }

    /**
     * Same seed, same battle: the Rust library reproduces the PHP engine bit for bit. Compared over the golden
     * fleets with several seeds and over generated battles (random units, amounts, techs, several fleets per side,
     * rapid fire, explosions, defense rebuild), field by field.
     */
    public function testRustEngineMatchesThePhpEngineExactlyWithTheSameSeed(): void
    {
        $this->engine('rust');
        $compared = 0;

        $second = $this->fleet(1002, [206 => 15, 204 => 60], [6, 4, 5]);
        foreach ([[self::FLEET_A, 42], [self::FLEET_A, 7], [self::FLEET_D, 42], [self::FLEET_D, 99], [self::FLEET_D, 5]] as [$setup, $seed]) {
            $extra = $seed === 5 ? [1 => $second] : [];
            $php = $this->fight('php', $setup, $seed, $extra);
            $rust = $this->fight('rust', $setup, $seed, $extra);
            $this->assertEqualsWithDelta($php, $rust, 1e-6, 'golden fleet, seed ' . $seed . ': the engines differ');
            ++$compared;
        }

        $runs = (int) (getenv('BATTLE_PARITY_RUNS') ?: 300); // more for a long soak: BATTLE_PARITY_RUNS=5000
        for ($index = 0; $index < $runs; $index++) {
            [$attackers, $defenders] = $this->randomBattle($index);
            $seed = 31_000 + $index;
            $php = $this->fightFleets('php', $attackers, $defenders, $seed);
            $rust = $this->fightFleets('rust', $attackers, $defenders, $seed);
            $this->assertEqualsWithDelta($php, $rust, 1e-6, 'generated battle ' . $index . ' (seed ' . $seed . '): the engines differ');
            ++$compared;
        }

        $this->assertSame(5 + $runs, $compared);
    }

    /** Fleets of thousands of ships (long rapid fire chains, many explosions, several fights per round) match too. */
    public function testRustEngineMatchesThePhpEngineOnLargeFleets(): void
    {
        $this->engine('rust');
        foreach ([[self::FLEET_A, 40, 11], [self::FLEET_D, 30, 12], [self::FLEET_A, 90, 13]] as [$setup, $factor, $seed]) {
            $scaled = [array_map(static fn(int $n): int => $n * $factor, $setup[0]), $setup[1], array_map(static fn(int $n): int => $n * $factor, $setup[2]), $setup[3]];
            $php = $this->fight('php', $scaled, $seed);
            $rust = $this->fight('rust', $scaled, $seed);
            $this->assertEqualsWithDelta($php, $rust, 1e-6, "x$factor, seed $seed: the engines differ");
        }
    }

    /** The generated battles are a mix of decided and drawn fights, otherwise the parity test proves little. */
    public function testTheGeneratedBattlesCoverEveryOutcome(): void
    {
        $outcomes = [];
        $rounds = [];
        for ($index = 0; $index < 60; $index++) {
            [$attackers, $defenders] = $this->randomBattle($index);
            $result = $this->fightFleets('php', $attackers, $defenders, 31_000 + $index);
            $outcomes[$result['won']] = true;
            $rounds[$result['rounds']] = true;
        }

        $this->assertCount(3, $outcomes, 'attacker wins, defender wins and draws all occur');
        $this->assertGreaterThanOrEqual(4, count($rounds), 'fights of different length occur');
    }

    /** A Rust process that never finishes is killed after ENGINE_TIMEOUT and the fight falls back (runEngine gives null). */
    public function testRunEngineKillsAProcessThatRunsTooLong(): void
    {
        $endless = new class ($this->db, $this->configs, $this->state) extends BattleEngineRust {
            protected const ENGINE_TIMEOUT = 1;

            protected static function binary(): string
            {
                return '/usr/bin/yes'; // prints forever, never exits on its own
            }

            public function run(string $json): ?string
            {
                return $this->runEngine($json);
            }
        };

        $start = microtime(true);
        $this->assertNull($endless->run('{}'));
        $this->assertLessThan(5.0, microtime(true) - $start);
    }

    /** When the library refuses or fails, the PHP engine fights instead and the report is stored as a PHP-engine report. */
    public function testRustEngineFallsBackToThePhpEngineWhenTheLibraryFails(): void
    {
        $failing = new class ($this->db, $this->configs, $this->state) extends BattleEngineRust {
            protected function runEngine(string $json): ?string
            {
                return null;
            }
        };
        $this->assertSame('rust', $failing->getName());

        $attackers = [$this->fleet(1001, self::FLEET_A[0], self::FLEET_A[1])];
        $defenders = [0 => $this->fleet(2001, self::FLEET_A[2], self::FLEET_A[3])];
        $log = ini_set('error_log', '/dev/null');
        mt_srand(42);
        $result = $failing->calculateAttack($attackers, $defenders, 1, true);
        ini_set('error_log', (string) $log);

        $this->assertIsArray($result);
        $this->assertSame('steemnova', $failing->getName());
        $this->assertSame($this->fight('php', self::FLEET_A, 42)['attackersLeft'], array_map(static fn(array $fleet): array => array_filter($fleet['unit']), $attackers));
    }

    /** Without a seed the Rust engine still produces a valid, differing fight (the seed comes from the OS). */
    public function testRustEngineWithoutSeedGivesValidFights(): void
    {
        $outcomes = [];
        for ($run = 0; $run < 6; $run++) {
            $result = $this->fight('rust', self::FLEET_A);
            $outcomes[json_encode($result['defendersLeft'])] = true;
            $this->assertContains($result['won'], ['a', 'r', 'w']);
        }

        $this->assertGreaterThan(1, count($outcomes), 'unseeded fights are not all identical');
    }
}
