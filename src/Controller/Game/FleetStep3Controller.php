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
use Exception;
use App\Game\Fleet\FleetDispatch;
use App\Game\Fleet\FleetDispatchRefused;

/** Fleet dispatch, step 3: validates the prepared fleet and sends it (FleetDispatch); refusals are shown as message page. */
#[\App\Routing\GamePage('game.php', 'fleetStep3')]
#[\App\Routing\GamePage('game.php', 'fleetStep3', uniPrefix: true)]
class FleetStep3Controller extends AbstractGameController
{
    public static $requireModule = 'MODULE_FLEET_TABLE';

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Fleet dispatch step 3: FleetDispatch::dispatch() validates the prepared fleet and sends it; a refusal (FleetDispatchRefused) is shown as a message
     * with its buttons. On success renders the confirmation with the redirect target and delay (`goto`, `gotoinsec`).
     */
    public function show(): Response
    {
        try {
            $variables = (new FleetDispatch($this->db, $this->configs, $this->state))->dispatch();
        } catch (FleetDispatchRefused $refused) {
            $this->printMessage($refused->getMessage(), $refused->buttons);
        }
        $this->assign(['goto' => $variables['goto'], 'gotoinsec' => $variables['gotoinsec']]);
        unset($variables['goto'], $variables['gotoinsec']);
        $this->assign($variables);

        return $this->render('game/page.fleetStep3.default.html.twig');
    }
}
