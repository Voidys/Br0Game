<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Game\RepairdockController;
use App\Game\Core\EndOfRequest;
use App\Game\Core\Language;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Repair dock orders: an order that cannot repair anything is not stored (it used to jam the dock), a stored empty order
 * counts as free, and the wrecks an order consumes are computed with the dock level the order was started with.
 */
final class RepairdockTest extends GameTestCase
{
    /** @var list<int> */
    private array $wreckfields = [];

    protected function tearDown(): void
    {
        foreach ($this->wreckfields as $id) {
            $this->db->delete('DELETE FROM %%PLANET_WRECKFIELD%% WHERE planetID = :id;', [':id' => $id]);
        }
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $lng = new Language($this->configs, 'en');
        $lng->includeData(['L18N', 'INGAME', 'TECH', 'FLEET', 'CUSTOM']);
        $this->state->LNG = $lng;
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::container()->get('request_stack')->push($request);
        $this->state->request = $request;
        $this->state->time = ['start' => microtime(true), 'end_common' => microtime(true), 'db' => 0];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} user, planet with a repair dock of the given level and wrecks of 3 cruisers (ship 206) */
    private function dockWithWrecks(int $level, array $wrecks = [206 => 3], string $order = '', int $orderEnd = 0, float $rate = 0): array
    {
        $resource = $this->state->resource;
        [$user, $planet] = $this->createPlayer([], [$resource[REPAIR_DOCK] => $level]);
        $this->wreckfields[] = (int) $planet['id'];
        $this->db->delete('DELETE FROM %%PLANET_WRECKFIELD%% WHERE planetID = :id;', [':id' => $planet['id']]);
        $this->db->insert(
            'INSERT INTO %%PLANET_WRECKFIELD%% SET planetID = :id, created = :c, ships = :s, repair_order = :o, repair_order_start = :st, repair_order_end = :e, repair_order_rate = :r;',
            [':id' => $planet['id'], ':c' => $this->state->runtime->timestamp, ':s' => implode(';', array_map(static fn($id, $n) => $id . ',' . $n, array_keys($wrecks), $wrecks)), ':o' => $order, ':st' => $orderEnd ? $orderEnd - 3600 : 0, ':e' => $orderEnd, ':r' => $rate],
        );

        return [$user, $this->loadPlanet((int) $planet['id'])];
    }

    /** @param array<string, mixed> $input */
    private function post(array $user, array $planet, array $input): void
    {
        $this->state->USER = $user + ['messages' => 0, 'signalColors' => '', 'colors' => ''];
        $this->state->PLANET = $planet;
        $this->state->requestInput = $input;
        $this->state->requestPost = $input;
        $this->state->requestQuery = [];
        $this->state->requestServer = ($this->state->requestServer ?? []);
        $this->state->requestServer['REQUEST_METHOD'] = 'POST';
        try {
            (new RepairdockController())->withGameServices($this->db, $this->configs, $this->state)->show();
            $this->fail('the page should end the request');
        } catch (EndOfRequest) {
        }
    }

    /** @return array<string, mixed> */
    private function wreckfield(int $planetId): array
    {
        return $this->db->selectSingle('SELECT * FROM %%PLANET_WRECKFIELD%% WHERE planetID = :id;', [':id' => $planetId]);
    }

    public function testAnOrderThatCannotRepairAnythingIsNotStored(): void
    {
        [$user, $planet] = $this->dockWithWrecks(1);

        // 3 wrecks at 25 %: floor(0.75) = 0 ships can be repaired
        $this->post($user, $planet, ['fmenge' => [206 => 1]]);

        $this->assertSame('', (string) $this->wreckfield((int) $planet['id'])['repair_order']);
    }

    public function testAnEmptyStoredOrderDoesNotBlockANewOrder(): void
    {
        [$user, $planet] = $this->dockWithWrecks(1, [206 => 40], '[]', $this->state->runtime->timestamp - 100);

        $this->post($user, $planet, ['fmenge' => [206 => 2]]);

        $order = $this->wreckfield((int) $planet['id']);
        $this->assertSame('{"206":2.0}', $order['repair_order'], 'the stale empty order was replaced by the new one');
    }

    public function testDeployConsumesTheWrecksWithTheRateOfTheOrder(): void
    {
        $resource = $this->state->resource;
        // order of 10 cruisers started at 25 % (40 wrecks consumed), dock meanwhile upgraded to level 9 (51 %)
        [$user, $planet] = $this->dockWithWrecks(9, [206 => 40], '{"206":10}', $this->state->runtime->timestamp - 10, 0.25);

        $this->post($user, $planet, ['cmd' => 'deploy']);

        $this->assertEquals(10, $this->loadPlanet((int) $planet['id'])[$resource[206]]);
        $after = $this->wreckfield((int) $planet['id']);
        $this->assertSame('', (string) $after['repair_order']);
        $this->assertSame('', (string) $after['ships'], 'all 40 wrecks were used up by the order, not only 10/0.51');
    }
}
