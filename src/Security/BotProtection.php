<?php

declare(strict_types=1);

namespace App\Security;

use App\Runtime\GameConfig;

/**
 * The bot check of the public forms (registration, lost password): ProofOfWork with difficulty and minimum fill time
 * from the basic settings (category `proofofwork`). Form fields: `pow` (solution, written by public/scripts/app/pow.js)
 * and `website` (honeypot, hidden from humans).
 *
 * Adaptive difficulty (setting `pow_adaptive`, default on): PowRisk rates the client IP and its /24 (/64) prefix from
 * recent challenges, failures and successes; normal clients get `pow_max_number`, elevated ones ×`pow_elevated_factor`
 * and high ones ×`pow_high_factor`, never more than `pow_max_cap` (a slow phone hashes roughly 30000/s, the average
 * solve takes half the maximum: 1.5 million = about 25 s at the high tier) and with a longer minimum fill time. The
 * chosen values are part of the signed challenge. With the switch off every visitor gets the base value and no
 * counter is read or written.
 */
final class BotProtection
{
    public const FIELD = 'pow';
    public const HONEYPOT = 'website';

    private const DEFAULT_MAX_NUMBER = 25000;
    private const DEFAULT_MIN_SECONDS = 3;
    private const DEFAULT_FACTORS = [PowRisk::TIER_ELEVATED => 20, PowRisk::TIER_HIGH => 100];
    private const DEFAULT_CAP = 1500000;
    /** extra minimum fill time (seconds) per tier */
    private const WAIT_BONUS = [PowRisk::TIER_NORMAL => 0, PowRisk::TIER_ELEVATED => 2, PowRisk::TIER_HIGH => 5];

    public function __construct(
        private readonly ProofOfWork $proof,
        private readonly GameConfig $config,
        private readonly PowRisk $risk,
    ) {}

    /**
     * A fresh challenge for the client; counts as one issued challenge of the IP.
     *
     * @return array{salt: string, challenge: string, signature: string, maxnumber: int, wait: int}
     */
    public function challenge(string $ip, ?int $now = null): array
    {
        $tier = $this->tier($ip, $now);
        $base = $this->maxNumber();
        $max = $tier === PowRisk::TIER_NORMAL ? $base : max($base, min($this->int('pow_max_cap', self::DEFAULT_CAP, 1000, 50000000), $base * $this->factor($tier)));
        $wait = min(60, $this->minSeconds() + self::WAIT_BONUS[$tier]);
        if ($this->adaptive()) {
            $this->risk->record(PowRisk::KIND_CHALLENGE, $ip, $now);
        }

        return $this->proof->issue($max, $wait, $now);
    }

    /** whether the submitted solution and honeypot are valid (the solution is used up by a successful check); the result feeds the risk counters */
    public function passes(string $payload, string $honeypot, string $ip = '', ?int $now = null): bool
    {
        $reason = $this->proof->verify($payload, $honeypot, $this->minSeconds(), $now);
        if ($this->adaptive()) {
            $this->risk->record($reason === null ? PowRisk::KIND_SUCCESS : PowRisk::KIND_FAILURE, $ip, $now);
        }

        return $reason === null;
    }

    /** the risk tier of the client (always normal with the adaptive mode off) */
    public function tier(string $ip, ?int $now = null): int
    {
        return $this->adaptive() ? $this->risk->tier($ip, $now, $this->int('pow_sensitivity', 100, 10, 10000)) : PowRisk::TIER_NORMAL;
    }

    private function adaptive(): bool
    {
        return (int) $this->config->get('pow_adaptive', '1') !== 0;
    }

    private function factor(int $tier): int
    {
        $key = $tier === PowRisk::TIER_HIGH ? 'pow_high_factor' : 'pow_elevated_factor';

        return $this->int($key, self::DEFAULT_FACTORS[$tier], 1, 1000);
    }

    private function maxNumber(): int
    {
        return $this->int('pow_max_number', self::DEFAULT_MAX_NUMBER, 1000, 5000000);
    }

    private function minSeconds(): int
    {
        return $this->int('pow_min_seconds', self::DEFAULT_MIN_SECONDS, 0, 60);
    }

    private function int(string $key, int $default, int $min, int $max): int
    {
        $value = $this->config->get($key);

        return min($max, max($min, $value === null || $value === '' ? $default : (int) $value));
    }
}
