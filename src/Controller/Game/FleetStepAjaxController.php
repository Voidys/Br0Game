<?php

/**
 * pr0game powered by steemnova
 * Fleets
 * (c) 2024 Hyman
 */

namespace App\Controller\Game;

use App\Game\Core\HTTP;
use App\Game\Core\Session;

/** Fleet dispatch helper (XHR): drops the fleet prepared in the session when the dispatch pages are left. */
#[\App\Routing\GamePage('game.php', 'fleetStepAjax')]
#[\App\Routing\GamePage('game.php', 'fleetStepAjax', uniPrefix: true)]
class FleetStepAjaxController extends AbstractGameController
{
    public $returnData = [];

    public static $requireModule = 0;

    public function initialize(): void
    {
        parent::initialize();
        $this->setWindow('ajax');
    }

    /**
     * This function will delete the prepared fleet in session via its token.
     * Called by ajax in flotten.js on browser page change.
     * Function addBeforeUnload() adds the listener to the window object. It is called in FleetStep1 and 2 pages.
     * CheckTarget() and CheckResources() are called by forms of Sttep1 and 2 to proceed, which is also a page change.
     * To avoid deleting the fleet token, removeBeforeUnload() is called within them.
     * On failed checks addBeforeUnload() is called again.
     *
     * @return void
     */
    public function show()
    {
        $token = $this->http()->_GP('token', '');
        $session = $this->sessions()->load();
        $fleet = $session->fleet;
        if (isset($fleet[$token])) {
            unset($fleet[$token]);
            $session->fleet = $fleet;
            $session->save();
        }
    }
}
