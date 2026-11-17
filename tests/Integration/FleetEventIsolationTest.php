<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\ErrorReporter;
use App\Game\Core\FlyingFleetHandler;
use App\Game\Missions\MissionInterface;
use App\Tests\Support\GameTestCase;
use App\Game\Enum\Mission;

/**
 * A failing fleet event must not stall the batch: every event runs in its own transaction, a throwing one is rolled
 * back, reported and quarantined (lock released, time pushed back), the other events of the claim are processed.
 */
final class FleetEventIsolationTest extends GameTestCase
{
    /** @var list<int> */
    private array $fleetIds = [];

    protected function tearDown(): void
    {
        foreach ($this->fleetIds as $id) {
            $this->db->delete('DELETE FROM %%FLEETS_EVENT%% WHERE fleetID = :id;', [':id' => $id]);
            $this->db->delete('DELETE FROM %%FLEETS%% WHERE id = :id;', [':id' => $id]);
        }
        parent::tearDown();
    }

    private function fleet(int $owner, int $planetId, int $mission, string $token, int $time): int
    {
        $this->db->insert(
            "INSERT INTO %%FLEETS%% (universe, mission, owner, target_owner, ship_amount, ships, start_ships, start_time, arrival_time, stay_time, return_time, start_id, end_id, start_galaxy, start_system, start_planet, start_type, end_galaxy, end_system, end_planet, end_type, status) VALUES (1, :m, :o, :o, 1, '202,1', '202,1', :t, :t, :t, :t, :p, :p, 1, 1, 1, 1, 1, 1, 1, 1, :st);",
            [':m' => $mission, ':o' => $owner, ':t' => $time, ':p' => $planetId, ':st' => FLEET_RETURN],
        );
        $id = (int) $this->db->lastInsertId();
        $this->fleetIds[] = $id;
        $this->db->insert('INSERT INTO %%FLEETS_EVENT%% SET fleetID = :id, `time` = :t, universe = 1, `lock` = :l;', [':id' => $id, ':t' => $time, ':l' => $token]);

        return $id;
    }

    public function testAThrowingEventIsQuarantinedAndTheOthersAreProcessed(): void
    {
        [$user, $planet] = $this->createPlayer();
        $token = 'isolation-test';
        $now = $this->state->runtime->timestamp;
        $bad = $this->fleet((int) $user['id'], (int) $planet['id'], Mission::Transport->value, $token, $now - 20);
        $good = $this->fleet((int) $user['id'], (int) $planet['id'], Mission::Station->value, $token, $now - 10);

        $reports = [];
        $errors = new class ($this->db, $this->configs, $this->state, $reports) extends ErrorReporter {
            public function __construct($db, $configs, $state, private array &$reports)
            {
                parent::__construct($db, $configs, $state);
            }

            public function reportIsolated(\Throwable $exception, string $context): void
            {
                $this->reports[] = $exception->getMessage();
            }
        };
        $handler = new class ($this->db, $this->configs, $this->state, $errors) extends FlyingFleetHandler {
            protected function missionClass(int $mission): ?string
            {
                return $mission === Mission::Transport->value ? ThrowingMission::class : parent::missionClass($mission);
            }
        };
        $handler->setToken($token);
        $handler->run();

        $this->assertSame(['boom'], $reports);
        $badEvent = $this->db->selectSingle('SELECT * FROM %%FLEETS_EVENT%% WHERE fleetID = :id;', [':id' => $bad]);
        $this->assertNull($badEvent['lock'], 'the lock of the failed event is released');
        $this->assertGreaterThanOrEqual($now + FlyingFleetHandler::QUARANTINE_SECONDS, (int) $badEvent['time'], 'the event is parked');
        $this->assertNotEmpty($this->db->selectSingle('SELECT id FROM %%FLEETS%% WHERE id = :id;', [':id' => $bad]), 'the fleet survives');
        // the return event of the second fleet ran (fleet landed and was deleted), its lock does not matter any more
        $this->assertEmpty($this->db->selectSingle('SELECT id FROM %%FLEETS%% WHERE id = :id;', [':id' => $good]), 'the other event was processed');
    }
}

/** Mission that always fails (test double for FleetEventIsolationTest). */
final class ThrowingMission implements MissionInterface
{
    public function __construct(array $fleet, mixed $db, mixed $configs, mixed $state) {}

    public function TargetEvent()
    {
        throw new \RuntimeException('boom');
    }

    public function EndStayEvent()
    {
        throw new \RuntimeException('boom');
    }

    public function ReturnEvent()
    {
        throw new \RuntimeException('boom');
    }
}
