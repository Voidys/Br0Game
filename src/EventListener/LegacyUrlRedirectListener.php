<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The game's URLs carry no file extension (/game, /admin, /account, /CombatReport, / for the login,
 * /install/ for the installer).
 * GET/HEAD requests to the former *.php URLs (bookmarks, external links) get a permanent redirect
 * to the clean URL with the same query; other methods are still served under the old path, so a
 * form posted from a page cached before the switch keeps working.
 */
final class LegacyUrlRedirectListener
{
    private const PATTERN = '#^(?<prefix>/(?:uni\d+/|install/)?)(?:(?<area>game|admin|account|CombatReport)\.php|index\.php)$#';

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethodCacheable()) {
            return;
        }

        $path = (string) parse_url($request->getRequestUri(), PHP_URL_PATH);
        if (!preg_match(self::PATTERN, $path, $match)) {
            return;
        }

        $target = $match['prefix'] . ($match['area'] ?? '');
        $query = $request->server->get('QUERY_STRING', '');
        $event->setResponse(new RedirectResponse($target . ($query !== '' ? '?' . $query : ''), 301));
    }
}
