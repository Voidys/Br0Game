<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Risk signals of the adaptive bot check, per client IP and per its network prefix (/24 for IPv4, /64 for IPv6).
 *
 * Three event kinds are kept as timestamp lists in the app cache (each with the TTL of its window, no database):
 * challenges issued (10 minutes), failed verifications (1 hour) and successful verifications, i.e. registrations or
 * reset requests (24 hours). tier() compares the counts with the thresholds below: the IP itself against
 * the plain values, its prefix against PREFIX_FACTOR times them (many clients behind one prefix are normal).
 */
final class PowRisk
{
    public const TIER_NORMAL = 0;
    public const TIER_ELEVATED = 1;
    public const TIER_HIGH = 2;

    public const KIND_CHALLENGE = 'challenge';
    public const KIND_FAILURE = 'failure';
    public const KIND_SUCCESS = 'success';

    /** seconds each event kind is counted */
    private const WINDOWS = [self::KIND_CHALLENGE => 600, self::KIND_FAILURE => 3600, self::KIND_SUCCESS => 86400];

    /** per kind: counts that make an IP elevated / high (prefixes need PREFIX_FACTOR times as many) */
    private const THRESHOLDS = [
        self::KIND_CHALLENGE => [self::TIER_ELEVATED => 5, self::TIER_HIGH => 20],
        self::KIND_FAILURE => [self::TIER_ELEVATED => 3, self::TIER_HIGH => 10],
        self::KIND_SUCCESS => [self::TIER_ELEVATED => 2, self::TIER_HIGH => 5],
    ];

    private const PREFIX_FACTOR = 3;

    /** timestamps kept per list (enough for the highest threshold of a prefix) */
    private const MAX_EVENTS = 200;

    public function __construct(private readonly CacheItemPoolInterface $cache) {}

    /** records one event for the IP and for its prefix */
    public function record(string $kind, string $ip, ?int $now = null): void
    {
        $now ??= time();
        foreach ($this->subjects($ip) as $subject) {
            $item = $this->cache->getItem($this->key($kind, $subject));
            $events = $this->recent($item->isHit() ? $item->get() : [], $kind, $now);
            $events[] = $now;
            $item->set(array_slice($events, -self::MAX_EVENTS))->expiresAfter(self::WINDOWS[$kind]);
            $this->cache->save($item);
        }
    }

    /**
     * the highest tier (TIER_*) any signal of the IP or its prefix reaches
     *
     * @param int $sensitivity percent of the default thresholds (200 = twice as many events are needed, 50 = half)
     */
    public function tier(string $ip, ?int $now = null, int $sensitivity = 100): int
    {
        $now ??= time();
        [$own, $prefix] = $this->subjects($ip);
        $tier = self::TIER_NORMAL;
        foreach (self::THRESHOLDS as $kind => $levels) {
            $counts = [[$this->count($kind, $own, $now), 1], [$this->count($kind, $prefix, $now), self::PREFIX_FACTOR]];
            foreach ($counts as [$count, $factor]) {
                foreach ($levels as $level => $threshold) {
                    if ($count >= max(1, (int) ceil($threshold * $factor * $sensitivity / 100))) {
                        $tier = max($tier, $level);
                    }
                }
            }
        }

        return $tier;
    }

    /**
     * Cache subjects of a client: [IP, prefix]. An unparsable address counts as one prefix of its own.
     *
     * @return array{0: string, 1: string}
     */
    public function subjects(string $ip): array
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return [$ip, $ip];
        }
        $prefix = strlen($packed) === 4 ? substr($packed, 0, 3) : substr($packed, 0, 8);

        return [bin2hex($packed), 'p' . bin2hex($prefix)];
    }

    private function count(string $kind, string $subject, int $now): int
    {
        $item = $this->cache->getItem($this->key($kind, $subject));

        return $item->isHit() ? count($this->recent($item->get(), $kind, $now)) : 0;
    }

    /**
     * @param mixed $events stored timestamps
     *
     * @return list<int> the ones inside the window of the kind
     */
    private function recent(mixed $events, string $kind, int $now): array
    {
        return array_values(array_filter(is_array($events) ? $events : [], static fn($t): bool => is_int($t) && $t > $now - self::WINDOWS[$kind]));
    }

    private function key(string $kind, string $subject): string
    {
        return 'powr_' . $kind . '_' . sha1($subject);
    }
}
