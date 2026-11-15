<?php

namespace App\Game\Core;

class DisposableEmailDomainBlocklist
{
    private const CACHE_FILE = 'others/disposable-email-domains.json';
    private const FALLBACK_DOMAINS = ['emailkeep.com'];

    private static ?array $domains = null;

    public static function isBlocked(string $email): bool
    {
        $separator = strrpos($email, '@');
        if ($separator === false) {
            return false;
        }

        $domain = self::normalizeDomain(substr($email, $separator + 1));
        if ($domain === null) {
            return false;
        }

        $blockedDomains = self::domains();
        while (true) {
            if (isset($blockedDomains[$domain])) {
                return true;
            }

            $separator = strpos($domain, '.');
            if ($separator === false) {
                return false;
            }

            $domain = substr($domain, $separator + 1);
        }
    }

    public static function updateFromList(string $contents): bool
    {
        $domains = self::parseDomainList($contents);
        if ($domains === null || !is_dir(\App\Runtime\ProjectPaths::CACHE) || !is_writable(\App\Runtime\ProjectPaths::CACHE)) {
            return false;
        }

        $cacheDirectory = \App\Runtime\ProjectPaths::CACHE . 'others' . DIRECTORY_SEPARATOR;
        if (!is_dir($cacheDirectory) && !mkdir($cacheDirectory, 0775, true) && !is_dir($cacheDirectory)) {
            return false;
        }
        if (!is_writable($cacheDirectory)) {
            return false;
        }

        $cachePath = \App\Runtime\ProjectPaths::CACHE . self::CACHE_FILE;
        $temporaryPath = tempnam($cacheDirectory, '.disposable-email-domains-');
        if ($temporaryPath === false) {
            return false;
        }

        $json = json_encode($domains, JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($temporaryPath, $json, LOCK_EX) !== strlen($json)) {
            unlink($temporaryPath);
            return false;
        }

        if (!rename($temporaryPath, $cachePath)) {
            unlink($temporaryPath);
            return false;
        }

        self::$domains = null;
        return true;
    }

    private static function domains(): array
    {
        if (self::$domains !== null) {
            return self::$domains;
        }

        $domains = array_fill_keys(self::FALLBACK_DOMAINS, true);
        $contents = \App\Runtime\SafeFile::read(\App\Runtime\ProjectPaths::CACHE . self::CACHE_FILE);
        $cachedDomains = is_string($contents) ? json_decode($contents, true) : null;
        if (is_array($cachedDomains)) {
            foreach ($cachedDomains as $cachedDomain) {
                if (is_string($cachedDomain) && ($domain = self::normalizeDomain($cachedDomain)) !== null) {
                    $domains[$domain] = true;
                }
            }
        }

        self::$domains = $domains;
        return self::$domains;
    }

    private static function parseDomainList(string $contents): ?array
    {
        $domains = [];
        foreach (preg_split('/\R/', $contents) as $lineNumber => $line) {
            $line = trim($line);
            if ($lineNumber === 0) {
                $line = ltrim($line, "\xEF\xBB\xBF");
            }
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $domain = self::normalizeDomain($line);
            if ($domain === null) {
                return null;
            }
            $domains[$domain] = true;
        }

        return $domains === [] ? null : array_keys($domains);
    }

    private static function normalizeDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        if (function_exists('idn_to_ascii')) {
            $asciiDomain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($asciiDomain === false) {
                return null;
            }
            $domain = strtolower($asciiDomain);
        }

        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $domain)) {
            return null;
        }

        return $domain;
    }
}
