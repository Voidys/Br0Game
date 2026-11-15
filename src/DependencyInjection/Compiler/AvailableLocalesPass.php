<?php

namespace App\DependencyInjection\Compiler;

use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;

/**
 * Sets the parameter "app.locales": the selectable languages from config/languages.yaml
 * (same source as the game's LanguageBuildCache).
 *
 * The messages themselves are regular Symfony translation files in translations/
 * (<domain>.<locale>.yaml, e.g. public.de.yaml), used by both Symfony and the game code.
 */
final class AvailableLocalesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $file = $container->getParameter('kernel.project_dir') . '/config/languages.yaml';

        $locales = array_keys(Yaml::parseFile($file));
        sort($locales);

        $container->setParameter('app.locales', $locales);
        $container->addResource(new FileResource($file));
    }
}
