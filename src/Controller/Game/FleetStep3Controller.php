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
        // post/redirect/get: the outcome is kept in the session and shown by result(), so reload and browser back never resend the fleet
        $session = $this->sessions()->load();
        try {
            $variables = (new FleetDispatch($this->db, $this->configs, $this->state, $this->fleetCaps))->dispatch();
        } catch (FleetDispatchRefused $refused) {
            if ($refused->fleetPageCode !== null) {
                return $this->redirectResponse($this->fleetFunctions()->fleetPageUrl($refused->fleetPageCode));
            }

            $session->fleetOutcome = [
                'message' => $refused->getMessage(),
                'buttons' => array_map(
                    fn(array $button): array => ['label' => $button['label'], 'url' => $this->router->generate($button['url'])],
                    $refused->buttons,
                ),
            ];
            $session->save();

            return $this->redirectToRoute('game_fleet_step3_result');
        }
        $variables['goto'] = $this->router->generate($variables['goto']);
        $session->fleetOutcome = ['variables' => $variables];
        $session->save();

        return $this->redirectToRoute('game_fleet_step3_result');
    }

    /**
     * Outcome of the last dispatch (confirmation with the redirect target and delay, or the refusal message); without one the fleet page.
     */
    #[GameRoute('game', 'game/fleet/result', 'fleetStep3', 'result', ['GET'])]
    public function result(): Response
    {
        $outcome = $this->sessions()->load()->fleetOutcome;
        if (!is_array($outcome)) {
            return $this->redirectResponse($this->fleetFunctions()->fleetPageUrl(0));
        }
        if (isset($outcome['message'])) {
            return $this->messageResponse($outcome['message'], $outcome['buttons']);
        }
        $variables = $outcome['variables'];

        return $this->render('game/page.fleetStep3.default.html.twig', $variables);
    }
}
