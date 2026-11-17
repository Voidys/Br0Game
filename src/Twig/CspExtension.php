<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\CspNonce;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `csp_nonce()`: the request's Content-Security-Policy nonce, for the `nonce` attribute of the rare
 * inline <script> a template still needs (data islands of type application/json need none), and
 * `csp_allow('<feature>')`: the page loads that third-party feature, so the policy opens its sources.
 */
final class CspExtension extends AbstractExtension
{
    public function __construct(private readonly CspNonce $nonce) {}

    /** @return list<TwigFunction> csp_nonce() and csp_allow(feature); the latter prints nothing, it only records the feature for the policy header */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->nonce->value(...)),
            new TwigFunction('csp_allow', function (string $feature): string {
                $this->nonce->allow($feature);

                return '';
            }),
        ];
    }
}
