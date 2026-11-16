<?php

/**
 * Language-driven number formatting helpers.
 *
 * Compact abbreviation labels for shortly_number, plus the default
 * decimal_separator for pretty_number / settings.
 *
 * Per-language data (suffixes, profile, decimal_separator default) lives in
 * config/languages.yaml and is loaded via (new LanguageCatalog())->getPackConfig() so adding
 * a language only requires editing that pack — not this class.
 *
 * Scale is always successive ÷1000 steps for every language. Profiles
 * (short / long / hybrid) only group languages that share the same naming
 * convention at ≥1e9; the actual suffix strings are per language pack so
 * long-scale languages stay idiomatic (e.g. French 1e9 → "Md", not German
 * "Mrd").
 *
 * There is no user-facing short/long scale setting — profile and labels are
 * derived from language only. Prefer short tokens (≤3–4 chars) for topnav
 * width; avoid SI defaults (no G for 1e9).
 *
 * Decimal separator: language pack supplies the default (en → US/UK; most
 * other packs → European). users_settings.decimal_separator remains the
 * user override; settings UI must not be removed.
 *
 * JS receives the same abbreviation list via numberSuffixes from templates/game/layout.base.html.twig.
 */
namespace App\Game\Core;

/**
 * Compact number notation (1.5 Mio, 3 Mrd, 2.4K, ...) for the top bar and tables: the suffix table per language
 * comes from config/languages.yaml (number_suffixes, or one of the profiles short/long/hybrid), and the default
 * decimal separator (European 1.234,56 or US 1,234.56) per language.
 */
class NumberAbbreviation
{
    public const PROFILE_SHORT = 'short';
    public const PROFILE_LONG = 'long';
    public const PROFILE_HYBRID = 'hybrid';

    /** European: number_format(..., ',', '.') → 1.234,56 */
    public const DECIMAL_SEPARATOR_EUROPEAN = 0;
    /** US/UK: number_format(..., '.', ',') → 1,234.56 */
    public const DECIMAL_SEPARATOR_US = 1;

    /**
     * Profile default tables — fallback when a language in config/languages.yaml omits number_suffixes,
     * and documentation of the three scale conventions.
     *
     * @var array<string, list<string>>
     */
    private static array $profiles = [
        // English short scale (familiar K/M/B/T)
        self::PROFILE_SHORT => ['', 'K', 'M', 'B', 'T', 'Q', 'Q+', 'S', 'S+', 'O', 'N'],
        // Long scale compact (DE-style defaults for unknown long-scale langs)
        self::PROFILE_LONG => ['', 'k', 'Mio', 'Mrd', 'Bio', 'Brd', 'Tr', 'Trd', 'Qa', 'Qad', 'Qi'],
        // Hybrid: ÷1000 stepping with long-scale name at 1e9 (Mrd / Mr)
        self::PROFILE_HYBRID => ['', 'K', 'M', 'Mrd', 'T', 'Q', 'Q+', 'S', 'S+', 'O', 'N'],
    ];

    /**
     * Resolve profile id for a language code. Unknown / empty → long
     * (matches DEFAULT_LANG = de and most packs).
     */
    public function profileForLang(?string $lang): string
    {
        $pack = (new LanguageCatalog())->getPackConfig($this->normalizeLang($lang));
        $profile = isset($pack['number_profile']) ? (string) $pack['number_profile'] : '';

        if (isset(self::$profiles[$profile])) {
            return $profile;
        }

        return self::PROFILE_LONG;
    }

    /**
     * @return list<string>
     */
    public function suffixesForProfile(string $profile): array
    {
        return self::$profiles[$profile] ?? self::$profiles[self::PROFILE_LONG];
    }

    /**
     * Per-language compact suffixes from config/languages.yaml, falling back to the profile
     * default table when the pack omits number_suffixes.
     *
     * @return list<string>
     */
    public function suffixesForLang(?string $lang): array
    {
        $lang = $this->normalizeLang($lang);
        $pack = (new LanguageCatalog())->getPackConfig($lang);

        if (isset($pack['number_suffixes']) && is_array($pack['number_suffixes']) && $pack['number_suffixes'] !== []) {
            /** @var list<string> $suffixes */
            $suffixes = array_values(array_map('strval', $pack['number_suffixes']));

            return $suffixes;
        }

        return $this->suffixesForProfile($this->profileForLang($lang));
    }

    /**
     * Default users_settings.decimal_separator for a language pack.
     * 0 = European (1.234,56), 1 = US/UK (1,234.56). Unknown → European.
     */
    public function defaultDecimalSeparatorForLang(?string $lang): int
    {
        $pack = (new LanguageCatalog())->getPackConfig($this->normalizeLang($lang));

        if (isset($pack['decimal_separator'])) {
            return ((int) $pack['decimal_separator'] === self::DECIMAL_SEPARATOR_US)
                ? self::DECIMAL_SEPARATOR_US
                : self::DECIMAL_SEPARATOR_EUROPEAN;
        }

        return self::DECIMAL_SEPARATOR_EUROPEAN;
    }

    private function normalizeLang(?string $lang): string
    {
        $lang = strtolower(trim((string) $lang));
        if ($lang === '') {
            $lang = DEFAULT_LANG;
        }

        return $lang;
    }
}
