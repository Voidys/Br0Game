<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Game\FleetStep1Controller;
use App\Controller\Game\FleetStep3Controller;
use App\Controller\Game\FleetStepAjaxController;
use App\Game\Core\EndOfRequest;
use App\Game\Core\Language;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The fleet dispatch steps only act on what they should: step 3 sends only on POST, the release beacon (fleetStepAjax)
 * only drops a prepared fleet on POST, and step 1 stores nothing for ships the planet does not have.
 */
final class FleetStepGuardsTest extends GameTestCase
{
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

    /**
     * @param class-string $class
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @param array<string, mixed> $input
     */
    private function controller(string $class, array $user, array $planet, array $input, string $method): object
    {
        $this->state->USER = $user + ['messages' => 0, 'signalColors' => '', 'colors' => ''];
        $this->state->PLANET = $planet;
        $this->state->requestInput = $input;
        $this->state->requestPost = $method === 'POST' ? $input : [];
        $this->state->requestQuery = $method === 'POST' ? [] : $input;
        $this->state->requestServer['REQUEST_METHOD'] = $method;

        return (new $class())->withGameServices($this->db, $this->configs, $this->state);
    }

    private function session(object $controller): object
    {
        return (new \ReflectionMethod($controller, 'sessions'))->invoke($controller)->load();
    }

    /** @return array<string, mixed> */
    private function prepared(): array
    {
        return ['ownPlanet' => 0, 'time' => $this->state->runtime->timestamp, 'fleet' => [202 => 1], 'fleetRoom' => 5000, 'predefinedResources' => []];
    }

    public function testStepThreeByGetDoesNotSendAndKeepsTheToken(): void
    {
        [$user, $planet] = $this->createPlayer([], ['small_ship_cargo' => 5, 'deuterium' => 100000]);
        [, $target] = $this->createPlayer();
        $input = ['token' => 'tok', 'mission' => MISSION_TRANSPORT, 'metal' => 0, 'crystal' => 0, 'deuterium' => 0];
        $controller = $this->controller(FleetStep3Controller::class, $user, $planet, $input, 'GET');
        $session = $this->session($controller);
        $session->fleet = ['tok' => $this->prepared() + ['target' => $target['id']]];
        $session->save();

        try {
            $controller->show();
            $this->fail('a GET must end in the redirect to the fleet table');
        } catch (EndOfRequest) {
            // redirect to fleetTable
        }

        $this->assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%FLEETS%% WHERE owner = :o;', [':o' => $user['id']], 'c'));
        $this->assertSame(5, (int) $this->db->selectSingle('SELECT small_ship_cargo AS c FROM %%PLANETS%% WHERE id = :id;', [':id' => $planet['id']], 'c'));
        $this->assertArrayHasKey('tok', $this->session($controller)->fleet, 'a refused GET does not burn the prepared fleet');
    }

    public function testReleaseBeaconDropsThePreparedFleetOnlyByPost(): void
    {
        [$user, $planet] = $this->createPlayer();
        $controller = $this->controller(FleetStepAjaxController::class, $user, $planet, ['token' => 'tok'], 'GET');
        $session = $this->session($controller);
        $session->fleet = ['tok' => $this->prepared()];
        $session->save();

        $controller->show();
        $this->assertArrayHasKey('tok', $this->session($controller)->fleet, 'GET leaves the prepared fleet alone');

        $post = $this->controller(FleetStepAjaxController::class, $user, $planet, ['token' => 'tok'], 'POST');
        $post->show();
        $this->assertArrayNotHasKey('tok', $this->session($post)->fleet ?? []);
    }

    public function testStepOneStoresNothingForShipsThePlanetDoesNotHave(): void
    {
        [$user, $planet] = $this->createPlayer([], ['small_ship_cargo' => 2]);
        $controller = $this->controller(FleetStep1Controller::class, $user, $planet, ['ship202' => 50], 'POST');

        try {
            $controller->show();
            $this->fail('step 1 must refuse ships the planet does not have');
        } catch (EndOfRequest) {
            // message page
        }

        $this->assertEmpty($this->session($controller)->fleet ?? [], 'no token is stored for a refused fleet');
    }
}
