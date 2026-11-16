<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Generic in-game request budget per account (audit F-14/F-23), applied by App\EventListener\RateLimitListener to
 * the game and account areas:
 *
 * - state-changing requests (every unsafe method, plus the GET links of CsrfPolicy::GET_RULES) use the
 *   "game_actions" token bucket;
 * - expensive reads (galaxy, player card, search, statistics, battle hall, battle reports, records) use "game_reads".
 *
 * A page counts once, in the reads bucket when it is one of the expensive pages, otherwise in the actions bucket
 * when it changes state. Everything else (overview, menus, plain reads) is not limited. Buckets and the
 * switch (`app.game_rate_limit.enabled`) are configured in config/packages/framework.yaml and config/services.yaml.
 */
final class GameRateLimits
{
    /** lower-case page names of the expensive read pages */
    private const EXPENSIVE_PAGES = ['galaxy', 'playercard', 'search', 'statistics', 'battlehall', 'raport', 'records'];

    private const LIMITED_AREAS = ['game', 'account'];

    public function __construct(
        #[Autowire(service: 'limiter.game_actions')]
        private readonly RateLimiterFactoryInterface $actions,
        #[Autowire(service: 'limiter.game_reads')]
        private readonly RateLimiterFactoryInterface $reads,
        private readonly CsrfPolicy $policy,
        #[Autowire('%app.game_rate_limit.enabled%')]
        private readonly bool $enabled,
    ) {}

    /**
     * Counts the request for the account and says how long to wait when the budget is used up.
     *
     * @phpstan-impure
     * @return int|null null = allowed (or not covered); otherwise the seconds until the next request is accepted (at least 1)
     */
    public function check(Request $request, int $accountId): ?int
    {
        if (!$this->enabled || $accountId <= 0 || !$this->policy->isGameRequest($request)) {
            return null;
        }
        [$area, $page] = $this->policy->areaAndPage($request);
        if (!in_array($area, self::LIMITED_AREAS, true)) {
            return null;
        }

        if ($area === 'game' && in_array($page, self::EXPENSIVE_PAGES, true)) {
            $factory = $this->reads;
        } elseif ($this->policy->requiresToken($request)) {
            $factory = $this->actions;
        } else {
            return null;
        }

        $limit = $factory->create((string) $accountId)->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }
}
