<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\BotProtection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Bot-check challenge (/pow-challenge): a fresh signed proof-of-work challenge for the registration and lost-password forms. */
final class ProofOfWorkController
{
    public function __construct(private readonly BotProtection $botProtection) {}

    /** JSON `{salt, challenge, signature, maxnumber, wait}`, never cached; the difficulty depends on the client's risk tier (BotProtection) */
    #[Route('/pow-challenge', name: 'pow_challenge', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $response = new JsonResponse($this->botProtection->challenge((string) $request->getClientIp()));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
