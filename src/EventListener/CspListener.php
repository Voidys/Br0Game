<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\ContentSecurityPolicy;
use App\Security\CspNonce;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds the Content-Security-Policy (see App\Security\ContentSecurityPolicy) to the HTML responses of
 * the game areas, either enforcing or report-only depending on CSP_MODE and the area. The nonce is the
 * request's CspNonce, the same one the templates print into their inline scripts.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -65)]
final class CspListener
{
    public function __construct(
        private readonly ContentSecurityPolicy $policy,
        private readonly CspNonce $nonce,
    ) {}

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $response = $event->getResponse();
        $type = (string) $response->headers->get('Content-Type', '');
        if (!str_starts_with($type, 'text/html') || $response->headers->has(ContentSecurityPolicy::HEADER_ENFORCE)) {
            return;
        }
        $header = $this->policy->headerFor($this->policy->areaOf($event->getRequest()));
        if ($header === null) {
            return;
        }
        $response->headers->set($header, $this->policy->build($this->nonce->value(), $this->nonce->features()));
    }
}
