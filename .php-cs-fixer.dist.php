<?php

/*
 * Code style for the new code (Symfony port): PER Coding Style.
 * The legacy game code keeps its PSR-12 setup: .php-cs-fixer.legacy.php
 */

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/config', __DIR__ . '/public'])
    ->path([
        '#^Command/#', '#^Content/#', '#^Controller/#', '#^DependencyInjection/#', '#^EventListener/#',
        '#^EventSubscriber/#', '#^Routing/#', '#^Security/#', '#^Twig/#', '#^View/#',
        '#^Kernel\.php$#',
        '#^Game/Core/(EndOfRequest|DatabaseConfig)\.php$#',
        '#^[^/]+\.php$#', '#^packages/#', '#^routes/#',
    ])
    ->notPath(['bundles.php', 'reference.php', 'preload.php']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS' => true,
    ])
    ->setFinder($finder);
