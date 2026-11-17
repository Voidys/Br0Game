<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\BattleEngine\BattleDebris;
use App\Game\BattleEngine\BattleHandler;
use App\Game\BattleEngine\BattleLosses;
use App\Game\BattleEngine\BattleResult;
use App\Game\Core\AccountLinks;
use App\Game\Core\BashRules;
use App\Game\Core\FleetFunctions;
use App\Game\Missions\MissionCaseAttack;
use App\Tests\Support\GameTestCase;

/**
 * Collusion guards from the abuse audit (F-16, F-18): no rebuild protection against linked opponents and no bash war
 * exemption between linked accounts.
 */
final class CollusionGuardsTest extends GameTestCase
{
    private function links(): AccountLinks
    {
        return new AccountLinks($this->db);
    }

    public function testLinkedByIpMultiAllianceAndBuddy(): void
    {
        [$a] = $this->createPlayer(['user_lastip' => '10.1.1.1']);
        [$b] = $this->createPlayer(['user_lastip' => '10.2.2.2']);
        $this->assertFalse($this->links()->linked($a['id'], $b['id'], true));
        $this->assertFalse($this->links()->linked($a['id'], $a['id'], true));

        $this->db->insert('INSERT INTO %%BUDDY%% SET sender = :a, owner = :b, universe = 1;', [':a' => $b['id'], ':b' => $a['id']]);
        $this->assertTrue($this->links()->linked($a['id'], $b['id'], true));
        $this->assertFalse($this->links()->linked($a['id'], $b['id'], false));

        [$c] = $this->createPlayer(['user_lastip' => '10.1.1.1']);
        $this->assertTrue($this->links()->linked($a['id'], $c['id'], false));

        [$d] = $this->createPlayer();
        $this->db->insert('INSERT INTO %%MULTI%% SET multi_ip = \'\', universe = 1;');
        $multi = (int) $this->db->lastInsertId();
        foreach ([$a, $d] as $user) {
            $this->db->insert('INSERT INTO %%MULTI_TO_USERS%% SET multiID = :m, userID = :u;', [':m' => $multi, ':u' => $user['id']]);
        }
        $this->assertTrue($this->links()->linked($a['id'], $d['id'], false));

        [$e] = $this->createPlayer(['ally_id' => 9001]);
        [$f] = $this->createPlayer(['ally_id' => 9001]);
        $this->assertTrue($this->links()->linked($e['id'], $f['id'], true));
        $this->assertFalse($this->links()->linked($e['id'], $f['id'], false));
    }

    /**
     * Defender loses everything to the attacker; returns whether the defender got rebuild protection.
     *
     * @param array<string, mixed> $defender
     * @param array<string, mixed> $attacker
     */
    private function rebuildProtected(array $defender, array $attacker): bool
    {
        $lost = 10000000;
        $losses = new BattleLosses(0, $lost, ['attacker' => [$attacker['id'] => 0], 'defender' => [$defender['id'] => $lost]], ['attacker' => [$attacker['id'] => 0], 'defender' => [$defender['id'] => 0]]);
        $result = new BattleResult('a', new BattleDebris(0, 0, 0, 0), [], $losses, [], [], 0.0);
        $handler = new BattleHandler(true, $this->db, $this->configs, $this->state);
        $handler->CheckRebuildProtection([$defender['id'] => ['totalUnits' => $lost, 'totalCapacity' => 1]], $result, 5000, 1);

        return (int) $this->db->selectSingle('SELECT rebuild_protection FROM %%USERS%% WHERE id = :id;', [':id' => $defender['id']], 'rebuild_protection') === 5000;
    }

    public function testRebuildProtectionAgainstUnlinkedAttacker(): void
    {
        [$defender] = $this->createPlayer(['user_lastip' => '10.3.3.3']);
        [$attacker] = $this->createPlayer(['user_lastip' => '10.4.4.4']);
        $this->assertTrue($this->rebuildProtected($defender, $attacker));
    }

    public function testRebuildProtectionRefusedAgainstSameIpAttacker(): void
    {
        [$defender] = $this->createPlayer(['user_lastip' => '10.5.5.5']);
        [$attacker] = $this->createPlayer(['user_lastip' => '10.5.5.5']);
        $this->assertFalse($this->rebuildProtected($defender, $attacker));
        $this->assertSame(0, (int) $this->db->selectSingle('SELECT rebuild_protection_locked_until FROM %%USERS%% WHERE id = :id;', [':id' => $defender['id']], 'rebuild_protection_locked_until'));
    }

    public function testRebuildProtectionRefusedAgainstBuddyAttacker(): void
    {
        [$defender] = $this->createPlayer(['user_lastip' => '10.6.6.6']);
        [$attacker] = $this->createPlayer(['user_lastip' => '10.7.7.7']);
        $this->db->insert('INSERT INTO %%BUDDY%% SET sender = :a, owner = :b, universe = 1;', [':a' => $attacker['id'], ':b' => $defender['id']]);
        $this->assertFalse($this->rebuildProtected($defender, $attacker));
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} attacker, target planet */
    private function warScenario(string $attackerIp, string $victimIp): array
    {
        $this->setUniverseConfig(['bash_per_planet' => 1, 'bash_per_user' => 0]);
        $now = $this->state->runtime->timestamp;
        $allies = [];
        foreach ([1, 2] as $i) {
            $this->db->insert('INSERT INTO %%ALLIANCE%% SET ally_name = :n, ally_tag = :t, ally_owner = 0, ally_register_time = 0, ally_universe = 1;', [':n' => 'cg' . $i . mt_rand(), ':t' => 'C' . $i]);
            $allies[$i] = (int) $this->db->lastInsertId();
        }
        [$attacker] = $this->createPlayer(['ally_id' => $allies[1], 'user_lastip' => $attackerIp]);
        [$victim, $planet] = $this->createPlayer(['ally_id' => $allies[2], 'onlinetime' => $now, 'user_lastip' => $victimIp]);
        $this->db->insert('INSERT INTO %%DIPLO%% SET owner_1 = :a, owner_2 = :b, level = 5, accept = 1, accept_text = \'\', request_time = :t, universe = 1;', [':a' => $allies[1], ':b' => $allies[2], ':t' => $now - 2 * ONE_DAY]);
        $this->db->insert(
            'INSERT INTO %%LOG_FLEETS%% SET id = :id, `group` = 0, universe = 1, mission = :m, owner = :o, target_owner = :t, start_id = 0, end_id = :e, ships = \'204,5\', arrival_time = :at, hasCanceled = 0;',
            [':id' => mt_rand(1000000, 99999999), ':m' => MISSION_ATTACK, ':o' => $attacker['id'], ':t' => $victim['id'], ':e' => $planet['id'], ':at' => $now - 100],
        );
        $this->state->USER = $attacker;

        return [$attacker, $planet];
    }

    public function testWarExemptionStillWorksForUnlinkedPlayers(): void
    {
        $this->setUniverseConfig(['bash_war_exempt' => 1]);
        [, $planet] = $this->warScenario('10.8.8.1', '10.8.8.2');
        $this->assertFalse((new BashRules($this->db, $this->configs, $this->state))->checkBash((int) $planet['id']));
    }

    public function testWarExemptionDoesNotApplyToSameIpPlayers(): void
    {
        [, $planet] = $this->warScenario('10.9.9.9', '10.9.9.9');
        $this->assertTrue((new BashRules($this->db, $this->configs, $this->state))->checkBash((int) $planet['id']));
    }

    /**
     * Runs a real attack mission (1000 battleships against 2000 small cargos on the defender's planet) and returns the
     * defender's rebuild protection timestamp afterwards (0 = not granted).
     *
     * @param array<string, mixed> $userOverrides extra columns for the attacker (e.g. a shared IP)
     */
    private function fightAndReadProtection(array $userOverrides, bool $buddies): int
    {
        $this->setUniverseConfig(['noob_protection' => 1, 'rebuild_protection' => 1, 'noob_protection_time' => 1]);
        $resource = $this->elements()->columnMap();
        [$attacker, $attackerPlanet] = $this->createPlayer($userOverrides + ['computer_tech' => 5, 'user_lastip' => '10.1.0.1'], [$resource[207] => 1000, 'deuterium' => 1e7]);
        [$defender, $defenderPlanet] = $this->createPlayer(['user_lastip' => '10.2.0.1', 'onlinetime' => time()], [$resource[202] => 2000]);
        if ($buddies) {
            $this->db->insert('INSERT INTO %%BUDDY%% SET sender = :a, owner = :b, universe = 1;', [':a' => $attacker['id'], ':b' => $defender['id']]);
        }
        $now = time();
        $this->state->USER = $attacker;
        $this->state->PLANET = $attackerPlanet;
        $fleetId = (new FleetFunctions($this->db, $this->configs, $this->state))->sendFleet(
            [207 => 1000],
            MISSION_ATTACK,
            (int) $attacker['id'],
            (int) $attackerPlanet['id'],
            (int) $attackerPlanet['galaxy'],
            (int) $attackerPlanet['system'],
            (int) $attackerPlanet['planet'],
            1,
            (int) $defender['id'],
            (int) $defenderPlanet['id'],
            (int) $defenderPlanet['galaxy'],
            (int) $defenderPlanet['system'],
            (int) $defenderPlanet['planet'],
            1,
            [901 => 0, 902 => 0, 903 => 0],
            $now - 100,
            $now - 100,
            $now - 50,
            0,
            0,
            0,
            0,
        );
        $fleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);
        (new MissionCaseAttack($fleet, $this->db, $this->configs, $this->state))->TargetEvent();

        return (int) $this->db->selectSingle('SELECT rebuild_protection FROM %%USERS%% WHERE id = :id;', [':id' => $defender['id']], 'rebuild_protection');
    }

    public function testRealAttackGrantsRebuildProtectionAgainstAStranger(): void
    {
        $this->assertGreaterThan(0, $this->fightAndReadProtection([], false));
    }

    public function testRealAttackByABuddyGrantsNoRebuildProtection(): void
    {
        $this->assertSame(0, $this->fightAndReadProtection([], true));
    }

    public function testRealAttackFromTheSameIpGrantsNoRebuildProtection(): void
    {
        $this->assertSame(0, $this->fightAndReadProtection(['user_lastip' => '10.2.0.1'], false));
    }
}
