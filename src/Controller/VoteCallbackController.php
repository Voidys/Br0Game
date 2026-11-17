<?php

declare(strict_types=1);

namespace App\Controller;

use App\Community\VoteSites;
use App\Game\Core\Singleton;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Postback of a vote site (/vote/callback/<site id>): the site tells us that the player behind a token has voted.
 * Public and outside the CSRF layer on purpose (a server-to-server call, no session); it is authenticated by the
 * per-site secret and rate limited per client IP (limiter "vote_callback").
 */
final class VoteCallbackController
{
    public function __construct(
        private readonly VoteSites $votes,
        private readonly Singleton $state,
        #[Autowire(service: 'limiter.vote_callback')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {}

    /**
     * GET or POST with `secret` (query, body or header X-Vote-Secret) and `token` (query or body). Answers JSON {status}: 200 ok (reward paid),
     * used (token already consumed) or cooldown (token consumed, the player is still on cooldown); 403 forbidden (wrong secret, unknown, inactive or
     * non-postback site, module off); 404 unknown_token; 429 rate_limited.
     */
    #[Route('/vote/callback/{siteId}', name: 'vote_callback', requirements: ['siteId' => '\d+'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, int $siteId): JsonResponse
    {
        // not a game page, so the game is not booted: the database layer only needs its timing bag
        $this->state->time = ['start' => microtime(true), 'db' => 0.0];

        if (!$this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new JsonResponse(['status' => 'rate_limited'], 429);
        }

        $site = $this->votes->find($siteId);
        if ($site === null || (int) $site['active'] !== 1 || !$this->votes->moduleEnabled((int) $site['universe'])) {
            return new JsonResponse(['status' => VoteSites::CALLBACK_FORBIDDEN], 403);
        }

        $secret = $this->text($request, 'secret') ?: (string) $request->headers->get('X-Vote-Secret', '');
        $status = $this->votes->callback($site, $secret, $this->text($request, 'token'), time());

        return new JsonResponse(['status' => $status], match ($status) {
            VoteSites::CALLBACK_FORBIDDEN => 403,
            VoteSites::CALLBACK_UNKNOWN_TOKEN => 404,
            default => 200,
        });
    }

    /** a string parameter of the query or the body (arrays and missing values count as empty) */
    private function text(Request $request, string $name): string
    {
        $value = $request->query->all()[$name] ?? $request->request->all()[$name] ?? '';

        return is_string($value) ? $value : '';
    }
}
