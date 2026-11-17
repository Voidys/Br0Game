<?php

namespace App\Controller;

use App\Game\Core\WikiSsoCodeStore;
use App\Runtime\GameSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Wiki single sign-on, server-to-server part (formerly internal_sso_resolve.php): the wiki
 * resolves a one-time code (created by game/wiki-login) into the game account.
 * Authenticated by the shared secret $wikiSsoInternalToken (header X-Internal-Auth).
 */
final class WikiSsoResolveController
{
    public function __construct(private readonly GameSettings $settings) {}

    /**
     * Server-to-server resolve call from the wiki (POST code, header X-Internal-Auth = shared secret). Answers JSON {accountId, accountname} and consumes
     * the one-time code, or {error} with 503 (not configured), 403 (wrong token), 400 (malformed code), 404 (unknown, used or expired code) or 500 (corrupt payload).
     */
    #[Route('/internal_sso_resolve.php', name: 'wiki_sso_resolve')]
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) $this->settings->get('wikiSsoInternalToken', '');
        if ($token === '') {
            return $this->error(503, 'not_configured');
        }

        $providedToken = $request->headers->get('X-Internal-Auth', '');
        if (!is_string($providedToken) || !hash_equals($token, $providedToken)) {
            return $this->error(403, 'forbidden');
        }

        $code = $request->request->get('code', '');
        if (!is_string($code) || !preg_match('/^[A-Za-z0-9_-]{20,64}$/', $code)) {
            return $this->error(400, 'invalid_code');
        }

        $payload = WikiSsoCodeStore::create(
            (string) $this->settings->get('wikiSsoCodeStore', 'file'),
            (string) $this->settings->get('wikiSsoRedisUrl', ''),
        )->consume($code);

        if ($payload === null) {
            return $this->error(404, 'not_found_or_already_consumed_or_expired');
        }
        if (!isset($payload['accountId'], $payload['accountname'])) {
            return $this->error(500, 'corrupt_payload');
        }

        return new JsonResponse([
            'accountId'   => (int) $payload['accountId'],
            'accountname' => (string) $payload['accountname'],
        ]);
    }

    private function error(int $status, string $reason): JsonResponse
    {
        return new JsonResponse(['error' => $reason], $status);
    }
}
