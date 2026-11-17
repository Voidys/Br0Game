<?php

declare(strict_types=1);

namespace App\Controller\Game;

use App\Game\Core\AllianceDepotRefused;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alliance Depot of the current planet: lists the friendly fleets that hold here and extends their stay for deuterium
 * (rules in AllianceDepot). POST supply with fleet and hours supplies one fleet.
 */
class AllianceDepotController extends AbstractGameController
{
    /**
     * The alliance depot of the current planet: the fleets holding at it with their extension form.
     */
    #[GameRoute('game', 'game/alliance-depot', 'allianceDepot')]
    public function show(): Response
    {
        return $this->depotPage(false);
    }

    /**
     * Pays deuterium to extend the hold time of an allied fleet (request: fleet, hours); not in vacation mode. Answers with a message page.
     */
    #[GameRoute('game', 'game/alliance-depot/supply', 'allianceDepot', 'supply', ['GET', 'POST'])]
    public function supply(): Response
    {
        if ($redirect = $this->redirectUnlessPost('game_alliance_depot_show')) {
            return $redirect;
        }

        return $this->depotPage(true);
    }

    /**
     * The depot page, or the result message of the supply request.
     */
    private function depotPage(bool $supply): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $level = (int) ($PLANET[$this->elements()->column(34)] ?? 0);
        $back = [['label' => $LNG['bd_continue'], 'url' => $this->router->generate('game_alliance_depot_show')]];

        if ($level < 1) {
            return $this->messageResponse($LNG['depot_err_no_depot'], [['label' => $LNG['lm_buildings'], 'url' => $this->router->generate('game_buildings_show')]]);
        }

        if ($supply) {
            if ($USER['urlaubs_modus'] == 1) {
                return $this->messageResponse($LNG['fa_vacation_mode_current'], $back);
            }
            try {
                $extension = $this->allianceDepot()->extend(
                    (int) $PLANET['id'],
                    (int) $USER['id'],
                    $this->input()->int('fleet'),
                    $this->input()->int('hours'),
                );
            } catch (AllianceDepotRefused $refused) {
                return $this->messageResponse($LNG->format($refused->getMessage(), ...$refused->arguments), $back);
            }
            $this->allianceDepot()->notifyFleetOwner($extension, (string) $USER['username']);

            return $this->messageResponse(
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

        $this->addScript('allianceDepot.js');

        return $this->render('game/page.allianceDepot.default.html.twig', [
            'depotLevel' => $level,
            'fleets' => $fleets,
            'deuterium' => $PLANET[$this->elements()->column(RESOURCE_DEUT)],
            'maxStay' => $this->allianceDepot()->maxRemainingStayHours(),
        ]);
    }
}
