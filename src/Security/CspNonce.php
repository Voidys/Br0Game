<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The per-request nonce of the Content-Security-Policy. Generated on first use, so requests that
 * never print an inline script (JSON, redirects) pay nothing. The same value goes into the
 * `script-src 'nonce-…'` header (CspListener) and into the `nonce` attribute of the few inline
 * scripts the templates still contain (Twig function csp_nonce()). Also remembers which optional
 * third-party features the rendered page uses (csp_allow('recaptcha')), so the policy opens only
 * what the page needs.
 */
final class CspNonce implements ResetInterface
{
    private ?string $value = null;

    /** @var array<string, true> */
    private array $features = [];

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

    public function reset(): void
    {
        $this->features = [];
        $this->value = null;
    }
}
