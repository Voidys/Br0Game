<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\AllianceDepot;
use App\Game\Core\AllianceDepotRefused;
use App\Game\Core\FleetString;
use App\Tests\Support\GameTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Game\Enum\Mission;
use App\Game\Enum\MessageType;

/**
 * Alliance Depot: the hourly holding cost, the stay extension of a holding fleet (fleet row, fleet event, deuterium,
 * message) and every refusal (not the depot owner, no depot, hours over the depot level or the stay cap, own fleet,
 * fleet elsewhere, not holding, stay over, event being processed, too little deuterium).
 */
final class AllianceDepotTest extends GameTestCase
{
    /** @var list<int> fleets inserted by the test (the depot code commits, so the rollback does not remove them) */
    private array $fleetIds = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->fleetIds as $id) {
            $this->db->delete('DELETE FROM %%FLEETS_EVENT%% WHERE fleetID = :id;', [':id' => $id]);
            $this->db->delete('DELETE FROM %%FLEETS%% WHERE id = :id;', [':id' => $id]);
        }
        $this->fleetIds = [];
    }

    private function depot(): AllianceDepot
    {
        return new AllianceDepot($this->db, $this->configs, $this->state);
    }

    /**
     * Inserts a fleet of $owner holding at $planet: stays $stayLeft seconds from now, returns $stayLeft + 3600 later.
     *
     * @param array<string, mixed> $owner
     * @param array<string, mixed> $planet
     * @param array<string, mixed> $override fleets columns to overwrite
     */
    private function holdFleet(array $owner, array $planet, int $stayLeft, string $ships = '202,10', array $override = [], ?int $eventLock = null): int
    {
        $now = $this->state->runtime->timestamp;
        $row = $override + [
            'universe' => 1,
            'mission' => Mission::Hold->value,
            'owner' => $owner['id'],
            'target_owner' => $planet['id_owner'],
            'ship_amount' => 10,
            'ships' => $ships,
            'start_ships' => $ships,
            'start_time' => $now - 7200,
            'arrival_time' => $now - 3600,
            'stay_time' => $now + $stayLeft,
            'return_time' => $now + $stayLeft + 3600,
            'start_id' => $owner['id'],
            'end_id' => $planet['id'],
            'end_galaxy' => $planet['galaxy'],
            'end_system' => $planet['system'],
            'end_planet' => $planet['planet'],
            'end_type' => 1,
            'status' => FLEET_HOLD,
        ];
        $columns = array_keys($row);
        $this->db->insert(
            'INSERT INTO %%FLEETS%% (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)) . ');',
            array_combine(array_map(static fn(string $c): string => ':' . $c, $columns), array_values($row)),
        );
        $id = (int) $this->db->lastInsertId();
        $this->fleetIds[] = $id;
        $this->db->insert('INSERT INTO %%FLEETS_EVENT%% (`fleetID`, `time`, `universe`, `lock`) VALUES (:id, :time, 1, :lock);', [
            ':id' => $id,
            ':time' => $row['stay_time'],
            ':lock' => $eventLock === null ? null : 'token',
        ]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function fleet(int $id): array
    {
        return $this->db->selectSingle('SELECT f.*, fe.`time` AS event_time FROM %%FLEETS%% f INNER JOIN %%FLEETS_EVENT%% fe ON fe.fleetID = f.id WHERE f.id = :id;', [':id' => $id]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} depot owner, depot planet, fleet owner */
    private function scenario(int $level = 3, int $deuterium = 1000): array
    {
        [$owner, $planet] = $this->createPlayer([], ['ally_deposit' => $level, 'deuterium' => $deuterium]);
        [$guest] = $this->createPlayer();

        return [$owner, $planet, $guest];
    }

    private function refusedKey(callable $call): string
    {
        try {
            $call();
        } catch (AllianceDepotRefused $refused) {
            return $refused->getMessage();
        }
        $this->fail('The supply was not refused.');
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function hourlyCosts(): array
    {
        return [
            'nothing' => ['', 0],
            '10 small cargo (consumption 10 each)' => ['202,10', 10],
            'one small cargo rounds up' => ['202,1', 1],
            '3 small cargo' => ['202,3', 3],
            'light fighter 20 each' => ['204,1', 2],
            'sum of several types' => ['202,10;203,4;204,5', 40],
            'impulse ship uses its own drive: 7 heavy fighters 75 each' => ['205,7', 53],
            'hyperspace ship: one death star 1' => ['214,1', 1],
        ];
    }

    #[DataProvider('hourlyCosts')]
    public function testHourlyCostIsTenthOfBaseConsumptionRoundedUp(string $ships, int $expected): void
    {
        $fleet = (new FleetString())->unserialize($ships);
        $this->assertSame($expected, $this->depot()->hourlyCost($fleet));
    }

    public function testExtensionMovesFleetAndEventAndPaysDeuterium(): void
    {
        [$owner, $planet, $guest] = $this->scenario(3, 1000);
        $now = $this->state->runtime->timestamp;
        $id = $this->holdFleet($guest, $planet, 3600);

        $result = $this->depot()->extend((int) $planet['id'], (int) $owner['id'], $id, 2);

        $this->assertSame(['hours' => 2, 'cost' => 20, 'fleetOwner' => (int) $guest['id']], ['hours' => $result['hours'], 'cost' => $result['cost'], 'fleetOwner' => $result['fleetOwner']]);
        $fleet = $this->fleet($id);
        $this->assertSame($now + 3600 + 7200, (int) $fleet['stay_time']);
        $this->assertSame($now + 7200 + 7200, (int) $fleet['return_time']);
        $this->assertSame((int) $fleet['stay_time'], (int) $fleet['event_time'], 'the event of a holding fleet is its stay end');
        $this->assertSame($now - 3600, (int) $fleet['arrival_time']);
        $this->assertSame(FLEET_HOLD, (int) $fleet['status']);
        $this->assertSame('202,10', $fleet['ships']);
        $this->assertEquals(980, $this->loadPlanet((int) $planet['id'])['deuterium']);
    }

    public function testFleetCanBeSuppliedRepeatedly(): void
    {
        [$owner, $planet, $guest] = $this->scenario(3, 1000);
        $now = $this->state->runtime->timestamp;
        $id = $this->holdFleet($guest, $planet, 3600);

        $this->depot()->extend((int) $planet['id'], (int) $owner['id'], $id, 3);
        $this->depot()->extend((int) $planet['id'], (int) $owner['id'], $id, 1);

        $this->assertSame($now + 3600 + 4 * 3600, (int) $this->fleet($id)['stay_time']);
        $this->assertEquals(960, $this->loadPlanet((int) $planet['id'])['deuterium']);
    }

    public function testFleetOwnerGetsAMessage(): void
    {
        [$owner, $planet, $guest] = $this->scenario();
        $id = $this->holdFleet($guest, $planet, 3600);

        $extension = $this->depot()->extend((int) $planet['id'], (int) $owner['id'], $id, 1);
        $this->depot()->notifyFleetOwner($extension, 'Depotowner');

        $text = (string) $this->db->selectSingle(
            'SELECT m.message_text FROM %%MESSAGES%% m WHERE m.message_owner = :id AND m.message_type = :type ORDER BY m.message_id DESC LIMIT 1;',
            [':id' => $guest['id'], ':type' => MessageType::System->value],
            'message_text',
        );
        $this->assertStringContainsString('Depotowner', $text);
        $this->assertStringContainsString('1 h', $text);
        $this->assertStringContainsString('[' . $planet['galaxy'] . ':' . $planet['system'] . ':' . $planet['planet'] . ']', $text);
    }

    public function testTooLittleDeuteriumIsRefusedAndNothingChanges(): void
    {
        [$owner, $planet, $guest] = $this->scenario(3, 25);
        $id = $this->holdFleet($guest, $planet, 3600);
        $before = $this->fleet($id);

        $key = $this->refusedKey(fn() => $this->depot()->extend((int) $planet['id'], (int) $owner['id'], $id, 3));

        $this->assertSame('depot_err_deuterium', $key);
        $this->assertSame($before, $this->fleet($id));
        $this->assertEquals(25, $this->loadPlanet((int) $planet['id'])['deuterium']);
        // exactly enough is fine
        $this->depot()->extend((int) $planet['id'], (int) $owner['id'], $id, 2);
        $this->assertEquals(5, $this->loadPlanet((int) $planet['id'])['deuterium']);
    }

    public function testOnlyTheOwnerOfAPlanetWithADepotCanSupply(): void
    {
        [$owner, $planet, $guest] = $this->scenario();
        $id = $this->holdFleet($guest, $planet, 3600);

        $this->assertSame('depot_err_not_owner', $this->refusedKey(fn() => $this->depot()->extend((int) $planet['id'], (int) $guest['id'], $id, 1)));

        [$plain, $plainPlanet] = $this->createPlayer([], ['deuterium' => 1000]);
        $plainId = $this->holdFleet($guest, $plainPlanet, 3600);
        $this->assertSame('depot_err_no_depot', $this->refusedKey(fn() => $this->depot()->extend((int) $plainPlanet['id'], (int) $plain['id'], $plainId, 1)));
    }

    public function testFleetsOfTheDepotOwnerOrOtherPlanetsAreRefused(): void
    {
        [$owner, $planet, $guest] = $this->scenario();
        $own = $this->holdFleet($owner, $planet, 3600);
        $this->assertSame('depot_err_fleet', $this->refusedKey(fn() => $this->depot()->extend((int) $planet['id'], (int) $owner['id'], $own, 1)));

        [, $otherPlanet] = $this->createPlayer([], ['ally_deposit' => 2, 'deuterium' => 1000]);
        $elsewhere = $this->holdFleet($guest, $otherPlanet, 3600);
        $this->assertSame('depot_err_fleet', $this->refusedKey(fn() => $this->depot()->extend((int) $planet['id'], (int) $owner['id'], $elsewhere, 1)));
        $this->assertSame('depot_err_fleet', $this->refusedKey(fn() => $this->depot()->extend((int) $planet['id'], (int) $owner['id'], 999999999, 1)));
    }

    public function testFleetsThatAreNotHoldingAreRefused(): void
    {
        [$owner, $planet, $guest] = $this->scenario();
        $flying = $this->holdFleet($guest, $planet, 3600, '202,10', ['status' => FLEET_OUTWARD]);
        $returning = $this->holdFleet($guest, $planet, 3600, '202,10', ['status' => FLEET_RETURN]);
        $otherMission = $this->holdFleet($guest, $planet, 3600, '202,10', ['mission' => Mission::Transport->value]);
        $over = $this->holdFleet($guest, $planet, -10);

        foreach ([$flying, $returning, $otherMission, $over] as $id) {
            $before = $this->fleet($id);
            $this->assertSame('depot_err_fleet', $this->refusedKey(fn() => $this->depot()->extend((int) $planet['id'], (int) $owner['id'], $id, 1)));
            $this->assertSame($before, $this->fleet($id));
        }
        $this->assertEquals(1000, $this->loadPlanet((int) $planet['id'])['deuterium']);
    }

    public function testFleetWhoseEventIsBeingProcessedIsRefused(): void
    {
        [$owner, $planet, $guest] = $this->scenario();
        $id = $this->holdFleet($guest, $planet, 3600, '202,10', [], 1);
        $before = $this->fleet($id);

        $this->assertSame('depot_err_fleet', $this->refusedKey(fn() => $this->depot()->extend((int) $planet['id'], (int) $owner['id'], $id, 1)));
        $this->assertSame($before, $this->fleet($id));
    }

    public function testHoursAreLimitedByTheDepotLevel(): void
    {
        [$owner, $planet, $guest] = $this->scenario(2);
        $id = $this->holdFleet($guest, $planet, 3600);

        foreach ([0, 3, -1] as $hours) {
            $this->assertSame('depot_err_hours', $this->refusedKey(fn() => $this->depot()->extend((int) $planet['id'], (int) $owner['id'], $id, $hours)));
        }
        $this->depot()->extend((int) $planet['id'], (int) $owner['id'], $id, 2);
        $this->assertSame($this->state->runtime->timestamp + 3600 + 7200, (int) $this->fleet($id)['stay_time']);
    }

    public function testStayLeftNeverExceedsTheLargestHoldDuration(): void
    {
        $depot = $this->depot();
        $this->assertSame(32, $depot->maxRemainingStayHours());

        [$owner, $planet, $guest] = $this->scenario(5);
        $id = $this->holdFleet($guest, $planet, 31 * 3600);

        $this->assertSame('depot_err_max_stay', $this->refusedKey(fn() => $depot->extend((int) $planet['id'], (int) $owner['id'], $id, 2)));
        $depot->extend((int) $planet['id'], (int) $owner['id'], $id, 1);
        $this->assertSame('depot_err_max_stay', $this->refusedKey(fn() => $depot->extend((int) $planet['id'], (int) $owner['id'], $id, 1)));
        $this->assertLessThanOrEqual(32 * 3600, (int) $this->fleet($id)['stay_time'] - $this->state->runtime->timestamp);
    }

    public function testAllowedHoursAndListing(): void
    {
        [$owner, $planet, $guest] = $this->scenario(4);
        $now = $this->state->runtime->timestamp;
        $depot = $this->depot();

        $this->assertSame(4, $depot->allowedHours(4, $now + 3600, $now));
        $this->assertSame(2, $depot->allowedHours(4, $now + 30 * 3600, $now));
        $this->assertSame(0, $depot->allowedHours(4, $now + 32 * 3600, $now));
        $this->assertSame(0, $depot->allowedHours(4, $now + 32 * 3600 - 1, $now), 'a partial hour of room is not enough');

        $this->assertSame([], $depot->holdingFleets((int) $planet['id'], (int) $owner['id'], 4));
        $late = $this->holdFleet($guest, $planet, 7200, '204,5');
        $soon = $this->holdFleet($guest, $planet, 3600);
        $this->holdFleet($owner, $planet, 3600);
        $this->holdFleet($guest, $planet, 3600, '202,10', ['status' => FLEET_OUTWARD]);

        $list = $depot->holdingFleets((int) $planet['id'], (int) $owner['id'], 4);
        $this->assertSame([$soon, $late], array_column($list, 'id'));
        $this->assertSame([10, 10], array_column($list, 'hourlyCost'));
        $this->assertSame([4, 4], array_column($list, 'maxHours'));
        $this->assertSame($guest['username'], $list[0]['username']);
        $this->assertEquals(['202' => 10], $list[0]['ships']);
    }
}
