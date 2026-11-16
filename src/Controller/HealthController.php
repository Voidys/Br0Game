<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Health check (/healthz): PHP, database version and account count. */
final class HealthController extends AbstractController
{
    /**
     * Health page: PHP version, database server version and the number of accounts; renders health.html.twig. Also shows the logged in account.
     */
    #[Route('/healthz', name: 'health', methods: ['GET'])]
    public function __invoke(
        Connection $connection,
        #[Autowire(param: 'app.table_prefix')]
        string $tablePrefix,
    ): Response {
        $accounts = (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %saccounts', $tablePrefix));

        return $this->render('health.html.twig', [
            'php' => PHP_VERSION,
            'database' => $connection->getServerVersion(),
            'accounts' => $accounts,
            'account' => $this->getUser(),
        ]);
    }
}
