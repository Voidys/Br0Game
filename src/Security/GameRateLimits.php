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
 * - expensive reads (galaxy, player card, search, statistics, battle hall, battle reports, records, and the read-only
 *   AJAX modes of the fleet steps and the message player search) use "game_reads";
 * - visitors without a login are limited per client IP ("game_anonymous") on the public pages (battle reports, board).
 *
 * A page counts once, in the reads bucket when it is one of the expensive pages, otherwise in the actions bucket
 * when it changes state. Everything else (overview, menus, plain reads) is not limited. Buckets and the
 * switch (`app.game_rate_limit.enabled`) are configured in config/packages/framework.yaml and config/services.yaml.
 */
final class GameRateLimits
{
    /** lower-case page names ("page") or page and mode ("page:mode") of the expensive reads */
    private const EXPENSIVE_PAGES = [
        'galaxy', 'playercard', 'search', 'statistics', 'battlehall', 'raport', 'records',
        'fleetstep1:checktarget', 'fleetstep2:checkresources', 'messages:loaduser',
    ];

    /** pages (lower case) that can be opened without a login */
    private const ANONYMOUS_PAGES = [['game', 'raport'], ['account', 'board']];

    private const LIMITED_AREAS = ['game', 'account'];

    public function __construct(
        #[Autowire(service: 'limiter.game_actions')]
        private readonly RateLimiterFactoryInterface $actions,
        #[Autowire(service: 'limiter.game_reads')]
        private readonly RateLimiterFactoryInterface $reads,
        #[Autowire(service: 'limiter.game_anonymous')]
        private readonly RateLimiterFactoryInterface $anonymous,
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

        if ($area === 'game' && $this->isExpensive($request, $page)) {
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

    /**
     * Counts a request of a visitor without a login against his IP, for the public pages only (battle reports, board).
     *
     * @phpstan-impure
     * @return int|null null = allowed (or not covered); otherwise the seconds until the next request is accepted (at least 1)
     */
    public function checkAnonymous(Request $request, ?string $ip): ?int
    {
        if (!$this->enabled || $ip === null || !$this->policy->isGameRequest($request)) {
            return null;
        }
        if (!in_array($this->policy->areaAndPage($request), self::ANONYMOUS_PAGES, true)) {
            return null;
        }
        $limit = $this->anonymous->create($ip)->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }

    private function isExpensive(Request $request, string $page): bool
    {
        if (in_array($page, self::EXPENSIVE_PAGES, true)) {
            return true;
        }
        $mode = $this->policy->queryValue($request, 'mode');

        return $mode !== null && in_array($page . ':' . $mode, self::EXPENSIVE_PAGES, true);
    }
}
