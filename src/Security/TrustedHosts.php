<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Builds the Request::setTrustedHosts() patterns from the TRUSTED_HOSTS environment variable: comma separated host
 * names ("example.com,www.example.com"); "*.example.com" also admits sub-domains (uni2.example.com) and the bare
 * domain. Empty = no restriction (development).
 */
final class TrustedHosts
{
    /** @return list<string> regular expressions without delimiters, as Request::setTrustedHosts() expects */
    public static function patterns(string $value): array
    {
        $patterns = [];
        foreach (explode(',', $value) as $host) {
            $host = strtolower(trim($host));
            if ($host === '') {
                continue;
            }
            if (str_starts_with($host, '*.')) {
                $patterns[] = '^(.+\.)?' . preg_quote(substr($host, 2), '#') . '$';
            } else {
                $patterns[] = '^' . preg_quote($host, '#') . '$';
            }
        }

        return $patterns;
    }
}
