<?php

namespace App\Game\Core;

/**
 * Language pack metadata (config/languages.yaml, language list cache); formerly static methods
 * of Language. Dependency-free, memoized per request.
 */
class LanguageCatalog
{
    private static array $allLanguages = [];

    /**
     * Languages the game offers.
     * @param bool $OnlyKey true: codes only; false: code => display name.
     */
    public function getAllowedLangs(bool $OnlyKey = true): array
    {
        if (empty(self::$allLanguages)) {
            $cache	= new Cache(); // the language cache reads files only
            self::$allLanguages = $cache->getData('language');
        }

        if ($OnlyKey) {
            return array_keys(self::$allLanguages);
        }

        // LanguageBuildCache stores the full pack metadata; UI selectors need code => name.
        // Older caches may still hold plain name strings until rebuilt.
        $names = [];
        foreach (self::$allLanguages as $code => $meta) {
            $names[$code] = is_array($meta) ? ($meta['name'] ?? $code) : $meta;
        }

        return $names;
    }

    /**
     * All selectable language packs, locale => metadata (config/languages.yaml).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getPacks(): array
    {
        static $packs = null;

        if ($packs === null) {
            $root = \App\Runtime\ProjectPaths::ROOT;
            $packs = \Symfony\Component\Yaml\Yaml::parseFile($root . 'config/languages.yaml');
        }

        return $packs;
    }

    /**
     * Metadata of a language pack (suffixes, profile, …) from config/languages.yaml,
     * read directly (memoized per request) so number formatting does not depend on the
     * language list cache being rebuilt. LanguageBuildCache stores the same metadata
     * for the allowed-lang list.
     *
     * @return array<string, mixed>
     */
    public function getPackConfig(?string $lang = null): array
    {
        static $packMemo = [];

        $lang = strtolower(trim((string) $lang));
        if ($lang === '') {
            $lang = DEFAULT_LANG;
        }

        if (array_key_exists($lang, $packMemo)) {
            return $packMemo[$lang];
        }

        $packs = $this->getPacks();
        $packMemo[$lang] = $packs[$lang] ?? [];

        return $packMemo[$lang];
    }
}
