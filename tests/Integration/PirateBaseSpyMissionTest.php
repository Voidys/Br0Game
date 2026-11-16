<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\FleetFunctions;
use App\Game\Missions\MissionCasePiratebaseSpy;
use App\Tests\Support\GameTestCase;

/**
 * Runs the pirate base espionage mission and pins the report it sends (JSON without the volatile roll and times)
 * and the probes' fate: they return, or are shot down (debris on the base) when the detection chance is 100 percent,
 * and return with a message when the base is gone. Golden values come from the code before TargetEvent() was split.
 */
final class PirateBaseSpyMissionTest extends GameTestCase
{
    // GOLDEN-BEGIN
    private const GOLDEN = [
        'report' => ['targetUserName' => 'Pirate base', 'stbEnabled' => 0, 'danger' => 35, 'dangerClass' => 'danger', 'smallCargoNeeded' => 2, 'largeCargoNeeded' => 1, 'energy' => 0, 'energyClass' => '', 'spyData' => [900 => [901 => 9000, 902 => 4000, 903 => 2000], 200 => [202 => 5, 203 => 2], 100 => [109 => 4, 110 => 2, 111 => 1]], 'repair_order' => [], 'targetChance' => 0, 'totalShipDefCount' => 7, 'isBattleSim' => true],
        'debris' => [0.0, 30000.0],
    ];
    // GOLDEN-END

    private int $fleetId = 0;

    /** @return array{0: array<string, mixed>, 1: int} the sender and the base id; the fleet id is in $this->fleetId */
    private function spy(int $probes, string $baseShips, bool $baseExists = true): array
    {
        $resource = $this->state->resource;
        [$sender, $senderPlanet] = $this->createPlayer(['spy_tech' => 8, 'military_tech' => 7, 'shield_tech' => 5, 'defence_tech' => 4], [$resource[210] => $probes, 'deuterium' => 1e6]);
        $this->db->insert('INSERT INTO %%PIRATE_BASES%% SET id_owner = 0, universe = 1, galaxy = :g, system = :s, planet_type = 4, metal = 9000, crystal = 4000, deuterium = 2000, ships = :ships;', [
            ':g' => $senderPlanet['galaxy'], ':s' => $senderPlanet['system'] + 1, ':ships' => $baseShips,
        ]);
        $baseId = $this->db->lastInsertId();
        $now = time();
        $this->state->USER = $sender;
        $this->state->PLANET = $senderPlanet;
        $id = (new FleetFunctions($this->db, $this->configs, $this->state))->sendFleet(
            [210 => $probes],
            MISSION_PIRATEBASE_SPY,
            (int) $sender['id'],
            (int) $senderPlanet['id'],
            (int) $senderPlanet['galaxy'],
            (int) $senderPlanet['system'],
            (int) $senderPlanet['planet'],
            1,
            0,
            $baseId,
            (int) $senderPlanet['galaxy'],
            (int) $senderPlanet['system'] + 1,
            1,
            4,
            [901 => 0, 902 => 0, 903 => 0],
            $now - 100,
            $now - 100,
            $now + 5000,
        );
        $this->fleetId = (int) $id;
        if (!$baseExists) {
            $this->db->delete('DELETE FROM %%PIRATE_BASES%% WHERE id = :id;', [':id' => $baseId]);
        }
        $fleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $id]);
        (new MissionCasePiratebaseSpy($fleet, $this->db, $this->configs, $this->state))->TargetEvent();

        return [$sender, $baseId];
    }

    /** @return array<string, mixed> the report of the sender without the volatile parts */
    private function report(array $sender): array
    {
        $text = (string) $this->db->selectSingle('SELECT m.message_text FROM %%MESSAGES%% m WHERE m.message_owner = :id AND m.message_type = :type ORDER BY m.message_id DESC LIMIT 1;', [':id' => $sender['id'], ':type' => MESSAGE_TYPE_SPY_REPORT], 'message_text');
        $data = json_decode($text, true);
        unset($data['spyChance'], $data['time'], $data['targetPlanet']);

        return $data;
    }

    public function testReportOfAPirateBase(): void
    {
        [$sender] = $this->spy(30, '202,5;203,2');

        $this->goldenAssert('report', $this->report($sender));
        $this->assertEquals(FLEET_RETURN, $this->db->selectSingle('SELECT status FROM %%FLEETS%% WHERE id = :id;', [':id' => $this->fleetId], 'status'));
    }

    public function testCertainDetectionShootsTheProbesDownAndLeavesDebris(): void
    {
        [$sender, $baseId] = $this->spy(100, '202,100000');

        $this->assertFalse($this->db->selectSingle('SELECT id FROM %%FLEETS%% WHERE id = :id;', [':id' => $this->fleetId]), 'the probes are gone');
        $base = $this->db->selectSingle('SELECT der_metal, der_crystal FROM %%PIRATE_BASES%% WHERE id = :id;', [':id' => $baseId]);
        $this->goldenAssert('debris', [(float) $base['der_metal'], (float) $base['der_crystal']]);
        $this->assertNotEmpty($this->report($sender));
    }

    public function testAVanishedBaseSendsTheProbesHome(): void
    {
        [$sender] = $this->spy(10, '202,5', false);

        $this->assertEquals(FLEET_RETURN, $this->db->selectSingle('SELECT status FROM %%FLEETS%% WHERE id = :id;', [':id' => $this->fleetId], 'status'));
        $this->assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%MESSAGES%% WHERE message_owner = :id AND message_type = :t;', [':id' => $sender['id'], ':t' => MESSAGE_TYPE_SPY_REPORT], 'c'), 'no spy report, only the "base gone" notice');
    }
}
