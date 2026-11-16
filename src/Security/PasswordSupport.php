<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Password helpers shared by login, registration, settings and the lost-password flow.
 *
 * Passwords are read raw from the request. Until round 2 of the security review they went through the game's
 * input filter (HTML-encoded, trimmed, non-ASCII replaced by "?" on the settings page) before hashing, so hashes
 * of those accounts do not match the typed password; legacyVariants() rebuilds exactly those strings so the old
 * accounts keep working (the login rehashes them with the raw password).
 */
final class PasswordSupport
{
    public const MIN_LENGTH = 6;

    /** bcrypt (cost 13) hash of a string nobody knows: verified against when the account does not exist, so unknown and known accounts cost the same time */
    private const DUMMY_HASH = '$2y$13$Mxt9i.h2fAcVsQLFxQM/R.GWqzaej1zEHw9DsERrxYA2B3HGTTmgu';

    /**
     * The values the former input filter would have produced from $raw: the registration form variant (UTF-8 kept) and
     * the settings page variant (non-ASCII bytes replaced by "?"). Both HTML-encoded and trimmed; $raw itself is not included.
     *
     * @return list<string>
     */
    public static function legacyVariants(string $raw): array
    {
        $value = trim(htmlspecialchars(str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $raw), ENT_QUOTES, 'UTF-8'));
        $variants = [];
        $variants[] = preg_match('/^./u', $value) === 1 || $value === '' ? $value : '';
        $variants[] = (string) preg_replace('/[\x80-\xFF]/', '?', $value);

        return array_values(array_filter(array_unique($variants), static fn(string $variant): bool => $variant !== $raw && $variant !== ''));
    }

    /** Checks the raw password against the hash (only the raw value; use matchesLegacy() for old hashes). */
    public static function verify(string $raw, string $hash): bool
    {
        return $hash !== '' && password_verify($raw, $hash);
    }

    /** Does an old-style hash (made from the filtered string) match? Only call after verify() failed. */
    public static function matchesLegacy(string $raw, string $hash): bool
    {
        foreach (self::legacyVariants($raw) as $variant) {
            if (password_verify($variant, $hash)) {
                return true;
            }
        }

        return false;
    }

    /** Raw or old-style match, for places that only compare (settings, admin prompt). */
    public static function verifyAny(string $raw, string $hash): bool
    {
        return self::verify($raw, $hash) || self::matchesLegacy($raw, $hash);
    }

    /** Spends the time of a password check (raw value and old-style variants, like verifyAny() on a wrong password) without an account to compare with. */
    public static function burn(string $raw): void
    {
        password_verify($raw, self::DUMMY_HASH);
        foreach (self::legacyVariants($raw) as $variant) {
            password_verify($variant, self::DUMMY_HASH);
        }
    }
}
