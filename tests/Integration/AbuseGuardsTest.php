<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Game\AllianceController;
use App\Controller\Game\FleetMissileController;
use App\Controller\Game\MessagesController;
use App\Game\Core\EndOfRequest;
use App\Game\Core\Language;
use App\Game\Messages\MessageComposer;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Audit fixes F-06, F-09, F-11: missile attacks count towards the bash limit (switchable) and self-targets are refused, private
 * messages are capped in recipients and length and rate limited per player, an empty alliance admin action shows the overview.
 */
final class AbuseGuardsTest extends GameTestCase
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
        $this->setUniverseConfig(['noob_protection' => 0, 'bash_per_user' => 0, 'bash_per_planet' => 3, 'adm_attack' => 0, 'bash_count_missiles' => 1]);
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @param array<string, mixed> $input
     */
    private function call(string $class, string $method, array $user, array $planet, array $input, string $httpMethod = 'POST'): string
    {
        $this->state->USER = $user + ['messages' => 0, 'signalColors' => '', 'colors' => ''];
        $this->state->PLANET = $planet;
        $this->state->requestInput = $input;
        $this->state->requestPost = $input;
        $this->state->requestQuery = [];
        $this->state->requestServer['REQUEST_METHOD'] = $httpMethod;
        $controller = (new $class())->withGameServices($this->db, $this->configs, $this->state);
        $controller->setSecurityServices(
            self::container()->get(\App\Security\AuthRateLimits::class),
            self::container()->get(\App\Runtime\CanonicalUrl::class),
        );
        ob_start();
        try {
            $controller->$method();
            ob_end_clean();
            $this->fail('the page should end the request');
        } catch (EndOfRequest $end) {
            return ob_get_clean() . ($end->getResponse()?->getContent() ?? '') . $end->getOutput();
        }
    }

    /**
     * a launcher and a victim in missile range, both with a points row
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>}
     */
    private function pair(): array
    {
        [$attacker, $attackerPlanet] = $this->createPlayer(['impulse_motor_tech' => 5], ['silo' => 5, 'interplanetary_misil' => 50]);
        [$victim, $victimPlanet] = $this->createPlayer();
        foreach ([$attacker, $victim] as $user) {
            $this->db->insert('INSERT IGNORE INTO %%STATPOINTS%% SET id_owner = :id, universe = 1, stat_type = 1, total_points = 100;', [':id' => $user['id']]);
        }

        return [$attacker, $attackerPlanet, $victim, $victimPlanet];
    }

    /**
     * @param array<string, mixed> $planet
     * @return array<string, mixed>
     */
    private function missileInput(array $planet): array
    {
        return ['galaxy' => $planet['galaxy'], 'system' => $planet['system'], 'planet' => $planet['planet'], 'type' => 1, 'SendMI' => 1, 'Target' => 0];
    }

    /**
     * @param array<string, mixed> $attacker
     * @param array<string, mixed> $attackerPlanet
     * @param array<string, mixed> $victimPlanet
     */
    private function launches(array $attacker, array $attackerPlanet, array $victimPlanet, int $times): string
    {
        $out = '';
        for ($i = 0; $i < $times; $i++) {
            $attackerPlanet = $this->loadPlanet((int) $attackerPlanet['id']);
            $out = $this->call(FleetMissileController::class, 'show', $attacker, $attackerPlanet, $this->missileInput($victimPlanet));
        }

        return $out;
    }

    public function testMissilesCountTowardsTheBashLimit(): void
    {
        [$attacker, $attackerPlanet, , $victimPlanet] = $this->pair();
        $out = $this->launches($attacker, $attackerPlanet, $victimPlanet, 3);
        $this->assertStringNotContainsString(htmlspecialchars($this->lng['fl_bash_protection'], ENT_QUOTES), $out);
        $out = $this->launches($attacker, $attackerPlanet, $victimPlanet, 1);
        $this->assertStringContainsString(htmlspecialchars($this->lng['fl_bash_protection'], ENT_QUOTES), $out);
    }

    public function testMissilesAreNotCountedWhenTheSettingIsOff(): void
    {
        $this->setUniverseConfig(['bash_count_missiles' => 0]);
        [$attacker, $attackerPlanet, , $victimPlanet] = $this->pair();
        $out = $this->launches($attacker, $attackerPlanet, $victimPlanet, 5);
        $this->assertStringNotContainsString(htmlspecialchars($this->lng['fl_bash_protection'], ENT_QUOTES), $out);
        $this->assertStringContainsString(htmlspecialchars($this->lng['ma_missiles_sended'], ENT_QUOTES), $out);
    }

    public function testMissileAtOwnPlanetIsRefused(): void
    {
        [$attacker, $attackerPlanet] = $this->pair();
        $out = $this->call(FleetMissileController::class, 'show', $attacker, $attackerPlanet, $this->missileInput($attackerPlanet));
        $this->assertStringContainsString(htmlspecialchars($this->lng['ma_own_planet'], ENT_QUOTES), $out);
    }

    public function testRecipientCapRateLimitAndNormalSend(): void
    {
        [$sender, $senderPlanet] = $this->createPlayer();
        [$other] = $this->createPlayer();
        // limiter state outlives the test run (cache pool) while user ids restart with the rebuilt database
        self::container()->get('limiter.private_messages')->create((string) $sender['id'])->reset();

        $many = range($other['id'], $other['id'] + MessageComposer::MAX_RECIPIENTS);
        $out = $this->call(MessagesController::class, 'send', $sender, $senderPlanet, ['to' => $many, 'subject' => 's', 'text' => 'hi']);
        $this->assertStringContainsString(sprintf($this->lng['mg_too_many_recipients'], MessageComposer::MAX_RECIPIENTS), $out);

        $out = $this->call(MessagesController::class, 'send', $sender, $senderPlanet, ['to' => [$other['id']], 'subject' => 's', 'text' => str_repeat('a', 5001)]);
        $this->assertStringContainsString(sprintf($this->lng['mg_text_too_long'], MessageComposer::MAX_TEXT_LENGTH), $out);

        $out = $this->call(MessagesController::class, 'send', $sender, $senderPlanet, ['to' => [$other['id']], 'subject' => 's', 'text' => 'hi']);
        $this->assertStringContainsString($this->lng['mg_message_send'], $out);

        $limit = (int) self::container()->getParameter('app.pm_rate_limit');
        for ($i = 1; $i < $limit; $i++) {
            $this->call(MessagesController::class, 'send', $sender, $senderPlanet, ['to' => [$other['id']], 'subject' => 's', 'text' => 'hi']);
        }
        $out = $this->call(MessagesController::class, 'send', $sender, $senderPlanet, ['to' => [$other['id']], 'subject' => 's', 'text' => 'hi']);
        $this->assertStringContainsString($this->lng['mg_rate_limited'], $out);
    }

    public function testDeliverableReceiversHonoursBlocksInOneQuery(): void
    {
        [$sender] = $this->createPlayer();
        [$ok] = $this->createPlayer();
        [$blocked] = $this->createPlayer();
        [$blocker] = $this->createPlayer();
        $this->db->insert('INSERT INTO %%USERS_BLOCKLIST%% SET blocked_user = :a, blocking_user = :b, block_dm = 1;', [':a' => $sender['id'], ':b' => $blocked['id']]);
        $this->db->insert('INSERT INTO %%USERS_BLOCKLIST%% SET blocked_user = :a, blocking_user = :b, block_dm = 1;', [':a' => $blocker['id'], ':b' => $sender['id']]);
        $this->state->USER = $sender;
        $composer = new MessageComposer($this->db, $this->configs, $this->state);
        $this->assertSame([(int) $ok['id']], $composer->deliverableReceivers([$ok['id'], $blocked['id'], $blocker['id'], 99999999, 'x', $ok['id']]));
    }

    public function testEmptyAllianceAdminActionShowsTheOverview(): void
    {
        [$user, $planet] = $this->createPlayer();
        $this->state->USER = $user + ['messages' => 0, 'signalColors' => '', 'colors' => ''];
        $this->state->PLANET = $planet;
        $this->state->requestInput = $this->state->requestQuery = ['action' => ''];
        $controller = (new AllianceController())->withGameServices($this->db, $this->configs, $this->state);
        $controller->setSecurityServices(
            self::container()->get(\App\Security\AuthRateLimits::class),
            self::container()->get(\App\Runtime\CanonicalUrl::class),
        );
        // no alliance: the overview handler redirects home instead of recursing
        $this->expectException(EndOfRequest::class);
        $controller->admin();
    }
}
