<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Self-hosted bot check without interaction (proof of work in the style of ALTCHA, no third party).
 *
 * The server issues a stateless challenge: a salt that carries a random id, the expiry, the issue time, the difficulty (`maxNumber`) and the minimum fill time, and
 * `sha256(salt . N)` for a secret random number N between 0 and `maxNumber`. The challenge is signed with an HMAC
 * over salt and hash, so difficulty and minimum time cannot be lowered by the client (key derived from the application secret). The browser brute-forces N (a Web Worker, see
 * public/scripts/app/pow.js) and sends back `base64(json{salt, challenge, signature, number})`.
 *
 * verify() checks, in this order: format, honeypot field empty, signature (constant time), the solution, expiry,
 * minimum fill time (issue time + `minSeconds`, signed as part of the salt) and single use (the challenge id is kept
 * in the app cache until the challenge would have expired anyway). Only a fully valid payload consumes the challenge.
 */
final class ProofOfWork
{
    /** seconds a challenge can be used after it was issued */
    public const LIFETIME = 600;

    public const REASON_MALFORMED = 'malformed';
    public const REASON_HONEYPOT = 'honeypot';
    public const REASON_SIGNATURE = 'signature';
    public const REASON_WRONG = 'wrong';
    public const REASON_EXPIRED = 'expired';
    public const REASON_TOO_FAST = 'too_fast';
    public const REASON_REPLAY = 'replay';

    public function __construct(
        private readonly string $secret,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * A new challenge for the browser.
     *
     * @param int $maxNumber upper bound of the secret number (about half of it is tried on average)
     * @param int $minSeconds seconds the form must stay open before it may be submitted (the client waits for it)
     * @param int|null $now unix time, null = now
     *
     * @return array{salt: string, challenge: string, signature: string, maxnumber: int, wait: int} `wait` = seconds until a solution is accepted
     */
    public function issue(int $maxNumber, int $minSeconds, ?int $now = null): array
    {
        $now ??= time();
        $salt = bin2hex(random_bytes(12)) . '.' . ($now + self::LIFETIME) . '.' . $now . '.' . $maxNumber . '.' . $minSeconds;
        $challenge = hash('sha256', $salt . random_int(0, $maxNumber));

        return [
            'salt' => $salt,
            'challenge' => $challenge,
            'signature' => $this->sign($salt, $challenge),
            'maxnumber' => $maxNumber,
            'wait' => $minSeconds,
        ];
    }

    /**
     * Checks a submitted solution and consumes its challenge.
     *
     * @param string $payload base64 of the JSON `{salt, challenge, signature, number}` the browser built
     * @param string $honeypot value of the hidden honeypot field (must be empty)
     * @param int $minSeconds minimum seconds between issue and submit (at least the signed one of the challenge applies)
     * @param int|null $now unix time, null = now
     *
     * @return string|null null when valid, otherwise the REASON_* constant that refused it
     */
    public function verify(string $payload, string $honeypot, int $minSeconds, ?int $now = null): ?string
    {
        $now ??= time();
        $data = json_decode((string) base64_decode($payload, true), true);
        if (!is_array($data) || !is_string($data['salt'] ?? null) || !is_string($data['challenge'] ?? null)
            || !is_string($data['signature'] ?? null) || !is_int($data['number'] ?? null) || $data['number'] < 0) {
            return self::REASON_MALFORMED;
        }
        if ($honeypot !== '') {
            return self::REASON_HONEYPOT;
        }
        if (!hash_equals($this->sign($data['salt'], $data['challenge']), $data['signature'])) {
            return self::REASON_SIGNATURE;
        }
        if (!hash_equals($data['challenge'], hash('sha256', $data['salt'] . $data['number']))) {
            return self::REASON_WRONG;
        }
        if (preg_match('/^([0-9a-f]{24})\.(\d+)\.(\d+)\.(\d+)\.(\d+)$/', $data['salt'], $parts) !== 1) {
            return self::REASON_MALFORMED;
        }
        [, $id, $expires, $issued, $max, $signedWait] = $parts;
        if ($data['number'] > (int) $max) {
            return self::REASON_WRONG;
        }
        $minSeconds = max($minSeconds, (int) $signedWait);
        if ((int) $expires < $now) {
            return self::REASON_EXPIRED;
        }
        if ($now - (int) $issued < $minSeconds) {
            return self::REASON_TOO_FAST;
        }

        return $this->consume($id, (int) $expires - $now) ? null : self::REASON_REPLAY;
    }

    /** marks the challenge id as used; false when it already was */
    private function consume(string $id, int $ttl): bool
    {
        $fresh = false;
        $this->cache->get('pow_' . $id, static function (ItemInterface $item) use (&$fresh, $ttl): bool {
            $item->expiresAfter(max(1, $ttl));
            $fresh = true;

            return true;
        });

        return $fresh;
    }

    private function sign(string $salt, string $challenge): string
    {
        return hash_hmac('sha256', 'pow1|' . $salt . '|' . $challenge, $this->secret);
    }
}
