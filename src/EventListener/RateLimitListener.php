<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\Account;
use App\Security\GameRateLimits;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Per-account (visitors: per-IP) request budget of the game and account areas (see GameRateLimits). Runs after the firewall
 * (priority 4 < 8), so the logged-in account is known; visitors are limited per IP on the public pages only.
 *
 * Over the limit: script calls (fetch/XHR) get a JSON 429 right away. Page loads continue to the normal game
 * controller wrapper (GameControllerListener), which boots the game and shows the translated, themed message page
 * instead of running the page (request attribute RETRY_AFTER); onResponse() makes that page a 429 with Retry-After.
 */
final class RateLimitListener
{
    public const RETRY_AFTER = '_rate_limited';

    public function __construct(
        private readonly GameRateLimits $limits,
        private readonly TokenStorageInterface $tokens,
        private readonly TranslatorInterface $translator,
    ) {}

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $user = $this->tokens->getToken()?->getUser();
        $request = $event->getRequest();
        $retry = $user instanceof Account
            ? $this->limits->check($request, $user->getId())
            : $this->limits->checkAnonymous($request, $request->getClientIp());
        if ($retry === null) {
            return;
        }

        if ($this->expectsJson($request)) {
            $locale = $request->getPreferredLanguage(['de', 'en']) ?? 'en';
            $message = sprintf($this->translator->trans('rl_too_many_requests', [], 'ingame', $locale), $retry);
            $event->setResponse($this->withRetryAfter(new JsonResponse(['error' => true, 'rateLimited' => true, 'message' => $message], Response::HTTP_TOO_MANY_REQUESTS), $retry));

            return;
        }
        $request->attributes->set(self::RETRY_AFTER, $retry);
    }

    /** Turns the "slow down" page of a limited page load into a 429 with Retry-After. */
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $retry = $event->getRequest()->attributes->get(self::RETRY_AFTER);
        if ($event->isMainRequest() && is_int($retry)) {
            $response = $event->getResponse();
            $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);
            $this->withRetryAfter($response, $retry);
        }
    }

    private function withRetryAfter(Response $response, int $seconds): Response
    {
        $response->headers->set('Retry-After', (string) $seconds);

        return $response;
    }

    /** Is the caller a script (fetch/XHR) that expects JSON? (same test as CsrfListener) */
    private function expectsJson(Request $request): bool
    {
        return $request->headers->has('X-CSRF-Token')
            || $request->isXmlHttpRequest()
            || $request->headers->has('X-Game-Fragment')
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json')
            || (bool) ($request->query->get('ajax') ?? $request->request->get('ajax'));
    }
}
