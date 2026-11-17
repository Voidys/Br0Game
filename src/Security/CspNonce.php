<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The per-request nonce of the Content-Security-Policy. Generated on first use, so requests that
 * never print an inline script (JSON, redirects) pay nothing. The same value goes into the
 * `script-src 'nonce-…'` header (CspListener) and into the `nonce` attribute of the few inline
 * scripts the templates still contain (Twig function csp_nonce()). Also remembers which optional
 * third-party features the rendered page uses (csp_allow('<feature>')), so the policy opens only
 * what the page needs.
 */
final class CspNonce implements ResetInterface
{
    private ?string $value = null;

    /** @var array<string, true> */
    private array $features = [];

    /** @var array<string, array<string, true>> directive => extra sources for this response */
    private array $sources = [];

    /** the nonce (128 random bits, base64) */
    public function value(): string
    {
        return $this->value ??= base64_encode(random_bytes(16));
    }

    /** whether a template asked for the nonce, i.e. the response contains nonce-protected scripts */
    public function wasUsed(): bool
    {
        return $this->value !== null;
    }

    /** the page uses an optional third-party feature (see ContentSecurityPolicy::FEATURES) */
    public function allow(string $feature): void
    {
        $this->features[$feature] = true;
    }

    /** @return list<string> */
    public function features(): array
    {
        return array_keys($this->features);
    }

    /**
     * Opens one extra origin for one directive of this response only, e.g. the configured wiki for the
     * `form-action` of the wiki SSO hand-off. Only plain http(s) origins are accepted (scheme, host,
     * optional port; path/query are dropped), anything else is ignored.
     */
    public function allowSource(string $directive, string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
            return;
        }
        $origin = $scheme . '://' . strtolower($host) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
        $this->sources[$directive][$origin] = true;
    }

    /** @return array<string, list<string>> directive => extra sources opened for this response */
    public function sources(): array
    {
        return array_map(static fn(array $origins): array => array_keys($origins), $this->sources);
    }

    public function reset(): void
    {
        $this->features = [];
        $this->sources = [];
        $this->value = null;
    }
}
