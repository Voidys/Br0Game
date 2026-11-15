<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\AcsService;
use App\Game\Core\FleetFunctions;
use App\Game\Core\FleetLauncher;
use App\Game\Core\FleetStatistics;
use App\Game\Core\ResourceProduction;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\StoredData;
use App\Game\Core\WreckfieldMaintenance;
use App\Tests\Support\GameTestCase;

/**
 * Behaviour of the services split out of FleetFunctions and ResourceUpdate that the golden suites do not reach:
 * the rows written by a fleet launch, recall timing (outward flight, holding fleet, forbidden recalls, ACS group),
 * ACS group time shifting, the flight slot counter, the production clamps of ResourceProduction and the 72 h
 * wreckfield expiry.
 *
 * Expected values are derived from the game rules, not captured: return time of a recalled flight = now + time
 * already flown, a holding fleet returns after its outward duration, a launch takes ships, cargo and fuel off the planet.
 */
final class FleetLauncherTest extends GameTestCase
{
    private function launcher(): FleetLauncher
    {
        return new FleetLauncher($this->db, $this->configs, $this->state);
    }

    /**
     * Launches 5 small cargo ships with cargo and fuel from a rich planet to another player's planet.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: int} sender, sender planet, target planet, fleet id
     */
    private function launch(int $mission = MISSION_TRANSPORT, int $group = 0): array
    {
        [$user, $planet] = $this->createPlayer([], ['small_ship_cargo' => 20, 'metal' => 10000, 'crystal' => 10000, 'deuterium' => 10000]);
        [$targetUser, $target] = $this->createPlayer();
        $now = $this->state->runtime->timestamp;

        $fleetId = $this->launcher()->sendFleet(
            [202 => 5],
            $mission,
            (int) $user['id'],
            (int) $planet['id'],
            (int) $planet['galaxy'],
            (int) $planet['system'],
            (int) $planet['planet'],
            1,
            (int) $targetUser['id'],
            (int) $target['id'],
            (int) $target['galaxy'],
            (int) $target['system'],
            (int) $target['planet'],
            1,
            [901 => 1000, 902 => 500, 903 => 100],
            $now + 600,
            0,
            $now + 1200,
            $group,
            0,
            0,
            50.0,
        );

        return [$user, $planet, $target, $fleetId];
    }

    public function testSendFleetTakesShipsCargoAndFuelAndWritesFlightEventAndLog(): void
    {
        [$user, $planet, , $fleetId] = $this->launch();
        $now = $this->state->runtime->timestamp;

        $after = $this->loadPlanet((int) $planet['id']);
        $this->assertSame(15, (int) $after['small_ship_cargo']);
        $this->assertSame(10000 - 1000, (int) $after['metal']);
        $this->assertSame(10000 - 500, (int) $after['crystal']);
        $this->assertSame(10000 - 100 - 50, (int) $after['deuterium'], 'fuel comes on top of the deuterium cargo');

        $fleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);
        $this->assertSame('202,5', $fleet['ships']);
        $this->assertSame(5, (int) $fleet['ship_amount']);
        $this->assertSame((int) $user['id'], (int) $fleet['owner']);
        $this->assertSame($now, (int) $fleet['start_time']);
        $this->assertSame($now + 600, (int) $fleet['arrival_time']);
        $this->assertSame($now + 1200, (int) $fleet['return_time']);
        $this->assertSame(50, (int) $fleet['fuel_cost']);
        $this->assertSame([1000, 500, 100], [(int) $fleet['resource_metal'], (int) $fleet['resource_crystal'], (int) $fleet['resource_deuterium']]);

        $event = $this->db->selectSingle('SELECT * FROM %%FLEETS_EVENT%% WHERE fleetID = :id;', [':id' => $fleetId]);
        $this->assertSame($now + 600, (int) $event['time'], 'the first event is the arrival');

        $log = $this->db->selectSingle('SELECT * FROM %%LOG_FLEETS%% WHERE id = :id;', [':id' => $fleetId]);
        $this->assertSame($user['username'], $log['owner_name']);
        $this->assertSame('202,5', $log['ships']);
        $this->assertMatchesRegularExpression('/^[^;]+;[^;]+;[^;]+$/', $log['current_market_rate']);
    }

    public function testSlotCounterCountsFlightsOutsideOrOfAMission(): void
    {
        [$user] = $this->launch();

        $launcher = $this->launcher();
        $this->assertSame(1, $launcher->getCurrentFleets((int) $user['id']));
        $this->assertSame(1, $launcher->getCurrentFleets((int) $user['id'], MISSION_EXPEDITION));
        $this->assertSame(0, $launcher->getCurrentFleets((int) $user['id'], MISSION_EXPEDITION, true));
        $this->assertSame(1, $launcher->getCurrentFleets((int) $user['id'], MISSION_TRANSPORT, true));
    }

    public function testRecallReturnsAfterTheTimeAlreadyFlown(): void
    {
        [$user, , , $fleetId] = $this->launch();
        $now = $this->state->runtime->timestamp;
        $this->db->update('UPDATE %%FLEETS%% SET start_time = :t WHERE id = :id;', [':t' => $now - 100, ':id' => $fleetId]);

        $this->assertTrue($this->launcher()->sendFleetBack($user, $fleetId));

        $fleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);
        $this->assertSame(FLEET_RETURN, (int) $fleet['status']);
        $this->assertSame($now + 100, (int) $fleet['return_time'], 'flown 100 s outward, so 100 s back');
        $this->assertSame(1, (int) $fleet['hasCanceled']);
        $event = $this->db->selectSingle('SELECT * FROM %%FLEETS_EVENT%% WHERE fleetID = :id;', [':id' => $fleetId]);
        $this->assertSame($now + 100, (int) $event['time']);
        $log = $this->db->selectSingle('SELECT * FROM %%LOG_FLEETS%% WHERE id = :id;', [':id' => $fleetId]);
        $this->assertSame($now + 100, (int) $log['return_time']);
        $this->assertSame(FLEET_RETURN, (int) $log['status']);

        $this->assertFalse($this->launcher()->sendFleetBack($user, $fleetId), 'a returning fleet cannot be recalled again');
    }

    public function testRecallOfAHoldingFleetReturnsAfterItsOutwardDuration(): void
    {
        [$user, , , $fleetId] = $this->launch(MISSION_HOLD);
        $now = $this->state->runtime->timestamp;
        $this->db->update('UPDATE %%FLEETS%% SET start_time = :s, arrival_time = :a, status = :st WHERE id = :id;', [
            ':s' => $now - 1000,
            ':a' => $now - 400,
            ':st' => FLEET_HOLD,
            ':id' => $fleetId,
        ]);

        $this->assertTrue($this->launcher()->sendFleetBack($user, $fleetId));

        $fleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);
        $this->assertSame($now + 600, (int) $fleet['return_time'], 'outward duration (arrival - start) counted from now');
    }

    public function testRecallIsRefusedForForeignMissileAndNonCancellableFlights(): void
    {
        [$user, , $target, $fleetId] = $this->launch();
        $other = $this->loadUser((int) $target['id_owner']);

        $this->assertFalse($this->launcher()->sendFleetBack($other, $fleetId), 'only the owner may recall');

        $this->db->update('UPDATE %%FLEETS%% SET cannotCancel = 1 WHERE id = :id;', [':id' => $fleetId]);
        $this->assertFalse($this->launcher()->sendFleetBack($user, $fleetId));

        $this->db->update('UPDATE %%FLEETS%% SET cannotCancel = 0, mission = :m WHERE id = :id;', [':m' => MISSION_MISSILE, ':id' => $fleetId]);
        $this->assertFalse($this->launcher()->sendFleetBack($user, $fleetId), 'missiles cannot be recalled');

        $this->assertFalse($this->launcher()->sendFleetBack($user, 999999), 'unknown flight');
    }

    public function testRecallOfAnAcsGroupMemberRecallsTheWholeGroupWithItsOwnTimes(): void
    {
        $now = $this->state->runtime->timestamp;
        $this->db->insert('INSERT INTO %%AKS%% SET public_id = :p, name = :n, target = 1, ankunft = :a;', [':p' => 'abcdefabcdefabcdefabcdefabcdef12', ':n' => 'test', ':a' => $now + 500]);
        $acsId = (int) $this->db->lastInsertId();
        [$user, , , $first] = $this->launch(MISSION_ATTACK, $acsId);
        [, , , $second] = $this->launch(MISSION_ACS, $acsId);
        $this->db->insert('INSERT INTO %%USERS_TO_ACS%% SET userID = :u, acsID = :a;', [':u' => $user['id'], ':a' => $acsId]);
        $this->db->update('UPDATE %%FLEETS%% SET start_time = :t WHERE id = :id;', [':t' => $now - 100, ':id' => $first]);
        $this->db->update('UPDATE %%FLEETS%% SET start_time = :t WHERE id = :id;', [':t' => $now - 300, ':id' => $second]);

        $this->assertTrue($this->launcher()->sendFleetBack($user, $first));

        $rows = $this->db->select('SELECT id, return_time, `group`, status FROM %%FLEETS%% WHERE id IN (:a, :b);', [':a' => $first, ':b' => $second]);
        $byId = array_column($rows, null, 'id');
        $this->assertSame($now + 100, (int) $byId[$first]['return_time']);
        $this->assertSame($now + 300, (int) $byId[$second]['return_time']);
        $this->assertSame([FLEET_RETURN, FLEET_RETURN], [(int) $byId[$first]['status'], (int) $byId[$second]['status']]);
        $this->assertSame([0, 0], [(int) $byId[$first]['group'], (int) $byId[$second]['group']]);
        $this->assertEmpty($this->db->selectSingle('SELECT id FROM %%AKS%% WHERE id = :id;', [':id' => $acsId]), 'the group is dissolved');
    }

    public function testAcsGroupTimeIsShiftedForTheGroupFlightsOnly(): void
    {
        $now = $this->state->runtime->timestamp;
        $this->db->insert('INSERT INTO %%AKS%% SET public_id = :p, name = :n, target = 1, ankunft = :a;', [':p' => '12345678123456781234567812345678', ':n' => 'shift', ':a' => $now + 500]);
        $acsId = (int) $this->db->lastInsertId();
        [, , , $grouped] = $this->launch(MISSION_ACS, $acsId);
        [, , , $solo] = $this->launch();

        $acs = new AcsService($this->db, $this->configs, $this->state);
        $this->assertSame(500, $acs->getACSDuration($acsId));
        $this->assertSame(0, $acs->getACSDuration(0));
        $this->assertTrue($acs->setACSTime(120, $acsId));
        $this->assertSame(620, $acs->getACSDuration($acsId));

        $groupedRow = $this->db->selectSingle('SELECT arrival_time, return_time FROM %%FLEETS%% WHERE id = :id;', [':id' => $grouped]);
        $soloRow = $this->db->selectSingle('SELECT arrival_time, return_time FROM %%FLEETS%% WHERE id = :id;', [':id' => $solo]);
        $this->assertSame([$now + 720, $now + 1320], [(int) $groupedRow['arrival_time'], (int) $groupedRow['return_time']]);
        $this->assertSame([$now + 600, $now + 1200], [(int) $soloRow['arrival_time'], (int) $soloRow['return_time']]);
        $event = $this->db->selectSingle('SELECT `time` FROM %%FLEETS_EVENT%% WHERE fleetID = :id;', [':id' => $grouped]);
        $this->assertSame($now + 720, (int) $event['time']);
        $log = $this->db->selectSingle('SELECT arrival_time FROM %%LOG_FLEETS%% WHERE id = :id;', [':id' => $grouped]);
        $this->assertSame($now + 720, (int) $log['arrival_time']);

        $this->expectException(\InvalidArgumentException::class);
        $acs->setACSTime(10, 0);
    }

    public function testFacadeDelegatesFleetStringAndStatistics(): void
    {
        $facade = new FleetFunctions($this->db, $this->configs, $this->state);
        $this->assertSame('202,5;204,3', $facade->serialize([202 => 5, 204 => 3, 205 => 0]));
        $this->assertSame([202 => 5], $facade->unserialize('202,2;202,3'));

        [$user] = $this->launch();
        $state = (new FleetStatistics($this->db, $this->configs, $this->state))->calculateCurrentTotalFleetState((int) $user['id']);
        $this->assertSame($state, $facade->calculateCurrentTotalFleetState((int) $user['id']));
        $this->assertGreaterThan(0, $state['totalUnits'], 'the 20 ships (15 docked + 5 flying) count');
        $this->assertSame(100, $facade->calculateMaxFactor(1), 'no statistics yet: lowest tier');
        $this->assertSame(200, $facade->calculateMaxFactorRes(1));
    }

    public function testProductionClampsToStorageFloorsConsumptionAndSkipsMoons(): void
    {
        [$user, $planet] = $this->createPlayer();
        $config = $this->configs->get(1);
        $production = new ResourceProduction($this->db, $this->configs, $this->state);
        $builded = [RESOURCE_METAL => 0.0, RESOURCE_CRYSTAL => 0.0, RESOURCE_DEUT => 0.0];

        $planet = array_merge($planet, [
            'metal' => 900, 'metal_max' => 1000, 'metal_perhour' => 3600,
            'crystal' => 50, 'crystal_max' => 1000, 'crystal_perhour' => 0,
            'deuterium' => 10, 'deuterium_max' => 1000, 'deuterium_perhour' => -3600 * 100,
        ]);
        $config->metal_basic_income = 0;
        $config->crystal_basic_income = 0;
        $config->deuterium_basic_income = 0;

        $result = $production->produce($planet, 3600, $config, $builded);

        $this->assertEquals(1000, $result['metal'], 'capped at storage (max_overflow 1)');
        $this->assertEquals(100.0, $builded[RESOURCE_METAL]);
        $this->assertEquals(50, $result['crystal']);
        $this->assertEquals(0, $result['deuterium'], 'consumption never goes below zero');
        $this->assertEquals(-10.0, $builded[RESOURCE_DEUT]);

        $moon = $production->produce(array_merge($planet, ['planet_type' => 3]), 3600, $config, $builded);
        $this->assertEquals(900, $moon['metal'], 'moons produce nothing');
    }

    public function testEcoHashChangesWithBuildingLevelsAndMultipliers(): void
    {
        [$user, $planet] = $this->createPlayer();
        $config = $this->configs->get(1);
        $production = new ResourceProduction($this->db, $this->configs, $this->state);

        $hash = $production->createHash($user, $planet, $config);
        $this->assertSame($hash, $production->createHash($user, $planet, $config));
        $this->assertNotSame($hash, $production->createHash($user, ['metal_mine' => $planet['metal_mine'] + 1] + $planet, $config));
        $this->assertNotSame($hash, $production->createHash(['energy_tech' => $user['energy_tech'] + 1] + $user, $planet, $config));
        $changed = $production->createHash($user, $planet, $config);
        $config->resource_multiplier = 5000;
        $this->assertNotSame($changed, $production->createHash($user, $planet, $config));
    }

    public function testWreckfieldExpiresAfter72HoursKeepingARunningRepairOrder(): void
    {
        [, $planet] = $this->createPlayer();
        $now = $this->state->runtime->timestamp;
        $maintenance = new WreckfieldMaintenance($this->db, $this->configs, $this->state);
        $this->db->insert('INSERT INTO %%PLANET_WRECKFIELD%% SET planetID = :p, created = :c, ships = :s, repair_order = :r;', [
            ':p' => $planet['id'], ':c' => $now - TIME_72_HOURS - 10, ':s' => '202,5', ':r' => '',
        ]);

        $this->assertFalse($maintenance->expire((int) $planet['id'], $now - 3600), 'younger than 72 h at that time');
        $this->assertTrue($maintenance->expire((int) $planet['id'], $now));
        $this->assertEmpty($this->db->selectSingle('SELECT planetID FROM %%PLANET_WRECKFIELD%% WHERE planetID = :p;', [':p' => $planet['id']]));

        $this->db->insert('INSERT INTO %%PLANET_WRECKFIELD%% SET planetID = :p, created = :c, ships = :s, repair_order = :r;', [
            ':p' => $planet['id'], ':c' => $now - TIME_72_HOURS - 10, ':s' => '202,5', ':r' => '{"202":3}',
        ]);
        $this->assertTrue($maintenance->expire((int) $planet['id'], $now));
        $row = $this->db->selectSingle('SELECT ships, repair_order FROM %%PLANET_WRECKFIELD%% WHERE planetID = :p;', [':p' => $planet['id']]);
        $this->assertSame('', $row['ships'], 'the ships are gone, the repair order stays');
        $this->assertSame('{"202":3}', $row['repair_order']);
    }

    public function testFinishedRepairJobIsDeployedAfter72HoursAndClearedInTheDatabase(): void
    {
        [, $planet] = $this->createPlayer([], ['small_ship_cargo' => 2]);
        $now = $this->state->runtime->timestamp;
        $maintenance = new WreckfieldMaintenance($this->db, $this->configs, $this->state);
        $this->db->insert('INSERT INTO %%PLANET_WRECKFIELD%% SET planetID = :p, created = :c, ships = :s, repair_order = :r, repair_order_start = :rs, repair_order_end = :re;', [
            ':p' => $planet['id'], ':c' => $now, ':s' => '202,5', ':r' => '{"202":3}', ':rs' => $now - TIME_72_HOURS - 100, ':re' => $now - TIME_72_HOURS - 10,
        ]);
        $builded = [];
        $repaired = [];

        $result = $maintenance->applyFinishedRepair($planet, $now, $builded, $repaired);

        $this->assertSame(5, (int) $result['small_ship_cargo']);
        $this->assertSame([202 => 3], $builded);
        $this->assertSame([202 => 3], $repaired);
        $row = $this->db->selectSingle('SELECT repair_order, repair_order_end FROM %%PLANET_WRECKFIELD%% WHERE planetID = :p;', [':p' => $planet['id']]);
        $this->assertSame(['', 0], [$row['repair_order'], (int) $row['repair_order_end']]);

        $notYet = $maintenance->applyFinishedRepair($result, $now, $builded, $repaired);
        $this->assertSame(5, (int) $notYet['small_ship_cargo'], 'nothing is deployed twice');
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} user with a research ending at start + 100, planet */
    private function researchingUser(): array
    {
        [$user, $planet] = $this->createPlayer([], ['metal' => 1e6, 'crystal' => 1e6, 'deuterium' => 1e6, 'laboratory' => 6]);
        $start = (int) $planet['last_update'];
        $user['b_tech'] = $start + 100;
        $user['b_tech_id'] = 106;
        $user['b_tech_planet'] = $planet['id'];
        $user['b_tech_queue'] = StoredData::encode([[106, 2, 100.0, (float) ($start + 100), (int) $planet['id'], [901 => 200.0]]]);

        return [$user, $planet];
    }

    public function testResearchCompletesOnPageLoadOfAnotherPlanetOfTheUser(): void
    {
        [$user, $planet] = $this->researchingUser();
        $start = (int) $planet['last_update'];
        // a second planet that is not b_tech_planet: the research finishes with any planet update of its owner
        $other = $planet;
        $other['id'] = $planet['id'] + 1000000;

        [$afterUser] = (new ResourceUpdate($this->db, $this->configs, $this->state))->CalcResource($user, $other, false, $start + 120);

        $this->assertSame(2, (int) $afterUser['spy_tech']);
        $this->assertSame(0, (int) $afterUser['b_tech']);
        $this->assertSame('', $afterUser['b_tech_queue']);
    }

    public function testPlanetWithLastUpdateInTheFutureIsNotProcessedAtAll(): void
    {
        [$user, $planet] = $this->researchingUser();
        $start = (int) $planet['last_update'];
        $planet['last_update'] = $start + 5000;

        [$afterUser, $afterPlanet] = (new ResourceUpdate($this->db, $this->configs, $this->state))->CalcResource($user, $planet, false, $start + 120);

        $this->assertSame($user['spy_tech'], $afterUser['spy_tech'], 'queues wait while last_update is not before the target time');
        $this->assertSame($user['b_tech'], $afterUser['b_tech']);
        $this->assertSame($start + 5000, (int) $afterPlanet['last_update']);
    }
}
