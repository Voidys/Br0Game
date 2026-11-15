<?php

namespace App\Runtime;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Metadata (config/languages.yaml) and text templates (templates/texts/<locale>/) of the game languages.
 * Messages themselves are served by the Symfony translator (see LegacyTranslationsPass).
 */
final class LanguagePacks
{
    /** @var array<string, string>|null */
    private ?array $names = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $packs = null;

    /**
     * @param list<string> $locales
     */
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        #[Autowire(param: 'app.locales')]
        private readonly array $locales,
        #[Autowire(param: 'kernel.default_locale')]
        private readonly string $defaultLocale,
    ) {}

    /**
     * Available languages, locale => display name (config/languages.yaml).
     *
     * @return array<string, string>
     */
    public function getNames(): array
    {
        if ($this->names !== null) {
            return $this->names;
        }

        $this->names = [];
        foreach ($this->locales as $locale) {
            $this->names[$locale] = $this->readConfig($locale)['name'] ?? $locale;
        }

        return $this->names;
    }

    /**
     * A text template (templates/texts/<locale>/<name>.txt), falling back to the default locale.
     */
    public function getTemplate(string $name, string $locale): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException(sprintf('Invalid template name "%s".', $name));
        }

        foreach (array_unique([$locale, $this->defaultLocale]) as $candidate) {
            $file = sprintf('%s/templates/texts/%s/%s.txt', $this->projectDir, $candidate, $name);
            if (in_array($candidate, $this->locales, true) && is_file($file)) {
                return (string) file_get_contents($file);
            }
        }

        throw new \RuntimeException(sprintf('Language template "%s" not found.', $name));
    }

    /**
     * @return array<string, mixed>
     */
    private function readConfig(string $locale): array
    {
        $this->packs ??= Yaml::parseFile($this->projectDir . '/config/languages.yaml');

        return $this->packs[$locale] ?? [];
    }
}
