<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Admin\AbuseReport\AbuseReportQuery;
use App\Tests\Support\GameTestCase;

/**
 * Admin abuse report: one-sided transports, repeated trades, rebuild-protection grants and recalls after arrival show up
 * with their link markers; without log rows every section is empty.
 */
final class AbuseReportQueryTest extends GameTestCase
{
    private const NOW = 1800000000;
    private static int $fleetId = 9100000;

    /** @return array<string, mixed> */
    private function query(int $days = 7): array
    {
        return (new AbuseReportQuery($this->db, $this->elements()))->report(1, $days, self::NOW);
    }

    /** @param array<string, mixed> $values */
    private function logFleet(array $values): int
    {
        $id = ++self::$fleetId;
        $row = $values + [
            'id' => $id, 'universe' => 1, 'mission' => MISSION_TRANSPORT, 'owner' => 0, 'target_owner' => 0, 'start_id' => 1, 'end_id' => 2,
            'start_time' => self::NOW - 3600, 'arrival_time' => self::NOW - 1800, 'stay_time' => 0, 'hasCanceled' => 0,
            'resource_metal' => 0, 'resource_crystal' => 0, 'resource_deuterium' => 0,
        ];
        $columns = implode(', ', array_map(static fn(string $c): string => "`$c`", array_keys($row)));
        $params = [];
        foreach ($row as $column => $value) {
            $params[':' . $column] = $value;
        }
        $this->db->insert("INSERT INTO %%LOG_FLEETS%% ($columns) VALUES (" . implode(', ', array_keys($params)) . ');', $params);

        return $row['id'];
    }

    public function testEmptyLogsGiveEmptySections(): void
    {
        $report = $this->query();

        $this->assertSame([], $report['transfers']);
        $this->assertSame([], $report['trades']);
        $this->assertSame([], $report['protections']);
        $this->assertSame([], $report['recalls']);
        $this->assertSame([], $report['graph']);
        $this->assertSame([], $report['activity']);
        $this->assertSame(7, $report['days']);
    }

    public function testOneSidedTransferBetweenLinkedPlayersIsListed(): void
    {
        [$a] = $this->createPlayer(['user_lastip' => '10.9.8.7']);
        [$b] = $this->createPlayer(['user_lastip' => '10.9.8.7']);
        [$c] = $this->createPlayer(['user_lastip' => '10.1.1.1']);
        $a = (int) $a['id'];
        $b = (int) $b['id'];
        $c = (int) $c['id'];

        // a -> b: 3 x 10 000 metal, b -> a: 1 000 metal (10 % of the value: one-sided)
        for ($i = 0; $i < 3; ++$i) {
            $this->logFleet(['owner' => $a, 'target_owner' => $b, 'resource_metal' => 10000]);
        }
        $this->logFleet(['owner' => $b, 'target_owner' => $a, 'resource_metal' => 1000]);
        // c <-> a: balanced, and a recalled transport (not counted)
        $this->logFleet(['owner' => $c, 'target_owner' => $a, 'resource_metal' => 5000]);
        $this->logFleet(['owner' => $a, 'target_owner' => $c, 'resource_metal' => 5000]);
        $this->logFleet(['owner' => $c, 'target_owner' => $b, 'resource_metal' => 90000, 'hasCanceled' => 1]);
        // too old for the window
        $this->logFleet(['owner' => $c, 'target_owner' => $b, 'resource_metal' => 90000, 'start_time' => self::NOW - 20 * 86400]);

        $transfers = $this->query()['transfers'];

        $this->assertCount(1, $transfers);
        $this->assertSame($a, (int) $transfers[0]['from']);
        $this->assertSame($b, (int) $transfers[0]['to']);
        $this->assertEquals(30000, $transfers[0]['sent']);
        $this->assertEquals(1000, $transfers[0]['back']);
        $this->assertEquals(29000, $transfers[0]['net']);
        $this->assertTrue($transfers[0]['ip']);
        $this->assertFalse($transfers[0]['multi']);

        // the wider window picks the old transport of c up as well
        $this->assertCount(2, $this->query(30)['transfers']);
    }

    public function testMultiGroupMarksTheTransfer(): void
    {
        [$a] = $this->createPlayer(['user_lastip' => '10.2.2.2']);
        [$b] = $this->createPlayer(['user_lastip' => '10.3.3.3']);
        $a = (int) $a['id'];
        $b = (int) $b['id'];
        $multiId = $this->db->insert('INSERT INTO %%MULTI%% SET `universe` = 1, `lastActivity` = 1, `allowed` = 0;', []);
        foreach ([$a, $b] as $id) {
            $this->db->insert('INSERT INTO %%MULTI_TO_USERS%% SET `multiID` = :m, `userID` = :u, `lastActivity` = 1, `allowed` = 1;', [':m' => $multiId, ':u' => $id]);
        }
        $this->logFleet(['owner' => $a, 'target_owner' => $b, 'resource_deuterium' => 2000, 'mission' => MISSION_HOLD]);

        $transfers = $this->query()['transfers'];

        $this->assertCount(1, $transfers);
        $this->assertFalse($transfers[0]['ip']);
        $this->assertFalse($transfers[0]['subnet']);
        $this->assertTrue($transfers[0]['multi']);
        $this->assertTrue($transfers[0]['multiKnown']);
    }

    public function testShipTransferIsValuedAtBuildCostWithCargo(): void
    {
        [$a] = $this->createPlayer(['user_lastip' => '10.5.5.5']);
        [$b] = $this->createPlayer(['user_lastip' => '10.5.5.77']);
        $a = (int) $a['id'];
        $b = (int) $b['id'];
        $cost = $this->elements()->get(202)->cost;
        $weights = (new AbuseReportQuery($this->db))->weights(1);
        $each = $cost->metal + $cost->crystal * $weights['crystal'] + $cost->deuterium * $weights['deuterium'];
        $this->assertGreaterThan(0.0, $each);

        $this->logFleet(['owner' => $a, 'target_owner' => $b, 'mission' => MISSION_TRANSFER, 'ships' => '202,10', 'resource_metal' => 100]);
        // a recalled transfer is ignored
        $this->logFleet(['owner' => $a, 'target_owner' => $b, 'mission' => MISSION_TRANSFER, 'ships' => '202,10', 'hasCanceled' => 1]);

        $transfers = $this->query()['transfers'];

        $this->assertCount(1, $transfers);
        $this->assertSame($a, (int) $transfers[0]['from']);
        $this->assertSame(1, (int) $transfers[0]['count']);
        $this->assertEqualsWithDelta(10 * $each + 100, $transfers[0]['sent'], 0.01);
        $this->assertFalse($transfers[0]['ip']);
        $this->assertTrue($transfers[0]['subnet'], 'same /24 with different addresses is the weaker marker');
    }

    public function testSubnetPrefixLinksIpv4AndIpv6(): void
    {
        [$a] = $this->createPlayer(['user_lastip' => '2001:db8:1:2:aaaa::1']);
        [$b] = $this->createPlayer(['user_lastip' => '2001:db8:1:2:bbbb::9']);
        [$c] = $this->createPlayer(['user_lastip' => '2001:db8:1:3::1']);
        $this->logFleet(['owner' => (int) $a['id'], 'target_owner' => (int) $b['id'], 'resource_metal' => 5000]);
        $this->logFleet(['owner' => (int) $a['id'], 'target_owner' => (int) $c['id'], 'resource_metal' => 4000]);

        $byTarget = [];
        foreach ($this->query()['transfers'] as $row) {
            $byTarget[(int) $row['to']] = $row;
        }

        $this->assertTrue($byTarget[(int) $b['id']]['subnet']);
        $this->assertFalse($byTarget[(int) $c['id']]['subnet']);
    }

    public function testRepeatedSkewedTradesAreFlagged(): void
    {
        [$a] = $this->createPlayer();
        [$b] = $this->createPlayer();
        $a = (int) $a['id'];
        $b = (int) $b['id'];
        $time = date('Y-m-d H:i:s', self::NOW - 3600);

        // a sells 3000 metal for 1000 metal: ratio 3 (a gave more), three times
        for ($i = 0; $i < 3; ++$i) {
            $seller = $this->logFleet(['owner' => $a, 'target_owner' => 0]);
            $buyer = $this->logFleet(['owner' => $b, 'target_owner' => 0]);
            $this->db->insert(
                'INSERT INTO %%TRADES%% SET `seller_fleet_id` = :s, `buyer_fleet_id` = :b, `buy_time` = :t, `transaction_type` = 0, `resource_type` = 1, `resource_amount` = 3000, `ex_resource_type` = 1, `ex_resource_amount` = 1000;',
                [':s' => $seller, ':b' => $buyer, ':t' => $time],
            );
        }

        $trades = $this->query()['trades'];

        $this->assertCount(1, $trades);
        $this->assertSame($a, $trades[0]['giver']);
        $this->assertSame($b, $trades[0]['receiver']);
        $this->assertSame(3, (int) $trades[0]['count']);
        $this->assertEqualsWithDelta(3.0, $trades[0]['ratio'], 0.001);
        $this->assertTrue($trades[0]['outlier']);
    }

    public function testRebuildProtectionGrantShowsOpponentsAndLoot(): void
    {
        [$victim] = $this->createPlayer(['user_lastip' => '10.5.5.5']);
        [$raider] = $this->createPlayer(['user_lastip' => '10.5.5.5']);
        $victim = (int) $victim['id'];
        $raider = (int) $raider['id'];
        $fight = self::NOW - 7200;
        $this->db->update(
            'UPDATE %%USERS%% SET `rebuild_protection_locked_until` = :until WHERE `id` = :id;',
            [':until' => $fight + TIME_1_MONTH, ':id' => $victim],
        );
        $this->db->insert(
            "INSERT INTO %%RW%% SET `rid` = 'abuse-test-report', `raport` = '', `time` = :time, `attacker` = :a, `defender` = :d;",
            [':time' => $fight, ':a' => (string) $raider, ':d' => (string) $victim],
        );
        $this->logFleet(['owner' => $raider, 'target_owner' => $victim, 'mission' => MISSION_ATTACK, 'report_id' => 'abuse-test-report', 'gained_metal' => 4000, 'gained_deuterium' => 100]);

        $report = $this->query();
        $protections = $report['protections'];

        $this->assertCount(1, $protections);
        $this->assertSame($victim, $protections[0]['user']);
        $this->assertSame($fight, $protections[0]['granted']);
        $this->assertSame('abuse-test-report', $protections[0]['report']);
        $this->assertTrue($protections[0]['linked']);
        $this->assertSame($raider, $protections[0]['opponents'][0]['id']);
        $this->assertEqualsWithDelta(4000 + 100 * $report['weights']['deuterium'], $protections[0]['loot'], 0.001);
    }

    public function testRecallAfterArrivalIsListed(): void
    {
        [$a] = $this->createPlayer();
        [$b] = $this->createPlayer();
        $a = (int) $a['id'];
        $b = (int) $b['id'];
        // recalled 30 s after the arrival time, and a normal recall before arrival
        $this->logFleet(['owner' => $a, 'target_owner' => $b, 'mission' => MISSION_ATTACK, 'hasCanceled' => 1, 'arrival_time' => self::NOW - 600, 'stay_time' => self::NOW - 570]);
        $this->logFleet(['owner' => $a, 'target_owner' => $b, 'mission' => MISSION_ATTACK, 'hasCanceled' => 1, 'arrival_time' => self::NOW - 600, 'stay_time' => self::NOW - 900]);

        $recalls = $this->query()['recalls'];

        $this->assertCount(1, $recalls);
        $this->assertSame(30, (int) $recalls[0]['late']);
    }

    public function testChainOfTransfersFormsOneClusterAndSmallFlowsDoNot(): void
    {
        [$a, $b, $c, $d, $e] = array_map(fn(): int => (int) $this->createPlayer()[0]['id'], range(1, 5));

        // a -> b -> c chain: 3 flows of 2 000 each (6 000 >= 5 x MIN_TRANSFER_VALUE is 5 000)
        for ($i = 0; $i < 3; ++$i) {
            $this->logFleet(['owner' => $a, 'target_owner' => $b, 'resource_metal' => 2000]);
            $this->logFleet(['owner' => $b, 'target_owner' => $c, 'resource_metal' => 1000, 'resource_crystal' => 1000]);
        }
        // d -> e: plenty of value but only two flows; c -> d: three flows of tiny value
        $this->logFleet(['owner' => $d, 'target_owner' => $e, 'resource_metal' => 50000]);
        $this->logFleet(['owner' => $d, 'target_owner' => $e, 'resource_metal' => 50000]);
        for ($i = 0; $i < 3; ++$i) {
            $this->logFleet(['owner' => $c, 'target_owner' => $d, 'resource_metal' => 10]);
        }

        $report = $this->query();
        $graph = $report['graph'];

        $this->assertCount(1, $graph);
        $this->assertEqualsCanonicalizing([$a, $b, $c], array_column($graph[0]['members'], 'id'));
        $this->assertSame(6, $graph[0]['flows']);
        $this->assertEqualsWithDelta(6000 + 3 * (1000 + 1000 * $report['weights']['crystal']), $graph[0]['value'], 0.01);
        $this->assertSame($c, $graph[0]['top']['to']);
    }

    public function testTradesAndLootJoinTheGraphAndOldFlowsAreIgnored(): void
    {
        [$a, $b, $c] = array_map(fn(): int => (int) $this->createPlayer()[0]['id'], range(1, 3));
        $time = date('Y-m-d H:i:s', self::NOW - 3600);
        for ($i = 0; $i < 3; ++$i) {
            $seller = $this->logFleet(['owner' => $a, 'target_owner' => 0]);
            $buyer = $this->logFleet(['owner' => $b, 'target_owner' => 0]);
            $this->db->insert(
                'INSERT INTO %%TRADES%% SET `seller_fleet_id` = :s, `buyer_fleet_id` = :b, `buy_time` = :t, `transaction_type` = 0, `resource_type` = 1, `resource_amount` = 2000, `ex_resource_type` = 1, `ex_resource_amount` = 1000;',
                [':s' => $seller, ':b' => $buyer, ':t' => $time],
            );
            $this->logFleet(['owner' => $c, 'target_owner' => $b, 'mission' => MISSION_ATTACK, 'gained_metal' => 2000]);
            // too old for the 30 day window
            $this->logFleet(['owner' => $c, 'target_owner' => $a, 'resource_metal' => 90000, 'start_time' => self::NOW - 40 * 86400]);
        }

        $graph = $this->query()['graph'];

        $this->assertCount(1, $graph);
        $this->assertEqualsCanonicalizing([$a, $b, $c], array_column($graph[0]['members'], 'id'));
        $this->assertSame(6, $graph[0]['flows']);
        $this->assertEqualsWithDelta(9000 + 6000, $graph[0]['value'], 0.01);
    }

    public function testDeviceMarkerNeedsASharedDeviceHash(): void
    {
        $table = $this->db->getTablePrefix() . 'user_devices';
        [$a, $b, $c] = array_map(fn(): int => (int) $this->createPlayer()[0]['id'], range(1, 3));
        $this->logFleet(['owner' => $a, 'target_owner' => $b, 'resource_metal' => 5000]);
        $this->logFleet(['owner' => $a, 'target_owner' => $c, 'resource_metal' => 4000]);

        $insert = "INSERT INTO `$table` SET `user_id` = :u, `device_hash` = :h, `first_seen` = 1, `last_seen` = 1;";
        $this->db->insert($insert, [':u' => $a, ':h' => 'abuse-test-device']);
        $this->db->insert($insert, [':u' => $b, ':h' => 'abuse-test-device']);
        $this->db->insert($insert, [':u' => $c, ':h' => 'abuse-test-other']);

        $byTarget = [];
        foreach ($this->query()['transfers'] as $row) {
            $byTarget[(int) $row['to']] = $row;
        }

        $this->assertTrue($byTarget[$b]['device']);
        $this->assertFalse($byTarget[$c]['device']);
    }

    public function testActivityListsTheBusiestAccountsFirstWithHourlyPeak(): void
    {
        [$busy, $calm] = array_map(fn(): int => (int) $this->createPlayer()[0]['id'], range(1, 2));
        // busy: 4 launches in one hour plus 1 earlier; calm: 2 spread out; one launch older than 24 h is ignored
        foreach ([600, 700, 800, 900] as $ago) {
            $this->logFleet(['owner' => $busy, 'start_time' => self::NOW - $ago]);
        }
        $this->logFleet(['owner' => $busy, 'start_time' => self::NOW - 10 * 3600]);
        $this->logFleet(['owner' => $calm, 'start_time' => self::NOW - 5 * 3600]);
        $this->logFleet(['owner' => $calm, 'start_time' => self::NOW - 8 * 3600]);
        $this->logFleet(['owner' => $calm, 'start_time' => self::NOW - 30 * 3600]);

        $activity = $this->query()['activity'];

        $this->assertSame([$busy, $calm], array_column($activity, 'id'));
        $this->assertSame(5, $activity[0]['launches']);
        $this->assertGreaterThanOrEqual(2, $activity[0]['peak']);
        $this->assertSame(2, $activity[1]['launches']);
        $this->assertSame(1, $activity[1]['peak']);
    }
}
