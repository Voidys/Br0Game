<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Game\FleetMissileController;
use App\Controller\Game\FleetStep2Controller;
use App\Game\Core\EndOfRequest;
use App\Game\Core\FleetFunctions;
use App\Game\Core\Language;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Invalid fleet input ends in the game's normal message/redirect instead of an exception: a missile launch at a planet that does
 * not exist, a fleet step 2 request with a speed setting that is not allowed (0 used to divide by zero), and the flight
 * duration formula itself refuses a non-positive speed with a clear exception.
 */
final class FleetInputGuardsTest extends GameTestCase
{
    private Language $lng;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lng = new Language($this->configs, 'en');
        $this->lng->includeData(['L18N', 'INGAME', 'TECH', 'FLEET', 'CUSTOM']);
        $this->state->LNG = $this->lng;
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::container()->get('request_stack')->push($request);
        $this->state->request = $request;
        $this->state->time = ['start' => microtime(true), 'end_common' => microtime(true), 'db' => 0];
    }

    /** @param array<string, mixed> $input */
    private function callShow(string $class, array $user, array $planet, array $input): string
    {
        // the message page renders the navigation, which reads the unread message counter and the colour settings
        $this->state->USER = $user + ['messages' => 0, 'signalColors' => '', 'colors' => ''];
        $this->state->PLANET = $planet;
        $this->state->requestInput = $input;
        $this->state->requestPost = $input;
        $this->state->requestQuery = [];
        $this->state->requestServer = ($this->state->requestServer ?? []) + [];
        $this->state->requestServer['REQUEST_METHOD'] = 'POST';
        $controller = (new $class())->withGameServices($this->db, $this->configs, $this->state);
        try {
            $controller->show();
            $this->fail('the page should end the request');
        } catch (EndOfRequest $end) {
            return ($end->getResponse()?->getContent() ?? '') . $end->getOutput();
        }
    }

    public function testMissileAtAnUnknownPlanetShowsTheNotFoundMessage(): void
    {
        [$user, $planet] = $this->createPlayer(['impulse_motor_tech' => 5], ['silo' => 5, 'interplanetary_misil' => 10]);
        $out = $this->callShow(FleetMissileController::class, $user, $planet, [
            'galaxy' => $planet['galaxy'], 'system' => 499, 'planet' => 3, 'type' => 1, 'SendMI' => 1, 'Target' => 0,
        ]);
        // the message is HTML-escaped in the page (apostrophes become &#039;)
        $this->assertStringContainsString(htmlspecialchars($this->lng['ma_planet_doesnt_exists'], ENT_QUOTES), $out);
    }

    public function testStepTwoWithADisallowedSpeedGoesBackToTheFleetPage(): void
    {
        foreach ([0, 11] as $speed) {
            [$user, $planet] = $this->createPlayer([], ['small_ship_cargo' => 5, 'deuterium' => 100000]);
            $this->state->USER = $user;
            $this->state->PLANET = $planet;
            $this->state->requestServer['REQUEST_METHOD'] = 'POST';
            $controller = (new FleetStep2Controller())->withGameServices($this->db, $this->configs, $this->state);
            $session = $this->sessionOf($controller);
            $session->fleet = ['tok' => ['fleet' => [202 => 1], 'fleetRoom' => 5000, 'predefinedResources' => []]];
            $session->save();
            $this->state->requestInput = ['token' => 'tok', 'galaxy' => $planet['galaxy'], 'system' => $planet['system'], 'planet' => $planet['planet'], 'type' => 1, 'speed' => $speed];
            try {
                $controller->show();
                $this->fail('step 2 should end the request');
            } catch (EndOfRequest) {
                $this->assertTrue(true, "speed $speed ends the request instead of dividing by zero");
            }
        }
    }

    public function testDurationFormulaRejectsANonPositiveSpeed(): void
    {
        $fleet = new FleetFunctions($this->db, $this->configs, $this->state);
        [$user] = $this->createPlayer();
        $this->expectException(\DomainException::class);
        $fleet->getMissionDuration(0, 5000, 1000, 1, $user);
    }

    public function testDurationFormulaRejectsAZeroFleetSpeed(): void
    {
        $fleet = new FleetFunctions($this->db, $this->configs, $this->state);
        [$user] = $this->createPlayer();
        $this->expectException(\DomainException::class);
        $fleet->getMissionDuration(10, 0, 1000, 1, $user);
    }

    private function sessionOf(object $controller): object
    {
        $m = new \ReflectionMethod($controller, 'sessions');
        return $m->invoke($controller)->load();
    }
}
