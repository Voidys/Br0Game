<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Game\AbstractGameController;
use App\Controller\Game\InformationController;
use App\Game\Core\EndOfRequest;
use App\Game\Core\FleetLauncher;
use App\Game\Cronjob\FleetEventCronjob;
use App\Tests\Support\GameTestCase;

/**
 * Race guards of the fleet rules (security audit F-03, F-05): the missile silo is changed under a row lock and
 * relatively, an arrived outward flight cannot be recalled before its event ran, and the fleet-event driver processes
 * due events without a page view.
 */
final class FleetRaceGuardsTest extends GameTestCase
{
    private function launcher(): FleetLauncher
    {
        return new FleetLauncher($this->db, $this->configs, $this->state);
    }

    /**
     * Sends 5 small cargo ships (mission $mission) from a fresh player to another player's planet.
     *
     * @return array{0: array<string, mixed>, 1: int} sender, fleet id
     */
    private function launch(int $mission = MISSION_TRANSPORT): array
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
            0,
            0,
            0,
            50.0,
        );

        return [$user, $fleetId];
    }

    public function testDestroyMissilesDoesNotRestoreMissilesLaunchedBehindItsBack(): void
    {
        [$user, $planet] = $this->createPlayer([], ['interceptor_misil' => 10, 'interplanetary_misil' => 10]);
        $resource = $this->elements()->columnMap();

        $this->state->USER = $user + ['messages' => 0];
        $this->state->PLANET = $planet; // stale copy: 10 / 10
        $this->state->requestInput = $this->state->requestPost = ['missile' => [502 => 0, 503 => 0]];
        $this->state->requestQuery = [];
        $this->state->requestServer = ($this->state->requestServer ?? []) + ['REQUEST_METHOD' => 'POST'];

        // a concurrent launch used 7 interplanetary missiles after the request began
        $this->db->update('UPDATE %%PLANETS%% SET `' . $resource[503] . '` = 3 WHERE id = :id;', [':id' => $planet['id']]);

        $output = $this->callDestroy([502 => 2, 503 => 5]);

        $after = $this->loadPlanet((int) $planet['id']);
        $this->assertSame(8, (int) $after[$resource[502]]);
        $this->assertSame(0, (int) $after[$resource[503]], '3 left, destroying 5 is clamped to 3');
        $this->assertSame('[8,0]', $output);
        $this->assertSame(0, (int) $this->state->PLANET[$resource[503]]);

        // nothing to destroy: the stale value must not be written back
        $this->db->update('UPDATE %%PLANETS%% SET `' . $resource[502] . '` = 1 WHERE id = :id;', [':id' => $planet['id']]);
        $this->state->PLANET = $planet;
        $this->assertSame('[1,0]', $this->callDestroy([502 => 0, 503 => 0]));
        $this->assertSame(1, (int) $this->loadPlanet((int) $planet['id'])[$resource[502]]);
    }

    /** @param array<int, int> $missiles */
    private function callDestroy(array $missiles): string
    {
        $this->state->requestInput = $this->state->requestPost = ['missile' => $missiles];
        $controller = new InformationController();
        $property = new \ReflectionProperty(AbstractGameController::class, 'ecoObj');
        $property->setValue($controller, new \App\Game\Core\ResourceUpdate($this->db, $this->configs, $this->state));
        ob_start();
        try {
            $controller->withGameServices($this->db, $this->configs, $this->state)->destroyMissiles();
        } catch (EndOfRequest) {
        }

        return (string) ob_get_clean();
    }

    public function testArrivedOutwardFleetCannotBeRecalledBeforeItsEventRan(): void
    {
        [$user, $fleetId] = $this->launch();
        $now = $this->state->runtime->timestamp;
        $this->db->update('UPDATE %%FLEETS%% SET start_time = :s, arrival_time = :a WHERE id = :id;', [':s' => $now - 700, ':a' => $now - 100, ':id' => $fleetId]);

        $this->assertFalse($this->launcher()->sendFleetBack($user, $fleetId));
        $this->assertSame(FLEET_OUTWARD, (int) $this->db->selectSingle('SELECT status FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId], 'status'));

        // still in flight: recallable
        $this->db->update('UPDATE %%FLEETS%% SET arrival_time = :a WHERE id = :id;', [':a' => $now + 100, ':id' => $fleetId]);
        $this->assertTrue($this->launcher()->sendFleetBack($user, $fleetId));
    }

    public function testHoldingFleetCanStillBeRecalledDuringItsStay(): void
    {
        [$user, $fleetId] = $this->launch(MISSION_HOLD);
        $now = $this->state->runtime->timestamp;
        $this->db->update('UPDATE %%FLEETS%% SET start_time = :s, arrival_time = :a, status = :st WHERE id = :id;', [
            ':s' => $now - 1000,
            ':a' => $now - 400,
            ':st' => FLEET_HOLD,
            ':id' => $fleetId,
        ]);

        $this->assertTrue($this->launcher()->sendFleetBack($user, $fleetId));
    }

    public function testFleetEventDriverProcessesDueEventsWithoutAPageView(): void
    {
        [, $fleetId] = $this->launch(MISSION_STATION);
        $now = $this->state->runtime->timestamp;
        $this->db->update('UPDATE %%FLEETS%% SET start_time = :s, arrival_time = :a WHERE id = :id;', [':s' => $now - 700, ':a' => $now - 100, ':id' => $fleetId]);
        $this->db->update('UPDATE %%FLEETS_EVENT%% SET `time` = :t WHERE fleetID = :id;', [':t' => $now - 100, ':id' => $fleetId]);

        $driver = self::container()->get(FleetEventCronjob::class);
        $this->assertGreaterThanOrEqual(1, $driver->processDueEvents());

        $fleet = $this->db->selectSingle('SELECT status FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);
        $event = $this->db->selectSingle('SELECT `lock`, `time` FROM %%FLEETS_EVENT%% WHERE fleetID = :id;', [':id' => $fleetId]);
        $this->assertNotSame(FLEET_OUTWARD, empty($fleet) ? null : (int) $fleet['status'], 'the arrival event ran (fleet turned around or ended)');
        $this->assertTrue(empty($event) || ($event['lock'] === null && (int) $event['time'] > $now - 100), 'event released and rescheduled');
    }
}
