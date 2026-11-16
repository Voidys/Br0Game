<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Response;

/**
 * Maintenance tools on one page (clear the game caches, recalculate the points) instead of action
 * links in the menu; the actions themselves stay their own pages (clearCache, statUpdate) and lead
 * back here.
 */
#[\App\Routing\GamePage('admin.php', 'maintenance')]
#[\App\Routing\GamePage('admin.php', 'maintenance', uniPrefix: true)]
class MaintenanceController extends AbstractAdminController
{
    /**
     * Shown to every admin; the single actions have their own permission checks.
     */
    protected function construct(): void
    {
        // no right of its own: open for admins who may run at least one of the tools
        if (!$this->viewHelpers()->allowedTo('ShowClearCachePage') && !$this->viewHelpers()->allowedTo('ShowStatUpdatePage')) {
            $this->deny();
        }
    }

    /**
     * Renders the maintenance page (cache clearing, statistics recalculation).
     */
    public function show(): Response
    {
        $this->assign([
            'canClearCache' => $this->viewHelpers()->allowedTo('ShowClearCachePage'),
            'canUpdateStats' => $this->viewHelpers()->allowedTo('ShowStatUpdatePage'),
            'gameLog' => ClearLogController::logInfo(),
        ]);
        return $this->render('admin/MaintenancePage.html.twig');
    }
}
