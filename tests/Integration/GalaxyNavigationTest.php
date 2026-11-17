<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Game\AbstractGameController;
use App\Controller\Game\GalaxyController;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Language;
use App\Game\Core\ResourceUpdate;
use App\Security\CsrfPolicy;
use App\Security\GameRateLimits;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The galaxy view's client-side navigation: the fragment route answers one system with the same rows as the page, charges the
 * deuterium exactly once per displayed system (not for the own one), refuses without deuterium, and counts in the reads bucket.
 */
final class GalaxyNavigationTest extends GameTestCase
{
    private const COST = 10;

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
        $this->state->twig = self::container()->get('twig');
        $this->state->time = ['start' => microtime(true), 'end_common' => microtime(true), 'db' => 0];
        $this->setUniverseConfig(['deuterium_cost_galaxy' => self::COST]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @param array<string, int|string> $input
     */
    private function controller(array $user, array $planet, array $input): GalaxyController
    {
        $this->state->USER = $user + ['messages' => 0, 'signalColors' => '', 'colors' => ''];
        $this->state->PLANET = $planet;
        $this->state->requestInput = $input;
        $this->state->requestQuery = $input;
        $this->state->requestPost = [];
        $this->state->requestServer = ($this->state->requestServer ?? []) + ['REQUEST_METHOD' => 'GET'];
        $controller = (new GalaxyController())->withGameServices($this->db, $this->configs, $this->state);
        $controller->setRouter(self::container()->get('router'));
        (new \ReflectionProperty(AbstractGameController::class, 'ecoObj'))->setValue($controller, new ResourceUpdate($this->db, $this->configs, $this->state));

        return $controller;
    }

    private function deuterium(int $planetId): float
    {
        return (float) $this->loadPlanet($planetId)['deuterium'];
    }

    private function spent(int $userId): float
    {
        return (float) (new AdvancedStatsHandler($this->db, $this->configs, $this->state))->getValue($userId, AdvancedStatsEnum::GALAXY_SCROLL_SPENDING);
    }

    public function testFragmentChargesOncePerDisplayedSystem(): void
    {
        [$user, $planet] = $this->createPlayer([], ['deuterium' => 1000]);
        $other = (int) $planet['system'] % 400 + 1;
        $input = ['galaxy' => (int) $planet['galaxy'], 'system' => $other];

        $response = $this->controller($user, $planet, $input)->rows();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('1', $response->headers->get('X-Galaxy-Fragment'));
        $this->assertStringContainsString('id="galaxy-view" data-galaxy="' . $planet['galaxy'] . '" data-system="' . $other . '"', (string) $response->getContent());
        $this->assertSame(1000.0 - self::COST, $this->deuterium((int) $planet['id']));
        $this->assertSame((float) self::COST, $this->spent((int) $user['id']));

        // the same system displayed again is a new view: charged again
        $planet = $this->loadPlanet((int) $planet['id']);
        $this->controller($user, $planet, $input)->rows();
        $this->assertSame(1000.0 - 2 * self::COST, $this->deuterium((int) $planet['id']));
        $this->assertSame(2.0 * self::COST, $this->spent((int) $user['id']));
    }

    public function testOwnSystemIsFree(): void
    {
        [$user, $planet] = $this->createPlayer([], ['deuterium' => 1000]);
        $response = $this->controller($user, $planet, ['galaxy' => (int) $planet['galaxy'], 'system' => (int) $planet['system']])->rows();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(1000.0, $this->deuterium((int) $planet['id']));
        $this->assertSame(0.0, $this->spent((int) $user['id']));
    }

    public function testArrowStepMovesFromTheGivenCoordinatesAndWraps(): void
    {
        [$user, $planet] = $this->createPlayer([], ['deuterium' => 1000]);
        $response = $this->controller($user, $planet, ['galaxy' => 1, 'system' => 400, 'systemRight' => 1])->rows();
        $this->assertStringContainsString('data-galaxy="1" data-system="1"', (string) $response->getContent());
        $response = $this->controller($user, $this->loadPlanet((int) $planet['id']), ['galaxy' => 1, 'system' => 1, 'systemLeft' => 1])->rows();
        $this->assertStringContainsString('data-galaxy="1" data-system="400"', (string) $response->getContent());
    }

    public function testWithoutDeuteriumNothingIsChargedAndTheScriptIsToldToOpenThePage(): void
    {
        [$user, $planet] = $this->createPlayer([], ['deuterium' => self::COST - 1]);
        $other = (int) $planet['system'] % 400 + 1;
        $response = $this->controller($user, $planet, ['galaxy' => (int) $planet['galaxy'], 'system' => $other])->rows();
        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertSame('', (string) $response->getContent());
        $this->assertSame((float) (self::COST - 1), $this->deuterium((int) $planet['id']));
        $this->assertSame(0.0, $this->spent((int) $user['id']));
    }

    public function testFragmentCarriesTheRowsOfThePage(): void
    {
        [$user, $planet] = $this->createPlayer([], ['deuterium' => 1000]);
        $input = ['galaxy' => (int) $planet['galaxy'], 'system' => (int) $planet['system']];
        $fragment = (string) $this->controller($user, $planet, $input)->rows()->getContent();
        $page = (string) $this->controller($user, $planet, $input)->show()->getContent();

        // the page includes the very same block
        $this->assertStringContainsString(trim($fragment), $page);
        // tooltips ship once as templates, not as attributes
        $this->assertStringNotContainsString('data-tooltip-content', $fragment);
        $this->assertStringContainsString('<template id="tt-', $fragment);
        $this->assertStringContainsString('data-tooltip-ref="', $fragment);
    }

    public function testFragmentRouteCountsInTheReadsBucket(): void
    {
        $bucket = static fn(int $burst, int $perSecond): RateLimiterFactory => new RateLimiterFactory(
            ['id' => 'g' . $burst, 'policy' => 'token_bucket', 'limit' => $burst, 'rate' => ['interval' => '1 second', 'amount' => $perSecond]],
            new InMemoryStorage(),
        );
        $limits = new GameRateLimits($bucket(5, 1), $bucket(4, 2), $bucket(3, 1), self::container()->get(CsrfPolicy::class), true);
        $request = Request::create('/game/galaxy-system', 'GET');
        $request->attributes->set('_route', 'game_galaxy_rows');
        $request->attributes->set('_area', 'game');
        $request->attributes->set('_controller', GalaxyController::class . '::rows');
        for ($i = 0; $i < 4; $i++) {
            $this->assertNull($limits->check($request, 77), "read $i");
        }
        $this->assertNotNull($limits->check($request, 77), 'the reads bucket is empty');
    }
}
