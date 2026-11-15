<?php

/*
 * Code style for the legacy game code (PSR-12). New code uses .php-cs-fixer.dist.php (PER-CS).
 * Usage: vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.legacy.php
 */

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['cache', 'var', 'vendor', 'config', 'public', 'templates'])
    ->notPath([
        '#^src/(Command|Content|Controller|DependencyInjection|EventListener|EventSubscriber|Routing|Security|Twig|View)/#',
        '#^src/Kernel\.php$#',
        '#^src/Game/Core/(EndOfRequest|DatabaseConfig)\.php$#',
    ]);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder);
