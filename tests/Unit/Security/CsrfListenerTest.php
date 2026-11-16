<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Controller\GameControllerInterface;
use App\EventListener\CsrfListener;
use App\Security\CsrfPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The central CSRF check (CsrfListener + CsrfPolicy): which requests need the token, where it may be sent,
 * what a failure looks like (JSON for scripts, a page otherwise) and the cross-site navigation guard.
 * No kernel and no database: the listener only needs the request attributes the router would set.
 */
final class CsrfListenerTest extends TestCase
{
    private CsrfTokenManager $tokens;
    private CsrfListener $listener;

    protected function setUp(): void
    {
        $storage = new class implements TokenStorageInterface {
            /** @var array<string, string> */
            private array $values = [];

            public function getToken(string $tokenId): string
            {
                return $this->values[$tokenId] ?? '';
            }

            public function setToken(string $tokenId, #[\SensitiveParameter] string $token): void
            {
                $this->values[$tokenId] = $token;
            }

            public function hasToken(string $tokenId): bool
            {
                return isset($this->values[$tokenId]);
            }

            public function removeToken(string $tokenId): ?string
            {
                $old = $this->values[$tokenId] ?? null;
                unset($this->values[$tokenId]);

                return $old;
            }
        };
        $this->tokens = new CsrfTokenManager(new UriSafeTokenGenerator(), $storage);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('csrf failed');

        $this->listener = new CsrfListener(new CsrfPolicy(), $this->tokens, $translator);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     */
    private function request(string $method, string $route, array $query = [], array $post = [], array $headers = [], string $controller = FixtureGameController::class . '::show'): Request
    {
        $request = Request::create('/game', $method, $method === 'GET' ? $query : $post + [], [], [], []);
        if ($method !== 'GET') {
            $request->query->add($query);
        }
        $request->attributes->set('_route', $route);
        $request->attributes->set('_controller', $controller);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    private function dispatch(Request $request): RequestEvent
    {
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        ($this->listener)($event);

        return $event;
    }

    private function token(): string
    {
        return $this->tokens->getToken(CsrfPolicy::TOKEN_ID)->getValue();
    }

    public function testPostWithValidFieldPassesAndTokenIsRemoved(): void
    {
        $request = $this->request('POST', 'game_game_settings', post: [CsrfPolicy::FIELD => $this->token(), 'name' => 'x']);
        $event = $this->dispatch($request);

        self::assertFalse($event->hasResponse());
        self::assertFalse($request->request->has(CsrfPolicy::FIELD));
        self::assertSame('x', $request->request->get('name'));
    }

    public function testPostWithValidHeaderPasses(): void
    {
        $event = $this->dispatch($this->request('POST', 'game_game_settings', headers: [CsrfPolicy::HEADER => $this->token()]));

        self::assertFalse($event->hasResponse());
    }

    public function testPostWithoutTokenIsRejectedWithPage(): void
    {
        $event = $this->dispatch($this->request('POST', 'game_game_settings', post: ['name' => 'x']));

        self::assertSame(403, $event->getResponse()?->getStatusCode());
        self::assertStringContainsString('csrf failed', (string) $event->getResponse()?->getContent());
        self::assertNotInstanceOf(JsonResponse::class, $event->getResponse());
    }

    public function testPostWithWrongTokenIsRejected(): void
    {
        $this->token();
        $event = $this->dispatch($this->request('POST', 'game_game_settings', post: [CsrfPolicy::FIELD => 'forged.token.value']));

        self::assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function testLogoutRouteByGetOnlyRedirectsAndNeverLogsOut(): void
    {
        $event = $this->dispatch($this->request('GET', 'app_logout', controller: 'App\\Controller\\LogoutController'));

        self::assertSame(303, $event->getResponse()?->getStatusCode());
    }

    public function testLogoutRoutePostNeedsTheToken(): void
    {
        $rejected = $this->dispatch($this->request('POST', 'app_logout', controller: 'App\\Controller\\LogoutController'));
        $accepted = $this->dispatch($this->request('POST', 'app_logout', post: [CsrfPolicy::FIELD => $this->token()], controller: 'App\\Controller\\LogoutController'));

        self::assertSame(403, $rejected->getResponse()?->getStatusCode());
        self::assertFalse($accepted->hasResponse());
    }

    public function testAccountModeSendByGetNeedsTheToken(): void
    {
        foreach (['game_account_register' => 'send', 'game_account_account' => 'send'] as $route => $mode) {
            $event = $this->dispatch($this->request('GET', $route, query: ['mode' => $mode]));

            self::assertSame(403, $event->getResponse()?->getStatusCode(), $route);
        }
    }

    /** @return iterable<string, array{array<string, string>, array<string, mixed>}> */
    public static function ajaxCalls(): iterable
    {
        yield 'fetch header present but wrong' => [[CsrfPolicy::HEADER => 'wrong'], []];
        yield 'X-Requested-With' => [['X-Requested-With' => 'XMLHttpRequest'], []];
        yield 'Accept json' => [['Accept' => 'application/json'], []];
        yield 'ajax=1' => [[], ['ajax' => '1']];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     */
    #[DataProvider('ajaxCalls')]
    public function testAjaxFailureIsJson(array $headers, array $query): void
    {
        $event = $this->dispatch($this->request('POST', 'game_game_messages', query: $query, headers: $headers));

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(403, $response->getStatusCode());
        self::assertTrue(json_decode((string) $response->getContent(), true)['csrf']);
    }

    public function testPlainGetNeedsNoToken(): void
    {
        self::assertFalse($this->dispatch($this->request('GET', 'game_game_overview'))->hasResponse());
        self::assertFalse($this->dispatch($this->request('GET', 'game_game_buddylist', ['mode' => 'show']))->hasResponse());
    }

    public function testStateChangingGetNeedsTokenAndAcceptsItInTheQuery(): void
    {
        $query = ['mode' => 'delete', 'id' => '5'];

        self::assertSame(403, $this->dispatch($this->request('GET', 'game_game_buddylist', $query))->getResponse()?->getStatusCode());
        self::assertFalse($this->dispatch($this->request('GET', 'game_game_buddylist', $query + [CsrfPolicy::FIELD => $this->token()]))->hasResponse());
    }

    public function testAdminActionGetsAreCoveredButViewsAreNot(): void
    {
        self::assertSame(403, $this->dispatch($this->request('GET', 'game_admin_cronjob', ['action' => 'run', 'id' => '1']))->getResponse()?->getStatusCode());
        self::assertSame(403, $this->dispatch($this->request('GET', 'game_admin_module', ['mode' => 'aktiv', 'id' => '3']))->getResponse()?->getStatusCode());
        self::assertSame(403, $this->dispatch($this->request('GET', 'game_admin_search', ['delete' => 'user', 'user' => '5']))->getResponse()?->getStatusCode());
        self::assertSame(403, $this->dispatch($this->request('GET', 'game_admin_flyingfleet', ['id' => '3', 'lock' => '1']))->getResponse()?->getStatusCode());
        self::assertSame(403, $this->dispatch($this->request('GET', 'game_admin_flyingfleet', ['massunlock' => '1']))->getResponse()?->getStatusCode());
        self::assertFalse($this->dispatch($this->request('GET', 'game_admin_cronjob', ['action' => 'detail', 'id' => '1']))->hasResponse());
        self::assertFalse($this->dispatch($this->request('GET', 'game_admin_overview'))->hasResponse());
    }

    public function testControllersOutsideTheGameAreNotChecked(): void
    {
        $event = $this->dispatch($this->request('POST', 'wiki_sso', controller: \stdClass::class . '::__invoke'));

        self::assertFalse($event->hasResponse());
    }

    public function testForeignNavigationIntoAuthenticatedPageIsBounced(): void
    {
        $headers = ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'navigate'];

        $response = $this->dispatch($this->request('GET', 'game_game_alliance', ['mode' => 'apply'], headers: $headers))->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/game', $response->getTargetUrl());

        // public pages and ordinary same-origin/typed-in navigation are left alone
        self::assertFalse($this->dispatch($this->request('GET', 'game_game_raport', headers: $headers))->hasResponse());
        self::assertFalse($this->dispatch($this->request('GET', 'game_game_alliance', headers: ['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Mode' => 'navigate']))->hasResponse());
        self::assertFalse($this->dispatch($this->request('GET', 'game_game_alliance', headers: ['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Mode' => 'navigate']))->hasResponse());
        // the login area (index) can be linked from anywhere (e-mail links)
        self::assertFalse($this->dispatch($this->request('GET', 'game_index_lostpassword', headers: $headers))->hasResponse());
    }
}

/** stands in for a routed game controller (the policy only looks at the interface) */
final class FixtureGameController implements GameControllerInterface
{
    public static function getGameMode(): string
    {
        return 'INGAME';
    }

    public static function getEntryScript(): string
    {
        return 'game.php';
    }

    public function initialize(): void {}
}
