<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\CsrfPolicy;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The one CSRF check for the game, account and admin areas (see CsrfPolicy for what is covered).
 *
 * Runs after routing but before the firewall (priority 16 > 8), so the login form, registration and
 * lost-password form are protected as well: the token is bound to the session, which exists before
 * the login. On a missing or wrong token the request ends with 403 (JSON for ajax calls, a small
 * page otherwise). A valid token field is removed from the request so game code that walks over all
 * submitted fields never sees it. Foreign top-level navigations into authenticated pages are bounced
 * to the start page of the area (CsrfPolicy::isForeignNavigation()).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final class CsrfListener
{
    public function __construct(
        private readonly CsrfPolicy $policy,
        private readonly CsrfTokenManagerInterface $tokens,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * Order of checks for the main request: bounce foreign navigations, skip requests the policy does not cover,
     * reject a missing/invalid token (403 response, request ends), otherwise strip the token field from the request.
     */
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();

        // /logout only logs out through a POST with the token; a plain visit (link, image, prefetch) does nothing
        if ($this->policy->isLogoutRoute($request) && !$request->isMethod('POST')) {
            $event->setResponse(new RedirectResponse($request->getBasePath() . '/', Response::HTTP_SEE_OTHER));

            return;
        }
        if ($this->policy->isForeignNavigation($request)) {
            $event->setResponse($this->bounce($request));

            return;
        }
        if (!$this->policy->requiresToken($request)) {
            return;
        }

        $submitted = $this->policy->submittedToken($request);
        if ($submitted === '' || !$this->tokens->isTokenValid(new CsrfToken(CsrfPolicy::TOKEN_ID, $submitted))) {
            $event->setResponse($this->reject($request));

            return;
        }
        $request->request->remove(CsrfPolicy::FIELD);
        $request->query->remove(CsrfPolicy::FIELD);
    }

    /** Is the caller a script (fetch/XHR) that expects JSON? */
    private function expectsJson(Request $request): bool
    {
        return $request->headers->has(CsrfPolicy::HEADER)
            || $request->isXmlHttpRequest()
            || $request->headers->has('X-Game-Fragment')
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json')
            || (bool) ($request->query->get('ajax') ?? $request->request->get('ajax'));
    }

    /** 403 answer for a failed check: JSON `{error, csrf, message}` for scripts, a minimal HTML page otherwise (message in de/en by Accept-Language). */
    private function reject(Request $request): Response
    {
        $locale = $request->getPreferredLanguage(['de', 'en']) ?? 'en';
        $message = $this->translator->trans('csrf_invalid', [], 'public', $locale);

        if ($this->expectsJson($request)) {
            return new JsonResponse(['error' => true, 'csrf' => true, 'message' => $message], Response::HTTP_FORBIDDEN);
        }

        $page = '<!DOCTYPE html><html lang="' . $locale . '"><head><meta charset="utf-8"><title>403</title></head>'
            . '<body><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>'
            . '<p><a href="' . htmlspecialchars($request->getBasePath() . '/', ENT_QUOTES) . '">&larr;</a></p></body></html>';

        return new Response($page, Response::HTTP_FORBIDDEN);
    }

    /** 303 redirect to the area's start page (keeping the /uniN prefix) for a foreign top-level navigation. */
    private function bounce(Request $request): Response
    {
        [$area] = $this->policy->areaAndPage($request);
        $prefix = preg_match('#^/uni\d+/#', $request->getPathInfo(), $match) === 1 ? $match[0] : '/';

        return new RedirectResponse($request->getBasePath() . $prefix . $area, Response::HTTP_SEE_OTHER);
    }
}
