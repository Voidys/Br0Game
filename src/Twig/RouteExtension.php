<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * URLs for scripts. `path()` / `url()` (Symfony) give the link of a route inside the universe directory of the
 * request; `universe_path()` gives the same route relative to a universe directory (no leading slash, no
 * `uniN/`, no base path), for scripts that compose the URL themselves, e.g. the start page switching a form to
 * another universe: `data-action="{{ universe_path('login_login_send') }}"`.
 */
final class RouteExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly RequestStack $requests,
    ) {}

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [new TwigFunction('universe_path', $this->universePath(...))];
    }

    /**
     * @param array<string, scalar> $parameters
     */
    public function universePath(string $route, array $parameters = []): string
    {
        $path = $this->router->generate($route, ['uni' => ''] + $parameters);
        $base = $this->requests->getCurrentRequest()?->getBasePath() ?? '';

        return ltrim(substr($path, strlen($base)), '/');
    }
}
