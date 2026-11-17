<?php

declare(strict_types=1);

namespace App\Security;

use App\Game\Enum\Mission;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Audit F-14: optional daily caps per account on fleet launches and on attacks (slow bots that play at human speed).
 * Fixed windows of 24 h (limiters "fleet_launches" and "fleet_attacks" in config/packages/framework.yaml), limits from
 * app.fleet_launches_per_day and app.attacks_per_day; 0 switches a cap off. The counters sit in the limiter cache, so the
 * cap needs no table and survives restarts; the window starts with the first counted launch.
 */
final class FleetDailyCaps
{
    /** mission ids that count as an attack: attack, ACS attack, ACS join (2) and destruction, missile */
    private const ATTACK_MISSIONS = [Mission::Attack->value, Mission::Acs->value, Mission::Destruction->value, Mission::Missile->value];

    public function __construct(
        #[Autowire(service: 'limiter.fleet_launches')]
        private readonly RateLimiterFactoryInterface $launches,
        #[Autowire(service: 'limiter.fleet_attacks')]
        private readonly RateLimiterFactoryInterface $attacks,
        #[Autowire('%app.fleet_launches_per_day%')]
        private readonly int $launchLimit,
        #[Autowire('%app.attacks_per_day%')]
        private readonly int $attackLimit,
    ) {}

    public static function isAttack(mixed $mission): bool
    {
        return \in_array((int) $mission, self::ATTACK_MISSIONS, true);
    }

    /**
     * Counts one launch (and one attack for an attack mission) of the account. Nothing is counted when a cap is full.
     *
     * @phpstan-impure
     * @return array{key: string, limit: int, hours: int, minutes: int}|null null = allowed and counted; else the language key of the refusal, the exhausted cap and the time until it resets
     */
    public function consume(int $accountId, mixed $mission): ?array
    {
        $caps = [[$this->launches, $this->launchLimit, 'fl_daily_cap_reached']];
        if (self::isAttack($mission)) {
            $caps[] = [$this->attacks, $this->attackLimit, 'fl_daily_attack_cap_reached'];
        }
        $caps = array_filter($caps, static fn(array $cap): bool => $cap[1] > 0);

        // first look at every cap, so a refusal by one does not use up the other
        foreach ($caps as [$factory, $limit, $key]) {
            $limiter = $factory->create((string) $accountId);
            if ($limiter->consume(0)->getRemainingTokens() < 1) {
                $seconds = max(0, $limiter->consume(1)->getRetryAfter()->getTimestamp() - time());

                return ['key' => $key, 'limit' => $limit, 'hours' => intdiv($seconds, 3600), 'minutes' => intdiv($seconds % 3600, 60) + 1];
            }
        }
        foreach ($caps as [$factory]) {
            $factory->create((string) $accountId)->consume(1);
        }

        return null;
    }
}
