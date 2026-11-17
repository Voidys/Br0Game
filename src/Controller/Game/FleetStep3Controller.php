<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Game;

use Symfony\Component\HttpFoundation\Response;
use App\Routing\GameRoute;
use App\Game\Fleet\FleetDispatch;
use App\Game\Fleet\FleetDispatchRefused;

/** Fleet dispatch, step 3: validates the prepared fleet and sends it (FleetDispatch); refusals are shown as message page. */
#[\App\Controller\RequireModule('MODULE_FLEET_TABLE')]
class FleetStep3Controller extends AbstractGameController
{
    /**
     * Fleet dispatch step 3: FleetDispatch::dispatch() validates the prepared fleet and sends it; a refusal (FleetDispatchRefused) is shown as a message
     * with its buttons. On success renders the confirmation with the redirect target and delay (`goto`, `gotoinsec`).
     */
    #[GameRoute('game', 'game/fleet/step3', 'fleetStep3', 'show', ['GET', 'POST'])]
    public function show(): Response
    {
        // sending a fleet changes state: only the posted form of step 2 (with the CSRF token) may do it, a GET goes back to the fleet table
        if (($this->state->requestServer['REQUEST_METHOD'] ?? '') !== 'POST') {
            return $this->redirectResponse($this->fleetFunctions()->fleetPageUrl(0));
        }
        try {
            $variables = (new FleetDispatch($this->db, $this->configs, $this->state, $this->fleetCaps))->dispatch();
        } catch (FleetDispatchRefused $refused) {
            if ($refused->fleetPageCode !== null) {
                return $this->redirectResponse($this->fleetFunctions()->fleetPageUrl($refused->fleetPageCode));
            }

            return $this->messageResponse($refused->getMessage(), array_map(
                fn(array $button): array => ['label' => $button['label'], 'url' => $this->router->generate($button['url'])],
                $refused->buttons,
            ));
        }
        $goto = $this->router->generate($variables['goto']);
        $gotoinsec = $variables['gotoinsec'];
        unset($variables['goto'], $variables['gotoinsec']);

        return $this->render('game/page.fleetStep3.default.html.twig', ['goto' => $goto, 'gotoinsec' => $gotoinsec] + $variables);
    }
}
