<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Baseline security headers on every response (a header a controller already set is kept):
 * no MIME sniffing, the referrer only goes to the same origin, framing only by the same origin and
 * no browser features the game does not use. A Content-Security-Policy is a separate step.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -64)]
final class SecurityHeadersListener
{
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'same-origin',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
    ];

    /** Adds the HEADERS to the main response unless a controller set them already. */
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $headers = $event->getResponse()->headers;
        foreach (self::HEADERS as $name => $value) {
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        }
    }
}
