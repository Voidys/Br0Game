<?php

declare(strict_types=1);

namespace App\Runtime;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Absolute URLs for links that leave the site (e-mails: password reset, account verification).
 *
 * They are built from the configured base URL (env DEFAULT_URI, written by the installer) and not from
 * the request's Host header, which an attacker controls (password reset poisoning). Only while
 * DEFAULT_URI is unset or still the placeholder http://localhost, the request's own address is used.
 */
final class CanonicalUrl
{
    public function __construct(
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
    ) {}

    /**
     * @param string $relative page URL relative to the universe directory, e.g. "./?page=vertify&i=1&k=abc"
     */
    public function forPage(RuntimeContext $runtime, string $relative): string
    {
        $parts = parse_url($this->defaultUri);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        if ($host === '' || $host === 'localhost') {
            return $runtime->httpPath . $relative;
        }

        $origin = ($parts['scheme'] ?? 'https') . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $origin . $runtime->httpRoot . $relative;
    }
}
