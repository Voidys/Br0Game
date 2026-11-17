<?php

namespace App\Controller\Admin;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Database migrations for upgrades by unpacking a release over an install: lists the current revision and the pending
 * migrations (install/migrations/) and applies them (App\Game\Core\MigrationRunner, the same code as the installer).
 * Reachable while migrations are pending (GameBootstrap exempts the `migrations` page from the upgrade redirect).
 */
class MigrationsController extends AbstractAdminController
{
    protected function construct(): void
    {
        if (!$this->viewHelpers()->allowedTo('ShowClearCachePage')) {
            $this->deny();
        }
    }

    /** current revision and pending migrations */
    #[GameRoute('admin', 'admin/migrations', 'migrations')]
    public function show(): Response
    {
        return $this->render('admin/MigrationsPage.html.twig', $this->status() + ['results' => null]);
    }

    /** applies all pending migrations and shows the result per migration */
    #[GameRoute('admin', 'admin/migrations/run', 'migrations', 'run', ['POST'])]
    public function run(): Response
    {
        $results = $this->migrations()->run();

        return $this->render('admin/MigrationsPage.html.twig', $this->status() + ['results' => $results]);
    }

    /**
     * @return array{dbVersion: mixed, pending: list<array{revision: string, file: string}>}
     */
    public function status(): array
    {
        try {
            $dbVersion = $this->db->selectSingle('SELECT max(dbVersion) as dbVersion FROM %%SYSTEM%%;', [], 'dbVersion');
        } catch (\Exception) {
            $dbVersion = 0;
        }

        $pending = [];
        foreach ($this->maintenance()->listMigrations() as $revision => $fileInfo) {
            $pending[] = ['revision' => (string) $revision, 'file' => $fileInfo['fileName']];
        }

        return ['dbVersion' => $dbVersion, 'pending' => $pending];
    }
}
