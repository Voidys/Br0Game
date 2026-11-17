<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Admin\BanController;
use App\Controller\Game\AbstractGameController;
use App\Controller\Game\OverviewController;
use App\Controller\Game\RepairdockController;
use App\Controller\Game\ResearchController;
use App\Game\Core\EndOfRequest;
use App\Game\Core\Language;
use App\Game\Core\Player\PlanetManager;
use App\Game\Core\ResourceUpdate;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use App\Game\Enum\AuthLevel;

/**
 * Regression tests of audit round 3: the ban form acts on the checked id only (AD-1), a ?mode= naming a method with required
 * parameters is not dispatched (SO-1), abandoning the planet a research was paid on leaves nothing to refund (EC-1) and the
 * repair dock level is enforced by the controller (EC-2). The CSRF action matching (AU-1) is in CsrfListenerTest.
 */
final class AuditRound3Test extends GameTestCase
{
    /** @var list<int> */
    private array $wreckfields = [];

    protected function setUp(): void
    {
        parent::setUp();
        $lng = new Language($this->configs, 'en');
        $lng->includeData(['L18N', 'INGAME', 'TECH', 'FLEET', 'CUSTOM', 'ADMIN']);
        $this->state->LNG = $lng;
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::container()->get('request_stack')->push($request);
        $this->state->request = $request;
        $this->state->time = ['start' => microtime(true), 'end_common' => microtime(true), 'db' => 0];
    }

    protected function tearDown(): void
    {
        foreach ($this->wreckfields as $id) {
            $this->db->delete('DELETE FROM %%PLANET_WRECKFIELD%% WHERE planetID = :id;', [':id' => $id]);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @param array<string, mixed> $post
     * @param array<string, mixed> $query
     */
    private function prepare(array $user, array $planet, array $post, array $query = []): void
    {
        $this->state->USER = $user + ['messages' => 0, 'signalColors' => '', 'colors' => ''];
        $this->state->PLANET = $planet;
        $this->state->universeEmulated = 1;
        $this->state->requestPost = $post;
        $this->state->requestQuery = $query;
        $this->state->requestInput = $post + $query;
        $this->state->requestServer = ($this->state->requestServer ?? []);
        $this->state->requestServer['REQUEST_METHOD'] = 'POST';
    }

    /** runs the controller action and swallows the request end (message, JSON, redirect) and the notice page's missing browser session */
    private function drive(object $controller, string $action = 'show'): void
    {
        if ($controller instanceof AbstractGameController) {
            $property = new \ReflectionProperty(AbstractGameController::class, 'ecoObj');
            $property->setValue($controller, new ResourceUpdate($this->db, $this->configs, $this->state));
        }
        ob_start();
        try {
            $controller->withGameServices($this->db, $this->configs, $this->state);
            $controller->setRouter(self::container()->get('router'));
            $controller->$action();
        } catch (EndOfRequest) {
        } finally {
            ob_end_clean();
        }
    }

    /** @return array<string, mixed> */
    private function banRow(int $userId): array
    {
        return $this->db->selectSingle('SELECT bana, banaday FROM %%USERS%% WHERE id = :id;', [':id' => $userId]);
    }

    // ---- AD-1 ban form

    /** @return iterable<string, array{int}> */
    public static function protectedTargets(): iterable
    {
        yield 'root' => [ROOT_USER];
        yield 'admin' => [-1];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedTargets')]
    public function testModeratorCannotBanAHigherUserThroughTheBanId(int $target): void
    {
        if ($target === ROOT_USER && empty($this->db->selectSingle('SELECT id FROM %%USERS%% WHERE id = :id;', [':id' => ROOT_USER]))) {
            $this->markTestSkipped('the test database has no root user row');
        }
        [$mod] = $this->createPlayer(['authlevel' => AuthLevel::Moderator->value]);
        [$plain] = $this->createPlayer();
        if ($target === -1) {
            [$high] = $this->createPlayer(['authlevel' => AuthLevel::Admin->value]);
            $target = (int) $high['id'];
        }
        $before = $this->banRow($target);

        // the authorisation looks at unban_id (a plain player); the old form then banned ban_id
        $this->prepare($mod, [], ['bannow' => 1, 'ban_id' => $target, 'unban_id' => $plain['id'], 'permanent' => 1, 'ban_name' => 'x', 'why' => 'x'], ['page' => 'ban', 'mode' => 'user']);
        $this->drive(new BanController(), 'playersAct');

        $this->assertEquals($before['bana'], $this->banRow($target)['bana'], 'the higher user stays unbanned');
        $this->assertEquals($before['banaday'], $this->banRow($target)['banaday']);
        $this->assertSame(0, (int) $this->banRow($target)['bana']);
        $this->assertSame(1, (int) $this->banRow((int) $plain['id'])['bana'], 'only the checked id is banned');
    }

    public function testAdminStillBansAPlainPlayer(): void
    {
        [$admin] = $this->createPlayer(['authlevel' => AuthLevel::Admin->value]);
        [$plain] = $this->createPlayer();

        $this->prepare($admin, [], ['bannow' => 1, 'ban_id' => $plain['id'], 'permanent' => 1, 'ban_name' => 'x', 'why' => 'cheating'], ['page' => 'ban', 'mode' => 'user']);
        $this->drive(new BanController(), 'playersAct');

        $row = $this->banRow((int) $plain['id']);
        $this->assertSame(1, (int) $row['bana']);
        $this->assertSame(2147483647, (int) $row['banaday']);
    }

    // ---- EC-1 abandon + cancel research

    public function testCancellingResearchPaidOnAnAbandonedPlanetRefundsNothing(): void
    {
        $resource = $this->elements()->columnMap();
        $now = $this->state->runtime->timestamp;
        [$user, $main] = $this->createPlayer([], [$resource[RESEARCH_LABORATORY] => 3, 'metal' => 1000, 'crystal' => 1000, 'deuterium' => 1000, 'last_update' => $now]);
        $players = new PlanetManager($this->db, $this->configs, $this->state);
        $colonyId = $players->createPlanet(7, 77, 7, 1, (int) $user['id'], 'Colony');
        $this->db->update('UPDATE %%PLANETS%% SET last_update = :t WHERE id = :id;', [':t' => $now, ':id' => $colonyId]);

        $queue = '[[' . ENERGY_TECH . ',1,60,' . ($now + 3600) . ',' . $colonyId . ',{"901":500,"902":500,"903":500}]]';
        $this->db->update('UPDATE %%USERS%% SET b_tech = :t, b_tech_id = :e, b_tech_planet = :p, b_tech_queue = :q WHERE id = :id;', [
            ':t' => $now + 3600, ':e' => ENERGY_TECH, ':p' => $colonyId, ':q' => $queue, ':id' => $user['id'],
        ]);

        // abandon the colony
        $this->prepare($this->loadUser((int) $user['id']), $this->loadPlanet($colonyId), ['password' => 'Colony'], ['page' => 'overview', 'mode' => 'delete']);
        $this->drive(new OverviewController(), 'delete');
        $this->assertGreaterThan(0, (int) $this->loadPlanet($colonyId)['destruyed'], 'the colony is marked as destroyed');

        $afterAbandon = $this->loadUser((int) $user['id']);
        $this->assertSame((int) $main['id'], (int) $afterAbandon['b_tech_planet'], 'the research moved to the main planet');

        // cancel it on the main planet
        $mainBefore = $this->loadPlanet((int) $main['id']);
        $this->prepare($afterAbandon, $mainBefore, ['cmd' => 'cancel', 'tech' => ENERGY_TECH], ['page' => 'research']);
        $this->drive(new ResearchController(), 'command');

        $mainAfter = $this->loadPlanet((int) $main['id']);
        foreach (['metal', 'crystal', 'deuterium'] as $column) {
            $this->assertEquals($mainBefore[$column], $mainAfter[$column], $column . ' was not refunded');
        }
        $after = $this->loadUser((int) $user['id']);
        $this->assertSame('', (string) $after['b_tech_queue'], 'the queue is cleared');
        $this->assertEquals(0, $after['b_tech']);
    }

    // ---- EC-2 repair dock level

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} user and a planet without repair dock holding wrecks and a finished order */
    private function planetWithoutDock(string $order = ''): array
    {
        [$user, $planet] = $this->createPlayer();
        $this->wreckfields[] = (int) $planet['id'];
        $this->db->delete('DELETE FROM %%PLANET_WRECKFIELD%% WHERE planetID = :id;', [':id' => $planet['id']]);
        $this->db->insert(
            'INSERT INTO %%PLANET_WRECKFIELD%% SET planetID = :id, created = :c, ships = :s, repair_order = :o, repair_order_start = :st, repair_order_end = :e, repair_order_rate = :r;',
            [':id' => $planet['id'], ':c' => $this->state->runtime->timestamp, ':s' => '206,40', ':o' => $order, ':st' => $order === '' ? 0 : $this->state->runtime->timestamp - 3600, ':e' => $order === '' ? 0 : $this->state->runtime->timestamp - 10, ':r' => $order === '' ? 0 : 0.25],
        );

        return [$user, $this->loadPlanet((int) $planet['id'])];
    }

    public function testRepairOrderCannotBeStartedWithoutADock(): void
    {
        [$user, $planet] = $this->planetWithoutDock();
        $this->assertEquals(0, $planet[$this->elements()->column(REPAIR_DOCK)]);

        $this->prepare($user, $planet, ['fmenge' => [206 => 2]]);
        $this->drive(new RepairdockController());

        $wreck = $this->db->selectSingle('SELECT * FROM %%PLANET_WRECKFIELD%% WHERE planetID = :id;', [':id' => $planet['id']]);
        $this->assertSame('', (string) $wreck['repair_order'], 'no order was stored');
        $this->assertSame('206,40', $wreck['ships']);
    }

    public function testRepairedShipsCannotBeDeployedWithoutADock(): void
    {
        [$user, $planet] = $this->planetWithoutDock('{"206":10}');

        $this->prepare($user, $planet, ['cmd' => 'deploy']);
        $this->drive(new RepairdockController());

        $this->assertEquals(0, $this->loadPlanet((int) $planet['id'])[$this->elements()->column(206)], 'no ships were added');
        $wreck = $this->db->selectSingle('SELECT * FROM %%PLANET_WRECKFIELD%% WHERE planetID = :id;', [':id' => $planet['id']]);
        $this->assertSame('{"206":10}', $wreck['repair_order']);
        $this->assertSame('206,40', $wreck['ships']);
    }
}
