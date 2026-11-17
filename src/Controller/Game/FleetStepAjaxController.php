<?php

/**
 * pr0game powered by steemnova
 * Fleets
 * (c) 2024 Hyman
 */

namespace App\Controller\Game;

use App\Game\Core\Session;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Fleet dispatch helper (XHR): drops the fleet prepared in the session when the dispatch pages are left. */
class FleetStepAjaxController extends AbstractGameController
{
    public $returnData = [];

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
     */
    #[GameRoute('game', 'game/fleet/step-ajax', 'fleetStepAjax', 'show', ['POST'])]
    public function show(): Response
    {
        // state-changing: POST only (the beacon is one) and thereby covered by the central CSRF check
        $token = $this->input()->string('token');
        $session = $this->sessions()->load();
        $fleet = $session->fleet;
        if (isset($fleet[$token])) {
            unset($fleet[$token]);
            $session->fleet = $fleet;
            $session->save();
        }

        return new Response();
    }
}
