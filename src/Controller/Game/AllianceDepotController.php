<?php

declare(strict_types=1);

namespace App\Controller\Game;

use App\Game\Core\AllianceDepotRefused;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alliance Depot of the current planet: lists the friendly fleets that hold here and extends their stay for deuterium
 * (rules in AllianceDepot). POST action=supply with fleet and hours supplies one fleet.
 */
#[\App\Routing\GamePage('game', 'allianceDepot')]
class AllianceDepotController extends AbstractGameController
{
    public static $defaultController = 'show';

    /**
     * Depot page. Without a depot on the planet the page ends in a message; a supply request is answered with a
     * result message (back button to the depot), otherwise the holding fleets are listed with the hours that can be added.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $level = (int) ($PLANET[$this->elements()->column(34)] ?? 0);
        $back = [['label' => $LNG['bd_continue'], 'url' => 'game?page=allianceDepot']];

        if ($level < 1) {
            $this->printMessage($LNG['depot_err_no_depot'], [['label' => $LNG['lm_buildings'], 'url' => 'game?page=buildings']]);
        }

        if ($this->http()->_GP('action', '') === 'supply' && ($this->state->requestServer['REQUEST_METHOD'] ?? '') === 'POST') {
            if ($USER['urlaubs_modus'] == 1) {
                $this->printMessage($LNG['fa_vacation_mode_current'], $back);
            }
            try {
                $extension = $this->allianceDepot()->extend(
                    (int) $PLANET['id'],
                    (int) $USER['id'],
                    (int) $this->http()->_GP('fleet', 0),
                    (int) $this->http()->_GP('hours', 0),
                );
            } catch (AllianceDepotRefused $refused) {
                $this->printMessage($LNG->format($refused->getMessage(), ...$refused->arguments), $back);
            }
            $this->allianceDepot()->notifyFleetOwner($extension, (string) $USER['username']);
            $this->printMessage(
                $LNG->format('depot_done', $extension['hours'], $this->viewHelpers()->prettyNumber($extension['cost'])),
                $back,
            );
        }

        $fleets = [];
        foreach ($this->allianceDepot()->holdingFleets((int) $PLANET['id'], (int) $USER['id'], $level) as $fleet) {
            $ships = [];
            foreach ($fleet['ships'] as $shipId => $count) {
                $ships[] = ['id' => (int) $shipId, 'count' => $count];
            }
            $fleets[] = [
                'id' => $fleet['id'],
                'username' => $fleet['username'],
                'ships' => $ships,
                'stayEnd' => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $fleet['stayTime'], $USER['timezone']),
                'hourlyCost' => $fleet['hourlyCost'],
                'maxHours' => $fleet['maxHours'],
            ];
        }

        $this->assign([
            'depotLevel' => $level,
            'fleets' => $fleets,
            'deuterium' => $PLANET[$this->elements()->column(RESOURCE_DEUT)],
            'maxStay' => $this->allianceDepot()->maxRemainingStayHours(),
        ]);
        $this->addScript('allianceDepot.js');

        return $this->render('game/page.allianceDepot.default.html.twig');
    }
}
