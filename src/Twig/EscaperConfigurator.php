<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Bundle\TwigBundle\DependencyInjection\Configurator\EnvironmentConfigurator;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Twig\Environment;
use Twig\Runtime\EscaperRuntime;

/**
 * Registers the "game_html" escaping strategy (App\Twig\GameEscaping) on every Twig environment
 * the container builds, so web requests, console commands (cron, lint:twig) and tests share it.
 */
#[AsDecorator('twig.configurator.environment')]
final class EscaperConfigurator
{
    public function __construct(
        #[AutowireDecorated]
        private readonly EnvironmentConfigurator $inner,
    ) {}

    public function configure(Environment $environment): void
    {
        $this->inner->configure($environment);
        $environment->getRuntime(EscaperRuntime::class)->setEscaper(GameEscaping::STRATEGY, GameEscaping::escape(...));
    }
}
