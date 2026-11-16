<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\FleetFunctions;
use App\Game\Missions\MissionCaseAttack;
use App\Game\Missions\MissionCaseDestruction;
use App\Tests\Support\GameTestCase;

/**
 * Runs the attack and the moon destruction mission end to end on the test database with fights that need no luck (an
 * overwhelming fleet against one cargo ship; same result in the PHP and the Rust engine) and pins the rows they write:
 * fleet, planet losses and loot, debris, combat report row, report messages, fleet log.
 *
 * Why: attack and destruction share one fight/report pipeline (AbstractBattleMission); its results must stay identical.
 */
final class BattleMissionTest extends GameTestCase
{
    // GOLDEN-BEGIN
    private const GOLDEN = [
        'attack on a planet' => ['fleet' => ['status' => 1, 'ships' => '207,1000', 'ship_amount' => 1000, 'mission' => 1], 'planet' => ['metal' => 50000.0, 'crystal' => 25000.0, 'deuterium' => 10000.0, 'der_metal' => 600.0, 'der_crystal' => 600.0, 'tf_active' => 1], 'report rows' => 1, 'battle messages' => 2, 'log' => ['status' => 0, 'ships' => 1000, 'has report' => true], 'topkb rows' => true],
        'attack on a moon' => ['fleet' => ['status' => 1, 'ships' => '207,1000', 'ship_amount' => 1000, 'mission' => 1], 'planet' => ['metal' => 50000.0, 'crystal' => 0.0, 'deuterium' => 0.0, 'der_metal' => 0.0, 'der_crystal' => 0.0, 'tf_active' => 0], 'report rows' => 1, 'battle messages' => 2, 'log' => ['status' => 0, 'ships' => 1000, 'has report' => true], 'topkb rows' => true],
        'destruction without death stars' => ['fleet' => ['status' => 1, 'ships' => '207,1000', 'ship_amount' => 1000, 'mission' => 9], 'planet' => ['metal' => 50000.0, 'crystal' => 0.0, 'deuterium' => 0.0, 'der_metal' => 0.0, 'der_crystal' => 0.0, 'tf_active' => 0], 'report rows' => 1, 'battle messages' => 2, 'log' => ['status' => 0, 'ships' => 1000, 'has report' => true], 'topkb rows' => true],
    ];
    // GOLDEN-END

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: int} attacker, defender, defender planet, fleet id */
    private function fight(int $mission, bool $moonTarget = false): array
    {
        $resource = $this->state->resource;
        [$attacker, $attackerPlanet] = $this->createPlayer(['computer_tech' => 5], ['metal' => 1e7, 'crystal' => 1e7, 'deuterium' => 1e7, $resource[207] => 1000]);
        [$defender, $defenderPlanet] = $this->createPlayer([], [$resource[202] => 1, 'metal' => 100000, 'crystal' => 50000, 'deuterium' => 20000]);
        $target = $defenderPlanet;
        if ($moonTarget) {
            $moonId = (new \App\Game\Core\PlayerUtil($this->db, $this->configs, $this->state))->createMoon(1, (int) $defenderPlanet['galaxy'], (int) $defenderPlanet['system'], (int) $defenderPlanet['planet'], (int) $defender['id'], 20.0, 'Moon', 8000);
            $this->db->update('UPDATE %%PLANETS%% SET ' . $resource[202] . ' = 1, metal = 100000 WHERE id = :id;', [':id' => $moonId]);
            $target = $this->loadPlanet($moonId);
        }
        $now = time();
        $this->state->USER = $attacker;
        $this->state->PLANET = $attackerPlanet;
        $fleetId = (new FleetFunctions($this->db, $this->configs, $this->state))->sendFleet(
            [207 => 1000],
            $mission,
            (int) $attacker['id'],
            (int) $attackerPlanet['id'],
            (int) $attackerPlanet['galaxy'],
            (int) $attackerPlanet['system'],
            (int) $attackerPlanet['planet'],
            1,
            (int) $defender['id'],
            (int) $target['id'],
            (int) $target['galaxy'],
            (int) $target['system'],
            (int) $target['planet'],
            $moonTarget ? 3 : 1,
            [901 => 0, 902 => 0, 903 => 0],
            $now - 100,
            $now - 100,
            $now - 50,
            0,
            0,
            0,
            0,
        );

        return [$attacker, $defender, $target, (int) $fleetId];
    }

    /** @return array<string, mixed> the pinned result rows of a finished fight */
    private function outcome(array $attacker, array $defender, array $target, int $fleetId): array
    {
        $fleet = $this->db->selectSingle('SELECT status, ships, ship_amount, mission FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);
        $planet = $this->db->selectSingle('SELECT metal, crystal, deuterium, der_metal, der_crystal, tf_active FROM %%PLANETS%% WHERE id = :id;', [':id' => $target['id']]);
        $rw = (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%RW%% WHERE attacker = :a;', [':a' => $attacker['id']], 'c');
        $msgs = (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS_TO_MESSAGES%% um JOIN %%MESSAGES%% m ON m.message_id = um.message_id WHERE m.message_type = :t AND um.user_id IN (:a, :d);', [':t' => MESSAGE_TYPE_BATTLE_REPORT, ':a' => $attacker['id'], ':d' => $defender['id']], 'c');
        $log = $this->db->selectSingle('SELECT status, ship_amount, report_id FROM %%LOG_FLEETS%% WHERE id = :id;', [':id' => $fleetId]);
        $topkb = (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%TOPKB%%;', [], 'c');

        return [
            'fleet' => array_map(static fn($v) => is_numeric($v) ? $v + 0 : $v, $fleet),
            'planet' => array_map(static fn($v) => is_numeric($v) ? $v + 0 : $v, $planet),
            'report rows' => $rw,
            'battle messages' => $msgs,
            'log' => ['status' => (int) $log['status'], 'ships' => (int) $log['ship_amount'], 'has report' => !empty($log['report_id'])],
            'topkb rows' => $topkb > 0,
        ];
    }

    public function testAttackOnAPlanet(): void
    {
        [$attacker, $defender, $target, $fleetId] = $this->fight(MISSION_ATTACK);
        $fleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);

        $this->attack($fleet)->TargetEvent();

        $this->goldenAssert('attack on a planet', $this->outcome($attacker, $defender, $target, $fleetId));
    }

    public function testAttackOnAMoon(): void
    {
        [$attacker, $defender, $target, $fleetId] = $this->fight(MISSION_ATTACK, true);
        $fleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);

        $this->attack($fleet)->TargetEvent();

        $this->goldenAssert('attack on a moon', $this->outcome($attacker, $defender, $target, $fleetId));
    }

    public function testDestructionWithoutDeathStarsKeepsTheMoon(): void
    {
        [$attacker, $defender, $target, $fleetId] = $this->fight(MISSION_DESTRUCTION, true);
        $fleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);

        $this->destruction($fleet, 100)->TargetEvent();

        $this->goldenAssert('destruction without death stars', $this->outcome($attacker, $defender, $target, $fleetId));
        $this->assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%PLANETS%% WHERE id = :id;', [':id' => $target['id']], 'c'), 'no death stars: the moon survives');
    }

    public function testDestructionLosesTheFleetWhenTheFleetRollHits(): void
    {
        [, , $target, $fleetId] = $this->fight(MISSION_DESTRUCTION, true);
        $fleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);

        $this->destruction($fleet, 1)->TargetEvent();

        $this->assertFalse($this->db->selectSingle('SELECT id FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]), 'the fleet roll hit: the fleet is gone');
        $this->assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%PLANETS%% WHERE id = :id;', [':id' => $target['id']], 'c'), 'no death stars: the moon survives');
    }

    /** Destruction mission whose chance rolls always return $roll (100 = no chance hits, 1 = every chance above 0 hits). */
    private function destruction(array $fleet, int $roll): MissionCaseDestruction
    {
        return new class ($fleet, $this->db, $this->configs, $this->state, $roll) extends MissionCaseDestruction {
            public function __construct(array $fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state, private int $forced)
            {
                parent::__construct($fleet, $db, $configs, $state);
            }

            protected function roll(int $min, int $max): int
            {
                return $this->forced;
            }
        };
    }

    /** Attack mission whose rolls never hit (moon creation stays off whatever the debris). */
    private function attack(array $fleet): MissionCaseAttack
    {
        return new class ($fleet, $this->db, $this->configs, $this->state) extends MissionCaseAttack {
            protected function roll(int $min, int $max): int
            {
                return $max;
            }
        };
    }
}
