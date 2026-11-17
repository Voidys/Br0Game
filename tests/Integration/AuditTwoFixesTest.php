<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Game\FleetTableController;
use App\Game\Core\FleetFunctions;
use App\Game\Core\FleetLauncher;
use App\Game\Core\Language;
use App\Game\Missions\MissionCaseMIP;
use App\Tests\Support\GameTestCase;

/**
 * Second audit round: an ACS group recall is all-or-nothing (R-04) and missile arrivals never take a planet's
 * defense below zero (R-08).
 */
final class AuditTwoFixesTest extends GameTestCase
{
    /** @var list<int> */
    private array $fleetIds = [];

    /** @var list<int> */
    private array $acsIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $lng = new Language($this->configs, 'en');
        $lng->includeData(['L18N', 'INGAME', 'TECH', 'FLEET', 'CUSTOM']);
        $this->state->LNG = $lng;
    }

    protected function tearDown(): void
    {
        foreach ($this->fleetIds as $id) {
            foreach (['FLEETS_EVENT' => 'fleetID', 'LOG_FLEETS' => 'id', 'FLEETS' => 'id'] as $table => $column) {
                $this->db->delete("DELETE FROM %%$table%% WHERE $column = :id;", [':id' => $id]);
            }
        }
        foreach ($this->acsIds as $id) {
            $this->db->delete('DELETE FROM %%USERS_TO_ACS%% WHERE acsID = :id;', [':id' => $id]);
            $this->db->delete('DELETE FROM %%AKS%% WHERE id = :id;', [':id' => $id]);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $target
     * @return array{0: array<string, mixed>, 1: int} sender, fleet id of an outgoing attack that has not arrived
     */
    private function attackFleet(array $target): array
    {
        [$user, $planet] = $this->createPlayer([], ['small_ship_cargo' => 20, 'deuterium' => 10000]);
        $now = $this->state->runtime->timestamp;
        $id = (new FleetLauncher($this->db, $this->configs, $this->state))->sendFleet(
            [202 => 5],
            MISSION_ATTACK,
            (int) $user['id'],
            (int) $planet['id'],
            (int) $planet['galaxy'],
            (int) $planet['system'],
            (int) $planet['planet'],
            1,
            (int) $target['id_owner'],
            (int) $target['id'],
            (int) $target['galaxy'],
            (int) $target['system'],
            (int) $target['planet'],
            1,
            [901 => 0, 902 => 0, 903 => 0],
            $now + 3600,
            0,
            $now + 7200,
        );
        $this->fleetIds[] = $id;

        return [$user, $id];
    }

    public function testGroupRecallIsRolledBackWhenAMemberIsClaimed(): void
    {
        [, $target] = $this->createPlayer();
        [$creator, $leadFleet] = $this->attackFleet($target);
        [$member] = $this->createPlayer();

        $this->state->USER = $creator;
        $this->state->requestInput = ['username' => $member['username']];
        (new FleetTableController())->withGameServices($this->db, $this->configs, $this->state)->getACSPageData($leadFleet);
        $acsId = (int) $this->db->selectSingle('SELECT `group` FROM %%FLEETS%% WHERE id = :id;', [':id' => $leadFleet], 'group');
        $this->acsIds[] = $acsId;

        [, $memberFleet] = $this->attackFleet($target);
        $this->db->update('UPDATE %%FLEETS%% SET `owner` = :o, `group` = :g WHERE id = :id;', [':o' => $member['id'], ':g' => $acsId, ':id' => $memberFleet]);
        // the event handler claimed the member's arrival event in the meantime
        $this->db->update('UPDATE %%FLEETS_EVENT%% SET `lock` = :l WHERE fleetID = :id;', [':l' => 'claimed', ':id' => $memberFleet]);

        $launcher = new FleetLauncher($this->db, $this->configs, $this->state);
        $this->assertFalse($launcher->sendFleetBack($creator, $leadFleet), 'the recall is reported as failed');

        $this->assertSame(FLEET_OUTWARD, (int) $this->db->selectSingle('SELECT status FROM %%FLEETS%% WHERE id = :id;', [':id' => $leadFleet], 'status'), 'no fleet was flipped');
        $this->assertSame($acsId, (int) $this->db->selectSingle('SELECT `group` FROM %%FLEETS%% WHERE id = :id;', [':id' => $leadFleet], 'group'));
        $this->assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%AKS%% WHERE id = :a;', [':a' => $acsId], 'c'), 'the group survives');

        // once the member is free again the recall goes through and dissolves the group
        $this->db->update('UPDATE %%FLEETS_EVENT%% SET `lock` = NULL WHERE fleetID = :id;', [':id' => $memberFleet]);
        $this->assertTrue($launcher->sendFleetBack($creator, $leadFleet));
        $this->assertSame(FLEET_RETURN, (int) $this->db->selectSingle('SELECT status FROM %%FLEETS%% WHERE id = :id;', [':id' => $memberFleet], 'status'));
        $this->assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%AKS%% WHERE id = :a;', [':a' => $acsId], 'c'));
    }

    public function testSequentialMissileVolleysNeverTakeDefenseBelowZero(): void
    {
        $resource = $this->elements()->columnMap();
        [$sender, $senderPlanet] = $this->createPlayer(['impulse_motor_tech' => 5], [$resource[503] => 40, 'deuterium' => 1e6]);
        [$owner, $ownerPlanet] = $this->createPlayer([], [$resource[401] => 3, $resource[502] => 4]);

        foreach ([6, 6] as $amount) {
            $now = time();
            $this->state->USER = $sender;
            $this->state->PLANET = $senderPlanet;
            $id = (new FleetFunctions($this->db, $this->configs, $this->state))->sendFleet(
                [503 => $amount],
                MISSION_MISSILE,
                (int) $sender['id'],
                (int) $senderPlanet['id'],
                (int) $senderPlanet['galaxy'],
                (int) $senderPlanet['system'],
                (int) $senderPlanet['planet'],
                1,
                (int) $owner['id'],
                (int) $ownerPlanet['id'],
                (int) $ownerPlanet['galaxy'],
                (int) $ownerPlanet['system'],
                (int) $ownerPlanet['planet'],
                1,
                [901 => 0, 902 => 0, 903 => 0],
                $now - 100,
                $now - 100,
                $now + 5000,
                0,
                401,
            );
            $this->fleetIds[] = $id;
            $fleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $id]);
            (new MissionCaseMIP($fleet, $this->db, $this->configs, $this->state))->TargetEvent();

            $planet = $this->loadPlanet((int) $ownerPlanet['id']);
            $this->assertGreaterThanOrEqual(0, (int) $planet[$resource[401]]);
            $this->assertGreaterThanOrEqual(0, (int) $planet[$resource[502]]);
        }

        $planet = $this->loadPlanet((int) $ownerPlanet['id']);
        $this->assertSame(0, (int) $planet[$resource[502]], 'interceptors used up by the first volley');
        $this->assertSame(0, (int) $planet[$resource[401]], 'the second volley destroyed the rest');
    }
}
