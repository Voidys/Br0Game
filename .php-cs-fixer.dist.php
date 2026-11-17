<?php

/*
 * Code style for all PHP code: PER Coding Style (plus short array syntax).
 * Usage: vendor/bin/php-cs-fixer fix   (inside DDEV: ddev composer exec php-cs-fixer fix)
 */

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/config', __DIR__ . '/install', __DIR__ . '/bin', __DIR__ . '/public'])
    ->name(['*.php', 'console'])
    ->notPath(['bundles.php', 'reference.php', 'preload.php']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder);
