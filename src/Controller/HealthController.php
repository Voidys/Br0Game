<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Health check (/healthz): answers "ok" when the database responds, nothing else (no versions, counts or paths). */
final class HealthController
{
    /**
     * Plain text `ok` (200) when `SELECT 1` works, `fail` (503) otherwise.
     */
    #[Route('/healthz', name: 'health', methods: ['GET'])]
    public function __invoke(Connection $connection): Response
    {
        try {
            $connection->fetchOne('SELECT 1');
        } catch (\Throwable) {
            return new Response('fail', Response::HTTP_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain']);
        }

        return new Response('ok', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }
}
