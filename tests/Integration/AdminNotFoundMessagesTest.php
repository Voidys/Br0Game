<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Admin\AccountDataController;
use App\Controller\Admin\SearchController;
use App\Controller\Admin\SendMessagesController;
use App\Game\Core\EndOfRequest;
use App\Game\Core\Language;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Admin pages that used to fail on empty results: an unknown player id on the account data page, deleting an unknown
 * player or planet from the search, and a mass mail without any matching recipient. Each ends with a normal message page.
 */
final class AdminNotFoundMessagesTest extends GameTestCase
{
    private Language $lng;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lng = new Language($this->configs, 'en');
        $this->lng->includeData(['L18N', 'INGAME', 'ADMIN', 'CUSTOM']);
        $this->state->LNG = $this->lng;
        // the message page renders a CSRF token: give the request stack a session
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::container()->get('request_stack')->push($request);
    }

    /** @param array<string, mixed> $input */
    private function call(object $controller, string $method, array $input, array $post = [], ?array $query = null): string
    {
        [$admin] = $this->createPlayer(['authlevel' => AUTH_ADM]);
        $this->state->USER = $admin;
        $this->state->universeEmulated = 1;
        $this->state->requestInput = $input + $post;
        $this->state->requestPost = $post;
        $this->state->requestQuery = $query ?? $input;
        try {
            $controller->{$method}();
            $this->fail('the page should end with a message');
        } catch (EndOfRequest $end) {
            return ($end->getResponse()?->getContent() ?? '') . $end->getOutput();
        }
    }

    private function controller(string $class): object
    {
        return (new $class())->withGameServices($this->db, $this->configs, $this->state);
    }

    public function testAccountDataWithUnknownIdShowsNotFoundMessage(): void
    {
        $out = $this->call($this->controller(AccountDataController::class), 'show', ['id_u' => 987654321]);
        $this->assertStringContainsString($this->lng['ac_username_doesnt'], $out);
    }

    public function testDeletingAnUnknownPlanetShowsNotFoundMessage(): void
    {
        $out = $this->call($this->controller(SearchController::class), 'show', ['delete' => 'planet', 'planet' => 987654321]);
        $this->assertStringContainsString($this->lng['se_delete_not_found'], $out);
    }

    public function testDeletingAnUnknownPlayerShowsNotFoundMessage(): void
    {
        $out = $this->call($this->controller(SearchController::class), 'show', ['delete' => 'user', 'user' => 987654321]);
        $this->assertStringContainsString($this->lng['se_delete_not_found'], $out);
    }

    public function testDeletingAnExistingPlayerStillReportsSuccess(): void
    {
        [$user] = $this->createPlayer();
        $out = $this->call($this->controller(SearchController::class), 'show', ['delete' => 'user', 'user' => (int) $user['id']]);
        $this->assertStringContainsString($this->lng['se_delete_succes_p'], $out);
    }

    public function testMassMailWithoutRecipientsSendsNothingAndSaysSo(): void
    {
        $out = $this->call(
            $this->controller(SendMessagesController::class),
            'show',
            ['action' => 'send', 'mode' => 1, 'subject' => 'S', 'text' => 'T', 'globalmessageuniverse' => 987654321],
            ['subject' => 'S', 'text' => 'T', 'mode' => 1],
        );
        $this->assertStringContainsString($this->lng['ma_no_recipients'], $out);
        $this->assertStringNotContainsString($this->lng['ma_message_sended'], $out);
    }
}
